<?php

declare(strict_types=1);

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostSummary;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogService;
use App\Core\Blog\Http\BlogAdminHtmlRenderer;
use PHPUnit\Framework\TestCase;

final class BlogAdminHtmlRendererTest extends TestCase
{
    public function testListIsAccessibleEscapedAndUsesPublicIdentifiers(): void
    {
        $now = new DateTimeImmutable('2026-08-01T10:00:00Z');
        $summary = new BlogPostSummary(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            'es',
            'matrix',
            'Matrix & "agents"',
            BlogPostVariant::DRAFT,
            null,
            2,
            $now
        );
        $html = (new BlogAdminHtmlRenderer())->index(
            '/admin/blog',
            [$summary],
            true,
            canPublish: true,
            canViewMedia: true
        );

        self::assertStringContainsString('<table>', $html);
        self::assertStringContainsString(
            'Matrix &amp; &quot;agents&quot;',
            $html
        );
        self::assertStringContainsString(
            'post=11111111-1111-4111-8111-111111111111&amp;locale=es',
            $html
        );
        self::assertStringContainsString('/admin/blog/editor/preview', $html);
        self::assertStringContainsString('/admin/blog/editor?', $html);
        self::assertStringContainsString('Vista previa guardada', $html);
        self::assertStringContainsString('/admin/blog/posts/new', $html);
    }

    public function testEditFormCarriesCsrfVersionAndPlainTextEscaped(): void
    {
        $now = new DateTimeImmutable('2026-08-01T10:00:00Z');
        $variant = new BlogPostVariant(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            'es',
            new BlogDraft(
                'Matrix',
                'Safe & plain',
                'matrix',
                'SEO title',
                'Description',
                'Excerpt'
            ),
            BlogPostVariant::DRAFT,
            null,
            7,
            '33333333-3333-4333-8333-333333333333',
            '33333333-3333-4333-8333-333333333333',
            $now,
            $now
        );
        $html = (new BlogAdminHtmlRenderer())->editForm(
            '/admin/blog',
            'csrf-token-safe',
            $variant,
            true
        );

        self::assertStringContainsString('name="csrf" value="csrf-token-safe"', $html);
        self::assertStringContainsString('name="lock_version" value="7"', $html);
        self::assertStringContainsString('Safe &amp; plain', $html);
        self::assertStringContainsString('/admin/blog/posts/preview', $html);
        self::assertStringContainsString('versi&oacute;n guardada', $html);
        self::assertStringContainsString('/admin/blog/posts/publish', $html);
        self::assertStringNotContainsString('33333333-', $html);
    }

    public function testPrivatePreviewEscapesStoredContentWithoutPublicSeo(): void
    {
        $now = new DateTimeImmutable('2026-08-01T10:00:00Z');
        $variant = new BlogPostVariant(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            'es',
            new BlogDraft(
                'Matrix & "agents"',
                "Primer & principal.\n\nSegundo \"final\".",
                'private-matrix',
                'SEO title must stay private',
                'SEO description must stay private',
                'Extracto & "safe"'
            ),
            BlogPostVariant::DRAFT,
            null,
            7,
            '33333333-3333-4333-8333-333333333333',
            '33333333-3333-4333-8333-333333333333',
            $now,
            $now
        );

        $html = (new BlogAdminHtmlRenderer())->preview(
            '/admin/blog',
            $variant,
            true
        );

        self::assertStringContainsString(
            '<article lang="es" aria-labelledby="blog-preview-title">',
            $html
        );
        self::assertStringContainsString(
            'Matrix &amp; &quot;agents&quot;',
            $html
        );
        self::assertStringContainsString(
            '<p>Primer &amp; principal.</p>',
            $html
        );
        self::assertStringContainsString(
            '<p>Segundo &quot;final&quot;.</p>',
            $html
        );
        self::assertStringContainsString('/admin/blog/editor?', $html);
        self::assertStringNotContainsString('private-matrix', $html);
        self::assertStringNotContainsString('SEO title must stay private', $html);
        self::assertStringNotContainsString(
            'SEO description must stay private',
            $html
        );
        self::assertStringNotContainsString('rel="canonical"', $html);
        self::assertStringNotContainsString(
            '<meta name="description"',
            $html
        );
    }

    public function testReadOnlyIncompleteDraftPreviewHasSafeEmptyState(): void
    {
        $now = new DateTimeImmutable('2026-08-01T10:00:00Z');
        $variant = new BlogPostVariant(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            'en',
            new BlogDraft('The Matrix', ''),
            BlogPostVariant::DRAFT,
            null,
            1,
            '33333333-3333-4333-8333-333333333333',
            '33333333-3333-4333-8333-333333333333',
            $now,
            $now
        );

        $html = (new BlogAdminHtmlRenderer())->preview(
            '/admin/blog',
            $variant,
            false
        );

        self::assertStringContainsString('article lang="en"', $html);
        self::assertStringContainsString('todav&iacute;a no tiene', $html);
        self::assertStringContainsString('Estado: Borrador', $html);
        self::assertStringNotContainsString('/posts/edit', $html);
        self::assertStringContainsString('Volver al Blog', $html);
    }

    public function testCreateFormSupportsNewAggregateAndExistingPost(): void
    {
        $renderer = new BlogAdminHtmlRenderer();
        $new = $renderer->createForm(
            '/admin/blog',
            'csrf-token',
            ['es', 'en']
        );
        self::assertStringContainsString('name="post" value=""', $new);
        self::assertSame(2, substr_count($new, '<option'));

        $existing = $renderer->createForm(
            '/admin/blog',
            'csrf-token',
            ['es'],
            '11111111-1111-4111-8111-111111111111'
        );
        self::assertStringContainsString(
            'name="post" value="11111111-1111-4111-8111-111111111111"',
            $existing
        );
    }

    public function testReadOnlyListDoesNotExposeMutationLinks(): void
    {
        $now = new DateTimeImmutable('2026-08-01T10:00:00Z');
        $summary = new BlogPostSummary(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            'es',
            'matrix',
            'Matrix',
            BlogPostVariant::DRAFT,
            null,
            1,
            $now
        );

        $html = (new BlogAdminHtmlRenderer())->index(
            '/admin/blog',
            [$summary],
            false,
            canViewMedia: true
        );

        self::assertStringContainsString('Solo lectura', $html);
        self::assertStringContainsString('/editor/preview', $html);
        self::assertStringNotContainsString('/editor?', $html);
        self::assertStringNotContainsString('/posts/new', $html);
    }

    public function testListActionsMatchStateAndEffectiveCapabilities(): void
    {
        $now = new DateTimeImmutable('2026-08-01T10:00:00Z');
        $draft = new BlogPostSummary(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            'es',
            'matrix-draft',
            'Matrix borrador',
            BlogPostVariant::DRAFT,
            null,
            2,
            $now
        );
        $published = new BlogPostSummary(
            '33333333-3333-4333-8333-333333333333',
            '44444444-4444-4444-8444-444444444444',
            'es',
            'matrix-published',
            'Matrix publicada',
            BlogPostVariant::PUBLISHED,
            $now,
            3,
            $now
        );
        $renderer = new BlogAdminHtmlRenderer();

        $editor = $renderer->index(
            '/admin/blog',
            [$draft, $published],
            true,
            canPublish: false,
            canViewMedia: true
        );
        self::assertStringContainsString(
            '/admin/blog/editor?post=11111111-1111-4111-8111-111111111111'
                . '&amp;locale=es',
            $editor
        );
        self::assertStringNotContainsString(
            '/admin/blog/editor?post=33333333-3333-4333-8333-333333333333'
                . '&amp;locale=es',
            $editor
        );
        self::assertSame(2, substr_count(
            $editor,
            '/admin/blog/editor/preview?'
        ));

        $publisher = $renderer->index(
            '/admin/blog',
            [$draft, $published],
            true,
            canPublish: true,
            canViewMedia: true
        );
        self::assertStringContainsString(
            '/admin/blog/editor?post=33333333-3333-4333-8333-333333333333'
                . '&amp;locale=es',
            $publisher
        );

        $withoutMedia = $renderer->index(
            '/admin/blog',
            [$draft, $published],
            true,
            canPublish: true,
            canViewMedia: false
        );
        self::assertSame(2, substr_count(
            $withoutMedia,
            '/admin/blog/posts/preview?'
        ));
        self::assertStringNotContainsString('/admin/blog/editor?', $withoutMedia);
        self::assertStringNotContainsString(
            '/admin/blog/editor/preview?',
            $withoutMedia
        );
        self::assertStringNotContainsString(
            '/admin/blog/posts/new',
            $withoutMedia
        );
    }

    public function testPublishedVariantMustBeWithdrawnBeforeEditing(): void
    {
        $now = new DateTimeImmutable('2026-08-01T10:00:00Z');
        $variant = new BlogPostVariant(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            'es',
            new BlogDraft(
                'Matrix',
                'Published body',
                'matrix',
                'Matrix title',
                'Matrix description',
                'Matrix excerpt'
            ),
            BlogPostVariant::PUBLISHED,
            $now,
            3,
            '33333333-3333-4333-8333-333333333333',
            '33333333-3333-4333-8333-333333333333',
            $now,
            $now
        );

        $html = (new BlogAdminHtmlRenderer())->editForm(
            '/admin/blog',
            'csrf-token',
            $variant,
            true
        );

        self::assertStringContainsString('Retira la variante', $html);
        self::assertStringContainsString(' readonly', $html);
        self::assertStringContainsString('/posts/unpublish', $html);
        self::assertStringNotContainsString('/posts/save', $html);
        self::assertStringNotContainsString('Guardar cambios', $html);
    }

    public function testPaginationIsAccessibleBoundedAndOmitsZeroOffset(): void
    {
        $renderer = new BlogAdminHtmlRenderer();
        $first = $renderer->index(
            '/admin/blog',
            [],
            true,
            0,
            true
        );
        self::assertStringContainsString(
            'aria-label="Paginaci&oacute;n de art&iacute;culos"',
            $first
        );
        self::assertStringContainsString(
            'rel="next" href="/admin/blog?offset=50"',
            $first
        );
        self::assertStringNotContainsString('rel="prev"', $first);

        $last = $renderer->index(
            '/admin/blog',
            [],
            true,
            50,
            false
        );
        self::assertStringContainsString(
            'rel="prev" href="/admin/blog"',
            $last
        );
        self::assertStringNotContainsString('offset=0', $last);
        self::assertStringNotContainsString('rel="next"', $last);

        $bounded = $renderer->index(
            '/admin/blog',
            [],
            true,
            BlogService::MAX_LIST_OFFSET,
            true
        );
        self::assertStringNotContainsString('rel="next"', $bounded);
    }
}
