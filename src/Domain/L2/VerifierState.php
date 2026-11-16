<?php
// src/Domain/L2/VerifierState.php
// chain walk による受理状態（spec resolver-l2-0.1-draft §9.6, §11.4, §15.2 phase 5, 6, 10）。
// 受理 prefix、T における effective prefix と snapshot k、current accepted epoch、
// statement floor、key snapshot、受理済み statement maximum generation を不変で保持する。
// issuedAt > T の Record は effective prefix に含まれず、T における snapshot/epoch/floor を
// 更新しない（§11.4）。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

final class VerifierState
{
    /**
     * @param TrustAnchorBinding $binding
     * @param array<int, Record> $acceptedRecords recordGeneration を key とする連続受理 prefix
     * @param array<int, string> $acceptedEpochs 受理済み epoch の順序
     * @param array<int, string> $statementPayloadHashes generation => sha256 hex of canonical payload
     */
    private function __construct(
        private readonly TrustAnchorBinding $binding,
        private readonly array $acceptedRecords,
        private readonly array $acceptedEpochs,
        private readonly array $statementPayloadHashes,
        private readonly ?Verdict $chainStopVerdict,
        private readonly string $chainStopReason,
    ) {
    }

    public static function empty(TrustAnchorBinding $binding): self
    {
        return new self($binding, [], [], [], null, '');
    }

    public function binding(): TrustAnchorBinding
    {
        return $this->binding;
    }

    /** @return array<int, Record> */
    public function acceptedRecords(): array
    {
        return $this->acceptedRecords;
    }

    public function acceptedMaximum(): int
    {
        return $this->acceptedRecords === [] ? -1 : max(array_keys($this->acceptedRecords));
    }

    public function record(int $generation): ?Record
    {
        return $this->acceptedRecords[$generation] ?? null;
    }

    /** @return list<string> */
    public function acceptedEpochs(): array
    {
        return array_values($this->acceptedEpochs);
    }

    public function isEpochAccepted(string $epochId): bool
    {
        return in_array($epochId, $this->acceptedEpochs, true);
    }

    /** chain tip の epoch（受理済み最大 recordGeneration の Record の epochId）。 */
    public function chainTipEpoch(): ?string
    {
        $tip = $this->record($this->acceptedMaximum());

        return $tip === null ? null : $tip->epochId();
    }

    /**
     * §11.4 effective prefix at T: the longest contiguous accepted prefix 0..k in which
     * every Record has issuedAt <= T; k is the highest accepted recordGeneration with
     * issuedAt(k) <= T under the §9.6 non-decreasing rule. Records with issuedAt > T are
     * excluded and never serve as the snapshot at T.
     *
     * @return list<Record>
     */
    public function effectivePrefixAt(int $T): array
    {
        $prefix = [];
        foreach ($this->acceptedRecords as $generation => $record) {
            if ($record->issuedAt() > $T) {
                break;
            }
            $prefix[] = $record;
        }

        return $prefix;
    }

    /** §11.4 revocation snapshot at T: the last Record k of the effective prefix. */
    public function snapshotAt(int $T): ?Record
    {
        $prefix = $this->effectivePrefixAt($T);

        return $prefix === [] ? null : $prefix[count($prefix) - 1];
    }

    /**
     * current accepted epoch at T: the epoch of the snapshot Record. A Record not
     * effective at T never updates the epoch, floor, or key snapshot at T (§11.4).
     */
    public function currentEpochAt(int $T): ?string
    {
        $snapshot = $this->snapshotAt($T);

        return $snapshot === null ? null : $snapshot->epochId();
    }

    public function statementFloorAt(int $T): ?int
    {
        $snapshot = $this->snapshotAt($T);

        return $snapshot === null ? null : $snapshot->statementFloor();
    }

    /** §11.4 key snapshot: the key entry carried by the snapshot Record at T. */
    public function keyAtSnapshot(int $T, string $keyId): ?KeyEntry
    {
        $snapshot = $this->snapshotAt($T);

        return $snapshot === null ? null : $snapshot->key($keyId);
    }

    /** §11.4 signed-time freshness predicate at T with bounded upper bound W. */
    public function isSnapshotFreshAt(int $T, int $W): bool
    {
        $snapshot = $this->snapshotAt($T);
        if ($snapshot === null) {
            return false;
        }
        if ($T > $snapshot->expiresAt()) {
            return false;
        }

        return $T - $snapshot->issuedAt() <= $W;
    }

    public function isKeyIdInAcceptedChain(string $keyId): bool
    {
        foreach ($this->acceptedRecords as $record) {
            if ($record->key($keyId) !== null) {
                return true;
            }
        }

        return false;
    }

    /** -1 when no statement has been accepted. */
    public function statementMaximum(): int
    {
        return $this->statementPayloadHashes === [] ? -1 : max(array_keys($this->statementPayloadHashes));
    }

    public function statementPayloadHash(int $generation): ?string
    {
        return $this->statementPayloadHashes[$generation] ?? null;
    }

    /** §11.3/§15.2 phase 10: byte-identity of a presentation at the accepted maximum. */
    public function isStatementByteIdentical(int $generation, string $canonicalPayloadOctets): bool
    {
        return $this->statementPayloadHash($generation) === hash('sha256', $canonicalPayloadOctets);
    }

    public function hasChainStop(): bool
    {
        return $this->chainStopVerdict !== null;
    }

    public function chainStopVerdict(): ?Verdict
    {
        return $this->chainStopVerdict;
    }

    public function chainStopReason(): string
    {
        return $this->chainStopReason;
    }

    public function withAcceptedRecord(Record $record): self
    {
        $records = $this->acceptedRecords;
        $records[$record->recordGeneration()] = $record;

        $epochs = $this->acceptedEpochs;
        if (!in_array($record->epochId(), $epochs, true)) {
            $epochs[] = $record->epochId();
        }

        return new self($this->binding, $records, $epochs, $this->statementPayloadHashes, $this->chainStopVerdict, $this->chainStopReason);
    }

    /** §11.3: acceptance of a VERIFIED statement moves the accepted statement maximum. */
    public function withAcceptedStatement(int $generation, string $canonicalPayloadOctets): self
    {
        $hashes = $this->statementPayloadHashes;
        $hashes[$generation] = hash('sha256', $canonicalPayloadOctets);

        return new self($this->binding, $this->acceptedRecords, $this->acceptedEpochs, $hashes, $this->chainStopVerdict, $this->chainStopReason);
    }

    /** §15.2 phase 5: chain walk stop (gap, trail failure, missing material) recorded. */
    public function withChainStop(Verdict $verdict, string $reason): self
    {
        return new self($this->binding, $this->acceptedRecords, $this->acceptedEpochs, $this->statementPayloadHashes, $verdict, $reason);
    }
}
