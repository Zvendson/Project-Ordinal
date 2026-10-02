# Project: Ordinal

Its a self hosted tool for build numbers. Create a project, generate a named token and ask for the next number before a build starts.

Every project has its own counter. Two builds starting together get different numbers. Retrying the same request with the same token returns the original number. Failed or cancelled builds still use their number.

A project only needs a name. You can paste a repository URL if you want a link to it, or leave it empty. There is no provider setup or repository ID to look up.

One administrator password protects project management. Each project can have multiple named tokens, like Laptop, Release pipeline or Test runner. Generate them manually and keep each secret somewhere safe. Its shown once and only its hash is stored. Tokens can be renamed, replaced or revoked. Expiration in days is optional.

The counter can be previewed without using a number. Normal edits increase it after builds have started. A confirmed reset can reuse numbers but keeps the original request IDs and their results. Projects can be archived and restored. Build history shows the token names.

## Hosting

Use 64-bit PHP 8.5, PostgreSQL 18 and a HTTPS web server. PHP needs PDO with pdo_pgsql. The standalone PHP build helper also needs curl.

Install the PHP dependencies:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
```

Point the web server at public/ and route application paths to public/index.php. Serve the compiled public/assets too. Keep the rest of the project outside the public files. Pass the Authorization header to PHP and set HTTPS=on in the trusted server configuration.

Set these private environment values for the migration command and PHP service:

| Variable | Value |
| --- | --- |
| ORDINAL_DATABASE_DSN | PostgreSQL PDO DSN, like pgsql:host=127.0.0.1;port=5432;dbname=ordinal |
| ORDINAL_DATABASE_USER | Database login |
| ORDINAL_DATABASE_PASSWORD | Database password |

Create a dedicated database and a non-superuser login. Run migrations as its owner:

```sh
php bin/migrate.php
```

For serving requests, use a separate login with schema usage, table SELECT/INSERT/UPDATE/DELETE and sequence USAGE/SELECT. It does not need to create tables or roles. Apply those grants to the existing tables and set the migration owners default privileges for future tables too.

Set the administrator password through standard input. On PowerShell this keeps it out of shell history and the command arguments:

```powershell
$ordinalPassword = Read-Host 'Administrator password' -AsSecureString
([System.Net.NetworkCredential]::new('', $ordinalPassword)).Password | php bin/set-admin-password.php
```

On Linux:

```sh
read -rs -p 'Administrator password: ' ordinalPassword
printf '%s' "$ordinalPassword" | php bin/set-admin-password.php
unset ordinalPassword
```

Use 12 to 72 bytes. The command saves only a hash in .ordinal/admin.php. Keep that directory private and outside Git. You can select another private PHP config path with ORDINAL_ADMIN_CONFIG, or supply an existing hash through ORDINAL_ADMIN_PASSWORD_HASH instead. There is no .env loader. Configure the actual PHP service environment too.

Open /login, enter the password and create a project. Browser sessions expire after 30 minutes without use or eight hours total. Changing the configured password invalidates existing administrator sessions. Build tokens remain independent from browser sessions.

## Requesting a build number

Generate a token on the projects page, then send:

```http
POST /api/projects/1/build-numbers
Authorization: Bearer YOUR_PROJECT_TOKEN
Content-Type: application/json

{"requestId":"11111111-1111-4111-8111-111111111111"}
```

The project number in that endpoint is assigned by Ordinal and shown after creating the project. It is not a repository ID. Generate a new UUID for a new build attempt, and keep it for every retry of that attempt. A revoked or expired token cannot allocate or replay a number.

The [PHP build helper and CI examples](examples/ci/README.md) save the request ID before calling the server and keep it for retries. They work with the same named project tokens. Hosted runners need network access and TLS trust to the server.

## Frontend assets

Each PHP widget has its own CSS module. TypeScript adds counter preview, copying new tokens and submit feedback. Normal forms and manual copying work without JavaScript.

Build before deployment:

```sh
npm ci --ignore-scripts
npm test
npm run build
```

Node is only needed for the build. The website serves the compiled files.

## Backups and upgrades

Back up the whole database, including permanent request mappings. Keep the administrator config private too. An old database backup can lose newer allocations, so check its recovery point before resuming builds.

Existing installations use the forward migration. It keeps projects, counters, token hashes and retry records. Earlier provider/device tables remain as historical data; they are not used for sign in or allocation. Previously issued automation tokens stay valid for their project unless expired or revoked. Old browser logins and device credentials no longer grant access.

Local tests cover the migration and restore behavior. Actual server and live runner checks still depend on your deployment.

## Tests

The test cases are written by AI.

Use the separate ordinal_test database/login for integration tests. Keep its password and local test configuration out of Git.

```sh
composer install
php vendor/bin/phpunit
npm test
```
