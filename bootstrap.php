<?php
// bootstrap.php
// RELink Resolver の依存関係を初期化するエントリポイント。

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Relink\\Resolver\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// Web ルートから到達できない既定のデータディレクトリを使用する。
$dataDirectory = getenv('RELINK_DATA_DIR') ?: (__DIR__ . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'data');
$databasePath = getenv('RELINK_DB_PATH') ?: ($dataDirectory . DIRECTORY_SEPARATOR . 'resolver.sqlite');
$allowHttp = strtolower((string) (getenv('RELINK_ADMIN_ALLOW_HTTP') ?: '0'));
$environment = strtolower((string) (getenv('RELINK_ENV') ?: 'development'));
$adminPassword = getenv('RELINK_ADMIN_PASSWORD') ?: '';
$trustedProxyCidrs = array_values(array_filter(
    array_map('trim', explode(',', (string) (getenv('RELINK_TRUSTED_PROXY_CIDRS') ?: ''))),
    static fn (string $value): bool => $value !== '',
));

return [
    'database_path' => $databasePath,
    'admin_username' => getenv('RELINK_ADMIN_USERNAME') ?: 'admin',
    'admin_password' => $adminPassword,
    'environment' => $environment,
    'configuration_error' => $environment === 'production' && ($adminPassword === '' || $adminPassword === 'change-me'),
    'admin_allow_http' => in_array($allowHttp, ['1', 'true', 'yes'], true),
    'trusted_proxy_cidrs' => $trustedProxyCidrs,
    'cache_max_age' => (int) (getenv('RELINK_CACHE_MAX_AGE') ?: 60),
    'service_prefix' => getenv('RELINK_SERVICE_PREFIX') ?: '/relink',
];
