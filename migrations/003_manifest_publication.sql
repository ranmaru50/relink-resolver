-- 既存 DB に Manifest 公開設定と integrity の管理メタデータを追加する。
ALTER TABLE resolver_records ADD COLUMN integrity_source TEXT NULL;
ALTER TABLE resolver_records ADD COLUMN manifest_enabled INTEGER NOT NULL DEFAULT 1 CHECK (manifest_enabled IN (0, 1));
