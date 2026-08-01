<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Navigation;

use InvalidArgumentException;

final class WebAdminNavigationItem
{
    public function __construct(
        private readonly string $module,
        private readonly string $label,
        private readonly string $suffix,
        private readonly string $requiredCapability
    ) {
        if (preg_match('/\A[a-z][a-z0-9-]*\z/', $module) !== 1) {
            throw new InvalidArgumentException(
                'Invalid WebAdmin navigation module.'
            );
        }
        if (
            $label === ''
            || $label !== trim($label)
            || strlen($label) > 120
            || preg_match('//u', $label) !== 1
            || preg_match('/[\x00-\x1F\x7F<>]/', $label) === 1
        ) {
            throw new InvalidArgumentException(
                'Invalid WebAdmin navigation label.'
            );
        }
        if (
            strlen($suffix) > 160
            || preg_match(
                '#\A/[a-z0-9][a-z0-9_-]*(?:/[a-z0-9][a-z0-9_-]*)*\z#',
                $suffix
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Invalid WebAdmin navigation child suffix.'
            );
        }
        if (
            preg_match(
                '/\A[a-z][a-z0-9_.-]{2,127}\z/',
                $requiredCapability
            ) !== 1
            || !str_starts_with($requiredCapability, $module . '.')
        ) {
            throw new InvalidArgumentException(
                'Invalid WebAdmin navigation capability.'
            );
        }
    }

    public function module(): string
    {
        return $this->module;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function suffix(): string
    {
        return $this->suffix;
    }

    public function requiredCapability(): string
    {
        return $this->requiredCapability;
    }

    public function equals(self $other): bool
    {
        return $this->module === $other->module
            && $this->label === $other->label
            && $this->suffix === $other->suffix
            && $this->requiredCapability === $other->requiredCapability;
    }
}
