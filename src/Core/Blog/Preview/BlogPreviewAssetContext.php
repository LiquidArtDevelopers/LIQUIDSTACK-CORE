<?php

declare(strict_types=1);

namespace App\Core\Blog\Preview;

use InvalidArgumentException;

/** Public, secret-free context exposed to a project-owned preview adapter. */
final class BlogPreviewAssetContext
{
    private readonly string $projectRoot;

    public function __construct(
        string $projectRoot,
        private readonly bool $development
    ) {
        $resolved = realpath($projectRoot);
        if (!is_string($resolved) || !is_dir($resolved)) {
            throw new InvalidArgumentException('Invalid preview project root.');
        }

        $this->projectRoot = $resolved;
    }

    /** @param array<string, mixed> $environment */
    public static function fromEnvironment(
        string $projectRoot,
        #[\SensitiveParameter] array $environment
    ): self {
        $value = $environment['DEV_MODE'] ?? null;
        $development = $value === true || (
            is_string($value)
            && in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true)
        );

        return new self($projectRoot, $development);
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    public function isDevelopment(): bool
    {
        return $this->development;
    }

    public function matches(self $other): bool
    {
        $normalize = static fn (string $path): string => PHP_OS_FAMILY
            === 'Windows'
                ? strtolower(str_replace('\\', '/', $path))
                : str_replace('\\', '/', $path);

        return $this->development === $other->development
            && $normalize($this->projectRoot)
                === $normalize($other->projectRoot);
    }
}
