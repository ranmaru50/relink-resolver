<?php
// tests/L2/Stage1Ed25519VerifierTest.php
// Ed25519 署名検証アダプタのテスト（spec resolver-l2-0.1-draft §10.8, RFC 8032）。
// 署名対象は payload の JCS canonical オクテット、publicKey 32 オクテット、
// signature 64 オクテット（base64url '=' 除去、86 chars）。
// 該当 Catalog case: L2-VER-005（pinned 署名ベクトル検証）、L2-VER-012
// （未対応アルゴリズムは検証を試みない fail-closed）、L2-VER-004/005 結合経路。
// ES256 はこの Stage の対象外（optional、§10.8）。

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Relink\Resolver\Adapters\SodiumEd25519Verifier;
use Relink\Resolver\Domain\L2\Base64Url;
use Relink\Resolver\Domain\L2\KeyId;
use Relink\Resolver\Domain\L2\Verdict;
use Relink\Resolver\Domain\L2\WireStatement;

require_once __DIR__ . '/L2Fixtures.php';

final class Stage1Ed25519VerifierTest extends TestCase
{
    private SodiumEd25519Verifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new SodiumEd25519Verifier();
    }

    /**
     * @return Generator<array{string, string, string, string}>
     */
    public static function signedVectorProvider(): Generator
    {
        foreach (L2Fixtures::signedVectors() as $vector) {
            yield [$vector['id'], $vector['canonical'], $vector['signature'], $vector['signerPublicKey']];
        }
    }

    /**
     * L2-VER-005: すべての pinned 署名ベクトル（付録 A + Catalog S9 family）が
     * signer publicKey と canonical payload octets 上で VERIFIED になる。
     */
    #[DataProvider('signedVectorProvider')]
    public function testPinnedSignedVectorsVerifyUnderSignerPublicKey(string $id, string $canonical, string $signature, string $signerPublicKey): void
    {
        $this->assertSame(
            Verdict::VERIFIED,
            $this->verifier->verify('ed25519', $signerPublicKey, $signature, $canonical),
            $id
        );
    }

    /**
     * @return Generator<array{string, string, string, string}>
     */
    public static function flippedSignatureProvider(): Generator
    {
        foreach (L2Fixtures::signedVectors() as $vector) {
            $octets = Base64Url::decode($vector['signature']);
            $octets[0] = chr(ord($octets[0]) ^ 0x01);
            yield [$vector['id'], $vector['canonical'], Base64Url::encode($octets), $vector['signerPublicKey']];
        }
    }

    /**
     * signature の 1 バイトを反転すると検証は INVALID になる（fail-closed）。
     */
    #[DataProvider('flippedSignatureProvider')]
    public function testFlippedSignatureByteIsInvalid(string $id, string $canonical, string $flippedSignature, string $signerPublicKey): void
    {
        $this->assertSame(Verdict::INVALID, $this->verifier->verify('ed25519', $signerPublicKey, $flippedSignature, $canonical), $id);
    }

    /**
     * 署名対象 octets を改変（generation を 7→8）すると検証は INVALID になる。
     */
    public function testTamperedSigningTargetIsInvalid(): void
    {
        $vector1 = L2Fixtures::signedVectors()[0];
        $tamperedTarget = str_replace('"generation":7', '"generation":8', $vector1['canonical']);
        $this->assertNotSame($vector1['canonical'], $tamperedTarget);

        $this->assertSame(
            Verdict::INVALID,
            $this->verifier->verify('ed25519', $vector1['signerPublicKey'], $vector1['signature'], $tamperedTarget)
        );
    }

    /**
     * key1 の署名を key2 で検証すると INVALID になる（keyId 分離の前提）。
     */
    public function testSignatureDoNotVerifyUnderOtherPinnedKey(): void
    {
        $vector1 = L2Fixtures::signedVectors()[0];
        $key2 = L2Fixtures::keys()['key2'];

        $this->assertSame(
            Verdict::INVALID,
            $this->verifier->verify('ed25519', $key2['publicKey'], $vector1['signature'], $vector1['canonical'])
        );
    }

    /**
     * L2-VER-012 / §10.8: 未対応アルゴリズムは検証を試みず UNSUPPORTED_ALGORITHM。
     * ES256 は optional でこの Stage の対象外（§10.8）、"ed9819" も same。
     * supports() が false のアルゴリズムでは verify() は sodium 呼び出しに到達しない。
     */
    public function testUnsupportedAlgorithmsNeverAttemptVerification(): void
    {
        $vector1 = L2Fixtures::signedVectors()[0];

        $unsupported = ['ES256', 'ed9819', 'Ed25519', 'es256', 'ed25519 ', 'ES256P256'];

        foreach ($unsupported as $algorithm) {
            $this->assertFalse($this->verifier->supports($algorithm), $algorithm . ' must not be supported');
            $this->assertSame(
                Verdict::UNSUPPORTED_ALGORITHM,
                $this->verifier->verify($algorithm, $vector1['signerPublicKey'], $vector1['signature'], $vector1['canonical']),
                $algorithm
            );
        }

        // 正準アルゴリズム id は 'ed25519' のみ supported
        $this->assertTrue($this->verifier->supports('ed25519'));
    }

    /**
     * §10.8: publicKey は 32 オクテットでなければならない（31/33 は INVALID）。
     */
    public function testPublicKeyMustBeExactly32Octets(): void
    {
        $vector1 = L2Fixtures::signedVectors()[0];
        $keyOctets = Base64Url::decode($vector1['signerPublicKey']);

        $shortKey = Base64Url::encode(substr($keyOctets, 0, 31));
        $longKey = Base64Url::encode($keyOctets . "\x00");

        $this->assertSame(Verdict::INVALID, $this->verifier->verify('ed25519', $shortKey, $vector1['signature'], $vector1['canonical']));
        $this->assertSame(Verdict::INVALID, $this->verifier->verify('ed25519', $longKey, $vector1['signature'], $vector1['canonical']));
    }

    /**
     * §10.8 / RFC 8032: signature は 64 オクテットでなければならない（63/65 は INVALID）。
     */
    public function testSignatureMustBeExactly64Octets(): void
    {
        $vector1 = L2Fixtures::signedVectors()[0];
        $sigOctets = Base64Url::decode($vector1['signature']);

        $shortSig = Base64Url::encode(substr($sigOctets, 0, 63));
        $longSig = Base64Url::encode($sigOctets . "\x00");

        $this->assertSame(Verdict::INVALID, $this->verifier->verify('ed25519', $vector1['signerPublicKey'], $shortSig, $vector1['canonical']));
        $this->assertSame(Verdict::INVALID, $this->verifier->verify('ed25519', $vector1['signerPublicKey'], $longSig, $vector1['canonical']));
    }

    /**
     * 正準 base64url（'=' 除去）でない signature は INVALID（86 chars + '='）。
     */
    public function testSignatureWithPaddingIsInvalidAtVerifier(): void
    {
        $vector1 = L2Fixtures::signedVectors()[0];

        $this->assertSame(
            Verdict::INVALID,
            $this->verifier->verify('ed25519', $vector1['signerPublicKey'], $vector1['signature'] . '=', $vector1['canonical'])
        );
    }

    /**
     * 空 / 不正長さの base64url signature は fail-closed で INVALID。
     */
    public function testMalformedSignatureStringsRejectedFailClosed(): void
    {
        $vector1 = L2Fixtures::signedVectors()[0];

        $this->assertSame(Verdict::INVALID, $this->verifier->verify('ed25519', $vector1['signerPublicKey'], '', $vector1['canonical']));
        $this->assertSame(Verdict::INVALID, $this->verifier->verify('ed25519', $vector1['signerPublicKey'], 'A', $vector1['canonical']));
    }

    /**
     * wire → verifier の統合: WireStatement が保持する canonical octets と
     * signature で検証でき、payload keyId は signer publicKey から再計算一致する
     * （L2-VER-004 と L2-VER-005 の結合経路、§15.2 phase 1→8）。
     */
    public function testParsedWireStatementVerifiesAndKeyIdRecomputes(): void
    {
        $vector1 = L2Fixtures::signedVectors()[0];
        $statement = WireStatement::parse(L2Fixtures::envelope($vector1['canonical'], $vector1['signature']));

        $this->assertSame(
            Verdict::VERIFIED,
            $this->verifier->verify(
                $statement->algorithm(),
                $vector1['signerPublicKey'],
                $statement->signatureBase64Url(),
                $statement->canonicalPayloadOctets()
            )
        );

        $payload = $statement->payload();
        $this->assertSame('ECMERdvpV0k', $payload['keyId']);
        $this->assertTrue(KeyId::matches($payload['keyId'], Base64Url::decode($vector1['signerPublicKey'])));
    }

    /**
     * Verdict の判定語彙（§15）と合一致性カタログ結果語彙（Catalog §2）の verbatim 固定。
     */
    public function testVerdictVocabularyIsPinned(): void
    {
        $this->assertSame([
            'VERIFIED',
            'INVALID',
            'ANCHOR_MISMATCH',
            'UNSUPPORTED_ALGORITHM',
            'UNVERIFIED',
            'UNKNOWN_AUTHORITY',
            'STALE',
            'CONFLICT',
            'REVOKED',
            'EXPIRED',
            'MISMATCH',
        ], array_map(static fn (Verdict $verdict): string => $verdict->value, Verdict::cases()));

        $this->assertSame('PASS', Verdict::PASS);
        $this->assertSame('FAIL', Verdict::FAIL);
        $this->assertSame('PASS-WITH-DEVIATION', Verdict::PASS_WITH_DEVIATION);
        $this->assertSame('NOT-APPLICABLE', Verdict::NOT_APPLICABLE);
        $this->assertSame('UNSUPPORTED-OPTIONAL', Verdict::UNSUPPORTED_OPTIONAL);

        $this->assertTrue(Verdict::VERIFIED->isVerified());
        $this->assertFalse(Verdict::INVALID->isVerified());
    }
}
