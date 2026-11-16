-- migrations/004_l2_authority.sql
-- Adds L2 authority storage for Issue #28 (Stage 3): Trust Anchor Binding (§9.3),
-- Authority Records stored VERBATIM plus derived query columns (§9.5/§6.3), and
-- L2 statements per anchor (§6.3). The wire column stores the canonical wire payload
-- string byte-for-byte; readers must reconstruct Records from the wire, not from the
-- derived columns.

CREATE TABLE trust_anchor_bindings (
    anchor_id TEXT PRIMARY KEY,
    entity_id TEXT NOT NULL,
    -- Stored key_id is informational; loads re-derive keyId from public_key (§9.4 fail-closed).
    key_id TEXT NOT NULL,
    public_key TEXT NOT NULL,
    algorithm TEXT NOT NULL CHECK (algorithm = 'ed25519'),
    initial_epoch_id TEXT NOT NULL,
    initial_statement_floor INTEGER NOT NULL CHECK (initial_statement_floor >= 0),
    created_at TEXT NOT NULL
);

CREATE TABLE l2_records (
    anchor_id TEXT NOT NULL,
    -- L2-SRV-002: record generations are unique per anchor and start at 0.
    record_generation INTEGER NOT NULL CHECK (record_generation >= 0),
    wire TEXT NOT NULL,
    entity_id TEXT NOT NULL,
    signer_key_id TEXT NOT NULL,
    epoch_id TEXT NOT NULL,
    issued_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    statement_floor INTEGER NOT NULL CHECK (statement_floor >= 0),
    -- §9.5: prev_record_digest is NULL for Record 0, lowercase hex SHA-256 otherwise.
    prev_record_digest TEXT NULL,
    stored_at TEXT NOT NULL,
    PRIMARY KEY (anchor_id, record_generation)
);

CREATE INDEX l2_records_generation_idx ON l2_records (anchor_id, record_generation);

CREATE TABLE l2_statements (
    anchor_id TEXT NOT NULL,
    key_id TEXT NOT NULL,
    generation INTEGER NOT NULL CHECK (generation >= 0),
    wire TEXT NOT NULL,
    issued_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    stored_at TEXT NOT NULL,
    PRIMARY KEY (anchor_id, key_id, generation)
);

CREATE INDEX l2_statements_generation_idx ON l2_statements (anchor_id, generation);
