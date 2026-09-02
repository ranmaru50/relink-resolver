#!/usr/bin/env sh
# bin/restore.sh
# 検証済みバックアップを一時 DB に復元し、現 DB を退避してから原子的に置換する。
set -eu
SOURCE="${1:?backup source is required}"
DB_PATH="${RELINK_DB_PATH:-/var/lib/relink-resolver/resolver.sqlite}"
DB_DIR="$(dirname "$DB_PATH")"
TEMP_PATH="${DB_PATH}.restore.$$"
RECOVERY_PATH="${DB_PATH}.pre-restore.$$.bak"
RECOVERY_TEMP_PATH="${RECOVERY_PATH}.tmp"
SERVICE_USER="${RELINK_SERVICE_USER:-www-data}"
SERVICE_UID="${RELINK_SERVICE_UID:-}"
SERVICE_GID="${RELINK_SERVICE_GID:-}"
DB_MODE="${RELINK_DB_MODE:-660}"
DATA_DIR_MODE="${RELINK_DATA_DIR_MODE:-770}"

cleanup() {
    rm -f "$TEMP_PATH" "$RECOVERY_TEMP_PATH"
}
trap cleanup EXIT INT TERM

ensure_owner() {
    expected_uid="$1"
    expected_gid="$2"
    target_path="$3"
    actual_uid="$(stat -c '%u' "$target_path")"
    actual_gid="$(stat -c '%g' "$target_path")"
    if test "$actual_uid" != "$expected_uid" || test "$actual_gid" != "$expected_gid"; then
        chown "$expected_uid:$expected_gid" "$target_path"
    fi
}

validate_numeric() {
    value_name="$1"
    value="$2"
    case "$value" in
        ''|*[!0-9]*)
            echo "$value_name must be a non-negative integer" >&2
            exit 1
            ;;
    esac
}

validate_db_mode() {
    value="$1"
    case "$value" in
        0[0-7][0-7][0-7]) value="${value#0}" ;;
        [0-7][0-7][0-7]) ;;
        *)
            echo "RELINK_DB_MODE must be a three-digit octal mode without special bits" >&2
            exit 1
            ;;
    esac
    case "$value" in
        [67][0-7][0145]) ;;
        *)
            echo "RELINK_DB_MODE must grant owner write and deny world write" >&2
            exit 1
            ;;
    esac
}

validate_data_dir_mode() {
    value="$1"
    case "$value" in
        0[0-7][0-7][0-7]) value="${value#0}" ;;
        [0-7][0-7][0-7]) ;;
        *)
            echo "RELINK_DATA_DIR_MODE must be a three-digit octal mode without special bits" >&2
            exit 1
            ;;
    esac
    case "$value" in
        7[0-7][0145]) ;;
        *)
            echo "RELINK_DATA_DIR_MODE must grant owner read/write/search and deny world write" >&2
            exit 1
            ;;
    esac
}

if test -n "$SERVICE_UID"; then
    validate_numeric RELINK_SERVICE_UID "$SERVICE_UID"
fi
if test -n "$SERVICE_GID"; then
    validate_numeric RELINK_SERVICE_GID "$SERVICE_GID"
fi
validate_db_mode "$DB_MODE"
validate_data_dir_mode "$DATA_DIR_MODE"

test -f "$SOURCE"
sqlite3 "$SOURCE" "PRAGMA integrity_check;" | grep -qx ok
mkdir -p "$DB_DIR"
rm -f "$TEMP_PATH" "$RECOVERY_TEMP_PATH"
sqlite3 "$SOURCE" ".backup '$TEMP_PATH'"
sqlite3 "$TEMP_PATH" "PRAGMA integrity_check;" | grep -qx ok
# アプリケーションが必要とするテーブルとライフサイクル値を検証してから切り替える。
test "$(sqlite3 "$TEMP_PATH" "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('resolver_records', 'resolver_history');")" = 2
sqlite3 "$TEMP_PATH" "SELECT COUNT(*) FROM resolver_records WHERE anchor_uuid IS NULL OR state NOT IN ('ACTIVE', 'SUSPENDED', 'RETIRED');" | grep -qx 0

if test -e "$DB_PATH"; then
    # アプリケーション停止済みを前提にWALを確定し、現DBを別ファイルへ安全に退避する。
    checkpoint_result="$(sqlite3 "$DB_PATH" "PRAGMA wal_checkpoint(TRUNCATE);")"
    test "${checkpoint_result%%|*}" = 0
    sqlite3 "$DB_PATH" ".backup '$RECOVERY_TEMP_PATH'"
    sqlite3 "$RECOVERY_TEMP_PATH" "PRAGMA integrity_check;" | grep -qx ok
    mv "$RECOVERY_TEMP_PATH" "$RECOVERY_PATH"
    # checkpoint後の副ファイルを除去して、新DBが古いWAL/SHMを再利用しないようにする。
    rm -f "${DB_PATH}-wal" "${DB_PATH}-shm" "${DB_PATH}-journal"
    chmod --reference="$DB_PATH" "$TEMP_PATH"
    chown --reference="$DB_PATH" "$TEMP_PATH"
else
    # 空の永続volumeでもApache/PHPのサービスユーザーがDBと副ファイルを更新できるようにする。
    if test -z "$SERVICE_UID"; then
        SERVICE_UID="$(id -u "$SERVICE_USER")"
    fi
    if test -z "$SERVICE_GID"; then
        SERVICE_GID="$(id -g "$SERVICE_USER")"
    fi
    validate_numeric RELINK_SERVICE_UID "$SERVICE_UID"
    validate_numeric RELINK_SERVICE_GID "$SERVICE_GID"
    ensure_owner "$SERVICE_UID" "$SERVICE_GID" "$DB_DIR"
    chmod "$DATA_DIR_MODE" "$DB_DIR"
    ensure_owner "$SERVICE_UID" "$SERVICE_GID" "$TEMP_PATH"
    chmod "$DB_MODE" "$TEMP_PATH"
fi

# 現在の DB パスを維持したまま、同一ディレクトリ内の rename で原子的に置換する。
mv "$TEMP_PATH" "$DB_PATH"
if test -e "$RECOVERY_PATH"; then
    echo "復元しました。旧 DB は $RECOVERY_PATH に退避されています。"
else
    echo "復元しました。既存 DB はありませんでした。"
fi
