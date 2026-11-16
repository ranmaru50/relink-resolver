<?php
// tests/L2/Stage2VerifierStateTest.php
// VerifierState：受理 prefix、§11.4 effective prefix と snapshot k、current accepted epoch、
// statement floor、key snapshot、freshness、受理済み statement maximum、chain stop、不変性。
// 対応 Conformance cases: L2-VER-022, 023, 024, 025, 026, 029, 047（pinned states）。

declare(strict_types=1);

namespace Relink\Resolver\Tests\L2;

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Domain\L2\Record;
use Relink\Resolver\Domain\L2\Verdict;
use Relink\Resolver\Domain\L2\VerifierState;

require_once __DIR__ . '/Stage2TestSupport.php';

final class Stage2VerifierStateTest extends TestCase
{
    /** The empty state carries the binding and a statement maximum of -1 (no accepted material). */
    public function testEmptyStateHasNoAcceptedRecordsAndStatementMaximumMinusOne(): void
    {
        $state = VerifierState::empty(Stage2TestSupport::binding());

        $this->assertSame(-1, $state->acceptedMaximum());
        $this->assertSame([], $state->acceptedRecords());
        $this->assertSame([], $state->acceptedEpochs());
        $this->assertSame(-1, $state->statementMaximum());
        $this->assertNull($state->snapshotAt(Stage2TestSupport::T));
        $this->assertNull($state->currentEpochAt(Stage2TestSupport::T));
        $this->assertNull($state->chainTipEpoch());
        $this->assertFalse($state->hasChainStop());
        $this->assertSame(Stage2TestSupport::ANCHOR_ID, $state->binding()->anchorId());
    }

    /**
     * L2-VER-022, §11.4: the effective prefix at T is the longest contiguous accepted
     * prefix 0..k with every issuedAt <= T; Records with issuedAt > T (Record 4B,
     * issuedAt 1769998700) are excluded and their existence is not a phase 6 failure.
     */
    public function testEffectivePrefixAtTIs0ToKWithNonDecreasingIssuedAt(): void
    {
        // accepted chain 0..3, 4A, 4R, 4B: non-decreasing issuedAt (A.5), k = 5 at T = 1769998400
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A', 'Record4R', 'Record4B']);

        $prefix = $state->effectivePrefixAt(Stage2TestSupport::T);
        $this->assertCount(6, $prefix);
        $this->assertSame([0, 1, 2, 3, 4, 5, 6], array_keys($state->acceptedRecords()));

        $snapshot = $state->snapshotAt(Stage2TestSupport::T);
        $this->assertNotNull($snapshot);
        $this->assertSame(5, $snapshot->recordGeneration());
        $this->assertSame('epoch-1', $snapshot->epochId());
        $this->assertSame('epoch-1', $state->currentEpochAt(Stage2TestSupport::T));
        $this->assertSame(0, $state->statementFloorAt(Stage2TestSupport::T));

        // Record 4B (issuedAt 1769998700) is not effective at 1769998600
        $prefixLate = $state->effectivePrefixAt(1769998600);
        $this->assertCount(6, $prefixLate);
        // at T = 1769998700, Record 4B becomes effective and the snapshot moves to G 6
        $prefixAt4B = $state->effectivePrefixAt(1769998700);
        $this->assertCount(7, $prefixAt4B);
        $snapshot4B = $state->snapshotAt(1769998700);
        $this->assertNotNull($snapshot4B);
        $this->assertSame(6, $snapshot4B->recordGeneration());
        // far in the future, the whole accepted prefix is effective
        $this->assertCount(7, $state->effectivePrefixAt(1770002000));
    }

    /** L2-VER-023, §11.4: freshness is evaluated only on the snapshot k; Record 2 is not fresh at T = 1769998400. */
    public function testFreshnessEvaluatedOnSnapshotOnly(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2']);

        $snapshot = $state->snapshotAt(Stage2TestSupport::T);
        $this->assertNotNull($snapshot);
        $this->assertSame(2, $snapshot->recordGeneration());
        $this->assertFalse($state->isSnapshotFreshAt(Stage2TestSupport::T, Stage2TestSupport::W));
        // T - issuedAt(2) = 1769998400 - 1769997600 = 800 > W
        $this->assertSame(800, Stage2TestSupport::T - $snapshot->issuedAt());
    }

    /** L2-VER-024, §11.4: the freshness condition is issuedAt <= T <= expiresAt and T - issuedAt <= W, inclusive at both ends. */
    public function testFreshnessBoundaryInclusiveAtIssuedAtAndExpiresAt(): void
    {
        // snapshot Record 0: issuedAt 1769996400, expiresAt 1770000000
        $state0 = Stage2TestSupport::walkState(['Record0']);
        $record0 = $state0->record(0);
        $this->assertNotNull($record0);
        $this->assertSame(1769996400, $record0->issuedAt());
        $this->assertSame(1770000000, $record0->expiresAt());

        $this->assertTrue($state0->isSnapshotFreshAt(1769996400, Stage2TestSupport::W));
        // delta == W is inclusive
        $this->assertTrue($state0->isSnapshotFreshAt(1769997000, Stage2TestSupport::W));
        // delta = W + 1 fails
        $this->assertFalse($state0->isSnapshotFreshAt(1769997001, Stage2TestSupport::W));
        // T == expiresAt is inclusive under W = 3600
        $this->assertTrue($state0->isSnapshotFreshAt(1770000000, 3600));
        // T > expiresAt fails
        $this->assertFalse($state0->isSnapshotFreshAt(1770000001, 3601));
        // a T before the prefix: empty effective prefix, no snapshot, not fresh
        $this->assertFalse($state0->isSnapshotFreshAt(1769996399, Stage2TestSupport::W));

        // snapshot Record 4E: expiresAt 1769998300, equality boundary fresh, one second past expired
        $state4e = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4E']);
        $this->assertTrue($state4e->isSnapshotFreshAt(1769998300, Stage2TestSupport::W));
        $this->assertFalse($state4e->isSnapshotFreshAt(1769998301, Stage2TestSupport::W));
    }

    /** L2-VER-025: the key snapshot at T reports the snapshot Record's entry, not a later Record's. */
    public function testKeySnapshotIsTheSnapshotRecordEntryAtT(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);

        $key2AtT = $state->keyAtSnapshot(Stage2TestSupport::T, '4KKXd7XSJW8');
        $this->assertNotNull($key2AtT);
        $this->assertSame('retired', $key2AtT->status());
        // key2 is retired with validUntil truncated to issuedAt(G 2) = 1769997600 in Record 2
        $this->assertSame(1769997600, $key2AtT->validUntil());

        $key3AtT = $state->keyAtSnapshot(Stage2TestSupport::T, 'G8VbXumewVs');
        $this->assertNotNull($key3AtT);
        $this->assertSame('current', $key3AtT->status());
        // Record 3 does not update the floor, epoch, or key snapshot from Record 4A
        $this->assertNull($state->keyAtSnapshot(Stage2TestSupport::T, 'ECYh7yJFAn8'));
    }

    /** L2-VER-026, §11.4, §13.4: the revocation snapshot at T is Record 4R; key3 revoked from G 5. */
    public function testRevocationSnapshotAtRevocationBoundary(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A', 'Record4R']);

        $snapshot = $state->snapshotAt(Stage2TestSupport::T);
        $this->assertNotNull($snapshot);
        $this->assertSame(5, $snapshot->recordGeneration());
        $this->assertTrue($state->isSnapshotFreshAt(Stage2TestSupport::T, Stage2TestSupport::W));

        $key3 = $state->keyAtSnapshot(Stage2TestSupport::T, 'G8VbXumewVs');
        $this->assertNotNull($key3);
        $this->assertSame('revoked', $key3->status());
        $this->assertSame(5, $key3->revokedFrom());
    }

    /** L2-VER-029, §11.3: the accepted statement maximum moves only with the accepted statement payload. */
    public function testStatementMaximumAndByteIdentityTrackAcceptedPayloads(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A']);
        $this->assertSame(-1, $state->statementMaximum());
        $this->assertFalse($state->isStatementByteIdentical(1, Stage2TestSupport::vector('S9v1')['canonical']));

        $after9v1 = $state->withAcceptedStatement(1, Stage2TestSupport::vector('S9v1')['canonical']);
        $this->assertSame(1, $after9v1->statementMaximum());
        $this->assertTrue($after9v1->isStatementByteIdentical(1, Stage2TestSupport::vector('S9v1')['canonical']));
        $this->assertFalse($after9v1->isStatementByteIdentical(1, Stage2TestSupport::vector('S9v2')['canonical']));

        $after9 = $state->withAcceptedStatement(2, Stage2TestSupport::vector('S9')['canonical']);
        $this->assertSame(2, $after9->statementMaximum());
        $this->assertTrue($after9->isStatementByteIdentical(2, Stage2TestSupport::vector('S9')['canonical']));
    }

    /** L2-VER-047: pinned state-base — prefix 0..3, 4A; snapshot fresh at T = 1769998300; key4 current. */
    public function testPinnedStateBaseForS9Family(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A']);

        $this->assertSame(4, $state->acceptedMaximum());
        $this->assertSame('epoch-1', $state->currentEpochAt(1769998300));
        $this->assertTrue($state->isSnapshotFreshAt(1769998300, Stage2TestSupport::W));
        $key4 = $state->keyAtSnapshot(1769998300, 'ECYh7yJFAn8');
        $this->assertNotNull($key4);
        $this->assertSame('current', $key4->status());
        $this->assertSame(1769998300, $key4->validFrom());
        $this->assertSame(1770001900, $key4->validUntil());
        $this->assertSame(-1, $state->statementMaximum());
    }

    /** §11.4: the pinned snapshot boundary at T = 1769998300 (Record 4A issuedAt) selects Record 4A. */
    public function testSnapshotAtBoundaryEqualitySelectsLatestEffectiveRecord(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A']);

        $snapshot = $state->snapshotAt(1769998300);
        $this->assertNotNull($snapshot);
        $this->assertSame(4, $snapshot->recordGeneration());
        $prefix = $state->effectivePrefixAt(1769998299);
        $this->assertCount(4, $prefix);
    }

    /** A chain stop is recorded once and retained immutably with its verdict and reason. */
    public function testChainStopIsImmutableAndCarriesVerdictAndReason(): void
    {
        $state = VerifierState::empty(Stage2TestSupport::binding())
            ->withChainStop(Verdict::UNVERIFIED, 'contiguity gap: Record 1 was never accepted (§9.6, §11.4)');

        $this->assertTrue($state->hasChainStop());
        $this->assertSame(Verdict::UNVERIFIED, $state->chainStopVerdict());
        $this->assertStringContainsString('contiguity gap', $state->chainStopReason());
        $this->assertSame(-1, $state->acceptedMaximum());

        $next = $state->withAcceptedStatement(1, 'payload-bytes');
        $this->assertTrue($next->hasChainStop());
        $this->assertSame(1, $next->statementMaximum());
        // immutability: the stop-bearing state is unchanged
        $this->assertSame(-1, $state->statementMaximum());
    }

    /** Immutability: withAcceptedRecord / withAcceptedStatement return new states; the original is unchanged. */
    public function testStateTransitionsAreImmutable(): void
    {
        $base = Stage2TestSupport::walkState(['Record0', 'Record1']);
        $record2 = Record::fromWire(Stage2TestSupport::wire('Record2'));
        $extended = $base->withAcceptedRecord($record2);

        $this->assertSame(1, $base->acceptedMaximum());
        $this->assertSame(2, $extended->acceptedMaximum());
        $this->assertSame(['epoch-0'], $base->acceptedEpochs());
        $this->assertSame(['epoch-0', 'epoch-1'], $extended->acceptedEpochs());
        $this->assertSame(1, $base->record(1)?->recordGeneration());
    }

    /** isKeyIdInAcceptedChain is satisfied by the Record 0 binding key and by a key introduced later. */
    public function testKeyIdInAcceptedChainResolvesBindingAndIntroducedKeys(): void
    {
        $base = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2']);

        $this->assertTrue($base->isKeyIdInAcceptedChain('ECMERdvpV0k'));
        $this->assertTrue($base->isKeyIdInAcceptedChain('4KKXd7XSJW8'));
        $this->assertTrue($base->isKeyIdInAcceptedChain('G8VbXumewVs'));
        $this->assertFalse($base->isKeyIdInAcceptedChain('ECYh7yJFAn8'));
        $this->assertFalse($base->isKeyIdInAcceptedChain('cIbUqaV2qxI'));
    }

    /** isEpochAccepted follows the accepted Records: epoch-0 from Record 0, epoch-1 from Record 2. */
    public function testAcceptedEpochsFollowAcceptedRecords(): void
    {
        $base = Stage2TestSupport::walkState(['Record0', 'Record1']);

        $this->assertTrue($base->isEpochAccepted('epoch-0'));
        $this->assertFalse($base->isEpochAccepted('epoch-1'));
        $this->assertSame('epoch-0', $base->chainTipEpoch());

        $withEpoch1 = $base->withAcceptedRecord(Record::fromWire(Stage2TestSupport::wire('Record2')));
        $this->assertTrue($withEpoch1->isEpochAccepted('epoch-1'));
        $this->assertSame('epoch-1', $withEpoch1->chainTipEpoch());
        $this->assertFalse($withEpoch1->isEpochAccepted('epoch-2'));
    }

    /** L2-VER-026 (state-s8 pin): prefix 0..5 including Record 4R; snapshot k = 5 is fresh at T. */
    public function testPinnedStateS8ForRevocationBoundary(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A', 'Record4R']);

        $this->assertSame(5, $state->acceptedMaximum());
        $snapshot = $state->snapshotAt(Stage2TestSupport::T);
        $this->assertNotNull($snapshot);
        $this->assertSame(5, $snapshot->recordGeneration());
        $this->assertTrue($state->isSnapshotFreshAt(Stage2TestSupport::T, Stage2TestSupport::W));
        $this->assertSame(0, $state->statementFloorAt(Stage2TestSupport::T));
    }
}
