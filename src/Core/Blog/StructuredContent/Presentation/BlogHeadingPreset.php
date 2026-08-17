<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Presentation;

use InvalidArgumentException;

/** Immutable, code-owned presentation contract for one Blog heading preset. */
final class BlogHeadingPreset
{
    public function __construct(
        private readonly string $token,
        private readonly string $label,
        private readonly string $showroomResource,
        private readonly string $previewClass,
        private readonly string $ssrClass
    ) {
        if (
            preg_match('/\A[a-z0-9-]+\z/', $token) !== 1
            || preg_match('/\A(?:base|moduleH2Type[0-9]{2})\z/', $showroomResource)
                !== 1
            || !$this->validCssClass($previewClass)
            || !$this->validCssClass($ssrClass)
            || $label === ''
            || strlen($label) > 80
            || preg_match('//u', $label) !== 1
            || preg_match('/\p{Cc}/u', $label) === 1
        ) {
            throw new InvalidArgumentException(
                'Invalid Blog heading preset definition.'
            );
        }
    }

    public function token(): string
    {
        return $this->token;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function showroomResource(): string
    {
        return $this->showroomResource;
    }

    public function previewClass(): string
    {
        return $this->previewClass;
    }

    public function ssrClass(): string
    {
        return $this->ssrClass;
    }

    /**
     * @return array{
     *   token: string,
     *   label: string,
     *   showroom_resource: string,
     *   preview_class: string,
     *   ssr_class: string
     * }
     */
    public function toSafeArray(): array
    {
        return [
            'token' => $this->token,
            'label' => $this->label,
            'showroom_resource' => $this->showroomResource,
            'preview_class' => $this->previewClass,
            'ssr_class' => $this->ssrClass,
        ];
    }

    private function validCssClass(string $className): bool
    {
        return preg_match(
            '/\A[A-Za-z_][A-Za-z0-9_-]*\z/',
            $className
        ) === 1;
    }
}
