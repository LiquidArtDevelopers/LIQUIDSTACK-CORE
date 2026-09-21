<?php

declare(strict_types=1);

namespace App\Core\Commerce\Media;

use RuntimeException;

final class CommercePublicMediaException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Commerce public media is unavailable.');
    }
}
