-- Retain existing counters, allocations and retry namespaces during the provider-free transition.
ALTER TABLE projects ALTER COLUMN provider_connection_id DROP NOT NULL;
ALTER TABLE projects ALTER COLUMN provider_repository_id DROP NOT NULL;
ALTER TABLE projects ADD COLUMN repository_url TEXT;
ALTER TABLE automation_tokens ALTER COLUMN created_by_user_id DROP NOT NULL;

CREATE TABLE administrator_sessions (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    secret_hash CHAR(64) NOT NULL UNIQUE,
    csrf_token CHAR(64) NOT NULL,
    is_authenticated BOOLEAN NOT NULL DEFAULT FALSE,
    password_version CHAR(64),
    created_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    expires_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp() + INTERVAL '8 hours',
    revoked_at TIMESTAMPTZ
);

CREATE TABLE administrator_login_limits (
    client_hash CHAR(64) PRIMARY KEY,
    attempts INTEGER NOT NULL DEFAULT 0,
    window_started_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);

-- Old provider/device tables are historical data, not part of current runtime authentication.
UPDATE browser_sessions SET revoked_at = COALESCE(revoked_at, clock_timestamp());
