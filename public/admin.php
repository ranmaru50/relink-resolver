<?php
// public/admin.php
// 認証済み管理操作のHTTPアダプタ。画面構成と表示だけを担い、業務規則はResolverServiceへ委譲する。

declare(strict_types=1);

use Relink\Resolver\Adapters\ConfiguredAdminCredentialVerifier;
use Relink\Resolver\Adapters\SqliteAdminLoginThrottleStore;
use Relink\Resolver\Adapters\SqliteResolverRepository;
use Relink\Resolver\Application\AdminAuthenticationService;
use Relink\Resolver\Application\AdminListQuery;
use Relink\Resolver\Application\AdminRequestException;
use Relink\Resolver\Application\AdminRequestGuard;
use Relink\Resolver\Application\AdminSession;
use Relink\Resolver\Application\AdminSessionPolicy;
use Relink\Resolver\Application\ResolverService;
use Relink\Resolver\Application\TrustedProxyPolicy;
use Relink\Resolver\Domain\LifecycleState;
use Relink\Resolver\Domain\ResolverHistoryEntry;
use Relink\Resolver\Domain\ResolverRecord;
use Relink\Resolver\Ports\AdminRecordQuery;

$config = require dirname(__DIR__) . '/bootstrap.php';

/** SQLiteアダプタを管理面専用の一覧検索ポートとして受け渡す。 */
function admin_record_query(SqliteResolverRepository $repository): AdminRecordQuery
{
    return $repository;
}

/** HTMLコンテキストへ安全に値を出力する。 */
function admin_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 管理画面内のルートをクエリ形式のURLへ変換する。 */
/** @param array<string, string|int> $parameters */
function admin_url(string $route = 'overview', array $parameters = []): string
{
    $query = ['route' => $route] + $parameters;
    return 'admin.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

/** ライフサイクル状態の表示名を返す。 */
function admin_state_label(LifecycleState $state): string
{
    return match ($state) {
        LifecycleState::ACTIVE => 'ACTIVE（公開中）',
        LifecycleState::SUSPENDED => 'SUSPENDED（停止中）',
        LifecycleState::RETIRED => 'RETIRED（廃止）',
    };
}

/** 状態バッジのCSSクラスを返す。 */
function admin_state_class(LifecycleState $state): string
{
    return strtolower($state->value);
}

/** 現在の状態から実行可能な遷移だけを表示する。 */
/** @return list<array{state: LifecycleState, label: string, class: string}> */
function admin_available_transitions(LifecycleState $state): array
{
    return match ($state) {
        LifecycleState::ACTIVE => [
            ['state' => LifecycleState::SUSPENDED, 'label' => 'Suspend（停止）', 'class' => 'button-secondary'],
            ['state' => LifecycleState::RETIRED, 'label' => 'Retire（廃止）', 'class' => 'button-danger'],
        ],
        LifecycleState::SUSPENDED => [
            ['state' => LifecycleState::ACTIVE, 'label' => 'Reactivate（再開）', 'class' => 'button-primary'],
            ['state' => LifecycleState::RETIRED, 'label' => 'Retire（廃止）', 'class' => 'button-danger'],
        ],
        LifecycleState::RETIRED => [],
    };
}

/** フラッシュメッセージを次のGETへ引き継ぐ。 */
function admin_set_flash(string $type, string $message): void
{
    $_SESSION['admin_flash'] = ['type' => $type, 'message' => $message];
}

/** @return array{type: string, message: string}|null */
function admin_consume_flash(): ?array
{
    $flash = $_SESSION['admin_flash'] ?? null;
    unset($_SESSION['admin_flash']);
    if (!is_array($flash) || !isset($flash['type'], $flash['message'])) {
        return null;
    }
    return ['type' => (string) $flash['type'], 'message' => (string) $flash['message']];
}

/** 共通のHTML開始部分と認証済みナビゲーションを出力する。 */
function admin_render_start(string $title, bool $authenticated, string $csrf = '', string $activeRoute = ''): void
{
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="description" content="RELink Reference Resolver 管理画面"><link rel="stylesheet" href="assets/admin.css"><title>' . admin_escape($title) . ' | RELink Resolver</title></head><body>';
    if (!$authenticated) {
        echo '<main class="login-shell">';
        return;
    }
    echo '<header class="site-header"><div class="header-inner"><a class="brand" href="' . admin_escape(admin_url()) . '"><span class="brand-mark">R</span><span>RELink Resolver</span></a><nav aria-label="メインナビゲーション"><a class="' . ($activeRoute === 'overview' ? 'is-active' : '') . '" href="' . admin_escape(admin_url()) . '">Overview</a><a class="' . ($activeRoute === 'records' ? 'is-active' : '') . '" href="' . admin_escape(admin_url('records')) . '">Records</a><a class="' . ($activeRoute === 'register' ? 'is-active' : '') . '" href="' . admin_escape(admin_url('register')) . '">Register</a></nav><div class="account"><span class="account-name">管理者</span><form method="post" class="inline-form"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="' . admin_escape($csrf) . '"><button type="submit" class="button-link">ログアウト</button></form></div></div></header><main class="page-shell">';
}

/** HTMLの共通終了部分を出力する。 */
function admin_render_end(): void
{
    echo '</main><footer class="site-footer">RELink Reference Resolver 0.1 Reference UI</footer></body></html>';
}

/** ページ見出しを出力する。 */
function admin_render_page_heading(string $eyebrow, string $heading, string $description = ''): void
{
    echo '<div class="page-heading"><p class="eyebrow">' . admin_escape($eyebrow) . '</p><h1>' . admin_escape($heading) . '</h1>';
    if ($description !== '') {
        echo '<p class="lede">' . admin_escape($description) . '</p>';
    }
    echo '</div>';
}

/** フラッシュメッセージを文脈付きで出力する。 */
/** @param array{type: string, message: string}|null $flash */
function admin_render_flash(?array $flash): void
{
    if ($flash === null) {
        return;
    }
    $type = in_array($flash['type'], ['success', 'error', 'notice'], true) ? $flash['type'] : 'notice';
    $heading = $type === 'success' ? '完了' : ($type === 'error' ? 'エラー' : 'お知らせ');
    echo '<div class="alert alert-' . admin_escape($type) . '" role="alert"><strong>' . $heading . '</strong><span>' . admin_escape($flash['message']) . '</span></div>';
}

/** フォームのCSRF hidden fieldを出力する。 */
function admin_csrf_field(string $csrf): string
{
    return '<input type="hidden" name="csrf" value="' . admin_escape($csrf) . '">';
}

/** 認証済みセッションを検証し、必要ならログインを試行する。 */
/** @param array<string, mixed> $config */
function admin_authenticated(array $config, AdminAuthenticationService $authentication, AdminSessionPolicy $sessionPolicy, ?string &$loginError = null): bool
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
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST' || ($_POST['action'] ?? '') !== 'login') {
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
    $loginError = 'ユーザー名またはパスワードを確認してください。';
    return false;
}

/** ログイン画面を出力する。 */
function admin_render_login(?string $loginError = null): void
{
    admin_render_start('ログイン', false);
    echo '<div class="login-card"><div class="login-emblem">R</div><p class="eyebrow">REFERENCE RESOLVER</p><h1>管理画面にログイン</h1><p class="lede">登録情報と公開設定を安全に管理します。</p>';
    if ($loginError !== null) {
        echo '<div class="alert alert-error" role="alert"><strong>ログインできません</strong><span>' . admin_escape($loginError) . '</span></div>';
    }
    echo '<form method="post" class="stack-form"><input type="hidden" name="action" value="login"><div class="field"><label for="username">ユーザー名</label><input id="username" name="username" autocomplete="username" required></div><div class="field"><label for="password">パスワード</label><input id="password" name="password" type="password" autocomplete="current-password" required></div><button type="submit" class="button-primary button-wide">ログイン</button></form></div>';
    admin_render_end();
}

/** 状態に応じたバッジを出力する。 */
function admin_render_state(LifecycleState $state): void
{
    echo '<span class="status-badge status-' . admin_escape(admin_state_class($state)) . '"><span class="status-dot" aria-hidden="true"></span>' . admin_escape(admin_state_label($state)) . '</span>';
}

/** 管理トップを出力する。 */
/** @param array{type: string, message: string}|null $flash */
function admin_render_overview(string $csrf, ?array $flash): void
{
    admin_render_start('Overview', true, $csrf, 'overview');
    admin_render_flash($flash);
    admin_render_page_heading('OVERVIEW', '運用の概要', 'RELink Resolver の日常的な管理操作をここから開始できます。');
    echo '<section class="hero-panel"><div><p class="eyebrow">OPERATOR WORKSPACE</p><h2>安全で分かりやすいレコード管理</h2><p>Resolver は Anchor UUID を Canonical Entity Identity と Description Location に結び付けます。既存レコードの変更は詳細画面から行えます。</p></div><a class="button-primary" href="' . admin_escape(admin_url('register')) . '">新しいレコードを登録</a></section><div class="card-grid"><a class="action-card" href="' . admin_escape(admin_url('records')) . '"><span class="card-kicker">BROWSE</span><h2>Records</h2><p>登録済みレコードを検索し、状態と公開先を確認します。</p><span class="card-link">レコード一覧を見る →</span></a><a class="action-card" href="' . admin_escape(admin_url('register')) . '"><span class="card-kicker">CREATE</span><h2>Register</h2><p>UUID、Entity Identity、Description Location を入力して登録します。</p><span class="card-link">登録フォームを開く →</span></a><section class="info-card"><span class="card-kicker">BOUNDARY</span><h2>運用上の注意</h2><p>公開解決の成功は Trust、認証、AR-XML の到達性、Capability の利用可能性を証明しません。</p></section></div><section class="section-block"><div class="section-heading"><div><p class="eyebrow">QUICK REFERENCE</p><h2>状態の意味</h2></div><a href="' . admin_escape(admin_url('records')) . '">一覧を確認</a></div><div class="state-reference"><div><span class="status-badge status-active"><span class="status-dot" aria-hidden="true"></span>ACTIVE</span><p>公開 Resolver は 303 で Description Location へ転送します。</p></div><div><span class="status-badge status-suspended"><span class="status-dot" aria-hidden="true"></span>SUSPENDED</span><p>公開 Resolver は 404 を返し、転送しません。</p></div><div><span class="status-badge status-retired"><span class="status-dot" aria-hidden="true"></span>RETIRED</span><p>終端状態です。公開 Resolver は 410 を返します。</p></div></div></section>';
    admin_render_end();
}

/** @param list<ResolverRecord> $records */
function admin_render_record_rows(array $records): void
{
    if ($records === []) {
        echo '<div class="empty-state"><h2>レコードが見つかりません</h2><p>検索条件を変えるか、最初のレコードを登録してください。</p><a class="button-primary" href="' . admin_escape(admin_url('register')) . '">レコードを登録</a></div>';
        return;
    }
    echo '<div class="table-wrap"><table><caption class="sr-only">Resolver レコード一覧</caption><thead><tr><th scope="col">Anchor UUID</th><th scope="col">状態</th><th scope="col">Entity Identity</th><th scope="col">Description Location</th><th scope="col"><span class="sr-only">操作</span></th></tr></thead><tbody>';
    foreach ($records as $record) {
        echo '<tr><td><a class="record-id" href="' . admin_escape(admin_url('record', ['uuid' => $record->anchor->value])) . '">' . admin_escape($record->anchor->value) . '</a></td><td>';
        admin_render_state($record->state);
        echo '</td><td><code class="breakable">' . admin_escape($record->entityId) . '</code></td><td><code class="breakable">' . admin_escape($record->location->value) . '</code></td><td><a class="text-link" href="' . admin_escape(admin_url('record', ['uuid' => $record->anchor->value])) . '">詳細 →</a></td></tr>';
    }
    echo '</tbody></table></div>';
}

/**
 * @param list<ResolverRecord> $records
 * @param array{type: string, message: string}|null $flash
 */
function admin_render_records(array $records, AdminListQuery $listQuery, bool $hasMore, string $csrf, ?array $flash): void
{
    admin_render_start('Records', true, $csrf, 'records');
    admin_render_flash($flash);
    admin_render_page_heading('RECORDS', 'レコード一覧', 'Anchor UUID、状態、Identity、公開先を確認できます。');
    echo '<section class="toolbar"><form method="get" class="search-form"><input type="hidden" name="route" value="records"><div class="field search-field"><label for="record-search">検索</label><input id="record-search" name="q" maxlength="200" value="' . admin_escape($listQuery->needle) . '" placeholder="UUID または Entity Identity"></div><div class="field page-size-field"><label for="per-page">表示件数</label><select id="per-page" name="per_page"><option value="10"' . ($listQuery->perPage === 10 ? ' selected' : '') . '>10件</option><option value="20"' . ($listQuery->perPage === 20 ? ' selected' : '') . '>20件</option><option value="50"' . ($listQuery->perPage === 50 ? ' selected' : '') . '>50件</option></select></div><button type="submit" class="button-secondary">検索</button></form><a class="button-primary" href="' . admin_escape(admin_url('register')) . '">＋ 登録</a></section><section class="panel"><div class="panel-heading"><div><h2>登録済みレコード</h2><p class="muted">ページ ' . $listQuery->page . ' ・ 最大 ' . $listQuery->perPage . ' 件</p></div></div>';
    admin_render_record_rows($records);
    $previous = $listQuery->page > 1 ? '<a class="button-secondary" href="' . admin_escape(admin_url('records', ['q' => $listQuery->needle, 'page' => $listQuery->page - 1, 'per_page' => $listQuery->perPage])) . '">← 前へ</a>' : '<span></span>';
    $next = $hasMore ? '<a class="button-secondary" href="' . admin_escape(admin_url('records', ['q' => $listQuery->needle, 'page' => $listQuery->page + 1, 'per_page' => $listQuery->perPage])) . '">次へ →</a>' : '<span></span>';
    echo '<div class="pagination">' . $previous . '<span>ページ ' . $listQuery->page . '</span>' . $next . '</div></section>';
    admin_render_end();
}

/** 登録フォームを出力する。 */
/** @param array{type: string, message: string}|null $flash */
function admin_render_register(string $csrf, ?array $flash): void
{
    admin_render_start('Register', true, $csrf, 'register');
    admin_render_flash($flash);
    admin_render_page_heading('REGISTER', 'レコードを登録', 'Resolver が管理する3つの識別情報を入力してください。');
    echo '<section class="panel narrow-panel"><form method="post" class="stack-form"><input type="hidden" name="action" value="register">' . admin_csrf_field($csrf) . '<div class="field"><label for="register-uuid">Anchor UUID <span class="required">必須</span></label><input id="register-uuid" name="uuid" required aria-describedby="uuid-help" placeholder="550e8400-e29b-41d4-a716-446655440000"><p id="uuid-help" class="help-text">物理アンカーを一意に識別する UUID です。</p></div><div class="field"><label for="register-entity">Canonical Entity Identity <span class="required">必須</span></label><input id="register-entity" name="entity_id" type="url" required aria-describedby="entity-help" placeholder="urn:relink:entity:example"><p id="entity-help" class="help-text">UUID とは異なる、正規エンティティの絶対 URI です。</p></div><div class="field"><label for="register-location">Description Location <span class="required">必須</span></label><input id="register-location" name="location" type="url" required aria-describedby="location-help" placeholder="https://entity.example/description.xml"><p id="location-help" class="help-text">現在の説明の取得先です。Resolver はこの値へ 303 で転送します。</p></div><div class="field"><label for="register-media">Media type <span class="optional">任意</span></label><input id="register-media" name="media_type" placeholder="application/xml"></div><details class="advanced-fields"><summary>Manifest の整合性情報（任意）</summary><p class="help-text">両方を指定した場合だけ Manifest の integrity として保存されます。</p><div class="field"><label for="register-algorithm">Integrity algorithm</label><input id="register-algorithm" name="integrity_algorithm" placeholder="sha-256"></div><div class="field"><label for="register-digest">Integrity digest</label><input id="register-digest" name="integrity_digest" placeholder="64桁の lowercase hex"></div></details><div class="form-actions"><button type="submit" class="button-primary">レコードを登録</button><a class="button-secondary" href="' . admin_escape(admin_url('records')) . '">キャンセル</a></div></form></section>';
    admin_render_end();
}

/** @param list<ResolverHistoryEntry> $history */
function admin_render_history(array $history): void
{
    if ($history === []) {
        echo '<div class="empty-substate"><p>保持されている履歴はありません。</p></div>';
        return;
    }
    echo '<div class="table-wrap"><table><caption class="sr-only">Lifecycle とレコード変更の履歴</caption><thead><tr><th scope="col">日時</th><th scope="col">イベント</th><th scope="col">状態</th><th scope="col">理由</th><th scope="col">実行者</th></tr></thead><tbody>';
    foreach ($history as $entry) {
        $stateChange = $entry->oldState !== null && $entry->newState !== null ? admin_state_label($entry->oldState) . ' → ' . admin_state_label($entry->newState) : '—';
        echo '<tr><td class="nowrap">' . admin_escape($entry->createdAt) . '</td><td>' . admin_escape($entry->eventType) . '</td><td>' . admin_escape($stateChange) . '</td><td>' . ($entry->reason === '' ? '—' : admin_escape($entry->reason)) . '</td><td>' . ($entry->actor === '' ? '—' : admin_escape($entry->actor)) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

/**
 * レコード詳細とレコード単位の操作を出力する。
 * @param list<ResolverHistoryEntry> $history
 * @param array{type: string, message: string}|null $flash
 */
function admin_render_record_detail(ResolverRecord $record, array $history, string $csrf, ?array $flash, string $manifestUrl): void
{
    admin_render_start('Record detail', true, $csrf, 'records');
    admin_render_flash($flash);
    echo '<div class="breadcrumb"><a href="' . admin_escape(admin_url('records')) . '">Records</a><span aria-hidden="true">/</span><span>Record detail</span></div><div class="detail-heading"><div><p class="eyebrow">RECORD DETAIL</p><h1><code>' . admin_escape($record->anchor->value) . '</code></h1></div><div>'; admin_render_state($record->state); echo '</div></div><div class="detail-grid"><section class="panel detail-section"><div class="section-heading"><div><p class="eyebrow">IDENTITY</p><h2>Identity</h2></div></div><dl class="definition-list"><div><dt>Anchor UUID</dt><dd><code class="breakable">' . admin_escape($record->anchor->value) . '</code></dd></div><div><dt>Canonical Entity Identity</dt><dd><code class="breakable">' . admin_escape($record->entityId) . '</code></dd></div><div><dt>Record version</dt><dd>' . $record->version . '</dd></div></dl></section><section class="panel detail-section"><div class="section-heading"><div><p class="eyebrow">RESOLUTION</p><h2>Resolution</h2></div></div><dl class="definition-list"><div><dt>Description Location</dt><dd><code class="breakable">' . admin_escape($record->location->value) . '</code></dd></div><div><dt>現在の公開動作</dt><dd>' . admin_escape(match ($record->state) { LifecycleState::ACTIVE => 'HTTP 303（Description Location へ転送）', LifecycleState::SUSPENDED => 'HTTP 404（停止中）', LifecycleState::RETIRED => 'HTTP 410（廃止済み）' }) . '</dd></div></dl><form method="post" class="action-form">' . admin_csrf_field($csrf) . '<input type="hidden" name="action" value="resolution-test"><input type="hidden" name="uuid" value="' . admin_escape($record->anchor->value) . '"><button type="submit" class="button-secondary">公開解決をテスト</button><p class="help-text">このテストは HTTP 応答だけを確認します。Trust や AR-XML の到達性は検証しません。</p></form></section><section class="panel detail-section"><div class="section-heading"><div><p class="eyebrow">LIFECYCLE</p><h2>Lifecycle</h2></div></div><p>現在の状態: '; admin_render_state($record->state); echo '</p>';
    $transitions = admin_available_transitions($record->state);
    if ($transitions === []) {
        echo '<div class="terminal-notice"><strong>RETIRED は終端状態です。</strong><p>このレコードに対する Lifecycle 遷移はありません。</p></div>';
    } else {
        echo '<div class="transition-list">';
        foreach ($transitions as $transition) {
            echo '<form method="post" class="transition-form">' . admin_csrf_field($csrf) . '<input type="hidden" name="action" value="transition"><input type="hidden" name="uuid" value="' . admin_escape($record->anchor->value) . '"><input type="hidden" name="state" value="' . admin_escape($transition['state']->value) . '"><label for="reason-' . admin_escape(strtolower($transition['state']->value)) . '">理由 <span class="optional">任意</span></label><textarea id="reason-' . admin_escape(strtolower($transition['state']->value)) . '" name="reason" rows="2" maxlength="500" placeholder="操作理由"></textarea>';
            if ($transition['state'] === LifecycleState::RETIRED) {
                echo '<label class="check-label"><input type="checkbox" name="confirm_retire" value="1" required> 廃止は取り消せないことを確認しました</label>';
            }
            echo '<button type="submit" class="' . admin_escape($transition['class']) . '">' . admin_escape($transition['label']) . '</button></form>';
        }
        echo '</div>';
    }
    echo '</section><section class="panel detail-section"><div class="section-heading"><div><p class="eyebrow">MAPPING</p><h2>Resolution mapping</h2></div></div><form method="post" class="stack-form"><input type="hidden" name="action" value="location">' . admin_csrf_field($csrf) . '<input type="hidden" name="uuid" value="' . admin_escape($record->anchor->value) . '"><div class="field"><label for="edit-location">Description Location</label><input id="edit-location" name="location" type="url" required value="' . admin_escape($record->location->value) . '"></div><div class="field"><label for="edit-entity">Canonical Entity Identity</label><input id="edit-entity" name="entity_id" type="url" required value="' . admin_escape($record->entityId) . '"></div><p class="help-text">Location を更新すると、対応する integrity 情報は ResolverService により破棄されます。</p><button type="submit" class="button-primary">マッピングを更新</button></form></section><section class="panel detail-section"><div class="section-heading"><div><p class="eyebrow">MANIFEST</p><h2>Manifest</h2></div></div><dl class="definition-list"><div><dt>公開エンドポイント</dt><dd><a class="text-link" href="' . admin_escape($manifestUrl) . '">' . admin_escape($manifestUrl) . '</a></dd></div><div><dt>Media type</dt><dd>' . ($record->mediaType === null ? '—' : '<code>' . admin_escape($record->mediaType) . '</code>') . '</dd></div><div><dt>Integrity</dt><dd>' . ($record->integrityAlgorithm === null || $record->integrityDigest === null ? '未設定' : '<code class="breakable">' . admin_escape($record->integrityAlgorithm . ': ' . $record->integrityDigest) . '</code>') . '</dd></div></dl><p class="help-text">Manifest は Resolver の説明メタデータです。公開解決や Trust の判定とは別の機能です。</p></section><section class="panel detail-section history-section"><div class="section-heading"><div><p class="eyebrow">HISTORY</p><h2>変更履歴</h2></div><span class="muted">最大100件</span></div>';
    admin_render_history($history);
    echo '</section></div>';
    admin_render_end();
}

// 管理画面の全応答でキャッシュと埋め込みを抑止し、ローカルCSSだけを許可する。
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

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
$sessionPolicy = new AdminSessionPolicy($config['admin_session_idle_seconds'], $config['admin_session_absolute_seconds']);
$loginError = null;
$action = (string) ($_POST['action'] ?? '');

// ログアウトは常にCSRFを検証し、GETでは実行できないようにする。
if ($action === 'logout') {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST' || !isset($_SESSION['admin']) || !hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('CSRF validation failed');
    }
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

if (!admin_authenticated($config, $authentication, $sessionPolicy, $loginError)) {
    header('Content-Type: text/html; charset=utf-8');
    admin_render_login($loginError);
    exit;
}
if ($action === 'login') {
    header('Location: ' . admin_url());
    exit;
}

// 認証後にだけ永続化アダプタを初期化し、未認証GETがDBを作成しないようにする。
try {
    $repository = new SqliteResolverRepository($config['database_path']);
    $recordQuery = admin_record_query($repository);
} catch (Throwable) {
    error_log('RELink admin persistence initialization failed');
    http_response_code(503);
    exit('管理サービスを利用できません。');
}
$service = new ResolverService($repository, $config['cache_max_age']);
$csrf = (string) ($_SESSION['csrf'] ?? '');

// 読み取り専用のJSON管理APIは既存契約を維持し、認証後だけ提供する。
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    $uuid = (string) ($_GET['uuid'] ?? '');
    try {
        if ($uuid !== '') {
            $record = $service->findRecord($uuid);
            echo json_encode(['record' => ['uuid' => $record->anchor->value, 'state' => $record->state->value, 'location' => $record->location->value, 'entity_id' => $record->entityId, 'version' => $record->version], 'history' => array_map(static fn (ResolverHistoryEntry $entry): array => ['id' => $entry->id, 'anchor_uuid' => $entry->anchor->value, 'event_type' => $entry->eventType, 'old_state' => $entry->oldState?->value, 'new_state' => $entry->newState?->value, 'old_location' => $entry->oldLocation?->value, 'new_location' => $entry->newLocation?->value, 'reason' => $entry->reason, 'actor' => $entry->actor, 'created_at' => $entry->createdAt], $service->history($uuid))], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } else {
            $rows = $recordQuery->search($listQuery->needle, $listQuery->perPage + 1, $listQuery->offset());
            $hasMore = count($rows) > $listQuery->perPage;
            $rows = array_slice($rows, 0, $listQuery->perPage);
            echo json_encode(['page' => $listQuery->page, 'per_page' => $listQuery->perPage, 'has_more' => $hasMore, 'records' => array_map(static fn (ResolverRecord $record): array => ['uuid' => $record->anchor->value, 'state' => $record->state->value, 'location' => $record->location->value, 'entity_id' => $record->entityId], $rows)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
    } catch (\Relink\Resolver\Application\ApplicationException $error) {
        http_response_code($error->errorCode === 'NOT_FOUND' ? 404 : 400);
        echo json_encode(['error' => $error->errorCode === 'NOT_FOUND' ? 'not_found' : 'invalid_request'], JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_request'], JSON_THROW_ON_ERROR);
    }
    exit;
}

// 変更操作はPOSTとCSRFを必須にし、処理後はPRGで詳細画面へ戻す。
$returnUuid = (string) ($_POST['uuid'] ?? '');
if ($action !== '' && !hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    exit('CSRF validation failed');
}
if ($action !== '') {
    try {
        if ($action === 'register') {
            $created = $service->register($_POST);
            $returnUuid = $created->anchor->value;
            admin_set_flash('success', 'レコードを登録しました。');
        } elseif ($action === 'location') {
            $service->updateLocation($returnUuid, (string) $_POST['location'], isset($_POST['entity_id']) ? (string) $_POST['entity_id'] : null);
            admin_set_flash('success', 'Resolution mapping を更新しました。');
        } elseif ($action === 'transition') {
            $target = (string) ($_POST['state'] ?? '');
            if (strtoupper(trim($target)) === LifecycleState::RETIRED->value && !isset($_POST['confirm_retire'])) {
                throw new \RuntimeException('RETIREMENT_CONFIRMATION_REQUIRED');
            }
            $service->transition($returnUuid, $target, (string) ($_POST['reason'] ?? ''), (string) $_SESSION['admin']);
            admin_set_flash('success', 'Lifecycle 状態を変更しました。');
        } elseif ($action === 'resolution-test') {
            $result = $service->resolve('GET', $returnUuid, []);
            $location = $result->headers['Location'] ?? null;
            admin_set_flash('notice', '公開解決テスト: HTTP ' . $result->status . ($location === null ? '' : ' / Location: ' . $location));
        } else {
            throw new \InvalidArgumentException('Unknown admin action');
        }
    } catch (Throwable $error) {
        $code = $error instanceof \Relink\Resolver\Application\ApplicationException ? $error->errorCode : $error->getMessage();
        $messages = ['INVALID_INPUT' => '入力が不正です。', 'INVALID_TRANSITION' => '許可されていない状態遷移です。', 'STATE_CONFLICT' => '別の操作で更新されたため、再読み込みしてください。', 'NOT_FOUND' => '対象レコードが見つかりません。', 'RECORD_EXISTS' => '同じ UUID は既に登録されています。', 'PERSISTENCE_FAILURE' => '永続化処理に失敗しました。', 'RETIREMENT_CONFIRMATION_REQUIRED' => '廃止する場合は確認チェックが必要です。'];
        admin_set_flash('error', '操作に失敗しました: ' . ($messages[$code] ?? '要求を処理できませんでした.'));
        error_log('RELink admin operation failed: ' . preg_replace('/[^A-Z_]/', '', (string) $code));
    }
    $redirectRoute = $returnUuid === '' || ($action === 'register' && !isset($created)) ? 'register' : 'record';
    header('Location: ' . admin_url($redirectRoute, $redirectRoute === 'record' ? ['uuid' => $returnUuid] : []));
    exit;
}

$route = strtolower((string) ($_GET['route'] ?? 'overview'));
$flash = admin_consume_flash();
header('Content-Type: text/html; charset=utf-8');
if ($route === 'register') {
    admin_render_register($csrf, $flash);
    exit;
}
if ($route === 'records') {
    $rows = $recordQuery->search($listQuery->needle, $listQuery->perPage + 1, $listQuery->offset());
    $hasMore = count($rows) > $listQuery->perPage;
    admin_render_records(array_slice($rows, 0, $listQuery->perPage), $listQuery, $hasMore, $csrf, $flash);
    exit;
}
if ($route === 'record') {
    try {
        $uuid = (string) ($_GET['uuid'] ?? '');
        $record = $service->findRecord($uuid);
        $history = $service->history($uuid);
        $manifestUrl = rtrim((string) $config['service_prefix'], '/') . '/' . rawurlencode($record->anchor->value) . '/manifest';
        admin_render_record_detail($record, $history, $csrf, $flash, $manifestUrl);
    } catch (Throwable $error) {
        http_response_code($error instanceof \Relink\Resolver\Application\ApplicationException && $error->errorCode === 'NOT_FOUND' ? 404 : 503);
        admin_render_start('Record not found', true, $csrf, 'records');
        admin_render_flash(['type' => 'error', 'message' => '対象レコードを表示できません。']);
        echo '<div class="empty-state"><h1>レコードが見つかりません</h1><p>UUIDを確認して、レコード一覧から再度お試しください。</p><a class="button-primary" href="' . admin_escape(admin_url('records')) . '">Recordsへ戻る</a></div>';
        admin_render_end();
    }
    exit;
}
admin_render_overview($csrf, $flash);
