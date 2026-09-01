#!/usr/bin/env sh
# bin/restore.sh
# 検証済みバックアップを一時 DB に復元し、既存 DB を退避してから原子的に切り替える。
set -eu
SOURCE="${1:?backup source is required}"
DB_PATH="${RELINK_DB_PATH:-/var/lib/relink-resolver/resolver.sqlite}"
DB_DIR="$(dirname "$DB_PATH")"
TEMP_PATH="${DB_PATH}.restore.$$"
RECOVERY_PATH="${DB_PATH}.pre-restore.$$.bak"
MOVED_CURRENT=0

cleanup() {
    rm -f "$TEMP_PATH"
}
trap cleanup EXIT INT TERM

test -f "$SOURCE"
sqlite3 "$SOURCE" "PRAGMA integrity_check;" | grep -qx ok
mkdir -p "$DB_DIR"
rm -f "$TEMP_PATH"
sqlite3 "$SOURCE" ".backup '$TEMP_PATH'"
sqlite3 "$TEMP_PATH" "PRAGMA integrity_check;" | grep -qx ok
# アプリケーションが必要とするテーブルとライフサイクル値を検証してから切り替える。
test "$(sqlite3 "$TEMP_PATH" "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('resolver_records', 'resolver_history');")" = 2
sqlite3 "$TEMP_PATH" "SELECT COUNT(*) FROM resolver_records WHERE anchor_uuid IS NULL OR state NOT IN ('ACTIVE', 'SUSPENDED', 'RETIRED');" | grep -qx 0

# 同じファイルシステム上の rename を使うため、切り替えは原子的に完了する。
if test -e "$DB_PATH"; then
    mv "$DB_PATH" "$RECOVERY_PATH"
    MOVED_CURRENT=1
fi
for suffix in -wal -shm -journal; do
    if test -e "${DB_PATH}${suffix}"; then
        if test "$MOVED_CURRENT" -eq 1; then
            mv "${DB_PATH}${suffix}" "${RECOVERY_PATH}${suffix}"
        else
            rm -f "${DB_PATH}${suffix}"
        fi
    fi
done

if ! mv "$TEMP_PATH" "$DB_PATH"; then
    if test "$MOVED_CURRENT" -eq 1; then
        mv "$RECOVERY_PATH" "$DB_PATH"
    fi
    exit 1
fi
if test "$MOVED_CURRENT" -eq 1; then
    chmod --reference="$RECOVERY_PATH" "$DB_PATH"
    chown --reference="$RECOVERY_PATH" "$DB_PATH"
fi
echo "復元しました。旧 DB は $RECOVERY_PATH に退避されています。"
