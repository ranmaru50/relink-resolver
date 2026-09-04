<?php
// src/Adapters/NativeContentCodingDecoder.php
// 圧縮爆弾を防ぐため、標準 outbound adapter が扱う content-coding を限定する。

declare(strict_types=1);

namespace Relink\Resolver\Adapters;

use RuntimeException;

final class NativeContentCodingDecoder
{
    public static function decode(string $body, ?string $encoding): string
    {
        $encoding = strtolower(trim((string) $encoding));
        if ($encoding === '' || $encoding === 'identity') {
            return $body;
        }
        // 一括 inflate は圧縮爆弾で上限検査前にメモリを消費するため行わない。
        throw new RuntimeException('OUTBOUND_ENCODING_UNSUPPORTED');
    }
}
