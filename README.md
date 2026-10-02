# Project: Ordinal

Its a self hosted website for managing build numbers across multiple projects. Each project has its own counter, so local builds and CI builds can ask for the next number before they start.

The idea is to keep the numbers in one place. If two builds start at the same time they should get different numbers. If a request needs to be retried it should return the same number again. Failed or cancelled builds still use their number.

I am building this step by step, starting with teh backend. The frontend comes later and should stay simple. The website will use HTML, CSS, TypeScript, PHP and PostgreSQL, with GitHub and GitLab for sign in and repository access.

The backend can now sign in through GitHub and GitLab, link repositories to projects and allocate build numbers. Retrying the same request returns its original number. There are simple account and administration pages, without CSS yet.

Devices can get their own project credentials. The secret is shown once and only its hash is stored. Lifetimes can be positive days, 0 for one allocation, or -1 without expiration. Credentials and whole devices can be revoked. Developer requests still check repository write access, including retries.

Projects can now have named CI tokens too. Only the hash is stored and the secret is shown once. They expire after 90 days by default, can be renamed, rotated or revoked, and keep their request history when rotated. CI requests check the token locally, so a provider outage does not stop them.

There is a PHP build helper with examples for [GitHub Actions, GitLab CI and custom builds](examples/ci/README.md). It saves the request ID before asking for a number and keeps it for retries. Temporary failures get five retries, then the build stops. Its important to keep that ID when a runner gets replaced.

Administrators can check the next number without using it, edit the counter and do a confirmed hard reset. A reset needs a recent provider sign in and can reuse older numbers, but it keeps the original request IDs and history. Contributors can see their own builds. Other callers history is off by default and can be enabled per project.

There are project and instance audit pages too. Instance administrators can preview and confirm deletion of old logs. Its only the logs that get removed, counters and permanent retries stay there. Projects can be archived and reactivated without losing their records.

Deployment checks and the frontend are still coming. The provider tests use mocked responses, so live GitHub and GitLab verification is still open. The CI examples have not been run on live runners yet.

## Running the tests

The test cases are written by AI.

You need 64-bit PHP 8.5 and Composer. Install the dependencies and run the unit tests:

```sh
composer install
php vendor/bin/phpunit --testsuite Unit
```

For the database test you need a separate PostgreSQL test database and login called `ordinal_test`. Set `ORDINAL_TEST_DATABASE_DSN` and `ORDINAL_TEST_DATABASE_PASSWORD` locally, then run:

```sh
php vendor/bin/phpunit --testsuite Integration
```

Keep passwords and local test configuration out of Git.
