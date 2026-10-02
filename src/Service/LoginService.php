<?php

declare(strict_types=1);

namespace Ordinal\Service;

use Ordinal\Database\Transaction;
use Ordinal\Model\BrowserSession;
use Ordinal\Repository\AuthorizationRepository;
use Ordinal\Repository\IdentityRepository;
use Ordinal\Repository\LoginRepository;
use Ordinal\Security\AuthenticationException;
use Ordinal\Security\TokenCipher;
use PDO;
use SensitiveParameter;

/** Coordinates browser-bound state/PKCE login and authenticated local identity/session creation. */
final readonly class LoginService
{
    /** Names the host-only cookie binding an OAuth attempt to its originating browser. */
    public const string COOKIE_NAME = '__Host-ordinal-login';

    /**
     * Composes login dependencies without exposing provider secrets to controllers.
     *
     * @param PDO $connection
     * @param ProviderRegistry $providers
     * @param LoginRepository $attempts
     * @param IdentityRepository $identities
     * @param AuthorizationRepository $authorizations
     * @param BrowserSessionService $sessions
     * @param AdministrationService $administration
     * @param TokenCipher $cipher
     */
    public function __construct(
        /** Owns atomic identity/credential/session persistence after verification. */
        private PDO                     $connection,
        /** Resolves configured allowed provider connections. */
        private ProviderRegistry        $providers,
        /** Stores and consumes expiring OAuth attempts. */
        private LoginRepository         $attempts,
        /** Preserves provider-qualified immutable identities. */
        private IdentityRepository      $identities,
        /** Saves encrypted tokens under the shared refresh mutex. */
        private AuthorizationRepository $authorizations,
        /** Creates or reauthenticates browser sessions. */
        private BrowserSessionService   $sessions,
        /** Grants bootstrap administration only to the configured identity. */
        private AdministrationService   $administration,
        /** Encrypts server-side PKCE verifiers. */
        private TokenCipher             $cipher,
    ) {}

    /**
     * Starts a new login or a CSRF-approved same-identity reauthentication attempt.
     *
     * @param int $connectionId
     * @param ?BrowserSession $session
     * @return array
     */
    public function beginLogin(int $connectionId, ?BrowserSession $session = null): array
    {
        $provider = $this->providers->createProvider($connectionId);
        if ($session !== null) {
            $session = $this->sessions->requireActiveSession($session);
            if ($session->providerConnectionId !== $connectionId) {
                throw new AuthenticationException('Reauthentication requires the same provider identity.');
            }
        }
        $state = bin2hex(random_bytes(BrowserSessionService::SECRET_BYTES));
        $browserSecret = bin2hex(random_bytes(BrowserSessionService::SECRET_BYTES));
        $verifier = rtrim(strtr(base64_encode(random_bytes(BrowserSessionService::SECRET_BYTES)), '+/', '-_'), '=');
        $stateHash = hash('sha256', $state);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $this->attempts->createAttempt($connectionId, $stateHash, hash('sha256', $browserSecret), $this->cipher->encrypt($verifier, 'oauth:' . $stateHash), $session?->id);
        return ['authorizationUrl' => $provider->createAuthorizationUrl($state, $challenge), 'browserSecret' => $browserSecret];
    }

    /**
     * Consumes matching state before exchange and persists only a verified provider identity.
     *
     * @param string $state
     * @param string $code
     * @param string $browserSecret
     * @param string $sessionSecret
     * @return string
     * @throws AuthenticationException
     */
    public function completeLogin(
        #[SensitiveParameter]
        string $state,
        #[SensitiveParameter]
        string $code,
        #[SensitiveParameter]
        string $browserSecret,
        #[SensitiveParameter]
        string $sessionSecret = '',
    ): string {
        if (preg_match('/^[a-f0-9]{64}$/D', $state) !== 1 || preg_match('/^[a-f0-9]{64}$/D', $browserSecret) !== 1 || trim($code) === '') {
            throw new AuthenticationException('Provider login state is invalid or expired.');
        }
        $stateHash = hash('sha256', $state);
        $attempt = $this->attempts->consumeAttempt($stateHash, hash('sha256', $browserSecret));
        if ($attempt === null) {
            throw new AuthenticationException('Provider login state is invalid or expired.');
        }
        $session = $attempt['browser_session_id'] === null ? null : $this->sessions->authenticate($sessionSecret);
        if ($session !== null && $session->id !== (int) $attempt['browser_session_id']) {
            throw new AuthenticationException('Reauthentication belongs to another browser session.');
        }
        $provider = $this->providers->createProvider((int) $attempt['provider_connection_id']);
        $authorization = $provider->exchangeAuthorizationCode($code, $this->cipher->decrypt(hex2bin($attempt['verifier']), 'oauth:' . $stateHash));
        $identity = $provider->findIdentity($authorization);
        if ($identity->providerConnectionId !== (int) $attempt['provider_connection_id']
            || ($session !== null && ($identity->providerUserId !== $session->providerUserId || $identity->providerConnectionId !== $session->providerConnectionId))) {
            throw new AuthenticationException('Provider login belongs to another identity.');
        }
        $secret = '';
        (new Transaction($this->connection))->execute(
            /**
             * Commits verified identity, encrypted authorization, and session together.
             *
             * @param PDO $connection
             * @return void
             */
            function (PDO $connection) use ($identity, $authorization, $session, &$secret): void {
                $this->administration->lockSettings();
                $userId = $this->identities->saveIdentity($identity);
                $this->authorizations->saveAuthorization($userId, $authorization);
                $this->administration->ensureBootstrapAdministrator($userId);
                $secret = $session === null ? $this->sessions->createSession($userId) : $this->sessions->reauthenticateSession($session);
                $this->administration->recordSignIn($userId);
            },
        );
        return $secret;
    }

    /**
     * Builds a short-lived secure cookie for the browser binding.
     *
     * @param string $browserSecret
     * @return string
     */
    public static function createCookie(#[SensitiveParameter] string $browserSecret): string
    {
        return self::COOKIE_NAME . '=' . $browserSecret . '; Path=/; Max-Age=' . LoginRepository::LIFETIME_SECONDS . '; Secure; HttpOnly; SameSite=Lax';
    }

    /**
     * Consumes browser-bound state when the provider declines authorization.
     *
     * @param string $state
     * @param string $browserSecret
     * @return void
     */
    public function cancelLogin(#[SensitiveParameter] string $state, #[SensitiveParameter] string $browserSecret): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $state) === 1 && preg_match('/^[a-f0-9]{64}$/D', $browserSecret) === 1) {
            $this->attempts->consumeAttempt(hash('sha256', $state), hash('sha256', $browserSecret));
        }
    }
}
