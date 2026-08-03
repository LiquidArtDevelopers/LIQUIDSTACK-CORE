<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

/** One deterministic and human-readable editorial observation. */
final class BlogSeoCheck
{
    /** @param array<string, int|float|string|bool> $metrics */
    public function __construct(
        private readonly string $key,
        private readonly string $group,
        private readonly string $label,
        private readonly string $status,
        private readonly string $message,
        private readonly array $metrics = []
    ) {
        if (
            preg_match('/\A[a-z][a-z0-9_.-]{2,63}\z/', $key) !== 1
            || preg_match('/\A[a-z][a-z0-9_.-]{2,31}\z/', $group) !== 1
            || trim($label) === ''
            || trim($message) === ''
            || preg_match('//u', $label . $message) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid Blog SEO check.');
        }
        BlogSeoStatus::assert($status);
        foreach ($metrics as $metric => $value) {
            if (
                !is_string($metric)
                || preg_match('/\A[a-z][a-z0-9_.-]{1,63}\z/', $metric) !== 1
                || !is_scalar($value)
            ) {
                throw new \InvalidArgumentException(
                    'Invalid Blog SEO check metrics.'
                );
            }
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    public function status(): string
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'group' => $this->group,
            'label' => $this->label,
            'status' => $this->status,
            'status_label' => BlogSeoStatus::label($this->status),
            'message' => $this->message,
            'metrics' => $this->metrics,
        ];
    }
}
