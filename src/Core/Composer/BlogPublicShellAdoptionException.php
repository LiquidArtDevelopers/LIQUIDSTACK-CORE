<?php

declare(strict_types=1);

namespace App\Core\Composer;

use RuntimeException;

final class BlogPublicShellAdoptionException extends RuntimeException
{
    public function __construct(
        private readonly string $issueCode,
        private readonly ?string $relativePath = null
    ) {
        parent::__construct($issueCode);
    }

    public function issueCode(): string
    {
        return $this->issueCode;
    }

    public function relativePath(): ?string
    {
        return $this->relativePath;
    }
}
