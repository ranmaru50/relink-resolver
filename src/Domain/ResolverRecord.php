<?php
// src/Domain/ResolverRecord.php
// Resolver の現在値と Manifest に必要なメタデータを表す不変モデル。

declare(strict_types=1);

namespace Relink\Resolver\Domain;

final readonly class ResolverRecord
{
    public function __construct(
        public AnchorUuid $anchor,
        public LifecycleState $state,
        public DescriptionLocation $location,
        public string $entityId,
        public ?string $mediaType,
        public ?string $integrityAlgorithm,
        public ?string $integrityDigest,
        public int $version,
    ) {
    }
}
