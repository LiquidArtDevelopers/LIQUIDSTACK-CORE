<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Authentication;

use RuntimeException;

final class PreAuthenticationRateLimited extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('webadmin.preauthentication.rate_limited');
    }
}
