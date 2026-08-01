<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Authorization;

use RuntimeException;

final class AuthorizationStorageException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('WebAdmin authorization is unavailable.');
    }
}
