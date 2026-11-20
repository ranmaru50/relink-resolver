<?php
// src/Application/L2ResolverService.php
// 公開 L2 面（§6.1 GET /relink/{uuid}?l=2 = L2 Trust Statement、§6.3 GET /relink/{uuid}/authority
// = Record series）のアプリケーションサービス。Thin adapter（public/index.php）が superglobal を
// 解析してハンドラに委譲する。両ハンドラは分離している（§6.1/§6.3 routing separation）。
// 順序（§6.1 fail-closed、両ハンドラ共通）: malformed UUID→400、メソッド→405、
// route-parameter 検査→400/501、ライフサイクル→404/410、Trust Anchor Binding→501。
//
// §6.1 statement route: 公開 statement は T で受理されている current epoch（§11.4 snapshot が
// 確立する epoch）内の最高 generation の署名済み wire を verbatim で返し、§15.2 phases（chain
// walk + verify）で VERIFIED になったもののみ公開する（P1-1: generation は epoch 内列、
// cross-epoch の global 選択は行わない）。ACTIVE anchor に statement が無い、chain walk が
// 停まる、verdict が非 VERIFIED（EXPIRED/STALE/CONFLICT 等）は §6.1 を規定どおり答えられない
// 内部失敗行として 500（no-store）で fail-closed。SUSPENDED/RETIRED anchor の statement は
// 公開してはならない（§6.1 前置き）。
//
// §6.3 ギャップ設計判断: verifier は 0..G を全て必要とし、ギャップは phase 5 で UNVERIFIED
// （§15.2）。公開エンドポイントギャップを含む集合を「完全」として提供してはならないため、内部
// 検出された存储ギャップは §6.1 の internal failure 行に従い 500（no-store）で fail-closed 応答
// する（500/503 のマッピングは §6.1 に規定）。
//
// §14.2 Cache-Control 計算: 200 では public, max-age = min(deployment bound, 対象の expiresAt - now)。
// Record series は expiresAt(G)、statement は statement 自身の expiresAt で throttle される
// （§14.2 statement 行）。deployment bound は env 設定（bootstrap.php の RELINK_L2_MAX_AGE、
// composition root で解決）からこのサービスに明示値として渡す（AGENTS.md §3）。0 クランプ:
// キャッシュ寿命は対象の有効期限を越えない（L2-SRV-005 immediate-freshness）。400/404/500/501/503
// は no-store（§14.2 SHOULD）、410 は MAY-cache、既存の参照慣習 public, max-age=300 を適用する。

declare(strict_types=1);

namespace Relink\Resolver\Application;

use InvalidArgumentException;
use Relink\Resolver\Domain\AnchorUuid;
use Relink\Resolver\Domain\L2\L2ResolutionResult;
use Relink\Resolver\Domain\L2\RecordChainWalker;
use Relink\Resolver\Domain\L2\StatementPayload;
use Relink\Resolver\Domain\L2\StatementVerifier;
use Relink\Resolver\Domain\L2\VerificationMode;
use Relink\Resolver\Domain\L2\WireStatementException;
use Relink\Resolver\Domain\L2\PayloadStructureException;
use Relink\Resolver\Domain\L2\Record;
use Relink\Resolver\Domain\LifecycleState;
use Relink\Resolver\Ports\L2RecordRepository;
use Relink\Resolver\Ports\L2StatementRepository;
use Relink\Resolver\Ports\ResolverRepository;
use Relink\Resolver\Ports\SignatureVerifier;
use Relink\Resolver\Ports\TrustAnchorBindingRepository;
use Throwable;

final class L2ResolverService
{
    public function __construct(
        private readonly ResolverRepository $resolverRepository,
        private readonly TrustAnchorBindingRepository $bindingRepository,
        private readonly L2RecordRepository $recordRepository,
        private readonly L2StatementRepository $statementRepository,
        private readonly SignatureVerifier $signatureVerifier,
        private readonly int $deploymentMaxAge,
        private readonly int $maxSnapshotAge,
    ) {
        if ($deploymentMaxAge < 0) {
            throw new InvalidArgumentException('deployment cache bound must be non-negative');
        }
        if ($maxSnapshotAge < 0) {
            throw new InvalidArgumentException('maxSnapshotAge (W, §11.4) must be non-negative');
        }
    }

    /**
     * §6.3 GET /{prefix}/{uuid}/authority: the Record series handler.
     * §6.4: the public L2 surface is GET-only, read-only, anonymous. No mutation endpoint
     * exists here (§6.4/§16: Record and Binding submission is administrative/policy only).
     *
     * @param array<string, mixed> $query
     */
    public function authority(string $method, string $uuid, array $query, int $now): L2ResolutionResult
    {
        // §6.1: malformed UUID → 400, before any lookup.
        try {
            $anchor = new AnchorUuid($uuid);
        } catch (InvalidArgumentException) {
            return L2ResolutionResult::error(400);
        }

        // §6.1: the method check precedes every lookup; 405 declares Allow: GET (§6.4).
        if (strtoupper($method) !== 'GET') {
            return new L2ResolutionResult(405, ['Allow' => 'GET', 'Cache-Control' => 'no-store']);
        }

        // BLOCKING-1 routing separation: the /authority path is the Record series (§6.3) and
        // carries no l parameter; l=2 is the statement route (§6.1). An l on this path is an
        // unsupported combination, never accepted as L2/L1/L3 semantics (§6.5), 501 no-store.
        if (array_key_exists('l', $query)) {
            return new L2ResolutionResult(501, ['Cache-Control' => 'no-store']);
        }

        // §6.2: p on an L2-supporting implementation answers 400. The reference deployment
        // rejects it before any lookup at all, which is strictly fail-closed.
        if (array_key_exists('p', $query)) {
            return L2ResolutionResult::error(400);
        }

        // §6.1 lifecycle statuses, before any record material is read.
        try {
            $lifecycle = $this->resolverRepository->find($anchor);
        } catch (Throwable) {
            return L2ResolutionResult::error(503);
        }
        if ($lifecycle === null || $lifecycle->state === LifecycleState::SUSPENDED) {
            return L2ResolutionResult::error(404);
        }
        if ($lifecycle->state === LifecycleState::RETIRED) {
            return L2ResolutionResult::error(410);
        }

        // §9.3: L2 support requires a Trust Anchor Binding delivered via the policy channel.
        // Documented deviation: §6.1 adds no MUST-level L2 status for Core-only deployments,
        // so an anchor without a binding keeps the reference deployment's existing Core 501
        // convention, and Record storage is never read for it (fail-closed).
        try {
            $binding = $this->bindingRepository->findBinding($anchor->value);
        } catch (Throwable) {
            return L2ResolutionResult::error(503);
        }
        if ($binding === null) {
            return new L2ResolutionResult(501, ['Cache-Control' => 'no-store']);
        }

        // §6.3: the served array is exactly the complete, strictly ascending 0..G prefix of
        // verbatim wire payload strings. If any generation is missing between 0 and the max,
        // the L2 verifier reports UNVERIFIED (§15.2 phase 5), so this endpoint MUST NOT serve
        // the gapful set as complete. A gap detected inside our own storage is an internal
        // failure per the §6.1 internal-failure row: 500 with no-store, serving no Records.
        try {
            $wires = $this->recordRepository->contiguousRecordWires($anchor->value);
        } catch (Throwable) {
            return L2ResolutionResult::error(503);
        }
        if ($wires === null) {
            // §6.3 mapping decision (gap → internal failure 500, documented in code comments):
            // serving 0,1,2,4,5,6 as {"records":[...]} would let clients read the gapful set as
            // complete transport; the verifier side would report UNVERIFIED. Fail closed.
            return L2ResolutionResult::error(500);
        }

        // P1-2 / §6.3 acceptance guarantee: numbering contiguity is not chain acceptance. The
        // stored prefix is run through the chain walk (§9.6) against the Trust Anchor Binding
        // before any Record is served. A wire that fails the walk (trail-window cap,
        // revokedFrom widening, signer not resolvable, keyId recomputation, …) means the
        // stored set is not the accepted chain, so nothing is served: §6.1 internal-failure
        // row, 500 no-store, same judgment basis as the storage gap above. The walk is
        // read-only; the public GET performs no mutation and no state is written (§6.4).
        $walker = new RecordChainWalker($this->signatureVerifier);
        $state = $walker->initialState($binding);
        foreach ($wires as $wire) {
            $acceptance = $walker->accept($state, $wire);
            if (!$acceptance->accepted()) {
                return L2ResolutionResult::error(500);
            }
            $state = $acceptance->state();
        }

        // P2-1 (PR #31 review round 2, §6.3/§14.2): the TTL is taken from the tip Record of
        // the validated wire set that is served, NOT from a separately re-queried max
        // generation. A concurrent admin append landing between the wire read and a TTL query
        // would serve body 0..G while Cache-Control claims the lifetime of Record G+1, a
        // Record absent from the body. The body and the TTL must derive from the same set:
        // the tip wire of the accepted list (the walk parsed every wire above, so the tip
        // re-parses without failure; a storage integrity surprise maps to the internal row).
        $tipWire = $wires[count($wires) - 1];
        try {
            $expiresAtG = Record::fromWire($tipWire)->expiresAt();
        } catch (Throwable) {
            return L2ResolutionResult::error(500);
        }

        // §14.2 canonical phrase: public, max-age = min(deployment bound, expiresAt(G) - now),
        // RFC 9111 directive form; clamped non-negative so the cache never outlives Record G's
        // expiry (L2-SRV-005 immediate-freshness: freshness at response time, §14.2).
        $maxAge = max(0, min($this->deploymentMaxAge, $expiresAtG - $now));

        // BLOCKING-2 / §6.3 unsigned transport framing: {"records":[Record_0..Record_G]} where
        // each element IS the verbatim signed {payload, signature} Record wire object. The wire
        // strings are joined raw (implode); json_encode of the array would double-encode each
        // Record into a JSON string element, which §6.3 forbids (the non-contiguous /
        // string-encoded array "MUST NOT be accepted" by verifiers).
        $body = '{"records":[' . implode(',', $wires) . ']}';

        return new L2ResolutionResult(200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'public, max-age=' . $maxAge,
        ], $body);
    }

    /**
     * §6.1 GET /{prefix}/{uuid}?l=2: the L2 Trust Statement handler.
     * The public statement is the signed wire of the accepted latest generation, served
     * verbatim, only when §15.2 verification reports VERIFIED at now (T). SUSPENDED/RETIRED
     * anchors are gated before any material is read (§6.1). Non-VERIFIED verdicts
     * (EXPIRED, STALE, CONFLICT, chain stop, cross-epoch replay) and missing statement
     * material are internal failures: 500 no-store, fail-closed, never served publicly.
     *
     * @param array<string, mixed> $query
     */
    public function statement(string $method, string $uuid, array $query, int $now): L2ResolutionResult
    {
        // §6.1: malformed UUID → 400, before any lookup.
        try {
            $anchor = new AnchorUuid($uuid);
        } catch (InvalidArgumentException) {
            return L2ResolutionResult::error(400);
        }

        // §6.1: the method check precedes every lookup; 405 declares Allow: GET (§6.4).
        if (strtoupper($method) !== 'GET') {
            return new L2ResolutionResult(405, ['Allow' => 'GET', 'Cache-Control' => 'no-store']);
        }

        // BLOCKING-1 routing separation: the statement route is invoked for l present; only
        // l=2 is accepted by this L2 0.1 implementation (§6.5). Unsupported l values are never
        // treated as L2, L1, or L3 semantics; the reference deployment mirrors its existing
        // Core 501 convention.
        if (($query['l'] ?? null) !== '2') {
            return new L2ResolutionResult(501, ['Cache-Control' => 'no-store']);
        }

        // §6.2: p on an L2-supporting implementation answers 400, before any lookup.
        if (array_key_exists('p', $query)) {
            return L2ResolutionResult::error(400);
        }

        // §6.1 lifecycle statuses, before any L2 material is read.
        try {
            $lifecycle = $this->resolverRepository->find($anchor);
        } catch (Throwable) {
            return L2ResolutionResult::error(503);
        }
        if ($lifecycle === null || $lifecycle->state === LifecycleState::SUSPENDED) {
            return L2ResolutionResult::error(404);
        }
        if ($lifecycle->state === LifecycleState::RETIRED) {
            return L2ResolutionResult::error(410);
        }

        // §9.3: the statement route requires the Trust Anchor Binding as well (same documented
        // 501 convention as the Record series route, fail-closed before storage reads).
        try {
            $binding = $this->bindingRepository->findBinding($anchor->value);
        } catch (Throwable) {
            return L2ResolutionResult::error(503);
        }
        if ($binding === null) {
            return new L2ResolutionResult(501, ['Cache-Control' => 'no-store']);
        }

        // P1-1 (PR #31 review round 2, §7.2/§11.3/§11.4): generations are intra-epoch
        // sequences. The public selection MUST run within the epoch accepted as current at T
        // (the snapshot Record at T establishes the epoch; Records issuedAt > T never update
        // the epoch, floor, and key snapshot at T), so the epoch-scoped statement query is
        // taken from currentEpochAt(T), never from the global store order. The Record chain
        // is read and walked first.

        // §6.3/§15.2 phases 5-6: the statement is verified against the accepted Record chain.
        // A stored gap is UNVERIFIED at phase 5 (never served); a chain walk stop on the
        // stored prefix (e.g. trail-matching failure, L2-VER-016) is fail-closed as well.
        try {
            $recordWires = $this->recordRepository->contiguousRecordWires($anchor->value);
        } catch (Throwable) {
            return L2ResolutionResult::error(503);
        }
        if ($recordWires === null) {
            return L2ResolutionResult::error(500);
        }

        $walker = new RecordChainWalker($this->signatureVerifier);
        $state = $walker->initialState($binding);
        foreach ($recordWires as $wire) {
            $acceptance = $walker->accept($state, $wire);
            if (!$acceptance->accepted()) {
                return L2ResolutionResult::error(500);
            }
            $state = $acceptance->state();
        }

        $epochAtT = $state->currentEpochAt($now);
        if ($epochAtT === null) {
            // No Record establishes the current epoch at T (T is before the Record0 anchor
            // record): the §6.1 internal-failure row applies, fail-closed 500 no-store. The
            // statement store is never queried unscoped for a null epoch.
            return L2ResolutionResult::error(500);
        }

        try {
            $statementWires = $this->statementRepository->statementWires($anchor->value, $epochAtT);
        } catch (Throwable) {
            return L2ResolutionResult::error(503);
        }

        // §6.1 active → 200 + statement. With no statement material for the current epoch the
        // deployment cannot answer §6.1 as specified (no §6.1 status exists for "ACTIVE
        // anchor, no statement"); this is a judgment basis (DEFENSIBLE-BUT-UNPINNED, same
        // class as the gap→500 decision above): the §6.1 internal-failure row (500/503) is
        // the closest pinned mapping, and serving nothing is fail-closed.
        if ($statementWires === []) {
            return L2ResolutionResult::error(500);
        }

        // Parse every wire in the epoch scope; a stored wire that no longer parses is a
        // storage integrity failure (the store keeps verbatim wires accepted via the policy
        // channel), mapped to the §6.1 internal-failure row.
        /** @var list<array{wire: string, payload: StatementPayload}> $pairs */
        $pairs = [];
        foreach ($statementWires as $wire) {
            try {
                $pairs[] = ['wire' => $wire, 'payload' => StatementPayload::fromWire($wire)];
            } catch (WireStatementException | PayloadStructureException) {
                return L2ResolutionResult::error(500);
            }
        }

        // Accepted statement of the current epoch: the highest generation inside the epoch
        // scope. Byte-identical duplicates at that generation are the same statement (§11.3
        // permits byte-identical re-acceptance within the validity window); distinct payloads
        // at the same epoch and generation are equivocation → CONFLICT, never served.
        $maxGeneration = max(array_map(static fn (array $pair): int => $pair['payload']->generation(), $pairs));
        /** @var list<array{wire: string, payload: StatementPayload}> $atMax */
        $atMax = array_values(array_filter($pairs, static fn (array $pair): bool => $pair['payload']->generation() === $maxGeneration));
        $distinctCanonical = array_values(array_unique(array_map(static fn (array $pair): string => $pair['payload']->canonicalPayloadOctets(), $atMax)));
        if (count($distinctCanonical) > 1) {
            // §11.3: same epochId + generation with different payloads is CONFLICT; the §6.1
            // internal-failure row (500/503) is the judgment basis, nothing is served.
            return L2ResolutionResult::error(500);
        }

        $selectedPair = $atMax[0];

        // §15.2 full verification at T = now.
        $verifier = new StatementVerifier($this->signatureVerifier, $this->maxSnapshotAge);
        $result = $verifier->verify($selectedPair['wire'], $state, $now, VerificationMode::STATEMENT_ONLY);

        // §6.1 fail-closed: only VERIFIED statements are served publicly. EXPIRED, STALE,
        // CONFLICT, REVOKED, UNVERIFIED, INVALID, ANCHOR_MISMATCH verdicts map to the §6.1
        // internal-failure row (500/503) as judgment basis: §6.1 pins active→200 + statement
        // and forbids serving SUSPENDED/RETIRED statement content publicly; serving a
        // non-VERIFIED statement would breach §11.3/§12.3/§14.2 freshness promises, so nothing
        // is served and the response is 500 no-store.
        if (!$result->isVerified()) {
            return L2ResolutionResult::error(500);
        }

        // P1-2 (PR #31 review round 4, §7.2 lines 298-299): the served statement's
        // lifecycleStatus MUST equal the record's registry state. The signed field never
        // overrides the registry and the body is never rewritten: a mismatch is an internal
        // inconsistency, fail-closed as the §6.1 internal-failure row (500 no-store). The
        // registry wire form is the enum's lowercased manifest value (§7.2 §14.2).
        if ($selectedPair['payload']->lifecycleStatus() !== $lifecycle->state->manifestValue()) {
            return L2ResolutionResult::error(500);
        }

        // §14.2 statement row: public, max-age = min(deployment bound, expiresAt(statement) - now),
        // never exceeding the remaining validity of the served statement.
        $maxAge = max(0, min($this->deploymentMaxAge, $selectedPair['payload']->expiresAt() - $now));

        return new L2ResolutionResult(200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'public, max-age=' . $maxAge,
        ], $selectedPair['wire']);
    }
}
