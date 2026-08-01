<?php

declare(strict_types=1);

namespace App\Core\Blog\Persistence;

use RuntimeException;

/** Internal fail-closed persistence error; never carries PDO/SQL details. */
final class BlogPersistenceException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Blog persistence is unavailable.');
    }
}
