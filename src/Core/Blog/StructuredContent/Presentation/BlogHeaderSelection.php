<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Presentation;

use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;

/** Typed selection that keeps the hero and its H1 module independent. */
final class BlogHeaderSelection
{
    public function __construct(
        private readonly ?string $hero,
        private readonly string $h1Module,
        ?BlogHeroCatalog $heroes = null,
        ?BlogH1ModuleCatalog $h1Modules = null
    ) {
        $heroes ??= new BlogHeroCatalog();
        $h1Modules ??= new BlogH1ModuleCatalog();
        if (
            ($hero !== null && !$heroes->supports($hero))
            || !$h1Modules->supports($h1Module)
        ) {
            throw new \InvalidArgumentException(
                'Unsupported Blog header selection.'
            );
        }
    }

    public function hero(): ?string
    {
        return $this->hero;
    }

    public function h1Module(): string
    {
        return $this->h1Module;
    }

    public function hasHero(): bool
    {
        return $this->hero !== null;
    }

    /** @return array{hero: ?string, h1_module: string} */
    public function toArray(): array
    {
        return [
            'hero' => $this->hero,
            'h1_module' => $this->h1Module,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(
        array $data,
        ?BlogHeroCatalog $heroes = null,
        ?BlogH1ModuleCatalog $h1Modules = null
    ): self {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        if (
            array_is_list($data)
            || $keys !== ['h1_module', 'hero']
            || ($data['hero'] !== null && !is_string($data['hero']))
            || !is_string($data['h1_module'])
        ) {
            throw new \InvalidArgumentException(
                'Invalid Blog header selection.'
            );
        }

        return new self(
            $data['hero'],
            $data['h1_module'],
            $heroes,
            $h1Modules
        );
    }

    public static function forDocument(
        BlogDocument $document,
        ?BlogHeroCatalog $heroes = null,
        ?BlogH1ModuleCatalog $h1Modules = null
    ): self {
        $selection = $document->headerSelectionData();
        if ($selection !== null) {
            return self::fromArray($selection, $heroes, $h1Modules);
        }

        return self::forTemplate(
            $document->template(),
            $heroes,
            $h1Modules
        );
    }

    public static function forTemplate(
        string $template,
        ?BlogHeroCatalog $heroes = null,
        ?BlogH1ModuleCatalog $h1Modules = null
    ): self {
        [$hero, $h1Module] = match ($template) {
            BlogDocumentTemplateRegistry::ARTICLE_BASIC => [
                null,
                BlogH1ModuleCatalog::TYPE04,
            ],
            BlogDocumentTemplateRegistry::ARTICLE_HERO00 => [
                BlogHeroCatalog::HERO00,
                BlogH1ModuleCatalog::TYPE01,
            ],
            BlogDocumentTemplateRegistry::ARTICLE_HERO06 => [
                BlogHeroCatalog::HERO06,
                BlogH1ModuleCatalog::TYPE03,
            ],
            BlogDocumentTemplateRegistry::ARTICLE_COVER => [
                BlogHeroCatalog::HERO07,
                BlogH1ModuleCatalog::TYPE04,
            ],
            default => throw new \InvalidArgumentException(
                'Unsupported Blog header template.'
            ),
        };

        return new self($hero, $h1Module, $heroes, $h1Modules);
    }
}
