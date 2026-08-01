<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Security;

use RuntimeException;

final class UnsupportedPasswordPolicy extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Required WebAdmin password policy is unavailable.');
    }
}
