<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Presentation;

use InvalidArgumentException;

/** Validated, canonical public color value; never arbitrary CSS. */
final class BlogPublicColor
{
    public const THEME = 'theme';
    public const RGBA = 'rgba';

    private const THEME_PATTERN = '/\Acolor0[0-5]\z/';
    private const RGBA_PATTERN = '/\Argba\(\s*([0-9]{1,3})\s*,\s*'
        . '([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*'
        . '(0(?:\.[0-9]{1,6})?|1(?:\.0{1,6})?)\s*\)\z/i';

    private function __construct(
        private readonly string $kind,
        private readonly string $value
    ) {
    }

    public static function fromInput(mixed $input): self
    {
        if (!is_string($input)) {
            throw new InvalidArgumentException('Invalid Blog color.');
        }
        $input = trim($input);
        if (preg_match(self::THEME_PATTERN, $input) === 1) {
            return new self(self::THEME, $input);
        }
        if (preg_match(self::RGBA_PATTERN, $input, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid Blog color.');
        }

        $channels = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
        foreach ($channels as $channel) {
            if ($channel < 0 || $channel > 255) {
                throw new InvalidArgumentException('Invalid Blog color.');
            }
        }
        $alpha = self::canonicalAlpha($matches[4]);

        return new self(
            self::RGBA,
            sprintf(
                'rgba(%d, %d, %d, %s)',
                $channels[0],
                $channels[1],
                $channels[2],
                $alpha
            )
        );
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isThemeToken(): bool
    {
        return $this->kind === self::THEME;
    }

    private static function canonicalAlpha(string $alpha): string
    {
        if (str_starts_with($alpha, '1')) {
            return '1';
        }
        if ($alpha === '0') {
            return '0';
        }

        $canonical = rtrim($alpha, '0');

        return str_ends_with($canonical, '.')
            ? substr($canonical, 0, -1)
            : $canonical;
    }
}
