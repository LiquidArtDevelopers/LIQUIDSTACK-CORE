<?php

declare(strict_types=1);

namespace App\Core\Database;

use PDO;

interface PdoConnectionFactoryInterface
{
    public function connect(): PDO;
}
