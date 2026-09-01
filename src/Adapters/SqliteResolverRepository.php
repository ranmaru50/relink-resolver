<?php
// src/Adapters/SqliteResolverRepository.php
// SQLite を用いた ResolverRepository 実装。入力値はすべてバインドする。

declare(strict_types=1);

namespace Relink\Resolver\Adapters;

use PDO;
use PDOException;
use Relink\Resolver\Domain\AnchorUuid;
use Relink\Resolver\Domain\DescriptionLocation;
use Relink\Resolver\Domain\LifecycleState;
use Relink\Resolver\Domain\ResolverRecord;
use Relink\Resolver\Ports\ResolverRepository;
use RuntimeException;

final class SqliteResolverRepository implements ResolverRepository
{
    private PDO $pdo;

    public function __construct(string $databasePath)
    {
        $parent = dirname($databasePath);
        if (!is_dir($parent)) {
            mkdir($parent, 0770, true);
        }
        $this->pdo = new PDO('sqlite:' . $databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->migrate();
    }

    public function find(AnchorUuid $anchor): ?ResolverRecord
    {
        $statement = $this->pdo->prepare('SELECT * FROM resolver_records WHERE anchor_uuid = :uuid');
        $statement->execute(['uuid' => $anchor->value]);
        $row = $statement->fetch();
        return $row === false ? null : $this->map($row);
    }

    public function insert(ResolverRecord $record): void
    {
        $statement = $this->pdo->prepare('INSERT INTO resolver_records (anchor_uuid, state, description_location, entity_id, media_type, integrity_algorithm, integrity_digest, version, created_at, updated_at) VALUES (:uuid, :state, :location, :entity, :media, :algorithm, :digest, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        try {
            $statement->execute($this->params($record));
        } catch (PDOException $error) {
            throw new RuntimeException('RECORD_EXISTS', 0, $error);
        }
    }

    public function update(ResolverRecord $record, DescriptionLocation $location, string $entityId, ?string $algorithm, ?string $digest): ResolverRecord
    {
        $newVersion = $record->version + 1;
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('UPDATE resolver_records SET description_location = :location, entity_id = :entity, integrity_algorithm = :algorithm, integrity_digest = :digest, version = :new_version, updated_at = CURRENT_TIMESTAMP WHERE anchor_uuid = :uuid AND version = :version');
            $statement->execute([
                'location' => $location->value,
                'entity' => $entityId,
                'algorithm' => $algorithm,
                'digest' => $digest,
                'new_version' => $newVersion,
                'uuid' => $record->anchor->value,
                'version' => $record->version,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('STATE_CONFLICT');
            }
            $updated = new ResolverRecord($record->anchor, $record->state, $location, $entityId, $record->mediaType, $algorithm, $digest, $newVersion);
            $this->historyInsert($record, $updated, 'mapping_update', 'admin');
            $this->pdo->commit();
            return $updated;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function transition(ResolverRecord $record, LifecycleState $target, string $reason, string $actor): ResolverRecord
    {
        $newVersion = $record->version + 1;
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('UPDATE resolver_records SET state = :state, version = :new_version, updated_at = CURRENT_TIMESTAMP WHERE anchor_uuid = :uuid AND version = :version AND state = :old_state');
            $statement->execute([
                'state' => $target->value,
                'new_version' => $newVersion,
                'uuid' => $record->anchor->value,
                'version' => $record->version,
                'old_state' => $record->state->value,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('STATE_CONFLICT');
            }
            $updated = new ResolverRecord($record->anchor, $target, $record->location, $record->entityId, $record->mediaType, $record->integrityAlgorithm, $record->integrityDigest, $newVersion);
            $history = $this->pdo->prepare('INSERT INTO resolver_history (anchor_uuid, event_type, old_state, new_state, old_location, new_location, reason, actor, created_at) VALUES (:uuid, :type, :old_state, :new_state, :old_location, :new_location, :reason, :actor, CURRENT_TIMESTAMP)');
            $history->execute([
                'uuid' => $record->anchor->value,
                'type' => 'lifecycle_transition',
                'old_state' => $record->state->value,
                'new_state' => $target->value,
                'old_location' => $record->location->value,
                'new_location' => $record->location->value,
                'reason' => substr($reason, 0, 500),
                'actor' => substr($actor, 0, 200),
            ]);
            $this->pdo->commit();
            return $updated;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function all(): array
    {
        return array_map(fn (array $row): ResolverRecord => $this->map($row), $this->pdo->query('SELECT * FROM resolver_records ORDER BY anchor_uuid')->fetchAll());
    }

    public function history(AnchorUuid $anchor): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM resolver_history WHERE anchor_uuid = :uuid ORDER BY id DESC LIMIT 100');
        $statement->execute(['uuid' => $anchor->value]);
        return $statement->fetchAll();
    }

    private function migrate(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version INTEGER PRIMARY KEY, applied_at TEXT NOT NULL)');
        $version = (int) $this->pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn();
        if ($version < 1) {
            $this->pdo->beginTransaction();
            $migration = file_get_contents(dirname(__DIR__, 2) . '/migrations/001_initial.sql');
            if ($migration === false) {
                throw new RuntimeException('MIGRATION_NOT_FOUND');
            }
            $this->pdo->exec($migration);
            $this->pdo->exec('INSERT INTO schema_migrations (version, applied_at) VALUES (1, CURRENT_TIMESTAMP)');
            $this->pdo->commit();
        }
    }

    private function map(array $row): ResolverRecord
    {
        return new ResolverRecord(new AnchorUuid($row['anchor_uuid']), LifecycleState::fromInput($row['state']), new DescriptionLocation($row['description_location']), $row['entity_id'], $row['media_type'], $row['integrity_algorithm'], $row['integrity_digest'], (int) $row['version']);
    }

    private function params(ResolverRecord $record): array
    {
        return ['uuid' => $record->anchor->value, 'state' => $record->state->value, 'location' => $record->location->value, 'entity' => $record->entityId, 'media' => $record->mediaType, 'algorithm' => $record->integrityAlgorithm, 'digest' => $record->integrityDigest];
    }

    private function historyInsert(ResolverRecord $old, ResolverRecord $new, string $type, string $actor): void
    {
        $statement = $this->pdo->prepare('INSERT INTO resolver_history (anchor_uuid, event_type, old_state, new_state, old_location, new_location, actor, created_at) VALUES (:uuid, :type, :old_state, :new_state, :old_location, :new_location, :actor, CURRENT_TIMESTAMP)');
        $statement->execute(['uuid' => $old->anchor->value, 'type' => $type, 'old_state' => $old->state->value, 'new_state' => $new->state->value, 'old_location' => $old->location->value, 'new_location' => $new->location->value, 'actor' => $actor]);
    }
}
