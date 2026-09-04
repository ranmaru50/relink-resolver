<?php
// src/Ports/ManifestPublicationRepository.php
// Manifest 公開設定と integrity pin の原子的更新を担う Port。

declare(strict_types=1);

namespace Relink\Resolver\Ports;

use Relink\Resolver\Domain\IntegrityMetadata;
use Relink\Resolver\Domain\ResolverRecord;

interface ManifestPublicationRepository
{
    public function updateManifestPublication(ResolverRecord $record, bool $enabled, ?IntegrityMetadata $integrity, ?string $source): ResolverRecord;

    public function publishIntegrity(ResolverRecord $record, IntegrityMetadata $integrity, string $source): ResolverRecord;
}
