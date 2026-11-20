<?php
// tests/L2/Stage1KeyIdTest.php
// keyId 規則のテスト（spec resolver-l2-0.1-draft §9.4, §10.1, 付録 A.1/A.5）。
// keyId = SHA-256(publicKey 生オクテット) の先頭 8 バイトの base64url（'=' 除去）。
// L2-VER-004（publicKey から keyId を再計算し照合する fail-closed 規則）に対応。

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Relink\Resolver\Domain\L2\Base64Url;
use Relink\Resolver\Domain\L2\KeyId;

require_once __DIR__ . '/L2Fixtures.php';

final class Stage1KeyIdTest extends TestCase
{
    /**
     * @return Generator<array{string, string, string, string, string}>
     */
    public static function pinnedKeyProvider(): Generator
    {
        foreach (L2Fixtures::keys() as $name => $key) {
            yield [$name, $key['keyId'], $key['publicKey'], $key['publicHex'], $key['shaHex']];
        }
    }

    /**
     * A.5: wire publicKey が pinned public hex と verbatim に一致し、
     * SHA-256 hex と keyId が verbatim に再計算される。
     */
    #[DataProvider('pinnedKeyProvider')]
    public function testPinnedKeysRecomputeTheirPinnedKeyIds(string $name, string $keyId, string $publicKeyWire, string $publicHex, string $shaHex): void
    {
        $publicOctets = Base64Url::decode($publicKeyWire);

        // wire publicKey → 付録 A.5 の pinned public hex（verbatim）
        $this->assertSame(hex2bin($publicHex), $publicOctets, $name . ' wire publicKey pinned');

        // SHA-256(publicKey 生オクテット) の pinned hex（verbatim）
        $this->assertSame($shaHex, bin2hex(hash('sha256', $publicOctets, true)), $name . ' SHA-256 pinned');

        // keyId = base64url(SHA-256 先頭 8 バイト, '=' 除去)（verbatim）
        $this->assertSame($keyId, KeyId::compute($publicOctets), $name . ' keyId pinned');
        $this->assertSame(11, strlen($keyId), $name . ' keyId is 11 chars (8 bytes base64url)');
        $this->assertTrue(KeyId::matches($keyId, $publicOctets), $name . ' matches');
    }

    /**
     * A.1: private seed = SHA-256(seed string)。pinned seed hex と verbatim に一致する。
     */
    public function testKey1PrivateSeedIsSha256OfPinnedSeedString(): void
    {
        $this->assertSame(
            L2Fixtures::KEY1_PRIVATE_SEED_HEX,
            hash('sha256', L2Fixtures::SEED_STRING_1),
            'A.1 seed derivation pinned'
        );
    }

    /**
     * §9.4: keyId の大文字小文字は保存される（case-preserved照合）。
     */
    public function testKeyIdCaseIsPreservedAndMustMatchExactly(): void
    {
        $publicOctets = Base64Url::decode(L2Fixtures::keys()['key1']['publicKey']);
        $keyId = KeyId::compute($publicOctets);

        $this->assertSame('ECMERdvpV0k', $keyId);
        $this->assertFalse(KeyId::matches(strtoupper($keyId), $publicOctets), 'upper-cased keyId must not match');
        $this->assertFalse(KeyId::matches(strtolower($keyId), $publicOctets), 'lower-cased keyId must not match');
    }

    /**
     * §9.4 fail-closed: publicKey の 1 バイトを反転すると keyId 照合は失敗する。
     */
    public function testFlippedPublicKeyByteBreaksKeyIdMatch(): void
    {
        $publicOctets = Base64Url::decode(L2Fixtures::keys()['key1']['publicKey']);
        $tampered = chr(ord($publicOctets[0]) ^ 0x01) . substr($publicOctets, 1);

        $this->assertFalse(KeyId::matches('ECMERdvpV0k', $tampered), 'tampered publicKey must not match pinned keyId');
        $this->assertNotSame('ECMERdvpV0k', KeyId::compute($tampered));
    }

    /**
     * §9.4: wire keyId が一致しない publicKey は fail-closed で拒否される。
     */
    public function testWrongWireKeyIdIsRejectedFailClosed(): void
    {
        $key1 = L2Fixtures::keys()['key1'];
        $key2 = L2Fixtures::keys()['key2'];

        $key1Octets = Base64Url::decode($key1['publicKey']);
        $key2Octets = Base64Url::decode($key2['publicKey']);

        $this->assertFalse(KeyId::matches($key2['keyId'], $key1Octets));
        $this->assertFalse(KeyId::matches($key1['keyId'], $key2Octets));
        $this->assertFalse(KeyId::matches('ECMERdvpV0', $key1Octets));
    }
}
