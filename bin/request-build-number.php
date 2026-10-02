<?php

/** Requests a build number using secret-store environment variables and durable per-attempt request state. */

declare(strict_types=1);

use Ordinal\Integration\BuildIntegrationException;
use Ordinal\Integration\BuildNumberClient;
use Ordinal\Integration\BuildRequestStore;
use Ordinal\Integration\CurlBuildNumberTransport;

require dirname(__DIR__) . '/integrations/autoload.php';

try {
    $serverUrl = getenv('ORDINAL_URL');
    $project = getenv('ORDINAL_PROJECT_ID');
    $buildAttempt = getenv('ORDINAL_BUILD_ATTEMPT');
    $projectId = filter_var($project, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($serverUrl === false || $buildAttempt === false || $project === false || $projectId === false || preg_match('/^[1-9][0-9]*$/D', $project) !== 1) {
        throw new BuildIntegrationException('Set ORDINAL_URL, ORDINAL_PROJECT_ID, and ORDINAL_BUILD_ATTEMPT before requesting a number.');
    }
    $stateDirectory = getenv('ORDINAL_REQUEST_DIRECTORY');
    $token = getenv('ORDINAL_TOKEN');
    $requestId = getenv('ORDINAL_REQUEST_ID');
    $client = new BuildNumberClient(new BuildRequestStore($stateDirectory === false || $stateDirectory === '' ? getcwd() . '/.ordinal' : $stateDirectory), new CurlBuildNumberTransport());
    $number = $client->getBuildNumber($serverUrl, $projectId, $buildAttempt, $token === false || $token === '' ? null : $token, $requestId === false || $requestId === '' ? null : $requestId);
    fwrite(STDOUT, $number . PHP_EOL);
} catch (BuildIntegrationException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, 'The build-number helper failed. Stop the build and preserve its request state.' . PHP_EOL);
    exit(1);
}
