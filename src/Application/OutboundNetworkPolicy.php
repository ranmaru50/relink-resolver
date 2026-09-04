<?php
// src/Application/OutboundNetworkPolicy.php
// 管理 outbound fetch の接続先アドレスに適用する設定可能なネットワークポリシー。

declare(strict_types=1);

namespace Relink\Resolver\Application;

use InvalidArgumentException;
use RuntimeException;

final readonly class OutboundNetworkPolicy
{
    /**
     * @param list<string> $allowedCidrs
     * @param list<string> $deniedCidrs
     */
    public function __construct(private array $allowedCidrs = [], private array $deniedCidrs = [])
    {
        foreach (array_merge($this->allowedCidrs, $this->deniedCidrs) as $cidr) {
            if (!$this->isValidCidr($cidr)) {
                throw new InvalidArgumentException('Invalid outbound network policy');
            }
        }
    }

    /** 実際に接続する候補から、許可されたアドレスだけを返す。 */
    /**
     * @param list<string> $addresses
     * @return list<string>
     */
    public function allowedAddresses(array $addresses): array
    {
        $allowed = array_values(array_filter($addresses, function (string $address): bool {
            foreach ($this->deniedCidrs as $cidr) {
                if ($this->matches($address, $cidr)) {
                    return false;
                }
            }
            if ($this->allowedCidrs === []) {
                return true;
            }
            foreach ($this->allowedCidrs as $cidr) {
                if ($this->matches($address, $cidr)) {
                    return true;
                }
            }
            return false;
        }));
        if ($allowed === []) {
            throw new RuntimeException('OUTBOUND_NETWORK_DENIED');
        }
        return $allowed;
    }

    private function isValidCidr(string $cidr): bool
    {
        [$address, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
        return filter_var($address, FILTER_VALIDATE_IP) !== false
            && $prefix !== null
            && ctype_digit($prefix)
            && (int) $prefix <= (str_contains($address, ':') ? 128 : 32);
    }

    private function matches(string $address, string $cidr): bool
    {
        [$network, $prefix] = explode('/', $cidr, 2);
        $addressBytes = inet_pton($address);
        $networkBytes = inet_pton($network);
        if ($addressBytes === false || $networkBytes === false || strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }
        $bits = (int) $prefix;
        $fullBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;
        if ($fullBytes > 0 && substr($addressBytes, 0, $fullBytes) !== substr($networkBytes, 0, $fullBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($addressBytes[$fullBytes]) & $mask) === (ord($networkBytes[$fullBytes]) & $mask);
    }
}
