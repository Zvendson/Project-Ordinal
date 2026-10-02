<?php

/** Delegates the saveAdministration browser endpoint to its account controller. */

declare(strict_types=1);

return (new \Ordinal\Controller\AccountController($this->accountApplication))->handle('saveAdministration', $query, $fields, $cookies, $isSecure);
