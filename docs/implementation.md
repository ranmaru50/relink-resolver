# Reference Resolver 実装ガイド

## 起動

Native profile では Apache の DocumentRoot を `public/` に設定し、PHP 8.3 以上の `pdo_sqlite` 拡張を有効にします。SQLite ファイルは `RELINK_DATA_DIR`（既定は `var/data`）に作成され、`public/` 配下には置かれません。初回起動・更新時は Web リクエストに依存せず、`php bin/migrate.php` を実行します。

Container profile は `docker compose --env-file .env up --build` で起動します。イメージには Composer と PHPUnit を含み、entrypoint が Apache 起動前に `bin/migrate.php` を実行します。`.env` は `.env.example` をコピーして作成し、本番パスワードを Secret 管理から注入してください。Compose の 8080 公開は loopback の開発用です。

初回管理ログインには `RELINK_ADMIN_USERNAME` と `RELINK_ADMIN_PASSWORD` を使用します。空パスワードではログインできません。管理面は既定で HTTPS 必須です。ローカル HTTP の確認時だけ `.env` の `RELINK_ADMIN_ALLOW_HTTP=1` を設定し、本番では TLS、管理ネットワーク制限、プロキシ設定を構成してください。TLS 終端プロキシ配下では、プロキシの送信元 IP/CIDR を `RELINK_TRUSTED_PROXY_CIDRS` に設定し、プロキシ自身が `X-Forwarded-Proto` と単一の `X-Forwarded-For` をクライアント入力から上書きしてください。未設定または複数値の転送ヘッダーは信頼しません。`RELINK_ADMIN_ALLOW_HTTP=1` はプロキシ配下の本番設定に使用しないでください。

管理ログインは IP・ユーザー名ごとに失敗回数を SQLite へ保存します。既定では 15 分の時間窓で 5 回失敗すると 15 分間ロックします。`RELINK_ADMIN_LOGIN_MAX_FAILURES`、`RELINK_ADMIN_LOGIN_WINDOW_SECONDS`、`RELINK_ADMIN_LOGIN_LOCKOUT_SECONDS` で変更できます。認証済みセッションは既定で 15 分のアイドル期限と 8 時間の絶対期限を持ち、`RELINK_ADMIN_SESSION_IDLE_SECONDS` と `RELINK_ADMIN_SESSION_ABSOLUTE_SECONDS` で調整できます。パスワードには既存の固定シークレットに加え、`password_hash()` が生成するハッシュを設定できます。更新時には Native profile で `php bin/migrate.php` を実行してください。Container profile は entrypoint が migration 002 を自動適用します。

`RELINK_ENV=production` では空パスワードおよび `change-me` を拒否します。本番 Secret を必ず注入してください。

## 公開エンドポイント

`GET /relink/{uuid}` は登録済み ACTIVE レコードを `303 See Other` で HTTPS の Description Location へ転送します。公開処理は AR-XML を取得せず、Manifest に依存しません。`GET /relink/{uuid}/manifest` は利用可能な Manifest JSON を返します。

## バックアップと復元

`bin/backup.sh /secure/backup/resolver.sqlite` は SQLite の `.backup` を使用し、journal/WAL を考慮した一貫性のあるバックアップを作成します。Container の一時コンテナ内へ保存すると破棄されるため、ホストのバックアップディレクトリを必ず bind mount してください。例（Linux/macOS）は `docker compose run --rm -v /secure/host-backups:/backup resolver /var/www/bin/backup.sh /backup/resolver.sqlite`、PowerShell は `docker compose run --rm -v "C:\secure\host-backups:/backup" resolver /var/www/bin/backup.sh /backup/resolver.sqlite` です。復元時は先にアプリケーションを停止し、`bin/restore.sh` 実行後に `PRAGMA integrity_check` と UUID・状態・場所・履歴を確認してから再起動してください。空のデータディレクトリへ復元する場合は、既定の `www-data` または `RELINK_SERVICE_USER`、`RELINK_SERVICE_UID`、`RELINK_SERVICE_GID` で指定したサービスユーザーへDBとデータディレクトリを設定し、DBモードは `RELINK_DB_MODE`（既定 `660`）で設定します。NativeでApache/PHP実行ユーザーが `www-data` 以外の場合はUID/GIDを明示してください。復元前の DB は同じディレクトリの `resolver.sqlite.pre-restore.<pid>.bak` として退避されます。バックアップ先は Web ルート外のアクセス制御された場所に限定します。
`restore.sh` と `container-restore-acceptance.sh` はDebian ContainerのGNU userland（`stat -c`、`chmod --reference`、`chown --reference`）を前提にします。Native profileで復元する場合はLinux/GNU coreutilsを使用し、BSD/macOSでは同等の権限・所有者確認手順へ置き換えてください。macOS向けのバックアップbind mount例は、復元スクリプトのNative実行環境を意味しません。

## Issue #9 の適合性

公開 Resolver、Lifecycle 状態遷移、Manifest 生成、SQLite の明示的 migration、CSRF と HTML エスケープを含む最小管理面を実装しています。管理面は HTTPS 必須（開発時のみ明示的に緩和可能）で、公開解決から分離されています。Frozen Conformance Catalog の全ケースをこのリポジトリ単独で実行したものではありません。

## 既知の制限

管理者向けの到達性診断、SSRF 対策付き outbound fetch、integrity publishing は未実装です。追加する場合は、設定可能なネットワークポリシー、リダイレクトごとの再評価、DNS rebinding 対策、サイズ・時間上限を備えた専用アダプタとして実装します。適合性の最終判定は `relink-testbed` の実行可能ケースを Native/Container の両 profile に適用して行ってください。
