<?php

declare(strict_types=1);

namespace Ordinal\Service;

use Ordinal\Configuration\AdministratorConfigurationLoader;
use Ordinal\Configuration\ConfigurationLoader;
use Ordinal\Database\ConnectionFactory;
use Ordinal\Repository\AuditRepository;
use Ordinal\Repository\TokenRepository;
use Ordinal\Repository\ProjectRepository;
use Ordinal\Security\ProjectAllocationAuthorizer;
use PDO;
use SensitiveParameter;

/** Composes local project/token management without any external provider registration. */
final readonly class ManagementApplication
{
    /** Protects the single administrator's browser session. */
    public AdministratorService        $administrator;
    /** Manages independent projects and their counters. */
    public ProjectService              $projects;
    /** Generates multiple named project tokens. */
    public ProjectTokenService         $tokens;
    /** Verifies project tokens locally. */
    public ProjectAllocationAuthorizer $allocationAuthorizer;
    /** Allocates and replays build numbers atomically. */
    public BuildNumberService          $buildNumbers;
    /** Stores secret-free events and history. */
    public AuditRepository             $audit;

    /**
     * Wires local services on one transaction connection.
     *
     * @param PDO $connection
     * @param string $passwordHash
     */
    public function __construct(PDO $connection, #[SensitiveParameter] string $passwordHash)
    {
        $repository = new ProjectRepository($connection);
        $tokens = new TokenRepository($connection);
        $this->audit = new AuditRepository($connection);
        $this->administrator = new AdministratorService($connection, $passwordHash);
        $this->projects = new ProjectService($connection, $repository, $this->audit);
        $this->tokens = new ProjectTokenService($connection, $tokens, $repository, $this->audit);
        $this->allocationAuthorizer = new ProjectAllocationAuthorizer($repository, $tokens);
        $this->buildNumbers = new BuildNumberService($connection);
    }

    /**
     * Loads only database configuration and the one administrator password hash.
     *
     * @return self
     */
    public static function createFromEnvironment(): self
    {
        return new self(ConnectionFactory::createConnection(ConfigurationLoader::loadFromEnvironment()), AdministratorConfigurationLoader::loadPasswordHash());
    }
}
