<?php

declare(strict_types=1);

namespace App\Core\Commerce\Http;

use Throwable;

final class CommercePublicItemRenderer
{
    private readonly string $view;

    public function __construct(string $projectRoot)
    {
        $candidate = rtrim($projectRoot, '/\\')
            . '/App/views/commerce-item.php';
        if (
            !is_file($candidate)
            || is_link($candidate)
            || !is_readable($candidate)
        ) {
            throw new CommercePublicHttpRuntimeException(
                'commerce.public_item_view_unavailable'
            );
        }
        $resolved = realpath($candidate);
        if (!is_string($resolved)) {
            throw new CommercePublicHttpRuntimeException(
                'commerce.public_item_view_unavailable'
            );
        }
        $this->view = $resolved;
    }

    public function render(CommercePublicItemPage $commerceItemPage): string
    {
        $bufferLevel = ob_get_level();
        ob_start();
        try {
            (static function (
                string $_liquidstackCommerceView,
                CommercePublicItemPage $commerceItemPage
            ): void {
                require $_liquidstackCommerceView;
            })($this->view, $commerceItemPage);
            if (ob_get_level() !== $bufferLevel + 1) {
                throw new CommercePublicHttpRuntimeException(
                    'commerce.public_item_view_invalid'
                );
            }
            $html = ob_get_clean();
        } catch (Throwable) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            throw new CommercePublicHttpRuntimeException(
                'commerce.public_item_view_invalid'
            );
        }
        if (!is_string($html) || trim($html) === '') {
            throw new CommercePublicHttpRuntimeException(
                'commerce.public_item_view_invalid'
            );
        }

        return $html;
    }
}
