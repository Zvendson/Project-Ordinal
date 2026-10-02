<?php

/** Delegates the protected saveCounter action to its controller. */

declare(strict_types=1);

use Ordinal\Controller\AccountController;

return (new AccountController($this->accountApplication))->handle('saveCounter', $query, $fields, $cookies, $isSecure);
