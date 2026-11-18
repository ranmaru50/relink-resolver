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
use Relink\Resolver\Domain\L2\Base64Url;
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

        // envelope 余分メンバ: §7.5 で payload 外の extension は署名対象外、受理し無視する
        // （positive: testUnsignedOuterEnvelopeExtensionIsIgnoredNotRejected）

        // payload が object でない
        yield ['payload-not-object', '{"payload":5,"signature":"' . $signature . '"}'];

        // payload が空 object: JCS canonical `{}` は空 object に round-trip する
        // （positive: testEmptyPayloadObjectRoundTripsAsEmptyObject）

        // signature が JSON string でない
        yield ['signature-not-a-string', '{"payload":' . $canonical . ',"signature":7}'];

        // signature に '=' パディング（正準 base64url は '=' 除去）
        yield ['signature-with-padding', L2Fixtures::envelope($canonical, $signature . '=')];

        // signature に base64url 非アルファベット（'+'）
        yield ['signature-plus-character', L2Fixtures::envelope($canonical, str_replace('-', '+', $signature))];

        // signature の 64 オクテット長は phase 1 では検証しない（§10.2, §15.2 phase 1 の
        // wire 検査は base64url 形状のみ; 長失敗は phase 8 の署名検証）
        // （positive: testShortSignatureDecodesAtParseWithoutLengthCheck）

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

    /**
     * §7.3 (P2-3): an empty payload `{}` round-trips as an empty JCS object through wire
     * canonicalization; wire parse does not reject it as a list ambiguity. Statement-level
     * required-member validation is a separate phase (StatementPayload / §9.5).
     */
    public function testEmptyPayloadObjectRoundTripsAsEmptyObject(): void
    {
        $vector = self::vectorById('S7');
        $wire = L2Fixtures::envelope('{}', $vector['signature']);
        $statement = WireStatement::parse($wire);

        $this->assertSame('{}', $statement->canonicalPayloadOctets());
        $this->assertSame([], $statement->payload());
    }

    /**
     * §7.5 (P2-3): an empty object extension member inside the payload must round-trip as
     * an object through wire canonicalization, never as an empty list.
     */
    public function testEmptyObjectExtensionInsidePayloadRoundTripsAsObject(): void
    {
        $vector = self::vectorById('S7');
        $canonical = str_replace('"generation":1', '"ext":{},"generation":1', $vector['canonical']);
        $statement = WireStatement::parse(L2Fixtures::envelope($canonical, $vector['signature']));

        $this->assertSame($canonical, $statement->canonicalPayloadOctets());
        $this->assertSame([], $statement->payload()['ext']);
    }

    /**
     * §7.5 (P2-3): a numeric-keyed object extension member inside the payload must
     * round-trip as an object with the numeric string key preserved.
     */
    public function testNumberKeyObjectExtensionInsidePayloadRoundTrips(): void
    {
        $vector = self::vectorById('S7');
        $canonical = str_replace('"generation":1', '"ext":{"0":"x"},"generation":1', $vector['canonical']);
        $statement = WireStatement::parse(L2Fixtures::envelope($canonical, $vector['signature']));

        $this->assertSame($canonical, $statement->canonicalPayloadOctets());
        $this->assertSame(['0' => 'x'], $statement->payload()['ext']);
    }

    /**
     * §7.5: unsigned extension members outside the payload are not signature-covered and
     * MUST be ignored for trust decisions, not rejected at wire parse.
     */
    public function testUnsignedOuterEnvelopeExtensionIsIgnoredNotRejected(): void
    {
        $vector = self::vectorById('S7');
        $wire = '{"payload":' . $vector['canonical'] . ',"signature":"' . $vector['signature'] . '","ext":{}}';
        $statement = WireStatement::parse($wire);

        $this->assertSame($vector['canonical'], $statement->canonicalPayloadOctets());
        $this->assertSame($vector['signature'], $statement->signatureBase64Url());
    }

    /**
     * §15.2 phase 1 (P2-2): wire parse validates base64url shape but NOT Ed25519 signature
     * octets length; length failures belong to phase 8 verification under the signer key.
     */
    public function testShortSignatureDecodesAtParseWithoutLengthCheck(): void
    {
        $vector = self::vectorById('S7');
        $shortSignature = Base64Url::encode(str_repeat('x', 32));
        $statement = WireStatement::parse(L2Fixtures::envelope($vector['canonical'], $shortSignature));

        $this->assertSame($vector['canonical'], $statement->canonicalPayloadOctets());
        $this->assertSame($shortSignature, $statement->signatureBase64Url());
    }

    /**
     * §15.2 phase 1 (Mochi P2-5): an integer field carrying an array value is rejected
     * fail-closed, not passed through by array recursion.
     */
    public function testIntegerFieldWithArrayValueIsRejectedAtParse(): void
    {
        $vector = self::vectorById('S7');
        $canonical = str_replace('"generation":1', '"generation":[1]', $vector['canonical']);

        $this->expectException(WireStatementException::class);
        WireStatement::parse(L2Fixtures::envelope($canonical, $vector['signature']));
    }

    /**
     * §7.3, §15.2 phase 1 (Mochi P2-6): the 2^53-1 integer boundary is accepted as the
     * canonical wire integer form.
     */
    public function testIntegerFieldAtTwoToThe53MinusOneIsAcceptedAtParse(): void
    {
        $vector = self::vectorById('S7');
        $canonical = str_replace('"generation":1', '"generation":9007199254740991', $vector['canonical']);
        $statement = WireStatement::parse(L2Fixtures::envelope($canonical, $vector['signature']));

        $this->assertSame(9007199254740991, $statement->payload()['generation']);
    }

    /**
     * §7.3, §15.2 phase 1 (Mochi P2-6): 2^53 exceeds the canonical wire integer range and
     * is rejected fail-closed.
     */
    public function testIntegerFieldAtTwoToThe53IsRejectedAtParse(): void
    {
        $vector = self::vectorById('S7');
        $canonical = str_replace('"generation":1', '"generation":9007199254740992', $vector['canonical']);

        $this->expectException(WireStatementException::class);
        WireStatement::parse(L2Fixtures::envelope($canonical, $vector['signature']));
    }

    /**
     * §7.3 (Mochi P2-6): an exponent form inside an integer field fails wire canonicality
     * at phase 1; no string-based numeric coercion is applied.
     */
    public function testExponentFormInIntegerFieldFailsCanonicalityAtParse(): void
    {
        $vector = self::vectorById('S7');
        $canonical = str_replace('"generation":1', '"generation":1e15', $vector['canonical']);

        $this->expectException(WireStatementException::class);
        WireStatement::parse(L2Fixtures::envelope($canonical, $vector['signature']));
    }

    /**
     * §7.3 (Mochi P2-6): a decimal form inside an integer field fails wire canonicality
     * at phase 1.
     */
    public function testDecimalFormInIntegerFieldFailsCanonicalityAtParse(): void
    {
        $vector = self::vectorById('S7');
        $canonical = str_replace('"generation":1', '"generation":1.0', $vector['canonical']);

        $this->expectException(WireStatementException::class);
        WireStatement::parse(L2Fixtures::envelope($canonical, $vector['signature']));
    }

    /**
     * P1-2 (PR #31 review round 2, §7.3/§7.5/L2-VER-046): an extension member carrying the
     * ES262 fixed-point canonical decimal form 0.00001 is canonical on the wire, so the
     * envelope parses and the canonical octets are preserved byte-for-byte.
     */
    public function testCanonicalDecimalExtensionFloatIsAcceptedAtWireCanonicality(): void
    {
        $vector = self::vectorById('S5');
        $canonical = str_replace('"generation":', '"extDemo":0.00001,"generation":', $vector['canonical']);
        self::assertStringContainsString('"extDemo":0.00001', $canonical, 'fixture guard: the fixed-point form must sit inside the canonical payload');

        $statement = WireStatement::parse(L2Fixtures::envelope($canonical, $vector['signature']));

        $this->assertSame($canonical, $statement->canonicalPayloadOctets(), 'P1-2: 0.00001 is the canonical ES262 shortest form of 1e-5 on the wire (§7.3)');
        $this->assertSame(0.00001, $statement->payload()['extDemo'], '§7.5: the extension member is accepted as payload content (never security-critical)');
    }

    /**
     * P1-2 (PR #31 review round 2, §7.3/§15.2 phase 1/L2-VER-046): the non-canonical
     * exponential wire form 1e-5 fails wire canonicality at phase 1 → INVALID; canonicalizing
     * the decoded payload yields the fixed-point form 0.00001, so the wire bytes do not match.
     */
    public function testNonCanonicalExponentialExtensionFloatFailsWireCanonicalityAtParse(): void
    {
        $vector = self::vectorById('S5');
        $canonical = str_replace('"generation":', '"extDemo":1e-5,"generation":', $vector['canonical']);

        $this->expectException(WireStatementException::class);
        WireStatement::parse(L2Fixtures::envelope($canonical, $vector['signature']));
    }

    /**
     * @return array{canonical: string, signature: string, signerPublicKey: string}
     */
    private static function vectorById(string $id): array
    {
        foreach (L2Fixtures::signedVectors() as $vector) {
            if ($vector['id'] === $id) {
                return $vector;
            }
        }

        throw new RuntimeException('unknown fixture id: ' . $id);
    }
}
