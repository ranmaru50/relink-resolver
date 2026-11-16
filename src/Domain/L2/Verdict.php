<?php
// src/Domain/L2/Verdict.php
// L2 検証結果の判定語彙（spec resolver-l2-0.1-draft §15）。
// 合一致性カタログの期待結果（PASS/FAIL 等）も定数として保持する。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

enum Verdict: string
{
    case VERIFIED = 'VERIFIED';
    case INVALID = 'INVALID';
    case ANCHOR_MISMATCH = 'ANCHOR_MISMATCH';
    case UNSUPPORTED_ALGORITHM = 'UNSUPPORTED_ALGORITHM';
    case UNVERIFIED = 'UNVERIFIED';
    case UNKNOWN_AUTHORITY = 'UNKNOWN_AUTHORITY';
    case STALE = 'STALE';
    case CONFLICT = 'CONFLICT';
    case REVOKED = 'REVOKED';
    case EXPIRED = 'EXPIRED';
    case MISMATCH = 'MISMATCH';
    case PASS = 'PASS';

    public const string FAIL = 'FAIL';
    public const string PASS_WITH_DEVIATION = 'PASS-WITH-DEVIATION';
    public const string NOT_APPLICABLE = 'NOT-APPLICABLE';
    public const string UNSUPPORTED_OPTIONAL = 'UNSUPPORTED-OPTIONAL';

    public function isVerified(): bool
    {
        return $this === self::VERIFIED;
    }
}
