<?php

/** Delegates the logout browser endpoint to its account controller. */

declare(strict_types=1);

return (new \Ordinal\Controller\AccountController($this->accountApplication))->handle('logout', $query, $fields, $cookies, $isSecure);
