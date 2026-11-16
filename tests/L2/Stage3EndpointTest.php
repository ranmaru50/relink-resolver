<?php
// tests/L2/Stage3EndpointTest.php
// Stage 3（Issue #28）: public GET /relink/{uuid}?l=2 surface. Tests exercise the application
// service (L2ResolverService) directly; Apache superglobals stay in the thin public/index.php
// adapter, per AGENTS.md §3 (framework portability).
//
// Conformance mapping (docs/specs/resolver-l2-conformance-0.1.md):
// L2-SRV-001 status contract: active-200, suspended-404, retired-410, unknown-404,
//   malformed-400, method-405-before-lookup, no-suspended-retired-served;
// L2-SRV-002: {"records":[Record_0..Record_G]} verbatim wire strings, strictly ascending,
//   complete prefix 0..G, unsigned transport framing, gap never served as complete;
// L2-SRV-003: GET-only, read-only, anonymous, no mutation on the public surface;
// L2-SRV-004: p fail-closed 400 before record lookup; unsupported l not treated as L2/L1/L3;
// L2-SRV-005: Cache-Control public, max-age = min(deployment bound, expiresAt(G) - now) on 200;
//   error statuses no-store; immediate freshness (cache lifetime never outlives Record G expiry).

declare(strict_types=1);

namespace Relink\Resolver\Tests\L2;

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Application\L2ResolverService;
use Relink\Resolver\Domain\AnchorUuid;
use Relink\Resolver\Domain\DescriptionLocation;
use Relink\Resolver\Domain\L2\L2ResolutionResult;
use Relink\Resolver\Domain\L2\Record;
use Relink\Resolver\Domain\L2\TrustAnchorBinding;
use Relink\Resolver\Domain\LifecycleState;
use Relink\Resolver\Domain\ResolverRecord;
use Relink\Resolver\Ports\L2RecordRepository;
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

final class Stage3EndpointTest extends TestCase
{
    /** Record 6 expiry from the pinned fixture set: expiresAt(Record_6) = 1770002400. */
    private const RECORD_G_EXPIRES = 1770002400;

    /**
     * Build the full fixture: ACTIVE L1 record, Trust Anchor Binding, complete Record 0..6.
     *
     * @return array{0: L2ResolverService, 1: InMemoryResolverRepository, 2: InMemoryBindingRepository, 3: InMemoryL2RecordRepository}
     */
    private function fixture(int $deploymentMaxAge, array $recordIds = ['Record0', 'Record1', 'Record2', 'Record3', 'Record4', 'Record5', 'Record6'], bool $withBinding = true, LifecycleState $state = LifecycleState::ACTIVE): array
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

        return [new L2ResolverService($resolver, $bindings, $records, $deploymentMaxAge), $resolver, $bindings, $records];
    }

    /** L2-SRV-001/active-200 + L2-SRV-002: ACTIVE anchor serves the complete ascending verbatim array. */
    public function testActiveAnchorServesVerbatimAscendingRecordArrayAsJson(): void
    {
        [$service, $resolver, $bindings, $records] = $this->fixture(600);

        $result = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770001800);
        self::assertSame(200, $result->status, 'L2-SRV-001/active-200: ACTIVE L2-supporting anchor answers 200');
        self::assertSame('application/json; charset=utf-8', $result->headers['Content-Type'], 'L2-SRV-002/transport-framing: JSON body with application/json');

        $decoded = json_decode($result->body, true);
        self::assertIsArray($decoded, 'L2-SRV-002: body is JSON object');
        self::assertSame(['records'], array_keys($decoded), 'L2-SRV-002: single records key in transport framing');
        $recordsBody = $decoded['records'];
        self::assertIsArray($recordsBody, 'L2-SRV-002: records is an array');
        self::assertCount(7, $recordsBody, 'L2-SRV-002: complete prefix Record_0..Record_6');

        $generations = [];
        foreach ($recordsBody as $index => $wire) {
            self::assertIsString($wire, 'L2-SRV-002: each Record is the verbatim canonical wire payload string, not a re-encoded object');
            self::assertSame(Stage2TestSupport::wire('Record' . $index), $wire, 'L2-SRV-002/verbatim: Record_' . $index . ' served byte-for-byte as stored');
            $generations[] = Record::fromWire($wire)->recordGeneration();
        }
        self::assertSame(range(0, 6), $generations, 'L2-SRV-002: Records are strictly ascending 0..6');

        // L2-SRV-003/anonymous + L2-SRV-006 baseline: no ambient-credential header on the public read.
        foreach ($result->headers as $name => $value) {
            self::assertStringNotContainsStringIgnoringCase('set-cookie', $name, 'L2-SRV-003/anonymous: public L2 response carries no ambient credential');
        }
        self::assertArrayNotHasKey('Location', $result->headers, 'L2-SRV-002: L2 material response is not a redirect');
    }

    /** §14.2 / L2-SRV-005/200-bounded-policy + immediate-freshness: max-age = min(deployment bound, expiresAt(G) - now). */
    public function testCacheControlFollowsDeploymentBoundAndExpiryOfRecordG(): void
    {
        // Deployment bound (600) below the Record G freshness headroom (600 s at now=1770001800).
        [$service] = $this->fixture(600);
        $result = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770001800);
        self::assertSame(200, $result->status, 'L2-SRV-005/200-bounded-policy');
        self::assertSame('public, max-age=600', $result->headers['Cache-Control'], '§14.2: public, max-age = min(deployment bound, expiresAt(G) - now)');

        // Tighter deployment bound caps the value.
        [$service] = $this->fixture(120);
        $result = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770001800);
        self::assertSame('public, max-age=120', $result->headers['Cache-Control'], 'L2-SRV-005/200-bounded-policy: deployment bound caps max-age');

        // Freshness headroom to Record G expiry is the smaller side of the min.
        [$service] = $this->fixture(600);
        $result = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770002350);
        self::assertSame('public, max-age=50', $result->headers['Cache-Control'], 'L2-SRV-005/immediate-freshness: max-age never outlives expiresAt(G)=' . self::RECORD_G_EXPIRES);

        // Expiry reached: cache lifetime clamps to 0, answer stays 200 (§14.2 freshness boundary).
        [$service] = $this->fixture(600);
        $result = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770002400);
        self::assertSame(200, $result->status, 'L2-SRV-005: 200 answer is served at the expiry instant');
        self::assertSame('public, max-age=0', $result->headers['Cache-Control'], 'L2-SRV-005/immediate-freshness: non-negative clamp at expiry');

        // Clock past Record G expiry: clamped to 0, never negative.
        [$service] = $this->fixture(600);
        $result = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770002500);
        self::assertSame(200, $result->status, 'L2-SRV-002: the endpoint answers with the stored chain, not the L2 freshness verdict');
        self::assertSame('public, max-age=0', $result->headers['Cache-Control'], '§14.2: negative headroom clamps to 0, never negative max-age');
    }

    /** Anchor UUID parsing is case-insensitive at the service boundary (index.php regex /i companion). */
    public function testUppercaseAnchorUuidIsNormalizedBeforeLookup(): void
    {
        [$service, $resolver, $bindings, $records] = $this->fixture(600);

        $result = $service->authority('GET', strtoupper(Stage2TestSupport::ANCHOR_ID), ['l' => '2'], 1770001800);
        self::assertSame(200, $result->status, 'L2-SRV-001/active-200: uppercase anchor resolves to the same ACTIVE anchor');
        $decoded = json_decode($result->body, true);
        self::assertCount(7, $decoded['records'], 'L2-SRV-002: complete prefix served for the normalized anchor');
    }

    /** L2-SRV-001/malformed-400: malformed UUID is rejected before any lookup, with no-store. */
    public function testMalformedAnchorUuidReturns400WithoutAnyLookup(): void
    {
        [$service, $resolver, $bindings, $records] = $this->fixture(600);

        foreach (['not-a-uuid', '550e8400-e29b-41d4-a716-44665544000'] as $malformed) {
            $result = $service->authority('GET', $malformed, ['l' => '2'], 1770001800);
            self::assertSame(400, $result->status, 'L2-SRV-001/malformed-400: ' . $malformed);
            self::assertSame('no-store', $result->headers['Cache-Control'], 'L2-SRV-005/error-no-store: 400 must not be cached, §14.2');
            self::assertSame('', $result->body, 'L2-SRV-002: malformed request serves no Record material');
        }
        self::assertSame(0, $resolver->lookupCount, 'L2-SRV-001/malformed-400: rejected before any record lookup');
        self::assertSame(0, $bindings->lookupCount, 'L2-SRV-001/malformed-400: rejected before any Trust Anchor Binding lookup');
        self::assertSame(0, $records->queryCount, 'L2-SRV-001/malformed-400: rejected before any Record lookup');
    }

    /** L2-SRV-001/method-405 (§6.1): POST/PUT/DELETE/PATCH answer 405 Allow:GET before any lookup. */
    public function testUnsupportedMethodsReturn405BeforeAnyLookup(): void
    {
        [$service, $resolver, $bindings, $records] = $this->fixture(600);

        foreach (['POST', 'PUT', 'DELETE', 'PATCH', 'post'] as $method) {
            $result = $service->authority($method, Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770001800);
            self::assertSame(405, $result->status, 'L2-SRV-001/method-405: ' . $method . ' (§6.1, GET-only public surface)');
            self::assertSame('GET', $result->headers['Allow'], '§6.1: 405 declares Allow: GET (public surface supports GET only, §6.4/§16: no public Record submission)');
            self::assertSame('no-store', $result->headers['Cache-Control'], 'L2-SRV-005/error-no-store: 405 must not be cached, §14.2');
            self::assertSame('', $result->body, 'L2-SRV-002: 405 serves no Record material');
        }
        self::assertSame(0, $resolver->lookupCount, '§6.1: method check happens before registration lookup');
        self::assertSame(0, $bindings->lookupCount, '§6.1: method check happens before Trust Anchor Binding lookup');
        self::assertSame(0, $records->queryCount, '§6.1: method check happens before Record lookup');
    }

    /** L2-SRV-001/unknown-404: unknown anchor answers 404 and Record storage is never read. */
    public function testUnknownAnchorReturns404BeforeRecordLookup(): void
    {
        [$service, $resolver, $bindings, $records] = $this->fixture(600);
        // The fixture anchor is registered; probe a never-registered anchor.
        $unknown = '550e8400-e29b-41d4-a716-446655441111';

        $result = $service->authority('GET', $unknown, ['l' => '2'], 1770001800);
        self::assertSame(404, $result->status, 'L2-SRV-001/unknown-404: unknown anchor answers 404, §6.1');
        self::assertSame('no-store', $result->headers['Cache-Control'], 'L2-SRV-005/error-no-store: 404 must not be cached, §14.2');
        self::assertSame('', $result->body, 'L2-SRV-001/no-suspended-retired-served: 404 serves no Record material');
        self::assertSame(0, $records->queryCount, 'L2-SRV-001/unknown-404: Record storage never read for an unknown anchor');
    }

    /** L2-SRV-001/suspended-404: SUSPENDED answers 404 with no-store and serves no Records. */
    public function testSuspendedAnchorReturns404AndServesNoL2Material(): void
    {
        [$service, $resolver, $bindings, $records] = $this->fixture(600, recordIds: ['Record0', 'Record1', 'Record2', 'Record3', 'Record4', 'Record5', 'Record6'], withBinding: true, state: LifecycleState::SUSPENDED);

        $result = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770001800);
        self::assertSame(404, $result->status, 'L2-SRV-001/suspended-404, §6.1');
        self::assertSame('no-store', $result->headers['Cache-Control'], 'L2-SRV-005/error-no-store: 404 must not be cached, §14.2');
        self::assertSame('', $result->body, 'L2-SRV-001/no-suspended-retired-served: suspended anchor serves no Record material');
        self::assertSame(0, $records->queryCount, 'L2-SRV-001/suspended-404: Record storage never read for a SUSPENDED anchor');
    }

    /** L2-SRV-001/retired-410: RETIRED answers 410 with no Record material (§14.2: 410 MAY be cached). */
    public function testRetiredAnchorReturns410AndServesNoRecords(): void
    {
        [$service, $resolver, $bindings, $records] = $this->fixture(600, recordIds: ['Record0', 'Record1', 'Record2', 'Record3', 'Record4', 'Record5', 'Record6'], withBinding: true, state: LifecycleState::RETIRED);

        $result = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770001800);
        self::assertSame(410, $result->status, 'L2-SRV-001/retired-410, §6.1');
        self::assertSame('', $result->body, 'L2-SRV-001/no-suspended-retired-served: retired anchor serves no Record material');
        self::assertSame('public, max-age=300', $result->headers['Cache-Control'], '§14.2: 410 MAY be cached; the existing reference convention public, max-age=300 is applied (410 is not in the SHOULD no-store list)');
        self::assertSame(0, $records->queryCount, 'L2-SRV-001/retired-410: Record storage never read for a RETIRED anchor');
    }

    /** §6.2 / L2-SRV-004: p on an L2-supporting implementation answers 400 BEFORE any record lookup. */
    public function testPParameterReturns400BeforeRecordLookup(): void
    {
        [$service, $resolver, $bindings, $records] = $this->fixture(600);

        $result = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2', 'p' => Stage2TestSupport::DESCRIPTION_LOCATION], 1770001800);
        self::assertSame(400, $result->status, 'L2-SRV-004, §6.2: p is rejected on the L2 surface, fail-closed');
        self::assertSame('no-store', $result->headers['Cache-Control'], 'L2-SRV-005/error-no-store: 400 must not be cached, §14.2');
        self::assertSame('', $result->body, 'L2-SRV-002: p request serves no Record material');
        self::assertSame(0, $resolver->lookupCount, '§6.2: p is rejected before any lookup, fail-closed');
        self::assertSame(0, $bindings->lookupCount, '§6.2: p is rejected before any lookup, fail-closed');
        self::assertSame(0, $records->queryCount, '§6.2: p is rejected BEFORE record lookup');
    }

    /** §6.5 / L2-SRV-004/forward-compat: unsupported l values are never treated as L2, L1, or L3. */
    public function testUnsupportedLParameterIsNotTreatedAsL2(): void
    {
        [$service, $resolver, $bindings, $records] = $this->fixture(600);

        foreach (['3', '02', '2 '] as $l) {
            $result = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, ['l' => $l], 1770001800);
            self::assertSame(501, $result->status, 'L2-SRV-004/forward-compat, §6.5: unsupported l="' . $l . '" is not accepted as L2, L1, or L3 semantics (the reference deployment mirrors its existing Core 501 convention)');
            self::assertSame('no-store', $result->headers['Cache-Control'], 'L2-SRV-005/error-no-store: 501 must not be cached, §14.2');
            self::assertSame('', $result->body, 'L2-SRV-002: unsupported l serves no Record material');
            self::assertSame(0, $resolver->lookupCount, '§6.5: unsupported l rejected before any lookup');
            self::assertSame(0, $records->queryCount, '§6.5: unsupported l rejected before any Record lookup');
        }
    }

    /**
     * An anchor without a Trust Anchor Binding (§9.3) is not L2-supporting. Deviation documented in
     * the implementation: §6.1 adds no MUST-level L2 status for Core-only deployments, so the
     * reference deployment keeps its existing Core 501 convention for l=2 on such anchors.
     */
    public function testAnchorWithoutTrustAnchorBindingIsNotL2Supporting(): void
    {
        [$service, $resolver, $bindings, $records] = $this->fixture(600, withBinding: false);

        $result = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770001800);
        self::assertSame(501, $result->status, '§9.3 precondition: no Trust Anchor Binding means no L2 support; documented deviation mirrors the existing Core 501 convention');
        self::assertSame('no-store', $result->headers['Cache-Control'], 'L2-SRV-005/error-no-store');
        self::assertSame('', $result->body, 'L2-SRV-002: non-L2-supporting anchor serves no Record material');
        self::assertSame(1, $bindings->lookupCount, '§9.3: the binding lookup decides L2 support');
        self::assertSame(0, $records->queryCount, '§6.3 fail-closed: Record storage never read for a non-L2-supporting anchor');
    }

    /**
     * §6.3 / §6.1: a chain gap means a verifier reports UNVERIFIED at phase 5, so the public
     * endpoint MUST NOT serve a gapful set as if it were complete. The reference deployment
     * maps this internally detected storage integrity violation to an internal failure (500)
     * with no-store, per the §6.1 internal-failure status row.
     */
    public function testChainGapIsDetectedAndNeverServedAsComplete(): void
    {
        // Gap in the middle of the range: 0,1,2,4,5,6 with Record_3 missing.
        [$service, $resolver, $bindings, $records] = $this->fixture(600, recordIds: ['Record0', 'Record1', 'Record2', 'Record4', 'Record5', 'Record6']);

        $result = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770001800);
        self::assertSame(500, $result->status, 'L2-SRV-002, §6.3: gap between 0 and max must not be served as a complete set; mapped to internal failure per §6.1');
        self::assertSame('no-store', $result->headers['Cache-Control'], 'L2-SRV-005/error-no-store: 500 must not be cached, §14.2');
        self::assertSame('', $result->body, 'L2-SRV-002: the gapful set is never served, not even partially, as complete');
        self::assertSame(6, $records->storedCount(Stage2TestSupport::ANCHOR_ID), 'L2-SRV-002: detecting the gap changes nothing in storage (read-only surface)');

        // Filling the gap makes the complete 0..6 prefix serveable again.
        $wire3 = Stage2TestSupport::wire('Record3');
        $records->appendRecord(Record::fromWire($wire3), $wire3);
        $result = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770001800);
        self::assertSame(200, $result->status, 'L2-SRV-002: complete prefix 0..6 is served once the gap is filled');
        $decoded = json_decode($result->body, true);
        self::assertCount(7, $decoded['records'], 'L2-SRV-002: 7 Records in the served array');
        self::assertSame($wire3, $decoded['records'][3], 'L2-SRV-002/verbatim: Record_3 served verbatim');

        // Missing Record_0 (bootstrap gap) is the same class of internal failure.
        [$service, $resolver, $bindings, $records] = $this->fixture(600, recordIds: ['Record1', 'Record2', 'Record3']);
        $result = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770001800);
        self::assertSame(500, $result->status, '§6.3: the verifier requires Record_0; a chain starting at 1 must not be served as complete');
        self::assertSame('no-store', $result->headers['Cache-Control'], 'L2-SRV-005/error-no-store');

        // No L2 material at all: the chain has a bootstrap gap at Record_0 (§6.3).
        [$service, $resolver, $bindings, $records] = $this->fixture(600, recordIds: []);
        $result = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770001800);
        self::assertSame(500, $result->status, '§6.3 fail-closed: an empty record set is not a complete prefix');
        self::assertSame('', $result->body, 'L2-SRV-002: empty storage is never served as {"records":[]}');
    }

    /** L2-SRV-003 / §6.4: the public surface is GET-only, read-only, anonymous; mutations never reach Record storage. */
    public function testReadOnlyPublicSurfaceLeavesStateUnchanged(): void
    {
        [$service, $resolver, $bindings, $records] = $this->fixture(600);

        $get = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770001800);
        self::assertSame(200, $get->status, 'L2-SRV-003, §6.4: GET-only public read surface');
        foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
            $mutation = $service->authority($method, Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770001800);
            self::assertSame(405, $mutation->status, 'L2-SRV-003/§6.4/§16: no public Record submission endpoint exists on the read surface');
        }
        $getAgain = $service->authority('GET', Stage2TestSupport::ANCHOR_ID, ['l' => '2'], 1770001800);
        self::assertSame($get->body, $getAgain->body, 'L2-SRV-003/read-only: repeated requests observe byte-identical responses');
        self::assertSame(7, $records->storedCount(Stage2TestSupport::ANCHOR_ID), 'L2-SRV-003: Record storage is unchanged by any public request');
        self::assertSame(1, $bindings->storeCount, 'L2-SRV-003: Trust Anchor Bindings arrive only via the policy/admin channel (§9.3/§16), never via the public endpoint');
    }
}
