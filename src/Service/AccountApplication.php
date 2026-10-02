<?php

declare(strict_types=1);

namespace Ordinal\Service;

use Ordinal\Configuration\ConfigurationLoader;
use Ordinal\Configuration\SecurityConfigurationLoader;
use Ordinal\Database\ConnectionFactory;
use Ordinal\Http\CurlHttpClient;
use Ordinal\Http\HttpClient;
use Ordinal\Model\SecurityConfiguration;
use Ordinal\Repository\AdministrationRepository;
use Ordinal\Repository\AuthorizationRepository;
use Ordinal\Repository\BrowserSessionRepository;
use Ordinal\Repository\DeviceRepository;
use Ordinal\Repository\IdentityRepository;
use Ordinal\Repository\LoginRepository;
use Ordinal\Repository\ProjectRepository;
use Ordinal\Repository\ProviderConnectionRepository;
use Ordinal\Security\TokenCipher;
use Ordinal\Security\DeviceAllocationAuthorizer;
use PDO;
use SensitiveParameter;

/** Composes account and device services on one request-scoped database connection. */
final readonly class AccountApplication
{
    /** Resolves allowed concrete provider integrations. */
    public ProviderRegistry           $providers;
    /** Coordinates encrypted reusable credentials. */
    public AuthorizationService       $authorizations;
    /** Manages opaque server-side browser sessions. */
    public BrowserSessionService      $sessions;
    /** Coordinates state/PKCE callbacks. */
    public LoginService               $login;
    /** Protects instance grants and connection/session configuration. */
    public AdministrationService      $administration;
    /** Links immutable repositories and checks current project roles. */
    public ProjectService             $projects;
    /** Manages provider-approved project credentials and revocation. */
    public DeviceService              $devices;
    /** Verifies device credentials and live repository write permission. */
    public DeviceAllocationAuthorizer $allocationAuthorizer;
    /** Allocates and consumes single-use credentials atomically. */
    public BuildNumberService         $buildNumbers;

    /**
     * Wires domain repositories and services using one request-scoped database connection.
     *
     * @param PDO $connection
     * @param SecurityConfiguration $configuration
     * @param HttpClient $client
     */
    public function __construct(
        PDO                   $connection,
        #[SensitiveParameter]
        SecurityConfiguration $configuration,
        HttpClient            $client = new CurlHttpClient(),
    ) {
        $cipher = new TokenCipher($configuration->encryptionKey);
        $connections = new ProviderConnectionRepository($connection);
        $registration = $configuration->connections[$configuration->bootstrapConnection];
        $connections->initializeBootstrapConnection($configuration->bootstrapConnection, $registration['kind'], $registration['serverUrl']);
        $this->providers = new ProviderRegistry($connections, $configuration, $client);
        $identities = new IdentityRepository($connection);
        $authorizationRepository = new AuthorizationRepository($connection, $cipher);
        $administrationRepository = new AdministrationRepository($connection);
        $this->sessions = new BrowserSessionService(new BrowserSessionRepository($connection));
        $this->administration = new AdministrationService($connection, $administrationRepository, $identities, $connections, $this->sessions, $configuration);
        $this->authorizations = new AuthorizationService($connection, $authorizationRepository, $this->providers);
        $this->login = new LoginService($connection, $this->providers, new LoginRepository($connection), $identities, $authorizationRepository, $this->sessions, $this->administration, $cipher);
        $this->projects = new ProjectService(new ProjectRepository($connection), $this->providers, $this->authorizations, $this->sessions, $this->administration, $administrationRepository);
        $deviceRepository = new DeviceRepository($connection);
        $access = new RepositoryAccessService($identities, $this->providers, $this->authorizations);
        $this->devices = new DeviceService($connection, $deviceRepository, $access, $this->sessions, $this->administration, $administrationRepository, $this->projects);
        $this->allocationAuthorizer = new DeviceAllocationAuthorizer($deviceRepository, $access);
        $this->buildNumbers = new BuildNumberService($connection);
    }

    /**
     * Loads operator secrets and database configuration only when an account route needs them.
     *
     * @return self
     */
    public static function createFromEnvironment(): self
    {
        return new self(ConnectionFactory::createConnection(ConfigurationLoader::loadFromEnvironment()), SecurityConfigurationLoader::loadFromEnvironment());
    }
}
