<?php

declare(strict_types=1);

namespace Ordinal\Controller;

use InvalidArgumentException;
use Ordinal\Http\Response;
use Ordinal\Model\AdministratorSession;
use Ordinal\Security\AuthenticationException;
use Ordinal\Service\AccountException;
use Ordinal\Service\AdministratorService;
use Ordinal\Service\ManagementApplication;
use Ordinal\View\TemplateRenderer;
use SensitiveParameter;

/** Handles the small password-protected project/token interface. */
final class ManagementController
{
    /**
     * Opens configured services only for management requests.
     *
     * @param ?ManagementApplication $application
     */
    public function __construct(
        /** Supplies optional isolated services for route tests. */
        private ?ManagementApplication $application = null,
    ) {}

    /**
     * Dispatches fixed actions after HTTPS, browser session and CSRF checks.
     *
     * @param string $action
     * @param array $query
     * @param array $fields
     * @param array $cookies
     * @param bool $isSecure
     * @param string $clientAddress
     * @return Response
     */
    public function handle(
        string $action,
        array  $query,
        #[SensitiveParameter]
        array  $fields,
        #[SensitiveParameter]
        array  $cookies,
        bool   $isSecure,
        string $clientAddress = 'local',
    ): Response {
        if (!$isSecure) { return $this->createError('Management requires HTTPS.', 400); }
        try {
            $this->application ??= ManagementApplication::createFromEnvironment();
            $cookie = $cookies[AdministratorService::COOKIE_NAME] ?? '';
            $cookie = is_string($cookie) ? $cookie : '';
            if ($action === 'showLogin') { return $this->showLogin($cookie); }
            if ($action === 'signIn') {
                $secret = $this->application->administrator->signIn($cookie, $this->getString($fields, 'password'), $this->getString($fields, 'csrfToken'), $clientAddress);
                return $this->redirect('/projects', ['Set-Cookie' => AdministratorService::createCookie($secret)]);
            }
            $session = $this->application->administrator->authenticateSession($cookie);
            if (!str_starts_with($action, 'show') && $action !== 'previewNext') { $this->application->administrator->requireCsrf($session, $fields['csrfToken'] ?? null); }
            $projectId = isset($query['projectId']) ? $this->getPositiveInteger($query, 'projectId') : null;
            return match ($action) {
                'signOut' => $this->signOut($session),
                'showProjects' => $this->renderPage('projects', $session, ['projects' => $this->application->projects->findProjects()]),
                'createProject' => $this->redirect('/projects/' . $this->application->projects->createProject($this->getString($fields, 'name'), $this->getOptionalString($fields, 'repositoryUrl'))),
                'showProject' => $this->renderPage('project', $session, ['project' => $this->application->projects->getProject($projectId), 'tokens' => $this->application->projects->getProject($projectId)['archived_at'] === null ? $this->application->tokens->findProjectTokens($projectId) : []]),
                'saveProject' => $this->saveProject($projectId, $fields),
                'manageTokens' => $this->manageTokens($projectId, $session, $fields),
                'showCounter' => $this->renderPage('counter', $session, ['project' => $this->application->projects->getProject($projectId)]),
                'saveCounter' => $this->saveCounter($projectId, $fields),
                'previewNext' => $this->previewNext($projectId),
                'showHistory' => $this->showEvents($session, $projectId, $query),
                'showLogs' => $this->showEvents($session, null, $query),
                'saveArchiveState' => $this->saveArchiveState($projectId, $fields),
                default => $this->createError('Page not found.', 404),
            };
        } catch (AuthenticationException) {
            return $action === 'previewNext' ? Response::createJson(['error' => ['code' => 'INVALID_AUTHENTICATION', 'message' => 'Sign in to continue.']], 401)
                : ($action === 'signIn' ? $this->createError('The password is incorrect or the session expired. Sign in again.', 401) : $this->redirect('/login'));
        } catch (AccountException $exception) {
            return $action === 'previewNext' ? Response::createJson(['error' => ['code' => 'PROJECT_NOT_FOUND', 'message' => $exception->getMessage()]], $exception->statusCode)
                : $this->createError($exception->getMessage(), $exception->statusCode);
        } catch (InvalidArgumentException) {
            return $this->createError('Set the administrator password before using management.', 503);
        }
    }

    /**
     * Issues an anonymous CSRF-bound login form or redirects an authenticated browser.
     *
     * @param string $cookie
     * @return Response
     */
    private function showLogin(#[SensitiveParameter] string $cookie): Response
    {
        $headers = [];
        try { $session = $this->application->administrator->authenticateSession($cookie, false); }
        catch (AuthenticationException) {
            $initial = $this->application->administrator->startSession();
            $session = $initial['session'];
            $headers['Set-Cookie'] = AdministratorService::createCookie($initial['cookie']);
        }
        if ($session->isAuthenticated) { return $this->redirect('/projects'); }
        return $this->renderPage('login', $session, [], $headers);
    }

    /**
     * Revokes the authenticated browser session and clears its cookie.
     *
     * @param AdministratorSession $session
     * @return Response
     */
    private function signOut(AdministratorSession $session): Response
    {
        $this->application->administrator->signOut($session);
        return $this->redirect('/login', ['Set-Cookie' => AdministratorService::COOKIE_NAME . '=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Lax']);
    }

    /**
     * Updates descriptive project fields without provider discovery.
     *
     * @param int $id
     * @param array $fields
     * @return Response
     */
    private function saveProject(int $id, array $fields): Response
    {
        $this->application->projects->saveProject($id, $this->getString($fields, 'name'), $this->getOptionalString($fields, 'repositoryUrl'));
        return $this->redirect('/projects/' . $id);
    }

    /**
     * Dispatches named token actions and returns a secret only for issuance/rotation.
     *
     * @param int $id
     * @param AdministratorSession $session
     * @param array $fields
     * @return Response
     */
    private function manageTokens(int $id, AdministratorSession $session, array $fields): Response
    {
        $action = $this->getString($fields, 'action');
        $tokenId = $action === 'create' ? null : $this->getPositiveInteger($fields, 'tokenId');
        if (in_array($action, ['create', 'rotate'], true)) {
            $days = $this->getOptionalString($fields, 'lifetimeDays');
            $lifetime = $days === '' ? null : $this->parseNumber($days, 1, 36500);
            $receipt = $action === 'create' ? $this->application->tokens->createToken($id, $this->getString($fields, 'name'), $lifetime)
                : $this->application->tokens->rotateToken($id, $tokenId, $lifetime);
            return $this->renderPage('token-secret', $session, $receipt);
        }
        if ($action === 'rename') { $this->application->tokens->renameToken($id, $tokenId, $this->getString($fields, 'name')); }
        elseif ($action === 'revoke') { $this->application->tokens->revokeToken($id, $tokenId); }
        else { throw new AccountException('Choose a valid token action.', 400); }
        return $this->redirect('/projects/' . $id);
    }

    /**
     * Parses a counter action and protects reset with explicit name/reuse confirmation.
     *
     * @param int $id
     * @param array $fields
     * @return Response
     */
    private function saveCounter(int $id, array $fields): Response
    {
        $number = $this->parseNumber($this->getString($fields, 'nextBuildNumber'), 0, 4294967295);
        if (($fields['action'] ?? '') === 'reset') {
            $this->application->projects->resetCounter($id, $number, $this->getString($fields, 'projectName'), ($fields['confirmReuse'] ?? '') === '1');
        } elseif (($fields['action'] ?? '') === 'save') { $this->application->projects->saveCounter($id, $number); }
        else { throw new AccountException('Choose a valid counter action.', 400); }
        return $this->redirect('/projects/' . $id . '/counter');
    }

    /**
     * Returns a non-consuming administrator counter preview.
     *
     * @param int $id
     * @return Response
     */
    private function previewNext(int $id): Response
    {
        $project = $this->application->projects->getProject($id);
        if ($project['archived_at'] !== null) { throw new AccountException('Active project not found.', 404); }
        return Response::createJson(['projectId' => $id, 'nextBuildNumber' => $project['next_build_number'], 'isExhausted' => $project['is_exhausted']], headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * Reads bounded secret-free build history or instance events.
     *
     * @param AdministratorSession $session
     * @param ?int $id
     * @param array $query
     * @return Response
     */
    private function showEvents(AdministratorSession $session, ?int $id, array $query): Response
    {
        if ($id !== null) { $this->application->projects->getProject($id); }
        $before = isset($query['beforeId']) ? $this->getPositiveInteger($query, 'beforeId') : null;
        return $this->renderPage('events', $session, ['projectId' => $id, 'events' => $this->application->audit->findEvents($id, null, $id !== null, $before)]);
    }

    /**
     * Archives/restores without deleting historical records.
     *
     * @param int $id
     * @param array $fields
     * @return Response
     */
    private function saveArchiveState(int $id, array $fields): Response
    {
        $value = $this->getString($fields, 'isArchived');
        if (!in_array($value, ['0', '1'], true)) { throw new AccountException('Choose an archive action.', 400); }
        $this->application->projects->saveArchiveState($id, $value === '1');
        return $this->redirect('/projects/' . $id);
    }

    /**
     * Renders fixed templates and disables caching of all password-protected pages.
     *
     * @param string $page
     * @param AdministratorSession $session
     * @param array $data
     * @param array $headers
     * @return Response
     */
    private function renderPage(string $page, AdministratorSession $session, array $data = [], array $headers = []): Response
    {
        return Response::createHtml((new TemplateRenderer())->renderAccountPage($page, $data + ['session' => $session]), headers: $headers + ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    /**
     * Escapes readable errors and prevents sensitive page caching.
     *
     * @param string $message
     * @param int $status
     * @return Response
     */
    private function createError(string $message, int $status): Response
    {
        return Response::createHtml((new TemplateRenderer())->renderError($message), $status, ['Cache-Control' => 'no-store']);
    }

    /**
     * Redirects only to controlled local routes.
     *
     * @param string $location
     * @param array $headers
     * @return Response
     */
    private function redirect(string $location, array $headers = []): Response
    {
        return Response::createHtml('', 303, ['Location' => $location, 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'] + $headers);
    }

    /**
     * Requires a scalar field and rejects array-shaped input.
     *
     * @param array $values
     * @param string $name
     * @return string
     */
    private function getString(array $values, string $name): string
    {
        if (!is_string($values[$name] ?? null)) { throw new AccountException('The request is invalid.', 400); }
        return $values[$name];
    }

    /**
     * Accepts an omitted optional field but rejects non-string values.
     *
     * @param array $values
     * @param string $name
     * @return string
     */
    private function getOptionalString(array $values, string $name): string
    {
        return isset($values[$name]) ? $this->getString($values, $name) : '';
    }

    /**
     * Parses a positive internal project/token ID from a controlled route or form.
     *
     * @param array $values
     * @param string $name
     * @return int
     */
    private function getPositiveInteger(array $values, string $name): int
    {
        return $this->parseNumber($this->getString($values, $name), 1, PHP_INT_MAX);
    }

    /**
     * Requires canonical decimal input without signs, leading zeroes or overflow.
     *
     * @param string $value
     * @param int $minimum
     * @param int $maximum
     * @return int
     */
    private function parseNumber(string $value, int $minimum, int $maximum): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]);
        if (preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1 || $number === false) { throw new AccountException('Choose a valid whole number.', 400); }
        return $number;
    }
}
