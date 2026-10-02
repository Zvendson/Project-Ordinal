<?php

declare(strict_types=1);

namespace Ordinal\Controller;

use InvalidArgumentException;
use Ordinal\Http\Response;
use Ordinal\Model\BrowserSession;
use Ordinal\Provider\ProviderAuthenticationException;
use Ordinal\Provider\ProviderUnavailableException;
use Ordinal\Security\AuthenticationException;
use Ordinal\Service\AccountApplication;
use Ordinal\Service\AccountException;
use Ordinal\Service\BrowserSessionService;
use Ordinal\Service\LoginService;
use Ordinal\View\TemplateRenderer;
use SensitiveParameter;

/** Delegates unstyled account, administration, and repository-link forms to protected services. */
final class AccountController
{
    /** Stores lazy request-scoped services so account routes do not configure the public homepage. */
    private ?AccountApplication $application;

    /**
     * Accepts injectable services for browser integration tests.
     *
     * @param ?AccountApplication $application
     */
    public function __construct(?AccountApplication $application = null)
    {
        $this->application = $application;
    }

    /**
     * Dispatches only fixed endpoint actions and maps failures without upstream diagnostics.
     *
     * @param string $action
     * @param array $query
     * @param array $fields
     * @param array $cookies
     * @param bool $isSecure
     * @return Response
     */
    public function handle(
        string $action,
        #[SensitiveParameter]
        array  $query,
        #[SensitiveParameter]
        array  $fields,
        #[SensitiveParameter]
        array  $cookies,
        bool   $isSecure,
    ): Response {
        if (!$isSecure) {
            return $this->createError('Account pages require HTTPS.', 400);
        }
        try {
            $this->application ??= AccountApplication::createFromEnvironment();
            return match ($action) {
                'showLogin' => $this->showLogin(),
                'startLogin' => $this->startLogin($query),
                'completeLogin' => $this->completeLogin($query, $cookies),
                'showAccount' => $this->showAccount($cookies),
                'reauthenticate' => $this->reauthenticate($fields, $cookies),
                'logout' => $this->logout($fields, $cookies),
                'showAdministration' => $this->showAdministration($cookies),
                'saveAdministration' => $this->saveAdministration($fields, $cookies),
                'showProjects' => $this->showProjects($cookies),
                'showProject' => $this->showProject($query, $cookies),
                'createProject' => $this->createProject($fields, $cookies),
                default => $this->createError('Page not found.', 404),
            };
        } catch (AuthenticationException | ProviderAuthenticationException) {
            return $this->createError('Sign in again to continue.', 401);
        } catch (ProviderUnavailableException) {
            return $this->createError('Provider verification is temporarily unavailable. Try again later.', 503);
        } catch (AccountException $exception) {
            return $this->createError($exception->getMessage(), $exception->statusCode);
        } catch (InvalidArgumentException) {
            return $this->createError('Account configuration is unavailable. Contact the server operator.', 503);
        }
    }

    /**
     * Displays allowed provider choices without revealing registration credentials.
     *
     * @return Response
     */
    private function showLogin(): Response
    {
        return $this->renderPage('login', ['connections' => $this->application->providers->findAllowedConnections()]);
    }

    /**
     * Starts a new browser-bound provider login for an allowed connection.
     *
     * @param array $query
     * @return Response
     */
    private function startLogin(array $query): Response
    {
        return $this->createLoginRedirect($this->application->login->beginLogin($this->getPositiveInteger($query, 'connectionId')));
    }

    /**
     * Consumes state and sets only a fresh opaque session cookie on a successful callback.
     *
     * @param array $query
     * @param array $cookies
     * @return Response
     */
    private function completeLogin(#[SensitiveParameter] array $query, #[SensitiveParameter] array $cookies): Response
    {
        if (isset($query['error'])) {
            $this->application->login->cancelLogin(is_string($query['state'] ?? null) ? $query['state'] : '', $this->getCookie($cookies, LoginService::COOKIE_NAME));
            throw new AuthenticationException('Provider sign-in was not completed.');
        }
        $secret = $this->application->login->completeLogin($this->getString($query, 'state'), $this->getString($query, 'code'), $this->getCookie($cookies, LoginService::COOKIE_NAME), $this->getCookie($cookies, BrowserSessionService::COOKIE_NAME));
        return $this->redirect('/account', ['Set-Cookie' => [BrowserSessionService::createCookie($secret), LoginService::COOKIE_NAME . '=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Lax']]);
    }

    /**
     * Displays the verified provider identity and session-bound sign-out/reauthentication forms.
     *
     * @param array $cookies
     * @return Response
     */
    private function showAccount(#[SensitiveParameter] array $cookies): Response
    {
        $session = $this->getSession($cookies);
        return $this->renderPage('account', ['session' => $session, 'isAdministrator' => $this->application->administration->isInstanceAdministrator($session->userId)]);
    }

    /**
     * Starts same-identity provider reauthentication only after an authenticated CSRF check.
     *
     * @param array $fields
     * @param array $cookies
     * @return Response
     */
    private function reauthenticate(#[SensitiveParameter] array $fields, #[SensitiveParameter] array $cookies): Response
    {
        $session = $this->getFormSession($fields, $cookies);
        return $this->createLoginRedirect($this->application->login->beginLogin($session->providerConnectionId, $session));
    }

    /**
     * Revokes the authenticated session and expires its host-only cookie.
     *
     * @param array $fields
     * @param array $cookies
     * @return Response
     */
    private function logout(#[SensitiveParameter] array $fields, #[SensitiveParameter] array $cookies): Response
    {
        $this->application->sessions->revokeSession($this->getFormSession($fields, $cookies));
        return $this->redirect('/login', ['Set-Cookie' => BrowserSessionService::createExpiredCookie()]);
    }

    /**
     * Shows only instance-administrator policy, allowed registrations, and identity records.
     *
     * @param array $cookies
     * @return Response
     */
    private function showAdministration(#[SensitiveParameter] array $cookies): Response
    {
        $session = $this->getSession($cookies);
        return $this->renderPage('administration', $this->application->administration->getAdministrationData($session) + ['session' => $session]);
    }

    /**
     * Delegates validated CSRF-protected instance mutations without accepting submitted provider secrets.
     *
     * @param array $fields
     * @param array $cookies
     * @return Response
     */
    private function saveAdministration(#[SensitiveParameter] array $fields, #[SensitiveParameter] array $cookies): Response
    {
        $session = $this->getFormSession($fields, $cookies);
        $this->application->administration->requireInstanceAdministrator($session);
        switch ($this->getString($fields, 'action')) {
            case 'connection':
                $this->application->administration->saveConnection($session, $this->getString($fields, 'registration'), $this->getString($fields, 'name'), $this->getBoolean($fields, 'isEnabled'));
                break;
            case 'administrator':
                $this->application->administration->setAdministrator($session, $this->getPositiveInteger($fields, 'userId'), $this->getBoolean($fields, 'isAdministrator'));
                break;
            case 'sessionLimits':
                $this->application->administration->saveSessionLimits($session, $this->getPositiveInteger($fields, 'idleMinutes'), $this->getPositiveInteger($fields, 'absoluteMinutes'));
                break;
            default:
                throw new AccountException('The request is invalid.', 400);
        }
        return $this->redirect('/administration');
    }

    /**
     * Lists accessible projects and displays repository linking only to instance administrators.
     *
     * @param array $cookies
     * @return Response
     */
    private function showProjects(#[SensitiveParameter] array $cookies): Response
    {
        $session = $this->getSession($cookies);
        return $this->renderPage('projects', ['session' => $session, 'projects' => $this->application->projects->findVisibleProjects($session), 'isAdministrator' => $this->application->administration->isInstanceAdministrator($session->userId)]);
    }

    /**
     * Shows one project after current provider permissions are established.
     *
     * @param array $query
     * @param array $cookies
     * @return Response
     */
    private function showProject(array $query, #[SensitiveParameter] array $cookies): Response
    {
        $session = $this->getSession($cookies);
        return $this->renderPage('project', $this->application->projects->getProject($session, $this->getPositiveInteger($query, 'projectId')) + ['session' => $session]);
    }

    /**
     * Links a verified immutable repository through a protected instance-admin form.
     *
     * @param array $fields
     * @param array $cookies
     * @return Response
     */
    private function createProject(#[SensitiveParameter] array $fields, #[SensitiveParameter] array $cookies): Response
    {
        $session = $this->getFormSession($fields, $cookies);
        $id = $this->application->projects->createProject($session, $this->getPositiveInteger($fields, 'connectionId'), $this->getString($fields, 'repositoryId'), $this->getString($fields, 'name'));
        return $this->redirect('/projects/' . $id);
    }

    /**
     * Authenticates a browser and verifies CSRF before any form fields can cause mutations.
     *
     * @param array $fields
     * @param array $cookies
     * @return BrowserSession
     */
    private function getFormSession(#[SensitiveParameter] array $fields, #[SensitiveParameter] array $cookies): BrowserSession
    {
        $session = $this->getSession($cookies);
        $this->application->sessions->requireCsrfToken($session, $fields['csrfToken'] ?? null);
        return $session;
    }

    /**
     * Looks up the current server-side session by its opaque cookie.
     *
     * @param array $cookies
     * @return BrowserSession
     */
    private function getSession(#[SensitiveParameter] array $cookies): BrowserSession
    {
        return $this->application->sessions->authenticate($this->getCookie($cookies, BrowserSessionService::COOKIE_NAME));
    }

    /**
     * Rejects array cookie values rather than coercing them into credentials.
     *
     * @param array $cookies
     * @param string $name
     * @return string
     */
    private function getCookie(#[SensitiveParameter] array $cookies, string $name): string
    {
        return is_string($cookies[$name] ?? null) ? $cookies[$name] : '';
    }

    /**
     * Requires one nonempty scalar text value for browser input.
     *
     * @param array $fields
     * @param string $name
     * @return string
     */
    private function getString(#[SensitiveParameter] array $fields, string $name): string
    {
        if (!is_string($fields[$name] ?? null) || trim($fields[$name]) === '') {
            throw new AccountException('The request is invalid.', 400);
        }
        return $fields[$name];
    }

    /**
     * Validates canonical positive integer input without rounding or overflow.
     *
     * @param array $fields
     * @param string $name
     * @return int
     */
    private function getPositiveInteger(array $fields, string $name): int
    {
        $value = $this->getString($fields, $name);
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1 || strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
            throw new AccountException('The request is invalid.', 400);
        }
        return (int) $value;
    }

    /**
     * Accepts only explicit form boolean values.
     *
     * @param array $fields
     * @param string $name
     * @return bool
     */
    private function getBoolean(array $fields, string $name): bool
    {
        $value = $this->getString($fields, $name);
        if (!in_array($value, ['0', '1'], true)) {
            throw new AccountException('The request is invalid.', 400);
        }
        return $value === '1';
    }

    /**
     * Redirects to a fixed local target or the provider-produced authorization URL.
     *
     * @param string $location
     * @param array $headers
     * @return Response
     */
    private function redirect(string $location, array $headers = []): Response
    {
        return Response::createHtml('', 303, ['Location' => $location, 'Referrer-Policy' => 'no-referrer'] + $headers);
    }

    /**
     * Sets only the short-lived browser binding on a provider redirect.
     *
     * @param array $attempt
     * @return Response
     */
    private function createLoginRedirect(#[SensitiveParameter] array $attempt): Response
    {
        return $this->redirect($attempt['authorizationUrl'], ['Set-Cookie' => LoginService::createCookie($attempt['browserSecret'])]);
    }

    /**
     * Renders one fixed private account template with escaped dynamic values.
     *
     * @param string $page
     * @param array $data
     * @return Response
     */
    private function renderPage(string $page, array $data): Response
    {
        return Response::createHtml((new TemplateRenderer())->renderAccountPage($page, $data), headers: ['Referrer-Policy' => 'no-referrer']);
    }

    /**
     * Returns a fixed public error message in escaped uncached HTML.
     *
     * @param string $message
     * @param int $status
     * @return Response
     */
    private function createError(string $message, int $status): Response
    {
        return Response::createHtml((new TemplateRenderer())->renderError($message), $status, ['Referrer-Policy' => 'no-referrer']);
    }
}
