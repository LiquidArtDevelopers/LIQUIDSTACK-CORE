<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\Analytics\BlogArticleAnalyticsSummary;
use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostSummary;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogService;
use App\Core\WebAdmin\Http\WebAdminPageAssets;
use App\Core\WebAdmin\Http\WebAdminShellContext;
use App\Core\WebAdmin\Http\WebAdminShellRenderer;
use InvalidArgumentException;

final class BlogAdminHtmlRenderer
{
    private readonly WebAdminShellRenderer $shellRenderer;

    public function __construct(?WebAdminShellRenderer $shellRenderer = null)
    {
        $this->shellRenderer = $shellRenderer ?? new WebAdminShellRenderer();
    }

    /**
     * @param list<BlogPostSummary> $summaries
     * @param array<string, string> $publicPaths
     * @param array<string, BlogArticleAnalyticsSummary>
     *     $analyticsByLocalization
     */
    public function index(
        string $basePath,
        array $summaries,
        bool $canEdit,
        int $offset = 0,
        bool $hasNext = false,
        bool $canPublish = false,
        bool $canViewMedia = false,
        ?WebAdminShellContext $shell = null,
        array $publicPaths = [],
        string $csrf = '',
        bool $canDelete = false,
        bool $canDuplicate = false,
        array $analyticsByLocalization = [],
        bool $showAnalytics = false,
        int $analyticsPeriodDays = 30
    ): string {
        if (
            $offset < 0
            || $offset > BlogService::MAX_LIST_OFFSET
            || $offset % BlogService::DEFAULT_LIST_LIMIT !== 0
            || count($summaries) > BlogService::DEFAULT_LIST_LIMIT
        ) {
            throw new InvalidArgumentException(
                'Invalid Blog pagination presentation.'
            );
        }
        if (!in_array($analyticsPeriodDays, [7, 30, 90], true)) {
            throw new InvalidArgumentException(
                'Invalid Blog analytics period presentation.'
            );
        }
        $rows = '';
        foreach ($summaries as $summary) {
            if (!$summary instanceof BlogPostSummary) {
                throw new InvalidArgumentException(
                    'Invalid Blog summary presentation.'
                );
            }
            $preview = $this->pathWithQuery(
                $basePath,
                $canViewMedia ? '/editor/preview' : '/posts/preview',
                [
                    'post' => $summary->postPublicId(),
                    'locale' => $summary->locale(),
                ]
            );
            $isPublished = $summary->status()
                === BlogPostVariant::PUBLISHED;
            $publicPath = $publicPaths[$summary->locale()] ?? null;
            $slug = $summary->slug();
            if (
                $isPublished
                && is_string($publicPath)
                && $slug !== null
            ) {
                $viewHref = $this->publicArticlePath($publicPath, $slug);
                $viewLabel = 'Vista web';
            } else {
                $viewHref = $preview;
                $viewLabel = 'Vista previa';
            }
            $rowTitleId = 'blog-row-title-'
                . $summary->localizationPublicId();
            $actions = '<div class="blogAdminPage__rowActions">'
                . '<a class="blogAdminPage__action '
                . 'blogAdminPage__action--view" href="' . $viewHref
                . '" target="_blank" rel="noopener" aria-describedby="'
                . $rowTitleId . '">' . $viewLabel
                . '</a>';
            $canOpenEditor = $canEdit
                && $canViewMedia
                && (
                    $summary->status() === BlogPostVariant::DRAFT
                    || $canPublish
                );
            if ($canOpenEditor) {
                $edit = $this->pathWithQuery($basePath, '/editor', [
                    'post' => $summary->postPublicId(),
                    'locale' => $summary->locale(),
                ]);
                $actions .= '<a class="blogAdminPage__action '
                    . 'blogAdminPage__action--edit" href="' . $edit
                    . '" aria-describedby="' . $rowTitleId
                    . '">Editar</a>';
            } else {
                $actions .= '<span class="blogAdminPage__readOnly">'
                    . 'Solo lectura</span>';
            }
            if (
                $canDuplicate
                && $canEdit
                && $canViewMedia
                && $csrf !== ''
            ) {
                $actions .= $this->variantActionForm(
                    $basePath,
                    '/posts/duplicate',
                    $csrf,
                    $summary,
                    'Duplicar',
                    'blogAdminPage__action--duplicate'
                );
            }
            if (
                $canDelete
                && !$isPublished
                && $csrf !== ''
            ) {
                $actions .= $this->variantActionForm(
                    $basePath,
                    '/posts/trash',
                    $csrf,
                    $summary,
                    'Borrar',
                    'blogAdminPage__action--delete',
                    true
                );
            } elseif ($canDelete && $isPublished) {
                $actions .= '<span class="blogAdminPage__action '
                    . 'blogAdminPage__action--disabled" aria-disabled="true" '
                    . 'aria-describedby="' . $rowTitleId . '">'
                    . '<span>Borrar</span><small>Retira primero</small></span>';
            }
            $actions .= '</div>';

            $analyticsCells = '';
            if ($showAnalytics) {
                $metric = $analyticsByLocalization[
                    $summary->localizationPublicId()
                ] ?? null;
                if (
                    $metric !== null
                    && !$metric instanceof BlogArticleAnalyticsSummary
                ) {
                    throw new InvalidArgumentException(
                        'Invalid Blog analytics presentation.'
                    );
                }
                $analyticsCells = $this->analyticsCells($metric);
            }
            $rows .= '<tr><th id="' . $rowTitleId . '" scope="row">'
                . $this->escape($summary->h1()) . '</th><td>'
                . $this->localeBadge($summary->locale()) . '</td><td>'
                . $this->statusLabel($summary->status()) . '</td>'
                . $analyticsCells
                . '<td>'
                . $this->escape($summary->updatedAt()->format('Y-m-d H:i'))
                . '</td><td>' . $actions . '</td></tr>';
        }
        if ($rows === '') {
            $columns = $showAnalytics ? 10 : 5;
            $rows = '<tr><td colspan="' . $columns
                . '">No hay art&iacute;culos.</td></tr>';
        }
        $create = $canEdit && $canViewMedia
            ? '<p class="blogAdminPage__primaryAction"><a href="'
                . $this->path($basePath, '/posts/new')
                . '">Crear art&iacute;culo</a></p>'
            : '';
        $trash = $canDelete
            ? '<p class="blogAdminPage__trashLink"><a href="'
                . $this->path($basePath, '/trash')
                . '">Ver papelera</a></p>'
            : '';
        $analyticsHeaders = $showAnalytics
            ? '<th scope="col">Visitas</th>'
                . '<th scope="col">Visitantes &uacute;nicos</th>'
                . '<th scope="col">Habituales</th>'
                . '<th scope="col">Interacci&oacute;n media</th>'
                . '<th scope="col" aria-describedby="blog-analytics-bounce-help">'
                . 'Rebote del Blog</th>'
            : '';
        $analyticsFilter = $showAnalytics
            ? $this->analyticsPeriodFilter(
                $basePath,
                $analyticsPeriodDays
            )
            : '';

        return $this->page(
            'Art&iacute;culos del Blog',
            '<article class="blogAdminPage blogAdminPage--index" '
            . 'aria-labelledby="blog-admin-title">'
            . '<h1 id="blog-admin-title">Art&iacute;culos del Blog</h1>'
            . '<p>Gestiona cada idioma, consulta su rendimiento y abre la '
            . 'acci&oacute;n que necesitas sin alterar las dem&aacute;s variantes.</p>'
            . $create
            . $trash
            . $analyticsFilter
            . '<div class="blogAdminPage__tableViewport" tabindex="0" '
            . 'role="region" aria-label="Variantes editoriales">'
            . '<table><caption>Variantes editoriales</caption><thead><tr>'
            . '<th scope="col">T&iacute;tulo</th><th scope="col">Idioma</th>'
            . '<th scope="col">Estado</th>' . $analyticsHeaders
            . '<th scope="col">Actualizado</th>'
            . '<th scope="col">Acciones</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>'
            . $this->pagination(
                $basePath,
                $offset,
                $hasNext,
                $showAnalytics
                    ? ['period' => (string) $analyticsPeriodDays]
                    : []
            )
            . $this->backToDashboard($basePath)
            . '</article>',
            $basePath,
            '/blog',
            $shell
        );
    }

    /** @param list<BlogPostSummary> $summaries */
    public function trash(
        string $basePath,
        array $summaries,
        int $offset,
        bool $hasNext,
        string $csrf,
        ?WebAdminShellContext $shell = null
    ): string {
        if (
            $offset < 0
            || $offset > BlogService::MAX_LIST_OFFSET
            || $offset % BlogService::DEFAULT_LIST_LIMIT !== 0
            || count($summaries) > BlogService::DEFAULT_LIST_LIMIT
            || $csrf === ''
        ) {
            throw new InvalidArgumentException(
                'Invalid Blog trash presentation.'
            );
        }

        $rows = '';
        foreach ($summaries as $summary) {
            if (!$summary instanceof BlogPostSummary) {
                throw new InvalidArgumentException(
                    'Invalid Blog trash summary presentation.'
                );
            }
            $restore = $this->variantActionForm(
                $basePath,
                '/posts/restore',
                $csrf,
                $summary,
                'Restaurar',
                'blogAdminPage__action--edit'
            );
            $rows .= '<tr><th id="blog-row-title-'
                . $summary->localizationPublicId() . '" scope="row">'
                . $this->escape($summary->h1()) . '</th><td>'
                . $this->localeBadge($summary->locale()) . '</td><td>'
                . $this->escape($summary->updatedAt()->format('Y-m-d H:i'))
                . '</td><td><div class="blogAdminPage__rowActions">'
                . $restore . '</div></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">La papelera est&aacute; vac&iacute;a.'
                . '</td></tr>';
        }

        return $this->page(
            'Papelera del Blog',
            '<article class="blogAdminPage blogAdminPage--index" '
            . 'aria-labelledby="blog-trash-title">'
            . '<h1 id="blog-trash-title">Papelera del Blog</h1>'
            . '<p>Los borradores eliminados no se muestran en la web ni en '
            . 'el listado editorial y pueden recuperarse desde aqu&iacute;.</p>'
            . '<div class="blogAdminPage__tableViewport" tabindex="0" '
            . 'role="region" aria-label="Borradores eliminados">'
            . '<table><caption>Borradores eliminados</caption><thead><tr>'
            . '<th scope="col">T&iacute;tulo</th><th scope="col">Idioma</th>'
            . '<th scope="col">Actualizado</th><th scope="col">Acciones</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . $this->paginationForPath(
                $basePath . '/trash',
                $offset,
                $hasNext
            )
            . $this->backToBlog($basePath)
            . '</article>',
            $basePath,
            '/blog/trash',
            $shell
        );
    }

    private function localeBadge(string $locale): string
    {
        $label = BlogLocalePresentation::label($locale);
        $asset = BlogLocalePresentation::flagAsset($locale);
        $visual = $asset === null
            ? '<span class="blogAdminPage__localeFallback" aria-hidden="true">'
                . '&#9673;</span>'
            : '<img src="' . $this->escape($asset)
                . '" alt="" aria-hidden="true" width="24" height="18">';

        return '<span class="blogAdminPage__locale">' . $visual . '<span>'
            . $this->escape($label) . '</span></span>';
    }

    private function publicArticlePath(string $publicPath, string $slug): string
    {
        return $this->escape(
            rtrim($publicPath, '/') . '/' . rawurlencode($slug)
        );
    }

    private function variantActionForm(
        string $basePath,
        string $suffix,
        string $csrf,
        BlogPostSummary $summary,
        string $label,
        string $modifier,
        bool $requiresConfirmation = false
    ): string {
        $attributes = $requiresConfirmation
            ? ' data-blog-trash-form data-blog-title="'
                . $this->escape($summary->h1()) . '"'
            : '';

        return '<form class="blogAdminPage__inlineAction" method="post" '
            . 'action="' . $this->path($basePath, $suffix) . '"'
            . $attributes . '>'
            . $this->csrfInput($csrf)
            . '<input type="hidden" name="post" value="'
            . $this->escape($summary->postPublicId()) . '">'
            . '<input type="hidden" name="locale" value="'
            . $this->escape($summary->locale()) . '">'
            . '<input type="hidden" name="lock_version" value="'
            . $summary->lockVersion() . '">'
            . '<button class="blogAdminPage__action ' . $modifier
            . '" type="submit" aria-describedby="blog-row-title-'
            . $summary->localizationPublicId() . '">' . $label
            . '</button></form>';
    }

    private function analyticsPeriodFilter(
        string $basePath,
        int $periodDays
    ): string {
        $options = '';
        foreach ([7, 30, 90] as $days) {
            $options .= '<option value="' . $days . '"'
                . ($days === $periodDays ? ' selected' : '') . '>'
                . $days . ' d&iacute;as</option>';
        }

        return '<form class="blogAdminPage__analyticsFilter" method="get" '
            . 'action="' . $this->path($basePath, '') . '">'
            . '<label for="blog-analytics-period">M&eacute;tricas de los '
            . '&uacute;ltimos</label><select id="blog-analytics-period" '
            . 'name="period">' . $options . '</select>'
            . '<button type="submit">Aplicar</button></form>'
            . '<p class="blogAdminPage__analyticsNotice">Datos propios de '
            . 'visitantes que han aceptado la medici&oacute;n anal&iacute;tica. '
            . 'La identificaci&oacute;n es seud&oacute;nima por navegador; no se '
            . 'guarda la IP.'
            . '</p><p class="blogAdminPage__analyticsNotice" '
            . 'id="blog-analytics-bounce-help"><strong>Rebote del Blog:</strong> '
            . 'sesiones de entrada sin m&aacute;s de 10 segundos de '
            . 'interacci&oacute;n ni una segunda p&aacute;gina vista.</p>';
    }

    private function analyticsCells(
        ?BlogArticleAnalyticsSummary $metric
    ): string {
        if ($metric === null) {
            return str_repeat('<td><span aria-label="Sin datos">&mdash;</span></td>', 5);
        }

        $bounce = $metric->landingSessions() === 0
            ? '<span aria-label="Sin sesiones de entrada">&mdash;</span>'
            : $this->escape(number_format(
                $metric->bounceRatePercentage(),
                1,
                ',',
                ''
            )) . '%';

        return '<td>' . $metric->pageViews() . '</td><td>'
            . $metric->uniqueVisitors() . '</td><td>'
            . $metric->returningVisitors() . '</td><td>'
            . $this->formatDuration(
                $metric->averageEngagementMilliseconds()
            ) . '</td><td>'
            . $bounce . '</td>';
    }

    private function formatDuration(int $milliseconds): string
    {
        $seconds = intdiv(max(0, $milliseconds), 1000);
        if ($seconds < 60) {
            return $seconds . ' s';
        }

        return intdiv($seconds, 60) . ' min '
            . str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT)
            . ' s';
    }

    /** @param array<string, string> $localePublicPaths */
    public function createForm(
        string $basePath,
        string $csrf,
        array $localePublicPaths,
        ?string $postPublicId = null,
        bool $failed = false,
        ?WebAdminShellContext $shell = null
    ): string {
        $localeOptions = $this->localeOptions($localePublicPaths);

        return $this->page(
            $postPublicId === null
                ? 'Crear art&iacute;culo'
                : 'A&ntilde;adir idioma',
            '<article class="blogAdminPage" aria-labelledby="blog-create-title">'
            . '<h1 id="blog-create-title">'
            . ($postPublicId === null
                ? 'Crear art&iacute;culo'
                : 'A&ntilde;adir variante de idioma')
            . '</h1>' . $this->formError($failed)
            . '<form method="post" action="'
            . $this->path($basePath, '/posts/create') . '">'
            . $this->csrfInput($csrf)
            . '<input type="hidden" name="post" value="'
            . $this->escape($postPublicId ?? '') . '">'
            . '<div><label for="blog-locale">Idioma y ruta p&uacute;blica</label>'
            . '<select '
            . 'id="blog-locale" name="locale" required>'
            . '<option value="" selected disabled>Selecciona un idioma</option>'
            . $localeOptions . '</select></div>'
            . $this->creationFields()
            . '<button type="submit">Crear borrador y abrir editor</button>'
            . '</form>'
            . $this->backToBlog($basePath)
            . '</article>',
            $basePath,
            '/blog/posts/new',
            $shell
        );
    }

    public function editForm(
        string $basePath,
        string $csrf,
        BlogPostVariant $variant,
        bool $canPublish,
        bool $failed = false,
        bool $canAddLocalization = true,
        ?WebAdminShellContext $shell = null
    ): string {
        $identity = $this->identityFields($variant);
        $publish = '';
        if ($canPublish) {
            $transition = $variant->status() === BlogPostVariant::PUBLISHED
                ? ['suffix' => '/posts/unpublish', 'label' => 'Retirar']
                : ['suffix' => '/posts/publish', 'label' => 'Publicar'];
            $publish = '<form method="post" action="'
                . $this->path($basePath, $transition['suffix']) . '">'
                . $this->csrfInput($csrf) . $identity
                . '<button type="submit">' . $transition['label']
                . '</button></form>';
        }

        $editor = $variant->status() === BlogPostVariant::DRAFT
            ? '<form method="post" action="'
                . $this->path($basePath, '/posts/save') . '">'
                . $this->csrfInput($csrf) . $identity
                . $this->editorialFields($variant)
                . '<button type="submit">Guardar cambios</button></form>'
            : '<p role="status">Retira la variante antes de editar su '
                . 'contenido.</p><div aria-label="Contenido publicado">'
                . $this->editorialFields($variant, true) . '</div>';
        $preview = '<p><a href="' . $this->pathWithQuery(
            $basePath,
            '/posts/preview',
            [
                'post' => $variant->postPublicId(),
                'locale' => $variant->locale(),
            ]
        ) . '" target="_blank" rel="noopener">Abrir vista previa de la '
            . 'versi&oacute;n guardada</a>. Guarda primero cualquier cambio '
            . 'pendiente.</p>';

        return $this->page(
            'Editar art&iacute;culo',
            '<article class="blogAdminPage" aria-labelledby="blog-edit-title">'
            . '<h1 id="blog-edit-title">Editar art&iacute;culo</h1>'
            . '<p>Idioma: <strong>' . $this->escape($variant->locale())
            . '</strong>. Estado: ' . $this->statusLabel($variant->status())
            . '.</p>' . $this->formError($failed)
            . $preview
            . $editor
            . $publish
            . ($canAddLocalization
                ? '<p><a href="' . $this->pathWithQuery(
                    $basePath,
                    '/posts/new',
                    ['post' => $variant->postPublicId()]
                ) . '">A&ntilde;adir otro idioma</a></p>'
                : '<p role="status">Este art&iacute;culo ya tiene una variante '
                    . 'para todos los idiomas activos.</p>')
            . $this->backToBlog($basePath)
            . '</article>',
            $basePath,
            '/blog/posts/edit',
            $shell
        );
    }

    public function localizationsComplete(
        string $basePath,
        ?WebAdminShellContext $shell = null
    ): string
    {
        return $this->page(
            'Idiomas del art&iacute;culo',
            '<article class="blogAdminPage" aria-labelledby="blog-locales-title">'
            . '<h1 id="blog-locales-title">Idiomas del art&iacute;culo</h1>'
            . '<p role="status">Este art&iacute;culo ya tiene una variante para '
            . 'todos los idiomas activos.</p>'
            . $this->backToBlog($basePath)
            . '</article>',
            $basePath,
            '/blog/posts/new',
            $shell
        );
    }

    public function preview(
        string $basePath,
        BlogPostVariant $variant,
        bool $canEdit,
        ?WebAdminShellContext $shell = null
    ): string {
        $draft = $variant->draft();
        $excerpt = $draft->excerpt() === null
            ? ''
            : '<p>' . $this->escape($draft->excerpt()) . '</p>';
        $body = $this->previewBody($draft->bodyText());
        if ($body === '') {
            $body = '<p role="status">Este borrador todav&iacute;a no tiene '
                . 'contenido guardado.</p>';
        }
        $edit = $canEdit
            ? '<li><a href="' . $this->pathWithQuery(
                $basePath,
                '/editor',
                [
                    'post' => $variant->postPublicId(),
                    'locale' => $variant->locale(),
                ]
            ) . '">Volver a editar</a></li>'
            : '';

        return $this->page(
            'Vista previa privada',
            '<p role="status"><strong>Vista previa privada de la '
            . 'versi&oacute;n guardada.</strong> No crea una URL p&uacute;blica '
            . 'ni modifica el art&iacute;culo. Idioma: '
            . $this->escape($variant->locale()) . '. Estado: '
            . $this->statusLabel($variant->status()) . '.</p>'
            . '<article lang="' . $this->escape($variant->locale())
            . '" aria-labelledby="blog-preview-title">'
            . '<h1 id="blog-preview-title">'
            . $this->escape($draft->h1()) . '</h1>'
            . $excerpt
            . '<div aria-label="Contenido del art&iacute;culo">'
            . $body . '</div></article>'
            . '<nav aria-label="Acciones de la vista previa"><ul>'
            . $edit . '<li><a href="' . $this->path($basePath, '')
            . '">Volver al Blog</a></li></ul></nav>',
            $basePath,
            '/blog/posts/preview',
            $shell
        );
    }

    public function operationCompleted(
        string $basePath,
        ?WebAdminShellContext $shell = null
    ): string
    {
        return $this->page(
            'Operaci&oacute;n completada',
            '<article class="blogAdminPage" aria-labelledby="blog-updated-title">'
            . '<h1 id="blog-updated-title">Operaci&oacute;n completada</h1>'
            . '<p role="status" aria-live="polite">Los cambios se han '
            . 'guardado correctamente.</p>'
            . $this->backToBlog($basePath)
            . '</article>',
            $basePath,
            '/blog',
            $shell
        );
    }

    private function editorialFields(
        ?BlogPostVariant $variant,
        bool $readOnly = false
    ): string
    {
        $draft = $variant?->draft();
        $readonly = $readOnly ? ' readonly' : '';

        return '<div><label for="blog-h1">H1</label><input id="blog-h1" '
            . 'name="h1" type="text" maxlength="'
            . BlogDraft::MAX_H1_BYTES . '" value="'
            . $this->escape($draft?->h1() ?? '') . '" required'
            . $readonly . '></div>'
            . '<div><label for="blog-slug">Slug</label><input id="blog-slug" '
            . 'name="slug" type="text" maxlength="'
            . BlogDraft::MAX_SLUG_BYTES . '" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" '
            . 'value="' . $this->escape($draft?->slug() ?? '') . '"'
            . $readonly . '></div>'
            . '<div><label for="blog-seo-title">Title SEO</label><input '
            . 'id="blog-seo-title" name="seo_title" type="text" maxlength="'
            . BlogDraft::MAX_SEO_TITLE_BYTES . '" value="'
            . $this->escape($draft?->seoTitle() ?? '') . '"'
            . $readonly . '></div>'
            . '<div><label for="blog-description">Meta description</label>'
            . '<textarea id="blog-description" name="meta_description" '
            . 'maxlength="' . BlogDraft::MAX_META_DESCRIPTION_BYTES . '"'
            . $readonly . '>'
            . $this->escape($draft?->metaDescription() ?? '')
            . '</textarea></div>'
            . '<div><label for="blog-excerpt">Extracto</label><textarea '
            . 'id="blog-excerpt" name="excerpt" maxlength="'
            . BlogDraft::MAX_EXCERPT_BYTES . '"' . $readonly . '>'
            . $this->escape($draft?->excerpt() ?? '') . '</textarea></div>'
            . '<div><label for="blog-body">Contenido en texto plano</label>'
            . '<textarea id="blog-body" name="body_text" maxlength="'
            . BlogDraft::MAX_BODY_BYTES . '"' . $readonly . '>'
            . $this->escape($draft?->bodyText() ?? '') . '</textarea></div>';
    }

    private function creationFields(): string
    {
        return '<div><label for="blog-h1">H1 inicial</label><input '
            . 'id="blog-h1" name="h1" type="text" maxlength="'
            . BlogDraft::MAX_H1_BYTES . '" required></div>'
            . '<p>El slug, los metadatos, la portada y el contenido se '
            . 'completan en el editor visual.</p>'
            . '<input type="hidden" name="slug" value="">'
            . '<input type="hidden" name="seo_title" value="">'
            . '<input type="hidden" name="meta_description" value="">'
            . '<input type="hidden" name="excerpt" value="">'
            . '<input type="hidden" name="body_text" value="">';
    }

    /** @param array<string, string> $localePublicPaths */
    private function localeOptions(array $localePublicPaths): string
    {
        $options = '';
        $seenPaths = [];
        foreach ($localePublicPaths as $locale => $publicPath) {
            if (
                !is_string($locale)
                || preg_match(
                    '/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})*\z/',
                    $locale
                ) !== 1
                || !is_string($publicPath)
                || preg_match('/\A\/[^\x00-\x20?#]*\z/u', $publicPath) !== 1
                || isset($seenPaths[$publicPath])
            ) {
                throw new InvalidArgumentException(
                    'Invalid Blog language presentation.'
                );
            }
            $seenPaths[$publicPath] = true;
            $options .= '<option value="' . $this->escape($locale) . '">'
                . $this->escape($locale) . ' &mdash; '
                . $this->escape($publicPath) . '</option>';
        }
        if ($options === '') {
            throw new InvalidArgumentException(
                'Blog needs at least one presentation language.'
            );
        }

        return $options;
    }

    private function identityFields(BlogPostVariant $variant): string
    {
        return '<input type="hidden" name="post" value="'
            . $this->escape($variant->postPublicId()) . '">'
            . '<input type="hidden" name="locale" value="'
            . $this->escape($variant->locale()) . '">'
            . '<input type="hidden" name="lock_version" value="'
            . $variant->lockVersion() . '">';
    }

    private function previewBody(string $bodyText): string
    {
        $paragraphs = preg_split(
            '/\n[\t ]*\n+/u',
            trim($bodyText)
        );
        if (!is_array($paragraphs)) {
            throw new InvalidArgumentException(
                'Invalid Blog preview body presentation.'
            );
        }

        $body = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            $body .= '<p>' . $this->escape($paragraph) . '</p>';
        }

        return $body;
    }

    private function page(
        string $title,
        string $body,
        string $blogBasePath,
        string $activePath,
        ?WebAdminShellContext $shell
    ): string
    {
        $shell ??= new WebAdminShellContext(
            basePath: $this->webAdminBasePath($blogBasePath),
            logoutCsrf: null,
            activePath: $activePath,
            assets: new WebAdminPageAssets([
                '/assets/modules/blog/blog-admin.css',
            ], [
                '/assets/modules/blog/blog-admin-list.js',
            ])
        );

        return $this->shellRenderer->render($title, $body, $shell);
    }

    private function webAdminBasePath(string $blogBasePath): string
    {
        $normalized = rtrim($blogBasePath, '/');
        if (!str_ends_with($normalized, '/blog')) {
            throw new InvalidArgumentException(
                'Invalid Blog administration base path.'
            );
        }

        $basePath = substr($normalized, 0, -strlen('/blog'));

        return $basePath === '' ? '/' : $basePath;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            BlogPostVariant::DRAFT => 'Borrador',
            BlogPostVariant::PUBLISHED => 'Publicado',
            default => throw new InvalidArgumentException(
                'Invalid Blog status presentation.'
            ),
        };
    }

    private function formError(bool $failed): string
    {
        return $failed
            ? '<p role="alert" aria-live="assertive">No se pudo completar '
                . 'la operaci&oacute;n. Revisa los datos y la versi&oacute;n.</p>'
            : '';
    }

    private function csrfInput(string $csrf): string
    {
        return '<input type="hidden" name="csrf" value="'
            . $this->escape($csrf) . '">';
    }

    private function backToBlog(string $basePath): string
    {
        return '<p><a href="' . $this->path($basePath, '')
            . '">Volver al Blog</a></p>';
    }

    private function backToDashboard(string $basePath): string
    {
        $dashboard = substr($basePath, 0, -strlen('/blog'));

        return '<p><a href="' . $this->escape($dashboard)
            . '">Volver a la gesti&oacute;n web</a></p>';
    }

    private function pagination(
        string $basePath,
        int $offset,
        bool $hasNext,
        array $preservedQuery = []
    ): string {
        return $this->paginationForPath(
            $basePath,
            $offset,
            $hasNext,
            $preservedQuery
        );
    }

    /** @param array<string, string> $preservedQuery */
    private function paginationForPath(
        string $path,
        int $offset,
        bool $hasNext,
        array $preservedQuery = []
    ): string {
        $items = '';
        if ($offset > 0) {
            $previous = max(
                0,
                $offset - BlogService::DEFAULT_LIST_LIMIT
            );
            $query = $preservedQuery;
            if ($previous > 0) {
                $query['offset'] = (string) $previous;
            }
            $href = $query === []
                ? $this->path($path, '')
                : $this->pathWithQuery($path, '', $query);
            $items .= '<li><a rel="prev" href="' . $href
                . '">P&aacute;gina anterior</a></li>';
        }
        if (
            $hasNext
            && $offset <= BlogService::MAX_LIST_OFFSET
                - BlogService::DEFAULT_LIST_LIMIT
        ) {
            $items .= '<li><a rel="next" href="'
                . $this->pathWithQuery(
                    $path,
                    '',
                    $preservedQuery + [
                        'offset' => (string) (
                            $offset + BlogService::DEFAULT_LIST_LIMIT
                        ),
                    ]
                )
                . '">P&aacute;gina siguiente</a></li>';
        }

        return $items === ''
            ? ''
            : '<nav aria-label="Paginaci&oacute;n de art&iacute;culos"><ul>'
                . $items . '</ul></nav>';
    }

    /** @param array<string, string> $query */
    private function pathWithQuery(
        string $basePath,
        string $suffix,
        array $query
    ): string {
        return $this->escape(
            rtrim($basePath, '/') . $suffix . '?'
            . http_build_query($query, '', '&', PHP_QUERY_RFC3986)
        );
    }

    private function path(string $basePath, string $suffix): string
    {
        return $this->escape(rtrim($basePath, '/') . $suffix);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
