<?php
// tests/ResolverServiceEdgeCaseTest.php
// ResolverService の入力検証、エラー境界、全ライフサイクル遷移を検証する。

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Application\ApplicationException;
use Relink\Resolver\Application\ResolverService;
use Relink\Resolver\Domain\AnchorUuid;
use Relink\Resolver\Domain\DescriptionLocation;
use Relink\Resolver\Domain\LifecycleState;
use Relink\Resolver\Domain\ResolverRecord;
use Relink\Resolver\Ports\ResolverRepository;

/** テストで操作回数と永続化エラーを制御するインメモリリポジトリ。 */
final class ServiceEdgeCaseRepository implements ResolverRepository
{
    /** @var array<string, ResolverRecord> */
    private array $records = [];

    /** @var list<array<string, mixed>> */
    private array $events = [];

    public int $findCalls = 0;
    public int $updateCalls = 0;
    public int $transitionCalls = 0;
    public ?string $failureOperation = null;

    /** テスト用の初期レコードを登録する。 */
    public function seed(ResolverRecord $record): void
    {
        $this->records[$record->anchor->value] = $record;
    }

    public function find(AnchorUuid $anchor): ?ResolverRecord
    {
        $this->findCalls++;
        $this->throwIfConfigured('find');

        return $this->records[$anchor->value] ?? null;
    }

    public function insert(ResolverRecord $record): void
    {
        $this->throwIfConfigured('insert');
        if (isset($this->records[$record->anchor->value])) {
            throw new RuntimeException('RECORD_EXISTS');
        }
        $this->records[$record->anchor->value] = $record;
    }

    public function update(ResolverRecord $record, DescriptionLocation $location, string $entityId, ?string $algorithm, ?string $digest): ResolverRecord
    {
        $this->updateCalls++;
        $this->throwIfConfigured('update');
        $updated = new ResolverRecord($record->anchor, $record->state, $location, $entityId, $record->mediaType, $algorithm, $digest, $record->version + 1);
        $this->records[$record->anchor->value] = $updated;
        return $updated;
    }

    public function transition(ResolverRecord $record, LifecycleState $target, string $reason, string $actor): ResolverRecord
    {
        $this->transitionCalls++;
        $this->throwIfConfigured('transition');
        $updated = new ResolverRecord($record->anchor, $target, $record->location, $record->entityId, $record->mediaType, $record->integrityAlgorithm, $record->integrityDigest, $record->version + 1);
        $this->records[$record->anchor->value] = $updated;
        $this->events[] = ['old_state' => $record->state->value, 'new_state' => $target->value, 'reason' => $reason, 'actor' => $actor];
        return $updated;
    }

    public function history(AnchorUuid $anchor): array
    {
        return $this->events;
    }

    /** 指定された操作だけを永続化エラーにする。 */
    private function throwIfConfigured(string $operation): void
    {
        if ($this->failureOperation !== $operation) {
            return;
        }
        throw new \RuntimeException($operation === 'update' || $operation === 'transition' ? 'STATE_CONFLICT' : 'storage unavailable');
    }
}

final class ResolverServiceEdgeCaseTest extends TestCase
{
    private const UUID = '550e8400-e29b-41d4-a716-446655440100';

    /** 全ての許可された遷移と、同一状態の no-op を確認する。 */
    public function testAllPermittedLifecycleTransitionsAndSameStateNoOp(): void
    {
        $repository = new ServiceEdgeCaseRepository();
        $service = new ResolverService($repository);
        $service->register(['uuid' => self::UUID, 'location' => 'https://entity.example/100.xml', 'entity_id' => 'urn:relink:entity:100']);

        $same = $service->transition(self::UUID, 'ACTIVE');
        $this->assertSame(1, $same->version);
        $this->assertSame(0, $repository->transitionCalls);
        $this->assertSame(LifecycleState::SUSPENDED, $service->transition(self::UUID, 'SUSPENDED')->state);
        $this->assertSame(LifecycleState::ACTIVE, $service->transition(self::UUID, 'ACTIVE')->state);
        $this->assertSame(LifecycleState::RETIRED, $service->transition(self::UUID, 'RETIRED')->state);

        $this->expectExceptionMessage('INVALID_TRANSITION');
        $service->transition(self::UUID, 'ACTIVE');
    }

    /** SUSPENDED からの両方の許可遷移を確認する。 */
    public function testSuspendedRecordCanReactivateOrRetire(): void
    {
        $repository = new ServiceEdgeCaseRepository();
        $service = new ResolverService($repository);
        $uuid = '550e8400-e29b-41d4-a716-446655440101';
        $service->register(['uuid' => $uuid, 'state' => 'SUSPENDED', 'location' => 'https://entity.example/101.xml', 'entity_id' => 'urn:relink:entity:101']);

        $this->assertSame(LifecycleState::ACTIVE, $service->transition($uuid, 'ACTIVE')->state);
        $this->assertSame(LifecycleState::SUSPENDED, $service->transition($uuid, 'SUSPENDED')->state);
        $this->assertSame(LifecycleState::RETIRED, $service->transition($uuid, 'RETIRED')->state);
    }

    /** Manifest に任意メディア型と整合性情報が反映されることを確認する。 */
    public function testManifestIncludesOptionalDescriptionFields(): void
    {
        $service = new ResolverService(new ServiceEdgeCaseRepository());
        $uuid = '550e8400-e29b-41d4-a716-446655440102';
        $service->register([
            'uuid' => $uuid,
            'location' => 'https://entity.example/102.xml',
            'entity_id' => 'urn:relink:entity:102',
            'media_type' => 'application/xml',
            'integrity_algorithm' => 'sha-256',
            'integrity_digest' => str_repeat('b', 64),
        ]);

        $manifest = $service->manifest($uuid);
        $this->assertSame('application/xml', $manifest['description']['mediaType']);
        $this->assertSame(['algorithm' => 'sha-256', 'digest' => str_repeat('b', 64)], $manifest['description']['integrity']);
    }

    /** リポジトリにない UUID の Manifest は null になることを確認する。 */
    public function testManifestReturnsNullForUnknownRecord(): void
    {
        $service = new ResolverService(new ServiceEdgeCaseRepository());

        $this->assertNull($service->manifest('550e8400-e29b-41d4-a716-446655440103'));
    }

    /** 登録時に整合性情報を一部だけ指定できないことを確認する。 */
    public function testRegisterRejectsPartialIntegrity(): void
    {
        $service = new ResolverService(new ServiceEdgeCaseRepository());

        $this->expectException(\InvalidArgumentException::class);
        $service->register([
            'uuid' => self::UUID,
            'location' => 'https://entity.example/100.xml',
            'entity_id' => 'urn:relink:entity:100',
            'integrity_algorithm' => 'sha-256',
        ]);
    }

    /** sha-256 の桁数・文字種を検証する。 */
    public function testRegisterRejectsInvalidSha256Digest(): void
    {
        $service = new ResolverService(new ServiceEdgeCaseRepository());

        $this->expectException(\InvalidArgumentException::class);
        $service->register([
            'uuid' => self::UUID,
            'location' => 'https://entity.example/100.xml',
            'entity_id' => 'urn:relink:entity:100',
            'integrity_algorithm' => 'sha-256',
            'integrity_digest' => str_repeat('G', 64),
        ]);
    }

    /** Entity identity が絶対 URI でない場合を拒否する。 */
    public function testRegisterRejectsRelativeEntityId(): void
    {
        $service = new ResolverService(new ServiceEdgeCaseRepository());

        $this->expectException(\InvalidArgumentException::class);
        $service->register(['uuid' => self::UUID, 'location' => 'https://entity.example/100.xml', 'entity_id' => 'entity/100']);
    }

    /** 未対応メソッドではリポジトリ参照を行わないことを確認する。 */
    public function testUnsupportedMethodIsIndependentOfRepositoryState(): void
    {
        $repository = new ServiceEdgeCaseRepository();
        $repository->failureOperation = 'find';
        $service = new ResolverService($repository);

        $result = $service->resolve('POST', self::UUID, []);

        $this->assertSame(405, $result->status);
        $this->assertSame(['GET'], [$result->headers['Allow']]);
        $this->assertSame(0, $repository->findCalls);
    }

    /** リポジトリ障害を公開解決の 503 に変換する。 */
    public function testResolveMapsRepositoryFailureToServiceUnavailable(): void
    {
        $repository = new ServiceEdgeCaseRepository();
        $repository->failureOperation = 'find';

        $this->assertSame(503, (new ResolverService($repository))->resolve('GET', self::UUID, [])->status);
    }

    /** Manifest の永続化障害を安全なアプリケーション例外に変換する。 */
    public function testManifestMapsRepositoryFailureToApplicationException(): void
    {
        $repository = new ServiceEdgeCaseRepository();
        $repository->failureOperation = 'find';
        $service = new ResolverService($repository);

        try {
            $service->manifest(self::UUID);
            $this->fail('例外が発生しませんでした。');
        } catch (ApplicationException $error) {
            $this->assertSame('PERSISTENCE_FAILURE', $error->errorCode);
        }
    }

    /** 登録・更新・遷移の永続化障害コードを確認する。 */
    public function testAdministrativePersistenceFailuresKeepSafeErrorCodes(): void
    {
        $repository = new ServiceEdgeCaseRepository();
        $service = new ResolverService($repository);
        $service->register(['uuid' => self::UUID, 'location' => 'https://entity.example/100.xml', 'entity_id' => 'urn:relink:entity:100']);

        $repository->failureOperation = 'insert';
        try {
            $service->register(['uuid' => '550e8400-e29b-41d4-a716-446655440104', 'location' => 'https://entity.example/104.xml', 'entity_id' => 'urn:relink:entity:104']);
            $this->fail('登録障害が発生しませんでした。');
        } catch (ApplicationException $error) {
            $this->assertSame('PERSISTENCE_FAILURE', $error->errorCode);
        }

        $repository->failureOperation = 'update';
        try {
            $service->updateLocation(self::UUID, 'https://entity.example/100-new.xml');
            $this->fail('更新競合が発生しませんでした。');
        } catch (ApplicationException $error) {
            $this->assertSame('STATE_CONFLICT', $error->errorCode);
        }

        $repository->failureOperation = 'transition';
        try {
            $service->transition(self::UUID, 'SUSPENDED');
            $this->fail('遷移競合が発生しませんでした。');
        } catch (ApplicationException $error) {
            $this->assertSame('STATE_CONFLICT', $error->errorCode);
        }
    }
}
