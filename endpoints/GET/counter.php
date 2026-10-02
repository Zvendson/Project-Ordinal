<?php

/** Delegates the protected showCounter action to its controller. */

declare(strict_types=1);

use Ordinal\Controller\AccountController;

return (new AccountController($this->accountApplication))->handle('showCounter', $query, $fields, $cookies, $isSecure);
