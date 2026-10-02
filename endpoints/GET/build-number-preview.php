<?php

/** Delegates the protected previewNext action to its controller. */

declare(strict_types=1);

use Ordinal\Controller\AccountController;

return (new AccountController($this->accountApplication))->handle('previewNext', $query, $fields, $cookies, $isSecure);
