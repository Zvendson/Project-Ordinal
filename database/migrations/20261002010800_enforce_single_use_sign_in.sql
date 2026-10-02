-- One provider sign-in can approve at most one lifetime-zero credential per project.
-- Reauthentication creates a new timestamp; ordinary activity and refresh do not.
ALTER TABLE device_credentials ADD COLUMN provider_sign_in_at TIMESTAMPTZ;
ALTER TABLE device_credentials ADD CONSTRAINT device_credentials_sign_in_time
    CHECK (provider_sign_in_at IS NULL OR provider_sign_in_at = authenticated_at);
CREATE UNIQUE INDEX device_credentials_single_use_sign_in
    ON device_credentials (user_id, project_id, provider_sign_in_at)
    WHERE lifetime_days = 0 AND provider_sign_in_at IS NOT NULL;
