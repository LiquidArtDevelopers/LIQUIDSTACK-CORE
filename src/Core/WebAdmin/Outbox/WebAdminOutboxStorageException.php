<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Outbox;

use RuntimeException;

final class WebAdminOutboxStorageException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('WebAdmin outbox storage is unavailable.');
    }
}
