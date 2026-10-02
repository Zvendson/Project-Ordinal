<?php

/** Delegates provider-approved enrollment through a protected browser form. */

declare(strict_types=1);

use Ordinal\Controller\AccountController;

return (new AccountController($this->accountApplication))->handle('enrollDevice', $query, $fields, $cookies, $isSecure);
