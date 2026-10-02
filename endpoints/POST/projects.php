<?php

/** Delegates the createProject browser endpoint to its account controller. */

declare(strict_types=1);

return (new \Ordinal\Controller\AccountController($this->accountApplication))->handle('createProject', $query, $fields, $cookies, $isSecure);
