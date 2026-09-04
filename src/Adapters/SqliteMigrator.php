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
        $migrations = [1 => '001_initial.sql', 2 => '002_admin_login_throttles.sql', 3 => '003_manifest_publication.sql'];
        foreach ($migrations as $version => $filename) {
            $applied = (int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn();
            if ($applied >= $version) {
                continue;
            }
            $migration = file_get_contents(dirname(__DIR__, 2) . '/migrations/' . $filename);
            if ($migration === false) {
                throw new RuntimeException('MIGRATION_NOT_FOUND');
            }
            $pdo->beginTransaction();
            try {
                $pdo->exec($migration);
                $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (:version, CURRENT_TIMESTAMP)')->execute(['version' => $version]);
                $pdo->commit();
            } catch (\Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
        }
    }
}
