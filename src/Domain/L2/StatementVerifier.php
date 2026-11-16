<?php
// src/Domain/L2/StatementVerifier.php
// §15.2 phase 1..11 の固定順序で statement を検証する domain service（spec
// resolver-l2-0.1-draft §15, §15.1, §15.2）。Phase は並べ替えられない。最初の失敗 phase が
// 結果であり、結果はその phase を記録する（L2-VER-042）。mode は §15.1 の
// `statement-only` / `l1-bound` で、結果の verificationMode に記録する。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

use Relink\Resolver\Ports\SignatureVerifier;

final class StatementVerifier
{
    private readonly RecordChainWalker $chainWalker;

    public function __construct(
        private readonly SignatureVerifier $signatureVerifier,
        /** §11.4 W: 受理 snapshot に許容される signed-time 上限。 */
        private readonly int $maxSnapshotAge,
        ?RecordChainWalker $chainWalker = null,
    ) {
        $this->chainWalker = $chainWalker ?? new RecordChainWalker($signatureVerifier);
    }

    /**
     * @param int|null $T trusted clock（§11.2）。null は trusted clock なし（phase 4）。
     * @param string $mode §15.1 verificationMode。
     * @param string|null $observedL1Location l1-bound における L1 303 Location 観測（§7.4）。
     */
    public function verify(
        string $wire,
        VerifierState $state,
        ?int $T,
        string $mode = VerificationMode::STATEMENT_ONLY,
        ?string $observedL1Location = null,
    ): VerificationResult {
        // phase 1: wire canonicality, strict parse, duplicate members, integer range,
        // l2Version, payload/signature separation（§9.4, §10.1, §7.3）
        try {
            $statement = StatementPayload::fromWire($wire);
        } catch (WireStatementException $e) {
            return VerificationResult::failed(Verdict::INVALID, 1, $mode, $e->getMessage());
        } catch (PayloadStructureException $e) {
            return VerificationResult::failed(Verdict::INVALID, 1, $mode, $e->getMessage());
        }

        if (!VerificationMode::isKnown($mode)) {
            return VerificationResult::failed(Verdict::INVALID, 1, $mode, 'verificationMode is not a §15.1 mode');
        }

        $binding = $state->binding();

        // phase 2: expected anchorId match（§15.1）
        if ($statement->anchorId() !== $binding->anchorId()) {
            return VerificationResult::failed(Verdict::ANCHOR_MISMATCH, 2, $mode, 'anchorId does not match the expected anchor (§15.1)');
        }

        // phase 3: algorithm identifier known and supported（§10.8）
        if (!$this->signatureVerifier->supports($statement->algorithm())) {
            return VerificationResult::failed(Verdict::UNSUPPORTED_ALGORITHM, 3, $mode, 'algorithm is outside the baseline and unsupported (§10.2, §10.8)');
        }

        // phase 4: trusted clock available（§11.2）
        if ($T === null) {
            return VerificationResult::failed(Verdict::UNVERIFIED, 4, $mode, 'no trusted clock source available; no freshness judgment is made (§11.2)');
        }

        // phase 5: authority material, chain walk, signer resolvability（§9.6, §12.2, §9.4, §9.8）
        $phase5 = $this->phase5($state, $statement, $mode);
        if ($phase5 !== null) {
            return $phase5;
        }

        // phase 6: revocation snapshot and signed-time freshness at T（§11.4, §11.3）
        $snapshot = $state->snapshotAt($T);
        if ($snapshot === null) {
            return VerificationResult::failed(Verdict::UNVERIFIED, 6, $mode, 'empty effective prefix at T: no revocation snapshot (§11.4)');
        }
        if ($T > $snapshot->expiresAt()) {
            return VerificationResult::failed(Verdict::UNVERIFIED, 6, $mode, 'effective-latest snapshot Record ' . $snapshot->recordGeneration() . ' is expired at T (T > expiresAt(k)); back-off to an older Record is prohibited (§11.4)');
        }
        if ($T - $snapshot->issuedAt() > $this->maxSnapshotAge) {
            return VerificationResult::failed(Verdict::UNVERIFIED, 6, $mode, 'effective-latest snapshot Record ' . $snapshot->recordGeneration() . ' is stale by signed time at T (T - issuedAt(k) > W); back-off to an older Record is prohibited (§11.4)');
        }
        $signerKeyInSnapshot = $snapshot->key($statement->keyId());
        if ($signerKeyInSnapshot === null) {
            return VerificationResult::failed(Verdict::UNVERIFIED, 6, $mode, 'snapshot coverage gap: signing key ' . $statement->keyId() . ' is not covered by the snapshot at T (§11.4)');
        }

        // phase 7: Record chain position. A statement carries no recordGeneration; statement
        // generation positions are judged only at phase 10 (L2-VER-029, §7.2).
        // (Record-position evaluation is verifyRecord().)

        // phase 8: signature verification under signerKeyId publicKey bytes（§9.6）
        if ($this->signatureVerifier->verify($statement->algorithm(), $signerKeyInSnapshot->publicKeyBase64Url(), $statement->signatureBase64Url(), $statement->canonicalPayloadOctets()) !== Verdict::VERIFIED) {
            return VerificationResult::failed(Verdict::INVALID, 8, $mode, 'signature does not verify under the signer publicKey recorded in the snapshot (§9.6, §15.2 phase 8)');
        }

        // phase 9: signer key status in the fresh snapshot, at statement issuedAt, only when
        // the statement epochId equals the current accepted epoch（§15.2 phase 9）
        $phase9 = $this->phase9($state, $snapshot, $statement, $T, $mode);
        if ($phase9 !== null) {
            return $phase9;
        }

        // phase 10: statement window, epoch, floor, accepted maximum, equivocation（§11.1, §11.3, §12.3）
        $phase10 = $this->phase10($state, $statement, $T, $mode);
        if ($phase10 !== null) {
            return $phase10;
        }

        // phase 11: l1-bound mode, §7.4 consistency（§15.1）
        if (VerificationMode::isL1Bound($mode)) {
            if ($observedL1Location === null) {
                return VerificationResult::failed(Verdict::UNVERIFIED, 11, $mode, 'l1-bound mode with no L1 observation recorded for the expected Anchor (§15.1, §7.4)');
            }
            if ($observedL1Location !== $statement->descriptionLocation()) {
                return VerificationResult::failed(Verdict::MISMATCH, 11, $mode, 'observed L1 Location differs from the statement descriptionLocation (§7.4, §15.2 phase 11)');
            }
        }

        return VerificationResult::verified($mode, 'all phases 1..11 passed (§15.2)');
    }

    /**
     * §15.2 phase 7 Record chain position evaluation for a presented Record
     *（L2-VER-029）。Statement generations are never judged at phase 7.
     */
    public function verifyRecord(string $wire, VerifierState $state, ?int $T): VerificationResult
    {
        $mode = VerificationMode::STATEMENT_ONLY;
        $binding = $state->binding();

        try {
            $record = Record::fromWire($wire);
        } catch (WireStatementException $e) {
            return VerificationResult::failed(Verdict::INVALID, 1, $mode, $e->getMessage());
        } catch (PayloadStructureException $e) {
            return VerificationResult::failed(Verdict::INVALID, 1, $mode, $e->getMessage());
        }

        if ($record->anchorId() !== $binding->anchorId()) {
            return VerificationResult::failed(Verdict::ANCHOR_MISMATCH, 2, $mode, 'Record anchorId does not match the expected anchor (§15.1)');
        }
        if (!$this->signatureVerifier->supports($record->algorithm())) {
            return VerificationResult::failed(Verdict::UNSUPPORTED_ALGORITHM, 3, $mode, 'algorithm is outside the baseline and unsupported (§10.2, §10.8)');
        }

        $acceptedMax = $state->acceptedMaximum();
        $g = $record->recordGeneration();

        // phase 5 before phase 7: chain material and signer resolvability（§15.2, §9.4）
        if ($acceptedMax < 0) {
            return VerificationResult::failed(Verdict::UNVERIFIED, 5, $mode, 'no authority material and no trusted lower bound for Record ' . $g . ' (§9.8, §15.2 phase 5)');
        }

        if ($g < $acceptedMax) {
            return VerificationResult::failed(Verdict::STALE, 7, $mode, 'Record ' . $g . ' is below the accepted maximum ' . $acceptedMax . '; authority rollback is STALE (§9.7, §15.2 phase 7)');
        }

        if ($g === $acceptedMax) {
            $accepted = $state->record($g);
            if ($accepted !== null && $accepted->canonicalPayloadOctets() === $record->canonicalPayloadOctets()) {
                // Same-generation byte-identical presentation is a replay, not freshness
                // progress (§11.3, §9.7); no replay penalty.
                return VerificationResult::verified($mode, 'Record ' . $g . ' is a byte-identical replay at the accepted maximum; replay is not freshness progress (§11.3, §15.2 phase 7)');
            }

            return VerificationResult::failed(Verdict::CONFLICT, 7, $mode, 'same-generation Record ' . $g . ' with a different payload is CONFLICT and is never accepted (§9.7, §15.2 phase 7)');
        }

        if ($state->hasChainStop()) {
            return VerificationResult::failed(Verdict::UNVERIFIED, 5, $mode, 'chain walk stopped: ' . $state->chainStopReason());
        }
        if (!$state->isKeyIdInAcceptedChain($record->signerKeyId())) {
            return VerificationResult::failed(Verdict::UNKNOWN_AUTHORITY, 5, $mode, 'Record signer ' . $record->signerKeyId() . ' is not resolvable inside the accepted Record chain (§9.4, §15.2 phase 5)');
        }

        // Record above the accepted maximum: chain walk acceptance at contiguity G = acceptedMax + 1
        if ($g !== $acceptedMax + 1) {
            return VerificationResult::failed(Verdict::UNVERIFIED, 5, $mode, 'contiguity gap: Record ' . ($acceptedMax + 1) . ' is required before Record ' . $g . ' (§9.6, §15.2 phase 5)');
        }

        $acceptance = $this->chainWalker->accept($state, $wire);
        if ($acceptance->accepted()) {
            return VerificationResult::verified($mode, 'Record ' . $g . ' accepted at the chain tip (§9.6, §15.2 phases 1-8 passed)');
        }

        return VerificationResult::failed($acceptance->verdict(), $acceptance->phase(), $mode, $acceptance->reason());
    }

    /** §15.2 phase 5. */
    private function phase5(VerifierState $state, StatementPayload $statement, string $mode): ?VerificationResult
    {
        if ($state->acceptedMaximum() < 0) {
            return VerificationResult::failed(Verdict::UNVERIFIED, 5, $mode, 'no authority material, no accepted chain, no trusted lower bound (§9.8, §15.2 phase 5)');
        }
        if ($state->hasChainStop()) {
            $reason = $state->chainStopReason();
            // §9.4 (L2-AUT-022, L2-VER-002): a Record rejected because its wire keyId does not
            // equal the recomputed keyId of the wire publicKey makes the key unresolvable. The
            // dependent statement fails at phase 5 as UNKNOWN_AUTHORITY, never as INVALID.
            if (!$state->isKeyIdInAcceptedChain($statement->keyId())
                && str_contains($reason, 'keyId')
                && str_contains($reason, '§9.4')) {
                return VerificationResult::failed(Verdict::UNKNOWN_AUTHORITY, 5, $mode, 'signer ' . $statement->keyId() . ' is not resolvable under §9.4 keyId recomputation; the dependent statement fails as UNKNOWN_AUTHORITY, never INVALID (§9.4, §15.2 phase 5)');
            }

            return VerificationResult::failed(Verdict::UNVERIFIED, 5, $mode, 'chain walk stopped: ' . $reason . ' (§9.6, §15.2 phase 5)');
        }
        if (!$state->isKeyIdInAcceptedChain($statement->keyId())) {
            return VerificationResult::failed(Verdict::UNKNOWN_AUTHORITY, 5, $mode, 'signer ' . $statement->keyId() . ' is not resolvable inside the accepted Record chain; phases 8-9 are never reached (§9.4, §15.2 phase 5)');
        }

        return null;
    }

    /** §15.2 phase 9: key status in the fresh snapshot at statement issuedAt. */
    private function phase9(VerifierState $state, Record $snapshot, StatementPayload $statement, int $T, string $mode): ?VerificationResult
    {
        $currentEpoch = $state->currentEpochAt($T);
        if ($statement->epochId() !== $currentEpoch) {
            // A statement whose epochId is not the current accepted epoch skips the key-status
            // judgment and is judged at phase 10 (§15.2 phase 9, L2-VER-025).
            return null;
        }

        $key = $snapshot->key($statement->keyId());
        if ($key === null) {
            // Coverage was checked at phase 6; a gap cannot reach here.
            return null;
        }

        if ($key->status() === KeyStatus::COMPROMISED) {
            return VerificationResult::failed(Verdict::REVOKED, 9, $mode, 'signer key ' . $key->keyId() . ' is compromised in the fresh snapshot (§13.4, §15.2 phase 9)');
        }

        if ($key->status() === KeyStatus::REVOKED) {
            $revokedFrom = $key->revokedFrom();
            $revokingRecord = $revokedFrom === null ? null : $state->record($revokedFrom);
            if ($revokingRecord !== null && $statement->issuedAt() >= $revokingRecord->issuedAt()) {
                return VerificationResult::failed(Verdict::REVOKED, 9, $mode, 'signer key ' . $key->keyId() . ' is revoked from Record ' . $revokedFrom . ' (revokedFrom, revocation boundary reached at T with fresh evidence) (§13.4, §15.2 phase 9)');
            }
            // Statement below the revocation issuedAt: §11.3 anti-rollback governs at phase 10.
        }

        if ($key->status() === KeyStatus::RETIRED) {
            return VerificationResult::failed(Verdict::UNVERIFIED, 9, $mode, 'signer key ' . $key->keyId() . ' is retired in the fresh snapshot; retirement is not compromise (§10.5, §15.2 phase 9)');
        }

        if (!$key->containsTime($statement->issuedAt())) {
            return VerificationResult::failed(Verdict::UNVERIFIED, 9, $mode, 'signer key ' . $key->keyId() . ' is outside its validity window at the statement issuedAt (§10.4, §15.2 phase 9)');
        }

        if ($statement->entityId() !== $snapshot->entityId()) {
            return VerificationResult::failed(Verdict::UNKNOWN_AUTHORITY, 9, $mode, 'Canonical Entity Identity mismatch between statement and the accepted chain (§15, §15.2 phase 9)');
        }

        return null;
    }

    /** §15.2 phase 10. */
    private function phase10(VerifierState $state, StatementPayload $statement, int $T, string $mode): ?VerificationResult
    {
        if ($T < $statement->issuedAt() || $T > $statement->expiresAt()) {
            return VerificationResult::failed(Verdict::EXPIRED, 10, $mode, 'T is outside the statement validity window [issuedAt, expiresAt] (§15, §11.1, §15.2 phase 10)');
        }

        $currentEpoch = $state->currentEpochAt($T);
        if ($statement->epochId() !== $currentEpoch) {
            return VerificationResult::failed(Verdict::STALE, 10, $mode, 'statement epochId is a prior epoch, an epoch never accepted, or an epoch introduced only by a rejected Record (§12.3, §15.2 phase 10)');
        }

        $floor = $state->statementFloorAt($T);
        if ($floor !== null && $statement->generation() <= $floor) {
            return VerificationResult::failed(Verdict::STALE, 10, $mode, 'generation ' . $statement->generation() . ' does not strictly exceed the statement floor ' . $floor . '; floor equality is not a valid statement position (§12.3, L2-VER-001)');
        }

        $statementMax = $state->statementMaximum();
        if ($statement->generation() < $statementMax) {
            return VerificationResult::failed(Verdict::STALE, 10, $mode, 'generation ' . $statement->generation() . ' is below the accepted statement maximum ' . $statementMax . ' (§11.3, §15.2 phase 10)');
        }

        if ($statement->generation() === $statementMax) {
            if (!$state->isStatementByteIdentical($statement->generation(), $statement->canonicalPayloadOctets())) {
                return VerificationResult::failed(Verdict::CONFLICT, 10, $mode, 'same epoch and generation with a different payload is equivocation; no heuristic choice between payloads (§9.7, §11.3, §15.2 phase 10)');
            }
            // Byte-identical replay at the accepted maximum: no replay penalty (§11.3).
        }

        return null;
    }
}
