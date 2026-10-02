# Project: Ordinal

Its a self hosted website for managing build numbers across multiple projects. Each project has its own counter, so local builds and CI builds can ask for the next number before they start.

The idea is to keep the numbers in one place. If two builds start at the same time they should get different numbers. If a request needs to be retried it should return the same number again. Failed or cancelled builds still use their number.

I am building this step by step, starting with teh backend. The frontend comes later and should stay simple. The website will use HTML, CSS, TypeScript, PHP and PostgreSQL, with GitHub and GitLab for sign in and repository access.

The backend can now sign in through GitHub and GitLab, link repositories to projects and allocate build numbers. Retrying the same request returns its original number. There are simple account and administration pages, without CSS yet.

Devices can get their own project credentials. The secret is shown once and only its hash is stored. Lifetimes can be positive days, 0 for one allocation, or -1 without expiration. Credentials and whole devices can be revoked. Developer requests still check repository write access, including retries.

CI tokens, the remaining counter and history controls, deployment checks and the frontend are still coming. The provider tests use mocked responses, so live GitHub and GitLab verification is still open.

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
