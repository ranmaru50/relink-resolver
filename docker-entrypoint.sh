#!/bin/sh
# docker-entrypoint.sh
# Apache 起動前に明示的な SQLite マイグレーションを適用する。
set -eu
php /var/www/bin/migrate.php
db_path="${RELINK_DB_PATH:-/var/lib/relink-resolver/resolver.sqlite}"
chown www-data:www-data "$db_path"
exec "$@"
