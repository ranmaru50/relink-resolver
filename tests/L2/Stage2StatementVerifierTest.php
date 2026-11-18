<?php
// tests/L2/Stage2StatementVerifierTest.php
// StatementVerifier：§15.2 phase 1..11 の固定順序、§15.1 modes、§11.4 snapshot/freshness、
// §13.4 revocation、§12.3 epoch/floor、§9.4 signer resolvability、§7.4 l1-bound。
// 対応 Conformance cases: L2-VER-001..006, 008, 013, 014, 015, 019..027, 029, 030, 031,
// 033..036, 038..042, 044, 046; L2-AUT-022。

declare(strict_types=1);

namespace Relink\Resolver\Tests\L2;

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Domain\L2\Base64Url;
use Relink\Resolver\Domain\L2\ChainStopKind;
use Relink\Resolver\Domain\L2\VerificationMode;
use Relink\Resolver\Domain\L2\VerificationResult;
use Relink\Resolver\Domain\L2\Verdict;
use Relink\Resolver\Domain\L2\VerifierState;
use L2Fixtures;

require_once __DIR__ . '/Stage2TestSupport.php';

final class Stage2StatementVerifierTest extends TestCase
{
    /**
     * L2-VER-001 (statement A / S7, generation 1, floor 0, snapshot Record 3 fresh at
     * T = 1769998400): VERIFIED.
     */
    public function testStatementAIsVerifiedAtPinnedTWithGenerationStrictlyExceedingFloor(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);
        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S7'), $state, Stage2TestSupport::T);

        $this->assertTrue($result->isVerified());
        $this->assertSame(Verdict::VERIFIED, $result->verdict());
        $this->assertSame(VerificationMode::STATEMENT_ONLY, $result->mode());
        $this->assertNull($result->failedPhase());
    }

    /**
     * L2-VER-002 / L2-AUT-022: a Record rejected for §9.4 keyId recomputation makes the key
     * unresolvable; the dependent statement is UNKNOWN_AUTHORITY, never INVALID.
     */
    public function testDependentStatementOfKeyIdRecomputationFailureIsUnknownAuthorityNotInvalid(): void
    {
        $state = Stage2TestSupport::walkState(['Record0']);
        // Record 1 with key2 entry re-registered against foreign publicKey: §9.4 recomputation fails
        $tampered = Stage2TestSupport::wireWithKeyChanges('Record1', '4KKXd7XSJW8', ['publicKey' => L2Fixtures::keys()['key5']['publicKey']], 1);
        $acceptance = Stage2TestSupport::walker()->accept($state, $tampered);

        $this->assertFalse($acceptance->accepted());
        $this->assertSame(Verdict::INVALID, $acceptance->verdict());
        $this->assertSame(1, $acceptance->phase());
        $this->assertStringContainsString('§9.4', $acceptance->reason());
        $this->assertStringContainsString('keyId', $acceptance->reason());

        // The dependent statement S7 (signer key3) is judged against the chain that
        // never resolved the key: the signer is not resolvable inside the accepted
        // chain, so the statement fails as UNKNOWN_AUTHORITY, never as INVALID.
        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S7'), $acceptance->state(), Stage2TestSupport::T);

        $this->assertFalse($result->isVerified());
        $this->assertSame(Verdict::UNKNOWN_AUTHORITY, $result->verdict());
        $this->assertSame(5, $result->phase());
        // a §9.4 chain stop recorded in the state keeps the same classification
        $stopped = $state->withChainStop(Verdict::INVALID, 'keyId recomputation failed (§9.4)', ChainStopKind::KEY_ID_RECOMPUTATION);
        $result2 = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S7'), $stopped, Stage2TestSupport::T);

        $this->assertSame(Verdict::UNKNOWN_AUTHORITY, $result2->verdict());
        $this->assertSame(5, $result2->phase());
    }

    /** §9.4 (statement wire): a keyId member that fails recomputation is INVALID at phase 1. */
    public function testStatementWireKeyIdRecomputationFailureIsInvalidAtPhase1(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);
        // keyId replaced by key4's keyId while the signature is still under key3 material
        $wire = Stage2TestSupport::wireWithChanges('S7', ['keyId' => 'ECYh7yJFAn8'], 3);

        // key4 is not resolvable inside the accepted chain 0..3: phase 5 fails first,
        // phases 8-9 are never reached (§9.4)
        $result = Stage2TestSupport::verifier()->verify($wire, $state, Stage2TestSupport::T);

        $this->assertSame(Verdict::UNKNOWN_AUTHORITY, $result->verdict());
        $this->assertSame(5, $result->phase());

        // In chain 0..3,4A (key4 resolvable), the same wire fails the signature check at phase 8
        $state4A = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A']);
        $result2 = Stage2TestSupport::verifier()->verify($wire, $state4A, 1769998300);

        $this->assertSame(Verdict::INVALID, $result2->verdict());
        $this->assertSame(8, $result2->phase());
    }

    /** L2-VER-003 / L2-VER-046: raw wire duplicate member is INVALID at phase 1. */
    public function testWireDuplicateMemberIsInvalidAtPhase1(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);
        $raw = substr(Stage2TestSupport::vector('S7')['canonical'], 0, -1) . ',"keyId":"G8VbXumewVs"}';
        $wire = Stage2TestSupport::wireFromRawPayload($raw, 3);

        $result = Stage2TestSupport::verifier()->verify($wire, $state, Stage2TestSupport::T);

        $this->assertSame(Verdict::INVALID, $result->verdict());
        $this->assertSame(1, $result->phase());
    }

    /** L2-VER-046: unsorted payload keys are non-canonical; the wire is INVALID at phase 1. */
    public function testNonCanonicalPayloadKeyOrderIsInvalidAtPhase1(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);
        $payload = Stage2TestSupport::payloadOf('S7');
        $reversed = array_reverse($payload, true);
        $raw = json_encode($reversed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // the raw payload text is not in JCS order
        $this->assertNotSame(Stage2TestSupport::vector('S7')['canonical'], $raw);
        $wire = Stage2TestSupport::wireFromRawPayload($raw, 3);

        $result = Stage2TestSupport::verifier()->verify($wire, $state, Stage2TestSupport::T);

        $this->assertSame(Verdict::INVALID, $result->verdict());
        $this->assertSame(1, $result->phase());
    }

    /** L2-VER-046: a signature member inside the payload is INVALID at phase 1 (§7.1 separation). */
    public function testSignatureInsidePayloadIsInvalidAtPhase1(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);
        // "signature" sorts before "statementType": insert at the canonical position so the
        // payload span itself remains JCS; the separation check must catch the member.
        $raw = str_replace('"statementType"', '"signature":"aa","statementType"', Stage2TestSupport::vector('S7')['canonical']);
        $wire = Stage2TestSupport::wireFromRawPayload($raw, 3);

        $result = Stage2TestSupport::verifier()->verify($wire, $state, Stage2TestSupport::T);

        $this->assertSame(Verdict::INVALID, $result->verdict());
        $this->assertSame(1, $result->phase());
    }

    /** L2-VER-046: a non-integer issuedAt literal is INVALID at phase 1. */
    public function testNonIntegerTimestampIsInvalidAtPhase1(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);
        $wire = Stage2TestSupport::wireWithChanges('S7', ['issuedAt' => 1769998300.5], 3);

        $result = Stage2TestSupport::verifier()->verify($wire, $state, Stage2TestSupport::T);

        $this->assertSame(Verdict::INVALID, $result->verdict());
        $this->assertSame(1, $result->phase());
    }

    /** L2-VER-046: an l2Version other than "0.1" is INVALID at phase 1. */
    public function testL2VersionMismatchIsInvalidAtPhase1(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);
        $wire = Stage2TestSupport::wireWithChanges('S7', ['l2Version' => '0.2'], 3);

        $result = Stage2TestSupport::verifier()->verify($wire, $state, Stage2TestSupport::T);

        $this->assertSame(Verdict::INVALID, $result->verdict());
        $this->assertSame(1, $result->phase());
    }

    /** L2-VER-046 positive: an extension member beyond the §7.2 required members is allowed. */
    public function testExtensionMemberIsAllowedAtPhase1AndStatementVerifies(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);
        $wire = Stage2TestSupport::wireWithChanges('S7', ['extExample' => ['a' => [2, 3], 'z' => 1]], 3);

        $result = Stage2TestSupport::verifier()->verify($wire, $state, Stage2TestSupport::T);

        $this->assertTrue($result->isVerified());
        $this->assertSame(Verdict::VERIFIED, $result->verdict());
    }

    /** L2-VER-004: anchorId mismatch is ANCHOR_MISMATCH at phase 2, never INVALID. */
    public function testAnchorIdMismatchIsAnchorMismatchAtPhase2(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);
        $wire = Stage2TestSupport::wireWithChanges('S7', ['anchorId' => '550e8400-e29b-41d4-a716-446655440001'], 3);

        $result = Stage2TestSupport::verifier()->verify($wire, $state, Stage2TestSupport::T);

        $this->assertSame(Verdict::ANCHOR_MISMATCH, $result->verdict());
        $this->assertSame(2, $result->phase());
    }

    /** L2-VER-005 / Catalog line 805: ES256 is UNSUPPORTED_ALGORITHM at phase 3, never VERIFIED, never UNVERIFIED. */
    public function testEs256StatementIsUnsupportedAlgorithmAtPhase3(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);
        $wire = Stage2TestSupport::wireWithChanges('S7', ['algorithm' => 'ES256'], 3);

        $result = Stage2TestSupport::verifier()->verify($wire, $state, Stage2TestSupport::T);

        $this->assertSame(Verdict::UNSUPPORTED_ALGORITHM, $result->verdict());
        $this->assertSame(3, $result->phase());
    }

    /** L2-VER-005: an algorithm string outside the baseline ("ed25519x") is UNSUPPORTED_ALGORITHM at phase 3. */
    public function testUnknownAlgorithmStringIsUnsupportedAlgorithmAtPhase3(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);
        $wire = Stage2TestSupport::wireWithChanges('S7', ['algorithm' => 'ed25519x'], 3);

        $result = Stage2TestSupport::verifier()->verify($wire, $state, Stage2TestSupport::T);

        $this->assertSame(Verdict::UNSUPPORTED_ALGORITHM, $result->verdict());
        $this->assertSame(3, $result->phase());
    }

    /**
     * L2-VER-005, §15.2 phase 1 + phase 3 (P2-2): an unsupported algorithm presented with
     * a signature outside the 64-byte Ed25519 length must reach phase 3, not be rejected
     * as INVALID at phase 1; wire parse validates base64url shape only.
     */
    public function testUnsupportedAlgorithmWithShortSignatureReachesPhase3NotPhase1(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);
        $shortSignature = Base64Url::encode(str_repeat('x', 32));
        $wire = Stage2TestSupport::wireWithEnvelopeSignature('S7', $shortSignature, ['algorithm' => 'ES256']);

        $result = Stage2TestSupport::verifier()->verify($wire, $state, Stage2TestSupport::T);

        $this->assertSame(Verdict::UNSUPPORTED_ALGORITHM, $result->verdict());
        $this->assertSame(3, $result->phase());
    }

    /** L2-VER-008: no trusted clock (T = null) is UNVERIFIED at phase 4; no freshness judgment is made. */
    public function testNullTrustedClockIsUnverifiedAtPhase4(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);

        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S7'), $state, null);

        $this->assertSame(Verdict::UNVERIFIED, $result->verdict());
        $this->assertSame(4, $result->phase());
    }

    /** L2-VER-013: no accepted chain and no trusted lower bound is UNVERIFIED at phase 5. */
    public function testEmptyStateIsUnverifiedAtPhase5(): void
    {
        $state = VerifierState::empty(Stage2TestSupport::binding());

        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S7'), $state, Stage2TestSupport::T);

        $this->assertSame(Verdict::UNVERIFIED, $result->verdict());
        $this->assertSame(5, $result->phase());
    }

    /** L2-VER-014 / statement-a-signer-absent: signer outside the accepted chain is UNKNOWN_AUTHORITY at phase 5, phases 8-9 never reached. */
    public function testSignerAbsentFromAcceptedChainIsUnknownAuthorityAtPhase5(): void
    {
        // accepted prefix 0..1: key3 is not in the accepted chain
        $state = Stage2TestSupport::walkState(['Record0', 'Record1']);

        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S7'), $state, Stage2TestSupport::T);

        $this->assertSame(Verdict::UNKNOWN_AUTHORITY, $result->verdict());
        $this->assertSame(5, $result->phase());
        $this->assertFalse($state->isKeyIdInAcceptedChain('G8VbXumewVs'));
    }

    /** L2-VER-014 / statement-a-material-missing: a chain stop (trail matching failure) is UNVERIFIED at phase 5. */
    public function testMaterialMissingChainStopIsUnverifiedAtPhase5(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1']);
        $tampered = Stage2TestSupport::wireWithTrailChanges('Record2', ['epochId' => 'epoch-9'], 2, 2);
        $acceptance = Stage2TestSupport::walker()->accept($state, $tampered);
        $stopped = $acceptance->state();

        $this->assertTrue($stopped->hasChainStop());

        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S7'), $stopped, Stage2TestSupport::T);

        $this->assertSame(Verdict::UNVERIFIED, $result->verdict());
        $this->assertSame(5, $result->phase());
        $this->assertStringContainsString('chain walk stopped', $result->reason());
    }

    /** L2-VER-014 / chain-accepted-snapshot-gap: signer inside the accepted chain but not covered by the snapshot at T is UNVERIFIED at phase 6. */
    public function testSnapshotCoverageGapIsUnverifiedAtPhase6(): void
    {
        // accepted prefix 0..1; at T = 1769996500 the snapshot is Record 0, which does not carry key2
        $state = Stage2TestSupport::walkState(['Record0', 'Record1']);
        $this->assertTrue($state->isKeyIdInAcceptedChain('4KKXd7XSJW8'));

        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S5'), $state, 1769996500);

        $this->assertSame(Verdict::UNVERIFIED, $result->verdict());
        $this->assertSame(6, $result->phase());
        $this->assertStringContainsString('coverage gap', $result->reason());
    }

    /** L2-VER-019: Record 4F is accepted, not fresh at T' = 1770000000; back-off to Record 3 is prohibited. */
    public function testRecord4FNotFreshAtLateTIsUnverifiedAtPhase6WithoutBackOff(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4F']);

        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S7'), $state, 1770000000);

        $this->assertSame(Verdict::UNVERIFIED, $result->verdict());
        $this->assertSame(6, $result->phase());
        $this->assertStringContainsString('back-off', $result->reason());
        // the effective-latest Record 4F (T' - 1769999000 = 1000 > W at T' = 1770000000)
        // is the only snapshot candidate (Appendix A.5)
        $snapshot = $state->snapshotAt(1770000000);
        $this->assertNotNull($snapshot);
        $this->assertSame(4, $snapshot->recordGeneration());
        $this->assertFalse($state->isSnapshotFreshAt(1770000000, Stage2TestSupport::W));

        // at T = 1769998400, Record 4F (issuedAt > T) is outside the effective prefix and
        // MUST NOT contribute revocation, epoch, floor, or key evidence: the snapshot is
        // Record 3, fresh at T, and the post-transfer statement under key3 is VERIFIED
        $prefixAtT = $state->effectivePrefixAt(Stage2TestSupport::T);
        $this->assertCount(4, $prefixAtT);
        $this->assertSame(3, $prefixAtT[count($prefixAtT) - 1]->recordGeneration());
        $verifiedViaRecord3 = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S7'), $state, Stage2TestSupport::T);
        $this->assertSame(Verdict::VERIFIED, $verifiedViaRecord3->verdict());
    }

    /** L2-VER-020: effective-latest Record 4E expired at T = 1769998700; S7 is UNVERIFIED at phase 6, never VERIFIED. */
    public function testExpiredSnapshotRecord4EIsUnverifiedAtPhase6AtT1769998700(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4E']);

        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S7'), $state, 1769998700);

        $this->assertSame(Verdict::UNVERIFIED, $result->verdict());
        $this->assertSame(6, $result->phase());
        $this->assertFalse($state->isSnapshotFreshAt(1769998700, Stage2TestSupport::W));
        $snapshot = $state->snapshotAt(1769998700);
        $this->assertNotNull($snapshot);
        $this->assertSame(4, $snapshot->recordGeneration());
        // backing off to Record 3 (fresh at T) is prohibited: the snapshot is fixed to the effective-latest Record
        $prefix = $state->effectivePrefixAt(1769998700);
        $this->assertCount(5, $prefix);
    }

    /** L2-VER-021: VERIFIED only at the boundary T = 1769998300 where Record 4E is fresh with equality. */
    public function testBoundaryEqualityAtT1769998300IsVerified(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4E']);
        $this->assertTrue($state->isSnapshotFreshAt(1769998300, Stage2TestSupport::W));

        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S7'), $state, 1769998300);

        $this->assertTrue($result->isVerified());
        $this->assertSame(Verdict::VERIFIED, $result->verdict());
    }

    /** L2-VER-021: at T = 1769998200, S7 is not effective (issuedAt > T); failure at phase 10, not phase 6; never VERIFIED. */
    public function testEarlyTForStatementAIsExpiredAtPhase10NotPhase6(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);

        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S7'), $state, 1769998200);

        $this->assertSame(Verdict::EXPIRED, $result->verdict());
        $this->assertSame(10, $result->phase());
        $this->assertTrue($state->isSnapshotFreshAt(1769998200, Stage2TestSupport::W));
    }

    /** L2-VER-022: effective prefix excludes Records with issuedAt > T; k is the highest accepted generation with issuedAt(k) <= T; Record 4B's existence is not a phase 6 failure. */
    public function testEffectivePrefixAndSnapshotKByNonDecreasingRule(): void
    {
        // accepted chain 0..3, 4A, 4R, 4B (Appendix A.5, non-decreasing issuedAt);
        // Record 4B (G 6) has issuedAt 1769998700 > T = 1769998400 and is excluded;
        // the effective prefix is 0..5 and k = 5 (Record 4R).
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A', 'Record4R', 'Record4B']);
        $this->assertSame(6, $state->acceptedMaximum());

        $prefix = $state->effectivePrefixAt(Stage2TestSupport::T);
        $this->assertCount(6, $prefix);
        $last = $prefix[count($prefix) - 1];
        $this->assertSame(5, $last->recordGeneration());

        $snapshot = $state->snapshotAt(Stage2TestSupport::T);
        $this->assertNotNull($snapshot);
        $this->assertSame(5, $snapshot->recordGeneration());
        $this->assertTrue($state->isSnapshotFreshAt(Stage2TestSupport::T, Stage2TestSupport::W));
        $this->assertSame('epoch-1', $state->currentEpochAt(Stage2TestSupport::T));

        // Record 4B (issuedAt > T) is excluded from the prefix; its existence is not
        // by itself a phase 6 failure: S8 is judged REVOKED at phase 9 via snapshot 4R
        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S8'), $state, Stage2TestSupport::T);
        $this->assertSame(Verdict::REVOKED, $result->verdict());
        $this->assertSame(9, $result->phase());
    }

    /** L2-VER-023: re-presentation of Records 0..2 never moves the freshness point; snapshot stays Record 2. */
    public function testRetransmissionNeverRefreshesTheFreshnessPoint(): void
    {
        $walker = Stage2TestSupport::walker();
        $state = $walker->initialState(Stage2TestSupport::binding());
        foreach (['Record0', 'Record1', 'Record2'] as $id) {
            $state = $walker->accept($state, Stage2TestSupport::wire($id))->state();
        }

        for ($i = 0; $i < 5; $i++) {
            foreach (['Record0', 'Record1', 'Record2'] as $id) {
                $acceptance = $walker->accept($state, Stage2TestSupport::wire($id));
                // re-presentation at lower positions is STALE / contiguity-rejected; the
                // accepted state and its freshness point never move
                $this->assertFalse($acceptance->accepted());
                $state = $acceptance->state();
            }
        }

        $this->assertSame(2, $state->acceptedMaximum());
        $snapshot = $state->snapshotAt(Stage2TestSupport::T);
        $this->assertNotNull($snapshot);
        $this->assertSame(2, $snapshot->recordGeneration());
        $this->assertSame(1769997600, $snapshot->issuedAt());
        // T - issuedAt(2) = 800 > W = 600: freshness fails, retransmission did not refresh it
        $this->assertFalse($state->isSnapshotFreshAt(Stage2TestSupport::T, Stage2TestSupport::W));
    }

    /** L2-VER-024: no skew shifting manufactures freshness; T = 1769998301 past the boundary is UNVERIFIED. */
    public function testSkewToleranceNeverManufacturesFreshnessPastTheBoundary(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4E']);

        $fresh = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S7'), $state, 1769998300);
        $past = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S7'), $state, 1769998301);

        $this->assertTrue($fresh->isVerified());
        $this->assertSame(Verdict::UNVERIFIED, $past->verdict());
        $this->assertSame(6, $past->phase());
    }

    /** L2-VER-025 / L2-VER-033: prior-epoch statement S5 is STALE at phase 10 with phase 9 skipped. */
    public function testPriorEpochStatementIsStaleAtPhase10WithPhase9Skipped(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);

        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S5'), $state, Stage2TestSupport::T);

        $this->assertSame(Verdict::STALE, $result->verdict());
        $this->assertSame(10, $result->phase());
        // phase 6 passed (key2 is covered by snapshot Record 3); phase 9 skipped for prior epoch
        $this->assertNotNull($state->keyAtSnapshot(Stage2TestSupport::T, '4KKXd7XSJW8'));
    }

    /** L2-VER-015: Record 4 accepted, S6 (epoch-2, not accepted) is STALE; key4 window contains issuedAt(4). */
    public function testStatementS6AtT1770001200IsStaleForUnacceptedEpoch(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4']);

        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S6'), $state, 1770001200);

        $this->assertSame(Verdict::STALE, $result->verdict());
        $this->assertSame(10, $result->phase());
        $this->assertFalse($state->isEpochAccepted('epoch-2'));
    }

    /** L2-VER-026: S8 is REVOKED at phase 9 at the revocation boundary with fresh evidence. */
    public function testStatementS8IsRevokedAtPhase9AtRevocationBoundary(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A', 'Record4R']);

        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S8'), $state, Stage2TestSupport::T);

        $this->assertSame(Verdict::REVOKED, $result->verdict());
        $this->assertSame(9, $result->phase());
    }

    /** L2-VER-027 (phase 7 replay side): byte-identical re-presentation of S7 after acceptance is VERIFIED, no replay penalty. */
    public function testByteIdenticalReplayOfAcceptedStatementIsVerified(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3'])
            ->withAcceptedStatement('epoch-1', 1, Stage2TestSupport::vector('S7')['canonical']);

        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S7'), $state, Stage2TestSupport::T);

        $this->assertTrue($result->isVerified());
        $this->assertSame(Verdict::VERIFIED, $result->verdict());
    }

    /** L2-VER-029 (statement generations judged only at phase 10): S9 generation 2 above the accepted statement maximum passes. */
    public function testStatementAboveAcceptedStatementMaximumPasses(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A']);

        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S9'), $state, 1769998300);

        $this->assertTrue($result->isVerified());
        $this->assertSame(Verdict::VERIFIED, $result->verdict());
    }

    /** L2-VER-029 (Record chain position at phase 7): Record below accepted maximum is STALE. */
    public function testRecordBelowAcceptedMaximumIsStaleAtPhase7(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);

        $result = Stage2TestSupport::verifier()->verifyRecord(Stage2TestSupport::wire('Record2'), $state, Stage2TestSupport::T);

        $this->assertSame(Verdict::STALE, $result->verdict());
        $this->assertSame(7, $result->phase());
    }

    /** L2-VER-029: same-generation byte-identical Record presentation is a replay, not freshness progress. */
    public function testByteIdenticalRecordReplayAtAcceptedMaximumIsAcceptedAsReplay(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);

        $result = Stage2TestSupport::verifier()->verifyRecord(Stage2TestSupport::wire('Record3'), $state, Stage2TestSupport::T);

        $this->assertTrue($result->isVerified());
        $this->assertStringContainsString('replay', $result->reason());
    }

    /** L2-VER-029: same-generation Record with a different payload is CONFLICT at phase 7 and is never accepted. */
    public function testSameGenerationRecordWithDifferentPayloadIsConflictAtPhase7(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);
        $wire = Stage2TestSupport::wireWithChanges('Record3', ['statementFloor' => 1], 3);

        $result = Stage2TestSupport::verifier()->verifyRecord($wire, $state, Stage2TestSupport::T);

        $this->assertSame(Verdict::CONFLICT, $result->verdict());
        $this->assertSame(7, $result->phase());
        $this->assertSame(3, $state->acceptedMaximum());
    }

    /** L2-VER-029 (tip side): a Record at contiguity G = acceptedMax + 1 that passes the chain walk is accepted. */
    public function testRecordAtChainTipContiguityIsAcceptedAtPhase7(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);

        $result = Stage2TestSupport::verifier()->verifyRecord(Stage2TestSupport::wire('Record4F'), $state, 1769999000);

        $this->assertTrue($result->isVerified());
    }

    /** L2-VER-029 (delegation): Record 5 at the tip is rejected by the chain walk (trail matching), UNVERIFIED at phase 5. */
    public function testRecord5AtTipIsRejectedViaChainWalkDelegation(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4']);

        $result = Stage2TestSupport::verifier()->verifyRecord(Stage2TestSupport::wire('Record5'), $state, 1770001500);

        $this->assertSame(Verdict::UNVERIFIED, $result->verdict());
        $this->assertSame(5, $result->phase());
    }

    /** L2-VER-030: generation equal to the statement floor (0) does not strictly exceed the floor; STALE at phase 10. */
    public function testGenerationAtFloorIsStaleAtPhase10(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);
        $wire = Stage2TestSupport::wireWithChanges('S7', ['generation' => 0], 3);

        $result = Stage2TestSupport::verifier()->verify($wire, $state, Stage2TestSupport::T);

        $this->assertSame(Verdict::STALE, $result->verdict());
        $this->assertSame(10, $result->phase());
        $this->assertStringContainsString('floor', $result->reason());
    }

    /** L2-VER-031: T beyond the statement expiresAt is EXPIRED at phase 10 with a fresh snapshot Record 4. */
    public function testTAfterStatementExpiryIsExpiredAtPhase10(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4']);
        $this->assertTrue($state->isSnapshotFreshAt(1770001300, Stage2TestSupport::W));

        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S7'), $state, 1770001300);

        $this->assertSame(Verdict::EXPIRED, $result->verdict());
        $this->assertSame(10, $result->phase());
    }

    /** §12.1, §15.2 phase 1 (structural parse): expiresAt <= issuedAt is INVALID at phase 1.
     * Not a pinned catalog case; L2-VER-032 is re-evaluation at T' (§13.5, §11.4, §15.2). */
    public function testStatementWindowNotIncreasingIsInvalidAtPhase1(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);
        $wire = Stage2TestSupport::wireWithChanges('S7', ['expiresAt' => 1769998300], 3);

        $result = Stage2TestSupport::verifier()->verify($wire, $state, Stage2TestSupport::T);

        $this->assertSame(Verdict::INVALID, $result->verdict());
        $this->assertSame(1, $result->phase());
    }

    /** L2-VER-034: retired signer key in the fresh snapshot at the current epoch is UNVERIFIED at phase 9. */
    public function testRetiredSignerAtCurrentEpochIsUnverifiedAtPhase9(): void
    {
        // accepted chain 0..2: current epoch at T = 1769997600 is epoch-1, snapshot Record 2,
        // key2 is retired in Record 2; an epoch-1 statement signed under retired key2 is
        // judged at phase 9, retirement is not compromise
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2']);
        $wire = Stage2TestSupport::wireWithChanges('S5', ['epochId' => 'epoch-1'], 2);

        $result = Stage2TestSupport::verifier()->verify($wire, $state, 1769997600);

        $this->assertSame(Verdict::UNVERIFIED, $result->verdict());
        $this->assertSame(9, $result->phase());
        $this->assertStringContainsString('retired', $result->reason());
        // L2-VER-034 contrast: the same retired key2 is judged at phase 9 only at the
        // current epoch; at the prior epoch epoch-0 (T = 1769997200, snapshot Record 1
        // where key2 is current) the epoch-0 statement passes phase 9
        $state01 = Stage2TestSupport::walkState(['Record0', 'Record1']);
        $ok = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S5'), $state01, 1769997200);
        $this->assertSame(Verdict::VERIFIED, $ok->verdict());
    }

    /** L2-VER-035: a compromised signer key at the current epoch is REVOKED at phase 9. */
    public function testCompromisedSignerIsRevokedAtPhase9(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A']);
        $wire = Stage2TestSupport::wireWithKeyChanges('Record4R', 'G8VbXumewVs', ['status' => 'compromised'], 3);
        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);
        $state2 = $acceptance->state();

        $this->assertTrue($acceptance->accepted());

        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S8'), $state2, Stage2TestSupport::T);

        $this->assertSame(Verdict::REVOKED, $result->verdict());
        $this->assertSame(9, $result->phase());
    }

    /** L2-VER-036 (revocation window unchanged at phase 9): a revoked key is judged by its established window. */
    public function testRevokedKeyWindowIsEstablishedWindowAtPhase9(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A', 'Record4R']);
        $key = $state->record(5)?->key('G8VbXumewVs');
        $this->assertNotNull($key);
        $this->assertSame('revoked', $key->status());
        $this->assertTrue($key->containsTime(1769998400));
    }

    /** L2-VER-037 support (statement side): the revoking Record keeps its revocation record. */
    public function testRevokingRecordIsFoundByRevokedFromAtPhase9(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A', 'Record4R']);
        $key = $state->record(5)?->key('G8VbXumewVs');
        $this->assertNotNull($key);
        $revoking = $state->record((int) $key->revokedFrom());
        $this->assertNotNull($revoking);
        $this->assertSame(1769998400, $revoking->issuedAt());
    }

    /** §13.4 anti-rollback: revocation is not applied to a statement issued before the revoking Record; the statement window governs at phase 10. */
    public function testRevocationNotAppliedBeforeRevokingRecordIssuedAt(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A', 'Record4R']);
        // S8 issuedAt moved below Record 4R issuedAt, re-signed under key3
        $wire = Stage2TestSupport::wireWithChanges('S8', ['issuedAt' => 1769998300], 3);

        $result = Stage2TestSupport::verifier()->verify($wire, $state, Stage2TestSupport::T);

        // phase 9: revoked, revoking Record issuedAt 1769998400 > statement issuedAt -> falls through
        // phase 10: window, epoch, floor, maximum all pass -> VERIFIED
        $this->assertTrue($result->isVerified());
        $this->assertSame(Verdict::VERIFIED, $result->verdict());
    }

    /** L2-VER-038: l1-bound mode with matching L1 observation is VERIFIED and records the mode. */
    public function testL1BoundModeWithMatchingLocationIsVerified(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);

        $result = Stage2TestSupport::verifier()->verify(
            Stage2TestSupport::wire('S7'),
            $state,
            Stage2TestSupport::T,
            VerificationMode::L1_BOUND,
            Stage2TestSupport::DESCRIPTION_LOCATION,
        );

        $this->assertTrue($result->isVerified());
        $this->assertSame(Verdict::VERIFIED, $result->verdict());
        $this->assertSame(VerificationMode::L1_BOUND, $result->mode());
    }

    /** L2-VER-040: l1-bound mode with no L1 observation is UNVERIFIED at phase 11, never MISMATCH. */
    public function testL1BoundModeWithoutObservationIsUnverifiedAtPhase11(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);

        $result = Stage2TestSupport::verifier()->verify(
            Stage2TestSupport::wire('S7'),
            $state,
            Stage2TestSupport::T,
            VerificationMode::L1_BOUND,
            null,
        );

        $this->assertSame(Verdict::UNVERIFIED, $result->verdict());
        $this->assertSame(11, $result->phase());
    }

    /** L2-VER-039: an observed L1 Location differing byte-wise is MISMATCH at phase 11 (exact byte compare). */
    public function testObservedLocationMismatchIsMismatchAtPhase11(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);

        $result = Stage2TestSupport::verifier()->verify(
            Stage2TestSupport::wire('S7'),
            $state,
            Stage2TestSupport::T,
            VerificationMode::L1_BOUND,
            'https://sensors.example/thermal/42/ar-xml-2',
        );

        $this->assertSame(Verdict::MISMATCH, $result->verdict());
        $this->assertSame(11, $result->phase());
    }

    /** L2-VER-039: byte compare is exact and case-sensitive; an uppercase scheme prefix is MISMATCH. */
    public function testObservedLocationCaseDifferenceIsMismatchAtPhase11(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);

        $result = Stage2TestSupport::verifier()->verify(
            Stage2TestSupport::wire('S7'),
            $state,
            Stage2TestSupport::T,
            VerificationMode::L1_BOUND,
            'HTTPS://sensors.example/thermal/42/ar-xml',
        );

        $this->assertSame(Verdict::MISMATCH, $result->verdict());
        $this->assertSame(11, $result->phase());
    }

    /** L2-VER-041 / L2-VER-042: results carry the Verdict enum and the failed phase; the result records which phase failed. */
    public function testResultsRecordVerdictEnumAndFailedPhase(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);

        $anchor = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wireWithChanges('S7', ['anchorId' => '550e8400-e29b-41d4-a716-446655440001'], 3), $state, Stage2TestSupport::T);
        $algo = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wireWithChanges('S7', ['algorithm' => 'ES256'], 3), $state, Stage2TestSupport::T);
        $ok = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S7'), $state, Stage2TestSupport::T);

        $this->assertInstanceOf(Verdict::class, $anchor->verdict());
        $this->assertSame(2, $anchor->failedPhase());
        $this->assertSame(3, $algo->failedPhase());
        $this->assertNull($ok->failedPhase());
    }

    /** L2-VER-042: an unknown verificationMode is INVALID at phase 1. */
    public function testUnknownVerificationModeIsInvalidAtPhase1(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);

        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S7'), $state, Stage2TestSupport::T, 'trust-bound');

        $this->assertSame(Verdict::INVALID, $result->verdict());
        $this->assertSame(1, $result->phase());
    }

    /** L2-VER-044: offline evaluation (no fresh snapshot at T) is UNVERIFIED, never REVOKED. */
    public function testOfflineEvaluationIsUnverifiedNeverRevoked(): void
    {
        // state-s8 with stale snapshot at T = 1770001800 (Record 4R delta = 3400 > W)
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A', 'Record4R']);

        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S8'), $state, 1770001800);

        $this->assertSame(Verdict::UNVERIFIED, $result->verdict());
        $this->assertSame(6, $result->phase());
    }

    /** L2-VER-043: modes recorded in the result; statement-only is recorded for a plain verification. */
    public function testModeIsRecordedInResultForBothModes(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);

        $statementOnly = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S7'), $state, Stage2TestSupport::T);
        $l1Bound = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S7'), $state, Stage2TestSupport::T, VerificationMode::L1_BOUND, Stage2TestSupport::DESCRIPTION_LOCATION);

        $this->assertSame(VerificationMode::STATEMENT_ONLY, $statementOnly->mode());
        $this->assertSame(VerificationMode::L1_BOUND, $l1Bound->mode());
    }

    /** entityId mismatch against the Canonical Entity Identity is UNKNOWN_AUTHORITY at phase 9 (current epoch). */
    public function testStatementEntityIdMismatchIsUnknownAuthorityAtPhase9(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);
        $wire = Stage2TestSupport::wireWithChanges('S7', ['entityId' => 'urn:relink:entity:other-1'], 3);

        $result = Stage2TestSupport::verifier()->verify($wire, $state, Stage2TestSupport::T);

        $this->assertSame(Verdict::UNKNOWN_AUTHORITY, $result->verdict());
        $this->assertSame(9, $result->phase());
    }

    /** Signature failure under the snapshot signer publicKey is INVALID at phase 8. */
    public function testSignatureFailureUnderSnapshotSignerKeyIsInvalidAtPhase8(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);
        // S7 re-signed under key5: signature does not verify under the snapshot key3 publicKey
        $wire = Stage2TestSupport::wireWithChanges('S7', [], 5);

        $result = Stage2TestSupport::verifier()->verify($wire, $state, Stage2TestSupport::T);

        $this->assertSame(Verdict::INVALID, $result->verdict());
        $this->assertSame(8, $result->phase());
    }

    /** Result is a typed value object; never a boolean. */
    public function testResultIsTypedNotBoolean(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3']);
        $ok = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S7'), $state, Stage2TestSupport::T);

        $this->assertInstanceOf(VerificationResult::class, $ok);
        $this->assertInstanceOf(Verdict::class, $ok->verdict());
    }
}
