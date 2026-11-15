<?php
// tests/L2/Stage1WireStatementTest.php
// wire Envelope `{payload, signature}` の strict パースと phase-1 wire 検証のテスト
// （spec resolver-l2-0.1-draft §7.1, §7.2, §7.3, §15.2 phase 1: デコード前の wire 検査）。
// 該当 Catalog case: L2-VER-046（wire canonicality / outer whitespace）、
// L2-VER-002（wire 構文・strict parse）、L2-VER-003（payload と signature の分離）、
// L2-VER-008（wire 整数フィールド）、L2-VER-012（wire negatives 全般）。
// wire 系の失敗はすべて INVALID に分類される（§15.2 phase 1）。

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Relink\Resolver\Domain\L2\WireStatement;
use Relink\Resolver\Domain\L2\WireStatementException;

require_once __DIR__ . '/L2Fixtures.php';

final class Stage1WireStatementTest extends TestCase
{
    /**
     * pinned envelope `{payload, signature}` が strict にパースされ、
     * payload の canonical オクテットと signature をそのまま保持する。
     *
     * @return Generator<array{string, string, string, string}>
     */
    public static function envelopeProvider(): Generator
    {
        foreach (L2Fixtures::signedVectors() as $vector) {
            yield [$vector['id'], $vector['canonical'], $vector['signature'], $vector['signerPublicKey']];
        }
    }

    #[DataProvider('envelopeProvider')]
    public function testPinnedEnvelopesParseAndPreserveCanonicalPayload(string $id, string $canonical, string $signature): void
    {
        $wire = L2Fixtures::envelope($canonical, $signature);
        $statement = WireStatement::parse($wire);

        // raw payload span と canonicalPayloadOctets は verbatim 一致（§7.3 wire canonicality）
        $this->assertSame($canonical, $statement->canonicalPayloadOctets(), $id);
        $this->assertSame($signature, $statement->signatureBase64Url(), $id);
        $this->assertSame(64, strlen($statement->signatureOctets()), $id . ' signature must be 64 octets');
        $this->assertSame('ed25519', $statement->algorithm(), $id);
        // payload の top-level に signature メンバは存在しない（§7.1 / L2-VER-003）
        $this->assertArrayNotHasKey('signature', $statement->payload(), $id);
    }

    /**
     * L2-VER-046 (outer-whitespace-only): envelope framing の whitespace は許容される。
     * payload raw span の正準性は変わらない（§7.1 transport framing）。
     */
    #[DataProvider('envelopeProvider')]
    public function testEnvelopeFramingWhitespaceIsTolerated(string $id, string $canonical, string $signature): void
    {
        $wire = '  { "payload" : ' . $canonical . ' , "signature" : "' . $signature . '" } ';
        $statement = WireStatement::parse($wire);

        $this->assertSame($canonical, $statement->canonicalPayloadOctets(), $id);
        $this->assertSame($signature, $statement->signatureBase64Url(), $id);
    }

    /**
     * L2-VER-003 補足: 入れ子の `transfer.signature` は envelope 規則に違反しない。
     * Record2 は transfer payload + その signature を payload 内に含む。
     */
    public function testNestedTransferSignatureInPayloadIsLegal(): void
    {
        $record2 = L2Fixtures::signedVectors()[7];
        $statement = WireStatement::parse(L2Fixtures::envelope($record2['canonical'], $record2['signature']));

        $transfer = $statement->payload()['transfer'] ?? null;
        $this->assertIsArray($transfer);
        $this->assertIsString($transfer['signature'] ?? null);
        $this->assertSame($record2['canonical'], $statement->canonicalPayloadOctets());
    }

    /**
     * phase-1 wire negatives: すべて INVALID（WireStatementException）。
     * 該当: L2-VER-002, L2-VER-003, L2-VER-008, L2-VER-046。
     *
     * @return Generator<array{string, string}>
     */
    public static function wireNegativeProvider(): Generator
    {
        $vector1 = L2Fixtures::signedVectors()[0];
        $canonical = $vector1['canonical'];
        $signature = $vector1['signature'];

        // 重複メンバ名（payload 内）
        yield ['duplicate-keyId-in-payload', L2Fixtures::envelope(
            str_replace('"keyId":"ECMERdvpV0k"', '"keyId":"ECMERdvpV0k","keyId":"ECMERdvpV0k"', $canonical),
            $signature
        )];

        // 重複メンバ名（入れ子: Record0 の keys[0] 内）
        $record0 = L2Fixtures::signedVectors()[4]['canonical'];
        yield ['duplicate-keyId-nested-in-keys-array', L2Fixtures::envelope(
            str_replace('{"algorithm":"ed25519","keyId":"ECMERdvpV0k"', '{"algorithm":"ed25519","keyId":"ECMERdvpV0k","keyId":"ECMERdvpV0k"', $record0),
            $signature
        )];

        // envelope 重複（payload 2 つ）
        yield ['duplicate-envelope-payload-members', '{"payload":' . $canonical . ',"payload":' . $canonical . ',"signature":"' . $signature . '"}'];

        // envelope 重複（signature 2 つ）
        yield ['duplicate-envelope-signature-members', '{"payload":' . $canonical . ',"signature":"' . $signature . '","signature":"' . $signature . '"}'];

        // payload のメンバ順が JCS 非正準（algorithm を末尾へ移動）
        $unsorted = substr($canonical, strlen('{"algorithm":"ed25519",'), 1);
        yield ['payload-keys-not-sorted', L2Fixtures::envelope(
            str_replace(',"statementType"', ',"algorithm":"ed25519","statementType"', $unsorted),
            $signature
        )];

        // payload 内の余分な whitespace（raw span が正準でなくなる）
        yield ['whitespace-inside-payload', L2Fixtures::envelope(
            str_replace('"algorithm":"ed25519"', '"algorithm": "ed25519"', $canonical),
            $signature
        )];

        // 整数フィールドの `1.0` 形（L2-VER-008）
        yield ['generation-as-7.0', L2Fixtures::envelope(
            str_replace('"generation":7', '"generation":7.0', $canonical),
            $signature
        )];

        // 整数フィールドの指数形
        yield ['issuedAt-exponent-form', L2Fixtures::envelope(
            str_replace('"issuedAt":1769996400', '"issuedAt":1.7699964E+9', $canonical),
            $signature
        )];

        // 整数フィールドの小数形（nested keys[].validFrom）
        $record0Wire = L2Fixtures::signedVectors()[4]['canonical'];
        yield ['validFrom-fraction', L2Fixtures::envelope(
            str_replace('"validFrom":1769996400', '"validFrom":1769996400.5', $record0Wire),
            $signature
        )];

        // 整数フィールドの負値
        yield ['negative-issuedAt', L2Fixtures::envelope(
            str_replace('"issuedAt":1769996400', '"issuedAt":-1769996400', $canonical),
            $signature
        )];

        // 整数フィールドが 2^53 超（L2-VER-008 上限 9007199254740991）
        yield ['generation-above-2pow53', L2Fixtures::envelope(
            str_replace('"generation":7', '"generation":9007199254740992', $canonical),
            $signature
        )];

        // 先頭ゼロの数値
        yield ['leading-zero-number', L2Fixtures::envelope(
            str_replace('"generation":7', '"generation":07', $canonical),
            $signature
        )];

        // signature が payload の top-level メンバ（L2-VER-003）
        yield ['signature-inside-payload', L2Fixtures::envelope(
            str_replace(',"statementType"', ',"signature":"' . $signature . '","statementType"', $canonical),
            $signature
        )];

        // envelope メンバ不足（signature なし）
        yield ['missing-signature-member', '{"payload":' . $canonical . '}'];

        // envelope メンバ不足（payload なし）
        yield ['missing-payload-member', '{"signature":"' . $signature . '"}'];

        // envelope 余分メンバ
        yield ['extra-envelope-member', '{"payload":' . $canonical . ',"signature":"' . $signature . '","extra":1}'];

        // payload が object でない
        yield ['payload-not-object', '{"payload":5,"signature":"' . $signature . '"}'];

        // payload が空 object（PHP [] と {} の Ambiguity: fail-closed）
        yield ['payload-empty-object-ambiguity', '{"payload":{},"signature":"' . $signature . '"}'];

        // signature が JSON string でない
        yield ['signature-not-a-string', '{"payload":' . $canonical . ',"signature":7}'];

        // signature に '=' パディング（正準 base64url は '=' 除去）
        yield ['signature-with-padding', L2Fixtures::envelope($canonical, $signature . '=')];

        // signature に base64url 非アルファベット（'+'）
        yield ['signature-plus-character', L2Fixtures::envelope($canonical, str_replace('-', '+', $signature))];

        // signature が 64 オクテットにデコードされない（84 chars = 63 bytes）
        yield ['signature-63-bytes', L2Fixtures::envelope($canonical, substr($signature, 0, 84))];

        // wire 末尾の余分な content
        yield ['trailing-content', L2Fixtures::envelope($canonical, $signature) . 'x'];

        // wire 文字列内の raw 制御バイト（改行）
        yield ['raw-newline-in-payload-string', L2Fixtures::envelope(
            str_replace('ar-xml"', "ar-xml\n\"", $canonical),
            $signature
        )];

        // wire 文字列内の孤立 high surrogate エスケープ
        yield ['lone-surrogate-escape', L2Fixtures::envelope(
            str_replace('"keyId"', '"ext\\ud800KeyId"', $canonical),
            $signature
        )];

        // wire が非 UTF-8 バイト列
        yield ['invalid-utf8-bytes', L2Fixtures::envelope(
            str_replace('ar-xml', "ar-xml\xC3\x28", $canonical),
            $signature
        )];

        // wire が空
        yield ['empty-wire', ''];

        // wire が object でない
        yield ['wire-not-object', '5'];
    }

    #[DataProvider('wireNegativeProvider')]
    public function testWireNegativesAreClassifiedInvalid(string $id, string $wire): void
    {
        $this->expectException(WireStatementException::class);
        try {
            WireStatement::parse($wire);
        } catch (WireStatementException $exception) {
            // §15.2 phase 1: wire 検査の失敗は常に INVALID
            $this->assertSame('INVALID', $exception->verdict()->value, $id);
            throw $exception;
        }

        $this->fail($id . ' must be rejected as INVALID');
    }

    /**
     * 上限 2^53-1 (9007199254740991) の整数フィールドは受理される（L2-VER-008）。
     */
    public function testMaxSafeIntegerGenerationIsAccepted(): void
    {
        $vector1 = L2Fixtures::signedVectors()[0];
        $wire = L2Fixtures::envelope(
            str_replace('"generation":7', '"generation":9007199254740991', $vector1['canonical']),
            $vector1['signature']
        );

        $statement = WireStatement::parse($wire);
        $this->assertSame(9007199254740991, $statement->payload()['generation']);
    }

    /**
     * V3b の raw U+007F は wire 文字列として受理される（RFC 8259 §7 raw 3F 許容）。
     */
    public function testRawU007FInPayloadStringIsAccepted(): void
    {
        $vector3b = L2Fixtures::signedVectors()[3];
        $statement = WireStatement::parse(L2Fixtures::envelope($vector3b['canonical'], $vector3b['signature']));

        $note = $statement->payload()['note'] ?? null;
        $this->assertStringContainsString("\u{2028}", (string) $note);
        $this->assertStringContainsString("\u{007F}", (string) $note);
        $this->assertSame($vector3b['canonical'], $statement->canonicalPayloadOctets());
    }

    /**
     * L2-VER-046: outer framing whitespace のみでは canonical payload オクテットが変わらない。
     */
    public function testOuterWhitespaceOnlyLeavesCanonicalOctetsIdentical(): void
    {
        $vector1 = L2Fixtures::signedVectors()[0];
        $plain = WireStatement::parse(L2Fixtures::envelope($vector1['canonical'], $vector1['signature']));
        $spaced = WireStatement::parse(' { "payload" : ' . $vector1['canonical'] . ' , "signature" : "' . $vector1['signature'] . '" } ');

        $this->assertSame($plain->canonicalPayloadOctets(), $spaced->canonicalPayloadOctets());
    }
}
