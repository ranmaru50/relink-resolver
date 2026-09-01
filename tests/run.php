<?php
// tests/run.php
// 依存なしで実行できる最小テストランナー。

declare(strict_types=1);

$test = require __DIR__ . '/ResolverServiceTest.php';
$test();
fwrite(STDOUT, "ResolverServiceTest: OK\n");
