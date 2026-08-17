<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Presentation;

/**
 * Single code-owned catalog for heading styles available to the Blog editor.
 *
 * Tokens remain stable because they are persisted in structured documents.
 * The resource metadata records which real LiquidStack showroom pattern each
 * preset follows; the Blog renderer never invokes a project controller.
 */
final class BlogHeadingPresetCatalog
{
    public const BASE = 'default';
    public const MODULE_H2_TYPE01 = 'accent-line';
    public const MODULE_H2_TYPE02 = 'accent-block';

    /** @var array<string, BlogHeadingPreset> */
    private array $byToken;

    public function __construct()
    {
        $presets = [
            new BlogHeadingPreset(
                self::BASE,
                'Base',
                'base',
                'blogEditor__headingPreset--base',
                'blogDocument__heading--preset-default'
            ),
            new BlogHeadingPreset(
                self::MODULE_H2_TYPE01,
                "L\u{00ED}nea",
                'moduleH2Type01',
                'blogEditor__headingPreset--moduleH2Type01',
                'blogDocument__heading--preset-accent-line'
            ),
            new BlogHeadingPreset(
                self::MODULE_H2_TYPE02,
                'Degradado',
                'moduleH2Type02',
                'blogEditor__headingPreset--moduleH2Type02',
                'blogDocument__heading--preset-accent-block'
            ),
        ];

        $this->byToken = [];
        foreach ($presets as $preset) {
            $this->byToken[$preset->token()] = $preset;
        }
    }

    public static function defaults(): self
    {
        return new self();
    }

    /** @return list<BlogHeadingPreset> */
    public function presets(): array
    {
        return array_values($this->byToken);
    }

    /** @return list<string> */
    public function tokens(): array
    {
        return array_keys($this->byToken);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return $this->tokens();
    }

    public function defaultKey(): string
    {
        return self::BASE;
    }

    public function supports(string $token): bool
    {
        return isset($this->byToken[$token]);
    }

    public function isAllowed(string $token): bool
    {
        return $this->supports($token);
    }

    public function has(string $token): bool
    {
        return $this->supports($token);
    }

    public function find(string $token): ?BlogHeadingPreset
    {
        return $this->byToken[$token] ?? null;
    }

    public function publicClass(string $token): ?string
    {
        return $this->find($token)?->ssrClass();
    }

    public function previewClass(string $token): ?string
    {
        return $this->find($token)?->previewClass();
    }

    /**
     * Safe transport shape for server-rendered editor configuration.
     *
     * @return list<array{
     *   token: string,
     *   label: string,
     *   showroom_resource: string,
     *   preview_class: string,
     *   ssr_class: string
     * }>
     */
    public function toSafeArray(): array
    {
        return array_map(
            static fn (BlogHeadingPreset $preset): array =>
                $preset->toSafeArray(),
            $this->presets()
        );
    }
}
