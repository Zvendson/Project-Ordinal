<?php

/** Delegates protected defaults and project-specific authentication overrides. */

declare(strict_types=1);

use Ordinal\Controller\AccountController;

return (new AccountController($this->accountApplication))->handle('saveAuthenticationPolicy', $query, $fields, $cookies, $isSecure);
