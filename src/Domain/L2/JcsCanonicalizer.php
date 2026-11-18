<?php
// src/Domain/L2/JcsCanonicalizer.php
// RFC 8785 (JCS) 正準 JSON シリアライザ（spec resolver-l2-0.1-draft §7.3）。
// オブメントメンバはメンバ名の UTF-16 コード単位順（§7.3、付録 A.4）。
// スカラーコードポイント順ではない。エスケープは RFC 8785 §3.2.2.2 に従う。
// U+2028, U+2029, U+007F は生のまま出力し、エスケープしない。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

final class JcsCanonicalizer
{
    /**
     * JSON デコード済み PHP 値（連想配列/リスト/scalar）を JCS 正準文字列化する。
     *
     * 整数は wire 整数として、浮動小数は ES262 Number#toString の最短形式で出力する。
     * 空の連想配列（PHP では空オブジェクト {} のデコード結果）は空リスト [] とみなす。
     *
     * @param mixed $value
     *
     * @throws CanonicalizationException 対象化できない入力（NaN/INF/非 UTF-8 など）
     */
    public static function canonicalize(mixed $value): string
    {
        return self::encode($value);
    }

    /**
     * @param mixed $value
     */
    private static function encode(mixed $value): string
    {
        if (is_string($value)) {
            return self::encodeString($value);
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return self::encodeFloat($value);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_null($value)) {
            return 'null';
        }
        if ($value instanceof JcsObject) {
            // JSON object identity: {} canonicalizes as {} (empty object), never as []
            return self::encodeObject($value->members);
        }
        if (is_array($value)) {
            /** @var array<array-key, mixed> $value */
            if (array_is_list($value)) {
                return self::encodeList($value);
            }

            return self::encodeObject($value);
        }

        throw new CanonicalizationException('value is not canonicalizable: ' . gettype($value));
    }

    /**
     * @param array<array-key, mixed> $list
     */
    private static function encodeList(array $list): string
    {
        $parts = [];
        foreach ($list as $item) {
            $parts[] = self::encode($item);
        }

        return '[' . implode(',', $parts) . ']';
    }

    /**
     * メンバを UTF-16 コード単位順にソートして出力する（RFC 8785 §3.2.3）。
     *
     * @param array<array-key, mixed> $object
     */
    private static function encodeObject(array $object): string
    {
        $keys = array_keys($object);
        usort($keys, static function (int|string $a, int|string $b): int {
            return strcmp(
                mb_convert_encoding((string) $a, 'UTF-16BE', 'UTF-8'),
                mb_convert_encoding((string) $b, 'UTF-16BE', 'UTF-8')
            );
        });

        $parts = [];
        foreach ($keys as $key) {
            $parts[] = self::encodeString((string) $key) . ':' . self::encode($object[$key]);
        }

        return '{' . implode(',', $parts) . '}';
    }

    /**
     * RFC 8785 §3.2.2.2 に従い、エスケープは必要な最小限のみ。
     * U+2028 / U+2029 / U+007F は生で出力。C0 制御文字のうち
     * \b \t \n \f \r は短縮形、残りは小文字 hex の \uXXXX。
     */
    private static function encodeString(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new CanonicalizationException('string is not valid UTF-8');
        }

        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            throw new CanonicalizationException('string cannot be split into code points');
        }

        $out = '"';
        foreach ($characters as $character) {
            // $text は UTF-8 検証済み、$characters は code point 単位分割なので mb_ord は成功する
            $code = mb_ord($character, 'UTF-8');
            switch ($code) {
                case 0x22:
                    $out .= '\\"';
                    break;
                case 0x5C:
                    $out .= '\\\\';
                    break;
                case 0x08:
                    $out .= '\\b';
                    break;
                case 0x09:
                    $out .= '\\t';
                    break;
                case 0x0A:
                    $out .= '\\n';
                    break;
                case 0x0C:
                    $out .= '\\f';
                    break;
                case 0x0D:
                    $out .= '\\r';
                    break;
                default:
                    if ($code < 0x20) {
                        $out .= sprintf('\\u%04x', $code);
                    } else {
                        $out .= $character;
                    }
            }
        }

        return $out . '"';
    }

    /**
     * ES262 Number#toString と同じ最短形式で浮動小数を出力する。
     * n（仮数倍値の小数点位置）と k（有効 digits 数）で形式を決める：
     *   k <= n <= 21      → 整数形式（digits + zeros）
     *   1 <= n <= 21      → 小数点形式（digits.split(n)）
     *   -3 <= n <= 0      → 0. + zeros + digits
     *   n >= 22 または n <= -4 → 指数形式 d(.digits)e±E
     * （例：0.0001 → "0.0001"、1e-5 → "1e-5"、1e21 → "1e+21"）。
     */
    private static function encodeFloat(float $value): string
    {
        if (is_nan($value)) {
            throw new CanonicalizationException('NaN cannot be canonicalized');
        }
        if (is_infinite($value)) {
            throw new CanonicalizationException('infinite value cannot be canonicalized');
        }
        if ($value == 0.0) {
            return '0';
        }
        if ($value < 0.0) {
            return '-' . self::encodeFloat(-$value);
        }

        $formatted = null;
        for ($precision = 0; $precision <= 17; $precision++) {
            $candidate = sprintf('%.' . $precision . 'E', $value);
            if ((float) $candidate === $value) {
                $formatted = $candidate;
                break;
            }
        }
        if ($formatted === null) {
            throw new CanonicalizationException('float has no shortest round-trip form');
        }

        [$mantissa, $exponentPart] = explode('E', $formatted);
        $digits = rtrim(str_replace('.', '', $mantissa), '0');
        $digits = $digits === '' ? '0' : $digits;
        $k = strlen($digits);
        $n = (int) $exponentPart + 1;

        if ($k <= $n && $n <= 21) {
            return $digits . str_repeat('0', $n - $k);
        }
        if ($n >= 1 && $n <= 21) {
            return substr($digits, 0, $n) . '.' . substr($digits, $n);
        }
        // ES262 Number::toString fixed-point branch (RFC 8785 §3.4, ECMA-262 7.1.12.10,
        // step 5): fixed-point form when 0 < n <= 21 (integer part), 1 < n <= 21 (fraction
        // part), and -6 < n <= 0 (small magnitudes, n = 1 + exponent of the 10^k form).
        // With this bound, 1e-5 (n = -4) and 1e-6 (n = -5, the RFC 8785 Appendix B boundary
        // vector) are fixed-point '0.00001'/'0.000001', while 1e-7 (n = -6) is exponential.
        if ($n > -6 && $n <= 0) {
            return '0.' . str_repeat('0', -$n) . $digits;
        }

        $exponentValue = $n - 1;
        $head = $digits[0];
        $fraction = $k > 1 ? '.' . substr($digits, 1) : '';
        $sign = $exponentValue >= 0 ? '+' : '-';

        return $head . $fraction . 'e' . $sign . abs($exponentValue);
    }
}
