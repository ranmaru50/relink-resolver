<?php
// bin/migrate.php
// Web リクエストとは分離して SQLite マイグレーションを適用する CLI。

declare(strict_types=1);

use Relink\Resolver\Adapters\SqliteMigrator;

$config = require dirname(__DIR__) . '/bootstrap.php';
SqliteMigrator::migrate($config['database_path']);
fwrite(STDOUT, "Migration: OK\n");
