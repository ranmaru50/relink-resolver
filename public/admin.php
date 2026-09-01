<?php
// public/admin.php
// 認証済み管理操作用の最小 UI/API。公開 Resolver と責務を分離する。

declare(strict_types=1);

use Relink\Resolver\Adapters\SqliteResolverRepository;
use Relink\Resolver\Application\ResolverService;

$config = require dirname(__DIR__) . '/bootstrap.php';

session_start(['cookie_httponly' => true, 'cookie_secure' => isset($_SERVER['HTTPS']), 'cookie_samesite' => 'Strict']);

function admin_authenticated(array $config): bool
{
    if (isset($_SESSION['admin'])) {
        return true;
    }
    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    if ($username === $config['admin_username'] && $config['admin_password'] !== '' && hash_equals($config['admin_password'], $password)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = $username;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return true;
    }
    return false;
}

$action = (string) ($_POST['action'] ?? '');
if ($action === 'logout') {
    if (!isset($_SESSION['admin']) || !hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('CSRF validation failed');
    }
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}
if (!admin_authenticated($config)) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>RELink Admin</title><form method="post"><label>ユーザー名 <input name="username" required></label><label>パスワード <input type="password" name="password" required></label><button>ログイン</button></form>';
    exit;
}

// 認証後にだけ永続化アダプタを初期化し、未認証 GET が DB を作成しないようにする。
$repository = new SqliteResolverRepository($config['database_path']);
$service = new ResolverService($repository, $config['cache_max_age']);

// 読み取り専用の JSON 管理 API（認証後のみ）を提供する。

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Cache-Control: no-store');
    header('Content-Type: application/json; charset=utf-8');
    $uuid = (string) ($_GET['uuid'] ?? '');
    try {
        if ($uuid !== '') {
            $record = $repository->find(new \Relink\Resolver\Domain\AnchorUuid($uuid));
            if ($record === null) {
                http_response_code(404);
                echo json_encode(['error' => 'not_found'], JSON_THROW_ON_ERROR);
                exit;
            }
            echo json_encode(['record' => [
                'uuid' => $record->anchor->value,
                'state' => $record->state->value,
                'location' => $record->location->value,
                'entity_id' => $record->entityId,
                'version' => $record->version,
            ], 'history' => $repository->history($record->anchor)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } else {
            $needle = (string) ($_GET['q'] ?? '');
            $rows = array_values(array_filter($repository->all(), static fn ($record): bool => $needle === '' || str_contains($record->anchor->value, strtolower($needle)) || str_contains($record->entityId, $needle)));
            echo json_encode(array_map(static fn ($record): array => ['uuid' => $record->anchor->value, 'state' => $record->state->value, 'location' => $record->location->value, 'entity_id' => $record->entityId], $rows), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
    } catch (Throwable) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_request'], JSON_THROW_ON_ERROR);
    }
    exit;
}

if ($action !== '' && !hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    exit('CSRF validation failed');
}

$message = '';
try {
    if ($action === 'register') {
        $service->register($_POST);
        $message = '登録しました。';
    } elseif ($action === 'location') {
        $service->updateLocation((string) $_POST['uuid'], (string) $_POST['location'], isset($_POST['entity_id']) ? (string) $_POST['entity_id'] : null);
        $message = '更新しました。';
    } elseif ($action === 'transition') {
        $service->transition((string) $_POST['uuid'], (string) $_POST['state'], (string) ($_POST['reason'] ?? ''), (string) $_SESSION['admin']);
        $message = '状態を変更しました。';
    } elseif ($action === 'resolution-test') {
        $result = $service->resolve('GET', (string) $_POST['uuid'], []);
        $message = '公開解決テスト結果: HTTP ' . $result->status;
    }
} catch (Throwable $error) {
    $message = '操作に失敗しました: ' . htmlspecialchars($error->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');
$csrf = htmlspecialchars((string) $_SESSION['csrf'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
echo '<!doctype html><meta charset="utf-8"><title>RELink Admin</title><h1>RELink Admin</h1><p>' . $esc($message) . '</p>';
echo '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="register"><h2>登録</h2><input name="uuid" placeholder="UUID" required><input name="location" placeholder="https://..." required><input name="entity_id" placeholder="Entity URI" required><button>登録</button></form>';
echo '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="location"><h2>場所更新</h2><input name="uuid" placeholder="UUID" required><input name="location" placeholder="https://..." required><input name="entity_id" placeholder="Entity URI"><button>更新</button></form>';
echo '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="transition"><h2>Lifecycle</h2><input name="uuid" placeholder="UUID" required><select name="state"><option>ACTIVE</option><option>SUSPENDED</option><option>RETIRED</option></select><input name="reason" placeholder="理由"><button>変更</button></form>';
echo '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="resolution-test"><h2>公開解決テスト</h2><input name="uuid" placeholder="UUID" required><button>テスト</button></form>';
echo '<h2>Records</h2><ul>';
foreach ($repository->all() as $record) {
    echo '<li>' . $esc($record->anchor->value) . ' — ' . $esc($record->state->value) . ' — ' . $esc($record->location->value) . '</li>';
}
echo '</ul><form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="logout"><button>ログアウト</button></form>';
