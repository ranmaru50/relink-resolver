<?php
// bin/prepare-acceptance-fixtures.php
// Testbed の HTTP security header 受入で共有する Resolver fixture を準備する CLI。

declare(strict_types=1);

use Relink\Resolver\Adapters\SqliteMigrator;
use Relink\Resolver\Application\ApplicationException;
use Relink\Resolver\Application\ResolverService;
use Relink\Resolver\Adapters\SqliteResolverRepository;
use Relink\Resolver\Domain\LifecycleState;

$config = require dirname(__DIR__) . '/bootstrap.php';

// 本番データへ誤って fixture を作成しないよう、受入環境を明示的に要求する。
if ($config['environment'] !== 'acceptance') {
    throw new RuntimeException('RELINK_ENV=acceptance is required for acceptance fixtures.');
}

SqliteMigrator::migrate($config['database_path']);
$uuid = getenv('RELINK_SECURITY_FIXTURE_UUID') ?: '550e8400-e29b-41d4-a716-446655440000';
$location = getenv('RELINK_SECURITY_FIXTURE_LOCATION') ?: 'https://fixture.invalid/security-headers.arxml';
$entityId = getenv('RELINK_SECURITY_FIXTURE_ENTITY_ID') ?: 'https://fixture.invalid/entities/security-headers';
$service = new ResolverService(new SqliteResolverRepository($config['database_path']), $config['cache_max_age']);

$record = null;
try {
    $record = $service->findRecord($uuid);
} catch (ApplicationException $error) {
    if ($error->errorCode !== 'NOT_FOUND') {
        throw $error;
    }
}

if ($record === null) {
    // Manifest は optional のまま、fixture では 200 を確認できるよう明示的に有効化する。
    $record = $service->register([
        'uuid' => $uuid,
        'state' => LifecycleState::ACTIVE->value,
        'location' => $location,
        'entity_id' => $entityId,
        'media_type' => 'application/xml',
        'publication_mode' => 'manifest-without-integrity',
    ]);
} elseif ($record->state !== LifecycleState::ACTIVE || !$record->manifestEnabled) {
    // 既存データを暗黙に書き換えず、クリーンな受入 DB の再準備を促す。
    throw new RuntimeException('The security fixture exists but is not ACTIVE with Manifest enabled.');
}

fwrite(STDOUT, "Acceptance fixture: {$record->anchor->value}\n");
fwrite(STDOUT, "Manifest URL path: /relink/{$record->anchor->value}/manifest\n");
