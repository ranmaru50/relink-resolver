<?php
// tests/L2/Stage3EndpointTest.php
// Stage 3（Issue #28）public L2 surface。PR #31 review 対応の red-first テスト：
//  - BLOCKING-1（§6.1/§6.3 routing separation）: GET /{prefix}/{uuid}?l=2 は L2 Trust Statement
//    （§6.1）、GET /{prefix}/{uuid}/authority は Record series（§6.3）。両者を service 層
//    （statement()/authority() の分離ハンドラ）と public/index.php ルーティング（ソース検査、
//    deploy config をテキストとして読む既存テストの慣習）の両層で分離を主張する。
//  - BLOCKING-2（§6.3）: {"records":[Record_0..Record_G]} の各要素は JSON object であり、
//    保存された canonical wire バイトを byte-for-byte で保持する（二重エンコード禁止）。
//    positive 200 ケースは normal acceptance chain（Record 0..4 + 4A style）を使用する。
//    Appendix A Record5/6 は gap 検出ケースにのみ使用する。
// Service 層は application service（L2ResolverService）を直接駆動し、superglobal は thin
// public/index.php adapter に残す（AGENTS.md §3）。
//
// Conformance mapping (docs/specs/resolver-l2-conformance-0.1.md):
// L2-SRV-001 status contract: active-200, suspended-404, retired-410, unknown-404,
//   malformed-400, method-405-before-lookup, no-suspended-retired-served;
// L2-SRV-002: {"records":[Record_0..Record_G]} verbatim wire strings, strictly ascending,
//   complete prefix 0..G, unsigned transport framing, gap never served as complete;
// L2-SRV-003: GET-only, read-only, anonymous, no mutation on the public surface;
// L2-SRV-004: p fail-closed 400 before record lookup; unsupported l not treated as L2/L1/L3;
// L2-SRV-005: Cache-Control public, max-age = min(deployment bound, expiresAt(G) - now) on 200;
//   error statuses no-store; immediate freshness (cache lifetime never outlives Record G expiry);
// §6.1: statement 200 body is the signed statement wire, Cache-Control throttled by the
//   statement's own expiresAt (§14.2), non-VERIFIED statements never served publicly.

declare(strict_types=1);

namespace Relink\Resolver\Tests\L2;

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Adapters\SodiumEd25519Verifier;
use Relink\Resolver\Application\L2ResolverService;
use Relink\Resolver\Domain\AnchorUuid;
use Relink\Resolver\Domain\DescriptionLocation;
use Relink\Resolver\Domain\L2\Record;
use Relink\Resolver\Domain\L2\StatementPayload;
use Relink\Resolver\Domain\L2\TrustAnchorBinding;
use Relink\Resolver\Domain\LifecycleState;
use Relink\Resolver\Domain\ResolverRecord;
use Relink\Resolver\Ports\L2RecordRepository;
use Relink\Resolver\Ports\L2StatementRepository;
use Relink\Resolver\Ports\ResolverRepository;
use Relink\Resolver\Ports\TrustAnchorBindingRepository;

require_once __DIR__ . '/L2Fixtures.php';
require_once __DIR__ . '/Stage2TestSupport.php';

/**
 * In-memory L1 lifecycle fake counting lookups, to prove the fail-closed ordering
 * of §6.1: 400/405 decisions happen before any record lookup.
 */
final class InMemoryResolverRepository implements ResolverRepository
{
    /** @var array<string, ResolverRecord> */
    private array $records = [];
    public int $lookupCount = 0;

    public function find(AnchorUuid $anchor): ?ResolverRecord
    {
        $this->lookupCount++;

        return $this->records[$anchor->value] ?? null;
    }

    public function insert(ResolverRecord $record): void
    {
        $this->records[$record->anchor->value] = $record;
    }

    public function update(ResolverRecord $record, DescriptionLocation $location, string $entityId, ?string $algorithm, ?string $digest): ResolverRecord
    {
        return $record;
    }

    public function transition(ResolverRecord $record, LifecycleState $target, string $reason, string $actor): ResolverRecord
    {
        $updated = new ResolverRecord($record->anchor, $target, $record->location, $record->entityId, $record->mediaType, $record->integrityAlgorithm, $record->integrityDigest, $record->version + 1, $record->manifestEnabled, $record->integritySource);
        $this->records[$record->anchor->value] = $updated;

        return $updated;
    }

    public function history(AnchorUuid $anchor): array
    {
        return [];
    }
}

/**
 * In-memory Record store keyed by anchor and generation; counts every read query so the
 * tests can prove that 400/405/lifecycle decisions happen before any Record lookup (§6.1).
 */
final class InMemoryL2RecordRepository implements L2RecordRepository
{
    /** @var array<string, array<int, string>> anchor => generation => verbatim wire */
    private array $wires = [];
    public int $queryCount = 0;

    public function appendRecord(Record $record, string $wire): void
    {
        $anchor = $record->anchorId();
        $generation = $record->recordGeneration();
        if (array_key_exists($generation, $this->wires[$anchor] ?? [])) {
            throw new \RuntimeException('RECORD_EXISTS');
        }
        $this->wires[$anchor][$generation] = $wire;
    }

    public function recordWires(string $anchorId): array
    {
        $this->queryCount++;
        $generations = array_keys($this->wires[$anchorId] ?? []);
        sort($generations);

        return array_map(fn (int $generation): string => $this->wires[$anchorId][$generation], $generations);
    }

    public function maxGeneration(string $anchorId): int
    {
        $this->queryCount++;
        $generations = $this->wires[$anchorId] ?? [];

        return $generations === [] ? -1 : max(array_keys($generations));
    }

    public function contiguousRecordWires(string $anchorId): ?array
    {
        $this->queryCount++;
        $stored = $this->wires[$anchorId] ?? [];
        if ($stored === []) {
            return null;
        }
        $generations = array_keys($stored);
        sort($generations);
        $expected = 0;
        foreach ($generations as $generation) {
            if ($generation !== $expected++) {
                return null;
            }
        }

        return array_map(fn (int $generation): string => $stored[$generation], $generations);
    }

    public function expiresAtOfMaxGeneration(string $anchorId): ?int
    {
        $this->queryCount++;
        $stored = $this->wires[$anchorId] ?? [];
        if ($stored === []) {
            return null;
        }
        $max = max(array_keys($stored));

        return Record::fromWire($stored[$max])->expiresAt();
    }

    public function storedCount(string $anchorId): int
    {
        return count($this->wires[$anchorId] ?? []);
    }
}

/** In-memory Trust Anchor Binding store counting lookups (prove fail-closed ordering). */
final class InMemoryBindingRepository implements TrustAnchorBindingRepository
{
    /** @var array<string, TrustAnchorBinding> */
    private array $bindings = [];
    public int $lookupCount = 0;
    public int $storeCount = 0;

    public function storeBinding(TrustAnchorBinding $binding): void
    {
        $this->bindings[$binding->anchorId()] = $binding;
        $this->storeCount++;
    }

    public function findBinding(string $anchorId): ?TrustAnchorBinding
    {
        $this->lookupCount++;

        return $this->bindings[$anchorId] ?? null;
    }
}

/**
 * In-memory Statement store counting read queries; ascending by generation, ties by key id,
 * exactly as the L2StatementRepository port contract.
 */
final class InMemoryStatementRepository implements L2StatementRepository
{
    /** @var array<string, list<array{keyId: string, generation: int, wire: string}>> */
    private array $wires = [];
    public int $queryCount = 0;
    public int $storeCount = 0;

    public function appendStatement(StatementPayload $statement, string $wire): void
    {
        $this->wires[$statement->anchorId()][] = [
            'keyId' => $statement->keyId(),
            'generation' => $statement->generation(),
            'wire' => $wire,
        ];
        $this->storeCount++;
    }

    public function statementWires(string $anchorId): array
    {
        $this->queryCount++;
        $entries = $this->wires[$anchorId] ?? [];
        usort($entries, static fn (array $a, array $b): int => [$a['generation'], $a['keyId']] <=> [$b['generation'], $b['keyId']]);

        return array_map(static fn (array $entry): string => $entry['wire'], $entries);
    }

    public function statementCount(string $anchorId): int
    {
        return count($this->wires[$anchorId] ?? []);
    }
}

final class Stage3EndpointTest extends TestCase
{
    /** Record 4A (positive acceptance chain tip) expiry: expiresAt(Record_4A) = 1770001900. */
    private const RECORD_G_EXPIRES = 1770001900;

    /** Pinned statement S9 expiry: expiresAt(S9) = 1770001200. */
    private const STATEMENT_EXPIRES = 1770001200;

    /** Positive acceptance chain: normal Record 0..4 + 4A style (§6.3 positive cases). */
    private const CHAIN_POSITIVE = ['Record0', 'Record1', 'Record2', 'Record3', 'Record4A'];

    /**
     * Build the full fixture: ACTIVE L1 record, Trust Anchor Binding, Records, Statements.
     *
     * @return array{0: L2ResolverService, 1: InMemoryResolverRepository, 2: InMemoryBindingRepository, 3: InMemoryL2RecordRepository, 4: InMemoryStatementRepository}
     */
    private function fixture(int $deploymentMaxAge, array $recordIds = self::CHAIN_POSITIVE, array $statementIds = [], bool $withBinding = true, LifecycleState $state = LifecycleState::ACTIVE, int $snapshotMaxAge = 600): array
    {
        $resolver = new InMemoryResolverRepository();
        $resolver->insert(new ResolverRecord(new AnchorUuid(Stage2TestSupport::ANCHOR_ID), $state, new DescriptionLocation(Stage2TestSupport::DESCRIPTION_LOCATION), Stage2TestSupport::ENTITY_ID, null, null, null, 1));

        $bindings = new InMemoryBindingRepository();
        if ($withBinding) {
            $bindings->storeBinding(Stage2TestSupport::binding());
        }

        $records = new InMemoryL2RecordRepository();
        foreach ($recordIds as $id) {
            $wire = Stage2TestSupport::wire($id);
            $records->appendRecord(Record::fromWire($wire), $wire);
        }

        $statements = new InMemoryStatementRepository();
        foreach ($statementIds as $id) {
            $wire = Stage2TestSupport::wire($id);
            $statements->appendStatement(StatementPayload::fromWire($wire), $wire);
        }

        $service = new L2ResolverService($resolver, $bindings, $records, $statements, new SodiumEd25519Verifier(), $deploymentMaxAge, $snapshotMaxAge);

        return [$service, $resolver, $bindings, $records, $statements];
    }

    /** §6.3 assembly contract: {"records":[...]} with verbatim wire bytes as JSON object elements. */
    private function assembledBody(array $wireList): string
    {
        return '{"records":[' . implode(',', $wireList) . ']}';
    }

    /**
     * L2-SRV-001/active-200 + L2-SRV-002 + BLOCKING-2: ACTIVE anchor serves the complete
     * ascending array; each element is a JSON object carrying the stored canonical wire bytes
     * byte-for-byte (never a double-encoded JSON string).
     */
    public function testActiveAnchorServesVerbatimAscendingRecordArrayAsJsonObjects(): void
    {
        [$service, $resolver, $bindings, $records] = $this->fixture(600);

        $result = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, [], 1770001300);
        self::assertSame(200, $result->status, 'L2-SRV-001/active-200: ACTIVE L2-supporting anchor answers 200 on the /authority route (§6.3)');
        self::assertSame('application/json; charset=utf-8', $result->headers['Content-Type'], 'L2-SRV-002/transport-framing: JSON body with application/json');

        // Byte-level: the body is exactly {"records":[Record_0..Record_G]} with each Record
        // as its stored wire bytes, joined verbatim (no json_encode of the Record strings).
        $wireList = array_map(fn (string $id): string => Stage2TestSupport::wire($id), self::CHAIN_POSITIVE);
        self::assertSame($this->assembledBody($wireList), $result->body, 'BLOCKING-2/§6.3: wire bytes assembled verbatim into the records array, no double encoding');

        $decoded = json_decode($result->body, true);
        self::assertIsArray($decoded, 'L2-SRV-002: body is a JSON object');
        self::assertSame(['records'], array_keys($decoded), 'L2-SRV-002: single records key in transport framing');
        $recordsBody = $decoded['records'];
        self::assertIsArray($recordsBody, 'L2-SRV-002: records is an array');
        self::assertCount(5, $recordsBody, 'L2-SRV-002: complete prefix Record_0..Record_4A');

        $generations = [];
        foreach ($recordsBody as $index => $element) {
            self::assertIsArray($element, 'BLOCKING-2/§6.3: element ' . $index . ' is a JSON object (signed {payload, signature} Record), not a JSON string');
            self::assertSame(['payload', 'signature'], array_keys($element), '§9.5 envelope framing of each Record element');
            $wire = $wireList[$index];
            $reencoded = json_encode($element, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            self::assertSame($wire, $reencoded, 'BLOCKING-2/verbatim: Record_' . $index . ' element carries the stored canonical wire bytes byte-for-byte');
            self::assertSame(Stage2TestSupport::wire((string) ($index === 4 ? 'Record4A' : 'Record' . $index)), $wire, 'L2-SRV-002/verbatim: wire list position ' . $index . ' is the pinned acceptance-chain wire');
            $generations[] = Record::fromWire($wire)->recordGeneration();
        }
        self::assertSame([0, 1, 2, 3, 4], $generations, 'L2-SRV-002: Records are strictly ascending 0..G');

        // L2-SRV-003/anonymous baseline: no ambient-credential header on the public read.
        foreach ($result->headers as $name => $value) {
            self::assertStringNotContainsStringIgnoringCase('set-cookie', $name, 'L2-SRV-003/anonymous: public L2 response carries no ambient credential');
        }
        self::assertArrayNotHasKey('Location', $result->headers, 'L2-SRV-002: L2 material response is not a redirect');
        self::assertSame(1, $resolver->lookupCount, 'resolver lookup happens exactly once on the 200 path');
    }

    /**
     * P1-2 / §6.3 negative (red-first): a contiguous numbering that fails the accepted chain
     * walk MUST NOT be served publicly. Record 5 fails trail-window matching (§9.6,
     * Appendix A.5); Record 4C widens key3's revokedFrom (§9.5 revocation-field rule). Both
     * keep numbering 0..G contiguous, so numbering contiguity alone cannot protect the
     * public surface; the authority handler verifies the stored prefix against the chain.
     */
    public function testContiguousStoreFailingTrailCapAtRecord5IsNotServedAtAuthority(): void
    {
        [$service, $resolver, $bindings, $records] = $this->fixture(600, ['Record0', 'Record1', 'Record2', 'Record3', 'Record4A', 'Record5']);
        $result = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, [], 1770001300);

        self::assertSame(500, $result->status, 'P1-2/§6.1 internal-failure row: trail-cap failure at Record 5 is never public transport (§6.3/§9.6)');
        self::assertSame('no-store', $result->headers['Cache-Control'], '§14.2: the 500 answer is no-store');
        self::assertSame('', $result->body, 'L2-SRV-002/gap-never-complete: no Records served when the chain walk stops at Record 5');
        self::assertSame(1, $resolver->lookupCount, 'P1-2: the 500 decision is made without any mutation (read-only GET path)');
    }

    /** P1-2 negative, revocation-field widening at the tip (Record 4C, §9.5): contiguous numbering, chain walk rejects → 500 no-store. */
    public function testContiguousStoreWideningRevokedFromAtTipIsNotServedAtAuthority(): void
    {
        [$service, $resolver, $bindings, $records] = $this->fixture(600, ['Record0', 'Record1', 'Record2', 'Record3', 'Record4C']);
        $result = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, [], 1770001300);

        self::assertSame(500, $result->status, 'P1-2/§6.1 internal-failure row: revokedFrom widening at Record 4C is never public transport (§9.5)');
        self::assertSame('no-store', $result->headers['Cache-Control'], '§14.2: the 500 answer is no-store');
        self::assertSame('', $result->body, '§6.3: a rejected chain prefix is not assembled into records transport');
    }

    /** §14.2 / L2-SRV-005/200-bounded-policy + immediate-freshness: max-age = min(deployment bound, expiresAt(G) - now). */
    public function testCacheControlFollowsDeploymentBoundAndExpiryOfRecordG(): void
    {
        self::assertSame(1770001900, self::RECORD_G_EXPIRES, 'fixture pin: expiresAt(Record_4A) = 1770001900');
        $wireList = array_map(fn (string $id): string => Stage2TestSupport::wire($id), self::CHAIN_POSITIVE);

        // Deployment bound (600) below the Record G freshness headroom at now = 1770001300.
        [$service] = $this->fixture(600);
        $result = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, [], 1770001300);
        self::assertSame(200, $result->status, 'L2-SRV-005/200-bounded-policy');
        self::assertSame('public, max-age=600', $result->headers['Cache-Control'], '§14.2: public, max-age = min(deployment bound, expiresAt(G) - now)');
        self::assertSame($this->assembledBody($wireList), $result->body, 'BLOCKING-2: body assembly byte-exact in the bounded-bound case');

        // Deployment bound clamps below the headroom.
        [$serviceClamp] = $this->fixture(120);
        $clamped = $serviceClamp->authority('GET', Stage2TestSupport::ANCHOR_ID, [], 1770001300);
        self::assertSame('public, max-age=120', $clamped->headers['Cache-Control'], '§14.2: deployment bound clamps max-age below expiresAt(G) - now');

        // Headroom shrinks to 50, then to 0, and the cache never outlives Record G expiry.
        [$service] = $this->fixture(600);
        $narrow = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, [], 1770001850);
        self::assertSame('public, max-age=50', $narrow->headers['Cache-Control'], '§14.2/L2-SRV-005: headroom 1770001900 - 1770001850 = 50');
        $atExpiry = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, [], 1770001900);
        self::assertSame(200, $atExpiry->status, '§11.1 window is inclusive [issuedAt, expiresAt]; Record G is effective at its expiresAt');
        self::assertSame('public, max-age=0', $atExpiry->headers['Cache-Control'], '§14.2/L2-SRV-005 immediate-freshness: cache lifetime never outlives Record G expiry');
        $pastExpiry = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, [], 1770002000);
        self::assertSame(200, $pastExpiry->status, 'storage serving is not blocked by Record expiry; the freshness bound clamps to 0');
        self::assertSame('public, max-age=0', $pastExpiry->headers['Cache-Control'], '§14.2/L2-SRV-005: clamped non-negative at Record G expiry');
    }

    /** L2-SRV-001/uppercase-normalize (shared Core path): the authority route accepts an uppercase UUID presentation. */
    public function testUppercaseAnchorUuidIsNormalizedBeforeLookupOnAuthorityRoute(): void
    {
        [$service, $resolver, $bindings, $records, $statements] = $this->fixture(600);

        $result = $service->authority('GET', strtoupper(Stage2TestSupport::ANCHOR_ID), [], 1770001300);
        self::assertSame(200, $result->status, 'L2-SRV-001/normalize: uppercase UUID presentation resolves to the same anchor (§6.1 normalize-before-lookup, shared with the statement route)');
        self::assertSame(1, $resolver->lookupCount, 'normalized anchor hits the registered record exactly once');
        $decoded = json_decode($result->body, true);
        self::assertCount(5, $decoded['records'], 'L2-SRV-002: complete prefix served for the normalized anchor');
    }

    /** L2-SRV-001/malformed-400: malformed UUID is rejected before any lookup, on both routes, with no-store. */
    public function testMalformedAnchorUuidReturns400WithoutAnyLookupOnBothRoutes(): void
    {
        [$service, $resolver, $bindings, $records, $statements] = $this->fixture(600);

        foreach (['not-a-uuid', '550e8400-e29b-41d4-a716-44665544000'] as $malformed) {
            $authority = $service->authority('GET', $malformed, [], 1770001300);
            self::assertSame(400, $authority->status, 'L2-SRV-001/malformed-400 on the /authority route: ' . $malformed);
            self::assertSame('no-store', $authority->headers['Cache-Control'], 'L2-SRV-005/error-no-store: 400 must not be cached, §14.2');
            self::assertSame('', $authority->body, 'L2-SRV-002: malformed request serves no Record material');

            $statement = $service->statement('GET', $malformed, ['l' => '2'], 1770001300);
            self::assertSame(400, $statement->status, 'L2-SRV-001/malformed-400 on the ?l=2 route: ' . $malformed);
            self::assertSame('no-store', $statement->headers['Cache-Control'], '§14.2: 400 must not be cached');
            self::assertSame('', $statement->body, '§6.1: malformed request serves no statement material');
        }
        self::assertSame(0, $resolver->lookupCount, 'L2-SRV-001/malformed-400: rejected before any record lookup');
        self::assertSame(0, $bindings->lookupCount, 'L2-SRV-001/malformed-400: rejected before any Trust Anchor Binding lookup');
        self::assertSame(0, $records->queryCount, 'L2-SRV-001/malformed-400: rejected before any Record lookup');
        self::assertSame(0, $statements->queryCount, '§6.1: rejected before any Statement lookup');
    }

    /** L2-SRV-001/method-405 (§6.1): POST/PUT/DELETE/PATCH answer 405 Allow:GET before any lookup, on both routes. */
    public function testUnsupportedMethodsReturn405BeforeAnyLookupOnBothRoutes(): void
    {
        [$service, $resolver, $bindings, $records, $statements] = $this->fixture(600);

        foreach (['POST', 'PUT', 'DELETE', 'PATCH', 'post'] as $method) {
            $authority = $service->authority($method, Stage2TestSupport::ANCHOR_ID, [], 1770001300);
            self::assertSame(405, $authority->status, 'L2-SRV-001/method-405 on the /authority route: ' . $method . ' (§6.1, GET-only public surface)');
            self::assertSame('GET', $authority->headers['Allow'], '§6.1: 405 declares Allow: GET (public surface supports GET only, §6.4/§16: no public Record submission)');
            self::assertSame('no-store', $authority->headers['Cache-Control'], 'L2-SRV-005/error-no-store: 405 must not be cached, §14.2');
            self::assertSame('', $authority->body, 'L2-SRV-002: 405 serves no Record material');

            $statement = $service->statement($method, Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770001300);
            self::assertSame(405, $statement->status, 'L2-SRV-001/method-405 on the ?l=2 route: ' . $method . ' (§6.1, GET-only public surface)');
            self::assertSame('GET', $statement->headers['Allow'], '§6.1: 405 declares Allow: GET on the statement route');
            self::assertSame('no-store', $statement->headers['Cache-Control'], '§14.2: 405 must not be cached');
            self::assertSame('', $statement->body, '§6.1: 405 serves no statement material');
        }
        self::assertSame(0, $resolver->lookupCount, '§6.1: method check happens before registration lookup');
        self::assertSame(0, $bindings->lookupCount, '§6.1: method check happens before Trust Anchor Binding lookup');
        self::assertSame(0, $records->queryCount, '§6.1: method check happens before Record lookup');
        self::assertSame(0, $statements->queryCount, '§6.1: method check happens before Statement lookup');
    }

    /** L2-SRV-001/unknown-404: unknown anchor answers 404 on both routes and storage is never read. */
    public function testUnknownAnchorReturns404BeforeRecordLookupOnBothRoutes(): void
    {
        [$service, $resolver, $bindings, $records, $statements] = $this->fixture(600);
        // The fixture anchor is registered; probe a never-registered anchor.
        $unknown = '550e8400-e29b-41d4-a716-446655441111';

        $authority = $service->authority('GET', $unknown, [], 1770001300);
        self::assertSame(404, $authority->status, 'L2-SRV-001/unknown-404: unknown anchor answers 404, §6.1');
        self::assertSame('no-store', $authority->headers['Cache-Control'], 'L2-SRV-005/error-no-store: 404 must not be cached, §14.2');
        self::assertSame('', $authority->body, 'L2-SRV-001/no-suspended-retired-served: 404 serves no Record material');

        $statement = $service->statement('GET', $unknown, ['l' => '2'], 1770001300);
        self::assertSame(404, $statement->status, 'L2-SRV-001/unknown-404 on the statement route, §6.1');
        self::assertSame('no-store', $statement->headers['Cache-Control'], '§14.2: 404 must not be cached');
        self::assertSame('', $statement->body, '§6.1: 404 serves no statement material');

        self::assertSame(0, $records->queryCount, 'L2-SRV-001/unknown-404: Record storage never read for an unknown anchor');
        self::assertSame(0, $statements->queryCount, '§6.1: Statement storage never read for an unknown anchor');
    }

    /** L2-SRV-001/suspended-404: SUSPENDED answers 404 with no-store and serves no L2 material. */
    public function testSuspendedAnchorReturns404AndServesNoL2MaterialOnBothRoutes(): void
    {
        [$service, $resolver, $bindings, $records, $statements] = $this->fixture(600, self::CHAIN_POSITIVE, ['S9v1', 'S9'], true, LifecycleState::SUSPENDED);

        $authority = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, [], 1770001300);
        self::assertSame(404, $authority->status, 'L2-SRV-001/suspended-404, §6.1');
        self::assertSame('no-store', $authority->headers['Cache-Control'], 'L2-SRV-005/error-no-store: 404 must not be cached, §14.2');
        self::assertSame('', $authority->body, 'L2-SRV-001/no-suspended-retired-served: suspended anchor serves no Record material');

        $statement = $service->statement('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770001300);
        self::assertSame(404, $statement->status, '§6.1: SUSPENDED anchors MUST NOT be served L2 statements publicly');
        self::assertSame('', $statement->body, '§6.1/no-suspended-retired-served: suspended anchor serves no statement material');

        self::assertSame(0, $records->queryCount, 'L2-SRV-001/suspended-404: Record storage never read for a SUSPENDED anchor');
        self::assertSame(0, $statements->queryCount, '§6.1: Statement storage never read for a SUSPENDED anchor');
    }

    /** L2-SRV-001/retired-410: RETIRED answers 410 with no L2 material on both routes (§14.2: 410 MAY be cached). */
    public function testRetiredAnchorReturns410OnBothRoutes(): void
    {
        [$service, $resolver, $bindings, $records, $statements] = $this->fixture(600, self::CHAIN_POSITIVE, ['S9v1', 'S9'], true, LifecycleState::RETIRED);

        $authority = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, [], 1770001300);
        self::assertSame(410, $authority->status, 'L2-SRV-001/retired-410, §6.1');
        self::assertSame('public, max-age=300', $authority->headers['Cache-Control'], '§14.2/L2-SRV-005: 410 MAY be cached; reference Core convention public, max-age=300');
        self::assertSame('', $authority->body, 'L2-SRV-001/no-suspended-retired-served: RETIRED anchor serves no Record material');

        $statement = $service->statement('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770001300);
        self::assertSame(410, $statement->status, '§6.1: RETIRED anchors answer 410, never serving L2 statements publicly');
        self::assertSame('public, max-age=300', $statement->headers['Cache-Control'], '§14.2: 410 MAY be cached on the statement route too');
        self::assertSame('', $statement->body, '§6.1/no-suspended-retired-served: RETIRED anchor serves no statement material');

        self::assertSame(0, $records->queryCount, 'RETIRED: Record storage never read');
        self::assertSame(0, $statements->queryCount, '§6.1: RETIRED anchors MUST NOT be served L2 statements publicly, Statement storage never read');
    }

    /** L2-SRV-004/§6.2: p fail-closed 400 before any lookup, on both routes. */
    public function testPParameterReturns400BeforeLookupOnBothRoutes(): void
    {
        [$service, $resolver, $bindings, $records, $statements] = $this->fixture(600);
        $query = ['p' => 'https://sensors.example/thermal/42/ar-xml'];

        $authority = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, $query, 1770001300);
        self::assertSame(400, $authority->status, 'L2-SRV-004/§6.2: p fail-closed 400 on the /authority route');
        self::assertSame('', $authority->body);

        $statement = $service->statement('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'] + $query, 1770001300);
        self::assertSame(400, $statement->status, 'L2-SRV-004/§6.2: p fail-closed 400 before record lookup on the ?l=2 route');
        self::assertSame('', $statement->body, '§6.2: 400 serves no L2 material');

        self::assertSame(0, $resolver->lookupCount, '§6.2: p rejected before any lookup');
        self::assertSame(0, $bindings->lookupCount, '§6.2: p rejected before any Trust Anchor Binding lookup');
        self::assertSame(0, $records->queryCount, '§6.2: p rejected before any Record lookup');
        self::assertSame(0, $statements->queryCount, '§6.2: p rejected before any Statement lookup');
    }

    /**
     * L2-SRV-004/§6.5 + BLOCKING-1 routing: the /authority route never accepts an l parameter
     * (the statement route is the ?l=2 handler); unsupported l values on the statement route
     * are 501, never treated as L2, L1, or L3 semantics.
     */
    public function testLParameterIsNeverAcceptedOnAuthorityRouteAndUnsupportedLIs501(): void
    {
        [$service, $resolver, $bindings, $records, $statements] = $this->fixture(600);

        // §6.3 framing: the /authority path carries no l; l=2 belongs to the statement route.
        foreach (['2', '3', '02', '2 '] as $l) {
            $authority = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, ['l' => $l], 1770001300);
            self::assertSame(501, $authority->status, 'BLOCKING-1/§6.5: l=' . $l . ' on the /authority route is an unsupported combination, 501 no-store');
            self::assertSame('no-store', $authority->headers['Cache-Control'], '§14.2: 501 no-store');
            self::assertSame('', $authority->body, 'L2-SRV-002: 501 serves no Record material');
        }

        foreach (['3', '02', '2 '] as $l) {
            $statement = $service->statement('GET', Stage2TestSupport::ANCHOR_ID, ['l' => $l], 1770001300);
            self::assertSame(501, $statement->status, 'L2-SRV-004/§6.5: l=' . $l . ' is not accepted as L2/L1/L3, 501 no-store on the statement route');
            self::assertSame('no-store', $statement->headers['Cache-Control'], '§14.2: 501 no-store');
            self::assertSame('', $statement->body, '§6.5: 501 serves no L2 material');
        }

        // l absent on the statement route: the route contract is l=2 only (defensive 501).
        $noL = $service->statement('GET', Stage2TestSupport::ANCHOR_ID, [], 1770001300);
        self::assertSame(501, $noL->status, 'BLOCKING-1: the statement route is invoked only with l present; l=2 is the sole accepted value');

        self::assertSame(0, $resolver->lookupCount, '§6.5: unsupported parameters rejected before any lookup');
        self::assertSame(0, $bindings->lookupCount, '§6.5: unsupported parameters rejected before any Trust Anchor Binding lookup');
        self::assertSame(0, $records->queryCount, '§6.5: unsupported parameters rejected before any Record lookup');
        self::assertSame(0, $statements->queryCount, '§6.5: unsupported parameters rejected before any Statement lookup');
    }

    /** §9.3: L2 support requires the Trust Anchor Binding; without it the routes answer 501 fail-closed. */
    public function testAnchorWithoutTrustAnchorBindingAnswers501WithoutReadingStorage(): void
    {
        [$service, $resolver, $bindings, $records, $statements] = $this->fixture(600, self::CHAIN_POSITIVE, ['S9v1', 'S9'], false);

        $authority = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, [], 1770001300);
        self::assertSame(501, $authority->status, '§9.3 documented deviation: Core-only anchor keeps the reference 501 convention, no L2 Record material');
        self::assertSame('no-store', $authority->headers['Cache-Control'], '§14.2: 501 no-store');
        self::assertSame('', $authority->body);

        $statement = $service->statement('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770001300);
        self::assertSame(501, $statement->status, '§9.3: the statement route requires the Trust Anchor Binding as well');
        self::assertSame('no-store', $statement->headers['Cache-Control'], '§14.2: 501 no-store');
        self::assertSame('', $statement->body);

        self::assertSame(2, $bindings->lookupCount, 'the binding lookup happens once per route, on the 501 path');
        self::assertSame(0, $records->queryCount, '§9.3 fail-closed: Record storage is never read without a binding');
        self::assertSame(0, $statements->queryCount, '§9.3 fail-closed: Statement storage is never read without a binding');
    }

    /** L2-SRV-002/gap-never-complete on the /authority route, plus the statement route chain-stop fail-closed (§6.1). */
    public function testChainGapsAndWalkStopsAreNeverServedAsComplete(): void
    {
        // Gap inside the stored chain (Appendix A Record4/5/6 are used for gap detection only).
        [$service, $resolver, $bindings, $records, $statements] = $this->fixture(600, ['Record0', 'Record1', 'Record2', 'Record4', 'Record5', 'Record6']);
        $result = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, [], 1770001800);
        self::assertSame(500, $result->status, '§6.3 gap design decision (documented): stored gap 0,1,2,4,5,6 is never served as a complete array; internal-failure row of §6.1 maps to 500');
        self::assertSame('no-store', $result->headers['Cache-Control'], '§14.2: 500 no-store');
        self::assertSame('', $result->body, 'L2-SRV-002/gap-never-complete: no partial array is served');

        // Fill the gap with wires the chain walk accepts: the accepted completed prefix is
        // then served byte-exactly. 0,1,2,4A is a numbering gap at generation 3; Record 3
        // fills it, and the walk accepts 0..4A (§9.6, Appendix A acceptance chain).
        [$serviceFill, , , $fillRecords] = $this->fixture(600, ['Record0', 'Record1', 'Record2', 'Record4A']);
        $gapResult = $serviceFill->authority('GET', Stage2TestSupport::ANCHOR_ID, [], 1770001800);
        self::assertSame(500, $gapResult->status, '§6.3: gap at generation 3 (stored 0,1,2,4A) is never served as a complete array');

        $fillWire = Stage2TestSupport::wire('Record3');
        $fillRecords->appendRecord(Record::fromWire($fillWire), $fillWire);
        $filled = $serviceFill->authority('GET', Stage2TestSupport::ANCHOR_ID, [], 1770001800);
        self::assertSame(200, $filled->status, 'the completed accepted 0..4A prefix is served once the gap is filled (P1-2: the walk accepts every stored wire before serving)');
        $fullWireList = array_map(fn (string $id): string => Stage2TestSupport::wire($id), self::CHAIN_POSITIVE);
        self::assertSame($this->assembledBody($fullWireList), $filled->body, 'BLOCKING-2: gap-filled body is assembled byte-exactly from stored wires');
        $decoded = json_decode($filled->body, true);
        self::assertCount(5, $decoded['records'], 'L2-SRV-002: complete accepted prefix Record_0..Record_4A after fill');
        foreach ($decoded['records'] as $index => $element) {
            self::assertIsArray($element, 'BLOCKING-2/§6.3: gap-filled element ' . $index . ' is a JSON object, not a double-encoded string');
        }

        // Missing Record 0: the chain can never start, served as 500 (§6.3/§9.6).
        [$service2] = $this->fixture(600, ['Record1', 'Record2', 'Record3']);
        $noStart = $service2->authority('GET', Stage2TestSupport::ANCHOR_ID, [], 1770001300);
        self::assertSame(500, $noStart->status, 'missing Record 0 fails the chain start (§9.6), never served as complete');

        // Empty storage: 500, no Records served.
        [$service3] = $this->fixture(600, []);
        $empty = $service3->authority('GET', Stage2TestSupport::ANCHOR_ID, [], 1770001300);
        self::assertSame(500, $empty->status, 'an anchor with no Record storage is an internal storage state, fail-closed 500 (§6.1 internal-failure row)');

        // Statement route: a Record chain with a stored gap must never verify, never serve (§6.1).
        [$service4, $resolver4, $bindings4, $records4, $statements4] = $this->fixture(600, ['Record0', 'Record1', 'Record2', 'Record4A'], ['S9']);
        $gapStatement = $service4->statement('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1769998400);
        self::assertSame(500, $gapStatement->status, '§6.1 fail-closed: the statement route verifies the chain; a stored gap yields no public statement (§15.2 phase 5)');
        self::assertSame('no-store', $gapStatement->headers['Cache-Control'], '§14.2: 500 no-store');
        self::assertSame('', $gapStatement->body, '§6.1: no statement is served for a gap chain');
        self::assertSame(1, $records4->queryCount, 'the statement route reads the Record chain exactly once');

        // Statement route: a contiguous chain whose walk stops (Record5 trail-matching failure,
        // L2-VER-016) must fail closed, never serve the latest statement.
        [$service5, $resolver5, $bindings5, $records5, $statements5] = $this->fixture(600, ['Record0', 'Record1', 'Record2', 'Record3', 'Record4A', 'Record5'], ['S9']);
        $stoppedStatement = $service5->statement('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1769998400);
        self::assertSame(500, $stoppedStatement->status, '§6.1/§15.2: chain walk stop on the stored prefix fails the statement route closed (never 200)');
        self::assertSame('', $stoppedStatement->body, '§6.1: a statement over a stopped chain is never served publicly');
        self::assertSame(1, $records5->queryCount, 'the statement route reads the Record chain exactly once');

        // Statement route: cross-epoch statement (S5, epoch-0) over the epoch-1 chain is STALE
        // (§11.3/§12.3) and must never be served publicly.
        [$service6] = $this->fixture(600, ['Record0', 'Record1', 'Record2', 'Record3'], ['S5']);
        $crossEpoch = $service6->statement('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1769998400);
        self::assertSame(500, $crossEpoch->status, '§6.1: a STALE statement (epoch replay, §11.3/§12.3) is never served publicly, fail-closed 500');
        self::assertSame('', $crossEpoch->body, '§6.1: no statement material for non-VERIFIED verdicts');
    }

    /** ACTIVE anchor with no stored statement: §6.1 cannot be answered as specified; fail-closed 500. */
    public function testActiveAnchorWithoutStatementIsFailClosed(): void
    {
        // Judgment basis (DEFENSIBLE-BUT-UNPINNED, recorded in code comments at the service):
        // §6.1 pins ACTIVE → 200 + statement for an L2-supporting anchor; with an empty
        // statement store the deployment cannot answer §6.1 as specified. §6.1 internal-failure
        // row (500/503) is the closest pinned mapping; serving nothing is fail-closed.
        [$service, $resolver, $bindings, $records, $statements] = $this->fixture(600, self::CHAIN_POSITIVE, []);

        $result = $service->statement('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1769998400);
        self::assertSame(500, $result->status, '§6.1 internal-failure mapping: ACTIVE anchor, no statement material, no §6.1 status to serve');
        self::assertSame('no-store', $result->headers['Cache-Control'], '§14.2: 500 no-store');
        self::assertSame('', $result->body, '§6.1: no statement material is served on the failure path');
        self::assertSame(1, $statements->queryCount, 'the empty statement store was checked once, fail-closed');
        self::assertSame(0, $records->queryCount, 'the failure is detected before any Record chain read (fail-closed ordering)');
    }

    /**
     * §6.1 statement route 200: the signed statement wire for the accepted latest generation
     * is served verbatim; Cache-Control is throttled by the statement's own expiresAt (§14.2).
     * Positive fixture: acceptance chain Record 0..3 + 4A, statements S9v1 (gen 1) then S9 (gen 2),
     * now = T = 1769998400 → S9 VERIFIED (ReplayMatrix s9-generation-2-base).
     */
    public function testStatementRouteServesVerifiedLatestStatementVerbatim(): void
    {
        [$service, $resolver, $bindings, $records, $statements] = $this->fixture(600, self::CHAIN_POSITIVE, ['S9v1', 'S9']);

        $result = $service->statement('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1769998400);
        self::assertSame(200, $result->status, '§6.1: ACTIVE L2-supporting anchor answers 200 with the L2 Trust Statement on GET ?l=2');
        self::assertSame('application/json; charset=utf-8', $result->headers['Content-Type'], '§6.1: the signed statement is served as JSON');
        self::assertSame(Stage2TestSupport::wire('S9'), $result->body, '§6.1/verbatim: the body is the stored wire of the accepted latest statement (generation 2), byte-for-byte');

        $decoded = json_decode($result->body, true);
        self::assertIsArray($decoded, '§6.1: statement body is a JSON object');
        self::assertSame(['payload', 'signature'], array_keys($decoded), '§7.1: statement envelope is exactly the signed {payload, signature} framing');

        // §14.2: public, max-age = min(deployment bound, expiresAt(statement) - now), never
        // exceeding the statement's remaining validity.
        self::assertSame(1770001200, self::STATEMENT_EXPIRES, 'fixture pin: expiresAt(S9) = 1770001200');
        self::assertSame('public, max-age=600', $result->headers['Cache-Control'], '§14.2: min(deployment bound 600, 1770001200 - 1769998400 = 900) = 600, throttled by the statement expiry');
        self::assertArrayNotHasKey('Location', $result->headers, '§6.1: the statement response is not a redirect (L1 303 path is separate, no l)');
        foreach ($result->headers as $name => $value) {
            self::assertStringNotContainsStringIgnoringCase('set-cookie', $name, 'L2-SRV-003/anonymous: public statement response carries no ambient credential');
        }

        self::assertSame(1, $resolver->lookupCount, 'resolver lookup happens exactly once');
        self::assertSame(1, $bindings->lookupCount, 'binding lookup happens exactly once');
        self::assertSame(1, $statements->queryCount, 'the statement store is read exactly once');
        self::assertSame(1, $records->queryCount, 'the Record chain is read exactly once for statement verification (§15.2 phases 5-6)');
    }

    /** §14.2 statement route Cache-Control: throttled by the statement's expiresAt, clamped by the deployment bound. */
    public function testStatementCacheControlIsThrottledByStatementExpiry(): void
    {
        // Deployment bound below the headroom.
        [$serviceBound] = $this->fixture(50, self::CHAIN_POSITIVE, ['S9v1', 'S9']);
        $bound = $serviceBound->statement('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1769998400);
        self::assertSame(200, $bound->status);
        self::assertSame('public, max-age=50', $bound->headers['Cache-Control'], '§14.2: deployment bound 50 clamps below the statement headroom (900)');

        // Deployment bound above the headroom: the statement expiry throttles the cache.
        [$serviceHigh] = $this->fixture(900, self::CHAIN_POSITIVE, ['S9v1', 'S9']);
        $throttled = $serviceHigh->statement('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1769998400);
        self::assertSame(200, $throttled->status);
        self::assertSame('public, max-age=900', $throttled->headers['Cache-Control'], '§14.2/L2-SRV-005 analogue: the cache lifetime never outlives the statement expiresAt; bound 900, headroom 900');

        // Headroom shrinks to 50 (W widened to keep the pinned snapshot fresh at that T).
        [$serviceLate] = $this->fixture(600, self::CHAIN_POSITIVE, ['S9v1', 'S9'], true, LifecycleState::ACTIVE, 3000);
        $late = $serviceLate->statement('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770001150);
        self::assertSame(200, $late->status);
        self::assertSame('public, max-age=50', $late->headers['Cache-Control'], '§14.2: headroom 1770001200 - 1770001150 = 50 clamps the cache lifetime');

        // At the statement expiresAt the remaining validity is 0.
        $atExpiry = $serviceLate->statement('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770001200);
        self::assertSame(200, $atExpiry->status, '§11.1 window [issuedAt, expiresAt] is inclusive at expiresAt');
        self::assertSame('public, max-age=0', $atExpiry->headers['Cache-Control'], '§14.2: max-age never exceeds the remaining validity of the statement');

        // Beyond expiresAt: EXPIRED at phase 10, never served publicly (§6.1 fail-closed).
        $expired = $serviceLate->statement('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770001201);
        self::assertSame(500, $expired->status, '§6.1/L2-SRV-001 analogue: an EXPIRED statement is never served publicly, §6.1 internal-failure mapping fail-closed 500');
        self::assertSame('no-store', $expired->headers['Cache-Control'], '§14.2: 500 no-store');
        self::assertSame('', $expired->body, '§6.1: no statement material on the failure path');
    }

    /** L2-SRV-003/§6.4: GET-only, read-only, anonymous on both routes; nothing mutates storage. */
    public function testReadOnlyPublicSurfaceOnBothRoutes(): void
    {
        [$service, $resolver, $bindings, $records, $statements] = $this->fixture(600, self::CHAIN_POSITIVE, ['S9v1', 'S9']);

        $authorityGet = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, [], 1770001300);
        $statementGet = $service->statement('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1769998400);
        self::assertSame(200, $authorityGet->status);
        self::assertSame(200, $statementGet->status);

        foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
            $mutation = $service->authority($method, Stage2TestSupport::ANCHOR_ID, [], 1770001300);
            self::assertSame(405, $mutation->status, 'L2-SRV-003/§6.4/§16: no public Record submission endpoint exists on the /authority read surface');
            $mutationStatement = $service->statement($method, Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1769998400);
            self::assertSame(405, $mutationStatement->status, 'L2-SRV-003/§6.4/§16: no public statement submission endpoint exists on the ?l=2 read surface');
        }

        $authorityAgain = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, [], 1770001300);
        self::assertSame($authorityGet->body, $authorityAgain->body, 'L2-SRV-003/read-only: repeated requests observe byte-identical responses');
        $statementAgain = $service->statement('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1769998400);
        self::assertSame($statementGet->body, $statementAgain->body, 'L2-SRV-003/read-only: repeated statement requests observe byte-identical responses');

        self::assertSame(5, $records->storedCount(Stage2TestSupport::ANCHOR_ID), 'L2-SRV-003: Record storage is unchanged by any public request');
        self::assertSame(2, $statements->storeCount, 'L2-SRV-003: Statement storage is unchanged by any public request');
        self::assertSame(1, $bindings->storeCount, 'L2-SRV-003: Trust Anchor Bindings arrive only via the policy/admin channel (§9.3/§16), never via the public endpoint');
    }

    /**
     * BLOCKING-1 at the index.php layer: public/index.php must route
     * GET /{prefix}/{uuid}/authority to the Record series handler (authority()) and
     * GET /{prefix}/{uuid}?l=2 to the Trust Statement handler (statement()), keeping the L1
     * 303 path (no l) intact. Source-inspection test (repo precedent: tests read deploy
     * config files as text); service-level separation is asserted by the tests above.
     */
    public function testIndexRoutesAuthorityPathToRecordSeriesAndL2QueryToStatement(): void
    {
        $source = file_get_contents(__DIR__ . '/../../public/index.php');
        self::assertIsString($source, 'public/index.php must be readable for the routing separation assertion');

        // §6.3 route guard: the 2-segment /authority path is routed to the Record series handler.
        $guard = "count(\$parts) === 2 && \$parts[1] === 'authority'";
        self::assertStringContainsString($guard, $source, 'BLOCKING-1/§6.3: a route guard for the 2-segment /authority path exists (currently the 2-segment path answers 404)');

        // §6.1 route: the l query parameter routes the single-segment path to the statement handler.
        self::assertStringContainsString("array_key_exists('l', \$_GET)", $source, '§6.1: the l query parameter is the statement-route discriminator');

        // Both handlers are separate call sites; routing separation, not one handler serving both.
        $guardPos = strpos($source, $guard);
        $authorityPos = strpos($source, '->authority(');
        $lPos = strpos($source, "array_key_exists('l', \$_GET)");
        $statementPos = strpos($source, '->statement(');
        self::assertIsInt($authorityPos, 'BLOCKING-1: the Record series handler is invoked for the /authority path');
        self::assertIsInt($statementPos, 'BLOCKING-1/§6.1: the Trust Statement handler (statement()) is invoked for the ?l=2 path (currently ?l=2 is wired to authority())');
        self::assertLessThan($authorityPos, (int) $guardPos, 'the /authority route guard precedes its handler call');
        self::assertLessThan($statementPos, (int) $lPos, 'the l query guard precedes the statement handler call');
        self::assertLessThan($statementPos, $authorityPos, 'the two L2 route handlers are separate, ordered call sites (routing separation §6.1/§6.3)');

        // L1 Core path preserved: requests without l keep the ResolverService redirect passthrough
        // (ACTIVE → 303 status emitted from the service result, asserted at service level in the
        // L1 suite; the adapter must keep emitting the service status verbatim).
        self::assertStringContainsString('->resolve(', $source, '§6.1/§6.3: the L1 resolve path stays for requests without l');
        self::assertStringContainsString('http_response_code($result->status)', $source, 'L1 ACTIVE resolution keeps the service-pinned status (303 redirect) through the adapter, never replaced by the statement route');

        // Single-segment guard for the statement route (the statement path is /{uuid}, not /{uuid}/x).
        self::assertStringContainsString('count($parts) === 1', $source, 'the statement route is guarded by the single-segment /{uuid} path (§6.3 authority is 2-segment; §6.1 statement is 1-segment + l)');
    }
}
