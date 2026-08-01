<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\UserManagement;

use RuntimeException;

final class UserManagementStorageException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('WebAdmin user management is unavailable.');
    }
}
