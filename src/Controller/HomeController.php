<?php

declare(strict_types=1);

namespace Ordinal\Controller;

use Ordinal\Http\Response;
use Ordinal\View\TemplateRenderer;

/** Provides the initial home response for Project: Ordinal. */
final class HomeController
{
    /**
     * Displays the product name using the unstyled home template.
     *
     * @return Response
     */
    public function showHome(): Response
    {
        return Response::createHtml((new TemplateRenderer())->renderHome());
    }
}
