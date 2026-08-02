<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

use RuntimeException;

/** Loads the canonical LiquidStack resource templates used by WebAdmin. */
final class WebAdminResourceTemplateRenderer
{
    private const ALLOWED_TEMPLATES = [
        '_artAuth01.html',
        '_moduleFormAuthLogin01.html',
        '_moduleFormAuthRecover01.html',
        '_moduleFormAuthPassword01.html',
    ];

    private readonly string $templateRoot;

    public function __construct(?string $templateRoot = null)
    {
        $this->templateRoot = rtrim(
            $templateRoot ?? dirname(__DIR__, 4) . '/stubs/App/templates',
            '/\\'
        );
    }

    /** @param array<string, string> $values */
    public function render(string $template, array $values): string
    {
        if (!in_array($template, self::ALLOWED_TEMPLATES, true)) {
            throw new RuntimeException('Unsupported WebAdmin resource template.');
        }

        $path = $this->templateRoot . DIRECTORY_SEPARATOR . $template;
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new RuntimeException('WebAdmin resource template unavailable.');
        }
        $source = file_get_contents($path);
        if (!is_string($source)) {
            throw new RuntimeException('WebAdmin resource template unavailable.');
        }

        preg_match_all('/\{[A-Za-z][A-Za-z0-9-]*\}/', $source, $matches);
        $placeholders = array_values(array_unique($matches[0] ?? []));
        foreach ($placeholders as $placeholder) {
            if (!array_key_exists($placeholder, $values)) {
                throw new RuntimeException(
                    'Incomplete WebAdmin resource presentation contract.'
                );
            }
        }

        return str_replace(
            $placeholders,
            array_map(
                static fn (string $placeholder): string =>
                    $values[$placeholder],
                $placeholders
            ),
            $source
        );
    }
}
