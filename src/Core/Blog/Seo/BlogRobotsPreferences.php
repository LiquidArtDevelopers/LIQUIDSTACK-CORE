<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

use JsonException;

/** Immutable, per-locale robots preferences. */
final class BlogRobotsPreferences
{
    public function __construct(
        private readonly bool $index = true,
        private readonly bool $follow = true
    ) {
    }

    public static function defaults(): self
    {
        return new self();
    }

    public static function noIndexNoFollow(): self
    {
        return new self(false, false);
    }

    public function index(): bool
    {
        return $this->index;
    }

    public function follow(): bool
    {
        return $this->follow;
    }

    public function directive(): string
    {
        return ($this->index ? 'index' : 'noindex')
            . ',' . ($this->follow ? 'follow' : 'nofollow');
    }

    public function canonicalJson(): string
    {
        try {
            return json_encode([
                'schema' => 'liquidstack.blog.robots-preferences',
                'version' => 1,
                'index' => $this->index,
                'follow' => $this->follow,
            ], JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \LogicException(
                'Robots preferences could not be encoded.',
                0,
                $exception
            );
        }
    }

    public function integrityHash(): string
    {
        return hash('sha256', $this->canonicalJson());
    }

    public function equals(self $other): bool
    {
        return $this->index === $other->index
            && $this->follow === $other->follow;
    }

    /** @return array{index: bool, follow: bool, directive: string} */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'follow' => $this->follow,
            'directive' => $this->directive(),
        ];
    }
}
