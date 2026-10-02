<?php

/** Delegates owner or administrator device/credential revocation through a protected form. */

declare(strict_types=1);

use Ordinal\Controller\AccountController;

return (new AccountController($this->accountApplication))->handle('revokeDevice', $query, $fields, $cookies, $isSecure);
