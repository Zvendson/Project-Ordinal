-- BIGINT supports the full uint32 range on PostgreSQL and the 64-bit PHP runtime.
ALTER TABLE projects
    ADD COLUMN next_build_number BIGINT NOT NULL DEFAULT 1,
    ADD COLUMN has_allocated_build_number BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN is_exhausted BOOLEAN NOT NULL DEFAULT FALSE,
    ADD CONSTRAINT projects_next_build_number_range
        CHECK (next_build_number BETWEEN 0 AND 4294967295),
    ADD CONSTRAINT projects_exhaustion_state
        CHECK (NOT is_exhausted OR (has_allocated_build_number AND next_build_number = 4294967295));

-- Allocations retain their original number even after a later project reset.
ALTER TABLE allocations
    ADD CONSTRAINT allocations_build_number_range
        CHECK (build_number BETWEEN 0 AND 4294967295);
