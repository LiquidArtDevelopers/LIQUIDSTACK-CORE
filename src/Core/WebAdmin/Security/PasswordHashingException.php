<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Security;

use RuntimeException;

final class PasswordHashingException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Password hashing failed.');
    }
}
