<?php

declare(strict_types=1);

namespace App\Core\Composer;

final class BlogPublicShellAdoptionResult
{
    public const CONFIG_PATH = 'App/config/modules/blog.php';
    public const CONFIG_KEY = 'public_article_view';
    public const CONFIG_VALUE = 'App/views/blog-article.php';

    public function __construct(
        private readonly string $status,
        private readonly bool $changed
    ) {
    }

    /**
     * @return array{
     *     status: string,
     *     changed: bool,
     *     config_path: string,
     *     config_key: string,
     *     config_value: string
     * }
     */
    public function toSafeArray(): array
    {
        return [
            'status' => $this->status,
            'changed' => $this->changed,
            'config_path' => self::CONFIG_PATH,
            'config_key' => self::CONFIG_KEY,
            'config_value' => self::CONFIG_VALUE,
        ];
    }

    public function status(): string
    {
        return $this->status;
    }

    public function changed(): bool
    {
        return $this->changed;
    }
}
