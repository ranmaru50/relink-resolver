# Testbed 受入用 Native / Container 配備

この手順は、Frozen Resolver / Manifest Conformance Catalog 0.1 を、同じホスト上の Native と Container の両 profileへ外部 HTTP クライアントから適用するためのものです。Resolver 本体へ conformance runner や Testbed の判定ロジックは追加しません。

## 共通の前提

- Docker Compose v2、OpenSSL、PHP 8.3 以上、Apache、`pdo_sqlite` を用意する。
- 作業ディレクトリはリポジトリルートとする。
- 受入用 DB、証明書、環境ファイル、バックアップは `public/` の外に置く。
- `RELINK_ADMIN_PASSWORD` はローカル受入専用の値を設定し、リポジトリへ保存しない。
- Testbed の Node.js HTTPS client で自己署名証明書を使う場合だけ、実行プロセスへ `NODE_TLS_REJECT_UNAUTHORIZED=0` を設定する。これは本番設定ではない。

まず、受入用アカウントと共通設定を作成する。

```powershell
Copy-Item .env.acceptance.example .env.acceptance
# .env.acceptance の RELINK_ADMIN_PASSWORD をランダムなローカル値へ変更する
New-Item -ItemType Directory -Force var/acceptance/tls | Out-Null
sh bin/acceptance-certificate.sh var/acceptance/tls
```

証明書は `localhost` と `127.0.0.1` 用の短期自己署名証明書です。Native と Container は同じ証明書を使えますが、秘密鍵は Apache の設定ファイルや DocumentRoot にコピーしません。

## Container profile

Container の受入 profile は Apache がコンテナ内で TLS を終端し、ホストでは `127.0.0.1:8443` のみへ公開します。SQLite は named volume に保存され、コンテナの再作成で失われません。

毎回クリーンな受入状態から開始する場合は、profile 専用 volume だけを削除してから起動します。

```powershell
docker compose -f compose.acceptance.yaml down --volumes --remove-orphans
docker compose -f compose.acceptance.yaml up --build --detach
docker compose -f compose.acceptance.yaml ps
```

起動時に entrypoint が `bin/migrate.php` を実行し、`RELINK_TLS_ENABLED=1` のときだけ TLS VirtualHost を有効化します。動作確認は `https://127.0.0.1:8443/relink/{uuid}`、`https://127.0.0.1:8443/relink/{uuid}/manifest`、`https://127.0.0.1:8443/admin.php` で行えます。

```powershell
curl.exe -k -I https://127.0.0.1:8443/admin.php
$env:RELINK_NATIVE_URL = "https://127.0.0.1:8444"
$env:RELINK_CONTAINER_URL = "https://127.0.0.1:8443"
$env:RELINK_ADMIN_USERNAME = "acceptance-admin"
$env:RELINK_ADMIN_PASSWORD = "<.env.acceptance と同じ値>"
$env:NODE_TLS_REJECT_UNAUTHORIZED = "0"
pnpm conformance
```

Testbed の conformance runner が管理面へログインし、固定 fixture を登録します。

## Native profile

Native profile は OS 上の Apache + PHP + SQLite を使用します。Apache の実行ユーザーが `/var/lib/relink-resolver` を読み書きできるように、DB とその WAL/SHM/journal ファイルを同じ永続データディレクトリへ置きます。

Linux の例:

```sh
sudo install -d -o www-data -g www-data -m 770 /var/lib/relink-resolver
sudo install -d -o root -g root -m 750 /etc/relink/tls
sudo install -m 644 var/acceptance/tls/cert.pem /etc/relink/tls/cert.pem
sudo install -m 600 var/acceptance/tls/key.pem /etc/relink/tls/key.pem
RELINK_ENV=acceptance RELINK_DATA_DIR=/var/lib/relink-resolver \
RELINK_ADMIN_USERNAME=acceptance-admin RELINK_ADMIN_PASSWORD='<local-test-secret>' \
php bin/migrate.php
```

`deploy/apache-native-ssl-vhost.conf.example` を Apache の site 設定として読み込み、`/etc/relink/tls` のパスと `DocumentRoot` を実際の配置へ合わせます。`deploy/php-security.ini` も PHP の追加設定ディレクトリへ配置し、`RELINK_ADMIN_ALLOW_HTTP=0` を維持してください。

```sh
sudo a2enmod ssl rewrite headers reqtimeout
sudo apachectl configtest
sudo systemctl reload apache2
```

Native の受入 URL は `https://127.0.0.1:8444` とします。Apache の listen 設定で `8444` を有効にし、受入用 VirtualHost の `DocumentRoot` が `public/` だけを指すことを確認します。管理面は HTTPS とアプリケーション認証の両方で保護され、SQLite/秘密情報/バックアップは HTTP から取得できない場所に置きます。

## Testbed への引き渡し

両 profile を起動した後、Testbed リポジトリで次を実行します。

```powershell
$env:RELINK_NATIVE_URL = "https://127.0.0.1:8444"
$env:RELINK_CONTAINER_URL = "https://127.0.0.1:8443"
$env:RELINK_ADMIN_USERNAME = "acceptance-admin"
$env:RELINK_ADMIN_PASSWORD = "<共通のローカル受入用 secret>"
$env:NODE_TLS_REJECT_UNAUTHORIZED = "0"
pnpm conformance
```

結果は Testbed 側の `reports/resolver-conformance-0.1/native.json` と `container.json` へ profile 別に出力されます。HTTP security header の受入では、同じ Testbed の `pnpm security:headers` を HTTPS Native URL と Container HTTP URL へ向けて実行します。

Testbed の JSON artifact と環境固有の観測結果は Testbed リポジトリへコミットします。この Resolver リポジトリには結果や DB をコミットしません。`MNET-001` は HTTPS profile URL で実行した結果だけを受入結果として扱い、自己署名証明書の信頼緩和は TLS 終端の有無を変えません。

## 更新・復元

Container は再作成前に `bin/backup.sh` で SQLite Online Backup を取得し、Native へ移す場合は `bin/restore.sh` と同じ検証手順を使います。復元後は `PRAGMA integrity_check`、UUID、Lifecycle、Description Location、Manifest metadata、履歴を確認します。profile を移動しても Anchor UUID を作り直しません。

受入用 volume の削除を伴う `docker compose ... down --volumes` は、クリーンな受入を明示的に開始するときだけ実行してください。本番データやバックアップへこの profile の削除操作を向けないでください。
