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
        // Manifest 公開可否は Core 解決とは独立した永続化事実。
        public bool $manifestEnabled = true,
        // integrity の出所は管理画面用メタデータであり、Manifest には出力しない。
        public ?string $integritySource = null,
    ) {
    }
}
