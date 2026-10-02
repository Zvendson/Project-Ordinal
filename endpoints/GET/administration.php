<?php

/** Delegates the showAdministration browser endpoint to its account controller. */

declare(strict_types=1);

return (new \Ordinal\Controller\AccountController($this->accountApplication))->handle('showAdministration', $query, $fields, $cookies, $isSecure);
