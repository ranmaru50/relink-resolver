<?php
// src/Domain/L2/Base64Url.php
// RFC 4648 §5 base64url（'=' パディング除去）のエンコード/デコード。
// 非正準なアルファベット、余分なビット、'=' を含む文字列は fail-closed で拒否する。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

final class Base64Url
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

    public static function encode(string $octets): string
    {
        return rtrim(strtr(base64_encode($octets), '+/', '-_'), '=');
    }

    /**
     * 正準な base64url 文字列（'=' 除去）をオクテット列へ復元する。
     *
     * @throws Base64UrlException 非正準、非アルファベット、不正長さ
     */
    public static function decode(string $text): string
    {
        $length = strlen($text);
        if ($length === 0 || $length % 4 === 1) {
            throw new Base64UrlException('base64url length is invalid');
        }
        if (strspn($text, self::ALPHABET) !== $length) {
            throw new Base64UrlException('base64url text contains non-alphabet characters');
        }
        $padded = $text . str_repeat('=', (4 - $length % 4) % 4);
        $octets = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($octets === false) {
            throw new Base64UrlException('base64url text is not decodable');
        }
        if (self::encode($octets) !== $text) {
            throw new Base64UrlException('base64url text is not canonical');
        }

        return $octets;
    }

    public static function isValid(string $text): bool
    {
        try {
            self::decode($text);

            return true;
        } catch (Base64UrlException) {
            return false;
        }
    }
}
