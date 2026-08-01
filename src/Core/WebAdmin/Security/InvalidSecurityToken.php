<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Security;

use InvalidArgumentException;

final class InvalidSecurityToken extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('Invalid security token.');
    }
}
