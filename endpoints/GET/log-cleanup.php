<?php

/** Delegates the protected showLogCleanup action to its controller. */

declare(strict_types=1);

use Ordinal\Controller\AccountController;

return (new AccountController($this->accountApplication))->handle('showLogCleanup', $query, $fields, $cookies, $isSecure);
