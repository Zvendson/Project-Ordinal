<?php

/** Delegates protected named-token issuance, rename, rotation, and revocation forms. */

declare(strict_types=1);

use Ordinal\Controller\AccountController;

return (new AccountController($this->accountApplication))->handle('saveAutomation', $query, $fields, $cookies, $isSecure);
