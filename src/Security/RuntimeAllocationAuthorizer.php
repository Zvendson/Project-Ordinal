<?php

declare(strict_types=1);

namespace Ordinal\Security;

use InvalidArgumentException;
use Ordinal\Model\AllocationCaller;
use Ordinal\Service\AccountApplication;
use Ordinal\Service\AllocationException;
use Ordinal\Service\BuildNumberService;
use SensitiveParameter;

/** Lazily wires protected allocation after request validation, using one transaction connection. */
final class RuntimeAllocationAuthorizer extends AllocationAuthorizer
{
    /**
     * Accepts trusted transport context and optional request-scoped services.
     *
     * @param ?AccountApplication $application
     * @param bool $isSecure
     */
    public function __construct(
        /** Holds services only after a valid request needs application configuration. */
        private ?AccountApplication $application = null,
        /** Indicates trusted HTTPS rather than an untrusted forwarded header. */
        private readonly bool       $isSecure    = false,
    ) {}

    /**
     * Rejects insecure requests before exposing or using credentials.
     *
     * @param int $projectId
     * @param ?string $bearerToken
     * @return AllocationCaller
     */
    public function authorizeAllocation(int $projectId, #[SensitiveParameter] ?string $bearerToken): AllocationCaller
    {
        if (!$this->isSecure) {
            throw new AuthenticationException('Allocation requires HTTPS.');
        }
        try {
            $this->application ??= AccountApplication::createFromEnvironment();
        } catch (InvalidArgumentException) {
            throw new AllocationException(AllocationException::PROVIDER_UNAVAILABLE);
        }
        return $this->application->allocationAuthorizer->authorizeAllocation($projectId, $bearerToken);
    }

    /**
     * Supplies the same configured connection after authorization succeeds.
     *
     * @return BuildNumberService
     */
    public function getBuildNumberService(): BuildNumberService
    {
        return $this->application->buildNumbers ?? throw new AuthenticationException('Authorize the request first.');
    }
}
