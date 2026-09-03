<?php
// src/Domain/ResolverHistoryEntry.php
// Resolver の状態・マッピング変更履歴を表す型付き値。

declare(strict_types=1);

namespace Relink\Resolver\Domain;

final readonly class ResolverHistoryEntry
{
    public function __construct(
        public int $id,
        public AnchorUuid $anchor,
        public string $eventType,
        public ?LifecycleState $oldState,
        public ?LifecycleState $newState,
        public ?DescriptionLocation $oldLocation,
        public ?DescriptionLocation $newLocation,
        public string $reason,
        public string $actor,
        public string $createdAt,
    ) {
    }
}
