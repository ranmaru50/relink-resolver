<?php
// tests/L2/Stage2ChainWalkTest.php
// RecordChainWalker：Trust Anchor Binding からの chain walk、§9.6 受理規則、§9.5 keys-array
// mutation 規則、§12.2 trail matching、§9.4 keyId 再計算、§11.3 contiguity。
// 対応 Conformance cases: L2-VER-009, 010, 011, 012, 014, 015, 016, 017, 018, 020, 022, 026,
// 037; L2-AUT-001, 002, 003, 004, 005, 006, 007, 008, 009, 012, 013, 014, 015, 017, 018,
// 019, 021, 022, 023, 024。

declare(strict_types=1);

namespace Relink\Resolver\Tests\L2;

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Adapters\SodiumEd25519Verifier;
use Relink\Resolver\Domain\L2\Base64Url;
use Relink\Resolver\Domain\L2\ChainStopKind;
use Relink\Resolver\Domain\L2\JcsCanonicalizer;
use Relink\Resolver\Domain\L2\KeyId;
use Relink\Resolver\Domain\L2\PayloadStructureException;
use Relink\Resolver\Domain\L2\Record;
use Relink\Resolver\Domain\L2\TrustAnchorBinding;
use Relink\Resolver\Domain\L2\Verdict;
use L2Fixtures;

require_once __DIR__ . '/Stage2TestSupport.php';

final class Stage2ChainWalkTest extends TestCase
{
    /**
     * L2-VER-009, L2-AUT-001, L2-AUT-021 (bootstrap, pinned fixture): Record 0 is accepted
     * only through the Trust Anchor Binding delivered by the policy channel.
     */
    public function testRecord0IsAcceptedThroughTheTrustAnchorBinding(): void
    {
        $acceptance = Stage2TestSupport::walker()->accept(
            Stage2TestSupport::walker()->initialState(Stage2TestSupport::binding()),
            Stage2TestSupport::wire('Record0'),
        );

        $this->assertTrue($acceptance->accepted());
        $this->assertSame(0, $acceptance->state()->acceptedMaximum());
        $this->assertSame(['epoch-0'], $acceptance->state()->acceptedEpochs());
        $this->assertSame(Verdict::PASS, $acceptance->verdict());
    }

    /**
     * L2-AUT-001 / L2-AUT-021 negative: material signed under a key that is not in the
     * Trust Anchor Binding can never bootstrap its own authority. Records 1..6 presented as
     * bootstrap material on a binding that does not carry the signer are rejected.
     */
    public function testSelfBootstrappingMaterialIsRejectedAtBootstrap(): void
    {
        // Record 1 (signer key1) presented against a binding on key2: signature verification
        // under the binding key fails, so the Record is never accepted.
        $state = Stage2TestSupport::walker()->initialState(Stage2TestSupport::bindingWithKey(2));
        $acceptance = Stage2TestSupport::walker()->accept($state, Stage2TestSupport::wire('Record1'));

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(5, $acceptance->phase());
        $this->assertSame(-1, $acceptance->state()->acceptedMaximum());
        $this->assertTrue($acceptance->state()->hasChainStop());

        // Record 2 (signer key2) as bootstrap: Record 0 shape rules reject it (G must be 0).
        $state2 = Stage2TestSupport::walker()->initialState(Stage2TestSupport::bindingWithKey(2));
        $acceptance2 = Stage2TestSupport::walker()->accept($state2, Stage2TestSupport::wire('Record2'));

        $this->assertFalse($acceptance2->accepted());
        $this->assertSame(-1, $acceptance2->state()->acceptedMaximum());
    }

    /** L2-VER-010, L2-AUT-007, L2-AUT-018: Record 1 accepted with retirement truncation cap. */
    public function testRecord1AcceptedWithRetirementCapAndEpochPreserved(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1']);

        $this->assertSame(1, $state->acceptedMaximum());
        $this->assertSame(['epoch-0'], $state->acceptedEpochs());
        $record1 = $state->record(1);
        $this->assertNotNull($record1);
        $key1 = $record1->key('ECMERdvpV0k');
        $key2 = $record1->key('4KKXd7XSJW8');
        $this->assertNotNull($key1);
        $this->assertNotNull($key2);
        // cap: min(1770000000, 1769997000) = 1769997000
        $this->assertSame('retired', $key1->status());
        $this->assertSame(1769997000, $key1->validUntil());
        $this->assertSame('current', $key2->status());
        $this->assertSame(1769997000, $key2->validFrom());
        $this->assertSame('epoch-0', $record1->epochId());
    }

    /** L2-VER-011, L2-AUT-012, L2-AUT-015: Record 2 accepted via trail; epoch switches atomically. */
    public function testRecord2AcceptedViaTrailAndEpochSwitches(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2']);

        $this->assertSame(2, $state->acceptedMaximum());
        $this->assertSame(['epoch-0', 'epoch-1'], $state->acceptedEpochs());
        $record2 = $state->record(2);
        $this->assertNotNull($record2);
        $this->assertSame('epoch-1', $record2->epochId());
        $key3 = $record2->key('G8VbXumewVs');
        $this->assertNotNull($key3);
        $this->assertSame('current', $key3->status());
        $this->assertSame(1769997600, $key3->validFrom());
        $this->assertSame(1770001200, $key3->validUntil());
        // §12.2 atomic switch: incoming key is the sole current key in Record 2
        $this->assertCount(1, $record2->currentKeys());
    }

    /** L2-VER-012, L2-AUT-019: Record 3 accepted as first Record signed by the incoming key. */
    public function testRecord3AcceptedAndTipBecomes3(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);

        $this->assertSame(3, $state->acceptedMaximum());
        $this->assertSame('epoch-1', $state->chainTipEpoch());
    }

    /** L2-VER-015, L2-AUT-024: Record 4 accepted; key3 window contains issuedAt(4). */
    public function testRecord4Accepted(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4']);

        $this->assertSame(4, $state->acceptedMaximum());
        $record4 = $state->record(4);
        $this->assertNotNull($record4);
        $key3 = $record4->key('G8VbXumewVs');
        $this->assertNotNull($key3);
        // rotation Record keeps key3 current with its window extended (L2-AUT-024 overlap)
        $this->assertSame('current', $key3->status());
        $this->assertSame(1770002400, $key3->validUntil());
    }

    /**
     * L2-VER-016, L2-AUT-015: Record 5 is rejected at trail matching — key3 is left `current`
     * in the epoch-establishing Record, and the trail signature itself verifies (key4), so the
     * failure is a field-set matching failure, not a signature failure.
     */
    public function testRecord5RejectedBecauseKey3StaysCurrentInEpochSwitch(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4']);
        $acceptance = Stage2TestSupport::walker()->accept($state, Stage2TestSupport::wire('Record5'));

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(5, $acceptance->phase());
        $this->assertSame(Verdict::UNVERIFIED, $acceptance->verdict());
        $this->assertStringContainsString('sole current key', $acceptance->reason());
        $this->assertSame(4, $acceptance->state()->acceptedMaximum());
        $this->assertSame(['epoch-0', 'epoch-1'], $acceptance->state()->acceptedEpochs());
    }

    /** L2-VER-017: Record 6 is rejected by contiguity after Record 5 was rejected. */
    public function testRecord6RejectedByContiguityAfterRecord5Rejection(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4']);
        $rejected = Stage2TestSupport::walker()->accept($state, Stage2TestSupport::wire('Record5'));
        $this->assertFalse($rejected->accepted());

        $acceptance = Stage2TestSupport::walker()->accept($rejected->state(), Stage2TestSupport::wire('Record6'));

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(5, $acceptance->phase());
        // Record 5 の受理失敗で chain walk は停止済み。Record 6 は contiguity gap を埋められない
        // （§9.6: 受理は 0..G の連続受理で進むため、停止後 Record 6 は受理されない）。
        $this->assertStringContainsString('chain walk stopped', $acceptance->reason());
        $this->assertSame(4, $acceptance->state()->acceptedMaximum());
        $this->assertFalse($acceptance->state()->isEpochAccepted('epoch-2'));
        $this->assertFalse($acceptance->state()->isEpochAccepted('epoch-3'));
    }

    /** L2-AUT-023, L2-VER-017: epochs of Records rejected by chain walk never become accepted. */
    public function testEpochsOfRejectedRecordsNeverBecomeAccepted(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4']);
        $rejected5 = Stage2TestSupport::walker()->accept($state, Stage2TestSupport::wire('Record5'));
        $rejected6 = Stage2TestSupport::walker()->accept($rejected5->state(), Stage2TestSupport::wire('Record6'));

        $this->assertFalse($rejected5->accepted());
        $this->assertFalse($rejected6->accepted());
        $finalState = $rejected6->state();
        $this->assertSame(['epoch-0', 'epoch-1'], $finalState->acceptedEpochs());
        $this->assertFalse($finalState->isEpochAccepted('epoch-2'));
        $this->assertFalse($finalState->isEpochAccepted('epoch-3'));
    }

    /** L2-VER-018: Record 4F is accepted by chain walk. */
    public function testRecord4FAcceptedAfterChain0to3(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4F']);

        $this->assertSame(4, $state->acceptedMaximum());
        $this->assertSame('epoch-1', $state->chainTipEpoch());
    }

    /** L2-VER-020: Record 4E is accepted by chain walk (expired window is a validity fact, not an acceptance fact). */
    public function testRecord4EAcceptedByChainWalk(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4E']);

        $this->assertSame(4, $state->acceptedMaximum());
        $record4e = $state->record(4);
        $this->assertNotNull($record4e);
        $this->assertSame(1769998250, $record4e->issuedAt());
        $this->assertSame(1769998300, $record4e->expiresAt());
    }

    /**
     * L2-AUT-024, L2-VER-026: Records 4A, 4R, 4B accepted by chain walk; chain tip 6;
     * revocation is established at Record 4R with revokedFrom = 5.
     */
    public function testRecords4A4R4BAcceptedTipIsSix(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A', 'Record4R', 'Record4B']);

        $this->assertSame(6, $state->acceptedMaximum());
        $record4r = $state->record(5);
        $this->assertNotNull($record4r);
        $key3 = $record4r->key('G8VbXumewVs');
        $this->assertNotNull($key3);
        $this->assertSame('revoked', $key3->status());
        $this->assertSame(5, $key3->revokedFrom());
        $this->assertSame(1770001200, $key3->validUntil());
    }

    /** L2-VER-037, L2-AUT-005: Record 4C is rejected as the sole post-establishment revokedFrom mutation. */
    public function testRecord4CRejectedForRevokedFromMutationAndTipStaysAt5(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A', 'Record4R']);
        $acceptance = Stage2TestSupport::walker()->accept($state, Stage2TestSupport::wire('Record4C'));

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(5, $acceptance->phase());
        $this->assertStringContainsString('revokedFrom', $acceptance->reason());
        $this->assertSame(5, $acceptance->state()->acceptedMaximum());
        $this->assertSame(5, $acceptance->state()->record(5)?->key('G8VbXumewVs')?->revokedFrom());
    }

    /** L2-AUT-013: Record 5V is rejected because key4's window in Record 4 excludes issuedAt(5V). */
    public function testRecord5VRejectedForWindowExclusionDespiteVerifyingSignature(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4']);
        $acceptance = Stage2TestSupport::walker()->accept($state, Stage2TestSupport::wire('Record5V'));

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(5, $acceptance->phase());
        $this->assertStringContainsString('issuedAt', $acceptance->reason());
        $this->assertSame(4, $acceptance->state()->acceptedMaximum());
        $this->assertFalse($acceptance->state()->isEpochAccepted('epoch-2'));
    }

    /** L2-AUT-003: `recordGeneration` must be contiguous; Record 3 presented after Record 1 breaks the chain at the gap. */
    public function testGenerationGapBreaksTheChainAtTheFirstOffendingRecord(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1']);
        $acceptance = Stage2TestSupport::walker()->accept($state, Stage2TestSupport::wire('Record3'));

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(5, $acceptance->phase());
        $this->assertStringContainsString('contiguity gap', $acceptance->reason());
        $this->assertSame(1, $acceptance->state()->acceptedMaximum());
    }

    /** L2-AUT-003: Record 0 replayed after the chain started is rejected. */
    public function testRecord0ReplayAfterChainStartIsRejected(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1']);
        $acceptance = Stage2TestSupport::walker()->accept($state, Stage2TestSupport::wire('Record0'));

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(5, $acceptance->phase());
    }

    /** L2-AUT-003: issuedAt regression breaks the chain. */
    public function testIssuedAtRegressionBreaksChainAcceptance(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1']);
        // Record 2 with issuedAt regressed below issuedAt(1) = 1769997000, re-signed under key2
        $wire = Stage2TestSupport::wireWithChanges('Record2', ['issuedAt' => 1769996900], 2);
        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(5, $acceptance->phase());
        $this->assertStringContainsString('issuedAt regressed', $acceptance->reason());
    }

    /** L2-AUT-004, L2-AUT-021: a key entry must not change identity; re-registering keyId against a different publicKey is rejected. */
    public function testKeyEntryIdentityChangeIsRejectedAtChainAcceptance(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1']);
        // Record 2 re-registers key2 `4KKXd7XSJW8` against key5's publicKey
        $wire = Stage2TestSupport::wireWithKeyChanges('Record2', '4KKXd7XSJW8', ['publicKey' => L2Fixtures::keys()['key5']['publicKey']], 2);
        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(1, $acceptance->phase());
    }

    /** L2-AUT-004: a Record that deletes an existing keys entry is rejected. */
    public function testKeyEntryDeletionIsRejectedAtChainAcceptance(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2']);
        // Record 3 drops key2 from the keys array
        $payload = Stage2TestSupport::payloadOf('Record3');
        $payload['keys'] = array_values(array_filter(
            $payload['keys'],
            static fn (array $entry): bool => $entry['keyId'] !== '4KKXd7XSJW8',
        ));
        $canonical = JcsCanonicalizer::canonicalize($payload);
        $wire = L2Fixtures::envelope($canonical, Stage2TestSupport::sign($canonical, 3));

        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);
        $this->assertFalse($acceptance->accepted());
        $this->assertSame(5, $acceptance->phase());
        $this->assertStringContainsString('deleted', $acceptance->reason());
    }

    /** L2-AUT-006, L2-VER-036: a terminal key must never return to `current`. */
    public function testTerminalKeyReturningToCurrentIsRejected(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2']);
        // Record 3 presents key1 (retired in Record 2 history) as current again
        $wire = Stage2TestSupport::wireWithKeyChanges('Record3', 'ECMERdvpV0k', ['status' => 'current'], 3);
        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);

        $this->assertFalse($acceptance->accepted());
        $this->assertStringContainsString('terminal', $acceptance->reason());
    }

    /** L2-AUT-005/§9.5 positive (L2-VER-035 support): current -> compromised with revokedFrom == G and the established window unchanged is accepted. */
    public function testCurrentToCompromisedTransitionWithRevokedFromIsAccepted(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A']);
        // Record 4R mutated so key3 is revoked by a `compromised` classification at G = 5
        $wire = Stage2TestSupport::wireWithKeyChanges('Record4R', 'G8VbXumewVs', ['status' => 'compromised'], 3);
        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);

        $this->assertTrue($acceptance->accepted());
        $compromised = $acceptance->state()->record(5)?->key('G8VbXumewVs');
        $this->assertNotNull($compromised);
        $this->assertSame('compromised', $compromised->status());
        $this->assertSame(5, $compromised->revokedFrom());
        $this->assertSame(1770001200, $compromised->validUntil());
    }

    /** L2-AUT-007: a retirement with validUntil above the truncation cap is rejected. */
    public function testRetirementAboveTruncationCapIsRejected(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1']);
        // Record 2 above key2's cap issuedAt(2)=1769997600 (fixture value is exactly 1769997600; widen it)
        $wire = Stage2TestSupport::wireWithKeyChanges('Record2', '4KKXd7XSJW8', ['validUntil' => 1770001200], 2);
        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(5, $acceptance->phase());
        $this->assertStringContainsString('truncation', $acceptance->reason());
    }

    /** L2-AUT-008/§9.5: a non-transfer Record must repeat the previous floor; Record 2 repeating floor is accepted (fixture), Record 3 raising floor is rejected. */
    public function testNonTransferRecordMustRepeatFloor(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2']);
        $wire = Stage2TestSupport::wireWithChanges('Record3', ['statementFloor' => 1], 3);
        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);

        $this->assertFalse($acceptance->accepted());
        $this->assertStringContainsString('floor', $acceptance->reason());
    }

    /** L2-AUT-009: an epoch-introducing Record must carry the full trail; Record 2 without transfer is rejected. */
    public function testEpochChangeWithoutTransferIsRejected(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1']);
        $wire = Stage2TestSupport::wireWithChanges('Record2', ['transfer' => null], 2);
        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(5, $acceptance->phase());
        $this->assertStringContainsString('without an embedded transfer', $acceptance->reason());
        $this->assertSame(1, $acceptance->state()->acceptedMaximum());
    }

    /** L2-AUT-009: a Record whose epochId equals the previous Record's must not carry transfer; Record 3 with a copy of the trail is rejected. */
    public function testTransferMemberForbiddenOnSameEpochRecord(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2']);
        // mutate Record 3 to epoch-1 == prev epoch is already equal: add the trail member
        $trailPayload = Stage2TestSupport::payloadOf('Trail2');
        $wire = Stage2TestSupport::wireWithChanges('Record3', [
            'transfer' => ['payload' => $trailPayload, 'signature' => Stage2TestSupport::vector('Trail2')['signature']],
        ], 3);
        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);

        $this->assertFalse($acceptance->accepted());
        $this->assertStringContainsString('new epochId', $acceptance->reason());
    }

    /** L2-AUT-014: a mismatched trail (trail epochId != Record G-1 epoch) stops the chain at Record 1. */
    public function testTrailEpochMismatchStopsChainAtRecord1(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1']);
        $wire = Stage2TestSupport::wireWithTrailChanges('Record2', ['epochId' => 'epoch-9'], 2, 2);
        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(5, $acceptance->phase());
        $this->assertSame(1, $acceptance->state()->acceptedMaximum());
        $this->assertTrue($acceptance->state()->hasChainStop());
    }

    /** L2-AUT-014: a trail signed by a key outside the accepted prefix (key5 signs the trail) is an unsigned transfer attempt. */
    public function testTrailSignedByForeignKeyIsUnsignedTransferAttempt(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1']);
        $wire = Stage2TestSupport::wireWithTrailChanges('Record2', [], 5, 2);
        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(5, $acceptance->phase());
        $this->assertSame(Verdict::UNKNOWN_AUTHORITY, $acceptance->verdict());
        $this->assertStringContainsString('unsigned transfer attempt', $acceptance->reason());
        $this->assertSame(1, $acceptance->state()->acceptedMaximum());
    }

    /** L2-AUT-014/L2-VER-014: a trail recordGeneration mismatch stops the chain at Record 1. */
    public function testTrailRecordGenerationMismatchStopsChainAtRecord1(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1']);
        $wire = Stage2TestSupport::wireWithTrailChanges('Record2', ['recordGeneration' => 3], 2, 2);
        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);

        $this->assertFalse($acceptance->accepted());
        $this->assertStringContainsString('recordGeneration', $acceptance->reason());
        $this->assertSame(1, $acceptance->state()->acceptedMaximum());
    }

    /** L2-AUT-012: Record 2 trail verifies under the outgoing key publicKey recorded in Record 1. */
    public function testTrailSignatureVerifiesUnderOutgoingKeyOfPreviousRecord(): void
    {
        $trail = Record::fromWire(Stage2TestSupport::wire('Record2'))->transfer();
        $prev = Record::fromWire(Stage2TestSupport::wire('Record1'));
        $outgoing = $prev->key('4KKXd7XSJW8');
        $this->assertNotNull($trail);
        $this->assertNotNull($outgoing);

        $verifier = new SodiumEd25519Verifier();
        $verdict = $verifier->verify($trail->algorithm(), $outgoing->publicKeyBase64Url(), $trail->signatureBase64Url(), $trail->canonicalPayloadOctets());

        $this->assertSame(Verdict::VERIFIED, $verdict);
        $this->assertTrue($outgoing->containsTime(Record::fromWire(Stage2TestSupport::wire('Record2'))->issuedAt()));
    }

    /** §12.2/L2-AUT-010: outgoingKeyId must equal Record G signerKeyId; Record 2 pointing at key1 is rejected. */
    public function testTrailOutgoingKeyIdMustEqualRecordSignerKeyId(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1']);
        // outgoingKeyId key1 (ECMERdvpV0k) != Record 2 signerKeyId (key2); re-sign trail under key2
        $wire = Stage2TestSupport::wireWithTrailChanges('Record2', ['outgoingKeyId' => 'ECMERdvpV0k'], 2, 2);
        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);

        $this->assertFalse($acceptance->accepted());
        $this->assertStringContainsString('outgoingKeyId', $acceptance->reason());
    }

    /** L2-AUT-016: trail validity is inherited from Record G; the trail has no validity window. */
    public function testTrailCarriesNoValidityWindow(): void
    {
        $trailPayload = Record::fromWire(Stage2TestSupport::wire('Record2'))->transfer()?->payload();
        $this->assertIsArray($trailPayload);
        $this->assertArrayNotHasKey('issuedAt', $trailPayload);
        $this->assertArrayNotHasKey('expiresAt', $trailPayload);
        $this->assertArrayNotHasKey('validFrom', $trailPayload);
        $this->assertArrayNotHasKey('validUntil', $trailPayload);
    }

    /** L2-AUT-017/L2-AUT-009 (Record 2 fixture equality): incoming key window includes Record 2 issuedAt. */
    public function testIncomingKeyWindowIncludesRecordIssuedAt(): void
    {
        $record2 = Record::fromWire(Stage2TestSupport::wire('Record2'));
        $incoming = $record2->key('G8VbXumewVs');
        $this->assertNotNull($incoming);
        $this->assertTrue($incoming->containsTime($record2->issuedAt()));
    }

    /** L2-AUT-011: Record 2 presenting key5 as a second current key violates the sole-current rule. */
    public function testExtraCurrentKeyViolatesSoleCurrentRuleAtEpochSwitch(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1']);
        $payload = Stage2TestSupport::payloadOf('Record2');
        $key5 = L2Fixtures::keys()['key5'];
        $payload['keys'][] = [
            'algorithm' => 'ed25519',
            'keyId' => $key5['keyId'],
            'publicKey' => $key5['publicKey'],
            'status' => 'current',
            'validFrom' => 1769997600,
            'validUntil' => 1770001200,
        ];
        $canonical = JcsCanonicalizer::canonicalize($payload);
        $wire = L2Fixtures::envelope($canonical, Stage2TestSupport::sign($canonical, 2));

        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);

        $this->assertFalse($acceptance->accepted());
        $this->assertStringContainsString('sole current key', $acceptance->reason());
    }

    /** L2-AUT-013 (digest binding): Record 5V's prevRecordDigest pins Record 4's payload digest, so Record 4 must be accepted at generation 4. */
    public function testRecord5VDigestBindsRecord4PayloadDigest(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4']);
        // Record 5V's pinned prevRecordDigest equals Record 4's payloadDigest
        $this->assertSame('cf347228c0a32f5b47f10bff1ef86ca3e20b34172dceda39b706280db0a0665d', Record::fromWire(Stage2TestSupport::wire('Record4'))->payloadDigest());
        $this->assertSame('cf347228c0a32f5b47f10bff1ef86ca3e20b34172dceda39b706280db0a0665d', Stage2TestSupport::payloadOf('Record5V')['prevRecordDigest']);
    }

    /** L2-AUT-022: chain walk rejects a Record whose wire keyId fails §9.4 recomputation. */
    public function testChainWalkRejectsKeyIdRecomputationFailureAtPhase1(): void
    {
        $state = Stage2TestSupport::walkState(['Record0']);
        $wire = Stage2TestSupport::wireWithKeyChanges('Record1', '4KKXd7XSJW8', ['publicKey' => L2Fixtures::keys()['key5']['publicKey']], 1);
        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(1, $acceptance->phase());
        $this->assertSame(Verdict::INVALID, $acceptance->verdict());
        $this->assertStringContainsString('§9.4', $acceptance->reason());
    }

    /** L2-VER-005 (Record side): an unsupported algorithm Record stops the chain at phase 3, never UNVERIFIED. */
    public function testUnsupportedAlgorithmRecordStopsChainAtPhase3(): void
    {
        $state = Stage2TestSupport::walkState(['Record0']);
        $wire = Stage2TestSupport::wireWithChanges('Record1', ['algorithm' => 'ES256'], 1);
        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(3, $acceptance->phase());
        $this->assertSame(Verdict::UNSUPPORTED_ALGORITHM, $acceptance->verdict());
    }

    /**
     * L2-AUT-019 (fixture positive, key3 present in Record 3 for Record 4) and the
     * key-substitution counterpart: Record 2 re-signed under key3 — the signer named by
     * signerKeyId (key2) is present in Record 1, so the signature check under key2's
     * publicKey fails: INVALID at phase 5, never accepted.
     */
    public function testRecordSignatureUnderForeignKeyIsInvalidAtChainAcceptance(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1']);
        // Record 2 payload unchanged, re-signed under key3, signerKeyId stays key2
        $wire = Stage2TestSupport::wireWithChanges('Record2', [], 3);
        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(5, $acceptance->phase());
        $this->assertSame(Verdict::INVALID, $acceptance->verdict());
        $this->assertStringContainsString('signature does not verify', $acceptance->reason());
        $this->assertSame(1, $acceptance->state()->acceptedMaximum());
    }

    /** L2-VER-014/record-wire-duplicate: raw wire duplicate members stop the chain at Record 1 with INVALID at phase 1 (P1-2: recorded as a wire-parse chain stop). */
    public function testWireDuplicateMembersStopChainAtRecord1(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1']);
        $canonical = Stage2TestSupport::vector('Record2')['canonical'];
        $rawPayload = substr($canonical, 0, -1) . ',"signerKeyId":"4KKXd7XSJW8"}';
        $wire = Stage2TestSupport::wireFromRawPayload($rawPayload, 2);
        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(1, $acceptance->phase());
        $this->assertSame(Verdict::INVALID, $acceptance->verdict());
        $this->assertTrue($acceptance->state()->hasChainStop());
        $this->assertSame(ChainStopKind::WIRE_PARSE, $acceptance->state()->chainStopKind());
        $this->assertSame(1, $acceptance->state()->acceptedMaximum());
    }

    /** Record entityId mismatch is UNKNOWN_AUTHORITY at phase 5 (Canonical Entity Identity). */
    public function testRecordEntityIdMismatchIsUnknownAuthorityAtChainAcceptance(): void
    {
        $state = Stage2TestSupport::walkState(['Record0']);
        $wire = Stage2TestSupport::wireWithChanges('Record1', ['entityId' => 'urn:relink:entity:other-1'], 1);
        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(5, $acceptance->phase());
        $this->assertSame(Verdict::UNKNOWN_AUTHORITY, $acceptance->verdict());
    }

    /** Record anchorId mismatch against the Trust Anchor Binding stops the chain as UNVERIFIED. */
    public function testRecordAnchorIdMismatchStopsChainAsUnverified(): void
    {
        $state = Stage2TestSupport::walkState(['Record0']);
        $wire = Stage2TestSupport::wireWithChanges('Record1', ['anchorId' => '550e8400-e29b-41d4-a716-446655440001'], 1);
        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(5, $acceptance->phase());
        $this->assertSame(Verdict::UNVERIFIED, $acceptance->verdict());
    }

    /** L2-AUT-002 (Record 0 form): Record 0 presenting key2 as a second key is rejected by the binding shape rules. */
    public function testRecord0MustCarryExactlyTheBindingKeyEntry(): void
    {
        $payload = Stage2TestSupport::payloadOf('Record0');
        $key2 = L2Fixtures::keys()['key2'];
        $payload['keys'][] = [
            'algorithm' => 'ed25519',
            'keyId' => $key2['keyId'],
            'publicKey' => $key2['publicKey'],
            'status' => 'current',
            'validFrom' => 1769996400,
            'validUntil' => 1770000600,
        ];
        $canonical = JcsCanonicalizer::canonicalize($payload);
        $wire = L2Fixtures::envelope($canonical, Stage2TestSupport::sign($canonical, 1));

        $acceptance = Stage2TestSupport::walker()->accept(
            Stage2TestSupport::walker()->initialState(Stage2TestSupport::binding()),
            $wire,
        );

        $this->assertFalse($acceptance->accepted());
        $this->assertStringContainsString('binding key entry', $acceptance->reason());
    }

    /** L2-AUT-001: a Record 0 whose signature does not verify under the binding key is rejected. */
    public function testRecord0SignedUnderForeignKeyIsRejectedByBindingVerification(): void
    {
        $wire = Stage2TestSupport::wireWithChanges('Record0', [], 3);
        $acceptance = Stage2TestSupport::walker()->accept(
            Stage2TestSupport::walker()->initialState(Stage2TestSupport::binding()),
            $wire,
        );

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(5, $acceptance->phase());
        $this->assertSame(Verdict::INVALID, $acceptance->verdict());
    }

    /** L2-VER-010 (Record 1 pinned digest): Record 1 carries the pinned prevRecordDigest of Record 0. */
    public function testRecord1PinnedPrevRecordDigestMatchesRecord0PayloadDigest(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1']);
        $record1 = $state->record(1);
        $this->assertNotNull($record1);
        $this->assertSame('36ce3c7bf819434ebc79e534649873081ee0999af85c798c6586235680809afb', $record1->prevRecordDigest());
        $this->assertSame($state->record(0)?->payloadDigest(), $record1->prevRecordDigest());
    }

    /**
     * L2-AUT-022 (P1-2): a Record whose key entry publicKey fails §9.4 recomputation must
     * be recorded as a chain stop with the dedicated keyId-recomputation kind, and the
     * dependent statement (signer key4, unresolvable inside accepted prefix 0..3) is
     * UNKNOWN_AUTHORITY at phase 5, never INVALID.
     */
    public function testKeyIdRecomputationFailureStopsChainWithDedicatedKindAndDependentStatementIsUnknownAuthority(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);
        $tampered = Stage2TestSupport::wireWithKeyChanges('Record4A', 'G8VbXumewVs', ['publicKey' => Stage2TestSupport::publicKeyOf(5)], 3);
        $acceptance = Stage2TestSupport::walker()->accept($state, $tampered);

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(Verdict::INVALID, $acceptance->verdict());
        $this->assertSame(1, $acceptance->phase());
        $this->assertTrue($acceptance->state()->hasChainStop());
        $this->assertSame(ChainStopKind::KEY_ID_RECOMPUTATION, $acceptance->state()->chainStopKind());

        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S9v1'), $acceptance->state(), 1769998300);

        $this->assertSame(Verdict::UNKNOWN_AUTHORITY, $result->verdict());
        $this->assertSame(5, $result->phase());
    }

    /** §15.2 phase 1: a wire parse failure is recorded as a chain stop with the wire-parse kind. */
    public function testWireParseFailureStopsChainWithWireParseKind(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);
        $nonCanonical = Stage2TestSupport::swapLeadingKeys(Stage2TestSupport::vector('Record4A')['canonical']);
        $wire = Stage2TestSupport::wireFromRawPayload($nonCanonical, 3);
        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(Verdict::INVALID, $acceptance->verdict());
        $this->assertSame(1, $acceptance->phase());
        $this->assertSame(ChainStopKind::WIRE_PARSE, $acceptance->state()->chainStopKind());
    }

    /** §9.7, §15.2 phase 7: a Record below the accepted tip is never re-accepted. */
    public function testRecordBelowAcceptedTipIsNeverReAccepted(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A']);
        $this->assertSame(4, $state->acceptedMaximum());

        $acceptance = Stage2TestSupport::walker()->accept($state, Stage2TestSupport::wire('Record3'));

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(Verdict::UNVERIFIED, $acceptance->verdict());
        $this->assertSame(5, $acceptance->phase());
        $this->assertStringContainsString('below the accepted tip', $acceptance->reason());
        $this->assertSame(ChainStopKind::CHAIN, $acceptance->state()->chainStopKind());
    }

    /** §9.7, §11.3: byte-identical replay at the accepted tip is accepted without state mutation. */
    public function testByteIdenticalReplayAtTipIsAcceptedWithoutStateMutation(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A']);
        $acceptance = Stage2TestSupport::walker()->accept($state, Stage2TestSupport::wire('Record4A'));

        $this->assertTrue($acceptance->accepted());
        $this->assertFalse($acceptance->state()->hasChainStop());
        $this->assertSame(4, $acceptance->state()->acceptedMaximum());
        $this->assertSame(Stage2TestSupport::vector('Record4A')['canonical'], $acceptance->state()->record(4)?->canonicalPayloadOctets());
    }

    /** §9.7, §15.2 phase 7: a same-generation Record with a different payload at the tip is CONFLICT and stops the chain. */
    public function testSameGenerationDifferentPayloadAtTipIsConflictStop(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A']);
        // Record 4 carries the same generation (4) as Record 4A with a different payload
        $acceptance = Stage2TestSupport::walker()->accept($state, Stage2TestSupport::wire('Record4'));

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(Verdict::CONFLICT, $acceptance->verdict());
        $this->assertSame(7, $acceptance->phase());
        $this->assertTrue($acceptance->state()->hasChainStop());
        $this->assertSame(ChainStopKind::CHAIN, $acceptance->state()->chainStopKind());
    }

    /** §9.6 item 1: Record 0's algorithm must match the Trust Anchor Binding algorithm label. */
    public function testRecord0AlgorithmMustMatchBindingAlgorithm(): void
    {
        $binding = Stage2TestSupport::bindingWithAlgorithm('es256');
        $acceptance = Stage2TestSupport::walker()->accept(
            Stage2TestSupport::walker()->initialState($binding),
            Stage2TestSupport::wire('Record0'),
        );

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(Verdict::UNVERIFIED, $acceptance->verdict());
        $this->assertSame(5, $acceptance->phase());
        $this->assertStringContainsString('algorithm', $acceptance->reason());
    }

    /** §9.3, §9.4: an Ed25519 Trust Anchor Binding publicKey must be exactly 32 octets. */
    public function testBindingEd25519PublicKeyMustBeThirtyTwoOctets(): void
    {
        $thirtyOneBytes = str_repeat("\x07", 31);
        $encoded = Base64Url::encode($thirtyOneBytes);

        $this->expectException(PayloadStructureException::class);
        TrustAnchorBinding::fromPolicyChannel(
            Stage2TestSupport::ANCHOR_ID,
            Stage2TestSupport::ENTITY_ID,
            KeyId::compute($thirtyOneBytes),
            $encoded,
            'ed25519',
            'epoch-0',
            0,
        );
    }
}
