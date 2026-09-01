#!/usr/bin/env sh
# bin/restore.sh
# 既存 DB を上書きせず、検証済みバックアップから復元する。
set -eu
SOURCE="${1:?backup source is required}"
DB_PATH="${RELINK_DB_PATH:-/var/lib/relink-resolver/resolver.sqlite}"
test -f "$SOURCE"
sqlite3 "$SOURCE" "PRAGMA integrity_check;" | grep -qx ok
mkdir -p "$(dirname "$DB_PATH")"
sqlite3 "$SOURCE" ".backup '$DB_PATH'"
