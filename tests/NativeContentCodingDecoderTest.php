<?php
// tests/NativeContentCodingDecoderTest.php
// 圧縮応答を展開前に拒否し、圧縮爆弾を発生させない境界を検証する。

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Adapters\NativeContentCodingDecoder;

final class NativeContentCodingDecoderTest extends TestCase
{
    /** identity 表現はそのまま扱うことを確認する。 */
    public function testIdentityContentCodingIsAccepted(): void
    {
        self::assertSame('body', NativeContentCodingDecoder::decode('body', 'identity'));
        self::assertSame('body', NativeContentCodingDecoder::decode('body', null));
    }

    /** 圧縮された巨大展開候補を inflate せずに拒否することを確認する。 */
    public function testCompressedContentCodingIsRejectedBeforeDecompression(): void
    {
        $compressedBombFixture = gzencode(str_repeat('x', 1024 * 1024));
        self::assertIsString($compressedBombFixture);

        $this->expectExceptionMessage('OUTBOUND_ENCODING_UNSUPPORTED');
        NativeContentCodingDecoder::decode($compressedBombFixture, 'gzip');
    }
}
