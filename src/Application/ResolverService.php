<?php
// src/Application/ResolverService.php
// 公開 Resolver と管理操作のアプリケーションサービス。

declare(strict_types=1);

namespace Relink\Resolver\Application;

use Relink\Resolver\Domain\AnchorUuid;
use Relink\Resolver\Domain\DescriptionLocation;
use Relink\Resolver\Domain\LifecycleState;
use Relink\Resolver\Domain\IntegrityMetadata;
use Relink\Resolver\Domain\ResolutionResult;
use Relink\Resolver\Domain\ResolverRecord;
use Relink\Resolver\Domain\ResolverHistoryEntry;
use Relink\Resolver\Ports\AdministrativeResourceFetcher;
use Relink\Resolver\Ports\ManifestPublicationRepository;
use Relink\Resolver\Ports\ResolverRepository;
use RuntimeException;

final class ResolverService
{
    public function __construct(private ResolverRepository $repository, private int $cacheMaxAge = 60)
    {
    }

    /** @param array<string, mixed> $query */
    public function resolve(string $method, string $uuid, array $query): ResolutionResult
    {
        try {
            $anchor = new AnchorUuid($uuid);
        } catch (\InvalidArgumentException) {
            return ResolutionResult::error(400);
        }

        // Core 仕様に従い、登録状態の参照より先にメソッドを判定する。
        if (strtoupper($method) !== 'GET') {
            return new ResolutionResult(405, ['Allow' => 'GET', 'Cache-Control' => 'no-store']);
        }
        if (array_key_exists('l', $query)) {
            return ResolutionResult::error(501);
        }
        if (array_key_exists('p', $query)) {
            return ResolutionResult::error(400);
        }

        try {
            $record = $this->repository->find($anchor);
        } catch (\Throwable) {
            return ResolutionResult::error(503);
        }
        if ($record === null || $record->state === LifecycleState::SUSPENDED) {
            return ResolutionResult::error(404);
        }
        if ($record->state === LifecycleState::RETIRED) {
            return ResolutionResult::error(410);
        }

        try {
            return ResolutionResult::redirect((new DescriptionLocation($record->location->value))->value, $this->cacheMaxAge);
        } catch (\InvalidArgumentException) {
            return ResolutionResult::error(500);
        }
    }

    /** @return array<string, mixed>|null */
    public function manifest(string $uuid): ?array
    {
        try {
            $record = $this->repository->find(new AnchorUuid($uuid));
        } catch (\Throwable $error) {
            throw new ApplicationException('PERSISTENCE_FAILURE', 'Manifest persistence failure', 0, $error);
        }
        if ($record === null) {
            return null;
        }
        if (!$record->manifestEnabled) {
            return null;
        }
        return [
            'manifestVersion' => '0.1',
            'anchor' => ['id' => $record->anchor->value],
            'entity' => ['id' => $record->entityId],
            'description' => array_filter([
                'location' => $record->location->value,
                'mediaType' => $record->mediaType,
                'integrity' => $record->integrityAlgorithm !== null && $record->integrityDigest !== null ? [
                    'algorithm' => $record->integrityAlgorithm,
                    'digest' => $record->integrityDigest,
                ] : null,
            ], static fn ($value): bool => $value !== null),
            'lifecycle' => ['status' => $record->state->manifestValue()],
        ];
    }

    /**
     * Manifest 公開設定を管理者の明示操作で更新する。
     * integrity は supplied の場合だけ渡し、calculated は別の pin 操作で処理する。
     */
    public function configureManifest(string $uuid, string $mode, ?string $algorithm = null, ?string $digest = null): ResolverRecord
    {
        $record = $this->requireRecord($uuid);
        $publisher = $this->publicationRepository();
        $mode = strtolower(trim($mode));
        $integrity = null;
        $source = null;
        if (in_array($mode, ['direct', 'no-manifest'], true)) {
            $enabled = false;
        } elseif (in_array($mode, ['without-integrity', 'manifest-without-integrity', 'none'], true)) {
            $enabled = true;
        } elseif (in_array($mode, ['supplied', 'with-supplied-integrity'], true)) {
            $enabled = true;
            $integrity = $this->makeIntegrity($algorithm, $digest);
            $source = 'SUPPLIED';
        } elseif (in_array($mode, ['calculated', 'with-calculated-integrity'], true)) {
            // 計算はこの設定操作では行わず、calculateAndPinIntegrity の明示操作で行う。
            $enabled = true;
            if ($algorithm !== null || $digest !== null) {
                throw new \InvalidArgumentException('Integrity is only accepted in supplied mode');
            }
        } else {
            throw new \InvalidArgumentException('Invalid Manifest publication mode');
        }
        if ($integrity === null && ($algorithm !== null || $digest !== null) && !in_array($mode, ['supplied', 'with-supplied-integrity'], true)) {
            throw new \InvalidArgumentException('Integrity is only accepted in supplied mode');
        }

        try {
            return $publisher->updateManifestPublication($record, $enabled, $integrity, $source);
        } catch (ApplicationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            if ($error->getMessage() === 'STATE_CONFLICT') {
                throw new ApplicationException('STATE_CONFLICT', 'Record was changed concurrently', 0, $error);
            }
            throw new ApplicationException('PERSISTENCE_FAILURE', 'Manifest publication update failed', 0, $error);
        }
    }

    /**
     * 現在の Location の表現を一度だけ取得して sha-256 digest を pin する。
     * fetcher は管理面の composition root からだけ注入され、公開処理からは到達できない。
     */
    public function calculateAndPinIntegrity(string $uuid, AdministrativeResourceFetcher $fetcher): ResolverRecord
    {
        $record = $this->requireRecord($uuid);
        $publisher = $this->publicationRepository();
        try {
            $fetched = $fetcher->fetch($record->location);
        } catch (\Throwable $error) {
            throw new ApplicationException('FETCH_FAILURE', 'Administrative resource fetch failed', 0, $error);
        }
        if ($fetched->status < 200 || $fetched->status >= 300) {
            throw new ApplicationException('FETCH_FAILURE', 'Administrative resource was not successful');
        }
        try {
            // Fetch Port の契約により、digest 対象は decoded text ではなく body octets である。
            $integrity = new IntegrityMetadata('sha-256', hash('sha256', $fetched->body));
            new DescriptionLocation($fetched->finalUrl);
        } catch (\InvalidArgumentException $error) {
            throw new ApplicationException('FETCH_FAILURE', 'Administrative resource response is invalid', 0, $error);
        }
        try {
            return $publisher->publishIntegrity($record, $integrity, 'CALCULATED');
        } catch (ApplicationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            if ($error->getMessage() === 'STATE_CONFLICT') {
                throw new ApplicationException('STATE_CONFLICT', 'Record was changed concurrently', 0, $error);
            }
            throw new ApplicationException('PERSISTENCE_FAILURE', 'Integrity publication failed', 0, $error);
        }
    }

    /** 管理画面の preview と公開 Manifest endpoint が同じ生成経路を使う。 */
    /** @return array<string, mixed>|null */
    public function previewManifest(string $uuid): ?array
    {
        return $this->manifest($uuid);
    }

    /**
     * 管理ホスト向けに一件の Resolver レコードを取得する。
     *
     * HTTP や ORM のモデルを返さず、Resolver のドメインモデルだけを返す。
     */
    public function findRecord(string $uuid): ResolverRecord
    {
        return $this->requireRecord($uuid);
    }

    /**
     * 管理ホスト向けに型付きの Resolver 履歴を返す。
     *
     * 履歴はライフサイクル遷移を含む Resolver の変更履歴であり、監査ログではない。
     *
     * @return list<ResolverHistoryEntry>
     */
    public function history(string $uuid): array
    {
        $record = $this->requireRecord($uuid);
        try {
            return $this->repository->history($record->anchor);
        } catch (\Throwable $error) {
            throw new ApplicationException('PERSISTENCE_FAILURE', 'History lookup failure', 0, $error);
        }
    }

    /** @param array<string, mixed> $input */
    public function register(array $input): ResolverRecord
    {
        $algorithm = isset($input['integrity_algorithm']) && $input['integrity_algorithm'] !== '' ? (string) $input['integrity_algorithm'] : null;
        $digest = isset($input['integrity_digest']) && $input['integrity_digest'] !== '' ? (string) $input['integrity_digest'] : null;
        $integrity = $this->makeOptionalIntegrity($algorithm, $digest);
        $mode = strtolower((string) ($input['publication_mode'] ?? $input['manifest_mode'] ?? ($integrity !== null ? 'supplied' : 'manifest-without-integrity')));
        $manifestEnabled = !in_array($mode, ['direct', 'no-manifest'], true);
        if (!in_array($mode, ['direct', 'no-manifest', 'manifest-without-integrity', 'without-integrity', 'none', 'supplied', 'with-supplied-integrity', 'calculated', 'with-calculated-integrity'], true)) {
            throw new \InvalidArgumentException('Calculated integrity requires an explicit pin operation');
        }
        if ($mode === 'supplied' || $mode === 'with-supplied-integrity') {
            if ($integrity === null) {
                throw new \InvalidArgumentException('Supplied integrity is required');
            }
        } elseif ($integrity !== null) {
            throw new \InvalidArgumentException('Integrity is only accepted in supplied mode');
        }
        $source = $integrity === null ? null : 'SUPPLIED';
        $record = new ResolverRecord(
            new AnchorUuid((string) ($input['uuid'] ?? '')),
            LifecycleState::fromInput((string) ($input['state'] ?? 'ACTIVE')),
            new DescriptionLocation((string) ($input['location'] ?? '')),
            $this->validateEntityId((string) ($input['entity_id'] ?? '')),
            isset($input['media_type']) && $input['media_type'] !== '' ? (string) $input['media_type'] : null,
            $integrity?->algorithm,
            $integrity?->digest,
            1,
            $manifestEnabled,
            $source,
        );
        try {
            $this->repository->insert($record);
        } catch (ApplicationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            if ($error->getMessage() === 'RECORD_EXISTS') {
                throw new ApplicationException('RECORD_EXISTS', 'Record already exists', 0, $error);
            }
            throw new ApplicationException('PERSISTENCE_FAILURE', 'Record persistence failure', 0, $error);
        }
        return $record;
    }

    public function updateLocation(string $uuid, string $location, ?string $entityId = null): ResolverRecord
    {
        $record = $this->requireRecord($uuid);
        try {
            // Location と対応しない digest の保存を防ぐため、変更時は整合性情報を破棄する。
            return $this->repository->update($record, new DescriptionLocation($location), $entityId !== null && $entityId !== '' ? $this->validateEntityId($entityId) : $record->entityId, null, null);
        } catch (ApplicationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            if ($error->getMessage() === 'STATE_CONFLICT') {
                throw new ApplicationException('STATE_CONFLICT', 'Record was changed concurrently', 0, $error);
            }
            throw new ApplicationException('PERSISTENCE_FAILURE', 'Record update failure', 0, $error);
        }
    }

    public function transition(string $uuid, string $target, string $reason = '', string $actor = 'admin'): ResolverRecord
    {
        $record = $this->requireRecord($uuid);
        $next = LifecycleState::fromInput($target);
        if ($record->state === $next) {
            return $record;
        }
        $allowed = [
            LifecycleState::ACTIVE->value . ':' . LifecycleState::SUSPENDED->value,
            LifecycleState::SUSPENDED->value . ':' . LifecycleState::ACTIVE->value,
            LifecycleState::ACTIVE->value . ':' . LifecycleState::RETIRED->value,
            LifecycleState::SUSPENDED->value . ':' . LifecycleState::RETIRED->value,
        ];
        if (!in_array($record->state->value . ':' . $next->value, $allowed, true)) {
            throw new RuntimeException('INVALID_TRANSITION');
        }
        try {
            return $this->repository->transition($record, $next, $reason, $actor);
        } catch (ApplicationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            if ($error->getMessage() === 'STATE_CONFLICT') {
                throw new ApplicationException('STATE_CONFLICT', 'Record was changed concurrently', 0, $error);
            }
            throw new ApplicationException('PERSISTENCE_FAILURE', 'Lifecycle persistence failure', 0, $error);
        }
    }

    private function requireRecord(string $uuid): ResolverRecord
    {
        try {
            $record = $this->repository->find(new AnchorUuid($uuid));
        } catch (\Throwable $error) {
            throw new ApplicationException('PERSISTENCE_FAILURE', 'Record lookup failure', 0, $error);
        }
        return $record ?? throw new ApplicationException('NOT_FOUND', 'Record not found');
    }

    private function validateEntityId(string $entityId): string
    {
        $parts = parse_url($entityId);
        if ($entityId === '' || str_contains($entityId, "\r") || str_contains($entityId, "\n") || !is_array($parts) || empty($parts['scheme'])) {
            throw new \InvalidArgumentException('Entity identity must be an absolute URI');
        }
        return $entityId;
    }

    private function makeOptionalIntegrity(?string $algorithm, ?string $digest): ?IntegrityMetadata
    {
        if (($algorithm === null) !== ($digest === null)) {
            throw new \InvalidArgumentException('Integrity algorithm and digest must be provided together');
        }
        if ($algorithm === null) {
            return null;
        }
        return new IntegrityMetadata($algorithm, (string) $digest);
    }

    private function makeIntegrity(?string $algorithm, ?string $digest): IntegrityMetadata
    {
        $integrity = $this->makeOptionalIntegrity($algorithm, $digest);
        return $integrity ?? throw new \InvalidArgumentException('Supplied integrity is required');
    }

    private function publicationRepository(): ManifestPublicationRepository
    {
        if (!$this->repository instanceof ManifestPublicationRepository) {
            throw new ApplicationException('UNSUPPORTED_OPERATION', 'Manifest publication is not supported by this repository');
        }
        return $this->repository;
    }
}
