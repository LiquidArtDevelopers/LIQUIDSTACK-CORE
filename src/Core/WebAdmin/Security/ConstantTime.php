<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Security;

final class ConstantTime
{
    private function __construct()
    {
    }

    public static function equals(string $known, string $candidate): bool
    {
        return hash_equals($known, $candidate);
    }
}
