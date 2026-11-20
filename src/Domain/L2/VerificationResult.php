<?php
// src/Domain/L2/VerificationResult.php
// 検証結果モデル（spec resolver-l2-0.1-draft §15, §15.1, §15.2）。
// 結果は §15 の状態語彙そのものであり、boolean ではない。§15.2 の固定 phase 順序 1..11 に
// おいて最初の失敗 phase を記録する（"The result MUST record which phase failed"）。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

final class VerificationResult
{
    /** §15.2: all 11 phases evaluated and passed. */
    public const LAST_PHASE = 11;

    private function __construct(
        private readonly Verdict $verdict,
        private readonly int $phase,
        private readonly string $mode,
        private readonly string $reason,
    ) {
    }

    public static function failed(Verdict $verdict, int $phase, string $mode, string $reason): self
    {
        return new self($verdict, $phase, $mode, $reason);
    }

    public static function verified(string $mode, string $reason = 'all phases 1..11 passed (§15.2)'): self
    {
        return new self(Verdict::VERIFIED, self::LAST_PHASE, $mode, $reason);
    }

    public function verdict(): Verdict
    {
        return $this->verdict;
    }

    /** The §15.2 phase that produced the result; 11 when every phase passed. */
    public function phase(): int
    {
        return $this->phase;
    }

    public function failedPhase(): ?int
    {
        return $this->verdict->isVerified() ? null : $this->phase;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function isVerified(): bool
    {
        return $this->verdict->isVerified();
    }
}
