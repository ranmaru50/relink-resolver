<?php
// src/Domain/L2/ChainAcceptance.php
// chain walk の 1 Record 受理結果（spec resolver-l2-0.1-draft §9.6, §15.2 phase 1, 5）。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

final class ChainAcceptance
{
    private function __construct(
        private readonly bool $accepted,
        private readonly Verdict $verdict,
        private readonly int $phase,
        private readonly string $reason,
        private readonly VerifierState $state,
    ) {
    }

    public static function acceptedState(VerifierState $state, string $reason): self
    {
        return new self(true, Verdict::PASS, $state->acceptedMaximum(), $reason, $state);
    }

    public static function rejected(VerifierState $state, Verdict $verdict, int $phase, string $reason): self
    {
        return new self(false, $verdict, $phase, $reason, $state);
    }

    public function accepted(): bool
    {
        return $this->accepted;
    }

    public function verdict(): Verdict
    {
        return $this->verdict;
    }

    /** §15.2: the result MUST record which phase failed. */
    public function phase(): int
    {
        return $this->phase;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function state(): VerifierState
    {
        return $this->state;
    }
}
