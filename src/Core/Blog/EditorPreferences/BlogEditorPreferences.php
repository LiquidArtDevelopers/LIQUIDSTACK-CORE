<?php

declare(strict_types=1);

namespace App\Core\Blog\EditorPreferences;

use App\Core\Blog\StructuredContent\Presentation\BlogHeadingPresetCatalog;

/** Immutable code-owned contract for the global heading defaults. */
final class BlogEditorPreferences
{
    public const SCHEMA = 'liquidstack.blog.editor-preferences';
    public const VERSION = 1;

    /** @var list<string> */
    private const LEVELS = ['h2', 'h3', 'h4', 'h5', 'h6'];

    /** @var array<string, BlogHeadingDefaultStyle> */
    private readonly array $headingDefaults;

    /**
     * @param array<string, mixed> $headingDefaults
     */
    public function __construct(
        array $headingDefaults,
        ?BlogHeadingPresetCatalog $catalog = null
    ) {
        $catalog ??= new BlogHeadingPresetCatalog();
        $keys = array_keys($headingDefaults);
        sort($keys, SORT_STRING);
        $levels = self::LEVELS;
        sort($levels, SORT_STRING);
        if ($keys !== $levels) {
            throw new BlogEditorPreferencesException(
                BlogEditorPreferencesException::INVALID_INPUT
            );
        }
        $validated = [];
        foreach (self::LEVELS as $level) {
            $style = $headingDefaults[$level] ?? null;
            if (!is_array($style) || array_is_list($style)) {
                throw new BlogEditorPreferencesException(
                    BlogEditorPreferencesException::INVALID_INPUT
                );
            }
            $validated[$level] = new BlogHeadingDefaultStyle(
                $style,
                $catalog
            );
        }

        $this->headingDefaults = $validated;
    }

    public static function defaults(
        ?BlogHeadingPresetCatalog $catalog = null
    ): self {
        $catalog ??= new BlogHeadingPresetCatalog();
        $default = BlogHeadingDefaultStyle::defaults($catalog)->toArray();

        return new self([
            'h2' => $default,
            'h3' => $default,
            'h4' => $default,
            'h5' => $default,
            'h6' => $default,
        ], $catalog);
    }

    /** @return array<string, array<string, string>> */
    public function headingDefaults(): array
    {
        return array_map(
            static fn (BlogHeadingDefaultStyle $style): array =>
                $style->toArray(),
            $this->headingDefaults
        );
    }

    public function headingDefault(string $level): BlogHeadingDefaultStyle
    {
        if (!isset($this->headingDefaults[$level])) {
            throw new BlogEditorPreferencesException(
                BlogEditorPreferencesException::INVALID_INPUT
            );
        }

        return $this->headingDefaults[$level];
    }

    /**
     * @return array{
     *   schema: string,
     *   version: int,
     *   heading_defaults: array<string, array<string, string>>
     * }
     */
    public function toCanonicalArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'heading_defaults' => $this->headingDefaults(),
        ];
    }

    public function equals(self $other): bool
    {
        return $this->headingDefaults() === $other->headingDefaults();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->toCanonicalArray();
    }
}
