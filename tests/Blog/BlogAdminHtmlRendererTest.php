<?php

declare(strict_types=1);

use App\Core\Blog\Analytics\BlogArticleAnalyticsSummary;
use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostSummary;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogService;
use App\Core\Blog\Http\BlogAdminHtmlRenderer;
use App\Core\Blog\Http\BlogLocalePresentation;
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
        self::assertStringContainsString('Vista previa', $html);
        self::assertStringContainsString('>Editar</a>', $html);
        self::assertStringContainsString('T&iacute;tulo', $html);
        self::assertStringNotContainsString('scope="col">H1', $html);
        self::assertStringContainsString(
            '/assets/modules/blog/flags/es.svg',
            $html
        );
        self::assertStringContainsString(
            BlogLocalePresentation::label('es'),
            $html
        );
        self::assertStringContainsString(
            'blogAdminPage blogAdminPage--index',
            $html
        );
        self::assertStringContainsString(
            'blogAdminPage__tableViewport',
            $html
        );
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
        self::assertStringContainsString('name="locale" value="es"', $html);
        self::assertStringNotContainsString('name="locale" required', $html);
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
            [
                'es' => '/es/noticias',
                'en' => '/international/insights',
            ]
        );
        self::assertStringContainsString('name="post" value=""', $new);
        self::assertSame(3, substr_count($new, '<option'));
        self::assertStringContainsString(
            '<option value="" selected disabled>Selecciona un idioma</option>',
            $new
        );
        self::assertStringContainsString(
            '<option value="es">es &mdash; /es/noticias</option>',
            $new
        );
        self::assertStringContainsString(
            '<option value="en">en &mdash; /international/insights</option>',
            $new
        );

        $existing = $renderer->createForm(
            '/admin/blog',
            'csrf-token',
            ['eu' => '/eu/albisteak'],
            '11111111-1111-4111-8111-111111111111'
        );
        self::assertStringContainsString(
            'name="post" value="11111111-1111-4111-8111-111111111111"',
            $existing
        );
        self::assertStringNotContainsString('value="es"', $existing);
        self::assertStringContainsString(
            '<option value="eu">eu &mdash; /eu/albisteak</option>',
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

    public function testListDistinguishesPublicViewAndDraftPreviewAndOffersActions(): void
    {
        $now = new DateTimeImmutable('2026-08-01T10:00:00Z');
        $draft = new BlogPostSummary(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            'eu',
            'matrix-zirriborroa',
            'Matrix zirriborroa',
            BlogPostVariant::DRAFT,
            null,
            2,
            $now
        );
        $published = new BlogPostSummary(
            '33333333-3333-4333-8333-333333333333',
            '44444444-4444-4444-8444-444444444444',
            'en',
            'neo-awakens',
            'Neo awakens',
            BlogPostVariant::PUBLISHED,
            $now,
            4,
            $now
        );

        $html = (new BlogAdminHtmlRenderer())->index(
            '/admin/blog',
            [$draft, $published],
            true,
            canPublish: true,
            canViewMedia: true,
            publicPaths: [
                'eu' => '/eu/albisteak',
                'en' => '/en/news',
            ],
            csrf: 'csrf-safe',
            canDelete: true,
            canDuplicate: true
        );

        self::assertStringContainsString('>Vista previa</a>', $html);
        self::assertStringContainsString(
            'href="/en/news/neo-awakens"',
            $html
        );
        self::assertStringContainsString('>Vista web</a>', $html);
        self::assertSame(2, substr_count(
            $html,
            'action="/admin/blog/posts/duplicate"'
        ));
        self::assertSame(1, substr_count(
            $html,
            'action="/admin/blog/posts/trash"'
        ));
        self::assertStringContainsString('>Duplicar</button>', $html);
        self::assertStringContainsString('>Borrar</button>', $html);
        self::assertStringContainsString('Retira primero</small>', $html);
        self::assertStringContainsString(
            '/assets/modules/blog/flags/es-pv.svg',
            $html
        );
        self::assertStringContainsString('Euskera', $html);
        self::assertStringContainsString(
            '/assets/modules/blog/flags/gb.svg',
            $html
        );
        self::assertStringContainsString(
            BlogLocalePresentation::label('en'),
            $html
        );
    }

    public function testTrashListsRecoverableDraftsWithCsrfProtectedRestore(): void
    {
        $now = new DateTimeImmutable('2026-08-01T10:00:00Z');
        $summary = new BlogPostSummary(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            'es',
            'matrix',
            'Matrix recuperable',
            BlogPostVariant::DRAFT,
            null,
            7,
            $now
        );

        $html = (new BlogAdminHtmlRenderer())->trash(
            '/admin/blog',
            [$summary],
            0,
            false,
            'csrf-trash'
        );

        self::assertStringContainsString('Papelera del Blog', $html);
        self::assertStringContainsString('Matrix recuperable', $html);
        self::assertStringContainsString(
            'action="/admin/blog/posts/restore"',
            $html
        );
        self::assertStringContainsString(
            'name="csrf" value="csrf-trash"',
            $html
        );
        self::assertStringContainsString(
            'name="lock_version" value="7"',
            $html
        );
        self::assertStringContainsString('>Restaurar</button>', $html);
    }

    public function testListShowsConsentedAnalyticsWithExplicitBlogDefinitions(): void
    {
        $now = new DateTimeImmutable('2026-08-01T10:00:00Z');
        $summary = new BlogPostSummary(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            'es',
            'matrix',
            'Matrix',
            BlogPostVariant::PUBLISHED,
            $now,
            2,
            $now
        );
        $metric = new BlogArticleAnalyticsSummary(
            $summary->localizationPublicId(),
            125,
            80,
            21,
            65_000,
            50,
            38
        );

        $html = (new BlogAdminHtmlRenderer())->index(
            '/admin/blog',
            [$summary],
            true,
            canPublish: true,
            canViewMedia: true,
            analyticsByLocalization: [
                $summary->localizationPublicId() => $metric,
            ],
            showAnalytics: true,
            analyticsPeriodDays: 90,
            hasNext: true
        );

        foreach ([
            'Visitas',
            'Visitantes &uacute;nicos',
            'Habituales',
            'Interacci&oacute;n media',
            'Rebote del Blog',
            '>125</td>',
            '>80</td>',
            '>21</td>',
            '>1 min 05 s</td>',
            '>24,0%</td>',
            'visitantes que han aceptado',
            'no se guarda la IP',
            'id="blog-analytics-bounce-help"',
            '?period=90&amp;offset=50',
        ] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
    }

    public function testAnalyticsWithoutLandingSessionsDoesNotClaimZeroBounce(): void
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
        $metric = new BlogArticleAnalyticsSummary(
            $summary->localizationPublicId(),
            1,
            1,
            0,
            0,
            0,
            0
        );

        $html = (new BlogAdminHtmlRenderer())->index(
            '/admin/blog',
            [$summary],
            false,
            analyticsByLocalization: [
                $summary->localizationPublicId() => $metric,
            ],
            showAnalytics: true
        );

        self::assertStringContainsString(
            'aria-label="Sin sesiones de entrada">&mdash;',
            $html
        );
        self::assertStringNotContainsString('>0,0%</td>', $html);
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
