<?php
// src/Domain/L2/VerificationMode.php
// 検証モード（spec resolver-l2-0.1-draft §15.1）。結果に verificationMode として記録する。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

final class VerificationMode
{
    public const STATEMENT_ONLY = 'statement-only';
    public const L1_BOUND = 'l1-bound';

    /** @var list<string> */
    private const KNOWN = [self::STATEMENT_ONLY, self::L1_BOUND];

    public static function isKnown(string $mode): bool
    {
        return in_array($mode, self::KNOWN, true);
    }

    public static function isL1Bound(string $mode): bool
    {
        return $mode === self::L1_BOUND;
    }
}
