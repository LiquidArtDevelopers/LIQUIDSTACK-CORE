<?php

declare(strict_types=1);

namespace App\Core\Blog\Audit;

use RuntimeException;

/** Stable boundary for the WebAdmin-backed Blog audit adapter. */
final class BlogMutationAuditStorageException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Blog mutation audit is unavailable.');
    }
}
