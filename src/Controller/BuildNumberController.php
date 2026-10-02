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
        $caller = null;
        $requestId = null;
        $parsedProjectId = filter_var($projectId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $auditProjectId = preg_match('/^[1-9][0-9]*$/D', $projectId) === 1 && $parsedProjectId !== false ? $parsedProjectId : null;
        if (preg_match('/^[1-9][0-9]*$/D', $projectId) !== 1 || $parsedProjectId === false
            || strtolower(trim(explode(';', $contentType ?? '', 2)[0])) !== 'application/json') {
            return $this->createAllocationError('INVALID_REQUEST', $auditProjectId);
        }

        try {
            $request = json_decode($body, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->createAllocationError('INVALID_REQUEST', $auditProjectId);
        }

        if (!$request instanceof stdClass || !isset($request->requestId) || !is_string($request->requestId)
            || !RequestId::isValid($request->requestId)) {
            return $this->createAllocationError('INVALID_REQUEST', $auditProjectId);
        }
        $requestId = strtolower($request->requestId);

        $bearerToken = null;
        if ($authorizationHeader !== null) {
            if (preg_match(self::BEARER_PATTERN, $authorizationHeader, $matches) !== 1) {
                return $this->createAllocationError('INVALID_AUTHENTICATION', $auditProjectId, $requestId);
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
        } catch (AuthenticationException $exception) {
            return $this->createAllocationError('INVALID_AUTHENTICATION', $auditProjectId, $requestId, $caller ?? $this->authorizer->getVerifiedCaller(), reason: $exception->reason);
        } catch (AllocationException $exception) {
            return $this->createAllocationError($exception->errorCode, $auditProjectId, $requestId, $caller ?? $this->authorizer->getVerifiedCaller());
        } catch (\Throwable) {
            return $this->createAllocationError('INTERNAL_ERROR', $auditProjectId, $requestId, $caller ?? $this->authorizer->getVerifiedCaller());
        }

        return Response::createJson([
            'projectId'   => $parsedProjectId,
            'requestId'   => $requestId,
            'buildNumber' => $buildNumber,
        ]);
    }

    /**
     * Records a wrong-method allocation request and preserves the Allow header.
     *
     * @param string $projectId
     * @return Response
     */
    public function createMethodError(string $projectId): Response
    {
        $id = filter_var($projectId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $this->createAllocationError('METHOD_NOT_ALLOWED', $id !== false && preg_match('/^[1-9][0-9]*$/D', $projectId) === 1 ? $id : null, headers: ['Allow' => 'POST']);
    }

    /**
     * Records validated failure metadata when runtime storage is available, retaining the public error if storage fails.
     *
     * @param string $code
     * @param ?int $projectId
     * @param ?string $requestId
     * @param ?\Ordinal\Model\AllocationCaller $caller
     * @param array $headers
     * @param ?string $reason
     * @return Response
     */
    private function createAllocationError(
        string                           $code,
        ?int                             $projectId,
        ?string                          $requestId = null,
        ?\Ordinal\Model\AllocationCaller $caller    = null,
        array                            $headers   = [],
        ?string                          $reason    = null,
    ): Response
    {
        if ($this->authorizer instanceof RuntimeAllocationAuthorizer) {
            try { $this->authorizer->getAuditRepository()->recordAllocationFailure($projectId, $requestId, $caller, $code, $reason); }
            catch (\Throwable) { \Ordinal\Http\OperationalLog::recordAuditFailure(); }
        }
        return ApiError::createResponse($code, $headers);
    }
}
