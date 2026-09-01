<?php
// src/Adapters/SqliteMigrator.php
// SQLite スキーマを明示的な運用操作として適用するマイグレータ。

declare(strict_types=1);

namespace Relink\Resolver\Adapters;

use PDO;
use RuntimeException;

final class SqliteMigrator
{
    public static function migrate(string $databasePath): void
    {
        $parent = dirname($databasePath);
        if (!is_dir($parent) && !mkdir($parent, 0770, true) && !is_dir($parent)) {
            throw new RuntimeException('MIGRATION_DIRECTORY_FAILED');
        }
        $pdo = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version INTEGER PRIMARY KEY, applied_at TEXT NOT NULL)');
        $version = (int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn();
        if ($version >= 1) {
            return;
        }
        $migration = file_get_contents(dirname(__DIR__, 2) . '/migrations/001_initial.sql');
        if ($migration === false) {
            throw new RuntimeException('MIGRATION_NOT_FOUND');
        }
        $pdo->beginTransaction();
        try {
            $pdo->exec($migration);
            $pdo->exec('INSERT INTO schema_migrations (version, applied_at) VALUES (1, CURRENT_TIMESTAMP)');
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }
}
