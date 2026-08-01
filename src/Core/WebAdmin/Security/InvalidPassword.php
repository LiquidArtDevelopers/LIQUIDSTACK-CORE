<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Security;

use InvalidArgumentException;

final class InvalidPassword extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('Password does not satisfy policy.');
    }
}
