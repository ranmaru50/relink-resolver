<?php
// public/index.php
// 公開 Resolver / Manifest HTTP アダプタ。AR-XML へはアクセスしない。

declare(strict_types=1);

use Relink\Resolver\Adapters\SqliteResolverRepository;
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
        http_response_code(404);
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
