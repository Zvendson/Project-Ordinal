<?php

/** Delegates the protected saveArchiveState action to its controller. */

declare(strict_types=1);

use Ordinal\Controller\AccountController;

return (new AccountController($this->accountApplication))->handle('saveArchiveState', $query, $fields, $cookies, $isSecure);
