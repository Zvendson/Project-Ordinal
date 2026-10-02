<?php

/** Delegates the previewNext action to local management. */

declare(strict_types=1);

use Ordinal\Controller\ManagementController;

return (new ManagementController($this->managementApplication))->handle('previewNext', $query, $fields, $cookies, $isSecure, $_SERVER['REMOTE_ADDR'] ?? 'local');
