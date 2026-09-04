<?php
// tests/OutboundNetworkPolicyTest.php
// 接続実アドレスに対する設定可能な CIDR ポリシーを検証する。

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Application\OutboundNetworkPolicy;

final class OutboundNetworkPolicyTest extends TestCase
{
    /** 許可 CIDR と拒否 CIDR が実際の接続候補へ適用されることを確認する。 */
    public function testAllowedAddressesAreFilteredByConfiguredPolicy(): void
    {
        $policy = new OutboundNetworkPolicy(['203.0.113.0/24'], ['203.0.113.9/32']);

        self::assertSame(['203.0.113.8'], $policy->allowedAddresses(['203.0.113.8', '203.0.114.8', '203.0.113.9']));
    }

    /** 候補がすべて拒否されたときは接続前に失敗することを確認する。 */
    public function testDeniedAddressesFailClosedBeforeConnection(): void
    {
        $policy = new OutboundNetworkPolicy([], ['192.0.2.0/24']);

        $this->expectExceptionMessage('OUTBOUND_NETWORK_DENIED');
        $policy->allowedAddresses(['192.0.2.10']);
    }
}
