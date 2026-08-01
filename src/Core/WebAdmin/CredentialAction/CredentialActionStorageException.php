<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\CredentialAction;

use RuntimeException;

final class CredentialActionStorageException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'WebAdmin credential-action storage is unavailable.'
        );
    }
}
