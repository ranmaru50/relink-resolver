<?php
// src/Application/L2ResolverService.php
// 公開 L2 面（GET /relink/{uuid}?l=2）のアプリケーションサービス（§6）。
// Thin adapter（public/index.php）が superglobal を解析してここに委譲する。
// 順序（§6.1 fail-closed）: malformed UUID→400、メソッド→405、l/p→400/501、
// ライフサイクル→404/410、Trust Anchor Binding→501、Record 0..G。
//
// §6.3 ギャップ設計判断: verifier は 0..G を全て必要とし、ギャップは phase 5 で
// UNVERIFIED（§15.2）。公開エンドポイントギャップを含む集合を「完全」として提供しては
// ならないため、内部検出された存储ギャップは §6.1 の internal failure 行に従い 500
// （no-store）で fail-closed 応答する（500/503 のマッピングは §6.1 に規定）。
//
// §14.2 Cache-Control 計算: public, max-age = min(deployment bound, expiresAt(G) - now)。
// deployment bound は env 設定（bootstrap.php の RELINK_L2_MAX_AGE、composition root で
// 解決）からこのサービスに明示値として渡す（AGENTS.md §3: use case は明示値を受ける）。
// 0 クランプ: キャッシュ寿命は Record G の expiresAt を越えない（L2-SRV-005
// immediate-freshness）。400/404/500/501/503 は no-store（§14.2 SHOULD）、410 は
// MAY-cache、既存の参照慣習 public, max-age=300 を適用する。

declare(strict_types=1);

namespace Relink\Resolver\Application;

use InvalidArgumentException;
use Relink\Resolver\Domain\AnchorUuid;
use Relink\Resolver\Domain\L2\L2ResolutionResult;
use Relink\Resolver\Domain\LifecycleState;
use Relink\Resolver\Ports\L2RecordRepository;
use Relink\Resolver\Ports\ResolverRepository;
use Relink\Resolver\Ports\TrustAnchorBindingRepository;
use Throwable;

final class L2ResolverService
{
    public function __construct(
        private readonly ResolverRepository $resolverRepository,
        private readonly TrustAnchorBindingRepository $bindingRepository,
        private readonly L2RecordRepository $recordRepository,
        private readonly int $deploymentMaxAge,
    ) {
        if ($deploymentMaxAge < 0) {
            throw new InvalidArgumentException('deployment cache bound must be non-negative');
        }
    }

    /**
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

        // §6.5/L2-SRV-004 forward compatibility: only l=2 is accepted by this L2 0.1
        // implementation. Unsupported l values are never treated as L2, L1, or L3
        // semantics; the reference deployment mirrors its existing Core 501 convention.
        if (($query['l'] ?? null) !== '2') {
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

        try {
            $expiresAtG = $this->recordRepository->expiresAtOfMaxGeneration($anchor->value);
        } catch (Throwable) {
            return L2ResolutionResult::error(503);
        }
        if ($expiresAtG === null) {
            return L2ResolutionResult::error(500);
        }

        // §14.2 canonical phrase: public, max-age = min(deployment bound, expiresAt(G) - now),
        // RFC 9111 directive form; clamped non-negative so the cache never outlives Record G's
        // expiry (L2-SRV-005 immediate-freshness: freshness at response time, §14.2).
        $maxAge = max(0, min($this->deploymentMaxAge, $expiresAtG - $now));

        // §6.3 unsigned transport framing: {"records":[Record_0..Record_G]} with each Record as
        // its verbatim canonical wire payload string. json_encode does not touch the strings' bytes.
        $body = json_encode(['records' => $wires], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return new L2ResolutionResult(200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'public, max-age=' . $maxAge,
        ], $body);
    }
}
