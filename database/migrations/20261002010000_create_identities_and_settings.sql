-- Stable identities and history are retained by restrictive foreign keys.
-- Registration secrets and the provider encryption key stay outside this schema.
CREATE TABLE provider_connections (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    provider_kind VARCHAR(16) NOT NULL CHECK (provider_kind IN ('github', 'gitlab')),
    server_url TEXT NOT NULL CHECK (btrim(server_url) <> ''),
    name TEXT NOT NULL CHECK (btrim(name) <> ''),
    registration_reference TEXT,
    installation_id TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    disabled_at TIMESTAMPTZ
);

CREATE TABLE users (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    provider_connection_id BIGINT NOT NULL REFERENCES provider_connections (id) ON DELETE RESTRICT,
    provider_user_id TEXT NOT NULL CHECK (btrim(provider_user_id) <> ''),
    display_name TEXT NOT NULL CHECK (btrim(display_name) <> ''),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (provider_connection_id, provider_user_id)
);

CREATE TABLE instance_administrators (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id BIGINT NOT NULL UNIQUE REFERENCES users (id) ON DELETE RESTRICT,
    granted_by_user_id BIGINT REFERENCES users (id) ON DELETE RESTRICT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMPTZ
);

CREATE TABLE instance_settings (
    id SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    is_authentication_required BOOLEAN NOT NULL DEFAULT TRUE,
    device_lifetime_days INTEGER NOT NULL DEFAULT 30 CHECK (device_lifetime_days >= -1),
    automation_lifetime_days INTEGER NOT NULL DEFAULT 90 CHECK (automation_lifetime_days > 0),
    is_automation_without_expiration_allowed BOOLEAN NOT NULL DEFAULT FALSE,
    browser_idle_minutes INTEGER NOT NULL DEFAULT 30 CHECK (browser_idle_minutes > 0),
    browser_absolute_minutes INTEGER NOT NULL DEFAULT 720 CHECK (browser_absolute_minutes > 0)
);

INSERT INTO instance_settings DEFAULT VALUES;

-- Counter columns and repository uniqueness are added in the next schema step.
CREATE TABLE projects (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    provider_connection_id BIGINT NOT NULL REFERENCES provider_connections (id) ON DELETE RESTRICT,
    provider_repository_id TEXT NOT NULL CHECK (btrim(provider_repository_id) <> ''),
    name TEXT NOT NULL CHECK (btrim(name) <> ''),
    authentication_required_override BOOLEAN,
    device_lifetime_days_override INTEGER CHECK (device_lifetime_days_override >= -1),
    is_other_history_visible BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TIMESTAMPTZ
);

CREATE INDEX projects_provider_connection ON projects (provider_connection_id);
