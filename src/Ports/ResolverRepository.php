<?php
// src/Ports/ResolverRepository.php
// 永続化アダプタが実装する Resolver リポジトリ契約。

declare(strict_types=1);

namespace Relink\Resolver\Ports;

use Relink\Resolver\Domain\AnchorUuid;
use Relink\Resolver\Domain\DescriptionLocation;
use Relink\Resolver\Domain\LifecycleState;
use Relink\Resolver\Domain\ResolverRecord;

interface ResolverRepository
{
    public function find(AnchorUuid $anchor): ?ResolverRecord;

    public function insert(ResolverRecord $record): void;

    public function update(ResolverRecord $record, DescriptionLocation $location, string $entityId, ?string $algorithm, ?string $digest): ResolverRecord;

    public function transition(ResolverRecord $record, LifecycleState $target, string $reason, string $actor): ResolverRecord;

    /** @return list<ResolverRecord> */
    public function all(): array;

    /** @return list<ResolverRecord> */
    public function search(string $needle, int $limit, int $offset): array;

    /** @return list<array<string, mixed>> */
    public function history(AnchorUuid $anchor): array;
}
