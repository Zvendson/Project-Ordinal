<?php

/** Delegates the protected showProjectLogs action to its controller. */

declare(strict_types=1);

use Ordinal\Controller\AccountController;

return (new AccountController($this->accountApplication))->handle('showProjectLogs', $query, $fields, $cookies, $isSecure);
