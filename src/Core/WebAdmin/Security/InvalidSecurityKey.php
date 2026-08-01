<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Security;

use InvalidArgumentException;

final class InvalidSecurityKey extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('Invalid WebAdmin security key.');
    }
}
