<?php

/** Delegates the startLogin browser endpoint to its account controller. */

declare(strict_types=1);

return (new \Ordinal\Controller\AccountController($this->accountApplication))->handle('startLogin', $query, $fields, $cookies, $isSecure);
