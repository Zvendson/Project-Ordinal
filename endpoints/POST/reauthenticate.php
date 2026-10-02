<?php

/** Delegates the reauthenticate browser endpoint to its account controller. */

declare(strict_types=1);

return (new \Ordinal\Controller\AccountController($this->accountApplication))->handle('reauthenticate', $query, $fields, $cookies, $isSecure);
