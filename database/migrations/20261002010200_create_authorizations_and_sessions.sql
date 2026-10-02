-- These BYTEA fields hold ciphertext; encryption itself is implemented with provider login.
CREATE TABLE provider_authorizations (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id BIGINT NOT NULL UNIQUE REFERENCES users (id) ON DELETE RESTRICT,
    encrypted_access_token BYTEA NOT NULL CHECK (octet_length(encrypted_access_token) > 0),
    encrypted_refresh_token BYTEA CHECK (octet_length(encrypted_refresh_token) > 0),
    access_expires_at TIMESTAMPTZ,
    refresh_expires_at TIMESTAMPTZ,
    refresh_version BIGINT NOT NULL DEFAULT 0 CHECK (refresh_version >= 0),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMPTZ
);

CREATE TABLE browser_sessions (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
    secret_hash CHAR(64) NOT NULL UNIQUE CHECK (secret_hash ~ '^[a-f0-9]{64}$'),
    csrf_token CHAR(64) NOT NULL CHECK (csrf_token ~ '^[a-f0-9]{64}$'),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMPTZ NOT NULL,
    idle_minutes INTEGER NOT NULL DEFAULT 30 CHECK (idle_minutes > 0),
    absolute_minutes INTEGER NOT NULL DEFAULT 720 CHECK (absolute_minutes > 0),
    provider_reauthenticated_at TIMESTAMPTZ,
    revoked_at TIMESTAMPTZ,
    CHECK (last_activity_at >= created_at),
    CHECK (expires_at > created_at)
);

CREATE INDEX browser_sessions_user ON browser_sessions (user_id);
