<?php
// public/admin.php
// 認証済み管理操作用の最小 UI/API。公開 Resolver と責務を分離する。

declare(strict_types=1);

use Relink\Resolver\Adapters\SqliteResolverRepository;
use Relink\Resolver\Adapters\SqliteAdminLoginThrottleStore;
use Relink\Resolver\Adapters\ConfiguredAdminCredentialVerifier;
use Relink\Resolver\Adapters\NativeAdministrativeResourceFetcher;
use Relink\Resolver\Application\AdminAuthenticationService;
use Relink\Resolver\Application\AdminRequestException;
use Relink\Resolver\Application\AdminRequestGuard;
use Relink\Resolver\Application\AdminSession;
use Relink\Resolver\Application\AdminSessionPolicy;
use Relink\Resolver\Application\TrustedProxyPolicy;
use Relink\Resolver\Application\OutboundNetworkPolicy;
use Relink\Resolver\Application\ResolverService;
use Relink\Resolver\Ports\AdminRecordQuery;
use Relink\Resolver\Domain\ResolverHistoryEntry;

$config = require dirname(__DIR__) . '/bootstrap.php';

/** SQLiteアダプタを管理面専用の一覧検索ポートとして受け渡す。 */
function admin_record_query(SqliteResolverRepository $repository): AdminRecordQuery
{
    return $repository;
}

// 管理画面の全応答で MIME sniffing と埋め込みを抑止する。
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

// 認証、セッション、DB処理より前に管理リクエストの資源上限を検査する。
$requestGuard = new AdminRequestGuard($config['admin_request_limits']);
try {
    $requestGuard->assertContentLength(isset($_SERVER['CONTENT_LENGTH']) ? (string) $_SERVER['CONTENT_LENGTH'] : null);
    $requestGuard->assertContentType((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), isset($_SERVER['CONTENT_TYPE']) ? (string) $_SERVER['CONTENT_TYPE'] : null);
    $requestGuard->assertRawVariableCount((string) ($_SERVER['QUERY_STRING'] ?? ''));
    $rawInput = file_get_contents('php://input');
    if ($rawInput !== false) {
        $requestGuard->assertRawVariableCount($rawInput);
    }
    $requestGuard->assertPost($_POST);
    $requestGuard->assertQuery($_GET);
    $listQuery = $requestGuard->listQuery($_GET);
} catch (AdminRequestException $error) {
    http_response_code($error->status);
    header('Content-Type: text/plain; charset=utf-8');
    exit($error->status === 413 ? '要求が大きすぎます。' : '要求が不正です。');
}

if ($config['configuration_error']) {
    error_log('RELink admin configuration is invalid for production');
    http_response_code(503);
    exit('管理サービスの設定を確認してください。');
}

$secureRequest = TrustedProxyPolicy::isSecureRequest($_SERVER, $config['trusted_proxy_cidrs']);
if (!$secureRequest && !$config['admin_allow_http']) {
    http_response_code(403);
    exit('HTTPS is required for administration');
}
ini_set('session.use_strict_mode', '1');
session_start(['cookie_httponly' => true, 'cookie_secure' => $secureRequest, 'cookie_samesite' => 'Strict']);

$authentication = new AdminAuthenticationService(
    new SqliteAdminLoginThrottleStore($config['database_path']),
    new ConfiguredAdminCredentialVerifier($config['admin_username'], $config['admin_password']),
    $config['admin_login_max_failures'],
    $config['admin_login_window_seconds'],
    $config['admin_login_lockout_seconds'],
);
$sessionPolicy = new AdminSessionPolicy(
    $config['admin_session_idle_seconds'],
    $config['admin_session_absolute_seconds'],
);

/**
 * @param array<string, mixed> $config
 */
function admin_authenticated(array $config, AdminAuthenticationService $authentication, AdminSessionPolicy $sessionPolicy): bool
{
    $now = time();
    $session = AdminSession::fromArray($_SESSION);
    if ($session !== null && $session->admin === $config['admin_username'] && $sessionPolicy->isValid($session, $now)) {
        $_SESSION['last_activity_at'] = $now;
        return true;
    }
    if (isset($_SESSION['admin'])) {
        $_SESSION = [];
        session_destroy();
        session_start(['cookie_httponly' => true, 'cookie_secure' => TrustedProxyPolicy::isSecureRequest($_SERVER, $config['trusted_proxy_cidrs']), 'cookie_samesite' => 'Strict']);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return false;
    }
    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $clientAddress = TrustedProxyPolicy::clientAddress($_SERVER, $config['trusted_proxy_cidrs']);
    $authenticationResult = $authentication->attempt($clientAddress, $username, $password, $now);
    if ($authenticationResult === 'accepted') {
        session_regenerate_id(true);
        $_SESSION['admin'] = $username;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['authenticated_at'] = $now;
        $_SESSION['last_activity_at'] = $now;
        return true;
    }
    if ($username !== '' || $password !== '') {
        error_log('RELink admin authentication ' . $authenticationResult);
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
if (!admin_authenticated($config, $authentication, $sessionPolicy)) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>RELink Admin</title><form method="post"><label>ユーザー名 <input name="username" required></label><label>パスワード <input type="password" name="password" required></label><button>ログイン</button></form>';
    exit;
}

// 認証後にだけ永続化アダプタを初期化し、未認証 GET が DB を作成しないようにする。
try {
    $repository = new SqliteResolverRepository($config['database_path']);
    $recordQuery = admin_record_query($repository);
} catch (Throwable $error) {
    error_log('RELink admin persistence initialization failed');
    http_response_code(503);
    exit('管理サービスを利用できません。');
}
$service = new ResolverService($repository, $config['cache_max_age']);

// 読み取り専用の JSON 管理 API（認証後のみ）を提供する。

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Cache-Control: no-store');
    header('Content-Type: application/json; charset=utf-8');
    $uuid = (string) ($_GET['uuid'] ?? '');
    try {
        if ($uuid !== '') {
            $record = $service->findRecord($uuid);
            echo json_encode(['record' => [
                'uuid' => $record->anchor->value,
                'state' => $record->state->value,
                'location' => $record->location->value,
                'entity_id' => $record->entityId,
                'manifest_enabled' => $record->manifestEnabled,
                'integrity_algorithm' => $record->integrityAlgorithm,
                'integrity_digest' => $record->integrityDigest,
                'integrity_source' => $record->integritySource,
                'version' => $record->version,
            ], 'history' => array_map(static fn (ResolverHistoryEntry $entry): array => [
                'id' => $entry->id,
                'anchor_uuid' => $entry->anchor->value,
                'event_type' => $entry->eventType,
                'old_state' => $entry->oldState?->value,
                'new_state' => $entry->newState?->value,
                'old_location' => $entry->oldLocation?->value,
                'new_location' => $entry->newLocation?->value,
                'reason' => $entry->reason,
                'actor' => $entry->actor,
                'created_at' => $entry->createdAt,
            ], $service->history($uuid))], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } else {
            $rows = $recordQuery->search($listQuery->needle, $listQuery->perPage + 1, $listQuery->offset());
            $hasMore = count($rows) > $listQuery->perPage;
            $rows = array_slice($rows, 0, $listQuery->perPage);
            echo json_encode([
                'page' => $listQuery->page,
                'per_page' => $listQuery->perPage,
                'has_more' => $hasMore,
                'records' => array_map(static fn ($record): array => ['uuid' => $record->anchor->value, 'state' => $record->state->value, 'location' => $record->location->value, 'entity_id' => $record->entityId], $rows),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
    } catch (\Relink\Resolver\Application\ApplicationException $error) {
        if ($error->errorCode === 'NOT_FOUND') {
            http_response_code(404);
            echo json_encode(['error' => 'not_found'], JSON_THROW_ON_ERROR);
            exit;
        }
        http_response_code(400);
        echo json_encode(['error' => 'invalid_request'], JSON_THROW_ON_ERROR);
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
    } elseif ($action === 'publication') {
        $service->configureManifest((string) $_POST['uuid'], (string) $_POST['publication_mode'], isset($_POST['integrity_algorithm']) ? (string) $_POST['integrity_algorithm'] : null, isset($_POST['integrity_digest']) ? (string) $_POST['integrity_digest'] : null);
        $message = 'Manifest 公開設定を更新しました。';
    } elseif ($action === 'calculate-integrity') {
        $fetcher = new NativeAdministrativeResourceFetcher(
            new OutboundNetworkPolicy($config['outbound_allowed_cidrs'], $config['outbound_denied_cidrs']),
            $config['outbound_max_redirects'],
            $config['outbound_max_body_bytes'],
            $config['outbound_connect_timeout'],
            $config['outbound_read_timeout'],
        );
        $service->calculateAndPinIntegrity((string) $_POST['uuid'], $fetcher);
        $message = '現在の表現から sha-256 digest を計算して pin しました。';
    } elseif ($action === 'manifest-preview') {
        $preview = $service->previewManifest((string) $_POST['uuid']);
        $message = $preview === null ? 'Manifest は無効化されています。' : 'Manifest preview: ' . json_encode($preview, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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
    $code = $error instanceof \Relink\Resolver\Application\ApplicationException ? $error->errorCode : $error->getMessage();
    $messages = [
        'INVALID_INPUT' => '入力が不正です。',
        'INVALID_TRANSITION' => '許可されていない状態遷移です。',
        'STATE_CONFLICT' => '別の操作で更新されたため、再読み込みしてください。',
        'NOT_FOUND' => '対象レコードが見つかりません。',
        'RECORD_EXISTS' => '同じ UUID は既に登録されています。',
        'PERSISTENCE_FAILURE' => '永続化処理に失敗しました。',
        'FETCH_FAILURE' => '外部表現の取得に失敗しました。ネットワークポリシー、redirect、サイズ、timeout を確認してください。',
        'UNSUPPORTED_OPERATION' => 'この保存アダプタでは操作を利用できません。',
    ];
    $message = '操作に失敗しました: ' . ($messages[$code] ?? '要求を処理できませんでした。');
    error_log('RELink admin operation failed: ' . preg_replace('/[^A-Z_]/', '', (string) $code));
}

header('Content-Type: text/html; charset=utf-8');
$csrf = htmlspecialchars((string) $_SESSION['csrf'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
echo '<!doctype html><meta charset="utf-8"><title>RELink Admin</title><h1>RELink Admin</h1><p>' . $esc($message) . '</p>';
echo '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="register"><h2>登録</h2><input name="uuid" placeholder="UUID" required><input name="location" placeholder="https://..." required><input name="entity_id" placeholder="Entity URI" required><select name="publication_mode"><option value="direct">直接 AR-XML（Manifest なし）</option><option value="without-integrity">Manifest（integrity なし）</option><option value="supplied">Manifest（手動 digest）</option><option value="calculated">Manifest（後で明示計算）</option></select><input name="integrity_algorithm" placeholder="sha-256"><input name="integrity_digest" placeholder="64桁の lowercase hex"><button>登録</button></form>';
echo '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="publication"><h2>Manifest 公開設定</h2><input name="uuid" placeholder="UUID" required><select name="publication_mode"><option value="direct">直接 AR-XML（Manifest なし）</option><option value="without-integrity">Manifest（integrity なし）</option><option value="supplied">Manifest（手動 digest）</option></select><input name="integrity_algorithm" placeholder="sha-256"><input name="integrity_digest" placeholder="64桁の lowercase hex"><button>設定を保存</button></form>';
echo '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="calculate-integrity"><h2>Manifest digest の明示計算</h2><input name="uuid" placeholder="UUID" required><button>Calculate and pin current digest</button></form>';
echo '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="manifest-preview"><h2>Manifest preview</h2><input name="uuid" placeholder="UUID" required><button>Preview</button></form>';
echo '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="location"><h2>場所更新</h2><input name="uuid" placeholder="UUID" required><input name="location" placeholder="https://..." required><input name="entity_id" placeholder="Entity URI"><button>更新</button></form>';
echo '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="transition"><h2>Lifecycle</h2><input name="uuid" placeholder="UUID" required><select name="state"><option>ACTIVE</option><option>SUSPENDED</option><option>RETIRED</option></select><input name="reason" placeholder="理由"><button>変更</button></form>';
echo '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="resolution-test"><h2>公開解決テスト</h2><input name="uuid" placeholder="UUID" required><button>テスト</button></form>';
// 検索条件とページサイズをSQLのLIMIT/OFFSETへ渡し、全件をPHPへ読み込まない。
$listRows = $recordQuery->search($listQuery->needle, $listQuery->perPage + 1, $listQuery->offset());
$hasMore = count($listRows) > $listQuery->perPage;
$listRows = array_slice($listRows, 0, $listQuery->perPage);
echo '<form method="get"><h2>Records</h2><label>検索 <input name="q" maxlength="200" value="' . $esc($listQuery->needle) . '"></label><label>件数 <input name="per_page" type="number" min="1" max="50" value="' . $listQuery->perPage . '"></label><button>検索</button></form><ul>';
foreach ($listRows as $record) {
    echo '<li>' . $esc($record->anchor->value) . ' — ' . $esc($record->state->value) . ' — ' . $esc($record->location->value) . '</li>';
}
$previous = $listQuery->page > 1 ? '<a href="?q=' . rawurlencode($listQuery->needle) . '&page=' . ($listQuery->page - 1) . '&per_page=' . $listQuery->perPage . '">前へ</a> ' : '';
$next = $hasMore ? '<a href="?q=' . rawurlencode($listQuery->needle) . '&page=' . ($listQuery->page + 1) . '&per_page=' . $listQuery->perPage . '">次へ</a>' : '';
echo '</ul><p>ページ ' . $listQuery->page . ' ' . $previous . $next . '</p><form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="logout"><button>ログアウト</button></form>';
