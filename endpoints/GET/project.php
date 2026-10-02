<?php

/** Delegates the showProject browser endpoint to its account controller. */

declare(strict_types=1);

return (new \Ordinal\Controller\AccountController($this->accountApplication))->handle('showProject', $query, $fields, $cookies, $isSecure);
