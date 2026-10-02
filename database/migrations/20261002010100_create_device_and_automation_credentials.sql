CREATE TABLE devices (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
    name TEXT NOT NULL CHECK (btrim(name) <> ''),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMPTZ,
    UNIQUE (id, user_id)
);

-- Expiration belongs to each issued credential, independently of later policy edits.
CREATE TABLE device_credentials (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    device_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    project_id BIGINT NOT NULL REFERENCES projects (id) ON DELETE RESTRICT,
    secret_hash CHAR(64) NOT NULL UNIQUE CHECK (secret_hash ~ '^[a-f0-9]{64}$'),
    authenticated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lifetime_days INTEGER NOT NULL CHECK (lifetime_days >= -1),
    expires_at TIMESTAMPTZ,
    revoked_at TIMESTAMPTZ,
    consumed_allocation_id BIGINT,
    FOREIGN KEY (device_id, user_id) REFERENCES devices (id, user_id) ON DELETE RESTRICT,
    UNIQUE (id, project_id, user_id),
    CHECK ((lifetime_days > 0 AND expires_at IS NOT NULL AND expires_at > authenticated_at)
        OR (lifetime_days IN (-1, 0) AND expires_at IS NULL)),
    CHECK (consumed_allocation_id IS NULL OR lifetime_days = 0)
);

CREATE TABLE automation_tokens (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    project_id BIGINT NOT NULL REFERENCES projects (id) ON DELETE RESTRICT,
    created_by_user_id BIGINT NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
    name TEXT NOT NULL CHECK (btrim(name) <> ''),
    secret_hash CHAR(64) NOT NULL UNIQUE CHECK (secret_hash ~ '^[a-f0-9]{64}$'),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMPTZ,
    revoked_at TIMESTAMPTZ,
    rotated_at TIMESTAMPTZ,
    UNIQUE (id, project_id),
    CHECK (expires_at IS NULL OR expires_at > created_at)
);

CREATE INDEX devices_user ON devices (user_id);
CREATE INDEX device_credentials_device ON device_credentials (device_id);
CREATE INDEX device_credentials_project ON device_credentials (project_id);
