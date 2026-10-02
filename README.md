# Project: Ordinal

Its a self hosted website for managing build numbers across multiple projects. Each project has its own counter, so local builds and CI builds can ask for the next number before they start.

The idea is to keep the numbers in one place. If two builds start at the same time they should get different numbers. If a request needs to be retried it should return the same number again. Failed or cancelled builds still use their number.

I am building this step by step, starting with teh backend. The frontend comes later and should stay simple. The website will use HTML, CSS, TypeScript, PHP and PostgreSQL, with GitHub and GitLab for sign in and repository access.

The backend can now sign in through GitHub and GitLab, link repositories to projects and allocate build numbers. Retrying the same request returns its original number. There are simple account and administration pages with shared navigation and styles for smaller screens too.

Devices can get their own project credentials. The secret is shown once and only its hash is stored. Lifetimes can be positive days, 0 for one allocation, or -1 without expiration. Credentials and whole devices can be revoked. Developer requests still check repository write access, including retries.

Projects can now have named CI tokens too. Only the hash is stored and the secret is shown once. They expire after 90 days by default, can be renamed, rotated or revoked, and keep their request history when rotated. CI requests check the token locally, so a provider outage does not stop them.

There is a PHP build helper with examples for [GitHub Actions, GitLab CI and custom builds](examples/ci/README.md). It saves the request ID before asking for a number and keeps it for retries. Temporary failures get five retries, then the build stops. Its important to keep that ID when a runner gets replaced.

Administrators can check the next number without using it, edit the counter and do a confirmed hard reset. A reset needs a recent provider sign in and can reuse older numbers, but it keeps the original request IDs and history. Contributors can see their own builds. Other callers history is off by default and can be enabled per project.

There are project and instance audit pages too. Instance administrators can preview and confirm deletion of old logs. Its only the logs that get removed, counters and permanent retries stay there. Projects can be archived and reactivated without losing their records.

The backend tests and a PostgreSQL backup restore have passed locally. The simple frontend is there now too. Provider tests use mocked responses, so live GitHub and GitLab verification is still open. The CI examples have not been run on live runners yet. Apache, Nginx and deployed HTTPS still need to be checked on the actual server.

## Frontend assets

Each PHP widget has its own CSS module. Shared styles keep forms and navigation consistent. TypeScript adds an inline next-number preview, a copy button for new secrets and feedback while forms are submitted. Normal forms and manual secret copying still work without JavaScript.

Build the assets before deploying. Node and npm are only needed on the build machine, the running website serves the compiled files from public/assets:

```sh
npm ci --ignore-scripts
npm test
npm run build
```

The build uses TypeScript 7.0.2 and PHP to combine the styles. Keep the compiled release files with the deployment. Its still the backend that checks permissions, CSRF and reset confirmations.

## Running the website

Use 64-bit PHP 8.5 and PostgreSQL 18. PHP needs curl, openssl, PDO with pdo_pgsql, and sodium. Install the release dependencies from the project folder:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
```

Point the web server at `public/` and send application routes to `public/index.php`. Keep the rest of the project outside the public files. Use HTTPS and pass the bearer Authorization header to PHP. PHP has to receive `HTTPS=on` from the trusted server configuration. Its not enough to send an X-Forwarded-Proto header.

Apache 2.4 can use `FallbackResource /index.php` with directory listing and MultiViews disabled. Nginx can use `try_files $uri /index.php$is_args$args` and a FastCGI location for index.php. Linux can run PHP-FPM, Windows can run the NTS php-cgi build with a supervised loopback FastCGI listener. Check the configuration before starting it. Native Windows Nginx is still described as beta by its maintainers. See the [Apache routing](https://httpd.apache.org/docs/2.4/rewrite/remapping.html), [Nginx FastCGI](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html) and [Windows Nginx](https://nginx.org/en/docs/windows.html) documentation.

Set these values in the private environment of the PHP service and migration command. There is no .env loader:

| Variable | What it contains |
| --- | --- |
| ORDINAL_DATABASE_DSN | A PostgreSQL PDO DSN, like pgsql:host=127.0.0.1;port=5432;dbname=ordinal |
| ORDINAL_DATABASE_USER | The database login |
| ORDINAL_DATABASE_PASSWORD | Its password |
| ORDINAL_SECURITY_CONFIG | An absolute path to the private PHP configuration |

The private configuration returns an array with `encryptionKey`, `bootstrapConnection`, `bootstrapUserId` and `connections`. Each named connection contains `kind`, `serverUrl`, `clientId`, `clientSecret` and `redirectUri`. Keep this file outside public/ and Git, readable only by the operator and PHP service. Generate the encryption key once from 32 random bytes and save its 64 character hex value. Dont generate a different key on each request.

Register a [GitHub App](https://docs.github.com/en/apps/creating-github-apps/registering-a-github-app/registering-a-github-app) with Metadata read permission, expiring user tokens and installation on the managed repositories. Use kind `github` and serverUrl `https://github.com`. GitHub Enterprise Server is outside the current implementation. A [GitLab OAuth application](https://docs.gitlab.com/integration/oauth_provider/) uses kind `gitlab`, its HTTPS server URL and read_user/read_api scopes. Each registration needs the exact callback `https://YOUR_HOST/login/callback`. GitLab 17.4 or newer is the provisional baseline, no live version has been verified yet.

The bootstrap connection names one configured connection. The bootstrap user is that providers immutable numeric user ID, not the username. Only that account gets the first instance administrator role. Sign in with it, then configure the allowed connections and add other administrators.

Create a dedicated database and non-superuser logins. Run migrations with the database owner, then serve requests with a separate login that can use the schema, read and change application tables and use sequences. It does not need to create tables or database roles:

```sh
php bin/migrate.php
```

Use a trusted CA bundle for outbound provider requests, disable displayed PHP errors and protect error logs. Access logs should leave out query strings, request bodies and credential headers. CI runners need network access and TLS trust to this websites HTTPS address, a local address wont be reachable from a hosted runner automatically. macOS deployment is still provisional.

## Backups

Back up the whole database, including permanent request mappings. Save the private configuration and original encryption key separately in a protected backup. The database alone cannot recover encrypted provider credentials without that key. Keep backups outside public/ and Git.

With PostgreSQL 18 tools, use a protected password file instead of putting passwords in the command. Replace the host and database names before running:

```sh
pg_dump --host=DB_HOST --username=ordinal_owner --dbname=ordinal --format=custom --file=ordinal.dump
pg_restore --list ordinal.dump
pg_restore --host=RESTORE_HOST --username=ordinal_owner --dbname=ordinal_restore --exit-on-error --single-transaction --no-owner --no-privileges ordinal.dump
```

Restore into a new empty database with traffic stopped, restore the matching configuration and reapply the application logins permissions. Database roles are not part of this archive. Check the counters and replay an old request before starting builds again. An old backup can lose newer allocations, so check the recovery point first. See [pg_dump](https://www.postgresql.org/docs/current/app-pgdump.html) and [pg_restore](https://www.postgresql.org/docs/current/app-pgrestore.html).

The local restore check used PostgreSQL 18.6 and fake provider credentials. It verified counters, old CI and device retries after a reset, decrypted provider tokens with the copied key and rejected a wrong key. Its not a production restore or live provider check.

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
