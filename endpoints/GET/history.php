<?php

/** Delegates the protected showHistory action to its controller. */

declare(strict_types=1);

use Ordinal\Controller\AccountController;

return (new AccountController($this->accountApplication))->handle('showHistory', $query, $fields, $cookies, $isSecure);
