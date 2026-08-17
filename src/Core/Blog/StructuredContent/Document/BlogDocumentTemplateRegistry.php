<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Document;

/** Immutable registry of the code-owned templates supported by schema v1. */
final class BlogDocumentTemplateRegistry
{
    public const ARTICLE_BASIC = 'article-basic-01';
    public const ARTICLE_COVER = 'article-cover-01';
    public const ARTICLE_HERO00 = 'article-hero00-01';
    public const ARTICLE_HERO06 = 'article-hero06-01';

    private const TEMPLATES = [
        self::ARTICLE_BASIC,
        self::ARTICLE_COVER,
        self::ARTICLE_HERO00,
        self::ARTICLE_HERO06,
    ];

    private const COVER_TEMPLATES = [
        self::ARTICLE_COVER,
        self::ARTICLE_HERO00,
        self::ARTICLE_HERO06,
    ];

    /** @return list<string> */
    public function keys(): array
    {
        return self::TEMPLATES;
    }

    public function supports(string $template): bool
    {
        return in_array($template, self::TEMPLATES, true);
    }

    public function assertSupported(string $template): void
    {
        if (!$this->supports($template)) {
            throw new BlogDocumentException(
                BlogDocumentException::UNSUPPORTED_TEMPLATE
            );
        }
    }

    public static function hasCover(string $template): bool
    {
        return in_array($template, self::COVER_TEMPLATES, true);
    }

    /** @param list<array<string, mixed>> $blocks */
    public function assertDocumentContract(
        string $template,
        array $blocks
    ): void {
        $this->assertSupported($template);

        if (!self::hasCover($template)) {
            if ($this->coverPositions($blocks) !== []) {
                throw new BlogDocumentException(
                    BlogDocumentException::INVALID_TEMPLATE_CONTRACT
                );
            }

            return;
        }

        if (
            $blocks === []
            || ($blocks[0]['type'] ?? null) !== 'image'
            || ($blocks[0]['display'] ?? null) !== 'cover'
            || $this->coverPositions($blocks) !== [0]
        ) {
            throw new BlogDocumentException(
                BlogDocumentException::INVALID_TEMPLATE_CONTRACT
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<int>
     */
    private function coverPositions(array $blocks): array
    {
        $positions = [];
        foreach ($blocks as $position => $block) {
            if (
                ($block['type'] ?? null) === 'image'
                && ($block['display'] ?? null) === 'cover'
            ) {
                $positions[] = $position;
            }
        }

        return $positions;
    }
}
