<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\Http\BlogLocalePresentation;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Document\BlogHeadingLevelPolicy;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTextProjector;
use App\Core\Blog\StructuredContent\Editing\BlogStructuredDraft;
use App\Core\Blog\StructuredContent\Editing\BlogEditorTechnicalLimitCatalog;
use App\Core\Blog\StructuredContent\Presentation\BlogHeadingPresetCatalog;
use App\Core\Blog\StructuredContent\Presentation\BlogH1ModuleCatalog;
use App\Core\Blog\StructuredContent\Presentation\BlogHeaderSelection;
use App\Core\Blog\StructuredContent\Presentation\BlogHeroCatalog;
use App\Core\Blog\Seo\BlogSeoAnalysis;
use App\Core\Blog\Tags\BlogTagService;
use App\Core\WebAdmin\Http\WebAdminPageAssets;
use App\Core\WebAdmin\Http\WebAdminShellContext;
use App\Core\WebAdmin\Http\WebAdminShellContextFactory;
use App\Core\WebAdmin\Http\WebAdminShellRenderer;
use App\Core\WebAdmin\Media\MediaPickerReference;
use App\Core\WebAdmin\Media\Http\WebAdminMediaPickerHtmlRenderer;
use InvalidArgumentException;

/** Private, dependency-free presentation for the structured Blog editor. */
final class BlogStructuredEditorHtmlRenderer
{
    public const STYLESHEET_PATH = '/assets/modules/blog/blog-admin.css';
    public const SCRIPT_PATH = '/assets/modules/blog/blog-editor.js';
    public const MAX_MEDIA_OPTIONS = 248;
    public const MAX_CATEGORY_OPTIONS = 100;
    public const MAX_TAG_OPTIONS = BlogTagService::MAX_TAGS_PER_VARIANT;
    public const MAX_REVISION_SUMMARIES = 100;

    private const BLOCK_LABELS = [
        'paragraph' => 'Texto',
        'heading' => 'Encabezado',
        'list' => 'Lista',
        'callout' => 'Destacado',
        'link' => 'Enlace independiente',
        'image' => 'Imagen',
        'video' => 'V&iacute;deo de YouTube',
        'embed' => 'HTML',
        'cta' => 'Bot&oacute;n',
    ];

    private readonly WebAdminShellRenderer $shellRenderer;
    private readonly BlogHeadingPresetCatalog $headingPresetCatalog;
    private readonly BlogEditorTechnicalLimitCatalog $technicalLimits;
    private readonly BlogHeadingLevelPolicy $headingLevelPolicy;
    private readonly BlogHeroCatalog $heroCatalog;
    private readonly BlogH1ModuleCatalog $h1ModuleCatalog;
    private readonly WebAdminMediaPickerHtmlRenderer $mediaPicker;

    public function __construct(
        private readonly BlogDocumentCodec $codec = new BlogDocumentCodec(),
        private readonly BlogDocumentTextProjector $projector =
            new BlogDocumentTextProjector(),
        ?WebAdminShellRenderer $shellRenderer = null,
        ?BlogHeadingPresetCatalog $headingPresetCatalog = null,
        ?BlogEditorTechnicalLimitCatalog $technicalLimits = null,
        ?BlogHeroCatalog $heroCatalog = null,
        ?BlogH1ModuleCatalog $h1ModuleCatalog = null,
        ?WebAdminMediaPickerHtmlRenderer $mediaPicker = null,
        ?BlogHeadingLevelPolicy $headingLevelPolicy = null
    ) {
        $this->shellRenderer = $shellRenderer ?? new WebAdminShellRenderer();
        $this->headingPresetCatalog = $headingPresetCatalog
            ?? BlogHeadingPresetCatalog::defaults();
        $this->technicalLimits = $technicalLimits
            ?? new BlogEditorTechnicalLimitCatalog();
        $this->heroCatalog = $heroCatalog ?? new BlogHeroCatalog();
        $this->h1ModuleCatalog = $h1ModuleCatalog
            ?? new BlogH1ModuleCatalog();
        $this->mediaPicker = $mediaPicker
            ?? new WebAdminMediaPickerHtmlRenderer();
        $this->headingLevelPolicy = $headingLevelPolicy
            ?? new BlogHeadingLevelPolicy();
    }

    /**
     * @param list<BlogEditorMediaOption> $mediaOptions
     * @param list<BlogEditorRevisionSummary> $revisionSummaries
     * @param list<BlogEditorCategoryOption> $categoryOptions
     * @param list<BlogEditorTagOption> $tagOptions
     * @param list<string> $editorStylesheets
     */
    public function render(
        string $basePath,
        #[\SensitiveParameter] string $csrf,
        BlogPostVariant $variant,
        BlogDocument $document,
        #[\SensitiveParameter] string $canonicalJson,
        array $mediaOptions = [],
        array $revisionSummaries = [],
        bool $failed = false,
        bool $canPublish = false,
        bool $canAssignCategories = false,
        ?BlogSeoAnalysis $seoAnalysis = null,
        ?string $publicPath = null,
        ?WebAdminShellContextFactory $shellFactory = null,
        #[\SensitiveParameter] ?string $sessionToken = null,
        array $categoryOptions = [],
        bool $layoutEditorReady = false,
        array $headingDefaults = [],
        ?BlogStructuredDraft $workingSnapshot = null,
        bool $privateDraftPublicationReady = false,
        int $categoryWorkspaceVersion = 0,
        bool $dummyCategoryAssigned = false,
        bool $canUploadMedia = false,
        ?BlogEditorPreviewSandboxPolicy $previewSandbox = null,
        array $editorStylesheets = [],
        bool $canAssignTags = false,
        array $tagOptions = [],
        int $tagWorkspaceVersion = 0,
        bool $canViewTags = false
    ): string {
        $basePath = $this->basePath($basePath);
        $this->assertCsrfPresentation($csrf);
        $this->assertDocumentPresentation(
            $variant,
            $document,
            $canonicalJson,
            $workingSnapshot?->compatibilityDraft()
        );
        $this->assertOptions(
            $mediaOptions,
            $revisionSummaries,
            $categoryOptions,
            $tagOptions
        );
        $headingDefaults = $this->headingDefaults($headingDefaults);
        if ($categoryWorkspaceVersion < 0) {
            throw new InvalidArgumentException(
                'Invalid Blog editor category workspace version.'
            );
        }
        if ($tagWorkspaceVersion < 0) {
            throw new InvalidArgumentException(
                'Invalid Blog editor tag workspace version.'
            );
        }

        $presentationDraft = $workingSnapshot?->compatibilityDraft()
            ?? $variant->draft();
        $readOnly = $variant->status() !== BlogPostVariant::DRAFT
            && !$privateDraftPublicationReady;
        $identity = $this->identityFields($variant);
        $previewUrl = $this->query($basePath . '/editor/preview', [
            'post' => $variant->postPublicId(),
            'locale' => $variant->locale(),
        ]);
        $formId = 'blog-editor-form';
        $body = '<article class="blogEditor" '
            . 'aria-labelledby="blog-editor-title">'
            . '<h1 id="blog-editor-title" '
            . 'class="webadminShell-visuallyHidden">Editor visual del Blog'
            . '</h1>'
            . ($readOnly
                ? '<p role="status">Retira la variante antes de modificarla '
                    . 'o restaurar una revisi&oacute;n.</p>'
                : '')
            . ($failed
                ? '<p role="alert" aria-live="assertive">No se pudieron '
                    . 'guardar los cambios. Revisa los campos y vuelve a '
                    . 'intentarlo.</p>'
                : '')
            . '<form id="' . $formId . '" class="blogEditor__form" '
            . 'method="post" action="'
            . $this->path($basePath . '/editor/save')
            . '" data-blog-editor data-blog-editor-readonly="'
            . ($readOnly ? 'true' : 'false')
            . '" data-blog-layout-editor-ready="'
            . ($layoutEditorReady ? 'true' : 'false')
            . '" data-blog-heading-presets="'
            . $this->jsonAttribute(
                $this->headingPresetCatalog->toSafeArray()
            )
            . '" data-blog-heading-defaults="'
            . $this->jsonAttribute($headingDefaults)
            . '" data-blog-heading-policy="'
            . $this->jsonAttribute($this->headingLevelPolicy->toSafeArray())
            . '" data-blog-hero-catalog="'
            . $this->jsonAttribute($this->heroCatalog->toSafeArray())
            . '" data-blog-h1-module-catalog="'
            . $this->jsonAttribute($this->h1ModuleCatalog->toSafeArray())
            . '" data-blog-header-selection="'
            . $this->jsonAttribute(
                BlogHeaderSelection::forDocument(
                    $document,
                    $this->heroCatalog,
                    $this->h1ModuleCatalog
                )->toArray()
            )
            . '" data-blog-header-label="HERO'
            . '" data-blog-technical-limits="'
            . $this->jsonAttribute($this->technicalLimits->toSafeArray())
            . ($previewSandbox === null
                ? ''
                : '" data-blog-advanced-preview-style-nonce="'
                    . $this->escape($previewSandbox->styleNonce())
                    . '" data-blog-advanced-preview-csp="'
                    . $this->escape(
                        $previewSandbox->contentSecurityPolicy()
                    ))
            . '">'
            . $this->hidden('csrf', $csrf)
            . $identity
            . $this->hidden('document_json', $canonicalJson)
            . $this->documentEditor(
                $document,
                $mediaOptions,
                $readOnly
            )
            . '</form>'
            . $this->mediaDialog(
                $basePath,
                $csrf,
                $formId,
                $mediaOptions,
                $readOnly,
                $canUploadMedia
            )
            . $this->editorActionBar(
                $basePath,
                $csrf,
                $variant,
                $previewUrl,
                $formId,
                $readOnly,
                $canPublish,
                $privateDraftPublicationReady,
                $categoryWorkspaceVersion,
                $tagWorkspaceVersion
            )
            . '</article>';

        $inspector = $this->inspector(
            $basePath,
            $csrf,
            $variant,
            $document,
            $readOnly,
            $canPublish,
            $canAssignCategories,
            $seoAnalysis,
            $formId,
            $publicPath,
            $categoryOptions,
            $presentationDraft,
            $privateDraftPublicationReady,
            $categoryWorkspaceVersion,
            $dummyCategoryAssigned,
            $canAssignTags,
            $tagOptions,
            $tagWorkspaceVersion,
            $canViewTags
        );
        $assets = new WebAdminPageAssets(
            array_merge([
                self::STYLESHEET_PATH,
                WebAdminMediaPickerHtmlRenderer::STYLESHEET_PATH,
            ], $editorStylesheets),
            [
                self::SCRIPT_PATH,
                WebAdminMediaPickerHtmlRenderer::SCRIPT_PATH,
            ]
        );
        if ($shellFactory !== null && $sessionToken !== null) {
            $shell = $shellFactory->create(
                $sessionToken,
                $csrf,
                '/blog/editor',
                $inspector,
                $assets
            );
        } else {
            $adminBase = substr($basePath, 0, -strlen('/blog')) ?: '/admin';
            $shell = new WebAdminShellContext(
                basePath: $adminBase,
                logoutCsrf: null,
                activePath: '/blog/editor',
                trustedInspectorHtml: $inspector,
                assets: $assets
            );
        }

        return $this->shellRenderer->render(
            'Editor visual del Blog',
            $body,
            $shell
        );
    }

    private function editorActionBar(
        string $basePath,
        string $csrf,
        BlogPostVariant $variant,
        string $previewUrl,
        string $formId,
        bool $readOnly,
        bool $canPublish,
        bool $privateDraftPublicationReady,
        int $categoryWorkspaceVersion,
        int $tagWorkspaceVersion
    ): string {
        $publicationStatus = $this->publicationStatus($variant);
        $html = '<div class="blogEditor__save blogEditor__actionBar '
            . 'webadminActionGroup" '
            . 'role="group" aria-label="Acciones del art&iacute;culo">'
            . '<a class="webadminAction webadminAction--secondary" '
            . 'data-blog-editor-preview href="' . $previewUrl
            . '">Vista previa</a><a class="webadminAction '
            . 'webadminAction--secondary" href="'
            . $this->query($basePath . '/editor/revisions', [
                'post' => $variant->postPublicId(),
                'locale' => $variant->locale(),
            ])
            . '">Revisiones</a><button class="webadminAction '
            . 'webadminAction--secondary" type="submit" form="' . $formId
            . '" data-blog-editor-save'
            . ($readOnly ? ' disabled' : '') . '>'
            . ($privateDraftPublicationReady
                ? 'Guardar borrador' : 'Guardar documento')
            . '</button>';

        if ($canPublish && !$readOnly) {
            $published = $variant->status() === BlogPostVariant::PUBLISHED;
            $publishPath = $privateDraftPublicationReady
                ? '/editor/publish'
                : '/posts/publish';
            if ($privateDraftPublicationReady || !$published) {
                $html .= '<form id="blog-editor-publish-form" method="post" '
                    . 'data-blog-editor-publish-form action="'
                    . $this->path($basePath . $publishPath) . '">'
                    . $this->hidden('csrf', $csrf)
                    . $this->identityFields($variant)
                    . ($privateDraftPublicationReady
                        ? $this->hidden(
                            'category_workspace_version',
                            (string) $categoryWorkspaceVersion
                        ) . $this->hidden(
                            'tag_workspace_version',
                            (string) $tagWorkspaceVersion
                        )
                        : '')
                    . '<button class="webadminAction '
                    . 'webadminAction--primary" type="submit">'
                    . 'Publicar</button></form>';
            }
        }

        return $html . '<p data-blog-editor-status data-blog-editor-form="'
            . $formId . '" role="status" aria-live="polite"></p>'
            . '<p class="blogEditor__publicationStatus" '
            . 'data-blog-editor-publication-status="'
            . $publicationStatus['value'] . '" data-blog-editor-form="'
            . $formId . '" aria-label="Estado del artículo: '
            . $publicationStatus['label'] . '">'
            . $publicationStatus['label'] . '</p></div>';
    }

    private function inspector(
        string $basePath,
        string $csrf,
        BlogPostVariant $variant,
        BlogDocument $document,
        bool $readOnly,
        bool $canPublish,
        bool $canAssignCategories,
        ?BlogSeoAnalysis $seoAnalysis,
        string $formId,
        ?string $publicPath,
        array $categoryOptions,
        BlogDraft $presentationDraft,
        bool $privateDraftPublicationReady,
        int $categoryWorkspaceVersion,
        bool $dummyCategoryAssigned,
        bool $canAssignTags,
        array $tagOptions,
        int $tagWorkspaceVersion,
        bool $canViewTags
    ): string {
        return '<div class="blogEditor__inspector" data-blog-inspector '
            . 'data-blog-editor-form="' . $formId . '">'
            . '<div class="blogEditor__inspectorTabs" role="tablist" '
            . 'aria-label="Herramientas del art&iacute;culo">'
            . '<button id="blog-editor-tab-entry" type="button" role="tab" '
            . 'aria-controls="blog-editor-panel-entry" aria-selected="true" '
            . 'data-blog-inspector-tab="entry">Entrada</button>'
            . '<button id="blog-editor-tab-block" type="button" role="tab" '
            . 'aria-controls="blog-editor-panel-block" aria-selected="false" '
            . 'tabindex="-1" data-blog-inspector-tab="block">Bloque</button>'
            . '<button id="blog-editor-tab-seo" type="button" role="tab" '
            . 'aria-controls="blog-editor-panel-seo" aria-selected="false" '
            . 'tabindex="-1" data-blog-inspector-tab="seo">SEO</button></div>'
            . '<section id="blog-editor-panel-entry" role="tabpanel" '
            . 'class="blogEditor__inspectorPanel" '
            . 'data-blog-inspector-panel="entry" '
            . 'aria-labelledby="blog-editor-tab-entry">'
            . '<h2 id="blog-editor-entry-title">Configurar entrada</h2>'
            . $this->entryIdentity(
                $variant,
                $presentationDraft,
                $publicPath,
                $formId
            )
            . ($canAssignCategories
                ? $this->categoryAssignment(
                    $basePath,
                    $csrf,
                    $variant,
                    $categoryOptions,
                    $privateDraftPublicationReady,
                    $categoryWorkspaceVersion
                )
                : '')
            . (($canAssignTags || $canViewTags)
                ? ($canAssignTags
                    ? $this->tagAssignment(
                        $basePath,
                        $csrf,
                        $variant,
                        $tagOptions,
                        $tagWorkspaceVersion
                    )
                    : $this->tagReadOnly($variant, $tagOptions))
                : '')
            . $this->metadata(
                $presentationDraft,
                $readOnly,
                $formId,
                $dummyCategoryAssigned
            )
            . $this->templateControl($document, $readOnly, $formId)
            . $this->publicationControl(
                $basePath,
                $csrf,
                $variant,
                $canPublish,
                $privateDraftPublicationReady
            ) . '</section>'
            . '<section id="blog-editor-panel-block" role="tabpanel" '
            . 'class="blogEditor__inspectorPanel" hidden '
            . 'data-blog-inspector-panel="block" '
            . 'aria-labelledby="blog-editor-tab-block">'
            . '<h2 id="blog-editor-block-panel-title">Editar bloque</h2>'
            . '<div data-blog-block-inspector><p '
            . 'class="blogEditor__inspectorEmpty">Selecciona una caja del '
            . 'lienzo para configurarla.</p></div></section>'
            . '<section id="blog-editor-panel-seo" role="tabpanel" '
            . 'class="blogEditor__inspectorPanel" hidden '
            . 'data-blog-inspector-panel="seo" '
            . 'aria-labelledby="blog-editor-tab-seo">'
            . $this->seoPanel($basePath, $seoAnalysis, $formId)
            . '</section></div>';
    }

    /** @param list<BlogEditorTagOption> $tagOptions */
    private function tagAssignment(
        string $basePath,
        string $csrf,
        BlogPostVariant $variant,
        array $tagOptions,
        int $tagWorkspaceVersion
    ): string {
        [$items, $names] = $this->tagItems($tagOptions);
        $inputId = 'blog-editor-tags-csv';
        $helpId = 'blog-editor-tags-help';
        $statusId = 'blog-editor-tags-status';

        return '<div class="blogEditor__tags" '
            . 'aria-labelledby="blog-editor-tags-title">'
            . '<h3 id="blog-editor-tags-title">Etiquetas</h3>'
            . '<p>Se guardan para <strong>'
            . $this->escape(strtoupper($variant->locale()))
            . '</strong>. Quitar una etiqueta solo la desasigna de este '
            . 'art&iacute;culo.</p><form method="post" action="'
            . $this->path($basePath . '/tags/assign') . '" '
            . 'data-blog-tag-assignment-form>'
            . $this->hidden('csrf', $csrf)
            . $this->hidden('post', $variant->postPublicId())
            . $this->hidden('locale', $variant->locale())
            . $this->hidden('lock_version', (string) $variant->lockVersion())
            . $this->hidden(
                'tag_workspace_version',
                (string) $tagWorkspaceVersion
            )
            . '<label for="' . $inputId . '">A&ntilde;adir etiquetas</label>'
            . '<input id="' . $inputId . '" name="tags" type="text" '
            . 'dir="auto" '
            . 'value="' . $this->escape(implode(', ', $names)) . '" '
            . 'maxlength="' . BlogTagService::MAX_CSV_BYTES . '" '
            . 'autocomplete="off" aria-describedby="' . $helpId . ' '
            . $statusId . '" data-blog-tag-csv>'
            . '<p id="' . $helpId . '" class="blogEditor__fieldHelp">'
            . 'Hasta ' . BlogTagService::MAX_TAGS_PER_VARIANT
            . ' etiquetas separadas por comas. Con JavaScript, pulsa Intro '
            . 'o escribe una coma para a&ntilde;adirlas.</p>'
            . '<ul class="blogEditor__tagList" data-blog-tag-list '
            . 'aria-label="Etiquetas asignadas">' . $items . '</ul>'
            . '<button type="submit">Guardar etiquetas</button>'
            . '<p id="' . $statusId . '" data-blog-tag-assignment-status '
            . 'role="status" aria-live="polite"></p></form></div>';
    }

    /** @param list<BlogEditorTagOption> $tagOptions */
    private function tagReadOnly(
        BlogPostVariant $variant,
        array $tagOptions
    ): string {
        [$items] = $this->tagItems($tagOptions);

        return '<div class="blogEditor__tags" data-blog-tag-readonly '
            . 'aria-labelledby="blog-editor-tags-title">'
            . '<h3 id="blog-editor-tags-title">Etiquetas</h3>'
            . '<p>Asignadas para <strong>'
            . $this->escape(strtoupper($variant->locale()))
            . '</strong>. Vista de solo lectura.</p>'
            . '<ul class="blogEditor__tagList" '
            . 'aria-label="Etiquetas asignadas">' . $items . '</ul></div>';
    }

    /**
     * @param list<BlogEditorTagOption> $tagOptions
     * @return array{string, list<string>}
     */
    private function tagItems(array $tagOptions): array
    {
        $items = '';
        $names = [];
        foreach ($tagOptions as $option) {
            $names[] = $option->name();
            $items .= '<li data-blog-tag data-blog-tag-slug="'
                . $this->escape($option->slug()) . '"><span dir="auto">'
                . $this->escape($option->name()) . '</span></li>';
        }
        if ($items === '') {
            $items = '<li data-blog-tag-empty>No hay etiquetas asignadas.</li>';
        }

        return [$items, $names];
    }

    private function entryIdentity(
        BlogPostVariant $variant,
        BlogDraft $draft,
        ?string $publicPath,
        string $formId
    ): string {
        $publicationStatus = $this->publicationStatus($variant);
        $path = $publicPath === null ? '' : rtrim($publicPath, '/');
        $slug = $draft->slug();
        $url = $path === ''
            ? 'Se completará al guardar un slug.'
            : $path . ($slug === null ? '/…' : '/' . $slug);

        return '<dl class="blogEditor__entryIdentity" data-blog-entry-identity '
            . 'data-blog-public-base="' . $this->escape($path) . '">'
            . '<div><dt>Idioma</dt>'
            . '<dd>' . $this->entryLocale($variant->locale())
            . '</dd></div><div><dt>Estado</dt><dd '
            . 'data-blog-editor-publication-status="'
            . $publicationStatus['value'] . '" data-blog-editor-form="'
            . $formId . '">' . $publicationStatus['label']
            . '</dd></div><div><dt>Ruta p&uacute;blica</dt><dd><code '
            . 'data-blog-public-url>' . $this->escape($url)
            . '</code></dd></div></dl>';
    }

    private function entryLocale(string $locale): string
    {
        $asset = BlogLocalePresentation::flagAsset($locale);
        $visual = $asset === null
            ? '<span class="blogEditor__entryLocaleFallback" '
                . 'aria-hidden="true">&#9673;</span>'
            : '<img src="' . $this->escape($asset)
                . '" alt="" aria-hidden="true" width="24" height="18">';

        return '<span class="blogEditor__entryLocale">' . $visual . '<span>'
            . $this->escape(strtoupper($locale)) . '</span></span>';
    }

    /** @return array{value: string, label: string} */
    private function publicationStatus(BlogPostVariant $variant): array
    {
        return match ($variant->status()) {
            BlogPostVariant::DRAFT => [
                'value' => BlogPostVariant::DRAFT,
                'label' => 'Borrador',
            ],
            BlogPostVariant::PUBLISHED => [
                'value' => BlogPostVariant::PUBLISHED,
                'label' => 'Publicado',
            ],
        };
    }

    /** @param list<BlogEditorCategoryOption> $categoryOptions */
    private function categoryAssignment(
        string $basePath,
        string $csrf,
        BlogPostVariant $variant,
        array $categoryOptions,
        bool $privateDraftPublicationReady,
        int $categoryWorkspaceVersion
    ): string {
        $locale = $variant->locale();
        $choices = '';
        foreach ($categoryOptions as $option) {
            $inputId = 'blog-editor-category-' . $option->publicId();
            $choices .= '<label for="' . $inputId . '"><input id="'
                . $inputId . '" type="checkbox" name="categories[]" value="'
                . $this->escape($option->publicId()) . '"'
                . ($option->assigned() ? ' checked' : '') . '> '
                . $this->escape($option->name()) . '</label>';
        }
        if ($choices === '') {
            $choices = '<p data-blog-category-empty>No hay categor&iacute;as '
                . 'disponibles en este idioma.</p>';
        }

        $categoryBasePath = $this->path($basePath . '/categories');

        return '<div class="blogEditor__categories" '
            . 'aria-labelledby="blog-editor-categories-title" '
            . 'data-blog-category-tools data-blog-category-endpoint="'
            . $categoryBasePath . '" data-blog-category-locale="'
            . $this->escape($locale) . '">'
            . '<h3 id="blog-editor-categories-title">Categor&iacute;as</h3>'
            . '<p>Se muestran las categor&iacute;as de <strong>'
            . $this->escape(strtoupper($locale))
            . '</strong>. El idioma de esta entrada no cambia aqu&iacute;.</p>'
            . '<form method="post" action="'
            . $this->path($basePath . '/categories/assign') . '" '
            . 'data-blog-category-assignment-form '
            . 'data-blog-category-locale="' . $this->escape($locale) . '">'
            . $this->hidden('csrf', $csrf)
            . $this->hidden('post', $variant->postPublicId())
            . ($privateDraftPublicationReady
                ? $this->hidden('locale', $variant->locale())
                    . $this->hidden(
                        'lock_version',
                        (string) $variant->lockVersion()
                    )
                    . $this->hidden(
                        'category_workspace_version',
                        (string) $categoryWorkspaceVersion
                    )
                : '')
            . '<fieldset><legend>Asignaci&oacute;n del art&iacute;culo</legend>'
            . '<div class="blogEditor__categoryChoices">' . $choices
            . '</div></fieldset><button type="submit">Guardar categor&iacute;as'
            . '</button><p data-blog-category-assignment-status role="status" '
            . 'aria-live="polite"></p></form>'
            . '<form method="post" action="' . $categoryBasePath
            . '/create" class="blogEditor__categoryQuick" '
            . 'data-blog-category-quick-form>'
            . $this->hidden('csrf', $csrf)
            . $this->hidden('category', '')
            . $this->hidden('locale', $locale)
            . '<label for="blog-editor-category-quick-name">A&ntilde;adir '
            . 'nueva categor&iacute;a</label><div><input '
            . 'id="blog-editor-category-quick-name" name="name" '
            . 'type="text" autocomplete="off" required><button '
            . 'type="submit">A&ntilde;adir</button></div><p '
            . 'data-blog-category-quick-status role="status" '
            . 'aria-live="polite"></p></form>'
            . '<button type="button" class="blogEditor__categoryManagerOpen" '
            . 'data-blog-category-manager-open>Gestionar categor&iacute;as</button>'
            . '<dialog class="blogEditor__categoryDialog" '
            . 'data-blog-category-manager aria-labelledby="'
            . 'blog-editor-category-manager-title"><div '
            . 'class="blogEditor__categoryDialogShell"><header><div><p '
            . 'class="blogEditor__categoryDialogEyebrow">Idioma '
            . $this->escape(strtoupper($locale)) . '</p><h3 id="'
            . 'blog-editor-category-manager-title">Gestionar categor&iacute;as'
            . '</h3></div><button type="button" '
            . 'data-blog-category-manager-close aria-label="Cerrar gestor">'
            . '&times;</button></header><form method="post" action="'
            . $categoryBasePath . '/create" '
            . 'data-blog-category-manager-create>'
            . $this->hidden('csrf', $csrf)
            . $this->hidden('category', '')
            . $this->hidden('locale', $locale)
            . '<div class="blogEditor__categoryManagerFields"><label>Nombre'
            . '<input name="name" type="text" autocomplete="off" required>'
            . '</label><label>Slug <span>(opcional)</span><input name="slug" '
            . 'type="text" inputmode="url" autocomplete="off" '
            . 'pattern="[a-z0-9]+(?:-[a-z0-9]+)*"></label><button '
            . 'type="submit">Crear categor&iacute;a</button></div></form><div '
            . 'data-blog-category-manager-list aria-live="polite"></div><p '
            . 'data-blog-category-manager-status role="status" '
            . 'aria-live="polite"></p></div></dialog><noscript><p><a href="'
            . $this->query($basePath . '/categories', ['locale' => $locale])
            . '">Gestionar categor&iacute;as</a></p></noscript></div>';
    }

    private function templateControl(
        BlogDocument $document,
        bool $readOnly,
        string $formId
    ): string {
        $options = '';
        foreach ([
            BlogDocumentTemplateRegistry::ARTICLE_BASIC =>
                'Sin imagen destacada (compatible)',
            BlogDocumentTemplateRegistry::ARTICLE_HERO00 =>
                'Hero 00 &middot; tarjeta transl&uacute;cida',
            BlogDocumentTemplateRegistry::ARTICLE_HERO06 =>
                'Hero 06 &middot; inmersivo centrado',
            BlogDocumentTemplateRegistry::ARTICLE_COVER =>
                'Hero 07 &middot; compacto',
        ] as $key => $label) {
            $options .= '<option value="' . $key . '"'
                . ($document->template() === $key ? ' selected' : '')
                . '>' . $label . '</option>';
        }

        return '<div class="blogEditor__template" '
            . 'data-blog-header-settings><label '
            . 'class="blogEditor__templateFallbackLabel" '
            . 'for="blog-editor-template">Cabecera del art&iacute;culo</label><select '
            . 'id="blog-editor-template" form="' . $formId . '" '
            . 'data-blog-template-select'
            . ($readOnly ? ' disabled' : '') . '>' . $options
            . '</select><div data-blog-header-controls></div></div>';
    }

    private function seoPanel(
        string $basePath,
        ?BlogSeoAnalysis $analysis,
        string $formId
    ): string {
        $endpoint = $this->path($basePath . '/editor/seo-analysis');
        if ($analysis === null) {
            return '<section class="blogEditor__seo" '
                . 'aria-labelledby="blog-editor-seo-panel-title" '
                . 'data-blog-seo-panel data-blog-editor-form="' . $formId
                . '" data-blog-seo-endpoint="'
                . $endpoint . '"><h2 id="blog-editor-seo-panel-title">'
                . 'Revisi&oacute;n SEO editorial</h2><p>Los avisos son '
                . 'orientativos: nunca bloquean el guardado ni la publicaci&oacute;n.'
                . '</p><p data-blog-seo-live role="status" aria-live="polite">'
                . 'Pendiente de analizar el contenido guardado.</p>'
                . '<div data-blog-seo-results></div></section>';
        }

        $payload = $analysis->toArray();

        return '<section class="blogEditor__seo" '
            . 'aria-labelledby="blog-editor-seo-panel-title" '
            . 'data-blog-seo-panel data-blog-editor-form="' . $formId
            . '" data-blog-seo-endpoint="'
            . $endpoint . '"><h2 id="blog-editor-seo-panel-title">'
            . 'Revisi&oacute;n SEO editorial</h2><p>Los avisos son '
            . 'orientativos: nunca bloquean el guardado ni la publicaci&oacute;n.'
            . '</p><p data-blog-seo-live role="status" aria-live="polite">'
            . 'An&aacute;lisis del contenido guardado.</p><div '
            . 'data-blog-seo-results>' . $this->seoResults($payload)
            . '</div></section>';
    }

    /** @param array<string, mixed> $payload */
    private function seoResults(array $payload): string
    {
        $summary = is_array($payload['summary'] ?? null)
            ? $payload['summary']
            : [];
        $html = '<div class="blogEditor__seoSummary" '
            . 'aria-label="Resumen SEO"><span data-status="good">Bien: '
            . (int) ($summary['good'] ?? 0) . '</span><span '
            . 'data-status="review">Revisar: '
            . (int) ($summary['review'] ?? 0) . '</span><span '
            . 'data-status="pending">Pendiente: '
            . (int) ($summary['pending'] ?? 0) . '</span></div>';

        $preview = is_array($payload['serp_preview'] ?? null)
            ? $payload['serp_preview']
            : [];
        $html .= '<article class="blogEditor__serp" '
            . 'aria-labelledby="blog-editor-serp-title"><h3 '
            . 'id="blog-editor-serp-title">Vista previa SERP ('
            . $this->escape((string) ($preview['locale'] ?? ''))
            . ')</h3><p class="blogEditor__serpTitle">'
            . $this->escape((string) ($preview['title'] ?? ''))
            . '</p><p class="blogEditor__serpUrl">'
            . $this->escape((string) ($preview['url'] ?? ''))
            . '</p><p>'
            . $this->escape((string) ($preview['description'] ?? ''))
            . '</p></article><ul class="blogEditor__seoChecks">';

        $checks = is_array($payload['checks'] ?? null)
            ? $payload['checks']
            : [];
        foreach ($checks as $check) {
            if (!is_array($check)) {
                continue;
            }
            $status = (string) ($check['status'] ?? 'pending');
            if (!in_array($status, ['good', 'review', 'pending'], true)) {
                $status = 'pending';
            }
            $html .= '<li data-status="' . $status . '"><p><strong>'
                . $this->escape((string) ($check['status_label'] ?? ''))
                . ': ' . $this->escape((string) ($check['label'] ?? ''))
                . '</strong></p><p>'
                . $this->escape((string) ($check['message'] ?? ''))
                . '</p></li>';
        }
        $html .= '</ul>';

        $competitors = is_array($payload['competing_pages'] ?? null)
            ? $payload['competing_pages']
            : [];
        if ($competitors !== []) {
            $html .= '<details class="blogEditor__seoCompetition"><summary>'
                . 'URLs a revisar</summary><ul>';
            foreach ($competitors as $competitor) {
                if (!is_array($competitor)) {
                    continue;
                }
                $html .= '<li><code>'
                    . $this->escape((string) ($competitor['url'] ?? ''))
                    . '</code> &mdash; '
                    . $this->escape((string) ($competitor['h1'] ?? ''))
                    . ' ('
                    . (($competitor['match'] ?? '') === 'complete'
                        ? 'coincidencia completa'
                        : 'coincidencia parcial')
                    . ')</li>';
            }
            $html .= '</ul></details>';
        }

        return $html;
    }

    private function publicationControl(
        string $basePath,
        string $csrf,
        BlogPostVariant $variant,
        bool $canPublish,
        bool $privateDraftPublicationReady
    ): string {
        if (!$canPublish) {
            return '';
        }

        $published = $variant->status() === BlogPostVariant::PUBLISHED;
        if (!$published) {
            return '';
        }

        return '<section class="blogEditor__publication" '
            . 'aria-labelledby="blog-editor-publication-title">'
            . '<h2 id="blog-editor-publication-title">Publicaci&oacute;n</h2>'
            . ($privateDraftPublicationReady
                ? '<p>La versi&oacute;n p&uacute;blica permanece intacta '
                    . 'hasta pulsar Publicar en la barra inferior.</p>'
                : '')
            . '<form method="post" action="'
            . $this->path($basePath . '/posts/unpublish')
            . '">' . $this->hidden('csrf', $csrf)
            . $this->identityFields($variant)
            . '<button type="submit">Retirar publicaci&oacute;n</button>'
            . '</form></section>';
    }

    private function metadata(
        BlogDraft $draft,
        bool $readOnly,
        string $formId,
        bool $dummyCategoryAssigned
    ): string {
        $readonly = $readOnly ? ' readonly' : '';
        $form = ' form="' . $formId . '"';

        $h1Feedback = 'blog-editor-h1-feedback';
        $slugFeedback = 'blog-editor-slug-feedback';
        $titleFeedback = 'blog-editor-seo-title-feedback';
        $descriptionFeedback = 'blog-editor-description-feedback';
        $excerptFeedback = 'blog-editor-excerpt-feedback';
        $robots = $draft->robotsPreferences();
        $robotsDisabled = $readOnly || $dummyCategoryAssigned;
        $robotsHelp = 'blog-editor-robots-help';

        return '<div class="blogEditor__metadata" '
            . 'aria-labelledby="blog-editor-metadata-title">'
            . '<h3 id="blog-editor-metadata-title">Contenido y metadatos</h3>'
            . '<p id="blog-editor-h1-help">El H1 pertenece al art&iacute;culo '
            . 'y nunca forma parte de sus bloques.</p><div '
            . 'class="blogEditor__metadataGrid">'
            . '<div><label for="blog-editor-h1">H1</label><input '
            . 'id="blog-editor-h1" name="h1" type="text"' . $form
            . ' data-blog-limit-scope="entry" data-blog-limit-field="h1"'
            . ' value="'
            . $this->escape($draft->h1())
            . '" aria-describedby="blog-editor-h1-help ' . $h1Feedback
            . '" aria-invalid="false" required' . $readonly . '>'
            . $this->metadataFeedback(
                $h1Feedback,
                'h1',
                BlogDraft::MAX_H1_BYTES,
                'Recomendaci&oacute;n SEO orientativa: 20&ndash;80 caracteres.'
            ) . '</div>'
            . '<div><label for="blog-editor-slug">Slug</label><input '
            . 'id="blog-editor-slug" name="slug" type="text"' . $form
            . ' data-blog-limit-scope="entry" data-blog-limit-field="slug"'
            . ' pattern="[a-z0-9]+(?:-[a-z0-9]+)*" value="'
            . $this->escape($draft->slug() ?? '')
            . '" autocapitalize="none" spellcheck="false" aria-describedby="'
            . $slugFeedback . '" aria-invalid="false"' . $readonly . '>'
            . $this->metadataFeedback(
                $slugFeedback,
                'slug',
                BlogDraft::MAX_SLUG_BYTES,
                'Recomendaci&oacute;n SEO orientativa: hasta 75 caracteres.'
            ) . '</div>'
            . '<div><label for="blog-editor-seo-title">Title SEO</label>'
            . '<input id="blog-editor-seo-title" name="seo_title" '
            . 'type="text"' . $form
            . ' data-blog-limit-scope="entry" data-blog-limit-field="seo_title"'
            . ' value="' . $this->escape($draft->seoTitle() ?? '') . '"'
            . ' aria-describedby="' . $titleFeedback
            . '" aria-invalid="false"' . $readonly . '>'
            . $this->metadataFeedback(
                $titleFeedback,
                'seo_title',
                BlogDraft::MAX_SEO_TITLE_BYTES,
                'Recomendaci&oacute;n SEO orientativa: 30&ndash;65 caracteres.'
            ) . '</div>'
            . '<div><label for="blog-editor-description">Meta description</label>'
            . '<textarea id="blog-editor-description" '
            . 'name="meta_description"' . $form
            . ' data-blog-limit-scope="entry" data-blog-limit-field="meta_description"'
            . ' aria-describedby="' . $descriptionFeedback
            . '" aria-invalid="false"'
            . $readonly . '>'
            . $this->escape($draft->metaDescription() ?? '')
            . '</textarea>' . $this->metadataFeedback(
                $descriptionFeedback,
                'meta_description',
                BlogDraft::MAX_META_DESCRIPTION_BYTES,
                'Recomendaci&oacute;n SEO orientativa: 120&ndash;160 caracteres.'
            ) . '</div>'
            . '<div class="blogEditor__metadataWide"><label '
            . 'for="blog-editor-excerpt">Extracto</label><textarea '
            . 'id="blog-editor-excerpt" name="excerpt"' . $form
            . ' data-blog-limit-scope="entry" data-blog-limit-field="excerpt"'
            . ' aria-describedby="' . $excerptFeedback
            . '" aria-invalid="false"' . $readonly . '>'
            . $this->escape($draft->excerpt() ?? '')
            . '</textarea>' . $this->metadataFeedback(
                $excerptFeedback,
                'excerpt',
                BlogDraft::MAX_EXCERPT_BYTES
            ) . '</div></div>'
            . '<fieldset class="blogEditor__robots" '
            . 'data-blog-robots-controls data-blog-dummy-category="'
            . ($dummyCategoryAssigned ? 'true' : 'false') . '">'
            . '<legend>Visibilidad en buscadores</legend>'
            . '<input type="hidden" name="robots_index" value="0"'
            . $form . '><label for="blog-editor-robots-index">'
            . '<input id="blog-editor-robots-index" name="robots_index" '
            . 'type="checkbox" value="1"' . $form
            . ($robots->index() && !$dummyCategoryAssigned ? ' checked' : '')
            . ($robotsDisabled ? ' disabled' : '')
            . ' aria-describedby="' . $robotsHelp . '"> Index</label>'
            . '<input type="hidden" name="robots_follow" value="0"'
            . $form . '><label for="blog-editor-robots-follow">'
            . '<input id="blog-editor-robots-follow" name="robots_follow" '
            . 'type="checkbox" value="1"' . $form
            . ($robots->follow() && !$dummyCategoryAssigned ? ' checked' : '')
            . ($robotsDisabled ? ' disabled' : '')
            . ' aria-describedby="' . $robotsHelp . '"> Follow</label>'
            . '<p id="' . $robotsHelp . '" role="note">'
            . ($dummyCategoryAssigned
                ? 'La categor&iacute;a interna de pruebas fuerza '
                    . '<strong>noindex,nofollow</strong>. Estos controles '
                    . 'permanecen bloqueados mientras est&eacute; asignada.'
                : 'Index permite mostrar la entrada en buscadores; Follow '
                    . 'permite seguir sus enlaces.')
            . '</p></fieldset></div>';
    }

    private function metadataFeedback(
        string $id,
        string $field,
        int $limit,
        string $advice = ''
    ): string {
        return '<p id="' . $id . '" class="blogEditor__fieldFeedback" '
            . 'data-blog-field-feedback="' . $field . '" '
            . 'data-blog-technical-limit="' . $limit . '" '
            . 'aria-live="polite">L&iacute;mite t&eacute;cnico: ' . $limit
            . ' bytes.' . ($advice === '' ? '' : ' ' . $advice) . '</p>';
    }

    /** @param list<BlogEditorMediaOption> $mediaOptions */
    private function documentEditor(
        BlogDocument $document,
        array $mediaOptions,
        bool $readOnly
    ): string {
        $mediaCatalog = '';
        foreach ($mediaOptions as $option) {
            $mediaCatalog .= '<option value="'
                . $this->escape($option->publicId()) . '"'
                . ($option->thumbnailUrl() === null
                    ? ''
                    : ' data-thumbnail-url="'
                        . $this->escape($option->thumbnailUrl()) . '"')
                . '>'
                . $this->escape($option->label()) . '</option>';
        }

        $summary = '';
        if ($document->version() === BlogDocument::LAYOUT_VERSION) {
            $summary = '<p data-blog-empty-state>Preparando el lienzo visual&hellip;</p>';
        } else {
            foreach ($document->blocks() as $position => $block) {
                $label = $position === 0
                    && ($block['type'] ?? null) === 'image'
                    && ($block['display'] ?? null) === 'cover'
                        ? 'HERO'
                        : (self::BLOCK_LABELS[$block['type']] ?? null);
                if ($label === null) {
                    throw new InvalidArgumentException(
                        'Invalid Blog editor document presentation.'
                    );
                }
                $summary .= '<div data-blog-static-block data-block-id="'
                    . $this->escape($block['id']) . '">Bloque '
                    . ($position + 1) . ': ' . $label . '</div>';
            }
        }
        if ($summary === '') {
            $summary = '<p data-blog-empty-state>El documento todav&iacute;a no '
                . 'contiene bloques.</p>';
        }

        $buttons = '';
        foreach (self::BLOCK_LABELS as $type => $label) {
            if (in_array(
                $type,
                ['heading', 'list', 'callout', 'link'],
                true
            )) {
                continue;
            }
            $disabled = $readOnly
                || ($type === 'image' && $mediaOptions === []);
            $addLabel = $type === 'embed' ? $label : lcfirst($label);
            $buttons .= '<button type="button" data-blog-add-block="'
                . $type . '"' . ($disabled ? ' disabled' : '')
                . '>A&ntilde;adir ' . $addLabel . '</button>';
        }

        return '<section class="blogEditor__document" '
            . 'aria-labelledby="blog-editor-document-title">'
            . '<h2 id="blog-editor-document-title">Vista del art&iacute;culo</h2>'
            . '<p class="blogEditor__canvasHelp">Texto re&uacute;ne '
            . 'p&aacute;rrafos, encabezados H2-H6, listas, citas y destacados. '
            . 'El H1 permanece en los datos de la entrada.</p>'
            . '<select id="blog-editor-media-catalog" '
            . 'data-blog-media-catalog hidden aria-hidden="true" '
            . 'tabindex="-1">' . $mediaCatalog . '</select>'
            . '<div class="blogEditor__canvas" data-blog-block-list '
            . 'data-max-blocks="' . BlogDocument::MAX_BLOCKS . '">'
            . $summary . '</div>'
            . '<div class="blogEditor__blockToolbar" role="group" '
            . 'aria-label="Continuar construyendo">'
            . '<p>A&ntilde;adir al final</p>' . $buttons . '</div>'
            . '<noscript><p role="status">Sin JavaScript se conservan los '
            . 'bloques actuales sin cambios; los metadatos siguen siendo '
            . 'editables.</p></noscript></section>';
    }

    /** @param list<BlogEditorMediaOption> $mediaOptions */
    private function mediaDialog(
        string $basePath,
        #[\SensitiveParameter] string $csrf,
        string $formId,
        array $mediaOptions,
        bool $readOnly,
        bool $canUploadMedia
    ): string {
        if ($readOnly) {
            return '';
        }

        $references = [];
        foreach ($mediaOptions as $option) {
            $references[] = new MediaPickerReference(
                $option->publicId(),
                $option->label(),
                $option->thumbnailUrl()
            );
        }
        $adminBase = substr($basePath, 0, -strlen('/blog')) ?: '/admin';

        return $this->mediaPicker->dialog(
            $adminBase,
            $csrf,
            $formId,
            $references,
            $canUploadMedia
        );
    }

    private function identityFields(BlogPostVariant $variant): string
    {
        return $this->hidden('post', $variant->postPublicId())
            . $this->hidden('locale', $variant->locale())
            . $this->hidden('lock_version', (string) $variant->lockVersion());
    }

    private function hidden(string $name, string $value): string
    {
        return '<input type="hidden" name="' . $name . '" value="'
            . $this->escape($value) . '">';
    }

    private function assertDocumentPresentation(
        BlogPostVariant $variant,
        BlogDocument $document,
        string $canonicalJson,
        ?BlogDraft $presentationDraft = null
    ): void {
        $expected = $this->codec->encode($document);
        $projected = $this->projector->project($document);
        $storedBody = ($presentationDraft ?? $variant->draft())->bodyText();
        if (
            !hash_equals($expected, $canonicalJson)
            || (
                !hash_equals($storedBody, $projected)
                && !hash_equals(
                    $this->normalizeLegacyBodyText($storedBody),
                    $projected
                )
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid Blog editor document presentation.'
            );
        }
    }

    private function normalizeLegacyBodyText(string $bodyText): string
    {
        $bodyText = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $bodyText);
        $paragraphs = preg_split('/\n[ ]*\n+/u', trim($bodyText));
        if (!is_array($paragraphs)) {
            throw new InvalidArgumentException(
                'Invalid Blog editor document presentation.'
            );
        }

        return implode("\n\n", array_values(array_filter(
            array_map('trim', $paragraphs),
            static fn (string $paragraph): bool => $paragraph !== ''
        )));
    }

    /**
     * @param list<BlogEditorMediaOption> $mediaOptions
     * @param list<BlogEditorRevisionSummary> $revisionSummaries
     * @param list<BlogEditorCategoryOption> $categoryOptions
     * @param list<BlogEditorTagOption> $tagOptions
     */
    private function assertOptions(
        array $mediaOptions,
        array $revisionSummaries,
        array $categoryOptions,
        array $tagOptions
    ): void {
        if (
            !array_is_list($mediaOptions)
            || count($mediaOptions) > self::MAX_MEDIA_OPTIONS
            || !array_is_list($revisionSummaries)
            || count($revisionSummaries) > self::MAX_REVISION_SUMMARIES
            || !array_is_list($categoryOptions)
            || count($categoryOptions) > self::MAX_CATEGORY_OPTIONS
            || !array_is_list($tagOptions)
            || count($tagOptions) > self::MAX_TAG_OPTIONS
        ) {
            throw new InvalidArgumentException(
                'Invalid Blog editor presentation options.'
            );
        }

        $mediaIds = [];
        foreach ($mediaOptions as $option) {
            if (
                !$option instanceof BlogEditorMediaOption
                || isset($mediaIds[$option->publicId()])
            ) {
                throw new InvalidArgumentException(
                    'Invalid Blog editor presentation options.'
                );
            }
            $mediaIds[$option->publicId()] = true;
        }

        $revisionIds = [];
        $revisionNumbers = [];
        foreach ($revisionSummaries as $summary) {
            if (
                !$summary instanceof BlogEditorRevisionSummary
                || isset($revisionIds[$summary->revisionPublicId()])
                || isset($revisionNumbers[$summary->revisionNumber()])
            ) {
                throw new InvalidArgumentException(
                    'Invalid Blog editor presentation options.'
                );
            }
            $revisionIds[$summary->revisionPublicId()] = true;
            $revisionNumbers[$summary->revisionNumber()] = true;
        }

        $categoryIds = [];
        foreach ($categoryOptions as $option) {
            if (
                !$option instanceof BlogEditorCategoryOption
                || isset($categoryIds[$option->publicId()])
            ) {
                throw new InvalidArgumentException(
                    'Invalid Blog editor presentation options.'
                );
            }
            $categoryIds[$option->publicId()] = true;
        }

        $tagSlugs = [];
        $previousSlug = null;
        foreach ($tagOptions as $option) {
            if (
                !$option instanceof BlogEditorTagOption
                || isset($tagSlugs[$option->slug()])
                || ($previousSlug !== null
                    && strcmp($previousSlug, $option->slug()) >= 0)
            ) {
                throw new InvalidArgumentException(
                    'Invalid Blog editor presentation options.'
                );
            }
            $tagSlugs[$option->slug()] = true;
            $previousSlug = $option->slug();
        }
    }

    private function assertCsrfPresentation(string $csrf): void
    {
        if (
            $csrf === ''
            || strlen($csrf) > 512
            || preg_match('//u', $csrf) !== 1
            || preg_match('/\p{Cc}/u', $csrf) === 1
        ) {
            throw new InvalidArgumentException(
                'Invalid Blog editor security presentation.'
            );
        }
    }

    private function basePath(string $basePath): string
    {
        $basePath = rtrim($basePath, '/');
        if (
            $basePath === ''
            || strlen($basePath) > 512
            || !str_starts_with($basePath, '/')
            || str_starts_with($basePath, '//')
            || str_contains($basePath, '//')
            || str_contains($basePath, '\\')
            || str_contains($basePath, '?')
            || str_contains($basePath, '#')
            || preg_match('/\s/u', $basePath) === 1
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $basePath) === 1
        ) {
            throw new InvalidArgumentException(
                'Invalid Blog editor base path.'
            );
        }

        $decoded = $basePath;
        $stable = false;
        for ($pass = 0; $pass < 8; ++$pass) {
            if (preg_match('/%(?:2f|5c)/i', $decoded) === 1) {
                throw new InvalidArgumentException(
                    'Invalid Blog editor base path.'
                );
            }
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                $stable = true;
                break;
            }
            $decoded = $next;
        }
        if (
            !$stable
            || preg_match('//u', $decoded) !== 1
            || preg_match('/\p{Cc}/u', $decoded) === 1
            || str_contains($decoded, '//')
            || str_contains($decoded, '\\')
        ) {
            throw new InvalidArgumentException(
                'Invalid Blog editor base path.'
            );
        }
        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException(
                    'Invalid Blog editor base path.'
                );
            }
        }

        return $basePath;
    }

    /** @param array<string, string> $parameters */
    private function query(string $path, array $parameters): string
    {
        return $this->escape(
            $path . '?' . http_build_query(
                $parameters,
                '',
                '&',
                PHP_QUERY_RFC3986
            )
        );
    }

    private function path(string $path): string
    {
        return $this->escape($path);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, array<string, string>>
     */
    private function headingDefaults(array $values): array
    {
        $fallback = [
            'preset' => $this->headingPresetCatalog->defaultKey(),
            'font_size' => 'default',
            'font_weight' => 'default',
            'text_color' => 'default',
            'text_align' => 'start',
        ];
        if ($values === []) {
            return array_fill_keys(
                ['h2', 'h3', 'h4', 'h5', 'h6'],
                $fallback
            );
        }
        $keys = array_keys($values);
        sort($keys, SORT_STRING);
        if ($keys !== ['h2', 'h3', 'h4', 'h5', 'h6']) {
            throw new InvalidArgumentException(
                'Invalid Blog heading defaults presentation.'
            );
        }
        foreach ($values as $level => $preference) {
            if (!is_array($preference)) {
                throw new InvalidArgumentException(
                    'Invalid Blog heading defaults presentation.'
                );
            }
            $preferenceKeys = array_keys($preference);
            sort($preferenceKeys, SORT_STRING);
            if (
                $preferenceKeys !== [
                    'font_size',
                    'font_weight',
                    'preset',
                    'text_align',
                    'text_color',
                ]
                || !is_string($preference['preset'] ?? null)
                || !$this->headingPresetCatalog->isAllowed(
                    $preference['preset']
                )
                || !in_array(
                    $preference['font_size'] ?? null,
                    ['default', 'small', 'large', 'xlarge'],
                    true
                )
                || !in_array(
                    $preference['font_weight'] ?? null,
                    ['default', 'regular', 'medium', 'semibold', 'bold'],
                    true
                )
                || !in_array(
                    $preference['text_color'] ?? null,
                    [
                        'default',
                        'color00',
                        'color01',
                        'color02',
                        'color03',
                        'color04',
                        'color05',
                    ],
                    true
                )
                || !in_array(
                    $preference['text_align'] ?? null,
                    ['start', 'center', 'end', 'justify'],
                    true
                )
            ) {
                throw new InvalidArgumentException(
                    'Invalid Blog heading defaults presentation.'
                );
            }
        }

        /** @var array<string, array<string, string>> $values */
        return $values;
    }

    /** @param array<mixed> $value */
    private function jsonAttribute(array $value): string
    {
        return $this->escape((string) json_encode(
            $value,
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
        ));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8'
        );
    }
}
