<?php

/** Delegates the protected saveHistoryVisibility action to its controller. */

declare(strict_types=1);

use Ordinal\Controller\AccountController;

return (new AccountController($this->accountApplication))->handle('saveHistoryVisibility', $query, $fields, $cookies, $isSecure);
