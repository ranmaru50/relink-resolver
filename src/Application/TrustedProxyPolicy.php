<?php
// src/Application/TrustedProxyPolicy.php
// 管理面がプロキシ由来の HTTPS 情報を信頼できる条件を判定する。

declare(strict_types=1);

namespace Relink\Resolver\Application;

final class TrustedProxyPolicy
{
    /**
     * Web サーバーの HTTPS 情報と、明示的に許可したプロキシのヘッダーだけを評価する。
     *
     * @param array<string, mixed> $server
     * @param list<string> $trustedProxyCidrs
     */
    public static function isSecureRequest(array $server, array $trustedProxyCidrs): bool
    {
        $https = strtolower(trim((string) ($server['HTTPS'] ?? '')));
        if (in_array($https, ['on', '1', 'https'], true)) {
            return true;
        }

        $remoteAddress = (string) ($server['REMOTE_ADDR'] ?? '');
        if ($remoteAddress === '' || !self::matchesAny($remoteAddress, $trustedProxyCidrs)) {
            return false;
        }

        // 先頭要素だけを使い、プロキシ側でクライアント入力を上書きする運用を前提にする。
        $forwardedProto = strtolower(trim(explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''), 2)[0]));
        return $forwardedProto === 'https';
    }

    /**
     * @param list<string> $networks
     */
    private static function matchesAny(string $address, array $networks): bool
    {
        foreach ($networks as $network) {
            if (self::matchesNetwork($address, trim($network))) {
                return true;
            }
        }
        return false;
    }

    private static function matchesNetwork(string $address, string $network): bool
    {
        if ($network === '') {
            return false;
        }
        if (!str_contains($network, '/')) {
            return hash_equals($network, $address);
        }

        [$networkAddress, $prefixLength] = explode('/', $network, 2);
        $addressBinary = inet_pton($address);
        $networkBinary = inet_pton($networkAddress);
        $prefix = filter_var($prefixLength, FILTER_VALIDATE_INT);
        if ($addressBinary === false || $networkBinary === false || $prefix === false || strlen($addressBinary) !== strlen($networkBinary)) {
            return false;
        }

        $maxPrefix = strlen($addressBinary) * 8;
        if ($prefix < 0 || $prefix > $maxPrefix) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        if ($fullBytes > 0 && substr($addressBinary, 0, $fullBytes) !== substr($networkBinary, 0, $fullBytes)) {
            return false;
        }
        if ($fullBytes === strlen($addressBinary)) {
            return true;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($addressBinary[$fullBytes]) & $mask) === (ord($networkBinary[$fullBytes]) & $mask);
    }
}
