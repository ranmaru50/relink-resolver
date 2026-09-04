# Reference Resolver 実装ガイド

## 起動

Native profile では Apache の DocumentRoot を `public/` に設定し、PHP 8.3 以上の `pdo_sqlite` 拡張を有効にします。SQLite ファイルは `RELINK_DATA_DIR`（既定は `var/data`）に作成され、`public/` 配下には置かれません。初回起動・更新時は Web リクエストに依存せず、`php bin/migrate.php` を実行します。

Container profile は `docker compose --env-file .env up --build` で起動します。イメージには Composer と PHPUnit を含み、entrypoint が Apache 起動前に `bin/migrate.php` を実行します。`.env` は `.env.example` をコピーして作成し、本番パスワードを Secret 管理から注入してください。Compose の 8080 公開は loopback の開発用です。

初回管理ログインには `RELINK_ADMIN_USERNAME` と `RELINK_ADMIN_PASSWORD` を使用します。空パスワードではログインできません。管理面は既定で HTTPS 必須です。ローカル HTTP の確認時だけ `.env` の `RELINK_ADMIN_ALLOW_HTTP=1` を設定し、本番では TLS、管理ネットワーク制限、プロキシ設定を構成してください。TLS 終端プロキシ配下では、プロキシの送信元 IP/CIDR を `RELINK_TRUSTED_PROXY_CIDRS` に設定し、プロキシ自身が `X-Forwarded-Proto` と単一の `X-Forwarded-For` をクライアント入力から上書きしてください。未設定または複数値の転送ヘッダーは信頼しません。`RELINK_ADMIN_ALLOW_HTTP=1` はプロキシ配下の本番設定に使用しないでください。

管理ログインはクライアント IP ごとに失敗回数を SQLite へ保存します。既定では 15 分の時間窓で 5 回失敗すると 15 分間ロックします。IPを変更する分散試行はこの層だけでは防げないため、管理ネットワーク制限等を併用してください。`RELINK_ADMIN_LOGIN_MAX_FAILURES`、`RELINK_ADMIN_LOGIN_WINDOW_SECONDS`、`RELINK_ADMIN_LOGIN_LOCKOUT_SECONDS` で変更できます。認証済みセッションは既定で 15 分のアイドル期限と 8 時間の絶対期限を持ち、`RELINK_ADMIN_SESSION_IDLE_SECONDS` と `RELINK_ADMIN_SESSION_ABSOLUTE_SECONDS` で調整できます。パスワードには既存の固定シークレットに加え、`password_hash()` が生成するハッシュを設定できます。更新時には Native profile で `php bin/migrate.php` を実行してください。Container profile は entrypoint が全 migration（現在は 003 を含む）を自動適用します。

`RELINK_ENV=production` では空パスワードおよび `change-me` を拒否します。本番 Secret を必ず注入してください。

## 管理面のリクエスト・検索上限

管理面では、Native/Container共通で本文を64KiB、PHPパーサーの入力変数を32個、検索語を200バイト、Description LocationとEntity IDを各2,048バイト、Lifecycle理由を500バイトまでに制限します。UUIDは36バイト、media typeは255バイト、integrity algorithmは64バイト、digestは128バイトまでです。フォームのraw入力変数数もアプリケーション到達後に検査するため、PHPの `max_input_vars` による切り詰めを見逃しません。超過した本文は413、それ以外の不正な入力は内部情報を含まない400で終了します。

管理一覧は管理専用の読み取りポート経由で、検索語を正規化したうえで、既定20件・最大50件、ページ番号最大10,000の `LIMIT/OFFSET` 検索をSQLiteで実行します。管理画面の一覧・JSON APIともに `resolver_records` 全件をPHPへ読み込みません。`q`、`page`、`per_page` はGETパラメータとして利用できます。

Apacheの `LimitRequestBody`、ヘッダー数・サイズ、`RequestReadTimeout` と、PHPの `post_max_size`、`max_input_vars`、`arg_separator.input`、`max_input_time`、`max_execution_time`、`memory_limit` は `deploy/apache-vhost.conf.example`、`deploy/apache-docker.conf`、`deploy/php-security.ini` で同一方針にしています。Content-Length検査はアプリケーション側の整合性検査であり、PHPパーサーより前の資源防御はApache/PHP設定が担います。PHP設定を変更する場合も、アプリケーションの `RELINK_ADMIN_*` 上限とプロキシ側の上限を同じか、より厳しい値に保ってください。

## HTTP セキュリティヘッダーと情報露出

Native と Container の Apache 設定は、公開・管理・Apache 標準エラー応答を含めて `X-Content-Type-Options: nosniff` を常時付与します。値の生成元を Apache に一本化し、アプリケーション側では同ヘッダーを設定しません。両 profile で `ServerTokens Prod`、`ServerSignature Off`、`TraceEnable Off` を設定し、Apache の詳細バージョン／ホスト情報、TRACE、標準エラーページの署名を抑止します。Container profile では `deploy/php-security.ini` を読み込み、`expose_php = Off` によって `X-Powered-By` を出力しません。Native profile でも同じ ini を PHP の追加設定ディレクトリへ配置してください。

HSTS は HTTPS でのみ有効であるため、Native の TLS VirtualHost 例で `Strict-Transport-Security: max-age=31536000` を常時付与します。Container の標準 Compose 公開は loopback HTTP の開発用途であり、HSTS を設定しません。本番で TLS 終端プロキシを使用する Container profile では、プロキシ側で同じ HSTS を HTTPS 応答に付与してください。HTTP 応答に HSTS を付与してはなりません。

管理画面は全応答に `Cache-Control: no-store`、`X-Content-Type-Options: nosniff`、および `Content-Security-Policy: default-src 'none'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'` を設定します。`frame-ancestors 'none'` により管理画面の iframe 埋め込みを禁止します。公開 Resolver は CORS と `Referrer-Policy: no-referrer` を維持し、`X-Content-Type-Options: nosniff` を付与します。

## 公開エンドポイント

`GET /relink/{uuid}` は登録済み ACTIVE レコードを `303 See Other` で HTTPS の Description Location へ転送します。公開処理は AR-XML を取得せず、Manifest に依存しません。`GET /relink/{uuid}/manifest` は利用可能な Manifest JSON を返します。

## Manifest 公開ワークフロー

管理面では、Resolver レコードごとに次の4つの運用を選択できます。

- `direct`: Manifest を公開せず、Core の `303` を Description Location へ直接返す。
- `without-integrity`: integrity なしの Manifest を公開する。
- `supplied`: 管理者が入力した検証済みの algorithm/digest を公開する。`sha-256` は lowercase hex 64桁でなければなりません。
- `calculated`: 通常の登録・設定では取得を行わず、管理者が `Calculate and pin current digest` を明示的に実行したときだけ現在の表現を一度取得して pin する。

計算済み digest は自動更新されません。管理 outbound fetch は `AdministrativeResourceFetcher` Port に隔離され、HTTPS-only、redirect 上限、connect/read timeout、body 上限、接続実アドレスに対する CIDR ポリシーを適用します。標準 adapter は圧縮爆弾を防ぐため `Accept-Encoding: identity` のみを要求し、gzip/deflate 応答を展開せず拒否します。計算後の永続化は Description Location、Lifecycle、version の compare-and-swap で行うため、取得中にレコードが変更された場合は競合として失敗します。Manifest preview は公開 endpoint と同じ `ResolverService::manifest()` を利用します。

`RELINK_OUTBOUND_ALLOWED_CIDRS`、`RELINK_OUTBOUND_DENIED_CIDRS` と各 `RELINK_OUTBOUND_*` 上限で配備ポリシーを構成できます。空の許可リストはアプリケーションが全アドレスを一律拒否することを意味せず、管理ネットワークの配備ポリシーに委ねます。

## Resolver Engine の統合

`src/Domain`、`src/Application`、`src/Ports` は Plain PHP の入口、Apache、SQLite、管理画面から独立しています。Laravel/Symfony/Slim 等へ組み込む場合の Request/Response 変換、ResolverRepository 実装、認証・セッション接続はホスト側の composition root と adapter で行ってください。具体的な controller、資格情報検証ポート、型付き履歴の扱いは [Resolver Engine 統合ガイド](integration.md) を参照してください。

## バックアップと復元

`bin/backup.sh /secure/backup/resolver.sqlite` は SQLite の `.backup` を使用し、journal/WAL を考慮した一貫性のあるバックアップを作成します。Container の一時コンテナ内へ保存すると破棄されるため、ホストのバックアップディレクトリを必ず bind mount してください。例（Linux/macOS）は `docker compose run --rm -v /secure/host-backups:/backup resolver /var/www/bin/backup.sh /backup/resolver.sqlite`、PowerShell は `docker compose run --rm -v "C:\secure\host-backups:/backup" resolver /var/www/bin/backup.sh /backup/resolver.sqlite` です。復元時は先にアプリケーションを停止し、`bin/restore.sh` 実行後に `PRAGMA integrity_check` と UUID・状態・場所・履歴を確認してから再起動してください。空のデータディレクトリへ復元する場合は、既定の `www-data` または `RELINK_SERVICE_USER`、`RELINK_SERVICE_UID`、`RELINK_SERVICE_GID` で指定したサービスユーザーへDBとデータディレクトリを設定し、DBモードは `RELINK_DB_MODE`（既定 `660`）で設定します。NativeでApache/PHP実行ユーザーが `www-data` 以外の場合はUID/GIDを明示してください。復元前の DB は同じディレクトリの `resolver.sqlite.pre-restore.<pid>.bak` として退避されます。バックアップ先は Web ルート外のアクセス制御された場所に限定します。
`restore.sh` と `container-restore-acceptance.sh` はDebian ContainerのGNU userland（`stat -c`、`chmod --reference`、`chown --reference`）を前提にします。Native profileで復元する場合はLinux/GNU coreutilsを使用し、BSD/macOSでは同等の権限・所有者確認手順へ置き換えてください。macOS向けのバックアップbind mount例は、復元スクリプトのNative実行環境を意味しません。

## Issue #9 の適合性

公開 Resolver、Lifecycle 状態遷移、Manifest 生成、SQLite の明示的 migration、CSRF と HTML エスケープを含む最小管理面を実装しています。管理面は HTTPS 必須（開発時のみ明示的に緩和可能）で、公開解決から分離されています。Frozen Conformance Catalog の全ケースをこのリポジトリ単独で実行したものではありません。

## 管理 outbound fetch の境界

管理 outbound fetch は公開 Resolver / Manifest GET から到達できない privileged operation です。成功した fetch や digest 一致は認証、所有権、Trust、鮮度、anti-rollback の証明を意味しません。リダイレクト先は毎回再解決・再評価し、選択した実アドレスへ直接 TLS 接続します。適合性の最終判定は `relink-testbed` の実行可能ケースを Native/Container の両 profile に適用して行ってください。
