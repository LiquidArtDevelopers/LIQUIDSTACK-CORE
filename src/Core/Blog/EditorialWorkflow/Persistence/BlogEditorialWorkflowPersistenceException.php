<?php

declare(strict_types=1);

namespace App\Core\Blog\EditorialWorkflow\Persistence;

use RuntimeException;

final class BlogEditorialWorkflowPersistenceException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Blog editorial workflow storage is unavailable.');
    }
}
