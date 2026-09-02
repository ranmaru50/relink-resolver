#!/usr/bin/env sh
# bin/container-restore-acceptance.sh
# Container profileでwww-dataが復元DBを更新し、WAL/SHMを作成できることを検証する。
set -eu

IMAGE="${RELINK_TEST_IMAGE:-relink-resolver-resolver:latest}"
SUFFIX="relink-restore-acceptance-$$"
SOURCE_VOLUME="${SUFFIX}-source"
BACKUP_VOLUME="${SUFFIX}-backup"
TARGET_VOLUME="${SUFFIX}-target"

cleanup() {
    docker volume rm "$SOURCE_VOLUME" "$BACKUP_VOLUME" "$TARGET_VOLUME" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

docker volume create "$SOURCE_VOLUME" >/dev/null
docker volume create "$BACKUP_VOLUME" >/dev/null
docker volume create "$TARGET_VOLUME" >/dev/null

# source volumeをmigration済みDBとして作成し、backup volumeへ退避する。
docker run --rm -v "$SOURCE_VOLUME:/var/lib/relink-resolver" "$IMAGE" true
docker run --rm --entrypoint /var/www/bin/backup.sh -v "$SOURCE_VOLUME:/var/lib/relink-resolver" -v "$BACKUP_VOLUME:/backup" "$IMAGE" /backup/resolver.sqlite

# 危険な権限モードを受け付けず、空のtarget volumeを変更しないことを確認する。
if docker run --rm -e RELINK_DB_MODE=666 --entrypoint /var/www/bin/restore.sh -v "$TARGET_VOLUME:/var/lib/relink-resolver" -v "$BACKUP_VOLUME:/backup" "$IMAGE" /backup/resolver.sqlite; then
    echo "RELINK_DB_MODE=666 was unexpectedly accepted" >&2
    exit 1
fi
if docker run --rm -e RELINK_DATA_DIR_MODE=777 --entrypoint /var/www/bin/restore.sh -v "$TARGET_VOLUME:/var/lib/relink-resolver" -v "$BACKUP_VOLUME:/backup" "$IMAGE" /backup/resolver.sqlite; then
    echo "RELINK_DATA_DIR_MODE=777 was unexpectedly accepted" >&2
    exit 1
fi

# entrypointを介さず、空のtarget volumeへrootとして復元する。
docker run --rm --entrypoint /var/www/bin/restore.sh -v "$TARGET_VOLUME:/var/lib/relink-resolver" -v "$BACKUP_VOLUME:/backup" "$IMAGE" /backup/resolver.sqlite

# www-dataとしてSQLite transactionとWAL/SHM作成を検証する。
docker run --rm --user www-data --entrypoint sh -v "$TARGET_VOLUME:/var/lib/relink-resolver" "$IMAGE" -lc '
    test "$(stat -c "%U:%G" /var/lib/relink-resolver)" = "www-data:www-data"
    test "$(stat -c "%a" /var/lib/relink-resolver)" = "770"
    test "$(stat -c "%U:%G %a" /var/lib/relink-resolver/resolver.sqlite)" = "www-data:www-data 660"
    sqlite3 /var/lib/relink-resolver/resolver.sqlite "PRAGMA journal_mode=WAL; BEGIN IMMEDIATE; INSERT INTO resolver_records (anchor_uuid,state,description_location,entity_id,version,created_at,updated_at) VALUES ('"'"'550e8400-e29b-41d4-a716-446655440034'"'"','"'"'ACTIVE'"'"','"'"'https://entity.example/34.xml'"'"','"'"'urn:relink:entity:34'"'"',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP); COMMIT;"
    test -f /var/lib/relink-resolver/resolver.sqlite-wal
    test -f /var/lib/relink-resolver/resolver.sqlite-shm
    test "$(sqlite3 /var/lib/relink-resolver/resolver.sqlite "SELECT COUNT(*) FROM resolver_records WHERE anchor_uuid = '"'"'550e8400-e29b-41d4-a716-446655440034'"'"';")" = 1
'
