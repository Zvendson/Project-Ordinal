<?php

/** Delegates current project-administrator automation metadata to the account controller. */

declare(strict_types=1);

use Ordinal\Controller\AccountController;

return (new AccountController($this->accountApplication))->handle('showAutomation', $query, $fields, $cookies, $isSecure);
