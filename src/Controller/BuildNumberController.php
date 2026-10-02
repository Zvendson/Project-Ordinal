<?php

declare(strict_types=1);

namespace Ordinal\Controller;

use JsonException;
use Ordinal\Configuration\ConfigurationLoader;
use Ordinal\Database\ConnectionFactory;
use Ordinal\Http\ApiError;
use Ordinal\Http\Response;
use Ordinal\Security\AllocationAuthorizer;
use Ordinal\Security\AuthenticationException;
use Ordinal\Security\DenyingAllocationAuthorizer;
use Ordinal\Security\RuntimeAllocationAuthorizer;
use Ordinal\Service\AllocationException;
use Ordinal\Service\BuildNumberService;
use SensitiveParameter;
use stdClass;
use Ordinal\Model\RequestId;

/** Validates allocation requests and enforces their authorization boundary. */
final class BuildNumberController
{
    /** Matches a Bearer token without accepting other authentication schemes. */
    private const string BEARER_PATTERN = '/^Bearer ([A-Za-z0-9\-._~+\/]+=*)$/iD';

    /**
     * Uses denying authorization by default and opens the database only after acceptance.
     *
     * @param AllocationAuthorizer $authorizer
     * @param ?BuildNumberService $service
     */
    public function __construct(
        /** Verifies allocation permissions before business logic can run. */
        private readonly AllocationAuthorizer $authorizer = new DenyingAllocationAuthorizer(),
        /** Supplies a transaction service or lets an authorized request create one lazily. */
        private readonly ?BuildNumberService  $service    = null,
    ) {}

    /**
     * Returns an allocation/replay response only after input validation and authorization.
     *
     * @param string $projectId
     * @param string $body
     * @param ?string $authorizationHeader
     * @param ?string $contentType
     * @return Response
     * @throws \Throwable
     */
    public function createBuildNumber(
        string  $projectId,
        string  $body,
        #[SensitiveParameter]
        ?string $authorizationHeader,
        ?string $contentType,
    ): Response {
        $parsedProjectId = filter_var($projectId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (preg_match('/^[1-9][0-9]*$/D', $projectId) !== 1 || $parsedProjectId === false
            || strtolower(trim(explode(';', $contentType ?? '', 2)[0])) !== 'application/json') {
            return ApiError::createResponse('INVALID_REQUEST');
        }

        try {
            $request = json_decode($body, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ApiError::createResponse('INVALID_REQUEST');
        }

        if (!$request instanceof stdClass || !isset($request->requestId) || !is_string($request->requestId)
            || !RequestId::isValid($request->requestId)) {
            return ApiError::createResponse('INVALID_REQUEST');
        }

        $bearerToken = null;
        if ($authorizationHeader !== null) {
            if (preg_match(self::BEARER_PATTERN, $authorizationHeader, $matches) !== 1) {
                return ApiError::createResponse('INVALID_AUTHENTICATION');
            }
            $bearerToken = $matches[1];
        }

        try {
            $caller      = $this->authorizer->authorizeAllocation($parsedProjectId, $bearerToken);
            $requestId   = strtolower($request->requestId);
            $service     = $this->service ?? ($this->authorizer instanceof RuntimeAllocationAuthorizer
                ? $this->authorizer->getBuildNumberService()
                : new BuildNumberService(ConnectionFactory::createConnection(ConfigurationLoader::loadFromEnvironment())));
            $buildNumber = $service->allocateBuildNumber($parsedProjectId, $requestId, $caller);
        } catch (AuthenticationException) {
            return ApiError::createResponse('INVALID_AUTHENTICATION');
        } catch (AllocationException $exception) {
            return ApiError::createResponse($exception->errorCode);
        }

        return Response::createJson([
            'projectId'   => $parsedProjectId,
            'requestId'   => $requestId,
            'buildNumber' => $buildNumber,
        ]);
    }
}
