<?php

declare(strict_types=1);

namespace Ordinal\Controller;

use Ordinal\Http\Response;
use Ordinal\Security\AuthenticationException;
use Ordinal\Service\AdministratorService;
use Ordinal\Service\ManagementApplication;
use Ordinal\View\TemplateRenderer;
use SensitiveParameter;

/** Provides the initial home response for Project: Ordinal. */
final class HomeController
{
    /**
     * Supplies optional request-scoped services for session lookup.
     *
     * @param ?ManagementApplication $application
     */
    public function __construct(
        /** Reuses the configured management services when a browser session exists. */
        private ?ManagementApplication $application = null,
    ) {}

    /**
     * Introduces independent projects and named build tokens.
     *
     * @param array $cookies
     * @param bool $isSecure
     * @return Response
     */
    public function showHome(
        #[SensitiveParameter]
        array $cookies  = [],
        bool  $isSecure = false,
    ): Response
    {
        $session = null;
        $cookie = $cookies[AdministratorService::COOKIE_NAME] ?? null;
        if ($isSecure && is_string($cookie) && preg_match('/^[a-f0-9]{64}$/D', $cookie) === 1) {
            $this->application ??= ManagementApplication::createFromEnvironment();
            try {
                $session = $this->application->administrator->authenticateSession($cookie);
            } catch (AuthenticationException) {
                $session = null;
            }
        }
        return Response::createHtml((new TemplateRenderer())->renderHome($session), headers: ['Cache-Control' => 'no-store']);
    }
}
