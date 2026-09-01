#!/usr/bin/env sh
# bin/backup.sh
# SQLite Online Backup API 相当の安全なバックアップを sqlite3 CLI で行う。
set -eu
DB_PATH="${RELINK_DB_PATH:-/var/lib/relink-resolver/resolver.sqlite}"
DEST="${1:?backup destination is required}"
mkdir -p "$(dirname "$DEST")"
sqlite3 "$DB_PATH" ".timeout 5000" ".backup '$DEST'"
test -s "$DEST"
