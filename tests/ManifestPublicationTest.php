<?php
// tests/ManifestPublicationTest.php
// Manifest 公開4ワークフローと明示的 integrity pin の安全境界を検証する。

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Application\ApplicationException;
use Relink\Resolver\Application\ResolverService;
use Relink\Resolver\Domain\AnchorUuid;
use Relink\Resolver\Domain\DescriptionLocation;
use Relink\Resolver\Domain\FetchedRepresentation;
use Relink\Resolver\Domain\IntegrityMetadata;
use Relink\Resolver\Domain\LifecycleState;
use Relink\Resolver\Domain\ResolverHistoryEntry;
use Relink\Resolver\Domain\ResolverRecord;
use Relink\Resolver\Ports\AdministrativeResourceFetcher;
use Relink\Resolver\Ports\ManifestPublicationRepository;
use Relink\Resolver\Ports\ResolverRepository;

/** Manifest 公開更新の CAS を含むインメモリテストリポジトリ。 */
final class ManifestPublicationRepositoryFake implements ResolverRepository, ManifestPublicationRepository
{
    /** @var array<string, ResolverRecord> */
    private array $records = [];

    public function seed(ResolverRecord $record): void
    {
        $this->records[$record->anchor->value] = $record;
    }

    public function find(AnchorUuid $anchor): ?ResolverRecord
    {
        return $this->records[$anchor->value] ?? null;
    }

    public function insert(ResolverRecord $record): void
    {
        $this->records[$record->anchor->value] = $record;
    }

    public function update(ResolverRecord $record, DescriptionLocation $location, string $entityId, ?string $algorithm, ?string $digest): ResolverRecord
    {
        return $this->save(new ResolverRecord($record->anchor, $record->state, $location, $entityId, $record->mediaType, $algorithm, $digest, $record->version + 1, $record->manifestEnabled, null));
    }

    public function transition(ResolverRecord $record, LifecycleState $target, string $reason, string $actor): ResolverRecord
    {
        return $this->save(new ResolverRecord($record->anchor, $target, $record->location, $record->entityId, $record->mediaType, $record->integrityAlgorithm, $record->integrityDigest, $record->version + 1, $record->manifestEnabled, $record->integritySource));
    }

    public function updateManifestPublication(ResolverRecord $record, bool $enabled, ?IntegrityMetadata $integrity, ?string $source): ResolverRecord
    {
        $current = $this->find($record->anchor);
        if ($current === null || $current->version !== $record->version) {
            throw new RuntimeException('STATE_CONFLICT');
        }
        return $this->save(new ResolverRecord($record->anchor, $record->state, $record->location, $record->entityId, $record->mediaType, $integrity?->algorithm, $integrity?->digest, $record->version + 1, $enabled, $source));
    }

    public function publishIntegrity(ResolverRecord $record, IntegrityMetadata $integrity, string $source): ResolverRecord
    {
        $current = $this->find($record->anchor);
        if ($current === null || $current->version !== $record->version || $current->location->value !== $record->location->value || $current->state !== $record->state) {
            throw new RuntimeException('STATE_CONFLICT');
        }
        return $this->save(new ResolverRecord($record->anchor, $record->state, $record->location, $record->entityId, $record->mediaType, $integrity->algorithm, $integrity->digest, $record->version + 1, true, $source));
    }

    /** @return list<ResolverHistoryEntry> */
    public function history(AnchorUuid $anchor): array
    {
        return [];
    }

    private function save(ResolverRecord $record): ResolverRecord
    {
        $this->records[$record->anchor->value] = $record;
        return $record;
    }
}

/** ネットワークアクセスなしで取得結果を固定する fake fetcher。 */
final class CountingResourceFetcher implements AdministrativeResourceFetcher
{
    public int $calls = 0;

    public function __construct(private readonly FetchedRepresentation $result)
    {
    }

    public function fetch(DescriptionLocation $location): FetchedRepresentation
    {
        $this->calls++;
        return $this->result;
    }
}

final class ManifestPublicationTest extends TestCase
{
    public const UUID = '550e8400-e29b-41d4-a716-446655440200';

    /** Direct AR-XML は Manifest と fetcher に依存しないことを確認する。 */
    public function testDirectModeKeepsCoreResolutionAndDisablesManifest(): void
    {
        $repository = new ManifestPublicationRepositoryFake();
        $service = new ResolverService($repository);
        $record = $service->register([
            'uuid' => self::UUID,
            'location' => 'https://entity.example/direct.xml',
            'entity_id' => 'urn:relink:entity:200',
            'publication_mode' => 'direct',
        ]);

        self::assertFalse($record->manifestEnabled);
        self::assertSame(303, $service->resolve('GET', self::UUID, [])->status);
        self::assertNull($service->manifest(self::UUID));
    }

    /** supplied digest は検証・保存され、外部取得を発生させないことを確認する。 */
    public function testSuppliedIntegrityIsPublishedWithoutFetching(): void
    {
        $repository = new ManifestPublicationRepositoryFake();
        $service = new ResolverService($repository);
        $digest = str_repeat('a', 64);
        $record = $service->register([
            'uuid' => self::UUID,
            'location' => 'https://entity.example/supplied.xml',
            'entity_id' => 'urn:relink:entity:201',
            'publication_mode' => 'supplied',
            'integrity_algorithm' => 'sha-256',
            'integrity_digest' => $digest,
        ]);
        $fetcher = new CountingResourceFetcher(new FetchedRepresentation('https://entity.example/supplied.xml', 200, 'unused'));

        self::assertSame($digest, $service->manifest(self::UUID)['description']['integrity']['digest']);
        self::assertSame('SUPPLIED', $record->integritySource);
        self::assertSame(0, $fetcher->calls);
    }

    /** integrity なしの Manifest は有効であり、digest を暗黙生成しないことを確認する。 */
    public function testManifestWithoutIntegrityIsValid(): void
    {
        $repository = new ManifestPublicationRepositoryFake();
        $service = new ResolverService($repository);
        $service->register([
            'uuid' => self::UUID,
            'location' => 'https://entity.example/no-integrity.xml',
            'entity_id' => 'urn:relink:entity:202',
            'publication_mode' => 'without-integrity',
        ]);

        $manifest = $service->manifest(self::UUID);
        self::assertArrayNotHasKey('integrity', $manifest['description']);
    }

    /** 一度だけ計算した digest が body octets から生成されることを確認する。 */
    public function testExplicitCalculationPinsBodyDigest(): void
    {
        $repository = new ManifestPublicationRepositoryFake();
        $service = new ResolverService($repository);
        $service->register(['uuid' => self::UUID, 'location' => 'https://entity.example/calculated.xml', 'entity_id' => 'urn:relink:entity:203']);
        $fetcher = new CountingResourceFetcher(new FetchedRepresentation('https://entity.example/calculated.xml', 200, "\x00binary\xff"));

        $record = $service->calculateAndPinIntegrity(self::UUID, $fetcher);

        self::assertSame(1, $fetcher->calls);
        self::assertSame(hash('sha256', "\x00binary\xff"), $record->integrityDigest);
        self::assertSame('CALCULATED', $record->integritySource);
        self::assertSame($record->integrityDigest, $service->manifest(self::UUID)['description']['integrity']['digest']);
    }

    /** stale な Location/version へ計算結果を commit できないことを確認する。 */
    public function testStaleCalculationCannotPublishDigest(): void
    {
        $repository = new ManifestPublicationRepositoryFake();
        $service = new ResolverService($repository);
        $service->register(['uuid' => self::UUID, 'location' => 'https://entity.example/old.xml', 'entity_id' => 'urn:relink:entity:204']);
        $fetcher = new class($service) implements AdministrativeResourceFetcher {
            public function __construct(private readonly ResolverService $service) {}

            public function fetch(DescriptionLocation $location): FetchedRepresentation
            {
                $this->service->updateLocation(ManifestPublicationTest::UUID, 'https://entity.example/new.xml');
                return new FetchedRepresentation($location->value, 200, 'old body');
            }
        };

        try {
            $service->calculateAndPinIntegrity(self::UUID, $fetcher);
            self::fail('stale conflict が発生しませんでした。');
        } catch (ApplicationException $error) {
            self::assertSame('STATE_CONFLICT', $error->errorCode, $error->getPrevious()?->getMessage() ?? $error->getMessage());
        }
    }

    /** Location 更新後は旧 digest を保持せず、再計算も自動実行しないことを確認する。 */
    public function testLocationChangeClearsDigestWithoutAutomaticRefresh(): void
    {
        $repository = new ManifestPublicationRepositoryFake();
        $service = new ResolverService($repository);
        $service->register(['uuid' => self::UUID, 'location' => 'https://entity.example/old.xml', 'entity_id' => 'urn:relink:entity:205', 'integrity_algorithm' => 'sha-256', 'integrity_digest' => str_repeat('b', 64)]);
        $updated = $service->updateLocation(self::UUID, 'https://entity.example/new.xml');

        self::assertNull($updated->integrityDigest);
        self::assertArrayNotHasKey('integrity', $service->manifest(self::UUID)['description']);
    }
}
