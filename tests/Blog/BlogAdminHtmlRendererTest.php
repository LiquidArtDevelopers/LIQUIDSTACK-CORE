<?php

declare(strict_types=1);

use App\Core\Blog\Admin\BlogAdminCatalogQuery;
use App\Core\Blog\Analytics\BlogArticleAnalyticsSummary;
use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostSummary;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogService;
use App\Core\Blog\Http\BlogAdminHtmlRenderer;
use App\Core\Blog\Http\BlogLocalePresentation;
use App\Core\Blog\Seo\BlogRobotsPreferences;
use App\Core\Blog\Seo\BlogUrlResolution;
use App\Core\WebAdmin\Profile\WebAdminPublicProfile;
use App\Core\WebAdmin\Profile\WebAdminTimeZone;
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
        self::assertStringContainsString(
            'data-blog-private-preview data-blog-preview-title="Matrix '
                . '&amp; &quot;agents&quot;"',
            $html
        );
        self::assertStringNotContainsString('target="_blank"', $html);
        self::assertStringContainsString('/admin/blog/editor?', $html);
        self::assertStringContainsString('Vista previa', $html);
        self::assertStringContainsString('aria-label="Editar"', $html);
        self::assertStringContainsString(
            '<span class="webadmin-srOnly">Editar</span>',
            $html
        );
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
        self::assertStringContainsString(
            '<p class="blogAdminPage__primaryAction"><a class="'
                . 'webadminAction webadminAction--primary" '
                . 'href="/admin/blog/posts/new">Crear art&iacute;culo</a></p>',
            $html
        );
        self::assertStringNotContainsString(
            'Volver a la gesti&oacute;n web',
            $html
        );
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
        self::assertStringContainsString(
            'lectura privada del contenido guardado',
            $html
        );
        self::assertStringContainsString(
            'sin medios ni estilos p&uacute;blicos',
            $html
        );
        self::assertStringContainsString('/admin/blog/posts/publish', $html);
        self::assertStringNotContainsString('33333333-', $html);
        self::assertStringContainsString(
            '<button class="webadminAction webadminAction--primary" '
                . 'type="submit">Guardar cambios</button>',
            $html
        );
        self::assertStringContainsString(
            '<button class="webadminAction webadminAction--primary" '
                . 'type="submit">Publicar</button>',
            $html
        );
        self::assertStringContainsString(
            '<a class="webadminAction webadminAction--secondary" '
                . 'href="/admin/blog/posts/preview?',
            $html
        );

        $privateWorkflowHtml = (new BlogAdminHtmlRenderer())->editForm(
            '/admin/blog',
            'csrf-token-safe',
            $variant,
            true,
            privateDraftPublicationReady: true
        );
        self::assertStringNotContainsString(
            '/admin/blog/posts/publish',
            $privateWorkflowHtml
        );
        self::assertStringContainsString(
            '/admin/blog/editor?post=11111111-1111-4111-8111-111111111111'
                . '&amp;locale=es',
            $privateWorkflowHtml
        );
        self::assertStringContainsString(
            'Editar y publicar desde el editor visual',
            $privateWorkflowHtml
        );
        self::assertStringContainsString(
            '<a class="webadminAction webadminAction--primary" ',
            $privateWorkflowHtml
        );
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
        self::assertStringContainsString(
            'Lectura privada del contenido guardado',
            $html
        );
        self::assertStringContainsString(
            'representaci&oacute;n textual sin medios ni estilos '
                . 'p&uacute;blicos',
            $html
        );
        self::assertStringNotContainsString('Vista previa privada', $html);
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
        self::assertStringContainsString(
            '<button class="webadminAction webadminAction--primary" '
                . 'type="submit">Crear borrador y abrir editor</button>',
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
        self::assertStringContainsString(
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
            'data-blog-private-preview',
            $withoutMedia
        );
        self::assertSame(2, substr_count(
            $withoutMedia,
            'aria-label="Lectura textual del contenido guardado"'
        ));
        self::assertStringNotContainsString(
            'aria-label="Vista previa"',
            $withoutMedia
        );
        self::assertStringNotContainsString('target="_blank"', $withoutMedia);
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
            canDuplicate: true,
            localesByPost: [
                '11111111-1111-4111-8111-111111111111' => ['eu', 'en'],
                '33333333-3333-4333-8333-333333333333' => ['en'],
            ],
            canAddLocalization: true
        );

        self::assertStringContainsString('aria-label="Vista previa"', $html);
        self::assertStringContainsString(
            'data-blog-private-preview data-blog-preview-title="Matrix '
                . 'zirriborroa"',
            $html
        );
        self::assertStringContainsString(
            'href="/en/news/neo-awakens" target="_blank" rel="noopener"',
            $html
        );
        self::assertStringContainsString('aria-label="Vista web"', $html);
        self::assertSame(2, substr_count(
            $html,
            'action="/admin/blog/posts/duplicate"'
        ));
        self::assertSame(2, preg_match_all(
            '/name="operation_id" value="[0-9a-f]{8}-[0-9a-f]{4}-4'
                . '[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}" '
                . 'data-blog-language-operation>/',
            $html
        ));
        self::assertSame(2, preg_match_all(
            '/<form[^>]+action="\/admin\/blog\/posts\/duplicate"[^>]*>'
                . '.*?name="operation_id"/s',
            $html
        ));
        self::assertSame(2, preg_match_all(
            '/<form class="blogAdminPage__inlineAction".*?<\/form>/s',
            $html,
            $inlineForms
        ));
        foreach ($inlineForms[0] as $inlineForm) {
            self::assertStringNotContainsString('name="operation_id"',
                $inlineForm);
        }
        self::assertSame(1, substr_count(
            $html,
            'action="/admin/blog/posts/trash"'
        ));
        self::assertSame(1, substr_count(
            $html,
            'action="/admin/blog/posts/unpublish"'
        ));
        self::assertSame(4, substr_count(
            $html,
            'name="destination_locale"'
        ));
        self::assertSame(4, preg_match_all(
            '/data-blog-language-operation-id="([0-9a-f]{8}-[0-9a-f]{4}-4'
                . '[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})"/',
            $html,
            $optionOperationIds
        ));
        self::assertCount(4, array_unique($optionOperationIds[1]));
        self::assertStringContainsString(
            'aria-label="Duplicar o a&ntilde;adir idioma"',
            $html
        );
        self::assertMatchesRegularExpression(
            '/name="destination_locale" value="en"[^>]* disabled/',
            $html
        );
        self::assertStringContainsString(
            'Ya existe una variante activa o en la Papelera; '
                . 'rest&aacute;urala si procede.',
            $html
        );
        self::assertStringContainsString(
            'data-blog-language-outcome-status',
            $html
        );
        self::assertMatchesRegularExpression(
            '/<input type="radio"[^>]+aria-labelledby="([^"]+)" '
                . 'aria-describedby="([^"]+)"[^>]*>.*?'
                . '<span class="blogAdminPage__locale" id="\\1">.*?'
                . '<small id="\\2">/s',
            $html
        );
        self::assertStringContainsString(
            'class="blogAdminPage__languageActions webadminActionGroup"',
            $html
        );
        self::assertStringContainsString(
            '<button class="webadminAction webadminAction--primary" '
                . 'type="submit" data-blog-language-submit>',
            $html
        );
        self::assertStringContainsString(
            '<button class="webadminAction webadminAction--secondary" '
                . 'type="button" hidden data-blog-language-close>',
            $html
        );
        self::assertStringContainsString('aria-label="Borrar"', $html);
        self::assertStringContainsString('aria-label="Retirar"', $html);
        self::assertStringNotContainsString('a&amp;ntilde;adir', $html);
        self::assertStringContainsString(
            '<strong>Retirar</strong> despublica y conserva contenido',
            $html
        );
        self::assertStringContainsString(
            'data-blog-confirm-action="trash"',
            $html
        );
        self::assertStringContainsString(
            'data-blog-confirm-action="unpublish"',
            $html
        );
        self::assertStringNotContainsString('Retira primero</small>', $html);
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

    public function testCatalogShowsAccessibleEditorialStatusIndicators(): void
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
            1,
            $now
        );
        $published = new BlogPostSummary(
            '33333333-3333-4333-8333-333333333333',
            '44444444-4444-4444-8444-444444444444',
            'es',
            'matrix-published',
            'Matrix publicado',
            BlogPostVariant::PUBLISHED,
            $now,
            2,
            $now
        );

        $html = (new BlogAdminHtmlRenderer())->index(
            '/admin/blog',
            [$draft, $published],
            false
        );

        self::assertStringContainsString(
            '<span class="blogAdminPage__postStatus '
                . 'blogAdminPage__postStatus--draft"><span class="'
                . 'blogAdminPage__postStatusLed" aria-hidden="true"></span>'
                . '<span>Borrador</span></span>',
            $html
        );
        self::assertStringContainsString(
            '<span class="blogAdminPage__postStatus '
                . 'blogAdminPage__postStatus--published"><span class="'
                . 'blogAdminPage__postStatusLed" aria-hidden="true"></span>'
                . '<span>Publicado</span></span>',
            $html
        );
        self::assertSame(2, substr_count(
            $html,
            'class="blogAdminPage__postStatusLed" aria-hidden="true"'
        ));
        self::assertStringContainsString('sort=status', $html);
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
        $profile = new WebAdminPublicProfile(
            '33333333-3333-4333-8333-333333333333',
            'Editora de ejemplo',
            'editor',
            'Editor',
            WebAdminTimeZone::fromIana('Europe/Madrid'),
            true,
            3
        );

        $html = (new BlogAdminHtmlRenderer())->trash(
            '/admin/blog',
            [$summary],
            0,
            false,
            'csrf-trash',
            viewerProfile: $profile
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
        self::assertStringContainsString('aria-label="Restaurar"', $html);
        self::assertStringContainsString(
            '<th scope="col">Estado</th>',
            $html
        );
        self::assertStringContainsString(
            '<span class="blogAdminPage__postStatus '
                . 'blogAdminPage__postStatus--deleted"><span class="'
                . 'blogAdminPage__postStatusLed" aria-hidden="true"></span>'
                . '<span>Eliminado</span></span>',
            $html
        );
        self::assertStringContainsString(
            '<span class="webadmin-srOnly">Restaurar</span>',
            $html
        );
        self::assertStringContainsString('12:00 · 01/08/2026', $html);
    }

    public function testEmptyTrashKeepsTheFiveColumnTableContract(): void
    {
        $html = (new BlogAdminHtmlRenderer())->trash(
            '/admin/blog',
            [],
            0,
            false,
            'csrf-trash'
        );

        self::assertStringContainsString('<th scope="col">Estado</th>', $html);
        self::assertStringContainsString(
            '<td colspan="5">La papelera est&aacute; vac&iacute;a.</td>',
            $html
        );
    }

    public function testAdminDatesUseTheLiveViewerProfileTimeZone(): void
    {
        $summary = new BlogPostSummary(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            'es',
            'matrix',
            'Matrix localizada',
            BlogPostVariant::DRAFT,
            null,
            2,
            new DateTimeImmutable('2026-08-07T09:35:00Z')
        );
        $profile = new WebAdminPublicProfile(
            '33333333-3333-4333-8333-333333333333',
            'Editora de ejemplo',
            'editor',
            'Editor',
            WebAdminTimeZone::fromIana('Europe/Madrid'),
            true,
            3
        );

        $html = (new BlogAdminHtmlRenderer())->index(
            '/admin/blog',
            [$summary],
            false,
            viewerProfile: $profile
        );

        self::assertStringContainsString('11:35 · 07/08/2026', $html);
        self::assertStringNotContainsString('7 de agosto de 2026', $html);
        self::assertStringNotContainsString('2026-08-07 09:35', $html);
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
            $now,
            'Trinity & Neo',
            ['Noticias', 'Matrix & Zion'],
            BlogRobotsPreferences::noIndexNoFollow()
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
            'Autor',
            'Categor&iacute;as',
            'Index / Follow',
            'Trinity &amp; Neo',
            '<ul class="blogAdminPage__categoryStack"><li>Noticias</li>'
                . '<li>Matrix &amp; Zion</li></ul>',
            'aria-label="Index desactivado"',
            'aria-label="Follow desactivado"',
            'blogAdminPage__statusIcon--disabled',
            'title="Vistas"',
            '<span class="webadmin-srOnly">Vistas</span>',
            'title="Visitantes únicos"',
            'title="Habituales"',
            'Vistas',
            'Visitantes únicos',
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
            '?period=90&amp;offset=20',
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
        self::assertStringContainsString(
            'action="/admin/blog/posts/unpublish" data-blog-confirm-form '
                . 'data-blog-confirm-action="unpublish" '
                . 'data-blog-title="Matrix"',
            $html
        );
        self::assertStringNotContainsString('/posts/save', $html);
        self::assertStringNotContainsString('Guardar cambios', $html);
        self::assertStringContainsString(
            '<button class="webadminAction webadminAction--danger" '
                . 'type="submit">Retirar</button>',
            $html
        );
    }

    public function testCatalogFiltersAreNativeGetAndPaginationPreservesThem(): void
    {
        $query = new BlogAdminCatalogQuery(
            search: 'Matrix & agents',
            status: BlogPostVariant::DRAFT,
            locale: 'eu',
            offset: 40,
            sort: BlogAdminCatalogQuery::SORT_TITLE,
            direction: BlogAdminCatalogQuery::DIRECTION_ASC
        );
        $html = (new BlogAdminHtmlRenderer())->index(
            basePath: '/admin/blog',
            summaries: [],
            canEdit: true,
            offset: 40,
            hasNext: true,
            publicPaths: [
                'es' => '/es/noticias',
                'eu' => '/eu/albisteak',
            ],
            showAnalytics: true,
            analyticsPeriodDays: 90,
            catalogQuery: $query
        );

        self::assertStringContainsString(
            'method="get" action="/admin/blog" data-blog-admin-filter-form',
            $html
        );
        self::assertStringContainsString(
            '<button class="webadminAction webadminAction--primary" '
                . 'type="submit">Aplicar filtros</button>',
            $html
        );
        self::assertStringContainsString(
            '<a class="webadminAction webadminAction--secondary" '
                . 'href="/admin/blog" data-blog-admin-filter-reset>'
                . 'Limpiar filtros</a>',
            $html
        );
        self::assertStringContainsString(
            'name="q" value="Matrix &amp; agents"',
            $html
        );
        self::assertStringContainsString(
            '<option value="draft" selected>Borrador</option>',
            $html
        );
        self::assertStringContainsString(
            '<option value="eu" selected>EU</option>',
            $html
        );
        self::assertStringContainsString(
            'name="per_page" aria-controls="blog-admin-results"'
                . '><option value="10">10</option><option value="20" selected>',
            $html
        );
        self::assertStringContainsString(
            '<input type="hidden" name="sort" value="title">',
            $html
        );
        self::assertStringContainsString(
            '<input type="hidden" name="dir" value="asc">',
            $html
        );
        self::assertStringContainsString(
            '<th scope="col" aria-sort="ascending"><a class="'
                . 'blogAdminPage__sort"',
            $html
        );
        self::assertStringContainsString(
            'title="No ordenable: un art&iacute;culo puede pertenecer a varias '
                . 'categor&iacute;as">Categor&iacute;as</th>',
            $html
        );
        self::assertStringNotContainsString('sort=categories', $html);
        self::assertStringContainsString(
            'data-blog-admin-results data-blog-admin-result-count="0"',
            $html
        );
        self::assertStringContainsString(
            'No hay art&iacute;culos que coincidan con los filtros.',
            $html
        );
        self::assertStringContainsString(
            'data-blog-admin-pagination',
            $html
        );
        self::assertStringContainsString(
            'rel="prev" href="/admin/blog?q=Matrix%20%26%20agents'
                . '&amp;status=draft&amp;locale=eu&amp;sort=title&amp;dir=asc'
                . '&amp;period=90&amp;offset=20"',
            $html
        );
        self::assertStringContainsString(
            'rel="next" href="/admin/blog?q=Matrix%20%26%20agents'
                . '&amp;status=draft&amp;locale=eu&amp;sort=title&amp;dir=asc'
                . '&amp;period=90&amp;offset=60"',
            $html
        );
    }

    public function testRetiredUrlManagerExplainsAndProtectsSeoDecisions(): void
    {
        $now = new DateTimeImmutable('2026-08-01T10:00:00Z');
        $variant = new BlogPostVariant(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            'es',
            new BlogDraft('Matrix retirada', '', 'matrix-retirada'),
            BlogPostVariant::DRAFT,
            null,
            7,
            '33333333-3333-4333-8333-333333333333',
            '33333333-3333-4333-8333-333333333333',
            $now,
            $now
        );
        $replacement = new BlogPostSummary(
            '44444444-4444-4444-8444-444444444444',
            '55555555-5555-4555-8555-555555555555',
            'es',
            'matrix-equivalente',
            'Matrix equivalente',
            BlogPostVariant::PUBLISHED,
            $now,
            2,
            $now
        );
        $html = (new BlogAdminHtmlRenderer())->urlManager(
            '/admin/blog',
            'csrf-safe',
            $variant,
            new BlogUrlResolution(BlogUrlResolution::TEMPORARY_NOT_FOUND),
            [$replacement]
        );

        self::assertStringContainsString('responde 404', $html);
        self::assertStringContainsString('Mant&eacute;n el 404 temporal', $html);
        self::assertStringContainsString(
            'data-blog-confirm-action="gone"',
            $html
        );
        self::assertStringContainsString(
            'data-blog-confirm-action="redirect"',
            $html
        );
        self::assertStringContainsString(
            '<button class="webadminAction webadminAction--danger" '
                . 'type="submit">Marcar como 410</button>',
            $html
        );
        self::assertStringContainsString(
            '<button class="webadminAction webadminAction--primary" '
                . 'type="submit">Crear redirecci&oacute;n 301</button>',
            $html
        );
        self::assertStringContainsString('value="matrix-retirada"', $html);
        self::assertStringContainsString(
            'value="44444444-4444-4444-8444-444444444444"',
            $html
        );
        self::assertStringNotContainsString(
            'value="11111111-1111-4111-8111-111111111111">Matrix retirada',
            $html
        );
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
            'rel="next" href="/admin/blog?offset=20"',
            $first
        );
        self::assertDoesNotMatchRegularExpression(
            '/<nav class="blogAdminPage__pagination"[\s\S]*?'
                . 'class="webadminAction/',
            $first
        );
        self::assertStringNotContainsString('rel="prev"', $first);

        $last = $renderer->index(
            '/admin/blog',
            [],
            true,
            20,
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
