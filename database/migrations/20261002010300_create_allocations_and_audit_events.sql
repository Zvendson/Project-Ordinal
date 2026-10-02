-- Permanent allocations are independent of deletable audit events.
-- Counter bounds and retry uniqueness follow in their dedicated guide steps.
CREATE TABLE allocations (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    project_id BIGINT NOT NULL REFERENCES projects (id) ON DELETE RESTRICT,
    request_id UUID NOT NULL,
    build_number BIGINT NOT NULL,
    caller_kind VARCHAR(16) NOT NULL CHECK (caller_kind IN ('user', 'automation', 'anonymous')),
    user_id BIGINT REFERENCES users (id) ON DELETE RESTRICT,
    automation_token_id BIGINT,
    device_credential_id BIGINT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (id, project_id),
    UNIQUE (id, device_credential_id),
    FOREIGN KEY (automation_token_id, project_id) REFERENCES automation_tokens (id, project_id) ON DELETE RESTRICT,
    FOREIGN KEY (device_credential_id, project_id, user_id) REFERENCES device_credentials (id, project_id, user_id) ON DELETE RESTRICT,
    CHECK ((caller_kind = 'user' AND user_id IS NOT NULL AND automation_token_id IS NULL)
        OR (caller_kind = 'automation' AND automation_token_id IS NOT NULL AND user_id IS NULL AND device_credential_id IS NULL)
        OR (caller_kind = 'anonymous' AND user_id IS NULL AND automation_token_id IS NULL AND device_credential_id IS NULL))
);

ALTER TABLE device_credentials ADD CONSTRAINT device_credentials_consumed_allocation
    FOREIGN KEY (consumed_allocation_id, id) REFERENCES allocations (id, device_credential_id) ON DELETE RESTRICT;

CREATE TABLE audit_events (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    project_id BIGINT REFERENCES projects (id) ON DELETE RESTRICT,
    allocation_id BIGINT,
    user_id BIGINT REFERENCES users (id) ON DELETE RESTRICT,
    automation_token_id BIGINT,
    device_credential_id BIGINT,
    action TEXT NOT NULL CHECK (btrim(action) <> ''),
    outcome TEXT NOT NULL CHECK (btrim(outcome) <> ''),
    details JSONB NOT NULL DEFAULT '{}'::JSONB CHECK (jsonb_typeof(details) = 'object'),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (allocation_id, project_id) REFERENCES allocations (id, project_id) ON DELETE RESTRICT,
    FOREIGN KEY (automation_token_id, project_id) REFERENCES automation_tokens (id, project_id) ON DELETE RESTRICT,
    FOREIGN KEY (device_credential_id, project_id, user_id) REFERENCES device_credentials (id, project_id, user_id) ON DELETE RESTRICT,
    CHECK (allocation_id IS NULL OR project_id IS NOT NULL),
    CHECK (automation_token_id IS NULL OR (project_id IS NOT NULL AND user_id IS NULL)),
    CHECK (device_credential_id IS NULL OR (project_id IS NOT NULL AND user_id IS NOT NULL))
);

CREATE INDEX allocations_project_created ON allocations (project_id, created_at, id);
CREATE INDEX audit_events_project_created ON audit_events (project_id, created_at, id);
CREATE INDEX audit_events_created ON audit_events (created_at, id);
