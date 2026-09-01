<?php
// tests/TrustedProxyPolicyTest.php
// TLS 終端プロキシの信頼境界を検証する。

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Application\TrustedProxyPolicy;

final class TrustedProxyPolicyTest extends TestCase
{
    /** HTTPS サーバー変数はプロキシ設定なしでも安全な要求として扱う。 */
    public function testServerHttpsTakesPrecedence(): void
    {
        self::assertTrue(TrustedProxyPolicy::isSecureRequest(['HTTPS' => 'on'], []));
    }

    /** 信頼されていない送信元の転送ヘッダーを無視する。 */
    public function testForwardedProtoFromUntrustedAddressIsIgnored(): void
    {
        self::assertFalse(TrustedProxyPolicy::isSecureRequest([
            'REMOTE_ADDR' => '198.51.100.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], ['10.0.0.0/8']));
    }

    /** 信頼済みプロキシの CIDR 内からの HTTPS 転送だけを受け入れる。 */
    public function testForwardedProtoFromTrustedCidrIsAccepted(): void
    {
        self::assertTrue(TrustedProxyPolicy::isSecureRequest([
            'REMOTE_ADDR' => '10.12.0.8',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], ['10.0.0.0/8']));
        self::assertFalse(TrustedProxyPolicy::isSecureRequest([
            'REMOTE_ADDR' => '10.12.0.8',
            'HTTP_X_FORWARDED_PROTO' => 'http, https',
        ], ['10.0.0.0/8']));
        self::assertFalse(TrustedProxyPolicy::isSecureRequest([
            'REMOTE_ADDR' => '10.12.0.8',
            'HTTP_X_FORWARDED_PROTO' => 'https, http',
        ], ['10.0.0.0/8']));
    }

    /** IPv6 の信頼済み CIDR も同じ規則で評価する。 */
    public function testIpv6TrustedCidrIsSupported(): void
    {
        self::assertTrue(TrustedProxyPolicy::isSecureRequest([
            'REMOTE_ADDR' => '2001:db8:1234::20',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], ['2001:db8:1234::/48']));
    }
}
