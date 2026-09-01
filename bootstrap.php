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
if (!is_dir($dataDirectory)) {
    mkdir($dataDirectory, 0770, true);
}

$databasePath = getenv('RELINK_DB_PATH') ?: ($dataDirectory . DIRECTORY_SEPARATOR . 'resolver.sqlite');

return [
    'database_path' => $databasePath,
    'admin_username' => getenv('RELINK_ADMIN_USERNAME') ?: 'admin',
    'admin_password' => getenv('RELINK_ADMIN_PASSWORD') ?: '',
    'cache_max_age' => (int) (getenv('RELINK_CACHE_MAX_AGE') ?: 60),
    'service_prefix' => getenv('RELINK_SERVICE_PREFIX') ?: '/relink',
];
