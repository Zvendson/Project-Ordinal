<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Http;

use Ordinal\Http\FormHandler;
use Ordinal\Http\Response;
use Ordinal\Security\CsrfProtection;
use PHPUnit\Framework\TestCase;

/** Verifies that form operations run only after CSRF validation. */
final class FormHandlerTest extends TestCase
{
    /**
     * Stops an operation when a form has no valid token.
     *
     * @return void
     */
    public function testRejectsFormBeforeRunningOperation(): void
    {
        $wasCalled = false;
        $response = (new FormHandler())->handle([], [],
            /**
             * Records whether the rejected operation ran.
             *
             * @param array $fields
             * @return Response
             */
            function (array $fields) use (&$wasCalled): Response {
                $wasCalled = true;
                return new Response(200, 'Unexpected.');
            },
        );
        self::assertFalse($wasCalled);
        self::assertSame(403, $response->statusCode);
        self::assertSame('text/html; charset=UTF-8', $response->headers['Content-Type']);
    }

    /**
     * Passes form fields to the operation after a valid token is supplied.
     *
     * @return void
     */
    public function testHandlesValidForm(): void
    {
        $session = [];
        $token   = (new CsrfProtection())->issueToken($session);
        $response = (new FormHandler())->handle($session, ['csrfToken' => $token, 'name' => 'Ordinal'],
            /**
             * Returns the validated form value.
             *
             * @param array $fields
             * @return Response
             */
            function (array $fields): Response {
                return new Response(200, $fields['name']);
            },
        );
        self::assertSame('Ordinal', $response->body);
    }

    /**
     * Rejects malformed form token fields without a type error.
     *
     * @return void
     */
    public function testRejectsArrayToken(): void
    {
        $session = [];
        (new CsrfProtection())->issueToken($session);
        $response = (new FormHandler())->handle($session, ['csrfToken' => []],
            /**
             * Fails the test if the operation runs.
             *
             * @param array $fields
             * @return Response
             */
            function (array $fields): Response {
                self::fail('A malformed CSRF token must not reach the operation.');
            },
        );
        self::assertSame(403, $response->statusCode);
    }
}
