<?php

/** Delegates a project allocation request to its controller. */

declare(strict_types=1);

use Ordinal\Controller\BuildNumberController;
use Ordinal\Security\RuntimeAllocationAuthorizer;

return (new BuildNumberController(new RuntimeAllocationAuthorizer($this->accountApplication, $isSecure)))->createBuildNumber(
    $routeParameters['projectId'],
    $body,
    $authorizationHeader,
    $contentType,
);
