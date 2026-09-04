#!/bin/sh
# docker-entrypoint.sh
# Apache 起動前に明示的な SQLite マイグレーションを適用する。
set -eu
php /var/www/bin/migrate.php
db_path="${RELINK_DB_PATH:-/var/lib/relink-resolver/resolver.sqlite}"
chown www-data:www-data "$db_path"

if test "${RELINK_TLS_ENABLED:-0}" = "1"; then
    # 証明書は image に含めず、受入または運用環境から read-only で注入する。
    test -r /etc/relink/tls/cert.pem
    test -r /etc/relink/tls/key.pem
    a2ensite relink-ssl >/dev/null
fi

if test "${RELINK_ENV:-development}" = "acceptance"; then
    # 受入 profile の fault fixture を HTTP/HTTPS の両面へ有効化する。
    a2enconf relink-acceptance >/dev/null
else
    # 同一コンテナを再利用して環境変数だけ変更した場合も受入設定を残さない。
    a2disconf relink-acceptance >/dev/null 2>&1 || true
fi

exec "$@"
