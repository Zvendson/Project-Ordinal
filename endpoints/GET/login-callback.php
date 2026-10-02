<?php

/** Delegates the completeLogin browser endpoint to its account controller. */

declare(strict_types=1);

return (new \Ordinal\Controller\AccountController($this->accountApplication))->handle('completeLogin', $query, $fields, $cookies, $isSecure);
