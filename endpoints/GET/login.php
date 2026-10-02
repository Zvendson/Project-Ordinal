<?php

/** Delegates the showLogin browser endpoint to its account controller. */

declare(strict_types=1);

return (new \Ordinal\Controller\AccountController($this->accountApplication))->handle('showLogin', $query, $fields, $cookies, $isSecure);
