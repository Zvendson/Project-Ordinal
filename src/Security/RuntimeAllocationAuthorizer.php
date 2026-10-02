<?php

declare(strict_types=1);

namespace Ordinal\Security;

use InvalidArgumentException;
use Ordinal\Model\AllocationCaller;
use Ordinal\Service\ManagementApplication;
use Ordinal\Service\AllocationException;
use Ordinal\Service\BuildNumberService;
use SensitiveParameter;

/** Lazily wires protected allocation after request validation, using one transaction connection. */
final class RuntimeAllocationAuthorizer extends AllocationAuthorizer
{
    /** Prevents earlier request context in injected services from attributing an unchecked request. */
    private bool $hasCheckedCaller = false;

    /**
     * Returns only this request's locally verified identity.
     *
     * @return ?AllocationCaller
     */
    public function getVerifiedCaller(): ?AllocationCaller
    {
        return $this->hasCheckedCaller ? $this->application?->allocationAuthorizer->getVerifiedCaller() : null;
    }

    /**
     * Opens audit storage even for input/transport rejection, without examining rejected credentials.
     *
     * @return \Ordinal\Repository\AuditRepository
     */
    public function getAuditRepository(): \Ordinal\Repository\AuditRepository
    {
        $this->application ??= ManagementApplication::createFromEnvironment();
        return $this->application->audit;
    }
    /**
     * Accepts trusted transport context and optional request-scoped services.
     *
     * @param ?ManagementApplication $application
     * @param bool $isSecure
     */
    public function __construct(
        /** Holds services only after a valid request needs application configuration. */
        private ?ManagementApplication $application = null,
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
    public function authorizeAllocation(
        int     $projectId,
        #[SensitiveParameter]
        ?string $bearerToken,
    ): AllocationCaller
    {
        $this->hasCheckedCaller = false;
        if (!$this->isSecure) {
            throw new AuthenticationException('Allocation requires HTTPS.');
        }
        try {
            $this->application ??= ManagementApplication::createFromEnvironment();
        } catch (InvalidArgumentException) {
            throw new AllocationException('INTERNAL_ERROR');
        }
        $this->hasCheckedCaller = true;
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
