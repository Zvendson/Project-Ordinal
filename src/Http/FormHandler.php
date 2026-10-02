<?php

declare(strict_types=1);

namespace Ordinal\Http;

use Closure;
use Ordinal\Security\CsrfProtection;
use Ordinal\View\TemplateRenderer;

/** Guards browser form operations before their field validation or business logic runs. */
final class FormHandler
{
    /**
     * Runs a form operation only when its submitted token belongs to the browser session.
     *
     * @param array $session
     * @param array $fields
     * @param Closure $operation
     * @return Response
     * @throws \Throwable
     */
    public function handle(array $session, array $fields, Closure $operation): Response
    {
        if (!(new CsrfProtection())->isTokenValid($session, $fields['csrfToken'] ?? null)) {
            return Response::createHtml(
                (new TemplateRenderer())->renderError('The form expired or is invalid. Reload the page and try again.'),
                Response::STATUS_FORBIDDEN,
            );
        }

        return $operation($fields);
    }
}
