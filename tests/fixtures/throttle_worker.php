<?php
// tests/fixtures/throttle_worker.php
// SQLiteログイン制限の並行試行を開始するテスト用ワーカー。

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Relink\Resolver\Adapters\SqliteAdminLoginThrottleStore;
use Relink\Resolver\Application\AdminAuthenticationService;

[$script, $databasePath, $barrierPath, $resultPath] = $argv;
file_put_contents($barrierPath . '.' . getmypid(), 'ready');
while (!file_exists($barrierPath . '.go')) { usleep(1000); }
$service = new AdminAuthenticationService(new SqliteAdminLoginThrottleStore($databasePath), 'admin', 'secret', 2, 100, 100, 300, 3600);
file_put_contents($resultPath, $service->attempt('192.0.2.77', 'admin', 'wrong', 100));
