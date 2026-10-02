-- Partial indexes give anonymous NULL callers their own retry namespace.
CREATE UNIQUE INDEX allocations_user_request
    ON allocations (project_id, user_id, request_id) WHERE caller_kind = 'user';
CREATE UNIQUE INDEX allocations_automation_request
    ON allocations (project_id, automation_token_id, request_id) WHERE caller_kind = 'automation';
CREATE UNIQUE INDEX allocations_anonymous_request
    ON allocations (project_id, request_id) WHERE caller_kind = 'anonymous';
