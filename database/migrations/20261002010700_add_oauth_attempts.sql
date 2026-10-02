CREATE UNIQUE INDEX provider_connections_registration_reference
    ON provider_connections (registration_reference) WHERE registration_reference IS NOT NULL;

ALTER TABLE users ADD COLUMN user_name TEXT NOT NULL DEFAULT '';

CREATE TABLE oauth_attempts (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    provider_connection_id BIGINT NOT NULL REFERENCES provider_connections (id) ON DELETE RESTRICT,
    state_hash CHAR(64) NOT NULL UNIQUE CHECK (state_hash ~ '^[a-f0-9]{64}$'),
    browser_hash CHAR(64) NOT NULL CHECK (browser_hash ~ '^[a-f0-9]{64}$'),
    encrypted_verifier BYTEA NOT NULL CHECK (octet_length(encrypted_verifier) > 0),
    browser_session_id BIGINT REFERENCES browser_sessions (id) ON DELETE RESTRICT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX oauth_attempts_expiry ON oauth_attempts (expires_at);
