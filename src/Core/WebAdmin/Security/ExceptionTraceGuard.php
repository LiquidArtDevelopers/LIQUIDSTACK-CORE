<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Security;

use RuntimeException;

/** Prevents PHP exception traces from retaining login arguments on PHP 8.1. */
final class ExceptionTraceGuard
{
    public static function assertEnabled(): void
    {
        $configured = strtolower(trim((string) ini_get(
            'zend.exception_ignore_args'
        )));
        if (!in_array($configured, ['1', 'on', 'true', 'yes'], true)) {
            throw new RuntimeException(
                'webadmin.security.exception_trace_arguments_enabled'
            );
        }
    }
}
