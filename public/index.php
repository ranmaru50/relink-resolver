<?php
// public/index.php
// 公開 Resolver / Manifest HTTP アダプタ。AR-XML へはアクセスしない。

declare(strict_types=1);

use Relink\Resolver\Adapters\SqliteL2Repository;
use Relink\Resolver\Adapters\SqliteResolverRepository;
use Relink\Resolver\Application\L2ResolverService;
use Relink\Resolver\Application\ResolverService;

$config = require dirname(__DIR__) . '/bootstrap.php';
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$prefix = rtrim($config['service_prefix'], '/');
$relative = ($requestPath === $prefix || str_starts_with($requestPath, $prefix . '/')) ? substr($requestPath, strlen($prefix)) : '';
$relative = trim($relative, '/');
$parts = $relative === '' ? [] : explode('/', $relative);
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

header('Access-Control-Allow-Origin: *');
header('Referrer-Policy: no-referrer');

if (count($parts) === 2 && $parts[1] === 'manifest') {
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $parts[0])) {
        http_response_code(400);
        header('Cache-Control: no-store');
        exit;
    }
    if ($method !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        header('Cache-Control: no-store');
        exit;
    }
    try {
        $service = new ResolverService(new SqliteResolverRepository($config['database_path']), $config['cache_max_age']);
        $manifest = $service->manifest($parts[0]);
    } catch (\InvalidArgumentException) {
        http_response_code(400);
        header('Cache-Control: no-store');
        exit;
    } catch (Throwable) {
        http_response_code(503);
        header('Cache-Control: no-store');
        exit;
    }
    if ($manifest === null) {
        // Manifest 無効化中でも RETIRED の終端状態は公開仕様に従い 410 とする。
        try {
            $record = $service->findRecord($parts[0]);
            http_response_code($record->state->value === 'RETIRED' ? 410 : 404);
        } catch (Throwable) {
            http_response_code(404);
        }
        header('Cache-Control: no-store');
        exit;
    }
    $status = strtolower((string) ($manifest['lifecycle']['status'] ?? ''));
    if ($status === 'suspended') {
        http_response_code(404);
        header('Cache-Control: no-store');
        exit;
    }
    if ($status === 'retired') {
        http_response_code(410);
        header('Cache-Control: public, max-age=300');
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=' . max(0, $config['cache_max_age']));
    echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

if (count($parts) !== 1 || $parts[0] === '') {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
}

if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $parts[0])) {
    http_response_code(400);
    header('Cache-Control: no-store');
    exit;
}

// L2 公開面（§6）: thin adapter 分岐。§6.1 の全判断（405/400/501、ライフサイクル、
// §6.3 のギャップ fail-closed、§14.2 の Cache-Control）はアプリサービスが所有し、
// このアダプタは superglobal 解析と応答出力のみを行う（AGENTS.md §3）。
// 内部障害は §6.1 の internal-failure 行により 503 + no-store。
// §6.4/§16: この分岐は GET 専用・読み取り専用・匿名。公開 submission endpoint は存在しない。
if (array_key_exists('l', $_GET)) {
    try {
        $l2Repository = new SqliteL2Repository($config['database_path']);
        $l2Service = new L2ResolverService(
            new SqliteResolverRepository($config['database_path']),
            $l2Repository,
            $l2Repository,
            (int) $config['l2_cache_max_age'],
        );
        $result = $l2Service->authority($method, $parts[0], $_GET, time());
    } catch (Throwable) {
        http_response_code(503);
        header('Cache-Control: no-store');
        exit;
    }
    http_response_code($result->status);
    foreach ($result->headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo $result->body;
    exit;
}

try {
    $service = new ResolverService(new SqliteResolverRepository($config['database_path']), $config['cache_max_age']);
    $result = $service->resolve($method, $parts[0], $_GET);
} catch (Throwable) {
    http_response_code(503);
    header('Cache-Control: no-store');
    exit;
}
http_response_code($result->status);
foreach ($result->headers as $name => $value) {
    header($name . ': ' . $value);
}
exit;
