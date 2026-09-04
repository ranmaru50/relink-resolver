<?php
// public/admin.php
// 認証済み管理操作のHTTPアダプタ。画面構成と表示だけを担い、業務規則はResolverServiceへ委譲する。

declare(strict_types=1);

use Relink\Resolver\Adapters\ConfiguredAdminCredentialVerifier;
use Relink\Resolver\Adapters\NativeAdministrativeResourceFetcher;
use Relink\Resolver\Adapters\SqliteAdminLoginThrottleStore;
use Relink\Resolver\Adapters\SqliteResolverRepository;
use Relink\Resolver\Application\AdminAuthenticationService;
use Relink\Resolver\Application\AdminListQuery;
use Relink\Resolver\Application\AdminRequestException;
use Relink\Resolver\Application\AdminRequestGuard;
use Relink\Resolver\Application\AdminSession;
use Relink\Resolver\Application\AdminSessionPolicy;
use Relink\Resolver\Application\OutboundNetworkPolicy;
use Relink\Resolver\Application\ResolverService;
use Relink\Resolver\Application\TrustedProxyPolicy;
use Relink\Resolver\Hosting\AdminManifestPublicationInput;
use Relink\Resolver\Hosting\AdminTranslator;
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
function admin_url(AdminTranslator $translator, string $route = 'overview', array $parameters = []): string
{
    $query = ['route' => $route, 'lang' => $translator->locale] + $parameters;
    return 'admin.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

/** 翻訳キーを解決し、ページ内の可変値を表示用に埋め込む。 */
function admin_text(AdminTranslator $translator, string $key, mixed ...$values): string
{
    $message = $translator->get($key);
    return $values === [] ? $message : vsprintf($message, $values);
}

/** 現在のロケールを保持したPOST hidden fieldを出力する。 */
function admin_locale_field(AdminTranslator $translator): string
{
    return '<input type="hidden" name="lang" value="' . admin_escape($translator->locale) . '">';
}

/** 管理画面の表示言語を切り替えるリンクを出力する。 */
/** @param array<string, string|int> $parameters */
function admin_render_language_switcher(AdminTranslator $translator, string $route = 'overview', array $parameters = []): void
{
    $links = [];
    foreach (AdminTranslator::supportedLocales() as $locale) {
        $target = $translator->withLocale($locale);
        $links[] = $locale === $translator->locale
            ? '<strong lang="' . admin_escape($locale) . '">' . admin_escape(admin_text($target, 'locale.' . ($locale === 'ja' ? 'japanese' : 'english'))) . '</strong>'
            : '<a lang="' . admin_escape($locale) . '" href="' . admin_escape(admin_url($target, $route, $parameters)) . '">' . admin_escape(admin_text($target, 'locale.' . ($locale === 'ja' ? 'japanese' : 'english'))) . '</a>';
    }
    echo '<div class="locale-switcher" aria-label="' . admin_escape(admin_text($translator, 'locale.switcher_label')) . '">' . implode('<span aria-hidden="true">/</span>', $links) . '</div>';
}

/** ライフサイクル状態の表示名を返す。 */
function admin_state_label(LifecycleState $state, AdminTranslator $translator): string
{
    return match ($state) {
        LifecycleState::ACTIVE => admin_text($translator, 'state.active'),
        LifecycleState::SUSPENDED => admin_text($translator, 'state.suspended'),
        LifecycleState::RETIRED => admin_text($translator, 'state.retired'),
    };
}

/** 状態バッジのCSSクラスを返す。 */
function admin_state_class(LifecycleState $state): string
{
    return strtolower($state->value);
}

/** 現在の状態から実行可能な遷移だけを表示する。 */
/** @return list<array{state: LifecycleState, label: string, class: string}> */
function admin_available_transitions(LifecycleState $state, AdminTranslator $translator): array
{
    return match ($state) {
        LifecycleState::ACTIVE => [
            ['state' => LifecycleState::SUSPENDED, 'label' => admin_text($translator, 'detail.suspend'), 'class' => 'button-secondary'],
            ['state' => LifecycleState::RETIRED, 'label' => admin_text($translator, 'detail.retire'), 'class' => 'button-danger'],
        ],
        LifecycleState::SUSPENDED => [
            ['state' => LifecycleState::ACTIVE, 'label' => admin_text($translator, 'detail.reactivate'), 'class' => 'button-primary'],
            ['state' => LifecycleState::RETIRED, 'label' => admin_text($translator, 'detail.retire'), 'class' => 'button-danger'],
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
/** @param array<string, string|int> $routeParameters */
function admin_render_start(AdminTranslator $translator, string $titleKey, bool $authenticated, string $csrf = '', string $activeRoute = '', array $routeParameters = []): void
{
    echo '<!doctype html><html lang="' . admin_escape($translator->locale) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="description" content="' . admin_escape(admin_text($translator, 'brand.description')) . '"><link rel="stylesheet" href="assets/admin.css"><title>' . admin_escape(admin_text($translator, $titleKey)) . ' | ' . admin_escape(admin_text($translator, 'brand.name')) . '</title></head><body>';
    if (!$authenticated) {
        echo '<main class="login-shell"><div class="login-locale">';
        admin_render_language_switcher($translator, $activeRoute, $routeParameters);
        echo '</div>';
        return;
    }
    echo '<header class="site-header"><div class="header-inner"><a class="brand" href="' . admin_escape(admin_url($translator)) . '"><span class="brand-mark">R</span><span>' . admin_escape(admin_text($translator, 'brand.name')) . '</span></a><nav aria-label="' . admin_escape(admin_text($translator, 'nav.label')) . '"><a class="' . ($activeRoute === 'overview' ? 'is-active' : '') . '" href="' . admin_escape(admin_url($translator)) . '">' . admin_escape(admin_text($translator, 'nav.overview')) . '</a><a class="' . ($activeRoute === 'records' ? 'is-active' : '') . '" href="' . admin_escape(admin_url($translator, 'records')) . '">' . admin_escape(admin_text($translator, 'nav.records')) . '</a><a class="' . ($activeRoute === 'register' ? 'is-active' : '') . '" href="' . admin_escape(admin_url($translator, 'register')) . '">' . admin_escape(admin_text($translator, 'nav.register')) . '</a></nav><div class="account"><span class="account-name">' . admin_escape(admin_text($translator, 'account.admin')) . '</span><div class="locale-area">';
    admin_render_language_switcher($translator, $activeRoute, $routeParameters);
    echo '</div><form method="post" class="inline-form"><input type="hidden" name="action" value="logout">' . admin_locale_field($translator) . '<input type="hidden" name="csrf" value="' . admin_escape($csrf) . '"><button type="submit" class="button-link">' . admin_escape(admin_text($translator, 'nav.logout')) . '</button></form></div></div></header><main class="page-shell">';
}

/** HTMLの共通終了部分を出力する。 */
function admin_render_end(AdminTranslator $translator): void
{
    echo '</main><footer class="site-footer">' . admin_escape(admin_text($translator, 'footer.reference_ui')) . '</footer></body></html>';
}

/** ページ見出しを出力する。 */
function admin_render_page_heading(AdminTranslator $translator, string $eyebrowKey, string $headingKey, string $descriptionKey = ''): void
{
    echo '<div class="page-heading"><p class="eyebrow">' . admin_escape(admin_text($translator, $eyebrowKey)) . '</p><h1>' . admin_escape(admin_text($translator, $headingKey)) . '</h1>';
    if ($descriptionKey !== '') {
        echo '<p class="lede">' . admin_escape(admin_text($translator, $descriptionKey)) . '</p>';
    }
    echo '</div>';
}

/** フラッシュメッセージを文脈付きで出力する。 */
/** @param array{type: string, message: string}|null $flash */
function admin_render_flash(AdminTranslator $translator, ?array $flash): void
{
    if ($flash === null) {
        return;
    }
    $type = in_array($flash['type'], ['success', 'error', 'notice'], true) ? $flash['type'] : 'notice';
    $heading = admin_text($translator, 'feedback.' . $type);
    echo '<div class="alert alert-' . admin_escape($type) . '" role="alert"><strong>' . admin_escape($heading) . '</strong><span>' . admin_escape($flash['message']) . '</span></div>';
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
    $loginError = 'login.invalid';
    return false;
}

/** ログイン画面を出力する。 */
function admin_render_login(AdminTranslator $translator, ?string $loginError = null): void
{
    admin_render_start($translator, 'login.title', false);
    echo '<div class="login-card"><div class="login-emblem">R</div><p class="eyebrow">' . admin_escape(admin_text($translator, 'login.eyebrow')) . '</p><h1>' . admin_escape(admin_text($translator, 'login.heading')) . '</h1><p class="lede">' . admin_escape(admin_text($translator, 'login.lede')) . '</p>';
    if ($loginError !== null) {
        echo '<div class="alert alert-error" role="alert"><strong>' . admin_escape(admin_text($translator, 'feedback.error')) . '</strong><span>' . admin_escape(admin_text($translator, $loginError)) . '</span></div>';
    }
    echo '<form method="post" class="stack-form"><input type="hidden" name="action" value="login">' . admin_locale_field($translator) . '<div class="field"><label for="username">' . admin_escape(admin_text($translator, 'login.username')) . '</label><input id="username" name="username" autocomplete="username" required></div><div class="field"><label for="password">' . admin_escape(admin_text($translator, 'login.password')) . '</label><input id="password" name="password" type="password" autocomplete="current-password" required></div><button type="submit" class="button-primary button-wide">' . admin_escape(admin_text($translator, 'login.submit')) . '</button></form></div>';
    admin_render_end($translator);
}

/** 状態に応じたバッジを出力する。 */
function admin_render_state(LifecycleState $state, AdminTranslator $translator): void
{
    echo '<span class="status-badge status-' . admin_escape(admin_state_class($state)) . '"><span class="status-dot" aria-hidden="true"></span>' . admin_escape(admin_state_label($state, $translator)) . '</span>';
}

/** 管理トップを出力する。 */
/** @param array{type: string, message: string}|null $flash */
function admin_render_overview(AdminTranslator $translator, string $csrf, ?array $flash): void
{
    admin_render_start($translator, 'overview.title', true, $csrf, 'overview');
    admin_render_flash($translator, $flash);
    admin_render_page_heading($translator, 'overview.eyebrow', 'overview.heading', 'overview.lede');
    echo '<section class="hero-panel"><div><p class="eyebrow">' . admin_escape(admin_text($translator, 'overview.hero.eyebrow')) . '</p><h2>' . admin_escape(admin_text($translator, 'overview.hero.heading')) . '</h2><p>' . admin_escape(admin_text($translator, 'overview.hero.body')) . '</p></div><a class="button-primary" href="' . admin_escape(admin_url($translator, 'register')) . '">' . admin_escape(admin_text($translator, 'overview.hero.action')) . '</a></section><div class="card-grid"><a class="action-card" href="' . admin_escape(admin_url($translator, 'records')) . '"><span class="card-kicker">' . admin_escape(admin_text($translator, 'overview.browse.kicker')) . '</span><h2>' . admin_escape(admin_text($translator, 'overview.browse.heading')) . '</h2><p>' . admin_escape(admin_text($translator, 'overview.browse.body')) . '</p><span class="card-link">' . admin_escape(admin_text($translator, 'overview.browse.link')) . '</span></a><a class="action-card" href="' . admin_escape(admin_url($translator, 'register')) . '"><span class="card-kicker">' . admin_escape(admin_text($translator, 'overview.create.kicker')) . '</span><h2>' . admin_escape(admin_text($translator, 'overview.create.heading')) . '</h2><p>' . admin_escape(admin_text($translator, 'overview.create.body')) . '</p><span class="card-link">' . admin_escape(admin_text($translator, 'overview.create.link')) . '</span></a><section class="info-card"><span class="card-kicker">' . admin_escape(admin_text($translator, 'overview.boundary.kicker')) . '</span><h2>' . admin_escape(admin_text($translator, 'overview.boundary.heading')) . '</h2><p>' . admin_escape(admin_text($translator, 'overview.boundary.body')) . '</p></section></div><section class="section-block"><div class="section-heading"><div><p class="eyebrow">' . admin_escape(admin_text($translator, 'overview.reference.eyebrow')) . '</p><h2>' . admin_escape(admin_text($translator, 'overview.reference.heading')) . '</h2></div><a href="' . admin_escape(admin_url($translator, 'records')) . '">' . admin_escape(admin_text($translator, 'overview.reference.link')) . '</a></div><div class="state-reference"><div>'; admin_render_state(LifecycleState::ACTIVE, $translator); echo '<p>' . admin_escape(admin_text($translator, 'overview.state.active')) . '</p></div><div>'; admin_render_state(LifecycleState::SUSPENDED, $translator); echo '<p>' . admin_escape(admin_text($translator, 'overview.state.suspended')) . '</p></div><div>'; admin_render_state(LifecycleState::RETIRED, $translator); echo '<p>' . admin_escape(admin_text($translator, 'overview.state.retired')) . '</p></div></div></section>';
    admin_render_end($translator);
}

/** @param list<ResolverRecord> $records */
function admin_render_record_rows(AdminTranslator $translator, array $records): void
{
    if ($records === []) {
        echo '<div class="empty-state"><h2>' . admin_escape(admin_text($translator, 'records.empty_heading')) . '</h2><p>' . admin_escape(admin_text($translator, 'records.empty_body')) . '</p><a class="button-primary" href="' . admin_escape(admin_url($translator, 'register')) . '">' . admin_escape(admin_text($translator, 'records.empty_action')) . '</a></div>';
        return;
    }
    echo '<div class="table-wrap"><table><caption class="sr-only">' . admin_escape(admin_text($translator, 'records.table_caption')) . '</caption><thead><tr><th scope="col">' . admin_escape(admin_text($translator, 'records.anchor_uuid')) . '</th><th scope="col">' . admin_escape(admin_text($translator, 'records.state')) . '</th><th scope="col">' . admin_escape(admin_text($translator, 'records.entity_identity')) . '</th><th scope="col">' . admin_escape(admin_text($translator, 'records.description_location')) . '</th><th scope="col"><span class="sr-only">' . admin_escape(admin_text($translator, 'records.actions')) . '</span></th></tr></thead><tbody>';
    foreach ($records as $record) {
        echo '<tr><td><a class="record-id" href="' . admin_escape(admin_url($translator, 'record', ['uuid' => $record->anchor->value])) . '">' . admin_escape($record->anchor->value) . '</a></td><td>';
        admin_render_state($record->state, $translator);
        echo '</td><td><code class="breakable">' . admin_escape($record->entityId) . '</code></td><td><code class="breakable">' . admin_escape($record->location->value) . '</code></td><td><a class="text-link" href="' . admin_escape(admin_url($translator, 'record', ['uuid' => $record->anchor->value])) . '">' . admin_escape(admin_text($translator, 'records.detail')) . '</a></td></tr>';
    }
    echo '</tbody></table></div>';
}

/**
 * @param list<ResolverRecord> $records
 * @param array{type: string, message: string}|null $flash
 */
function admin_render_records(AdminTranslator $translator, array $records, AdminListQuery $listQuery, bool $hasMore, string $csrf, ?array $flash): void
{
    admin_render_start($translator, 'records.title', true, $csrf, 'records');
    admin_render_flash($translator, $flash);
    admin_render_page_heading($translator, 'records.eyebrow', 'records.heading', 'records.lede');
    echo '<section class="toolbar"><form method="get" class="search-form"><input type="hidden" name="route" value="records">' . admin_locale_field($translator) . '<div class="field search-field"><label for="record-search">' . admin_escape(admin_text($translator, 'records.search')) . '</label><input id="record-search" name="q" maxlength="200" value="' . admin_escape($listQuery->needle) . '" placeholder="' . admin_escape(admin_text($translator, 'records.search_placeholder')) . '"></div><div class="field page-size-field"><label for="per-page">' . admin_escape(admin_text($translator, 'records.page_size')) . '</label><select id="per-page" name="per_page"><option value="10"' . ($listQuery->perPage === 10 ? ' selected' : '') . '>' . admin_escape(admin_text($translator, 'records.option_count', 10)) . '</option><option value="20"' . ($listQuery->perPage === 20 ? ' selected' : '') . '>' . admin_escape(admin_text($translator, 'records.option_count', 20)) . '</option><option value="50"' . ($listQuery->perPage === 50 ? ' selected' : '') . '>' . admin_escape(admin_text($translator, 'records.option_count', 50)) . '</option></select></div><button type="submit" class="button-secondary">' . admin_escape(admin_text($translator, 'records.search_submit')) . '</button></form><a class="button-primary" href="' . admin_escape(admin_url($translator, 'register')) . '">' . admin_escape(admin_text($translator, 'records.register')) . '</a></section><section class="panel"><div class="panel-heading"><div><h2>' . admin_escape(admin_text($translator, 'records.panel_heading')) . '</h2><p class="muted">' . admin_escape(admin_text($translator, 'records.page_summary', $listQuery->page, $listQuery->perPage)) . '</p></div></div>';
    admin_render_record_rows($translator, $records);
    $previous = $listQuery->page > 1 ? '<a class="button-secondary" href="' . admin_escape(admin_url($translator, 'records', ['q' => $listQuery->needle, 'page' => $listQuery->page - 1, 'per_page' => $listQuery->perPage])) . '">' . admin_escape(admin_text($translator, 'records.previous')) . '</a>' : '<span></span>';
    $next = $hasMore ? '<a class="button-secondary" href="' . admin_escape(admin_url($translator, 'records', ['q' => $listQuery->needle, 'page' => $listQuery->page + 1, 'per_page' => $listQuery->perPage])) . '">' . admin_escape(admin_text($translator, 'records.next')) . '</a>' : '<span></span>';
    echo '<div class="pagination">' . $previous . '<span>' . admin_escape(admin_text($translator, 'records.page', $listQuery->page)) . '</span>' . $next . '</div></section>';
    admin_render_end($translator);
}

/** レコード単位のManifest公開設定・計算・preview操作を出力する。 */
function admin_render_manifest_controls(AdminTranslator $translator, ResolverRecord $record, string $csrf): void
{
    $mode = !$record->manifestEnabled ? 'direct' : match ($record->integritySource) {
        'SUPPLIED' => 'supplied',
        'CALCULATED' => 'calculated',
        default => 'without-integrity',
    };
    $options = [
        'direct' => 'register.mode_direct',
        'without-integrity' => 'register.mode_without_integrity',
        'supplied' => 'register.mode_supplied',
        'calculated' => 'register.mode_calculated',
    ];
    echo '<section class="panel detail-section manifest-controls"><div class="section-heading"><div><p class="eyebrow">' . admin_escape(admin_text($translator, 'detail.manifest.eyebrow')) . '</p><h2>' . admin_escape(admin_text($translator, 'detail.manifest.heading')) . '</h2></div></div><form method="post" class="stack-form"><input type="hidden" name="action" value="publication">' . admin_locale_field($translator) . admin_csrf_field($csrf) . '<input type="hidden" name="uuid" value="' . admin_escape($record->anchor->value) . '"><div class="field"><label for="manifest-mode">' . admin_escape(admin_text($translator, 'detail.manifest_mode')) . '</label><select id="manifest-mode" name="publication_mode">';
    foreach ($options as $value => $labelKey) {
        echo '<option value="' . admin_escape($value) . '"' . ($mode === $value ? ' selected' : '') . '>' . admin_escape(admin_text($translator, $labelKey)) . '</option>';
    }
    $algorithm = $mode === 'supplied' ? ($record->integrityAlgorithm ?? '') : '';
    $digest = $mode === 'supplied' ? ($record->integrityDigest ?? '') : '';
    echo '</select><p class="help-text">' . admin_escape(admin_text($translator, 'detail.manifest_mode_help')) . '</p></div><div class="field"><label for="manifest-algorithm">' . admin_escape(admin_text($translator, 'register.algorithm')) . '</label><input id="manifest-algorithm" name="integrity_algorithm" value="' . admin_escape($algorithm) . '" placeholder="' . admin_escape(admin_text($translator, 'register.algorithm_placeholder')) . '"></div><div class="field"><label for="manifest-digest">' . admin_escape(admin_text($translator, 'register.digest')) . '</label><input id="manifest-digest" name="integrity_digest" value="' . admin_escape($digest) . '" placeholder="' . admin_escape(admin_text($translator, 'register.digest_placeholder')) . '"></div><button type="submit" class="button-primary">' . admin_escape(admin_text($translator, 'detail.manifest_save')) . '</button></form>';
    if ($record->manifestEnabled) {
        echo '<form method="post" class="action-form"><input type="hidden" name="action" value="calculate-integrity">' . admin_locale_field($translator) . admin_csrf_field($csrf) . '<input type="hidden" name="uuid" value="' . admin_escape($record->anchor->value) . '"><button type="submit" class="button-secondary">' . admin_escape(admin_text($translator, 'detail.manifest_calculate')) . '</button><p class="help-text">' . admin_escape(admin_text($translator, 'detail.manifest_calculate_help')) . '</p></form>';
    }
    echo '<form method="post" class="action-form"><input type="hidden" name="action" value="manifest-preview">' . admin_locale_field($translator) . admin_csrf_field($csrf) . '<input type="hidden" name="uuid" value="' . admin_escape($record->anchor->value) . '"><button type="submit" class="button-secondary">' . admin_escape(admin_text($translator, 'detail.manifest_preview')) . '</button><p class="help-text">' . admin_escape(admin_text($translator, 'detail.manifest_preview_help')) . '</p></form></section>';
}

/** 登録フォームを出力する。 */
/** @param array{type: string, message: string}|null $flash */
function admin_render_register(AdminTranslator $translator, string $csrf, ?array $flash): void
{
    admin_render_start($translator, 'register.title', true, $csrf, 'register');
    admin_render_flash($translator, $flash);
    admin_render_page_heading($translator, 'register.eyebrow', 'register.heading', 'register.lede');
    $required = '<span class="required">' . admin_escape(admin_text($translator, 'register.required')) . '</span>';
    $optional = '<span class="optional">' . admin_escape(admin_text($translator, 'register.optional')) . '</span>';
    echo '<section class="panel narrow-panel"><form method="post" class="stack-form"><input type="hidden" name="action" value="register">' . admin_locale_field($translator) . admin_csrf_field($csrf) . '<div class="field"><label for="register-uuid">' . admin_escape(admin_text($translator, 'register.uuid')) . ' ' . $required . '</label><input id="register-uuid" name="uuid" required aria-describedby="uuid-help" placeholder="' . admin_escape(admin_text($translator, 'register.uuid_placeholder')) . '"><p id="uuid-help" class="help-text">' . admin_escape(admin_text($translator, 'register.uuid_help')) . '</p></div><div class="field"><label for="register-entity">' . admin_escape(admin_text($translator, 'register.entity')) . ' ' . $required . '</label><input id="register-entity" name="entity_id" type="url" required aria-describedby="entity-help" placeholder="' . admin_escape(admin_text($translator, 'register.entity_placeholder')) . '"><p id="entity-help" class="help-text">' . admin_escape(admin_text($translator, 'register.entity_help')) . '</p></div><div class="field"><label for="register-location">' . admin_escape(admin_text($translator, 'register.location')) . ' ' . $required . '</label><input id="register-location" name="location" type="url" required aria-describedby="location-help" placeholder="' . admin_escape(admin_text($translator, 'register.location_placeholder')) . '"><p id="location-help" class="help-text">' . admin_escape(admin_text($translator, 'register.location_help')) . '</p></div><div class="field"><label for="register-media">' . admin_escape(admin_text($translator, 'register.media_type')) . ' ' . $optional . '</label><input id="register-media" name="media_type" placeholder="' . admin_escape(admin_text($translator, 'register.media_placeholder')) . '"></div><div class="field"><label for="register-publication-mode">' . admin_escape(admin_text($translator, 'register.publication_mode')) . '</label><select id="register-publication-mode" name="publication_mode"><option value="direct">' . admin_escape(admin_text($translator, 'register.mode_direct')) . '</option><option value="without-integrity" selected>' . admin_escape(admin_text($translator, 'register.mode_without_integrity')) . '</option><option value="supplied">' . admin_escape(admin_text($translator, 'register.mode_supplied')) . '</option><option value="calculated">' . admin_escape(admin_text($translator, 'register.mode_calculated')) . '</option></select><p class="help-text">' . admin_escape(admin_text($translator, 'register.publication_mode_help')) . '</p></div><details class="advanced-fields"><summary>' . admin_escape(admin_text($translator, 'register.integrity_summary')) . '</summary><p class="help-text">' . admin_escape(admin_text($translator, 'register.integrity_help')) . '</p><div class="field"><label for="register-algorithm">' . admin_escape(admin_text($translator, 'register.algorithm')) . '</label><input id="register-algorithm" name="integrity_algorithm" placeholder="' . admin_escape(admin_text($translator, 'register.algorithm_placeholder')) . '"></div><div class="field"><label for="register-digest">' . admin_escape(admin_text($translator, 'register.digest')) . '</label><input id="register-digest" name="integrity_digest" placeholder="' . admin_escape(admin_text($translator, 'register.digest_placeholder')) . '"></div></details><div class="form-actions"><button type="submit" class="button-primary">' . admin_escape(admin_text($translator, 'register.submit')) . '</button><a class="button-secondary" href="' . admin_escape(admin_url($translator, 'records')) . '">' . admin_escape(admin_text($translator, 'register.cancel')) . '</a></div></form></section>';
    admin_render_end($translator);
}

/** @param list<ResolverHistoryEntry> $history */
function admin_render_history(AdminTranslator $translator, array $history): void
{
    if ($history === []) {
        echo '<div class="empty-substate"><p>' . admin_escape(admin_text($translator, 'detail.history_empty')) . '</p></div>';
        return;
    }
    echo '<div class="table-wrap"><table><caption class="sr-only">' . admin_escape(admin_text($translator, 'detail.history_caption')) . '</caption><thead><tr><th scope="col">' . admin_escape(admin_text($translator, 'detail.history_time')) . '</th><th scope="col">' . admin_escape(admin_text($translator, 'detail.history_event')) . '</th><th scope="col">' . admin_escape(admin_text($translator, 'detail.history_state')) . '</th><th scope="col">' . admin_escape(admin_text($translator, 'detail.history_reason')) . '</th><th scope="col">' . admin_escape(admin_text($translator, 'detail.history_actor')) . '</th></tr></thead><tbody>';
    foreach ($history as $entry) {
        $stateChange = $entry->oldState !== null && $entry->newState !== null ? admin_state_label($entry->oldState, $translator) . ' → ' . admin_state_label($entry->newState, $translator) : admin_text($translator, 'detail.history_no_value');
        echo '<tr><td class="nowrap">' . admin_escape($entry->createdAt) . '</td><td>' . admin_escape($entry->eventType) . '</td><td>' . admin_escape($stateChange) . '</td><td>' . ($entry->reason === '' ? admin_escape(admin_text($translator, 'detail.history_no_value')) : admin_escape($entry->reason)) . '</td><td>' . ($entry->actor === '' ? admin_escape(admin_text($translator, 'detail.history_no_value')) : admin_escape($entry->actor)) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

/**
 * レコード詳細とレコード単位の操作を出力する。
 * @param list<ResolverHistoryEntry> $history
 * @param array{type: string, message: string}|null $flash
 */
function admin_render_record_detail(AdminTranslator $translator, ResolverRecord $record, array $history, string $csrf, ?array $flash, string $manifestUrl): void
{
    admin_render_start($translator, 'detail.title', true, $csrf, 'records', ['uuid' => $record->anchor->value]);
    admin_render_flash($translator, $flash);
    $publicBehavior = match ($record->state) { LifecycleState::ACTIVE => admin_text($translator, 'detail.public_active'), LifecycleState::SUSPENDED => admin_text($translator, 'detail.public_suspended'), LifecycleState::RETIRED => admin_text($translator, 'detail.public_retired') };
    admin_render_manifest_controls($translator, $record, $csrf);
    echo '<div class="breadcrumb"><a href="' . admin_escape(admin_url($translator, 'records')) . '">' . admin_escape(admin_text($translator, 'nav.records')) . '</a><span aria-hidden="true">/</span><span>' . admin_escape(admin_text($translator, 'detail.breadcrumb')) . '</span></div><div class="detail-heading"><div><p class="eyebrow">' . admin_escape(admin_text($translator, 'detail.eyebrow')) . '</p><h1><code>' . admin_escape($record->anchor->value) . '</code></h1></div><div>'; admin_render_state($record->state, $translator); echo '</div></div><div class="detail-grid"><section class="panel detail-section"><div class="section-heading"><div><p class="eyebrow">' . admin_escape(admin_text($translator, 'detail.identity.eyebrow')) . '</p><h2>' . admin_escape(admin_text($translator, 'detail.identity.heading')) . '</h2></div></div><dl class="definition-list"><div><dt>' . admin_escape(admin_text($translator, 'detail.anchor_uuid')) . '</dt><dd><code class="breakable">' . admin_escape($record->anchor->value) . '</code></dd></div><div><dt>' . admin_escape(admin_text($translator, 'detail.entity_identity')) . '</dt><dd><code class="breakable">' . admin_escape($record->entityId) . '</code></dd></div><div><dt>' . admin_escape(admin_text($translator, 'detail.version')) . '</dt><dd>' . $record->version . '</dd></div></dl></section><section class="panel detail-section"><div class="section-heading"><div><p class="eyebrow">' . admin_escape(admin_text($translator, 'detail.resolution.eyebrow')) . '</p><h2>' . admin_escape(admin_text($translator, 'detail.resolution.heading')) . '</h2></div></div><dl class="definition-list"><div><dt>' . admin_escape(admin_text($translator, 'records.description_location')) . '</dt><dd><code class="breakable">' . admin_escape($record->location->value) . '</code></dd></div><div><dt>' . admin_escape(admin_text($translator, 'detail.public_behavior')) . '</dt><dd>' . admin_escape($publicBehavior) . '</dd></div></dl><form method="post" class="action-form">' . admin_locale_field($translator) . admin_csrf_field($csrf) . '<input type="hidden" name="action" value="resolution-test"><input type="hidden" name="uuid" value="' . admin_escape($record->anchor->value) . '"><button type="submit" class="button-secondary">' . admin_escape(admin_text($translator, 'detail.resolution_test')) . '</button><p class="help-text">' . admin_escape(admin_text($translator, 'detail.resolution_help')) . '</p></form></section><section class="panel detail-section"><div class="section-heading"><div><p class="eyebrow">' . admin_escape(admin_text($translator, 'detail.lifecycle.eyebrow')) . '</p><h2>' . admin_escape(admin_text($translator, 'detail.lifecycle.heading')) . '</h2></div></div><p>' . admin_escape(admin_text($translator, 'detail.current_state')) . ' '; admin_render_state($record->state, $translator); echo '</p>';
    $transitions = admin_available_transitions($record->state, $translator);
    if ($transitions === []) {
        echo '<div class="terminal-notice"><strong>' . admin_escape(admin_text($translator, 'detail.retired_heading')) . '</strong><p>' . admin_escape(admin_text($translator, 'detail.retired_body')) . '</p></div>';
    } else {
        echo '<div class="transition-list">';
        foreach ($transitions as $transition) {
            echo '<form method="post" class="transition-form">' . admin_locale_field($translator) . admin_csrf_field($csrf) . '<input type="hidden" name="action" value="transition"><input type="hidden" name="uuid" value="' . admin_escape($record->anchor->value) . '"><input type="hidden" name="state" value="' . admin_escape($transition['state']->value) . '"><label for="reason-' . admin_escape(strtolower($transition['state']->value)) . '">' . admin_escape(admin_text($translator, 'detail.reason')) . ' <span class="optional">' . admin_escape(admin_text($translator, 'register.optional')) . '</span></label><textarea id="reason-' . admin_escape(strtolower($transition['state']->value)) . '" name="reason" rows="2" maxlength="500" placeholder="' . admin_escape(admin_text($translator, 'detail.reason_placeholder')) . '"></textarea>';
            if ($transition['state'] === LifecycleState::RETIRED) {
                echo '<label class="check-label"><input type="checkbox" name="confirm_retire" value="1" required> ' . admin_escape(admin_text($translator, 'detail.retire_confirmation')) . '</label>';
            }
            echo '<button type="submit" class="' . admin_escape($transition['class']) . '">' . admin_escape($transition['label']) . '</button></form>';
        }
        echo '</div>';
    }
    echo '</section><section class="panel detail-section"><div class="section-heading"><div><p class="eyebrow">' . admin_escape(admin_text($translator, 'detail.mapping.eyebrow')) . '</p><h2>' . admin_escape(admin_text($translator, 'detail.mapping.heading')) . '</h2></div></div><form method="post" class="stack-form"><input type="hidden" name="action" value="location">' . admin_locale_field($translator) . admin_csrf_field($csrf) . '<input type="hidden" name="uuid" value="' . admin_escape($record->anchor->value) . '"><div class="field"><label for="edit-location">' . admin_escape(admin_text($translator, 'records.description_location')) . '</label><input id="edit-location" name="location" type="url" required value="' . admin_escape($record->location->value) . '"></div><div class="field"><label for="edit-entity">' . admin_escape(admin_text($translator, 'detail.entity_identity')) . '</label><input id="edit-entity" name="entity_id" type="url" required value="' . admin_escape($record->entityId) . '"></div><p class="help-text">' . admin_escape(admin_text($translator, 'detail.mapping_help')) . '</p><button type="submit" class="button-primary">' . admin_escape(admin_text($translator, 'detail.mapping_submit')) . '</button></form></section><section class="panel detail-section"><div class="section-heading"><div><p class="eyebrow">' . admin_escape(admin_text($translator, 'detail.manifest.eyebrow')) . '</p><h2>' . admin_escape(admin_text($translator, 'detail.manifest.heading')) . '</h2></div></div><dl class="definition-list"><div><dt>' . admin_escape(admin_text($translator, 'detail.manifest_endpoint')) . '</dt><dd><a class="text-link" href="' . admin_escape($manifestUrl) . '">' . admin_escape($manifestUrl) . '</a></dd></div><div><dt>' . admin_escape(admin_text($translator, 'detail.media_type')) . '</dt><dd>' . ($record->mediaType === null ? admin_escape(admin_text($translator, 'detail.not_set')) : '<code>' . admin_escape($record->mediaType) . '</code>') . '</dd></div><div><dt>' . admin_escape(admin_text($translator, 'detail.integrity')) . '</dt><dd>' . ($record->integrityAlgorithm === null || $record->integrityDigest === null ? admin_escape(admin_text($translator, 'detail.not_set')) : '<code class="breakable">' . admin_escape($record->integrityAlgorithm . ': ' . $record->integrityDigest) . '</code>') . '</dd></div></dl><p class="help-text">' . admin_escape(admin_text($translator, 'detail.manifest_help')) . '</p></section><section class="panel detail-section history-section"><div class="section-heading"><div><p class="eyebrow">' . admin_escape(admin_text($translator, 'detail.history.eyebrow')) . '</p><h2>' . admin_escape(admin_text($translator, 'detail.history.heading')) . '</h2></div><span class="muted">' . admin_escape(admin_text($translator, 'detail.history_limit')) . '</span></div>';
    admin_render_history($translator, $history);
    echo '</section></div>';
    admin_render_end($translator);
}

// 管理画面の全応答でキャッシュと埋め込みを抑止し、ローカルCSSだけを許可する。
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

$requestedLocale = null;
if (isset($_GET['lang']) && is_string($_GET['lang'])) {
    $requestedLocale = $_GET['lang'];
} elseif (isset($_POST['lang']) && is_string($_POST['lang'])) {
    $requestedLocale = $_POST['lang'];
}
$translator = AdminTranslator::fromInput(
    $requestedLocale,
    isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : null,
);

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
    exit($error->status === 413 ? admin_text($translator, 'error.request_too_large') : admin_text($translator, 'error.invalid_request'));
}
if ($config['configuration_error']) {
    error_log('RELink admin configuration is invalid for production');
    http_response_code(503);
    exit(admin_text($translator, 'error.configuration'));
}

$secureRequest = TrustedProxyPolicy::isSecureRequest($_SERVER, $config['trusted_proxy_cidrs']);
if (!$secureRequest && !$config['admin_allow_http']) {
    http_response_code(403);
    exit(admin_text($translator, 'error.https_required'));
}
ini_set('session.use_strict_mode', '1');
session_start(['cookie_httponly' => true, 'cookie_secure' => $secureRequest, 'cookie_samesite' => 'Strict']);
$sessionLocale = isset($_SESSION['admin_locale']) && is_string($_SESSION['admin_locale']) ? $_SESSION['admin_locale'] : null;
$translator = AdminTranslator::fromInput(
    $requestedLocale ?? $sessionLocale,
    isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : null,
);
// 明示選択をセッションへ保存し、未選択時は初回のAccept-Languageヒントを保存する。
if ($requestedLocale !== null || $sessionLocale === null) {
    $_SESSION['admin_locale'] = $translator->locale;
}

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
        exit(admin_text($translator, 'error.csrf'));
    }
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

if (!admin_authenticated($config, $authentication, $sessionPolicy, $loginError)) {
    header('Content-Type: text/html; charset=utf-8');
    admin_render_login($translator, $loginError);
    exit;
}
if ($action === 'login') {
    header('Location: ' . admin_url($translator));
    exit;
}

// 認証後にだけ永続化アダプタを初期化し、未認証GETがDBを作成しないようにする。
try {
    $repository = new SqliteResolverRepository($config['database_path']);
    $recordQuery = admin_record_query($repository);
} catch (Throwable) {
    error_log('RELink admin persistence initialization failed');
    http_response_code(503);
    exit(admin_text($translator, 'error.persistence_unavailable'));
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
    exit(admin_text($translator, 'error.csrf'));
}
if ($action !== '') {
    try {
        if ($action === 'register') {
            $created = $service->register($_POST);
            $returnUuid = $created->anchor->value;
            admin_set_flash('success', admin_text($translator, 'action.registered'));
        } elseif ($action === 'publication') {
            $integrity = AdminManifestPublicationInput::fromPost($_POST);
            $service->configureManifest((string) $_POST['uuid'], (string) $_POST['publication_mode'], $integrity->algorithm, $integrity->digest);
            admin_set_flash('success', admin_text($translator, 'action.manifest_updated'));
        } elseif ($action === 'calculate-integrity') {
            $fetcher = new NativeAdministrativeResourceFetcher(
                new OutboundNetworkPolicy($config['outbound_allowed_cidrs'], $config['outbound_denied_cidrs']),
                $config['outbound_max_redirects'],
                $config['outbound_max_body_bytes'],
                $config['outbound_connect_timeout'],
                $config['outbound_read_timeout'],
            );
            $service->calculateAndPinIntegrity($returnUuid, $fetcher);
            admin_set_flash('success', admin_text($translator, 'action.integrity_calculated'));
        } elseif ($action === 'manifest-preview') {
            $preview = $service->previewManifest($returnUuid);
            $previewMessage = $preview === null
                ? admin_text($translator, 'action.manifest_preview_empty')
                : admin_text($translator, 'action.manifest_preview', json_encode($preview, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            admin_set_flash('notice', $previewMessage);
        } elseif ($action === 'location') {
            $service->updateLocation($returnUuid, (string) $_POST['location'], isset($_POST['entity_id']) ? (string) $_POST['entity_id'] : null);
            admin_set_flash('success', admin_text($translator, 'action.mapping_updated'));
        } elseif ($action === 'transition') {
            $target = (string) ($_POST['state'] ?? '');
            if (strtoupper(trim($target)) === LifecycleState::RETIRED->value && !isset($_POST['confirm_retire'])) {
                throw new \RuntimeException('RETIREMENT_CONFIRMATION_REQUIRED');
            }
            $service->transition($returnUuid, $target, (string) ($_POST['reason'] ?? ''), (string) $_SESSION['admin']);
            admin_set_flash('success', admin_text($translator, 'action.lifecycle_updated'));
        } elseif ($action === 'resolution-test') {
            $result = $service->resolve('GET', $returnUuid, []);
            $location = $result->headers['Location'] ?? null;
            admin_set_flash('notice', $location === null ? admin_text($translator, 'action.resolution_test', $result->status) : admin_text($translator, 'action.resolution_test_location', $result->status, $location));
        } else {
            throw new \InvalidArgumentException('Unknown admin action');
        }
    } catch (Throwable $error) {
        $code = $error instanceof \Relink\Resolver\Application\ApplicationException ? $error->errorCode : $error->getMessage();
        $messages = ['INVALID_INPUT' => 'error.invalid_input', 'INVALID_TRANSITION' => 'error.invalid_transition', 'STATE_CONFLICT' => 'error.state_conflict', 'NOT_FOUND' => 'error.not_found', 'RECORD_EXISTS' => 'error.record_exists', 'PERSISTENCE_FAILURE' => 'error.persistence', 'FETCH_FAILURE' => 'error.fetch_failure', 'UNSUPPORTED_OPERATION' => 'error.unsupported', 'RETIREMENT_CONFIRMATION_REQUIRED' => 'error.retirement_confirmation'];
        admin_set_flash('error', admin_text($translator, 'error.operation_failed', admin_text($translator, $messages[$code] ?? 'error.generic')));
        error_log('RELink admin operation failed: ' . preg_replace('/[^A-Z_]/', '', (string) $code));
    }
    $redirectRoute = $returnUuid === '' || ($action === 'register' && !isset($created)) ? 'register' : 'record';
    header('Location: ' . admin_url($translator, $redirectRoute, $redirectRoute === 'record' ? ['uuid' => $returnUuid] : []));
    exit;
}

$route = strtolower((string) ($_GET['route'] ?? 'overview'));
$flash = admin_consume_flash();
header('Content-Type: text/html; charset=utf-8');
if ($route === 'register') {
    admin_render_register($translator, $csrf, $flash);
    exit;
}
if ($route === 'records') {
    $rows = $recordQuery->search($listQuery->needle, $listQuery->perPage + 1, $listQuery->offset());
    $hasMore = count($rows) > $listQuery->perPage;
    admin_render_records($translator, array_slice($rows, 0, $listQuery->perPage), $listQuery, $hasMore, $csrf, $flash);
    exit;
}
if ($route === 'record') {
    try {
        $uuid = (string) ($_GET['uuid'] ?? '');
        $record = $service->findRecord($uuid);
        $history = $service->history($uuid);
        $manifestUrl = rtrim((string) $config['service_prefix'], '/') . '/' . rawurlencode($record->anchor->value) . '/manifest';
        admin_render_record_detail($translator, $record, $history, $csrf, $flash, $manifestUrl);
    } catch (Throwable $error) {
        http_response_code($error instanceof \Relink\Resolver\Application\ApplicationException && $error->errorCode === 'NOT_FOUND' ? 404 : 503);
        admin_render_start($translator, 'detail.not_found_title', true, $csrf, 'records');
        admin_render_flash($translator, ['type' => 'error', 'message' => admin_text($translator, 'error.not_found')]);
        echo '<div class="empty-state"><h1>' . admin_escape(admin_text($translator, 'detail.not_found_heading')) . '</h1><p>' . admin_escape(admin_text($translator, 'detail.not_found_body')) . '</p><a class="button-primary" href="' . admin_escape(admin_url($translator, 'records')) . '">' . admin_escape(admin_text($translator, 'detail.back_records')) . '</a></div>';
        admin_render_end($translator);
    }
    exit;
}
admin_render_overview($translator, $csrf, $flash);
