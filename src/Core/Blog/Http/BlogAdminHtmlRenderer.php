<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\Admin\BlogAdminCatalogQuery;
use App\Core\Blog\Analytics\BlogArticleAnalyticsSummary;
use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostSummary;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogService;
use App\Core\Blog\Seo\BlogSeoScore;
use App\Core\Blog\Seo\BlogUrlResolution;
use App\Core\WebAdmin\Http\WebAdminPageAssets;
use App\Core\WebAdmin\Http\WebAdminShellContext;
use App\Core\WebAdmin\Http\WebAdminShellRenderer;
use App\Core\WebAdmin\Profile\WebAdminPublicProfile;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use InvalidArgumentException;

final class BlogAdminHtmlRenderer
{
    private const TABLE_STATUS_DELETED = 'deleted';

    private readonly WebAdminShellRenderer $shellRenderer;
    private readonly BlogPublicationDateFormatter $dateFormatter;
    private readonly UuidGeneratorInterface $requestIds;

    public function __construct(
        ?WebAdminShellRenderer $shellRenderer = null,
        ?BlogPublicationDateFormatter $dateFormatter = null,
        ?UuidGeneratorInterface $requestIds = null
    ) {
        $this->shellRenderer = $shellRenderer ?? new WebAdminShellRenderer();
        $this->dateFormatter = $dateFormatter
            ?? new BlogPublicationDateFormatter();
        $this->requestIds = $requestIds ?? new RandomUuidV4Generator();
    }

    /**
     * @param list<BlogPostSummary> $summaries
     * @param array<string, string> $publicPaths
     * @param array<string, BlogArticleAnalyticsSummary>
     *     $analyticsByLocalization
     * @param array<string, list<string>> $localesByPost
     * @param array<string, BlogSeoScore> $seoScoresByLocalization
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
        int $analyticsPeriodDays = 30,
        ?BlogAdminCatalogQuery $catalogQuery = null,
        array $localesByPost = [],
        bool $canAddLocalization = false,
        ?WebAdminPublicProfile $viewerProfile = null,
        array $seoScoresByLocalization = [],
        bool $canBulkPublish = false,
        bool $canManageRobots = false
    ): string {
        if (
            $offset < 0
            || $offset > BlogService::MAX_LIST_OFFSET
            || count($summaries) > 50
        ) {
            throw new InvalidArgumentException(
                'Invalid Blog pagination presentation.'
            );
        }
        $catalogQuery ??= new BlogAdminCatalogQuery(offset: $offset);
        if (
            $catalogQuery->offset() !== $offset
            || $offset % $catalogQuery->pageSize() !== 0
            || count($summaries) > $catalogQuery->pageSize()
            || (
                $catalogQuery->locale() !== null
                && !array_key_exists($catalogQuery->locale(), $publicPaths)
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid Blog catalog presentation.'
            );
        }
        if (!in_array($analyticsPeriodDays, [7, 30, 90], true)) {
            throw new InvalidArgumentException(
                'Invalid Blog analytics period presentation.'
            );
        }
        $bulkOptions = $this->bulkActionOptions(
            $canDelete,
            $canPublish,
            $canBulkPublish,
            $canDuplicate,
            $canAddLocalization,
            $canManageRobots
        );
        $bulkEnabled = $summaries !== []
            && $csrf !== ''
            && $bulkOptions !== '';
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
                $viewLabel = $canViewMedia
                    ? 'Vista previa'
                    : 'Lectura textual del contenido guardado';
            }
            $isPrivatePreview = hash_equals($preview, $viewHref);
            $isImmersivePreview = $isPrivatePreview && $canViewMedia;
            $viewLinkAttributes = '';
            if ($isImmersivePreview) {
                $viewLinkAttributes = ' data-blog-private-preview '
                    . 'data-blog-preview-title="'
                    . $this->escape($summary->h1()) . '"';
            } elseif (!$isPrivatePreview) {
                // Public output is separate; keep WebAdmin open.
                $viewLinkAttributes = ' target="_blank" rel="noopener"';
            }
            $rowTitleId = 'blog-row-title-'
                . $summary->localizationPublicId();
            $actions = '<div class="blogAdminPage__rowActions">'
                . '<a class="blogAdminPage__action '
                . 'blogAdminPage__action--view" href="' . $viewHref . '"'
                . $viewLinkAttributes
                . ' title="' . $this->escape($viewLabel) . '"'
                . ' aria-label="' . $this->escape($viewLabel) . '"'
                . ' aria-describedby="'
                . $rowTitleId . '">' . $this->actionIcon('view', $viewLabel)
                . '</a>';
            $canOpenEditor = $canEdit && $canViewMedia;
            if ($canOpenEditor) {
                $edit = $this->pathWithQuery($basePath, '/editor', [
                    'post' => $summary->postPublicId(),
                    'locale' => $summary->locale(),
                ]);
                $actions .= '<a class="blogAdminPage__action '
                    . 'blogAdminPage__action--edit" href="' . $edit
                    . '" title="Editar" aria-label="Editar'
                    . '" aria-describedby="' . $rowTitleId
                    . '">' . $this->actionIcon('edit', 'Editar') . '</a>';
            } else {
                $actions .= '<span class="blogAdminPage__readOnly">'
                    . 'Solo lectura</span>';
            }
            if (!$isPublished && $canPublish && $slug !== null) {
                $urlManager = $this->pathWithQuery(
                    $basePath,
                    '/posts/url',
                    [
                        'post' => $summary->postPublicId(),
                        'locale' => $summary->locale(),
                    ]
                );
                $actions .= '<a class="blogAdminPage__action '
                    . 'blogAdminPage__action--url" href="' . $urlManager
                    . '" title="Estado URL" aria-label="Estado URL'
                    . '" aria-describedby="' . $rowTitleId
                    . '">' . $this->actionIcon('url', 'Estado URL') . '</a>';
            }
            if (
                ($canDuplicate || $canAddLocalization)
                && $canEdit
                && $canViewMedia
                && $csrf !== ''
            ) {
                $actions .= $this->languageCopyFlow(
                    $basePath,
                    $csrf,
                    $summary,
                    $publicPaths,
                    $localesByPost[$summary->postPublicId()]
                        ?? [$summary->locale()],
                    $canDuplicate,
                    $canAddLocalization
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
                    'trash'
                );
            } elseif ($canPublish && $isPublished && $csrf !== '') {
                $actions .= $this->variantActionForm(
                    $basePath,
                    '/posts/unpublish',
                    $csrf,
                    $summary,
                    'Retirar',
                    'blogAdminPage__action--retire',
                    'unpublish'
                );
            } elseif ($canDelete && $isPublished) {
                $actions .= '<span class="blogAdminPage__action '
                    . 'blogAdminPage__action--disabled" aria-disabled="true" '
                    . 'title="Borrar: retira primero" '
                    . 'aria-label="Borrar: retira primero" '
                    . 'aria-describedby="' . $rowTitleId . '">'
                    . $this->actionIcon('delete', 'Borrar: retira primero')
                    . '</span>';
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
            $seoScore = $seoScoresByLocalization[
                $summary->localizationPublicId()
            ] ?? null;
            if ($seoScore !== null && !$seoScore instanceof BlogSeoScore) {
                throw new InvalidArgumentException(
                    'Invalid Blog SEO score presentation.'
                );
            }
            $bulkCell = '';
            if ($bulkEnabled) {
                $bulkValue = implode('|', [
                    $summary->postPublicId(),
                    $summary->locale(),
                    (string) $summary->lockVersion(),
                    $this->requestIds->generateV4(),
                ]);
                $bulkCell = '<td class="blogAdminPage__bulkSelect"><input '
                    . 'type="checkbox" name="items[]" form="blog-admin-bulk-form" '
                    . 'value="' . $this->escape($bulkValue) . '" '
                    . 'aria-label="Seleccionar ' . $this->escape($summary->h1())
                    . '" aria-describedby="' . $rowTitleId
                    . '" data-blog-bulk-item></td>';
            }
            $rows .= '<tr>' . $bulkCell . '<th id="' . $rowTitleId
                . '" scope="row">'
                . $this->escape($summary->h1()) . '</th><td>'
                . $this->localeBadge($summary->locale()) . '</td><td>'
                . $this->tableStatus($summary->status()) . '</td><td>'
                . $this->authorCell($summary) . '</td><td>'
                . $this->categoryCell($summary) . '</td><td>'
                . $this->seoScoreCell($seoScore) . '</td><td>'
                . $this->robotsCell($summary) . '</td>'
                . $analyticsCells
                . '<td>'
                . $this->escape($this->adminDate(
                    $summary->updatedAt(),
                    $viewerProfile
                ))
                . '</td><td>' . $actions . '</td></tr>';
        }
        if ($rows === '') {
            $columns = ($showAnalytics ? 14 : 9) + ($bulkEnabled ? 1 : 0);
            $rows = '<tr><td colspan="' . $columns
                . '">'
                . ($catalogQuery->hasFilters()
                    ? 'No hay art&iacute;culos que coincidan con los filtros.'
                    : 'No hay art&iacute;culos.')
                . '</td></tr>';
        }
        $create = $canEdit && $canViewMedia
            ? '<p class="blogAdminPage__primaryAction"><a class="'
                . 'webadminAction webadminAction--primary" href="'
                . $this->path($basePath, '/posts/new')
                . '">Crear art&iacute;culo</a></p>'
            : '';
        $trash = $canDelete
            ? '<p class="blogAdminPage__trashLink"><a href="'
                . $this->path($basePath, '/trash')
                . '">Ver papelera</a></p>'
            : '';
        $filter = $this->catalogFilter(
            $basePath,
            $catalogQuery,
            $publicPaths,
            $showAnalytics,
            $analyticsPeriodDays
        );
        $preservedQuery = $catalogQuery->queryParameters();
        if ($showAnalytics) {
            $preservedQuery['period'] = (string) $analyticsPeriodDays;
        }
        $analyticsHeaders = $showAnalytics
            ? $this->compactMetricHeader('views', 'Vistas')
                . $this->compactMetricHeader(
                    'unique-visitors',
                    'Visitantes únicos'
                )
                . $this->compactMetricHeader('returning', 'Habituales')
                . '<th scope="col" title="No ordenable: la m&eacute;trica se '
                . 'calcula para la p&aacute;gina visible">Interacci&oacute;n media</th>'
                . '<th scope="col" title="No ordenable: la m&eacute;trica se '
                . 'calcula para la p&aacute;gina visible" '
                . 'aria-describedby="blog-analytics-bounce-help">'
                . 'Rebote del Blog</th>'
            : '';
        $bulkToolbar = $bulkEnabled
            ? $this->bulkToolbar(
                $basePath,
                $csrf,
                $bulkOptions,
                $publicPaths
            )
            : '';
        $bulkHeader = $bulkEnabled
            ? '<th class="blogAdminPage__bulkSelect" scope="col"><input '
                . 'type="checkbox" data-blog-bulk-select-all '
                . 'aria-label="Seleccionar todos los art&iacute;culos visibles"></th>'
            : '';

        return $this->page(
            'Art&iacute;culos del Blog',
            '<article class="blogAdminPage blogAdminPage--index" '
            . 'aria-labelledby="blog-admin-title">'
            . '<h1 id="blog-admin-title">Art&iacute;culos del Blog</h1>'
            . '<p>Gestiona cada idioma, consulta su rendimiento y abre la '
            . 'acci&oacute;n que necesitas sin alterar las dem&aacute;s variantes.</p>'
            . '<p><strong>Retirar</strong> despublica y conserva contenido, '
            . 'revisiones y opci&oacute;n de republicar. <strong>Borrar</strong> '
            . 'solo mueve un borrador a una papelera recuperable.</p>'
            . $create
            . $trash
            . $filter
            . '<p data-blog-admin-filter-status role="status" '
            . 'aria-live="polite" hidden></p>'
            . '<div id="blog-admin-results" data-blog-admin-results '
            . 'data-blog-admin-result-count="' . count($summaries) . '">'
            . $bulkToolbar
            . '<div class="blogAdminPage__tableViewport" tabindex="0" '
            . 'role="region" aria-label="Variantes editoriales">'
            . '<table><caption>Variantes editoriales</caption><thead><tr>'
            . $bulkHeader
            . $this->sortableHeading(
                $basePath,
                'T&iacute;tulo',
                BlogAdminCatalogQuery::SORT_TITLE,
                $catalogQuery,
                $preservedQuery
            )
            . $this->sortableHeading(
                $basePath,
                'Idioma',
                BlogAdminCatalogQuery::SORT_LOCALE,
                $catalogQuery,
                $preservedQuery
            )
            . $this->sortableHeading(
                $basePath,
                'Estado',
                BlogAdminCatalogQuery::SORT_STATUS,
                $catalogQuery,
                $preservedQuery
            )
            . $this->sortableHeading(
                $basePath,
                'Autor',
                BlogAdminCatalogQuery::SORT_AUTHOR,
                $catalogQuery,
                $preservedQuery
            )
            . '<th scope="col" title="No ordenable: un art&iacute;culo puede '
            . 'pertenecer a varias categor&iacute;as">Categor&iacute;as</th>'
            . '<th scope="col" title="No ordenable: la puntuaci&oacute;n se '
            . 'calcula para la versi&oacute;n guardada">SEO</th>'
            . $this->sortableHeading(
                $basePath,
                'Index / Follow',
                BlogAdminCatalogQuery::SORT_ROBOTS,
                $catalogQuery,
                $preservedQuery
            )
            . $analyticsHeaders
            . $this->sortableHeading(
                $basePath,
                'Actualizado',
                BlogAdminCatalogQuery::SORT_UPDATED,
                $catalogQuery,
                $preservedQuery
            )
            . '<th scope="col">Acciones</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>'
            . $this->pagination(
                $basePath,
                $offset,
                $hasNext,
                $preservedQuery,
                $catalogQuery->pageSize()
            )
            . '</div>'
            . '</article>',
            $basePath,
            '/blog',
            $shell
        );
    }

    /**
     * @param list<array{title: string, locale: string, state: string, message: string, href: ?string, link_label: ?string}> $results
     */
    public function bulkResults(
        string $basePath,
        string $actionLabel,
        array $results,
        ?WebAdminShellContext $shell = null
    ): string {
        if ($results === [] || count($results) > BlogAdminRequestPolicy::MAX_BULK_ITEMS) {
            throw new InvalidArgumentException(
                'Invalid Blog bulk result presentation.'
            );
        }
        $items = '';
        $successful = 0;
        foreach ($results as $result) {
            if (
                !is_array($result)
                || !is_string($result['title'] ?? null)
                || !is_string($result['locale'] ?? null)
                || !is_string($result['state'] ?? null)
                || !in_array($result['state'], ['success', 'warning', 'error'], true)
                || !is_string($result['message'] ?? null)
                || !array_key_exists('href', $result)
                || !array_key_exists('link_label', $result)
                || ($result['href'] !== null
                    && (!is_string($result['href'])
                        || !str_starts_with($result['href'], '/')))
                || ($result['link_label'] !== null
                    && !is_string($result['link_label']))
            ) {
                throw new InvalidArgumentException(
                    'Invalid Blog bulk result presentation.'
                );
            }
            if ($result['state'] === 'success') {
                ++$successful;
            }
            $link = $result['href'] === null
                ? ''
                : '<a href="' . $this->escape($result['href']) . '">'
                    . $this->escape((string) $result['link_label']) . '</a>';
            $items .= '<li class="blogAdminPage__bulkResult '
                . 'blogAdminPage__bulkResult--' . $result['state'] . '">'
                . '<strong>' . $this->escape($result['title']) . '</strong> '
                . '<span>(' . $this->escape($result['locale']) . ')</span>'
                . '<p>' . $this->escape($result['message']) . '</p>'
                . $link . '</li>';
        }

        return $this->page(
            'Resultado de acciones masivas',
            '<article class="blogAdminPage" '
            . 'aria-labelledby="blog-bulk-results-title">'
            . '<h1 id="blog-bulk-results-title">'
            . $this->escape($actionLabel) . '</h1>'
            . '<p role="status">' . $successful . ' de ' . count($results)
            . ' variantes completadas sin incidencias.</p>'
            . '<ul class="blogAdminPage__bulkResults">' . $items . '</ul>'
            . $this->backToBlog($basePath) . '</article>',
            $basePath,
            '/blog',
            $shell
        );
    }

    private function bulkActionOptions(
        bool $canDelete,
        bool $canPublish,
        bool $canBulkPublish,
        bool $canDuplicate,
        bool $canAddLocalization,
        bool $canManageRobots
    ): string {
        $options = '';
        $append = static function (
            string $value,
            string $label
        ) use (&$options): void {
            $options .= '<option value="' . $value . '">' . $label
                . '</option>';
        };
        if ($canBulkPublish) {
            $append(BlogAdminRequestPolicy::BULK_PUBLISH, 'Publicar');
        }
        if ($canPublish) {
            $append(
                BlogAdminRequestPolicy::BULK_UNPUBLISH,
                'Pasar a borrador'
            );
        }
        if ($canDuplicate) {
            $append(BlogAdminRequestPolicy::BULK_DUPLICATE, 'Duplicar');
        }
        if ($canAddLocalization) {
            $append(
                BlogAdminRequestPolicy::BULK_ADD_LOCALE,
                'A&ntilde;adir idioma'
            );
        }
        if ($canManageRobots) {
            $append(
                BlogAdminRequestPolicy::BULK_ROBOTS,
                'Cambiar Index / Follow'
            );
        }
        if ($canDelete) {
            $append(
                BlogAdminRequestPolicy::BULK_TRASH,
                'Mover borradores a la papelera'
            );
        }

        return $options;
    }

    /** @param array<string, string> $publicPaths */
    private function bulkToolbar(
        string $basePath,
        string $csrf,
        string $actionOptions,
        array $publicPaths
    ): string {
        return '<form id="blog-admin-bulk-form" '
            . 'class="blogAdminPage__bulkToolbar" method="post" action="'
            . $this->path($basePath, '/posts/bulk')
            . '" data-blog-bulk-form>'
            . $this->csrfInput($csrf)
            . '<div><label for="blog-bulk-action">Acci&oacute;n para la '
            . 'selecci&oacute;n</label><select id="blog-bulk-action" '
            . 'name="action" data-blog-bulk-action>' . $actionOptions
            . '</select></div>'
            . '<div data-blog-bulk-locale><label for="blog-bulk-locale">'
            . 'Idioma que se a&ntilde;adir&aacute;</label><select '
            . 'id="blog-bulk-locale" name="destination_locale">'
            . $this->localeOptions($publicPaths) . '</select></div>'
            . '<fieldset data-blog-bulk-robots><legend>Directivas '
            . 'robots</legend><div><label for="blog-bulk-index">'
            . 'Indexaci&oacute;n</label><select id="blog-bulk-index" '
            . 'name="robots_index"><option value="1">Index</option>'
            . '<option value="0">Noindex</option></select></div>'
            . '<div><label for="blog-bulk-follow">Seguimiento</label>'
            . '<select id="blog-bulk-follow" name="robots_follow">'
            . '<option value="1">Follow</option><option value="0">'
            . 'Nofollow</option></select></div></fieldset>'
            . '<p class="blogAdminPage__bulkCount" role="status" '
            . 'aria-live="polite"><strong data-blog-bulk-count>0</strong> '
            . 'seleccionados</p><button class="webadminAction '
            . 'webadminAction--primary" type="submit" '
            . 'data-blog-bulk-submit>Aplicar acci&oacute;n</button>'
            . '</form>';
    }

    /** @param list<BlogPostSummary> $summaries */
    public function trash(
        string $basePath,
        array $summaries,
        int $offset,
        bool $hasNext,
        string $csrf,
        ?WebAdminShellContext $shell = null,
        ?WebAdminPublicProfile $viewerProfile = null
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
                . $this->tableStatus(self::TABLE_STATUS_DELETED) . '</td><td>'
                . $this->escape($this->adminDate(
                    $summary->updatedAt(),
                    $viewerProfile
                ))
                . '</td><td><div class="blogAdminPage__rowActions">'
                . $restore . '</div></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5">La papelera est&aacute; vac&iacute;a.'
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
            . '<th scope="col">Estado</th><th scope="col">Actualizado</th>'
            . '<th scope="col">Acciones</th>'
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

    private function adminDate(
        \DateTimeImmutable $date,
        ?WebAdminPublicProfile $viewerProfile
    ): string {
        // An installation without WebAdmin profile preferences keeps the
        // additive legacy presentation. Once the profile feature exists,
        // its explicit IANA zone (or its visible UTC fallback) is canonical.
        if ($viewerProfile === null) {
            return $date->format('Y-m-d H:i');
        }

        return $this->dateFormatter->formatCompactDate(
            $date,
            'es',
            $viewerProfile
        );
    }

    private function localeBadge(string $locale, ?string $id = null): string
    {
        $label = BlogLocalePresentation::label($locale);
        $asset = BlogLocalePresentation::flagAsset($locale);
        $idAttribute = $id === null
            ? ''
            : ' id="' . $this->escape($id) . '"';
        $visual = $asset === null
            ? '<span class="blogAdminPage__localeFallback" aria-hidden="true">'
                . '&#9673;</span>'
            : '<img src="' . $this->escape($asset)
                . '" alt="" aria-hidden="true" width="24" height="18">';

        return '<span class="blogAdminPage__locale"' . $idAttribute . '>'
            . $visual . '<span>'
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
        ?string $confirmation = null
    ): string {
        if (
            $confirmation !== null
            && !in_array($confirmation, ['trash', 'unpublish'], true)
        ) {
            throw new InvalidArgumentException(
                'Invalid Blog action confirmation presentation.'
            );
        }
        $attributes = $confirmation === null
            ? ''
            : ' data-blog-confirm-form data-blog-confirm-action="'
                . $confirmation . '" data-blog-title="'
                . $this->escape($summary->h1()) . '"';

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
            . '" type="submit" title="' . $this->escape($label)
            . '" aria-label="' . $this->escape($label)
            . '" aria-describedby="blog-row-title-'
            . $summary->localizationPublicId() . '">'
            . $this->actionIcon(
                $confirmation === 'trash'
                    ? 'delete'
                    : ($confirmation === 'unpublish' ? 'retire' : 'restore'),
                $label
            )
            . '</button></form>';
    }

    /**
     * @param array<string, string> $publicPaths
     * @param list<string> $existingLocales
     */
    private function languageCopyFlow(
        string $basePath,
        string $csrf,
        BlogPostSummary $summary,
        array $publicPaths,
        array $existingLocales,
        bool $canDuplicate,
        bool $canAddLocalization
    ): string {
        if (!array_is_list($existingLocales)) {
            throw new InvalidArgumentException(
                'Invalid Blog aggregate locale presentation.'
            );
        }
        $existing = [];
        foreach ($existingLocales as $locale) {
            if (
                !is_string($locale)
                || preg_match(
                    '/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})*\z/',
                    $locale
                ) !== 1
                || isset($existing[$locale])
            ) {
                throw new InvalidArgumentException(
                    'Invalid Blog aggregate locale presentation.'
                );
            }
            $existing[$locale] = true;
        }
        $existing[$summary->locale()] = true;

        $flowId = $summary->localizationPublicId();
        $titleId = 'blog-language-title-' . $flowId;
        $descriptionId = 'blog-language-description-' . $flowId;
        $outcomeId = 'blog-language-outcome-' . $flowId;
        $options = '';
        $enabledOptions = 0;
        foreach ($publicPaths as $locale => $publicPath) {
            if (
                !is_string($locale)
                || preg_match(
                    '/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})*\z/',
                    $locale
                ) !== 1
                || !is_string($publicPath)
            ) {
                throw new InvalidArgumentException(
                    'Invalid Blog language copy presentation.'
                );
            }
            $label = BlogLocalePresentation::label($locale);
            $isIndependentPost = $locale === $summary->locale();
            $alreadyExists = isset($existing[$locale]);
            $enabled = $isIndependentPost
                ? $canDuplicate
                : (!$alreadyExists && $canAddLocalization);
            if ($enabled) {
                $enabledOptions++;
            }
            if ($isIndependentPost) {
                $help = $enabled
                    ? 'Crea otro art&iacute;culo independiente y conserva las '
                        . 'categor&iacute;as actuales.'
                    : 'La copia independiente requiere permiso para conservar '
                        . 'las categor&iacute;as actuales.';
                $outcome = 'Se creará un artículo independiente en '
                    . $label . '. La publicación actual no cambiará.';
                $submitLabel = 'Crear artículo independiente';
            } elseif ($alreadyExists) {
                $help = 'Ya existe una variante activa o en la Papelera; '
                    . 'rest&aacute;urala si procede.';
                $outcome = 'Este idioma ya existe en el artículo.';
                $outcome = 'Este idioma ya tiene una variante activa o en '
                    . 'la Papelera.';
                $submitLabel = 'Variante ya existente';
            } else {
                $help = $enabled
                    ? 'A&ntilde;ade una variante privada al art&iacute;culo actual.'
                    : 'No tienes permiso para a&ntilde;adir esta variante.';
                $outcome = 'Se añadirá una variante privada en '
                    . $label . '. La publicación actual no cambiará.';
                $submitLabel = 'Añadir ' . $label;
            }
            $helpId = 'blog-language-help-' . $flowId . '-'
                . str_replace('-', '_', $locale);
            $nameId = 'blog-language-name-' . $flowId . '-'
                . str_replace('-', '_', $locale);
            $operationId = $this->requestIds->generateV4();
            $options .= '<label class="blogAdminPage__languageOption">'
                . '<input type="radio" name="destination_locale" value="'
                . $this->escape($locale) . '" required aria-labelledby="'
                . $nameId . '" aria-describedby="' . $helpId
                . '" data-blog-language-outcome="'
                . $this->escape($outcome) . '" '
                . 'data-blog-language-submit-label="'
                . $this->escape($submitLabel) . '" '
                . 'data-blog-language-operation-id="'
                . $this->escape($operationId) . '"'
                . ($enabled ? '' : ' disabled') . '>'
                . $this->localeBadge($locale, $nameId)
                . '<small id="' . $helpId . '">' . $help . '</small>'
                . '</label>';
        }
        if ($enabledOptions === 0) {
            return '';
        }

        return '<details class="blogAdminPage__languageFlow" '
            . 'data-blog-language-flow>'
            . '<summary class="blogAdminPage__action '
            . 'blogAdminPage__action--duplicate" '
            . 'title="Duplicar o a&ntilde;adir idioma" '
            . 'aria-label="Duplicar o a&ntilde;adir idioma" '
            . 'aria-describedby="blog-row-title-'
            . $summary->localizationPublicId() . '">'
            . $this->actionIcon('duplicate', "Duplicar o a\u{00F1}adir idioma")
            . '</summary>'
            . '<div class="blogAdminPage__languagePanel" '
            . 'data-blog-language-panel>'
            . '<h2 id="' . $titleId . '">Duplicar o a&ntilde;adir idioma</h2>'
            . '<p id="' . $descriptionId . '">Elige un destino. Siempre se '
            . 'crear&aacute; un borrador privado nuevo; el contenido publicado y '
            . 'su historial no se modifican.</p>'
            . '<form method="post" action="'
            . $this->path($basePath, '/posts/duplicate') . '" '
            . 'aria-describedby="' . $descriptionId . ' ' . $outcomeId . '" '
            . 'data-blog-language-form>'
            . $this->csrfInput($csrf)
            . '<input type="hidden" name="post" value="'
            . $this->escape($summary->postPublicId()) . '">'
            . '<input type="hidden" name="locale" value="'
            . $this->escape($summary->locale()) . '">'
            . '<input type="hidden" name="lock_version" value="'
            . $summary->lockVersion() . '">'
            . '<input type="hidden" name="operation_id" value="'
            . $this->escape($this->requestIds->generateV4()) . '" '
            . 'data-blog-language-operation>'
            . '<fieldset><legend>Idioma de destino</legend>'
            . '<div class="blogAdminPage__languageOptions">' . $options
            . '</div></fieldset>'
            . '<p id="' . $outcomeId . '" role="status" aria-live="polite" '
            . 'data-blog-language-outcome-status>Selecciona un idioma para '
            . 'confirmar el destino.</p>'
            . '<div class="blogAdminPage__languageActions '
            . 'webadminActionGroup">'
            . '<button class="webadminAction webadminAction--secondary" '
            . 'type="button" hidden data-blog-language-close>'
            . 'Cancelar</button>'
            . '<button class="webadminAction webadminAction--primary" '
            . 'type="submit" data-blog-language-submit>Crear '
            . 'borrador</button></div></form></div></details>';
    }

    /** @param array<string, string> $preservedQuery */
    private function sortableHeading(
        string $basePath,
        string $label,
        string $sort,
        BlogAdminCatalogQuery $query,
        array $preservedQuery
    ): string {
        $active = $query->sort() === $sort;
        $direction = $active
            ? ($query->direction() === BlogAdminCatalogQuery::DIRECTION_ASC
                ? BlogAdminCatalogQuery::DIRECTION_DESC
                : BlogAdminCatalogQuery::DIRECTION_ASC)
            : ($sort === BlogAdminCatalogQuery::SORT_UPDATED
                || $sort === BlogAdminCatalogQuery::SORT_ROBOTS
                    ? BlogAdminCatalogQuery::DIRECTION_DESC
                    : BlogAdminCatalogQuery::DIRECTION_ASC);
        $parameters = $preservedQuery;
        unset($parameters['offset']);
        $parameters['sort'] = $sort;
        $parameters['dir'] = $direction;
        $ariaSort = $active
            ? ' aria-sort="' . ($query->direction()
                === BlogAdminCatalogQuery::DIRECTION_ASC
                    ? 'ascending'
                    : 'descending') . '"'
            : '';
        $plainLabel = html_entity_decode($label, ENT_QUOTES | ENT_HTML5);
        $title = 'Ordenar por ' . $plainLabel;
        $indicator = $active
            ? ($query->direction() === BlogAdminCatalogQuery::DIRECTION_ASC
                ? '&#9650;'
                : '&#9660;')
            : '&#8645;';

        return '<th scope="col"' . $ariaSort . '><a class="'
            . 'blogAdminPage__sort" href="'
            . $this->pathWithQuery($basePath, '', $parameters)
            . '" data-blog-admin-sort title="' . $this->escape($title)
            . '" aria-label="' . $this->escape($title) . '">'
            . '<span>' . $label . '</span><span aria-hidden="true">'
            . $indicator . '</span></a></th>';
    }

    /** @param array<string, string> $publicPaths */
    private function catalogFilter(
        string $basePath,
        BlogAdminCatalogQuery $query,
        array $publicPaths,
        bool $showAnalytics,
        int $periodDays
    ): string {
        $statusOptions = '<option value=""'
            . ($query->status() === null ? ' selected' : '')
            . '>Todos</option>';
        foreach ([
            BlogPostVariant::DRAFT => 'Borrador',
            BlogPostVariant::PUBLISHED => 'Publicado',
        ] as $status => $label) {
            $statusOptions .= '<option value="' . $status . '"'
                . ($query->status() === $status ? ' selected' : '') . '>'
                . $label . '</option>';
        }

        $localeOptions = '<option value=""'
            . ($query->locale() === null ? ' selected' : '')
            . '>Todos</option>';
        foreach ($publicPaths as $locale => $publicPath) {
            if (!is_string($locale) || !is_string($publicPath)) {
                throw new InvalidArgumentException(
                    'Invalid Blog locale filter presentation.'
                );
            }
            $localeOptions .= '<option value="' . $this->escape($locale) . '"'
                . ($query->locale() === $locale ? ' selected' : '') . '>'
                . $this->escape(strtoupper($locale)) . '</option>';
        }

        $analyticsField = '';
        $analyticsNotices = '';
        if ($showAnalytics) {
            $periodOptions = '';
            foreach ([7, 30, 90] as $days) {
                $periodOptions .= '<option value="' . $days . '"'
                    . ($days === $periodDays ? ' selected' : '') . '>'
                    . $days . ' d&iacute;as</option>';
            }
            $analyticsField = '<div><label for="blog-analytics-period">'
                . 'M&eacute;tricas de los &uacute;ltimos</label>'
                . '<select id="blog-analytics-period" name="period">'
                . $periodOptions . '</select></div>';
            $analyticsNotices = '<p class="blogAdminPage__analyticsNotice">'
                . 'Datos propios de visitantes que han aceptado la '
                . 'medici&oacute;n anal&iacute;tica. La identificaci&oacute;n es '
                . 'seud&oacute;nima por navegador; no se guarda la IP.</p>'
                . '<p class="blogAdminPage__analyticsNotice" '
                . 'id="blog-analytics-bounce-help"><strong>Rebote del '
                . 'Blog:</strong> sesiones de entrada sin m&aacute;s de 10 '
                . 'segundos de interacci&oacute;n ni una segunda p&aacute;gina '
                . 'vista.</p>';
        }

        $pageSizeOptions = '';
        foreach (BlogAdminCatalogQuery::pageSizes() as $pageSize) {
            $pageSizeOptions .= '<option value="' . $pageSize . '"'
                . ($query->pageSize() === $pageSize ? ' selected' : '') . '>'
                . $pageSize . '</option>';
        }

        return '<form id="blog-admin-filter-form" '
            . 'class="blogAdminPage__analyticsFilter" method="get" '
            . 'action="' . $this->path($basePath, '') . '" '
            . 'data-blog-admin-filter-form>'
            . '<div><label for="blog-admin-search">Buscar por '
            . 't&iacute;tulo o slug</label><input id="blog-admin-search" '
            . 'type="search" name="q" value="'
            . $this->escape($query->search() ?? '') . '" minlength="'
            . BlogAdminCatalogQuery::MIN_SEARCH_CHARACTERS . '" maxlength="'
            . BlogAdminCatalogQuery::MAX_SEARCH_CHARACTERS . '" '
            . 'autocomplete="off" aria-controls="blog-admin-results" '
            . 'data-blog-admin-live-search></div>'
            . '<div><label for="blog-admin-status">Estado</label>'
            . '<select id="blog-admin-status" name="status" '
            . 'aria-controls="blog-admin-results">' . $statusOptions
            . '</select></div>'
            . '<div><label for="blog-admin-locale">Idioma</label>'
            . '<select id="blog-admin-locale" name="locale" '
            . 'aria-controls="blog-admin-results">' . $localeOptions
            . '</select></div>'
            . '<div><label for="blog-admin-page-size">Resultados por '
            . 'p&aacute;gina</label><select id="blog-admin-page-size" '
            . 'name="per_page" aria-controls="blog-admin-results">'
            . $pageSizeOptions . '</select></div>'
            . '<input type="hidden" name="sort" value="'
            . $this->escape($query->sort()) . '">'
            . '<input type="hidden" name="dir" value="'
            . $this->escape($query->direction()) . '">'
            . $analyticsField
            . '<button class="webadminAction webadminAction--primary" '
            . 'type="submit">Aplicar filtros</button>'
            . '<a class="webadminAction webadminAction--secondary" href="'
            . $this->path($basePath, '')
            . '" data-blog-admin-filter-reset>Limpiar filtros</a></form>'
            . $analyticsNotices;
    }

    private function analyticsCells(
        ?BlogArticleAnalyticsSummary $metric
    ): string {
        if ($metric === null) {
            return str_repeat(
                '<td class="blogAdminPage__compactCell"><span '
                    . 'aria-label="Sin datos">&mdash;</span></td>',
                3
            ) . str_repeat(
                '<td><span aria-label="Sin datos">&mdash;</span></td>',
                2
            );
        }

        $bounce = $metric->landingSessions() === 0
            ? '<span aria-label="Sin sesiones de entrada">&mdash;</span>'
            : $this->escape(number_format(
                $metric->bounceRatePercentage(),
                1,
                ',',
                ''
            )) . '%';

        return '<td class="blogAdminPage__compactCell">'
            . $metric->pageViews() . '</td>'
            . '<td class="blogAdminPage__compactCell">'
            . $metric->uniqueVisitors() . '</td>'
            . '<td class="blogAdminPage__compactCell">'
            . $metric->returningVisitors() . '</td><td>'
            . $this->formatDuration(
                $metric->averageEngagementMilliseconds()
            ) . '</td><td>'
            . $bounce . '</td>';
    }

    private function authorCell(BlogPostSummary $summary): string
    {
        $author = $summary->authorName();
        if ($author === null || trim($author) === '') {
            return '<span aria-label="Autor sin nombre">&mdash;</span>';
        }

        return $this->escape($author);
    }

    private function categoryCell(BlogPostSummary $summary): string
    {
        $items = '';
        foreach ($summary->categoryNames() as $categoryName) {
            $items .= '<li>' . $this->escape($categoryName) . '</li>';
        }

        return $items === ''
            ? '<span aria-label="Sin categor&iacute;as">&mdash;</span>'
            : '<ul class="blogAdminPage__categoryStack">' . $items . '</ul>';
    }

    private function robotsCell(BlogPostSummary $summary): string
    {
        $preferences = $summary->robotsPreferences();

        return '<ul class="blogAdminPage__robots" '
            . 'aria-label="Directivas para robots"><li>'
            . $this->booleanStatusIcon('Index', $preferences->index())
            . '</li><li>'
            . $this->booleanStatusIcon('Follow', $preferences->follow())
            . '</li></ul>';
    }

    private function seoScoreCell(?BlogSeoScore $score): string
    {
        if ($score === null) {
            return '<span class="blogAdminPage__seoUnavailable" '
                . 'aria-label="Puntuaci&oacute;n SEO no disponible">'
                . '&mdash;</span>';
        }

        $band = $score->band();
        if (!in_array($band, [
            BlogSeoScore::BAND_RED,
            BlogSeoScore::BAND_ORANGE,
            BlogSeoScore::BAND_GREEN,
        ], true)) {
            throw new InvalidArgumentException(
                'Invalid Blog SEO score band presentation.'
            );
        }

        $percentage = $score->percentage();
        $label = $score->label();
        $valueText = sprintf(
            'SEO editorial: %d%%, %d de %d comprobaciones correctas, %s',
            $percentage,
            $score->goodChecks(),
            $score->totalChecks(),
            $label
        );

        return '<span class="blogAdminPage__seoScore '
            . 'blogAdminPage__seoScore--' . $band . '" role="meter" '
            . 'aria-label="Puntuaci&oacute;n SEO" aria-valuemin="0" '
            . 'aria-valuemax="100" aria-valuenow="' . $percentage . '" '
            . 'aria-valuetext="' . $this->escape($valueText) . '">'
            . $percentage . '%</span>';
    }

    private function booleanStatusIcon(string $label, bool $enabled): string
    {
        $state = $enabled ? 'activado' : 'desactivado';
        $accessible = $label . ' ' . $state;
        $drawing = $enabled
            ? '<path d="m5 12 4 4L19 6"/>'
            : '<path d="m6 6 12 12M18 6 6 18"/>';

        return '<span class="blogAdminPage__statusIcon '
            . ($enabled
                ? 'blogAdminPage__statusIcon--enabled'
                : 'blogAdminPage__statusIcon--disabled')
            . '" title="' . $this->escape($accessible) . '" '
            . 'aria-label="' . $this->escape($accessible) . '">'
            . '<svg viewBox="0 0 24 24" width="20" height="20" '
            . 'fill="none" stroke="currentColor" stroke-width="2.4" '
            . 'stroke-linecap="round" stroke-linejoin="round" '
            . 'focusable="false" aria-hidden="true">' . $drawing . '</svg>'
            . '<span class="webadmin-srOnly">'
            . $this->escape($accessible) . '</span></span>';
    }

    private function actionIcon(string $icon, string $label): string
    {
        $drawing = match ($icon) {
            'view' => '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/>'
                . '<circle cx="12" cy="12" r="2.5"/>',
            'edit' => '<path d="m4 20 4.5-1 10-10a2.1 2.1 0 0 0-3-3l-10 10L4 20Z"/>'
                . '<path d="m14.5 7.5 3 3"/>',
            'url' => '<path d="M10 13a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7-7l-1 1"/>'
                . '<path d="M14 11a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7 7l1-1"/>',
            'duplicate' => '<rect x="8" y="8" width="11" height="11" rx="1.5"/>'
                . '<path d="M16 8V5.5A1.5 1.5 0 0 0 14.5 4h-9A1.5 1.5 0 0 0 4 5.5v9A1.5 1.5 0 0 0 5.5 16H8"/>',
            'delete' => '<path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13M10 11v5M14 11v5"/>',
            'retire' => '<path d="M3 3l18 18M10.5 5.2A10.5 10.5 0 0 1 12 5c6.5 0 10 7 10 7a18 18 0 0 1-3 4.1M6.2 6.2C3.5 8 2 12 2 12s3.5 7 10 7c1 0 2-.2 2.8-.5M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
            'restore' => '<path d="M4 8V4m0 0h4M4 4l4 4a7 7 0 1 1-2 7"/>',
            default => throw new InvalidArgumentException(
                'Invalid Blog action icon.'
            ),
        };

        return '<svg viewBox="0 0 24 24" width="20" height="20" '
            . 'fill="none" stroke="currentColor" stroke-width="1.9" '
            . 'stroke-linecap="round" stroke-linejoin="round" '
            . 'focusable="false" aria-hidden="true">' . $drawing . '</svg>'
            . '<span class="webadmin-srOnly">' . $this->escape($label)
            . '</span>';
    }

    private function compactMetricHeader(string $icon, string $label): string
    {
        $drawing = match ($icon) {
            'views' => '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/>'
                . '<circle cx="12" cy="12" r="2.5"/>',
            'unique-visitors' => '<circle cx="9" cy="8" r="3"/>'
                . '<path d="M3.5 19c.5-4 2.3-6 5.5-6s5 2 5.5 6"/>'
                . '<circle cx="17" cy="9" r="2"/>'
                . '<path d="M15.5 14c3.2-.2 4.8 1.5 5 4"/>',
            'returning' => '<path d="M7 7H3v-4"/>'
                . '<path d="M3.5 7.5A9 9 0 1 1 3 16"/>'
                . '<path d="M12 7v5l3 2"/>',
            default => throw new InvalidArgumentException(
                'Invalid Blog metric icon.'
            ),
        };
        $escapedLabel = $this->escape($label);

        return '<th class="blogAdminPage__compactHeading" scope="col" '
            . 'title="No ordenable: la m&eacute;trica se calcula para la '
            . 'p&aacute;gina visible">'
            . '<span class="blogAdminPage__metricLabel" tabindex="0" '
            . 'title="' . $escapedLabel . '">'
            . '<svg viewBox="0 0 24 24" width="20" height="20" '
            . 'fill="none" stroke="currentColor" stroke-width="1.8" '
            . 'stroke-linecap="round" stroke-linejoin="round" '
            . 'focusable="false" aria-hidden="true">' . $drawing . '</svg>'
            . '<span class="webadmin-srOnly">' . $escapedLabel
            . '</span></span></th>';
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
            . '<button class="webadminAction webadminAction--primary" '
            . 'type="submit">Crear borrador y abrir editor</button>'
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
        ?WebAdminShellContext $shell = null,
        bool $privateDraftPublicationReady = false
    ): string {
        $identity = $this->identityFields($variant);
        $publish = '';
        if ($canPublish) {
            if (
                $privateDraftPublicationReady
                && $variant->status() === BlogPostVariant::DRAFT
            ) {
                $publish = '<p><a class="webadminAction '
                    . 'webadminAction--primary" href="' . $this->pathWithQuery(
                    $basePath,
                    '/editor',
                    [
                        'post' => $variant->postPublicId(),
                        'locale' => $variant->locale(),
                    ]
                ) . '">Editar y publicar desde el editor visual</a></p>';
            } else {
                $transition = $variant->status() === BlogPostVariant::PUBLISHED
                    ? [
                        'suffix' => '/posts/unpublish',
                        'label' => 'Retirar',
                        'class' => 'webadminAction--danger',
                        'confirmation' => ' data-blog-confirm-form '
                            . 'data-blog-confirm-action="unpublish" '
                            . 'data-blog-title="'
                            . $this->escape($variant->draft()->h1()) . '"',
                    ]
                    : [
                        'suffix' => '/posts/publish',
                        'label' => 'Publicar',
                        'class' => 'webadminAction--primary',
                        'confirmation' => '',
                    ];
                $publish = '<form method="post" action="'
                    . $this->path($basePath, $transition['suffix']) . '"'
                    . $transition['confirmation'] . '>'
                    . $this->csrfInput($csrf) . $identity
                    . '<button class="webadminAction '
                    . $transition['class'] . '" type="submit">'
                    . $transition['label']
                    . '</button></form>';
            }
        }

        $editor = $variant->status() === BlogPostVariant::DRAFT
            ? '<form method="post" action="'
                . $this->path($basePath, '/posts/save') . '">'
                . $this->csrfInput($csrf) . $identity
                . $this->editorialFields($variant)
                . '<button class="webadminAction webadminAction--primary" '
                . 'type="submit">Guardar cambios</button></form>'
            : '<p role="status">Retira la variante antes de editar su '
                . 'contenido.</p><div aria-label="Contenido publicado">'
                . $this->editorialFields($variant, true) . '</div>';
        $preview = '<p><a class="webadminAction webadminAction--secondary" '
            . 'href="' . $this->pathWithQuery(
            $basePath,
            '/posts/preview',
            [
                'post' => $variant->postPublicId(),
                'locale' => $variant->locale(),
            ]
        ) . '">Abrir lectura privada del contenido guardado</a>. Esta '
            . 'representaci&oacute;n es textual, sin medios ni estilos '
            . 'p&uacute;blicos. Guarda primero cualquier cambio pendiente.</p>';

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
            . $this->backToBlog($basePath)
            . '</article>',
            $basePath,
            '/blog/posts/edit',
            $shell
        );
    }

    /**
     * @param list<BlogPostSummary> $replacementCandidates
     */
    public function urlManager(
        string $basePath,
        string $csrf,
        BlogPostVariant $variant,
        ?BlogUrlResolution $resolution,
        array $replacementCandidates,
        ?WebAdminShellContext $shell = null
    ): string {
        if (count($replacementCandidates) > BlogAdminCatalogQuery::OVERFLOW_LIMIT) {
            throw new InvalidArgumentException(
                'Invalid Blog URL replacement presentation.'
            );
        }
        $slug = $variant->draft()->slug();
        $state = $resolution?->state();
        $stateCopy = match ($state) {
            BlogUrlResolution::ACTIVE =>
                'La URL est&aacute; publicada y responde con normalidad.',
            BlogUrlResolution::TEMPORARY_NOT_FOUND =>
                'La URL est&aacute; retirada temporalmente y responde 404. '
                    . 'Es la opci&oacute;n segura mientras se decide si se '
                    . 'republica o existe un sustituto equivalente.',
            BlogUrlResolution::GONE =>
                'La URL est&aacute; marcada como retirada definitivamente y '
                    . 'responde 410.',
            BlogUrlResolution::REDIRECT =>
                'La URL redirige con 301 a la publicaci&oacute;n equivalente '
                    . 'seleccionada.',
            default => 'Este borrador todav&iacute;a no tiene una URL '
                . 'hist&oacute;rica publicada.',
        };
        $actions = '';
        if (
            $slug !== null
            && $resolution !== null
            && $state !== BlogUrlResolution::ACTIVE
        ) {
            $identity = $this->csrfInput($csrf)
                . '<input type="hidden" name="post" value="'
                . $this->escape($variant->postPublicId()) . '">'
                . '<input type="hidden" name="locale" value="'
                . $this->escape($variant->locale()) . '">'
                . '<input type="hidden" name="lock_version" value="'
                . $variant->lockVersion() . '">'
                . '<input type="hidden" name="historical_slug" value="'
                . $this->escape($slug) . '">';
            $gone = '<form method="post" class="blogAdminPage__inlineAction" '
                . 'action="' . $this->path(
                    $basePath,
                    '/posts/url-resolution'
                ) . '" data-blog-confirm-form '
                . 'data-blog-confirm-action="gone" data-blog-title="'
                . $this->escape($variant->draft()->h1()) . '">'
                . $identity
                . '<input type="hidden" name="resolution" value="gone">'
                . '<input type="hidden" name="replacement_post" value="">'
                . '<button class="webadminAction webadminAction--danger" '
                . 'type="submit">'
                . 'Marcar como 410</button></form>';
            $options = '';
            foreach ($replacementCandidates as $candidate) {
                if (!$candidate instanceof BlogPostSummary) {
                    throw new InvalidArgumentException(
                        'Invalid Blog URL replacement presentation.'
                    );
                }
                if (
                    $candidate->status() !== BlogPostVariant::PUBLISHED
                    || $candidate->locale() !== $variant->locale()
                    || $candidate->postPublicId() === $variant->postPublicId()
                ) {
                    continue;
                }
                $options .= '<option value="'
                    . $this->escape($candidate->postPublicId()) . '">'
                    . $this->escape($candidate->h1()) . '</option>';
            }
            $redirect = $options === ''
                ? '<p>No hay otra publicaci&oacute;n activa en este idioma '
                    . 'que pueda proponerse como sustituta.</p>'
                : '<form method="post" action="' . $this->path(
                    $basePath,
                    '/posts/url-resolution'
                ) . '" data-blog-confirm-form '
                    . 'data-blog-confirm-action="redirect" data-blog-title="'
                    . $this->escape($variant->draft()->h1()) . '">'
                    . $identity
                    . '<input type="hidden" name="resolution" '
                    . 'value="redirect">'
                    . '<label for="blog-url-replacement">Publicaci&oacute;n '
                    . 'equivalente</label><select id="blog-url-replacement" '
                    . 'name="replacement_post" required><option value="">'
                    . 'Selecciona una publicaci&oacute;n</option>' . $options
                    . '</select><button class="webadminAction '
                    . 'webadminAction--primary" type="submit">'
                    . 'Crear redirecci&oacute;n '
                    . '301</button></form>';
            $actions = '<section aria-labelledby="blog-url-decision-title">'
                . '<h2 id="blog-url-decision-title">Decisi&oacute;n SEO '
                . 'expl&iacute;cita</h2><p>Mant&eacute;n el 404 temporal si se '
                . 'puede republicar. Usa 410 solo si el contenido desaparece '
                . 'definitivamente y 301 &uacute;nicamente hacia una '
                . 'publicaci&oacute;n realmente equivalente.</p><div '
                . 'class="blogAdminPage__rowActions">' . $gone . '</div>'
                . $redirect . '</section>';
        }
        $path = $slug === null
            ? ''
            : '<p>Ruta: <code>/' . $this->escape($variant->locale())
                . '/&hellip;/' . $this->escape($slug) . '</code></p>';

        return $this->page(
            'Estado de la URL',
            '<article class="blogAdminPage" '
            . 'aria-labelledby="blog-url-title"><h1 id="blog-url-title">'
            . 'Estado de la URL</h1><p>Art&iacute;culo: <strong>'
            . $this->escape($variant->draft()->h1()) . '</strong></p>'
            . $path . '<p role="status">' . $stateCopy . '</p>' . $actions
            . $this->backToBlog($basePath) . '</article>',
            $basePath,
            '/blog/posts/url',
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
            'Lectura privada del contenido guardado',
            '<p role="status"><strong>Lectura privada del contenido '
            . 'guardado.</strong> Es una representaci&oacute;n textual sin '
            . 'medios ni estilos p&uacute;blicos. No crea una URL p&uacute;blica '
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
            . '<nav aria-label="Acciones de la lectura privada"><ul>'
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

    public function copyOperationFailed(
        string $basePath,
        string $postPublicId,
        string $locale,
        string $issueCode,
        ?WebAdminShellContext $shell = null
    ): string {
        $message = match ($issueCode) {
            \App\Core\Blog\BlogException::IDEMPOTENCY_CONFLICT =>
                'Esta solicitud ya se utiliz&oacute; con otros datos. No se ha '
                    . 'creado un segundo borrador.',
            \App\Core\Blog\BlogException::COPY_RESULT_TRASHED =>
                'El borrador creado por esta solicitud est&aacute; en la '
                    . 'Papelera. No se ha creado otro; rest&aacute;uralo para '
                    . 'continuar.',
            \App\Core\Blog\BlogException::LOCALE_CONFLICT =>
                'Ese idioma ya existe en el art&iacute;culo. Revisa el listado '
                    . 'o la Papelera antes de volver a intentarlo.',
            \App\Core\Blog\BlogException::LOCK_CONFLICT =>
                'El art&iacute;culo cambi&oacute; mientras ten&iacute;as abierto el '
                    . 'di&aacute;logo. No se ha repetido la operaci&oacute;n.',
            \App\Core\Blog\BlogException::POST_NOT_FOUND,
            \App\Core\Blog\BlogException::VARIANT_NOT_FOUND =>
                'El art&iacute;culo de origen ya no est&aacute; disponible. No se '
                    . 'ha creado ning&uacute;n borrador.',
            default => 'No hemos podido completar la copia ahora. No se ha '
                . 'creado un borrador parcial; puedes volver al listado e '
                . 'intentarlo de nuevo.',
        };
        $editor = $this->pathWithQuery($basePath, '/editor', [
            'post' => $postPublicId,
            'locale' => $locale,
        ]);

        return $this->page(
            'No se pudo crear el borrador',
            '<article class="blogAdminPage" '
            . 'aria-labelledby="blog-copy-failed-title">'
            . '<h1 id="blog-copy-failed-title">No se pudo crear el borrador</h1>'
            . '<p role="alert">' . $message . '</p>'
            . '<div class="webadminActionGroup"><a class="webadminAction '
            . 'webadminAction--primary" href="' . $this->path($basePath, '')
            . '">Volver al listado</a><a class="webadminAction '
            . 'webadminAction--secondary" href="' . $editor
            . '">Volver al art&iacute;culo de origen</a></div></article>',
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

    private function tableStatus(string $status): string
    {
        [$modifier, $label] = match ($status) {
            BlogPostVariant::DRAFT => ['draft', 'Borrador'],
            BlogPostVariant::PUBLISHED => ['published', 'Publicado'],
            self::TABLE_STATUS_DELETED => ['deleted', 'Eliminado'],
            default => throw new InvalidArgumentException(
                'Invalid Blog table status presentation.'
            ),
        };

        return '<span class="blogAdminPage__postStatus '
            . 'blogAdminPage__postStatus--' . $modifier . '">'
            . '<span class="blogAdminPage__postStatusLed" '
            . 'aria-hidden="true"></span><span>' . $label . '</span></span>';
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

    private function pagination(
        string $basePath,
        int $offset,
        bool $hasNext,
        array $preservedQuery = [],
        int $pageSize = BlogAdminCatalogQuery::DEFAULT_PAGE_SIZE
    ): string {
        return $this->paginationForPath(
            $basePath,
            $offset,
            $hasNext,
            $preservedQuery,
            true,
            $pageSize
        );
    }

    /** @param array<string, string> $preservedQuery */
    private function paginationForPath(
        string $path,
        int $offset,
        bool $hasNext,
        array $preservedQuery = [],
        bool $reactive = false,
        int $pageSize = BlogService::DEFAULT_LIST_LIMIT
    ): string {
        if (
            !BlogAdminCatalogQuery::supportsPageSize($pageSize)
            || $offset % $pageSize !== 0
        ) {
            throw new InvalidArgumentException(
                'Invalid Blog pagination presentation.'
            );
        }
        $items = '';
        if ($offset > 0) {
            $previous = max(
                0,
                $offset - $pageSize
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
                - $pageSize
        ) {
            $items .= '<li><a rel="next" href="'
                . $this->pathWithQuery(
                    $path,
                    '',
                    $preservedQuery + [
                        'offset' => (string) (
                            $offset + $pageSize
                        ),
                    ]
                )
                . '">P&aacute;gina siguiente</a></li>';
        }

        if ($items === '' && !$reactive) {
            return '';
        }

        return '<nav class="blogAdminPage__pagination" '
            . 'aria-label="Paginaci&oacute;n de art&iacute;culos"'
            . ($reactive ? ' data-blog-admin-pagination' : '')
            . '><p>P&aacute;gina ' . (intdiv($offset, $pageSize) + 1)
            . '</p>' . ($items === '' ? '' : '<ul>' . $items . '</ul>')
            . '</nav>';
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
