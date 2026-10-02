<?php

/** Delegates the saveProject action to local management. */

declare(strict_types=1);

use Ordinal\Controller\ManagementController;

return (new ManagementController($this->managementApplication))->handle('saveProject', $query, $fields, $cookies, $isSecure, $_SERVER['REMOTE_ADDR'] ?? 'local');
