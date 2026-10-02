<?php

/** Delegates the signIn action to local management. */

declare(strict_types=1);

use Ordinal\Controller\ManagementController;

return (new ManagementController($this->managementApplication))->handle('signIn', $query, $fields, $cookies, $isSecure, $_SERVER['REMOTE_ADDR'] ?? 'local');
