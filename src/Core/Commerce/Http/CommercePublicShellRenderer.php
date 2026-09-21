<?php

declare(strict_types=1);

namespace App\Core\Commerce\Http;

use Throwable;

/** Renders the project-owned catalog and inquiry shells after static-route miss. */
final class CommercePublicShellRenderer
{
    private readonly string $catalogView;
    private readonly string $inquiryView;

    public function __construct(string $projectRoot)
    {
        $root = rtrim($projectRoot, '/\\');
        $this->catalogView = $this->resolve(
            $root . '/App/views/commerce.php'
        );
        $this->inquiryView = $this->resolve(
            $root . '/App/views/commerce-inquiry.php'
        );
    }

    public function renderCatalog(string $locale): string
    {
        return $this->render($this->catalogView, $locale);
    }

    public function renderInquiry(string $locale): string
    {
        return $this->render($this->inquiryView, $locale);
    }

    private function resolve(string $candidate): string
    {
        if (
            !is_file($candidate)
            || is_link($candidate)
            || !is_readable($candidate)
        ) {
            throw new CommercePublicHttpRuntimeException(
                'commerce.public_shell_unavailable'
            );
        }
        $resolved = realpath($candidate);
        if (!is_string($resolved)) {
            throw new CommercePublicHttpRuntimeException(
                'commerce.public_shell_unavailable'
            );
        }

        return $resolved;
    }

    private function render(string $view, string $locale): string
    {
        $bufferLevel = ob_get_level();
        ob_start();
        try {
            (static function (string $view, string $lang): void {
                require $view;
            })($view, $locale);
            if (ob_get_level() !== $bufferLevel + 1) {
                throw new CommercePublicHttpRuntimeException(
                    'commerce.public_shell_invalid'
                );
            }
            $html = ob_get_clean();
        } catch (Throwable) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            throw new CommercePublicHttpRuntimeException(
                'commerce.public_shell_invalid'
            );
        }
        if (!is_string($html) || trim($html) === '') {
            throw new CommercePublicHttpRuntimeException(
                'commerce.public_shell_invalid'
            );
        }

        return $html;
    }
}
