<?php

/** Delegates the showAccount browser endpoint to its account controller. */

declare(strict_types=1);

return (new \Ordinal\Controller\AccountController($this->accountApplication))->handle('showAccount', $query, $fields, $cookies, $isSecure);
