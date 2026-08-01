<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Authentication;

use RuntimeException;

final class AuthenticationStorageException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('WebAdmin authentication storage is unavailable.');
    }
}
