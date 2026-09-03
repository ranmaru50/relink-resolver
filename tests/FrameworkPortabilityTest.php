<?php
// tests/FrameworkPortabilityTest.php
// Web フレームワークを起動せず Resolver Engine を構成できることを検証する。

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Application\ResolverService;
use Relink\Resolver\Domain\AnchorUuid;
use Relink\Resolver\Domain\DescriptionLocation;
use Relink\Resolver\Domain\LifecycleState;
use Relink\Resolver\Domain\ResolverHistoryEntry;
use Relink\Resolver\Domain\ResolverRecord;
use Relink\Resolver\Ports\ResolverRepository;

final class PortableResolverRepository implements ResolverRepository
{
    /** @var list<ResolverRecord> */
    private array $records;

    /** @param list<ResolverRecord> $records */
    public function __construct(array $records)
    {
        $this->records = $records;
    }

    public function find(AnchorUuid $anchor): ?ResolverRecord
    {
        foreach ($this->records as $record) {
            if ($record->anchor->value === $anchor->value) {
                return $record;
            }
        }
        return null;
    }

    public function insert(ResolverRecord $record): void
    {
        $this->records[] = $record;
    }

    public function update(ResolverRecord $record, DescriptionLocation $location, string $entityId, ?string $algorithm, ?string $digest): ResolverRecord
    {
        return new ResolverRecord($record->anchor, $record->state, $location, $entityId, $record->mediaType, $algorithm, $digest, $record->version + 1);
    }

    public function transition(ResolverRecord $record, LifecycleState $target, string $reason, string $actor): ResolverRecord
    {
        return new ResolverRecord($record->anchor, $target, $record->location, $record->entityId, $record->mediaType, $record->integrityAlgorithm, $record->integrityDigest, $record->version + 1);
    }

    /** @return list<ResolverHistoryEntry> */
    public function history(AnchorUuid $anchor): array
    {
        return [];
    }
}

final class FrameworkPortabilityTest extends TestCase
{
    /** Apache、PHP セッション、ORM を起動せずにアプリケーション境界を利用できることを確認する。 */
    public function testResolverEngineUsesOnlyResolverOwnedPortAndDomainValues(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440040';
        $repository = new PortableResolverRepository([new ResolverRecord(
            new AnchorUuid($uuid),
            LifecycleState::ACTIVE,
            new DescriptionLocation('https://entity.example/40.xml'),
            'urn:relink:entity:40',
            null,
            null,
            null,
            1,
        )]);
        $service = new ResolverService($repository);

        self::assertSame(303, $service->resolve('GET', $uuid, [])->status);
        self::assertSame($uuid, $service->findRecord($uuid)->anchor->value);
        self::assertSame([], $service->history($uuid));
    }
}
