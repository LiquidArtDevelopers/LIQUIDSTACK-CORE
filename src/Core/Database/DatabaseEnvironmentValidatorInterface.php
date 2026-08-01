<?php

declare(strict_types=1);

namespace App\Core\Database;

interface DatabaseEnvironmentValidatorInterface
{
    /**
     * @param array<string, mixed> $environment
     * @return array{missing: list<string>, invalid: list<string>, ready: bool}
     */
    public function inspect(array $environment): array;

    /**
     * @param array<string, mixed> $environment
     * @return array{0: string, 1: string, 2: string}
     */
    public function connectionParameters(array $environment): array;
}
