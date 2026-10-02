<?php

/** Delegates protected device metadata and enrollment forms to the account controller. */

declare(strict_types=1);

use Ordinal\Controller\AccountController;

return (new AccountController($this->accountApplication))->handle('showDevices', $query, $fields, $cookies, $isSecure);
