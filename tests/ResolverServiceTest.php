<?php
// tests/ResolverServiceTest.php
// 外部 HTTP に依存しない Resolver の規範動作を PHPUnit で検証する。

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Application\ResolverService;
use Relink\Resolver\Domain\AnchorUuid;
use Relink\Resolver\Domain\DescriptionLocation;
use Relink\Resolver\Domain\LifecycleState;
use Relink\Resolver\Domain\ResolverRecord;
use Relink\Resolver\Ports\ResolverRepository;

final class MemoryResolverRepository implements ResolverRepository
{
    /** @var array<string, ResolverRecord> */
    private array $records = [];

    public function find(AnchorUuid $anchor): ?ResolverRecord { return $this->records[$anchor->value] ?? null; }
    public function insert(ResolverRecord $record): void { $this->records[$record->anchor->value] = $record; }
    public function update(ResolverRecord $record, DescriptionLocation $location, string $entityId, ?string $algorithm, ?string $digest): ResolverRecord
    {
        $updated = new ResolverRecord($record->anchor, $record->state, $location, $entityId, $record->mediaType, $algorithm, $digest, $record->version + 1);
        $this->records[$record->anchor->value] = $updated;
        return $updated;
    }
    public function transition(ResolverRecord $record, LifecycleState $target, string $reason, string $actor): ResolverRecord
    {
        $updated = new ResolverRecord($record->anchor, $target, $record->location, $record->entityId, $record->mediaType, $record->integrityAlgorithm, $record->integrityDigest, $record->version + 1);
        $this->records[$record->anchor->value] = $updated;
        return $updated;
    }
    public function history(AnchorUuid $anchor): array { return []; }
}

final class ResolverServiceTest extends TestCase
{
    private function makeService(): ResolverService
    {
        $repository = new MemoryResolverRepository();
        $repository->insert(new ResolverRecord(new AnchorUuid('550e8400-e29b-41d4-a716-446655440000'), LifecycleState::ACTIVE, new DescriptionLocation('https://entity.example/arxml.xml'), 'https://identity.example/entity/1', null, null, null, 1));
        $repository->insert(new ResolverRecord(new AnchorUuid('550e8400-e29b-41d4-a716-446655440001'), LifecycleState::SUSPENDED, new DescriptionLocation('https://entity.example/arxml.xml'), 'https://identity.example/entity/2', null, null, null, 1));
        $repository->insert(new ResolverRecord(new AnchorUuid('550e8400-e29b-41d4-a716-446655440002'), LifecycleState::RETIRED, new DescriptionLocation('https://entity.example/arxml.xml'), 'https://identity.example/entity/3', null, null, null, 1));
        return new ResolverService($repository);
    }

    public function testResolutionAndManifestMapping(): void
    {
        $service = $this->makeService();
        $active = $service->resolve('GET', '550E8400-E29B-41D4-A716-446655440000', []);
        $this->assertSame(303, $active->status);
        $this->assertSame('https://entity.example/arxml.xml', $active->headers['Location']);
        $this->assertSame(404, $service->resolve('GET', '550e8400-e29b-41d4-a716-446655440001', [])->status);
        $this->assertSame(410, $service->resolve('GET', '550e8400-e29b-41d4-a716-446655440002', [])->status);
        $this->assertSame(405, $service->resolve('POST', '550e8400-e29b-41d4-a716-446655440000', [])->status);
        $this->assertSame(405, $service->resolve('POST', '550e8400-e29b-41d4-a716-446655440099', [])->status);
        $this->assertSame(400, $service->resolve('GET', 'not-a-uuid', [])->status);
        $this->assertSame(501, $service->resolve('GET', '550e8400-e29b-41d4-a716-446655440000', ['l' => '2'])->status);
        $this->assertSame(400, $service->resolve('GET', '550e8400-e29b-41d4-a716-446655440000', ['p' => 'x'])->status);
        $manifest = $service->manifest('550e8400-e29b-41d4-a716-446655440000');
        $this->assertSame('0.1', $manifest['manifestVersion']);
        $this->assertSame('active', $manifest['lifecycle']['status']);
    }

    public function testLocationUpdateClearsStaleIntegrityAndLifecycleTransitions(): void
    {
        $service = new ResolverService(new MemoryResolverRepository());
        $registered = $service->register([
            'uuid' => '550e8400-e29b-41d4-a716-446655440010',
            'location' => 'https://entity.example/arxml.xml',
            'entity_id' => 'urn:relink:entity:10',
            'integrity_algorithm' => 'sha-256',
            'integrity_digest' => str_repeat('a', 64),
        ]);
        $this->assertSame(LifecycleState::ACTIVE, $registered->state);
        $this->assertNotNull($registered->integrityDigest);
        $updated = $service->updateLocation($registered->anchor->value, 'https://entity.example/new-arxml.xml');
        $this->assertNull($updated->integrityAlgorithm);
        $this->assertNull($updated->integrityDigest);
        $this->assertNull($service->manifest($registered->anchor->value)['description']['integrity'] ?? null);
        $this->assertSame(LifecycleState::SUSPENDED, $service->transition($registered->anchor->value, 'SUSPENDED')->state);
        $this->assertSame(LifecycleState::RETIRED, $service->transition($registered->anchor->value, 'RETIRED')->state);
        $this->expectExceptionMessage('INVALID_TRANSITION');
        $service->transition($registered->anchor->value, 'ACTIVE');
    }
}
