<?php
// tests/L2/Stage2ReplayMatrixTest.php
// L2-VER-047：Catalog §10 pinned S9 family と four pinned verifier states（state-base,
// state-after-s9, state-after-s9v1, state-s8）で replay / STALE / CONFLICT / REVOKED の
// matrix を固定順序で検証する。T = 1769998300 / 1769998400、W = 600。

declare(strict_types=1);

namespace Relink\Resolver\Tests\L2;

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Domain\L2\Verdict;
use Relink\Resolver\Domain\L2\VerifierState;
use Relink\Resolver\Adapters\SodiumEd25519Verifier;
use L2Fixtures;

require_once __DIR__ . '/Stage2TestSupport.php';

final class Stage2ReplayMatrixTest extends TestCase
{
    private const T_BASE = 1769998300;

    /** state-base: accepted chain prefix 0..3, 4A; current epoch epoch-1; key4 current in
     * Record 4A window 1769998300..1770001900; no accepted statement; T = 1769998300; W = 600. */
    private function stateBase(): VerifierState
    {
        return Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A']);
    }

    /** state-after-s9: as state-base, plus accepted statement S9, generation 2. */
    private function stateAfterS9(): VerifierState
    {
        return $this->stateBase()->withAcceptedStatement('epoch-1', 2, Stage2TestSupport::vector('S9')['canonical']);
    }

    /** state-after-s9v1: as state-base, plus accepted statement S9v1, generation 1. */
    private function stateAfterS9v1(): VerifierState
    {
        return $this->stateBase()->withAcceptedStatement('epoch-1', 1, Stage2TestSupport::vector('S9v1')['canonical']);
    }

    /** §11.3 round-3 review P1-1 regression: the accepted statement maximum is per
     * (epochId, generation). A high generation accepted in epoch-0 must NOT make a valid
     * low-generation statement in the current post-transfer epoch STALE. */
    public function testEpochScopedStatementMaximumDoesNotCrossTransfers(): void
    {
        // Record 2 introduces epoch-1 via Trail2, so the epoch-0 prefix is 0..1.
        $state = Stage2TestSupport::walkState(['Record0', 'Record1']);
        $this->assertSame('epoch-0', $state->chainTipEpoch());

        // Simulate the acceptance of a high-generation epoch-0 statement (generation 4 >
        // S9v1's generation 1); the statement payload itself is not relevant to phase 10.
        $state = $state->withAcceptedStatement('epoch-0', 4, Stage2TestSupport::vector('S7')['canonical']);

        foreach (['Record2', 'Record3', 'Record4A'] as $id) {
            $acceptance = Stage2TestSupport::walker()->accept($state, Stage2TestSupport::wire($id));
            $this->assertTrue($acceptance->accepted(), $id . ' must be accepted: ' . $acceptance->reason());
            $state = $acceptance->state();
        }

        $this->assertSame('epoch-1', $state->currentEpochAt(self::T_BASE));
        $this->assertSame(4, $state->statementMaximum('epoch-0'));
        $this->assertSame(-1, $state->statementMaximum('epoch-1'));

        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S9v1'), $state, self::T_BASE);

        $this->assertSame(Verdict::VERIFIED, $result->verdict(), 'epoch-1 generation 1 is above the epoch-1 maximum (-1), not below epoch-0\'s 4 (§11.3)');
    }

    /** state-s8: accepted chain prefix 0..3, 4A, 4R; T = 1769998400; key3 revoked from Record 4R. */
    private function stateS8(): VerifierState
    {
        return Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A', 'Record4R']);
    }

    /** s9-generation-2-base: S9 (generation 2, key4) at state-base, T = 1769998300 is VERIFIED. */
    public function testS9Generation2AtStateBaseIsVerified(): void
    {
        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S9'), $this->stateBase(), self::T_BASE);

        $this->assertTrue($result->isVerified());
        $this->assertSame(Verdict::VERIFIED, $result->verdict());
    }

    /** s9-generation-2-byte-identical-replay: replay of the accepted S9 at the statement
     * maximum is byte-identical -> VERIFIED with no replay penalty, and the statement
     * maximum does not move. */
    public function testByteIdenticalReplayOfS9AfterAcceptanceIsVerifiedWithoutPenalty(): void
    {
        $state = $this->stateAfterS9();
        $this->assertSame(2, $state->statementMaximum('epoch-1'));

        $first = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S9'), $state, self::T_BASE);
        $replay = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S9'), $state, self::T_BASE);

        $this->assertSame(Verdict::VERIFIED, $first->verdict());
        $this->assertSame(Verdict::VERIFIED, $replay->verdict());
        $this->assertSame(2, $state->statementMaximum('epoch-1'));
        $this->assertTrue($state->isStatementByteIdentical('epoch-1', 2, Stage2TestSupport::vector('S9')['canonical']));
    }

    /** s9-generation-1-stale-variant: S9v1 (generation 1) after S9 (generation 2) is
     * accepted is STALE at phase 10 (generation below the accepted statement maximum),
     * never CONFLICT, never VERIFIED. */
    public function testS9v1IsStaleAfterS9IsAccepted(): void
    {
        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S9v1'), $this->stateAfterS9(), self::T_BASE);

        $this->assertSame(Verdict::STALE, $result->verdict());
        $this->assertSame(10, $result->phase());
    }

    /** s9-generation-1-equivocation: S9v2 (generation 1, different descriptionLocation)
     * against accepted S9v1 (generation 1) is CONFLICT at phase 10; fail-closed, no
     * heuristic choice between payloads. */
    public function testS9v2EquivocationAgainstAcceptedS9v1IsConflict(): void
    {
        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S9v2'), $this->stateAfterS9v1(), self::T_BASE);

        $this->assertSame(Verdict::CONFLICT, $result->verdict());
        $this->assertSame(10, $result->phase());
    }

    /** L2-VER-028 complement: S9v2 against accepted S9 (generation 2) is below the
     * maximum: STALE at phase 10, not CONFLICT. */
    public function testS9v2BelowAcceptedS9MaximumIsStaleNotConflict(): void
    {
        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S9v2'), $this->stateAfterS9(), self::T_BASE);

        $this->assertSame(Verdict::STALE, $result->verdict());
        $this->assertSame(10, $result->phase());
    }

    /** Byte-identical replay of S9v1 in state-after-s9v1 is VERIFIED (no penalty). */
    public function testByteIdenticalReplayOfS9v1IsVerified(): void
    {
        $result = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S9v1'), $this->stateAfterS9v1(), self::T_BASE);

        $this->assertTrue($result->isVerified());
        $this->assertSame(Verdict::VERIFIED, $result->verdict());
    }

    /** s8-replay-byte-identical: a byte-identical re-presentation of S8 in state-s8 is
     * REVOKED at phase 9, never VERIFIED; an evaluation at the revocation boundary never
     * accepts the statement, so the replay never becomes an accepted statement. */
    public function testS8ReplayAtStateS8IsRevokedNeverVerified(): void
    {
        $state = $this->stateS8();
        $this->assertSame(-1, $state->statementMaximum('epoch-1'));

        $first = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S8'), $state, Stage2TestSupport::T);
        $replay = Stage2TestSupport::verifier()->verify(Stage2TestSupport::wire('S8'), $state, Stage2TestSupport::T);

        $this->assertSame(Verdict::REVOKED, $first->verdict());
        $this->assertSame(9, $first->phase());
        $this->assertSame(Verdict::REVOKED, $replay->verdict());
        $this->assertSame(9, $replay->phase());
        // replay of a REVOKED presentation never moves the statement maximum
        $this->assertSame(-1, $state->statementMaximum('epoch-1'));
    }

    /** The pinned S9 fixtures bind to the catalog-pinned canonical payloads and key4. */
    public function testS9FamilyFixturesArePinnedToCatalogPayloads(): void
    {
        $key4 = L2Fixtures::keys()['key4'];
        $signatureVerifier = new SodiumEd25519Verifier();

        foreach (['S9', 'S9v1', 'S9v2'] as $id) {
            $vector = Stage2TestSupport::vector($id);
            $this->assertStringContainsString('"keyId":"ECYh7yJFAn8"', $vector['canonical']);
            $this->assertStringContainsString('"epochId":"epoch-1"', $vector['canonical']);
            $this->assertStringContainsString('"issuedAt":1769998300', $vector['canonical']);
            $this->assertStringContainsString('"expiresAt":1770001200', $vector['canonical']);
            // signatures verify under key4 material for all three fixtures
            $this->assertSame(
                Verdict::VERIFIED,
                $signatureVerifier->verify('ed25519', $key4['publicKey'], $vector['signature'], $vector['canonical']),
                'fixture signature ' . $id . ' must verify under key4',
            );
        }

        // generation contract: S9 carries generation 2, S9v1/S9v2 carry generation 1
        $this->assertStringContainsString('"generation":2', Stage2TestSupport::vector('S9')['canonical']);
        $this->assertStringContainsString('"generation":1', Stage2TestSupport::vector('S9v1')['canonical']);
        $this->assertStringContainsString('"generation":1', Stage2TestSupport::vector('S9v2')['canonical']);
        $this->assertStringContainsString('"descriptionLocation":"https://sensors.example/thermal/42/ar-xml-2"', Stage2TestSupport::vector('S9v2')['canonical']);
        $this->assertStringContainsString('"descriptionLocation":"https://sensors.example/thermal/42/ar-xml"', Stage2TestSupport::vector('S9v1')['canonical']);
    }

    /**
     * Mochi P2-2: a byte-identical replay at the accepted tip must pass phase 8 signature
     * verification under the signer publicKey recorded in the accepted chain. The canonical
     * Record 4A payload bytes presented with a signature produced by key1 are INVALID at
     * phase 8, not a verified replay.
     */
    public function testByteIdenticalReplayRecordAtTipSignedUnderForeignKeyIsInvalidAtPhase8(): void
    {
        $state = Stage2TestSupport::walkState(['Record0', 'Record1', 'Record2', 'Record3', 'Record4A']);

        // Record 4A canonical payload bytes signed by key1 presented as a replay
        $forged = Stage2TestSupport::wireSignedBy('Record4A', 1);
        $result = Stage2TestSupport::verifier()->verifyRecord($forged, $state, 1769998300);

        $this->assertFalse($result->isVerified());
        $this->assertSame(Verdict::INVALID, $result->verdict());
        $this->assertSame(8, $result->failedPhase());
    }
}
