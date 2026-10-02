<?php

/** Delegates the showProject action to local management. */

declare(strict_types=1);

use Ordinal\Controller\ManagementController;

return (new ManagementController($this->managementApplication))->handle('showProject', $query, $fields, $cookies, $isSecure, $_SERVER['REMOTE_ADDR'] ?? 'local');
