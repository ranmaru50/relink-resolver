<?php
// tests/SqliteResolverRepositoryTest.php
// 明示的マイグレーション、SQLite transaction、履歴保持を PHPUnit で検証する。

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Adapters\SqliteMigrator;
use Relink\Resolver\Adapters\SqliteResolverRepository;
use Relink\Resolver\Application\ApplicationException;
use Relink\Resolver\Application\ResolverService;
use Relink\Resolver\Domain\AnchorUuid;
use Relink\Resolver\Domain\DescriptionLocation;
use Relink\Resolver\Domain\LifecycleState;

final class SqliteResolverRepositoryTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'relink-test-');
        $this->assertNotFalse($path);
        $this->path = $path;
        unlink($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-journal');
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');
    }

    public function testMigrationHistoryAndOptimisticConflict(): void
    {
        SqliteMigrator::migrate($this->path);
        $repository = new SqliteResolverRepository($this->path);
        $service = new ResolverService($repository);
        $uuid = '550e8400-e29b-41d4-a716-446655440020';
        $service->register(['uuid' => $uuid, 'location' => 'https://entity.example/20.xml', 'entity_id' => 'urn:relink:entity:20']);
        $service->transition($uuid, 'SUSPENDED', 'test');
        $history = $repository->history(new AnchorUuid($uuid));
        $this->assertCount(1, $history);
        $this->assertSame('lifecycle_transition', $history[0]['event_type']);
        $stale = $repository->find(new AnchorUuid($uuid));
        $service->transition($uuid, 'ACTIVE', 'test');
        $this->expectExceptionMessage('STATE_CONFLICT');
        $repository->transition($stale, LifecycleState::RETIRED, 'stale', 'test');
    }

    /** マイグレーションを繰り返してもスキーマが壊れないことを確認する。 */
    public function testMigrationIsIdempotent(): void
    {
        SqliteMigrator::migrate($this->path);
        SqliteMigrator::migrate($this->path);
        $service = new ResolverService(new SqliteResolverRepository($this->path));

        $record = $service->register([
            'uuid' => '550e8400-e29b-41d4-a716-446655440021',
            'location' => 'https://entity.example/21.xml',
            'entity_id' => 'urn:relink:entity:21',
        ]);

        $this->assertSame(1, $record->version);
    }

    /** Location 更新が現在値、整合性情報、履歴を一つの操作で更新することを確認する。 */
    public function testLocationUpdatePersistsNewValueAndHistory(): void
    {
        SqliteMigrator::migrate($this->path);
        $repository = new SqliteResolverRepository($this->path);
        $service = new ResolverService($repository);
        $uuid = '550e8400-e29b-41d4-a716-446655440022';
        $service->register([
            'uuid' => $uuid,
            'location' => 'https://entity.example/22.xml',
            'entity_id' => 'urn:relink:entity:22',
            'integrity_algorithm' => 'sha-256',
            'integrity_digest' => str_repeat('c', 64),
        ]);

        $updated = $service->updateLocation($uuid, 'https://entity.example/22-new.xml', 'urn:relink:entity:22-new');
        $stored = $repository->find(new AnchorUuid($uuid));
        $history = $repository->history(new AnchorUuid($uuid));

        $this->assertSame(2, $updated->version);
        $this->assertSame('https://entity.example/22-new.xml', $stored->location->value);
        $this->assertSame('urn:relink:entity:22-new', $stored->entityId);
        $this->assertNull($stored->integrityDigest);
        $this->assertCount(1, $history);
        $this->assertSame('mapping_update', $history[0]['event_type']);
        $this->assertSame('https://entity.example/22.xml', $history[0]['old_location']);
        $this->assertSame('https://entity.example/22-new.xml', $history[0]['new_location']);
    }

    /** 同一状態指定がバージョンと履歴を増やさないことを確認する。 */
    public function testSameStateTransitionIsNoOpWithoutHistory(): void
    {
        SqliteMigrator::migrate($this->path);
        $repository = new SqliteResolverRepository($this->path);
        $service = new ResolverService($repository);
        $uuid = '550e8400-e29b-41d4-a716-446655440023';
        $service->register(['uuid' => $uuid, 'location' => 'https://entity.example/23.xml', 'entity_id' => 'urn:relink:entity:23']);

        $record = $service->transition($uuid, 'ACTIVE', 'same state', 'tester');

        $this->assertSame(1, $record->version);
        $this->assertSame([], $repository->history(new AnchorUuid($uuid)));
    }

    /** 登録済み UUID の重複を安全なエラーコードへ変換することを確認する。 */
    public function testDuplicateRegistrationReturnsRecordExistsError(): void
    {
        SqliteMigrator::migrate($this->path);
        $service = new ResolverService(new SqliteResolverRepository($this->path));
        $input = ['uuid' => '550e8400-e29b-41d4-a716-446655440024', 'location' => 'https://entity.example/24.xml', 'entity_id' => 'urn:relink:entity:24'];
        $service->register($input);

        try {
            $service->register($input);
            $this->fail('重複登録が拒否されませんでした。');
        } catch (ApplicationException $error) {
            $this->assertSame('RECORD_EXISTS', $error->errorCode);
        }
    }

    /** スキーマ欠落などの永続化障害を UUID 重複と誤分類しないことを確認する。 */
    public function testMissingTableReturnsPersistenceFailure(): void
    {
        $repository = new SqliteResolverRepository($this->path);
        $record = new \Relink\Resolver\Domain\ResolverRecord(
            new AnchorUuid('550e8400-e29b-41d4-a716-446655440030'),
            LifecycleState::ACTIVE,
            new DescriptionLocation('https://entity.example/30.xml'),
            'urn:relink:entity:30',
            null,
            null,
            null,
            1,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PERSISTENCE_FAILURE');
        $repository->insert($record);
    }

    /** SQLite の読み取り専用接続を重複登録と誤分類しないことを確認する。 */
    public function testReadOnlyDatabaseReturnsPersistenceFailure(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('SQLite URI の読み取り専用検証は Container profile で実行します。');
        }
        SqliteMigrator::migrate($this->path);
        $readOnlyPath = 'file:' . $this->path . '?mode=ro';
        $repository = new SqliteResolverRepository($readOnlyPath);
        $record = new \Relink\Resolver\Domain\ResolverRecord(
            new AnchorUuid('550e8400-e29b-41d4-a716-446655440031'),
            LifecycleState::ACTIVE,
            new DescriptionLocation('https://entity.example/31.xml'),
            'urn:relink:entity:31',
            null,
            null,
            null,
            1,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PERSISTENCE_FAILURE');
        $repository->insert($record);
    }

    /** 遷移履歴の状態、理由、実行者を保持し、長さを制限することを確認する。 */
    public function testTransitionHistoryPreservesStateAndBoundsMetadata(): void
    {
        SqliteMigrator::migrate($this->path);
        $repository = new SqliteResolverRepository($this->path);
        $service = new ResolverService($repository);
        $uuid = '550e8400-e29b-41d4-a716-446655440025';
        $service->register(['uuid' => $uuid, 'location' => 'https://entity.example/25.xml', 'entity_id' => 'urn:relink:entity:25']);

        $service->transition($uuid, 'SUSPENDED', str_repeat('理由', 400), str_repeat('actor', 100));
        $event = $repository->history(new AnchorUuid($uuid))[0];

        $this->assertSame('ACTIVE', $event['old_state']);
        $this->assertSame('SUSPENDED', $event['new_state']);
        $this->assertSame(500, strlen($event['reason']));
        $this->assertSame(200, strlen($event['actor']));
    }

    /** stale なレコードによる Location 更新を競合として拒否することを確認する。 */
    public function testStaleLocationUpdateIsRejected(): void
    {
        SqliteMigrator::migrate($this->path);
        $repository = new SqliteResolverRepository($this->path);
        $service = new ResolverService($repository);
        $uuid = '550e8400-e29b-41d4-a716-446655440026';
        $service->register(['uuid' => $uuid, 'location' => 'https://entity.example/26.xml', 'entity_id' => 'urn:relink:entity:26']);
        $stale = $repository->find(new AnchorUuid($uuid));
        $service->updateLocation($uuid, 'https://entity.example/26-new.xml');

        $this->expectExceptionMessage('STATE_CONFLICT');
        $repository->update($stale, new DescriptionLocation('https://entity.example/26-stale.xml'), 'urn:relink:entity:26', null, null);
    }

    /** all() が UUID の昇順で返ることを確認する。 */
    public function testAllReturnsRecordsInStableUuidOrder(): void
    {
        SqliteMigrator::migrate($this->path);
        $service = new ResolverService(new SqliteResolverRepository($this->path));
        foreach ([
            ['550e8400-e29b-41d4-a716-446655440029', '29'],
            ['550e8400-e29b-41d4-a716-446655440027', '27'],
            ['550e8400-e29b-41d4-a716-446655440028', '28'],
        ] as [$uuid, $suffix]) {
            $service->register(['uuid' => $uuid, 'location' => 'https://entity.example/' . $suffix . '.xml', 'entity_id' => 'urn:relink:entity:' . $suffix]);
        }

        $records = (new SqliteResolverRepository($this->path))->all();

        $this->assertSame([
            '550e8400-e29b-41d4-a716-446655440027',
            '550e8400-e29b-41d4-a716-446655440028',
            '550e8400-e29b-41d4-a716-446655440029',
        ], array_map(static fn ($record): string => $record->anchor->value, $records));
    }
}
