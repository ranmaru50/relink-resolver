<?php
// src/Domain/L2/RecordChainWalker.php
// Trust Anchor Binding からの chain walk（spec resolver-l2-0.1-draft §9.6, §9.5 keys-array
// mutation 4 規則, §12.2 trail matching, §9.4 keyId 再計算, §15.2 phase 5）。
// 受理失敗は chain walk を停止させ、§15.2 の語彙（UNVERIFIED / UNKNOWN_AUTHORITY / INVALID）と
// 停止 phase を記録する。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

use Relink\Resolver\Ports\SignatureVerifier;

final class RecordChainWalker
{
    public function __construct(private readonly SignatureVerifier $signatureVerifier)
    {
    }

    public function initialState(TrustAnchorBinding $binding): VerifierState
    {
        return VerifierState::empty($binding);
    }

    public function accept(VerifierState $state, string $wire): ChainAcceptance
    {
        try {
            $record = Record::fromWire($wire);
        } catch (WireStatementException $e) {
            return ChainAcceptance::rejected($state, Verdict::INVALID, 1, $e->getMessage());
        } catch (PayloadStructureException $e) {
            return ChainAcceptance::rejected($state, Verdict::INVALID, 1, $e->getMessage());
        }

        if ($state->hasChainStop()) {
            return ChainAcceptance::rejected($state, Verdict::UNVERIFIED, 5, 'chain walk stopped: ' . $state->chainStopReason());
        }

        $binding = $state->binding();
        if ($record->anchorId() !== $binding->anchorId()) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'Record anchorId does not match the Trust Anchor Binding (§9.6)');
        }
        if ($record->entityId() !== $binding->entityId()) {
            return $this->stop($state, Verdict::UNKNOWN_AUTHORITY, 5, 'Record entityId does not match the Canonical Entity Identity (§9.5, §15)');
        }
        if (!$this->signatureVerifier->supports($record->algorithm())) {
            // §15.2 phase 3 precedes the chain walk: an unknown/unsupported algorithm is
            // UNSUPPORTED_ALGORITHM, never UNVERIFIED (§10.8, L2-VER-005, Catalog line 805).
            return $this->stop($state, Verdict::UNSUPPORTED_ALGORITHM, 3, 'algorithm is outside the baseline and unsupported by the verifier (§10.2, §10.8)');
        }

        $g = $record->recordGeneration();
        if ($g === 0) {
            return $this->acceptBootstrap($state, $record);
        }

        $prev = $state->record($g - 1);
        if ($prev === null) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'contiguity gap: Record ' . ($g - 1) . ' was never accepted (§9.6, §11.4)');
        }

        if ($record->prevRecordDigest() !== $prev->payloadDigest()) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'prevRecordDigest does not bind the payload of Record ' . ($g - 1) . ' (§9.5, §9.6)');
        }
        if ($record->issuedAt() < $prev->issuedAt()) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'issuedAt regressed along the chain (§9.6)');
        }

        $signerInPrev = $prev->key($record->signerKeyId());
        if ($signerInPrev === null) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'signerKeyId is not present with publicKey in Record ' . ($g - 1) . ' (§9.6)');
        }
        if ($signerInPrev->status() !== KeyStatus::CURRENT || !$signerInPrev->containsTime($record->issuedAt())) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'signer is not current in Record ' . ($g - 1) . ' with validFrom <= issuedAt(G) <= validUntil (§9.6 key-substitution defense)');
        }
        if ($this->signatureVerifier->verify($record->algorithm(), $signerInPrev->publicKeyBase64Url(), $record->signatureBase64Url(), $record->canonicalPayloadOctets()) !== Verdict::VERIFIED) {
            return $this->stop($state, Verdict::INVALID, 5, 'Record signature does not verify under the signer publicKey recorded in Record ' . ($g - 1) . ' (§9.6)');
        }

        $trailRejection = $this->acceptEpochAndTrail($state, $prev, $record);
        if ($trailRejection !== null) {
            return $trailRejection;
        }

        $mutationRejection = $this->acceptKeysMutation($state, $prev, $record);
        if ($mutationRejection !== null) {
            return $mutationRejection;
        }

        $newState = $state->withAcceptedRecord($record);

        return ChainAcceptance::acceptedState($newState, 'Record ' . $g . ' accepted by chain walk (§9.6)');
    }

    private function acceptBootstrap(VerifierState $state, Record $record): ChainAcceptance
    {
        $binding = $state->binding();
        if ($state->acceptedMaximum() >= 0) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'Record 0 presented after the chain started (§9.6)');
        }
        if ($record->hasTransfer()) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'transfer member is forbidden on Record 0 (§9.5)');
        }
        if ($record->epochId() !== $binding->initialEpochId()) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'Record 0 epochId does not match the Trust Anchor Binding initial epochId (§9.6 item 1)');
        }
        if ($record->statementFloor() !== $binding->initialStatementFloor()) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'Record 0 statementFloor does not match the Trust Anchor Binding initial floor (§9.6 item 1)');
        }

        $entries = $record->keys();
        if (count($entries) !== 1) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'Record 0 keys must carry exactly the binding key entry (§9.6 item 1)');
        }
        $entry = $entries[0];
        if ($entry->keyId() !== $binding->keyId()
            || $entry->publicKeyBase64Url() !== $binding->publicKeyBase64Url()
            || $entry->algorithm() !== $binding->algorithm()
            || $entry->status() !== KeyStatus::CURRENT
            || $entry->validFrom() !== $record->issuedAt()) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'Record 0 key entry does not match the Trust Anchor Binding initial key material and epoch start (§9.6 item 1)');
        }
        if ($record->signerKeyId() !== $binding->keyId()) {
            return $this->stop($state, Verdict::UNKNOWN_AUTHORITY, 5, 'Record 0 signerKeyId is not the binding keyId (§9.6 item 1)');
        }
        if ($this->signatureVerifier->verify($record->algorithm(), $binding->publicKeyBase64Url(), $record->signatureBase64Url(), $record->canonicalPayloadOctets()) !== Verdict::VERIFIED) {
            return $this->stop($state, Verdict::INVALID, 5, 'Record 0 does not verify under the Trust Anchor Binding public key (§9.6 item 1)');
        }

        $newState = $state->withAcceptedRecord($record);

        return ChainAcceptance::acceptedState($newState, 'Record 0 accepted through the Trust Anchor Binding policy channel (§9.3, §9.6 item 1)');
    }

    /**
     * §12.2 trail matching rules and §9.6 epoch rules; returns a rejection acceptance or null.
     *
     * @return ChainAcceptance|null
     */
    private function acceptEpochAndTrail(VerifierState $state, Record $prev, Record $record): ?ChainAcceptance
    {
        $binding = $state->binding();
        $g = $record->recordGeneration();
        $prevEpoch = $prev->epochId();

        if (!$record->hasTransfer()) {
            if ($record->epochId() !== $prevEpoch) {
                return $this->stop($state, Verdict::UNVERIFIED, 5, 'epochId differs from Record ' . ($g - 1) . ' without an embedded transfer (§9.6)');
            }
            if ($record->statementFloor() !== $prev->statementFloor()) {
                return $this->stop($state, Verdict::UNVERIFIED, 5, 'non-transfer Record must repeat the statement floor of Record ' . ($g - 1) . ' (§9.5)');
            }

            return null;
        }

        $trail = $record->transfer();
        if ($trail === null) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'transfer member malformed (§12.2)');
        }

        if ($record->epochId() === $prevEpoch) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'transfer-introducing Record must establish a new epochId (§9.6, §12.2)');
        }
        if ($trail->epochId() !== $prevEpoch) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'trail epochId must equal Record ' . ($g - 1) . ' epochId (§12.2)');
        }
        if ($trail->newEpochId() !== $record->epochId()) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'trail newEpochId must equal Record G epochId (§12.2)');
        }
        if ($state->isEpochAccepted($record->epochId())) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'newEpochId was already accepted in this Anchor chain history (§9.6)');
        }
        if ($record->statementFloor() !== 0) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'transfer-introducing Record statementFloor must be 0 (§9.6, §12.3)');
        }
        if ($trail->statementFloor() !== 0) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'trail statementFloor must be 0 on a transfer-introducing Record (§12.2)');
        }
        if ($trail->recordGeneration() !== $g) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'trail recordGeneration must equal recordGeneration = G (§12.2)');
        }
        if ($trail->outgoingKeyId() !== $record->signerKeyId()) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'trail outgoingKeyId must equal Record G signerKeyId (§12.2)');
        }
        if ($trail->algorithm() !== $record->algorithm()) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'trail algorithm must equal Record G algorithm (§12.2)');
        }
        if ($trail->anchorId() !== $binding->anchorId() || $trail->entityId() !== $binding->entityId()) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'trail anchorId/entityId must match the Trust Anchor Binding (§12.2)');
        }

        $outgoing = $prev->key($trail->outgoingKeyId());
        if ($outgoing === null) {
            return $this->stop($state, Verdict::UNKNOWN_AUTHORITY, 5, 'trail outgoingKeyId is not recorded in Record ' . ($g - 1) . ' (§12.2)');
        }
        if ($outgoing->status() !== KeyStatus::CURRENT) {
            return $this->stop($state, Verdict::UNKNOWN_AUTHORITY, 5, 'trail outgoing key is not current in Record ' . ($g - 1) . ' (§12.2, §15)');
        }
        if (!$outgoing->containsTime($record->issuedAt())) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'trail outgoing key window in Record ' . ($g - 1) . ' excludes issuedAt(G) (§9.6, §12.2)');
        }
        if ($this->signatureVerifier->verify($trail->algorithm(), $outgoing->publicKeyBase64Url(), $trail->signatureBase64Url(), $trail->canonicalPayloadOctets()) !== Verdict::VERIFIED) {
            return $this->stop($state, Verdict::UNKNOWN_AUTHORITY, 5, 'unsigned transfer attempt: trail signature does not verify under the outgoing key (§12.2, §15)');
        }

        $incomingKey = $trail->incomingKeyId();
        $incoming = $record->key($incomingKey);
        if ($incoming === null) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'trail incoming key is not introduced in the Record G keys array (§12.2)');
        }
        if ($incoming->status() !== KeyStatus::CURRENT) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'trail incoming key must be introduced as current in Record G (§12.2)');
        }
        if (!$incoming->containsTime($record->issuedAt())) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'trail incoming key window must include issuedAt(G) (§10.4, §12.2)');
        }
        if ($prev->key($incomingKey) !== null) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'trail incoming key was already accepted before Record G (§12.2)');
        }

        $currentInRecord = $record->currentKeys();
        if (count($currentInRecord) !== 1) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'trail matching failure: the trail-authorized incoming key must be the sole current key in Record G (§12.2)');
        }
        if ($currentInRecord[0]->keyId() !== $incomingKey) {
            return $this->stop($state, Verdict::UNVERIFIED, 5, 'trail matching failure: Record G current set does not consist of the trail incoming key (§12.2)');
        }

        foreach ($prev->currentKeys() as $prevCurrent) {
            $carried = $record->key($prevCurrent->keyId());
            if ($carried === null) {
                return $this->stop($state, Verdict::UNVERIFIED, 5, 'transfer Record must carry every key current in Record ' . ($g - 1) . ' (§9.5, §12.2)');
            }
            if ($carried->status() !== KeyStatus::RETIRED) {
                return $this->stop($state, Verdict::UNVERIFIED, 5, 'trail matching failure: key ' . $prevCurrent->keyId() . ' is current in Record ' . ($g - 1) . ' and left current in Record G (§12.2)');
            }
            $cap = min($prevCurrent->validUntil(), $record->issuedAt());
            if ($carried->validUntil() !== $cap) {
                return $this->stop($state, Verdict::UNVERIFIED, 5, 'transfer retirement truncation validUntil must be min(previous validUntil, issuedAt(G)) for key ' . $prevCurrent->keyId() . ' (§9.5, §12.2)');
            }
        }

        return null;
    }

    /**
     * §9.5 keys-array mutation rules: entry identity preservation, revocation-field rule,
     * terminal-state irreversibility, allowed transitions. Returns a rejection or null.
     *
     * @return ChainAcceptance|null
     */
    private function acceptKeysMutation(VerifierState $state, Record $prev, Record $record): ?ChainAcceptance
    {
        $newKeyIds = [];
        foreach ($record->keys() as $entry) {
            if ($prev->key($entry->keyId()) === null) {
                $newKeyIds[] = $entry->keyId();
            }
        }
        $isRotationRecord = count($newKeyIds) > 0;
        $g = $record->recordGeneration();

        foreach ($prev->keys() as $prevEntry) {
            $carried = $record->key($prevEntry->keyId());
            if ($carried === null) {
                return $this->stop($state, Verdict::UNVERIFIED, 5, 'key entry ' . $prevEntry->keyId() . ' deleted from a later Record (§9.5)');
            }
            if (!$carried->identityMatches($prevEntry)) {
                return $this->stop($state, Verdict::UNVERIFIED, 5, 'key identity fields keyId/publicKey/algorithm/validFrom changed for key ' . $prevEntry->keyId() . ' (§9.5, §9.4)');
            }

            if (KeyStatus::isTerminal($prevEntry->status())) {
                if ($carried->status() !== $prevEntry->status()) {
                    return $this->stop($state, Verdict::UNVERIFIED, 5, 'terminal key ' . $prevEntry->keyId() . ' (status ' . $prevEntry->status() . ') returned to ' . $carried->status() . ' (§9.5, §13.4)');
                }
                if ($carried->validUntil() !== $prevEntry->validUntil()) {
                    return $this->stop($state, Verdict::UNVERIFIED, 5, 'terminal validUntil of key ' . $prevEntry->keyId() . ' widened, shortened, extended, or overwritten (§9.5)');
                }
                if ($carried->revokedFrom() !== $prevEntry->revokedFrom()) {
                    return $this->stop($state, Verdict::UNVERIFIED, 5, 'established revokedFrom of key ' . $prevEntry->keyId() . ' changed, widened, shortened, removed, or re-set (§9.5, §13.4)');
                }
                continue;
            }

            // Record G-1 status was current: the allowed transitions are
            // current -> retired, current -> revoked, current -> compromised, staying current.
            if ($carried->status() === KeyStatus::CURRENT) {
                if ($carried->revokedFrom() !== null) {
                    return $this->stop($state, Verdict::UNVERIFIED, 5, 'revokedFrom present on a current entry for key ' . $prevEntry->keyId() . ' (§9.5)');
                }
                if (!$isRotationRecord && $carried->validUntil() !== $prevEntry->validUntil()) {
                    return $this->stop($state, Verdict::UNVERIFIED, 5, 'validUntil of current key ' . $prevEntry->keyId() . ' changed without an allowed transition, rotation Record, or transfer truncation (§9.5)');
                }
                continue;
            }

            if ($carried->status() === KeyStatus::RETIRED) {
                if ($carried->revokedFrom() !== null) {
                    return $this->stop($state, Verdict::UNVERIFIED, 5, 'revokedFrom set on a retired entry for key ' . $prevEntry->keyId() . ' (§9.5)');
                }
                $cap = min($prevEntry->validUntil(), $record->issuedAt());
                if ($carried->validUntil() !== $cap) {
                    return $this->stop($state, Verdict::UNVERIFIED, 5, 'current -> retired validUntil must be min(previous validUntil, issuedAt(G)) for key ' . $prevEntry->keyId() . ' (§9.5)');
                }
                continue;
            }

            if ($carried->revokedFrom() !== $g) {
                return $this->stop($state, Verdict::UNVERIFIED, 5, 'revokedFrom must equal recordGeneration of the revoking Record for key ' . $prevEntry->keyId() . ' (§9.5, §13.4)');
            }
            if ($carried->validUntil() !== $prevEntry->validUntil()) {
                return $this->stop($state, Verdict::UNVERIFIED, 5, 'revocation must not change the established window of key ' . $prevEntry->keyId() . ' (§9.5, §13.4)');
            }
        }

        foreach ($newKeyIds as $keyId) {
            $newEntry = $record->key($keyId);
            if ($newEntry !== null && $newEntry->status() !== KeyStatus::CURRENT) {
                return $this->stop($state, Verdict::UNVERIFIED, 5, 'new key ' . $keyId . ' must be introduced as current (§10.3, §12.2)');
            }
        }

        return null;
    }

    private function stop(VerifierState $state, Verdict $verdict, int $phase, string $reason): ChainAcceptance
    {
        return ChainAcceptance::rejected($state->withChainStop($verdict, $reason), $verdict, $phase, $reason);
    }
}
