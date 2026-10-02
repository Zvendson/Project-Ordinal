<?php

/** Delegates the protected showInstanceLogs action to its controller. */

declare(strict_types=1);

use Ordinal\Controller\AccountController;

return (new AccountController($this->accountApplication))->handle('showInstanceLogs', $query, $fields, $cookies, $isSecure);
