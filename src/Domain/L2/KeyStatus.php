<?php
// src/Domain/L2/KeyStatus.php
// key entry の status 列挙（spec resolver-l2-0.1-draft §9.5, §10.1-10.6）。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

final class KeyStatus
{
    public const CURRENT = 'current';
    public const RETIRED = 'retired';
    public const REVOKED = 'revoked';
    public const COMPROMISED = 'compromised';

    /** @var list<string> */
    private const KNOWN = [self::CURRENT, self::RETIRED, self::REVOKED, self::COMPROMISED];

    /** @var list<string> */
    private const TERMINAL = [self::RETIRED, self::REVOKED, self::COMPROMISED];

    public static function isKnown(string $status): bool
    {
        return in_array($status, self::KNOWN, true);
    }

    /** §9.5 terminal-state irreversibility: retired/revoked/compromised are established. */
    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    public static function isCurrent(string $status): bool
    {
        return $status === self::CURRENT;
    }

    /** §9.5/§13.4: revokedFrom is required exactly on revoked and compromised entries. */
    public static function isRevocationStatus(string $status): bool
    {
        return $status === self::REVOKED || $status === self::COMPROMISED;
    }
}
