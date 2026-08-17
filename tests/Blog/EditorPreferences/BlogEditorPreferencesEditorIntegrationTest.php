<?php

declare(strict_types=1);

namespace Tests\Blog\EditorPreferences;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogService;
use App\Core\Blog\Configuration\BlogConfig;
use App\Core\Blog\EditorPreferences\BlogEditorPreferences;
use App\Core\Blog\EditorPreferences\BlogEditorPreferencesService;
use App\Core\Blog\EditorPreferences\BlogEditorPreferencesState;
use App\Core\Blog\EditorPreferences\Persistence\{
    BlogEditorPreferencesRepositoryInterface
};
use App\Core\Blog\Http\BlogAdminHttpRuntime;
use App\Core\Blog\Http\BlogAdminHttpRuntimeException;
use App\Core\Blog\Http\BlogStructuredEditorHttpController;
use App\Core\Blog\Http\BlogStructuredEditorHttpRuntimeInterface;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTextProjector;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredEditorService;
use App\Core\Blog\StructuredContent\Media\BlogEditorMediaCatalogInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogImageResolverInterface;
use App\Core\Blog\StructuredContent\Rendering\BlogResolvedImage;
use App\Core\Blog\StructuredContent\Rendering\BlogStructuredEditorHtmlRenderer;
use App\Core\Blog\StructuredContent\Presentation\BlogHeadingPresetCatalog;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Authorization\WebAdminAuthorizationService;
use App\Core\WebAdmin\Authorization\WebAdminMutationActorGate;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use Closure;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class EditorPreferencesMemoryRepository implements
    BlogEditorPreferencesRepositoryInterface
{
    public function __construct(
        private readonly ?BlogEditorPreferencesState $state,
        private readonly bool $failReads = false
    ) {
    }

    public function transactional(callable $operation): mixed
    {
        return $operation(new PDO('sqlite::memory:'));
    }

    public function global(): ?BlogEditorPreferencesState
    {
        if ($this->failReads) {
            throw new RuntimeException('Simulated preference storage failure.');
        }

        return $this->state;
    }

    public function lockGlobal(): ?BlogEditorPreferencesState
    {
        return $this->state;
    }

    public function insertGlobal(
        BlogEditorPreferences $preferences,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void {
    }

    public function updateGlobal(
        int $expectedLockVersion,
        BlogEditorPreferences $preferences,
        string $actorPublicId,
        DateTimeImmutable $now
    ): bool {
        return true;
    }
}

/** Compatibility runtime that deliberately predates editor preferences. */
final class LegacyStructuredEditorRuntime implements
    BlogStructuredEditorHttpRuntimeInterface
{
    public function __construct(private readonly BlogAdminHttpRuntime $inner)
    {
    }

    public function projectRoot(): string
    {
        return $this->inner->projectRoot();
    }

    public function languages(): array
    {
        return $this->inner->languages();
    }

    public function blogConfig(): BlogConfig
    {
        return $this->inner->blogConfig();
    }

    public function webAdminConfig(): WebAdminConfig
    {
        return $this->inner->webAdminConfig();
    }

    public function service(): BlogService
    {
        return $this->inner->service();
    }

    public function authentication(): WebAdminAuthenticationService
    {
        return $this->inner->authentication();
    }

    public function authorization(): WebAdminAuthorizationService
    {
        return $this->inner->authorization();
    }

    public function structuredEditor(): BlogStructuredEditorService
    {
        return $this->inner->structuredEditor();
    }

    public function editorMediaCatalog(): BlogEditorMediaCatalogInterface
    {
        return $this->inner->editorMediaCatalog();
    }

    public function editorImageResolver(): BlogImageResolverInterface
    {
        return $this->inner->editorImageResolver();
    }

    public function mutationGate(
        string $sessionToken,
        string $csrfToken,
        string $capability
    ): Closure {
        return $this->inner->mutationGate(
            $sessionToken,
            $csrfToken,
            $capability
        );
    }

    public function mutationGateAll(
        string $sessionToken,
        string $csrfToken,
        array $capabilities
    ): Closure {
        return $this->inner->mutationGateAll(
            $sessionToken,
            $csrfToken,
            $capabilities
        );
    }
}

final class BlogEditorPreferencesEditorIntegrationTest extends TestCase
{
    public function testRendererTransportsTheCatalogAndCompleteDefaults(): void
    {
        $document = $this->document();
        $defaults = $this->customHeadingDefaults();
        $html = (new BlogStructuredEditorHtmlRenderer())->render(
            '/admin/blog',
            'csrf-token-safe',
            $this->variant($document),
            $document,
            (new BlogDocumentCodec())->encode($document),
            headingDefaults: $defaults
        );

        self::assertSame(
            BlogHeadingPresetCatalog::defaults()->toSafeArray(),
            $this->jsonDataAttribute($html, 'blog-heading-presets')
        );
        self::assertSame(
            $defaults,
            $this->jsonDataAttribute($html, 'blog-heading-defaults')
        );
        foreach (['h2', 'h3', 'h4', 'h5', 'h6'] as $level) {
            self::assertSame(
                [
                    'preset',
                    'font_size',
                    'font_weight',
                    'text_color',
                    'text_align',
                ],
                array_keys($defaults[$level])
            );
        }
    }

    public function testControllerUsesReadyPreferencesAndFallsBackSafely(): void
    {
        $preferences = new BlogEditorPreferences(
            $this->customHeadingDefaults()
        );
        $state = BlogEditorPreferencesState::fallback($preferences);
        $working = new BlogEditorPreferencesService(
            new EditorPreferencesMemoryRepository($state)
        );
        $failing = new BlogEditorPreferencesService(
            new EditorPreferencesMemoryRepository(null, true)
        );

        self::assertSame(
            $preferences->headingDefaults(),
            $this->resolvedControllerDefaults(
                $this->runtime($working)
            )
        );
        self::assertSame(
            [],
            $this->resolvedControllerDefaults($this->runtime())
        );
        self::assertSame(
            [],
            $this->resolvedControllerDefaults(
                new LegacyStructuredEditorRuntime($this->runtime())
            )
        );
        self::assertSame(
            [],
            $this->resolvedControllerDefaults($this->runtime($failing))
        );
    }

    public function testRuntimeKeepsLegacyConstructionAndGuardsOptionalService(
    ): void {
        $legacy = $this->runtime();

        self::assertFalse($legacy->editorPreferencesReady());
        try {
            $legacy->editorPreferences();
            self::fail('The absent optional service must remain guarded.');
        } catch (BlogAdminHttpRuntimeException $exception) {
            self::assertSame(
                'blog.editor_preferences_unavailable',
                $exception->issueCode()
            );
        }

        $service = new BlogEditorPreferencesService(
            new EditorPreferencesMemoryRepository(null)
        );
        $ready = $this->runtime($service);

        self::assertTrue($ready->editorPreferencesReady());
        self::assertSame($service, $ready->editorPreferences());
    }

    /** @return array<string, array<string, string>> */
    private function resolvedControllerDefaults(
        BlogStructuredEditorHttpRuntimeInterface $runtime
    ): array {
        $controller = new BlogStructuredEditorHttpController($runtime);
        $method = new ReflectionMethod($controller, 'headingDefaults');
        $method->setAccessible(true);

        $result = $method->invoke($controller);
        self::assertIsArray($result);

        return $result;
    }

    private function runtime(
        ?BlogEditorPreferencesService $preferences = null
    ): BlogAdminHttpRuntime {
        $imageResolver = new class implements BlogImageResolverInterface {
            public function resolve(
                string $mediaAssetPublicId
            ): ?BlogResolvedImage {
                return null;
            }
        };

        return new BlogAdminHttpRuntime(
            __DIR__,
            ['es'],
            BlogConfig::defaults(['es']),
            WebAdminConfig::defaults(),
            $this->uninitialized(BlogService::class),
            $this->uninitialized(WebAdminAuthenticationService::class),
            $this->uninitialized(WebAdminAuthorizationService::class),
            new PDO('sqlite::memory:'),
            $this->uninitialized(WebAdminMutationActorGate::class),
            editorImageResolver: $imageResolver,
            optionalEditorPreferences: $preferences
        );
    }

    /** @template T of object @param class-string<T> $class @return T */
    private function uninitialized(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    /** @return array<string, mixed> */
    private function jsonDataAttribute(string $html, string $name): array
    {
        self::assertSame(1, preg_match(
            '/\\sdata-' . preg_quote($name, '/') . '="([^"]*)"/',
            $html,
            $match
        ));
        $decoded = html_entity_decode(
            $match[1],
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $value = json_decode($decoded, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($value);

        return $value;
    }

    /** @return array<string, array<string, string>> */
    private function customHeadingDefaults(): array
    {
        return [
            'h2' => $this->style(
                BlogHeadingPresetCatalog::MODULE_H2_TYPE01,
                'xlarge',
                'bold',
                'color02',
                'center'
            ),
            'h3' => $this->style(
                BlogHeadingPresetCatalog::MODULE_H2_TYPE02,
                'large',
                'semibold',
                'color03',
                'start'
            ),
            'h4' => $this->style(
                'default', 'small', 'medium', 'color01', 'end'
            ),
            'h5' => $this->style(
                'default', 'default', 'regular', 'color00', 'justify'
            ),
            'h6' => $this->style(
                'default', 'default', 'default', 'default', 'start'
            ),
        ];
    }

    /** @return array<string, string> */
    private function style(
        string $preset,
        string $fontSize,
        string $fontWeight,
        string $textColor,
        string $textAlign
    ): array {
        return [
            'preset' => $preset,
            'font_size' => $fontSize,
            'font_weight' => $fontWeight,
            'text_color' => $textColor,
            'text_align' => $textAlign,
        ];
    }

    private function document(): BlogDocument
    {
        return BlogDocument::fromArray([
            'schema' => BlogDocument::SCHEMA,
            'version' => BlogDocument::VERSION,
            'template' => 'article-basic-01',
            'blocks' => [[
                'id' => $this->id(1),
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'Matrix body.',
                    'marks' => [],
                ]],
            ]],
        ]);
    }

    private function variant(BlogDocument $document): BlogPostVariant
    {
        $now = new DateTimeImmutable('2026-08-05T10:00:00Z');

        return new BlogPostVariant(
            $this->id(100_001),
            $this->id(200_001),
            'es',
            new BlogDraft(
                'Matrix heading',
                (new BlogDocumentTextProjector())->project($document),
                'matrix-heading',
                'Matrix SEO title',
                'Matrix meta description',
                'Matrix excerpt'
            ),
            BlogPostVariant::DRAFT,
            null,
            1,
            $this->id(300_001),
            $this->id(300_002),
            $now,
            $now
        );
    }

    private function id(int $number): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $number);
    }
}
