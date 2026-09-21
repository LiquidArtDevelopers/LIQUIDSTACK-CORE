<?php

declare(strict_types=1);

namespace App\Core\Commerce;

final class ProductPathResolution
{
    private function __construct(
        private readonly LocalizedProduct $product,
        private readonly string $currentPath,
        private readonly bool $redirect
    ) {
        CommerceInput::publicPath($currentPath);
    }

    public static function found(LocalizedProduct $product, string $currentPath): self
    {
        return new self($product, $currentPath, false);
    }

    public static function redirect(LocalizedProduct $product, string $currentPath): self
    {
        return new self($product, $currentPath, true);
    }

    public function product(): LocalizedProduct { return $this->product; }
    public function currentPath(): string { return $this->currentPath; }
    public function isRedirect(): bool { return $this->redirect; }
}
