-- migrations/001_initial.sql
-- Resolver レコードと bounded history の初期スキーマ。
CREATE TABLE resolver_records (
    anchor_uuid TEXT PRIMARY KEY,
    state TEXT NOT NULL CHECK (state IN ('ACTIVE', 'SUSPENDED', 'RETIRED')),
    description_location TEXT NOT NULL,
    entity_id TEXT NOT NULL,
    media_type TEXT NULL,
    integrity_algorithm TEXT NULL,
    integrity_digest TEXT NULL,
    version INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX resolver_records_state_idx ON resolver_records (state);
CREATE TABLE resolver_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    anchor_uuid TEXT NOT NULL,
    event_type TEXT NOT NULL,
    old_state TEXT NULL,
    new_state TEXT NULL,
    old_location TEXT NULL,
    new_location TEXT NULL,
    reason TEXT NOT NULL DEFAULT '',
    actor TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    FOREIGN KEY (anchor_uuid) REFERENCES resolver_records(anchor_uuid)
);
