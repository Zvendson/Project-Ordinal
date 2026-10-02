<?php

/** Delegates CSRF-protected instance automation policy changes. */

declare(strict_types=1);

use Ordinal\Controller\AccountController;

return (new AccountController($this->accountApplication))->handle('saveAutomationPolicy', $query, $fields, $cookies, $isSecure);
