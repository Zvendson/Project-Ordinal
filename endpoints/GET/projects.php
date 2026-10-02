<?php

/** Delegates the showProjects browser endpoint to its account controller. */

declare(strict_types=1);

return (new \Ordinal\Controller\AccountController($this->accountApplication))->handle('showProjects', $query, $fields, $cookies, $isSecure);
