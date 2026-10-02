-- One repository belongs to one project per configured provider connection.
ALTER TABLE projects
    ADD CONSTRAINT projects_provider_repository_unique
        UNIQUE (provider_connection_id, provider_repository_id);
