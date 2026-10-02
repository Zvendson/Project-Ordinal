# Asking for a build number

Create a named automation token on the project's automation page. Put its secret in the CI secret store as `ORDINAL_TOKEN`. Only trusted jobs should get it. The token can allocate numbers for its project and retry its own requests. It cannot sign into the website or change settings.

The token expires after 90 days by default. Instance administrators can change that default or allow tokens without expiration. Existing expiry stays the same until rotation. Rotating the secret keeps the token ID, so the replacement can retry the original request. The old secret stops working.

## The PHP helper

You need 64-bit PHP 8.5 with curl, a trusted CA bundle, and network access to the HTTPS instance. The helper does not need Composer, PostgreSQL, or provider registration secrets. Keep this checkout as a trusted tool installation, or copy `bin/request-build-number.php`, `integrations/autoload.php`, `src/Integration/`, `src/Http/HttpResponse.php`, `src/Model/RequestId.php`, and `src/Model/ProviderConfiguration.php` with the same paths.

Set these values in your build environment:

| Variable | Meaning |
| --- | --- |
| `ORDINAL_URL` | HTTPS instance URL, for example `https://ordinal.example` |
| `ORDINAL_PROJECT_ID` | Stable project ID |
| `ORDINAL_TOKEN` | Secret-store token; omit only for explicitly anonymous projects |
| `ORDINAL_BUILD_ATTEMPT` | A stable name for this logical build, such as `release-42`; keep it on retries |
| `ORDINAL_REQUEST_DIRECTORY` | Persistent state directory; defaults to `.ordinal` in the current directory |
| `ORDINAL_REQUEST_ID` | Optional UUID v4 for recovery or a CI-supplied identity |

Run `php /path/to/ordinal/bin/request-build-number.php` before the build. On success it prints only the number. On failure it exits with code 1, prints a short error, and keeps the request state. Stop the build when it fails. Never make up a fallback number.

Its request file is written and flushed before sending. Files are scoped by instance, project and build attempt. A new attempt name gets a new UUID unless you supply one yourself. Repeating an attempt keeps its saved UUID and asks the server again. The helper does not return a cached number without checking the server. Empty or damaged existing state stops the helper; it does not silently replace that ID.

Temporary network failures and HTTP 503 get five retries after the first attempt, after 1/2/4/8/16 seconds. Each request has a 30-second timeout. Other HTTP statuses and invalid success responses stop immediately. The response must match the project and request ID and contain an integer build number in the uint32 range.

The state files contain request metadata, never tokens. Keep `.ordinal/` out of source control and preserve it on a suitable volume or as an artifact. Local files do not survive a deleted runner. If the response got lost, restore the original file or supply its original `ORDINAL_REQUEST_ID` for the same project. If you cannot recover that ID, do not pretend the next request is a retry. A lost file alone cannot tell you whether the server already allocated a number.

## GitHub Actions

Copy `github-actions.yml` to `.github/workflows/` and set the URL/project as repository variables and the token as a repository secret. The example needs a trusted Linux runner tagged `ordinal-build` with PHP 8.5 and curl already available. When using another build repository, change the helper path to its trusted installation.

For each new build, start the workflow with a new UUID-v4 `request_id` input. Rerunning the same workflow run retains that input. To resume from another run, provide the original UUID instead. The request-state artifact is also saved when the job fails, when artifact upload can run. A cancelled job, missing artifact, or expired artifact can still lose local state, so keep the input for recovery. The example uploads only `.ordinal/*.json`, including the hidden directory. These action versions target GitHub.com; check runner compatibility and pin reviewed action revisions for your installation. See [checkout](https://github.com/actions/checkout), [artifact upload](https://github.com/actions/upload-artifact), and [workflow artifacts](https://docs.github.com/en/actions/concepts/workflows-and-actions/workflow-artifacts).

To restore an artifact rather than supply its UUID, download the original run's artifact into `ORDINAL_REQUEST_DIRECTORY` before calling the helper. [download-artifact](https://github.com/actions/download-artifact) supports selecting a run and artifact name with a token that can read that run. Do not use a different project's artifact or skip a failed restore and start with empty state.

## GitLab CI

Copy `gitlab-ci.yml` to `.gitlab-ci.yml`. Configure the URL/project as CI variables and `ORDINAL_TOKEN` as a masked, protected variable. Use a trusted runner with PHP 8.5 and curl. Supply a new `ORDINAL_REQUEST_ID` UUID v4 as a pipeline variable for each new pipeline/build. Keep that pipeline value when retrying its job. The example uses the stable pipeline ID in its attempt name and does not use the changing job ID.

The job also uploads request-state files with `artifacts: when: always`. It does not claim that GitLab automatically restores a failed job's own files when that job is retried. The supplied pipeline UUID lets the helper recreate the same request identity on a replacement runner. To recover in a new pipeline, supply the original UUID or explicitly download and restore the earlier state before allocation. See [job artifacts](https://docs.gitlab.com/ci/jobs/job_artifacts/) and the [CI YAML reference](https://docs.gitlab.com/ci/yaml/).

## Custom builds

Set the environment values above from your local credential store. Use a new `ORDINAL_BUILD_ATTEMPT` for a new build; reuse it and its state directory when retrying. In a shell that stops on errors, for example:

```sh
set -eu
BUILD_NUMBER=$(php /path/to/ordinal/bin/request-build-number.php)
export BUILD_NUMBER
# Run your actual build command here. It only runs after the helper succeeds.
```

Or call the reusable PHP classes from your build script:

```php
require '/path/to/ordinal/integrations/autoload.php';

$client = new Ordinal\Integration\BuildNumberClient(
    new Ordinal\Integration\BuildRequestStore('/persistent/build-request-state'),
    new Ordinal\Integration\CurlBuildNumberTransport(),
);
$number = $client->getBuildNumber(
    'https://ordinal.example',
    1,
    'release-42',
    getenv('ORDINAL_TOKEN') ?: null,
);
```

The helper sends the normal HTTP API request, so other build systems can use the same protocol:

```http
POST /api/projects/1/build-numbers HTTP/1.1
Authorization: Bearer <token from secret storage>
Content-Type: application/json
Accept: application/json

{"requestId":"11111111-1111-4111-8111-111111111111"}
```

Omit Authorization only when the project's build authentication is disabled. A successful response is HTTP 200 with `projectId`, `requestId`, and numeric `buildNumber`. Keep the original request ID for every retry, including after rotating the token. These workflow examples have not been run on live GitHub/GitLab runners yet.
