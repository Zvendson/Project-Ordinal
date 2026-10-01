<?php

declare(strict_types=1);

namespace Ordinal\Controller;

use Ordinal\Http\Response;

/** Provides the initial home response for Project: Ordinal. */
final class HomeController
{
    /**
     * Displays the product name before browser templates are introduced.
     *
     * @return Response
     */
    public function showHome(): Response
    {
        return new Response(Response::STATUS_OK, 'Project: Ordinal');
    }
}
