<?php

/** Delegates a project allocation request to its controller. */

declare(strict_types=1);

use Ordinal\Controller\BuildNumberController;

return (new BuildNumberController())->createBuildNumber(
    $routeParameters['projectId'],
    $body,
    $authorizationHeader,
    $contentType,
);
