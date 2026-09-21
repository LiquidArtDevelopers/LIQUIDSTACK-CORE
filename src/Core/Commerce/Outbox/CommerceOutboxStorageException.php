<?php

declare(strict_types=1);

namespace App\Core\Commerce\Outbox;

use RuntimeException;

final class CommerceOutboxStorageException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Commerce outbox storage is unavailable.');
    }
}
