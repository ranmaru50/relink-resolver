-- 管理ログイン試行のIP単位レート制限状態。識別子はIPのSHA-256ハッシュだけを保存する。
CREATE TABLE admin_login_throttles (
    subject_hash TEXT PRIMARY KEY,
    failures INTEGER NOT NULL CHECK (failures >= 0),
    window_started_at INTEGER NOT NULL,
    locked_until INTEGER NOT NULL
);
