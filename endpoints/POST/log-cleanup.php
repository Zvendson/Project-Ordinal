<?php

/** Delegates the protected cleanupLogs action to its controller. */

declare(strict_types=1);

use Ordinal\Controller\AccountController;

return (new AccountController($this->accountApplication))->handle('cleanupLogs', $query, $fields, $cookies, $isSecure);
