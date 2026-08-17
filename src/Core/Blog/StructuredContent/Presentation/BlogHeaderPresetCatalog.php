<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Presentation;

use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;

/** Stable allowlist mapping Blog templates to LiquidStack hero compositions. */
final class BlogHeaderPresetCatalog
{
    public const BASIC = 'basic';
    public const HERO00 = 'hero00';
    public const HERO06 = 'hero06';
    public const HERO07 = 'hero07';

    public function forTemplate(string $template): string
    {
        return match ($template) {
            BlogDocumentTemplateRegistry::ARTICLE_BASIC => self::BASIC,
            BlogDocumentTemplateRegistry::ARTICLE_HERO00 => self::HERO00,
            BlogDocumentTemplateRegistry::ARTICLE_HERO06 => self::HERO06,
            BlogDocumentTemplateRegistry::ARTICLE_COVER => self::HERO07,
            default => throw new \InvalidArgumentException(
                'Unsupported Blog header template.'
            ),
        };
    }
}
