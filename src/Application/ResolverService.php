<?php
// src/Application/ResolverService.php
// 公開 Resolver と管理操作のアプリケーションサービス。

declare(strict_types=1);

namespace Relink\Resolver\Application;

use Relink\Resolver\Domain\AnchorUuid;
use Relink\Resolver\Domain\DescriptionLocation;
use Relink\Resolver\Domain\LifecycleState;
use Relink\Resolver\Domain\ResolutionResult;
use Relink\Resolver\Domain\ResolverRecord;
use Relink\Resolver\Ports\ResolverRepository;
use RuntimeException;

final class ResolverService
{
    public function __construct(private ResolverRepository $repository, private int $cacheMaxAge = 60)
    {
    }

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

    public function manifest(string $uuid): ?array
    {
        $record = $this->repository->find(new AnchorUuid($uuid));
        if ($record === null) {
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

    public function register(array $input): ResolverRecord
    {
        $algorithm = isset($input['integrity_algorithm']) && $input['integrity_algorithm'] !== '' ? (string) $input['integrity_algorithm'] : null;
        $digest = isset($input['integrity_digest']) && $input['integrity_digest'] !== '' ? (string) $input['integrity_digest'] : null;
        $this->validateIntegrity($algorithm, $digest);
        $record = new ResolverRecord(
            new AnchorUuid((string) ($input['uuid'] ?? '')),
            LifecycleState::fromInput((string) ($input['state'] ?? 'ACTIVE')),
            new DescriptionLocation((string) ($input['location'] ?? '')),
            $this->validateEntityId((string) ($input['entity_id'] ?? '')),
            isset($input['media_type']) && $input['media_type'] !== '' ? (string) $input['media_type'] : null,
            $algorithm,
            $digest,
            1,
        );
        $this->repository->insert($record);
        return $record;
    }

    public function updateLocation(string $uuid, string $location, ?string $entityId = null): ResolverRecord
    {
        $record = $this->requireRecord($uuid);
        $updated = $this->repository->update($record, new DescriptionLocation($location), $entityId !== null && $entityId !== '' ? $this->validateEntityId($entityId) : $record->entityId, $record->integrityAlgorithm, $record->integrityDigest);
        return $updated;
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
        return $this->repository->transition($record, $next, $reason, $actor);
    }

    private function requireRecord(string $uuid): ResolverRecord
    {
        $record = $this->repository->find(new AnchorUuid($uuid));
        return $record ?? throw new RuntimeException('NOT_FOUND');
    }

    private function validateEntityId(string $entityId): string
    {
        $parts = parse_url($entityId);
        if ($entityId === '' || str_contains($entityId, "\r") || str_contains($entityId, "\n") || !is_array($parts) || empty($parts['scheme'])) {
            throw new \InvalidArgumentException('Entity identity must be an absolute URI');
        }
        return $entityId;
    }

    private function validateIntegrity(?string $algorithm, ?string $digest): void
    {
        if (($algorithm === null) !== ($digest === null)) {
            throw new \InvalidArgumentException('Integrity algorithm and digest must be provided together');
        }
        if ($algorithm === null) {
            return;
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/D', $algorithm)) {
            throw new \InvalidArgumentException('Invalid integrity algorithm');
        }
        if ($algorithm === 'sha-256' && !preg_match('/^[0-9a-f]{64}$/D', (string) $digest)) {
            throw new \InvalidArgumentException('Invalid sha-256 digest');
        }
    }
}
