<?php
// src/Application/AdminTranslator.php
// 管理画面の表示文言をロケールごとに管理する、フレームワーク非依存の翻訳サービス。

declare(strict_types=1);

namespace Relink\Resolver\Application;

use InvalidArgumentException;

final readonly class AdminTranslator
{
    private const DEFAULT_LOCALE = 'ja';

    /** @var list<string> 管理画面で選択できるロケール。 */
    private const SUPPORTED_LOCALES = ['ja', 'en'];

    /**
     * @var array<string, array<string, string>> 管理画面の翻訳カタログ。
     */
    private const MESSAGES = [
        'ja' => [
            'locale.japanese' => '日本語',
            'locale.english' => 'English',
            'locale.switcher_label' => '表示言語',
            'brand.name' => 'RELink Resolver',
            'brand.description' => 'RELink Reference Resolver 管理画面',
            'nav.label' => 'メインナビゲーション',
            'nav.overview' => 'Overview',
            'nav.records' => 'Records',
            'nav.register' => 'Register',
            'nav.logout' => 'ログアウト',
            'account.admin' => '管理者',
            'footer.reference_ui' => 'RELink Reference Resolver 0.1 Reference UI',
            'login.title' => 'ログイン',
            'login.eyebrow' => 'REFERENCE RESOLVER',
            'login.heading' => '管理画面にログイン',
            'login.lede' => '登録情報と公開設定を安全に管理します。',
            'login.username' => 'ユーザー名',
            'login.password' => 'パスワード',
            'login.submit' => 'ログイン',
            'login.invalid' => 'ユーザー名またはパスワードを確認してください。',
            'overview.title' => 'Overview',
            'overview.eyebrow' => 'OVERVIEW',
            'overview.heading' => '運用の概要',
            'overview.lede' => 'RELink Resolver の日常的な管理操作をここから開始できます。',
            'overview.hero.eyebrow' => 'OPERATOR WORKSPACE',
            'overview.hero.heading' => '安全で分かりやすいレコード管理',
            'overview.hero.body' => 'Resolver は Anchor UUID を Canonical Entity Identity と Description Location に結び付けます。既存レコードの変更は詳細画面から行えます。',
            'overview.hero.action' => '新しいレコードを登録',
            'overview.browse.kicker' => 'BROWSE',
            'overview.browse.heading' => 'Records',
            'overview.browse.body' => '登録済みレコードを検索し、状態と公開先を確認します。',
            'overview.browse.link' => 'レコード一覧を見る →',
            'overview.create.kicker' => 'CREATE',
            'overview.create.heading' => 'Register',
            'overview.create.body' => 'UUID、Entity Identity、Description Location を入力して登録します。',
            'overview.create.link' => '登録フォームを開く →',
            'overview.boundary.kicker' => 'BOUNDARY',
            'overview.boundary.heading' => '運用上の注意',
            'overview.boundary.body' => '公開解決の成功は Trust、認証、AR-XML の到達性、Capability の利用可能性を証明しません。',
            'overview.reference.eyebrow' => 'QUICK REFERENCE',
            'overview.reference.heading' => '状態の意味',
            'overview.reference.link' => '一覧を確認',
            'overview.state.active' => '公開 Resolver は 303 で Description Location へ転送します。',
            'overview.state.suspended' => '公開 Resolver は 404 を返し、転送しません。',
            'overview.state.retired' => '終端状態です。公開 Resolver は 410 を返します。',
            'state.active' => 'ACTIVE（公開中）',
            'state.suspended' => 'SUSPENDED（停止中）',
            'state.retired' => 'RETIRED（廃止）',
            'state.active_short' => 'ACTIVE',
            'state.suspended_short' => 'SUSPENDED',
            'state.retired_short' => 'RETIRED',
            'records.title' => 'Records',
            'records.eyebrow' => 'RECORDS',
            'records.heading' => 'レコード一覧',
            'records.lede' => 'Anchor UUID、状態、Identity、公開先を確認できます。',
            'records.search' => '検索',
            'records.search_placeholder' => 'UUID または Entity Identity',
            'records.page_size' => '表示件数',
            'records.option_count' => '%d件',
            'records.search_submit' => '検索',
            'records.register' => '＋ 登録',
            'records.table_caption' => 'Resolver レコード一覧',
            'records.anchor_uuid' => 'Anchor UUID',
            'records.state' => '状態',
            'records.entity_identity' => 'Entity Identity',
            'records.description_location' => 'Description Location',
            'records.actions' => '操作',
            'records.detail' => '詳細 →',
            'records.panel_heading' => '登録済みレコード',
            'records.page_summary' => 'ページ %d ・ 最大 %d 件',
            'records.empty_heading' => 'レコードが見つかりません',
            'records.empty_body' => '検索条件を変えるか、最初のレコードを登録してください。',
            'records.empty_action' => 'レコードを登録',
            'records.previous' => '← 前へ',
            'records.next' => '次へ →',
            'records.page' => 'ページ %d',
            'register.title' => 'Register',
            'register.eyebrow' => 'REGISTER',
            'register.heading' => 'レコードを登録',
            'register.lede' => 'Resolver が管理する3つの識別情報を入力してください。',
            'register.required' => '必須',
            'register.optional' => '任意',
            'register.uuid' => 'Anchor UUID',
            'register.uuid_placeholder' => '550e8400-e29b-41d4-a716-446655440000',
            'register.uuid_help' => '物理アンカーを一意に識別する UUID です。',
            'register.entity' => 'Canonical Entity Identity',
            'register.entity_placeholder' => 'urn:relink:entity:example',
            'register.entity_help' => 'UUID とは異なる、正規エンティティの絶対 URI です。',
            'register.location' => 'Description Location',
            'register.location_placeholder' => 'https://entity.example/description.xml',
            'register.location_help' => '現在の説明の取得先です。Resolver はこの値へ 303 で転送します。',
            'register.media_type' => 'Media type',
            'register.media_placeholder' => 'application/xml',
            'register.integrity_summary' => 'Manifest の整合性情報（任意）',
            'register.integrity_help' => '両方を指定した場合だけ Manifest の integrity として保存されます。',
            'register.algorithm' => 'Integrity algorithm',
            'register.algorithm_placeholder' => 'sha-256',
            'register.digest' => 'Integrity digest',
            'register.digest_placeholder' => '64桁の lowercase hex',
            'register.submit' => 'レコードを登録',
            'register.cancel' => 'キャンセル',
            'detail.title' => 'Record detail',
            'detail.breadcrumb' => 'Record detail',
            'detail.eyebrow' => 'RECORD DETAIL',
            'detail.identity.eyebrow' => 'IDENTITY',
            'detail.identity.heading' => 'Identity',
            'detail.anchor_uuid' => 'Anchor UUID',
            'detail.entity_identity' => 'Canonical Entity Identity',
            'detail.version' => 'Record version',
            'detail.resolution.eyebrow' => 'RESOLUTION',
            'detail.resolution.heading' => 'Resolution',
            'detail.public_behavior' => '現在の公開動作',
            'detail.public_active' => 'HTTP 303（Description Location へ転送）',
            'detail.public_suspended' => 'HTTP 404（停止中）',
            'detail.public_retired' => 'HTTP 410（廃止済み）',
            'detail.resolution_test' => '公開解決をテスト',
            'detail.resolution_help' => 'このテストは HTTP 応答だけを確認します。Trust や AR-XML の到達性は検証しません。',
            'detail.lifecycle.eyebrow' => 'LIFECYCLE',
            'detail.lifecycle.heading' => 'Lifecycle',
            'detail.current_state' => '現在の状態: ',
            'detail.suspend' => 'Suspend（停止）',
            'detail.reactivate' => 'Reactivate（再開）',
            'detail.retire' => 'Retire（廃止）',
            'detail.reason' => '理由',
            'detail.reason_placeholder' => '操作理由',
            'detail.retire_confirmation' => '廃止は取り消せないことを確認しました',
            'detail.retired_heading' => 'RETIRED は終端状態です。',
            'detail.retired_body' => 'このレコードに対する Lifecycle 遷移はありません。',
            'detail.mapping.eyebrow' => 'MAPPING',
            'detail.mapping.heading' => 'Resolution mapping',
            'detail.mapping_help' => 'Location を更新すると、対応する integrity 情報は ResolverService により破棄されます。',
            'detail.mapping_submit' => 'マッピングを更新',
            'detail.manifest.eyebrow' => 'MANIFEST',
            'detail.manifest.heading' => 'Manifest',
            'detail.manifest_endpoint' => '公開エンドポイント',
            'detail.media_type' => 'Media type',
            'detail.integrity' => 'Integrity',
            'detail.not_set' => '未設定',
            'detail.manifest_help' => 'Manifest は Resolver の説明メタデータです。公開解決や Trust の判定とは別の機能です。',
            'detail.history.eyebrow' => 'HISTORY',
            'detail.history.heading' => '変更履歴',
            'detail.history_limit' => '最大100件',
            'detail.history_caption' => 'Lifecycle とレコード変更の履歴',
            'detail.history_time' => '日時',
            'detail.history_event' => 'イベント',
            'detail.history_state' => '状態',
            'detail.history_reason' => '理由',
            'detail.history_actor' => '実行者',
            'detail.history_empty' => '保持されている履歴はありません。',
            'detail.history_no_value' => '—',
            'detail.not_found_title' => 'Record not found',
            'detail.not_found_heading' => 'レコードが見つかりません',
            'detail.not_found_body' => 'UUIDを確認して、レコード一覧から再度お試しください。',
            'detail.back_records' => 'Recordsへ戻る',
            'feedback.success' => '完了',
            'feedback.error' => 'エラー',
            'feedback.notice' => 'お知らせ',
            'action.registered' => 'レコードを登録しました。',
            'action.mapping_updated' => 'Resolution mapping を更新しました。',
            'action.lifecycle_updated' => 'Lifecycle 状態を変更しました。',
            'action.resolution_test' => '公開解決テスト: HTTP %d',
            'action.resolution_test_location' => '公開解決テスト: HTTP %d / Location: %s',
            'error.invalid_input' => '入力が不正です。',
            'error.invalid_transition' => '許可されていない状態遷移です。',
            'error.state_conflict' => '別の操作で更新されたため、再読み込みしてください。',
            'error.not_found' => '対象レコードが見つかりません。',
            'error.record_exists' => '同じ UUID は既に登録されています。',
            'error.persistence' => '永続化処理に失敗しました。',
            'error.retirement_confirmation' => '廃止する場合は確認チェックが必要です。',
            'error.generic' => '要求を処理できませんでした。',
            'error.operation_failed' => '操作に失敗しました: %s',
            'error.request_too_large' => '要求が大きすぎます。',
            'error.invalid_request' => '要求が不正です。',
            'error.configuration' => '管理サービスの設定を確認してください。',
            'error.https_required' => '管理画面にはHTTPS接続が必要です。',
            'error.csrf' => '安全確認に失敗しました。ページを再読み込みして再試行してください。',
            'error.persistence_unavailable' => '管理サービスを利用できません。',
        ],
        'en' => [
            'locale.japanese' => '日本語', 'locale.english' => 'English', 'locale.switcher_label' => 'Language', 'brand.name' => 'RELink Resolver', 'brand.description' => 'RELink Reference Resolver Admin', 'nav.label' => 'Main navigation', 'nav.overview' => 'Overview', 'nav.records' => 'Records', 'nav.register' => 'Register', 'nav.logout' => 'Log out', 'account.admin' => 'Administrator', 'footer.reference_ui' => 'RELink Reference Resolver 0.1 Reference UI',
            'login.title' => 'Sign in', 'login.eyebrow' => 'REFERENCE RESOLVER', 'login.heading' => 'Sign in to Admin', 'login.lede' => 'Manage records and publication settings safely.', 'login.username' => 'Username', 'login.password' => 'Password', 'login.submit' => 'Sign in', 'login.invalid' => 'Check your username or password.',
            'overview.title' => 'Overview', 'overview.eyebrow' => 'OVERVIEW', 'overview.heading' => 'Operations overview', 'overview.lede' => 'Start common RELink Resolver administration tasks here.', 'overview.hero.eyebrow' => 'OPERATOR WORKSPACE', 'overview.hero.heading' => 'Clear, safe record management', 'overview.hero.body' => 'The Resolver maps an Anchor UUID to a Canonical Entity Identity and a Description Location. Edit existing records from their detail page.', 'overview.hero.action' => 'Register a new record', 'overview.browse.kicker' => 'BROWSE', 'overview.browse.heading' => 'Records', 'overview.browse.body' => 'Search registered records and review their state and destination.', 'overview.browse.link' => 'View records →', 'overview.create.kicker' => 'CREATE', 'overview.create.heading' => 'Register', 'overview.create.body' => 'Enter the UUID, Entity Identity, and Description Location to register a record.', 'overview.create.link' => 'Open registration →', 'overview.boundary.kicker' => 'BOUNDARY', 'overview.boundary.heading' => 'Operational note', 'overview.boundary.body' => 'A successful public resolution does not prove Trust, authentication, AR-XML reachability, or capability availability.', 'overview.reference.eyebrow' => 'QUICK REFERENCE', 'overview.reference.heading' => 'State meanings', 'overview.reference.link' => 'Review records', 'overview.state.active' => 'The public Resolver redirects to the Description Location with 303.', 'overview.state.suspended' => 'The public Resolver returns 404 and does not redirect.', 'overview.state.retired' => 'This is a terminal state. The public Resolver returns 410.',
            'state.active' => 'ACTIVE (public)', 'state.suspended' => 'SUSPENDED (stopped)', 'state.retired' => 'RETIRED (retired)', 'state.active_short' => 'ACTIVE', 'state.suspended_short' => 'SUSPENDED', 'state.retired_short' => 'RETIRED',
            'records.title' => 'Records', 'records.eyebrow' => 'RECORDS', 'records.heading' => 'Records', 'records.lede' => 'Review Anchor UUID, state, identity, and destination.', 'records.search' => 'Search', 'records.search_placeholder' => 'UUID or Entity Identity', 'records.page_size' => 'Rows per page', 'records.option_count' => '%d', 'records.search_submit' => 'Search', 'records.register' => '+ Register', 'records.table_caption' => 'Resolver records', 'records.anchor_uuid' => 'Anchor UUID', 'records.state' => 'State', 'records.entity_identity' => 'Entity Identity', 'records.description_location' => 'Description Location', 'records.actions' => 'Actions', 'records.detail' => 'Details →', 'records.panel_heading' => 'Registered records', 'records.page_summary' => 'Page %d · up to %d', 'records.empty_heading' => 'No records found', 'records.empty_body' => 'Change the search or register the first record.', 'records.empty_action' => 'Register a record', 'records.previous' => '← Previous', 'records.next' => 'Next →', 'records.page' => 'Page %d',
            'register.title' => 'Register', 'register.eyebrow' => 'REGISTER', 'register.heading' => 'Register a record', 'register.lede' => 'Enter the three identifiers managed by the Resolver.', 'register.required' => 'Required', 'register.optional' => 'Optional', 'register.uuid' => 'Anchor UUID', 'register.uuid_placeholder' => '550e8400-e29b-41d4-a716-446655440000', 'register.uuid_help' => 'A UUID that uniquely identifies the physical anchor.', 'register.entity' => 'Canonical Entity Identity', 'register.entity_placeholder' => 'urn:relink:entity:example', 'register.entity_help' => 'An absolute URI for the canonical entity, distinct from the UUID.', 'register.location' => 'Description Location', 'register.location_placeholder' => 'https://entity.example/description.xml', 'register.location_help' => 'The current description location. The Resolver redirects here with 303.', 'register.media_type' => 'Media type', 'register.media_placeholder' => 'application/xml', 'register.integrity_summary' => 'Manifest integrity information (optional)', 'register.integrity_help' => 'Stored as Manifest integrity only when both values are provided.', 'register.algorithm' => 'Integrity algorithm', 'register.algorithm_placeholder' => 'sha-256', 'register.digest' => 'Integrity digest', 'register.digest_placeholder' => '64 lowercase hexadecimal characters', 'register.submit' => 'Register record', 'register.cancel' => 'Cancel',
            'detail.title' => 'Record detail', 'detail.breadcrumb' => 'Record detail', 'detail.eyebrow' => 'RECORD DETAIL', 'detail.identity.eyebrow' => 'IDENTITY', 'detail.identity.heading' => 'Identity', 'detail.anchor_uuid' => 'Anchor UUID', 'detail.entity_identity' => 'Canonical Entity Identity', 'detail.version' => 'Record version', 'detail.resolution.eyebrow' => 'RESOLUTION', 'detail.resolution.heading' => 'Resolution', 'detail.public_behavior' => 'Current public behavior', 'detail.public_active' => 'HTTP 303 (redirect to Description Location)', 'detail.public_suspended' => 'HTTP 404 (suspended)', 'detail.public_retired' => 'HTTP 410 (retired)', 'detail.resolution_test' => 'Test public resolution', 'detail.resolution_help' => 'This checks only the HTTP response. It does not verify Trust or AR-XML reachability.', 'detail.lifecycle.eyebrow' => 'LIFECYCLE', 'detail.lifecycle.heading' => 'Lifecycle', 'detail.current_state' => 'Current state: ', 'detail.suspend' => 'Suspend', 'detail.reactivate' => 'Reactivate', 'detail.retire' => 'Retire', 'detail.reason' => 'Reason', 'detail.reason_placeholder' => 'Reason for this operation', 'detail.retire_confirmation' => 'I understand that retirement cannot be undone', 'detail.retired_heading' => 'RETIRED is a terminal state.', 'detail.retired_body' => 'No Lifecycle transitions are available for this record.', 'detail.mapping.eyebrow' => 'MAPPING', 'detail.mapping.heading' => 'Resolution mapping', 'detail.mapping_help' => 'Updating the Location causes ResolverService to clear related integrity information.', 'detail.mapping_submit' => 'Update mapping', 'detail.manifest.eyebrow' => 'MANIFEST', 'detail.manifest.heading' => 'Manifest', 'detail.manifest_endpoint' => 'Public endpoint', 'detail.media_type' => 'Media type', 'detail.integrity' => 'Integrity', 'detail.not_set' => 'Not set', 'detail.manifest_help' => 'Manifest is Resolver description metadata, separate from public resolution and Trust.', 'detail.history.eyebrow' => 'HISTORY', 'detail.history.heading' => 'Change history', 'detail.history_limit' => 'Up to 100 entries', 'detail.history_caption' => 'Lifecycle and record change history', 'detail.history_time' => 'Time', 'detail.history_event' => 'Event', 'detail.history_state' => 'State', 'detail.history_reason' => 'Reason', 'detail.history_actor' => 'Actor', 'detail.history_empty' => 'No retained history.', 'detail.history_no_value' => '—', 'detail.not_found_title' => 'Record not found', 'detail.not_found_heading' => 'Record not found', 'detail.not_found_body' => 'Check the UUID and try again from the records list.', 'detail.back_records' => 'Back to Records',
            'feedback.success' => 'Done', 'feedback.error' => 'Error', 'feedback.notice' => 'Notice', 'action.registered' => 'Record registered.', 'action.mapping_updated' => 'Resolution mapping updated.', 'action.lifecycle_updated' => 'Lifecycle state updated.', 'action.resolution_test' => 'Public resolution test: HTTP %d', 'action.resolution_test_location' => 'Public resolution test: HTTP %d / Location: %s', 'error.invalid_input' => 'The input is invalid.', 'error.invalid_transition' => 'This Lifecycle transition is not allowed.', 'error.state_conflict' => 'The record changed in another operation. Reload and try again.', 'error.not_found' => 'The requested record was not found.', 'error.record_exists' => 'That UUID is already registered.', 'error.persistence' => 'The persistence operation failed.', 'error.retirement_confirmation' => 'Retirement requires confirmation.', 'error.generic' => 'The request could not be processed.', 'error.operation_failed' => 'Operation failed: %s', 'error.request_too_large' => 'The request is too large.', 'error.invalid_request' => 'The request is invalid.', 'error.configuration' => 'Check the administration service configuration.', 'error.https_required' => 'HTTPS is required for administration.', 'error.csrf' => 'The security check failed. Reload the page and try again.', 'error.persistence_unavailable' => 'The administration service is unavailable.',
        ],
    ];

    public function __construct(public string $locale = self::DEFAULT_LOCALE)
    {
        if (!in_array($this->locale, self::SUPPORTED_LOCALES, true)) {
            throw new InvalidArgumentException('Unsupported admin locale');
        }
    }

    /** @return list<string> */
    public static function supportedLocales(): array
    {
        return self::SUPPORTED_LOCALES;
    }

    /** URLやAccept-Languageから安全にロケールを選択する。 */
    public static function fromInput(?string $requested, ?string $acceptLanguage = null): self
    {
        $candidate = strtolower(trim((string) $requested));
        if ($candidate === '' && $acceptLanguage !== null) {
            $candidate = strtolower(substr(trim($acceptLanguage), 0, 2));
        }
        return new self(in_array($candidate, self::SUPPORTED_LOCALES, true) ? $candidate : self::DEFAULT_LOCALE);
    }

    /** 翻訳キーを現在のロケールで解決し、必要な値を安全に置換する。 */
    /** @param array<string, string|int> $parameters */
    public function get(string $key, array $parameters = []): string
    {
        $message = self::MESSAGES[$this->locale][$key] ?? self::MESSAGES[self::DEFAULT_LOCALE][$key] ?? $key;
        foreach ($parameters as $name => $value) {
            $message = str_replace('{' . $name . '}', (string) $value, $message);
        }
        return $message;
    }

    /** ロケール切り替え用の翻訳サービスを返す。 */
    public function withLocale(string $locale): self
    {
        return new self($locale);
    }
}
