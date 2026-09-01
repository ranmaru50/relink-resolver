<?php
// tests/ResolverServiceTest.php
// 外部 HTTP に依存しない Resolver の規範動作テスト。

declare(strict_types=1);

use Relink\Resolver\Application\ResolverService;
use Relink\Resolver\Domain\AnchorUuid;
use Relink\Resolver\Domain\DescriptionLocation;
use Relink\Resolver\Domain\LifecycleState;
use Relink\Resolver\Domain\ResolverRecord;
use Relink\Resolver\Ports\ResolverRepository;

require_once dirname(__DIR__) . '/bootstrap.php';

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
    public function all(): array { return array_values($this->records); }
    public function history(AnchorUuid $anchor): array { return []; }
}

function makeService(): ResolverService
{
    $repository = new MemoryResolverRepository();
    $repository->insert(new ResolverRecord(new AnchorUuid('550e8400-e29b-41d4-a716-446655440000'), LifecycleState::ACTIVE, new DescriptionLocation('https://entity.example/arxml.xml'), 'https://identity.example/entity/1', null, null, null, 1));
    $repository->insert(new ResolverRecord(new AnchorUuid('550e8400-e29b-41d4-a716-446655440001'), LifecycleState::SUSPENDED, new DescriptionLocation('https://entity.example/arxml.xml'), 'https://identity.example/entity/2', null, null, null, 1));
    $repository->insert(new ResolverRecord(new AnchorUuid('550e8400-e29b-41d4-a716-446655440002'), LifecycleState::RETIRED, new DescriptionLocation('https://entity.example/arxml.xml'), 'https://identity.example/entity/3', null, null, null, 1));
    return new ResolverService($repository);
}

return static function (): void {
    $service = makeService();
    $active = $service->resolve('GET', '550E8400-E29B-41D4-A716-446655440000', []);
    assert($active->status === 303);
    assert($active->headers['Location'] === 'https://entity.example/arxml.xml');
    assert($service->resolve('GET', '550e8400-e29b-41d4-a716-446655440001', [])->status === 404);
    assert($service->resolve('GET', '550e8400-e29b-41d4-a716-446655440002', [])->status === 410);
    assert($service->resolve('POST', '550e8400-e29b-41d4-a716-446655440000', [])->status === 405);
    assert($service->resolve('POST', '550e8400-e29b-41d4-a716-446655440099', [])->status === 405);
    assert($service->resolve('GET', 'not-a-uuid', [])->status === 400);
    assert($service->resolve('GET', '550e8400-e29b-41d4-a716-446655440000', ['l' => '2'])->status === 501);
    assert($service->resolve('GET', '550e8400-e29b-41d4-a716-446655440000', ['p' => 'x'])->status === 400);
    $manifest = $service->manifest('550e8400-e29b-41d4-a716-446655440000');
    assert($manifest['manifestVersion'] === '0.1');
    assert($manifest['lifecycle']['status'] === 'active');
    $repository = new MemoryResolverRepository();
    $service = new ResolverService($repository);
    $registered = $service->register([
        'uuid' => '550e8400-e29b-41d4-a716-446655440010',
        'location' => 'https://entity.example/arxml.xml',
        'entity_id' => 'urn:relink:entity:10',
        'integrity_algorithm' => 'sha-256',
        'integrity_digest' => str_repeat('a', 64),
    ]);
    assert($registered->state === LifecycleState::ACTIVE);
    assert($service->transition($registered->anchor->value, 'SUSPENDED')->state === LifecycleState::SUSPENDED);
    assert($service->transition($registered->anchor->value, 'RETIRED')->state === LifecycleState::RETIRED);
    try {
        $service->transition($registered->anchor->value, 'ACTIVE');
        assert(false);
    } catch (RuntimeException $error) {
        assert($error->getMessage() === 'INVALID_TRANSITION');
    }
};
