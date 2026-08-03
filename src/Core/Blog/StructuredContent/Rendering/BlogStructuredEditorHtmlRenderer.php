<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTextProjector;
use App\Core\Blog\Seo\BlogSeoAnalysis;
use App\Core\WebAdmin\Http\WebAdminPageAssets;
use App\Core\WebAdmin\Http\WebAdminShellContext;
use App\Core\WebAdmin\Http\WebAdminShellContextFactory;
use App\Core\WebAdmin\Http\WebAdminShellRenderer;
use InvalidArgumentException;

/** Private, dependency-free presentation for the structured Blog editor. */
final class BlogStructuredEditorHtmlRenderer
{
    public const STYLESHEET_PATH = '/assets/modules/blog/blog-admin.css';
    public const SCRIPT_PATH = '/assets/modules/blog/blog-editor.js';
    public const MAX_MEDIA_OPTIONS = 248;
    public const MAX_CATEGORY_OPTIONS = 100;
    public const MAX_REVISION_SUMMARIES = 100;

    private const BLOCK_LABELS = [
        'paragraph' => 'P&aacute;rrafo',
        'heading' => 'Encabezado',
        'list' => 'Lista',
        'callout' => 'Destacado',
        'link' => 'Enlace independiente',
        'image' => 'Imagen',
        'video' => 'V&iacute;deo de YouTube',
        'cta' => 'Llamada a la acci&oacute;n',
    ];

    private readonly WebAdminShellRenderer $shellRenderer;

    public function __construct(
        private readonly BlogDocumentCodec $codec = new BlogDocumentCodec(),
        private readonly BlogDocumentTextProjector $projector =
            new BlogDocumentTextProjector(),
        ?WebAdminShellRenderer $shellRenderer = null
    ) {
        $this->shellRenderer = $shellRenderer ?? new WebAdminShellRenderer();
    }

    /**
     * @param list<BlogEditorMediaOption> $mediaOptions
     * @param list<BlogEditorRevisionSummary> $revisionSummaries
     * @param list<BlogEditorCategoryOption> $categoryOptions
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
        array $categoryOptions = []
    ): string {
        $basePath = $this->basePath($basePath);
        $this->assertCsrfPresentation($csrf);
        $this->assertDocumentPresentation(
            $variant,
            $document,
            $canonicalJson
        );
        $this->assertOptions(
            $mediaOptions,
            $revisionSummaries,
            $categoryOptions
        );

        $readOnly = $variant->status() !== BlogPostVariant::DRAFT;
        $identity = $this->identityFields($variant);
        $previewUrl = $this->query($basePath . '/editor/preview', [
            'post' => $variant->postPublicId(),
            'locale' => $variant->locale(),
        ]);
        $revisionsUrl = $this->query($basePath . '/editor/revisions', [
            'post' => $variant->postPublicId(),
            'locale' => $variant->locale(),
        ]);

        $formId = 'blog-editor-form';
        $body = '<article class="blogEditor" '
            . 'aria-labelledby="blog-editor-title">'
            . '<header class="blogEditor__pageHeader"><div>'
            . '<p class="blogEditor__eyebrow">Edici&oacute;n visual</p>'
            . '<h1 id="blog-editor-title">Construir art&iacute;culo</h1>'
            . '<p>Trabaja de arriba abajo. La vista central conserva la '
            . 'jerarqu&iacute;a que se publicar&aacute; por SSR.</p></div>'
            . $this->editorNavigation(
                $basePath,
                $variant,
                $previewUrl,
                $revisionsUrl,
                $canAssignCategories
            ) . '</header>'
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
            . ($readOnly ? 'true' : 'false') . '">'
            . $this->hidden('csrf', $csrf)
            . $identity
            . $this->hidden('document_json', $canonicalJson)
            . $this->documentEditor(
                $document,
                $mediaOptions,
                $readOnly
            )
            . '<div class="blogEditor__save"><button type="submit"'
            . ($readOnly ? ' disabled' : '')
            . '>Guardar documento</button><p data-blog-editor-status '
            . 'role="status" aria-live="polite"></p></div></form>'
            . $this->revisionHistory(
                $basePath,
                $csrf,
                $variant,
                $revisionSummaries,
                $readOnly
            )
            . '<p><a href="' . $this->path($basePath)
            . '">Volver al Blog</a></p></article>';

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
            $categoryOptions
        );
        $assets = new WebAdminPageAssets(
            [self::STYLESHEET_PATH],
            [self::SCRIPT_PATH]
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

    private function editorNavigation(
        string $basePath,
        BlogPostVariant $variant,
        string $previewUrl,
        string $revisionsUrl,
        bool $canAssignCategories
    ): string {
        return '<nav class="blogEditor__navigation" '
            . 'aria-label="Acciones del art&iacute;culo"><ul><li><a href="'
            . $previewUrl . '" target="_blank" rel="noopener noreferrer">'
            . 'Vista previa guardada</a></li><li><a href="'
            . $revisionsUrl . '">Revisiones</a></li><li><a href="'
            . $this->query(
                $basePath . '/posts/new',
                ['post' => $variant->postPublicId()]
            ) . '">Otro idioma</a></li>'
            . ($canAssignCategories ? '<li><a href="' . $this->query(
                $basePath . '/categories/assign',
                [
                    'post' => $variant->postPublicId(),
                    'locale' => $variant->locale(),
                ]
            ) . '">Categor&iacute;as</a></li>' : '')
            . '</ul></nav>';
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
        array $categoryOptions
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
            . $this->entryIdentity($variant, $publicPath)
            . ($canAssignCategories
                ? $this->categoryAssignment(
                    $basePath,
                    $csrf,
                    $variant,
                    $categoryOptions
                )
                : '')
            . $this->metadata($variant, $readOnly, $formId)
            . $this->templateControl($document, $readOnly, $formId)
            . $this->publicationControl(
                $basePath,
                $csrf,
                $variant,
                $canPublish
            ) . '</section>'
            . '<section id="blog-editor-panel-block" role="tabpanel" '
            . 'class="blogEditor__inspectorPanel" hidden '
            . 'data-blog-inspector-panel="block" '
            . 'aria-labelledby="blog-editor-tab-block">'
            . '<h2 id="blog-editor-block-panel-title">Editar bloque</h2>'
            . '<div data-blog-block-inspector><p '
            . 'class="blogEditor__inspectorEmpty">Selecciona Editar en '
            . 'la vista central.</p></div></section>'
            . '<section id="blog-editor-panel-seo" role="tabpanel" '
            . 'class="blogEditor__inspectorPanel" hidden '
            . 'data-blog-inspector-panel="seo" '
            . 'aria-labelledby="blog-editor-tab-seo">'
            . $this->seoPanel($basePath, $seoAnalysis, $formId)
            . '</section></div>';
    }

    private function entryIdentity(
        BlogPostVariant $variant,
        ?string $publicPath
    ): string {
        $path = $publicPath === null ? '' : rtrim($publicPath, '/');
        $slug = $variant->draft()->slug();
        $url = $path === ''
            ? 'Se completará al guardar un slug.'
            : $path . ($slug === null ? '/…' : '/' . $slug);

        return '<dl class="blogEditor__entryIdentity" data-blog-entry-identity '
            . 'data-blog-public-base="' . $this->escape($path) . '">'
            . '<div><dt>Idioma</dt>'
            . '<dd>' . $this->escape(strtoupper($variant->locale()))
            . '</dd></div><div><dt>Estado</dt><dd>'
            . ($variant->status() === BlogPostVariant::DRAFT
                ? 'Borrador'
                : 'Publicado')
            . '</dd></div><div><dt>Ruta p&uacute;blica</dt><dd><code '
            . 'data-blog-public-url>' . $this->escape($url)
            . '</code></dd></div></dl>';
    }

    /** @param list<BlogEditorCategoryOption> $categoryOptions */
    private function categoryAssignment(
        string $basePath,
        string $csrf,
        BlogPostVariant $variant,
        array $categoryOptions
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
            $choices = '<p>No hay categor&iacute;as disponibles en este idioma.</p>';
        }

        return '<div class="blogEditor__categories" '
            . 'aria-labelledby="blog-editor-categories-title">'
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
            . '<fieldset><legend>Asignaci&oacute;n del art&iacute;culo</legend>'
            . '<div class="blogEditor__categoryChoices">' . $choices
            . '</div></fieldset><button type="submit">Guardar categor&iacute;as'
            . '</button><p data-blog-category-assignment-status role="status" '
            . 'aria-live="polite"></p></form><div '
            . 'class="blogEditor__entryActions"><a href="'
            . $this->path($basePath . '/categories/new')
            . '" target="_blank" rel="noopener noreferrer">Crear categor&iacute;a '
            . '(se abre aparte)</a><a href="'
            . $this->query($basePath . '/categories/assign', [
                'post' => $variant->postPublicId(),
                'locale' => $locale,
            ]) . '" target="_blank" rel="noopener noreferrer">Abrir gesti&oacute;n '
            . 'completa</a></div></div>';
    }

    private function templateControl(
        BlogDocument $document,
        bool $readOnly,
        string $formId
    ): string {
        $options = '';
        foreach ([
            BlogDocumentTemplateRegistry::ARTICLE_BASIC =>
                'Art&iacute;culo b&aacute;sico',
            BlogDocumentTemplateRegistry::ARTICLE_COVER =>
                'Art&iacute;culo con portada',
        ] as $key => $label) {
            $options .= '<option value="' . $key . '"'
                . ($document->template() === $key ? ' selected' : '')
                . '>' . $label . '</option>';
        }

        return '<div class="blogEditor__template"><label '
            . 'for="blog-editor-template">Plantilla visual</label><select '
            . 'id="blog-editor-template" form="' . $formId . '" '
            . 'data-blog-template-select'
            . ($readOnly ? ' disabled' : '') . '>' . $options
            . '</select></div>';
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
        bool $canPublish
    ): string {
        if (!$canPublish) {
            return '';
        }

        $published = $variant->status() === BlogPostVariant::PUBLISHED;

        return '<section class="blogEditor__publication" '
            . 'aria-labelledby="blog-editor-publication-title">'
            . '<h2 id="blog-editor-publication-title">Publicaci&oacute;n</h2>'
            . '<form method="post" action="' . $this->path(
                $basePath . ($published
                    ? '/posts/unpublish'
                    : '/posts/publish')
            ) . '">' . $this->hidden('csrf', $csrf)
            . $this->identityFields($variant)
            . '<button type="submit">'
            . ($published ? 'Retirar' : 'Publicar')
            . '</button></form></section>';
    }

    private function metadata(
        BlogPostVariant $variant,
        bool $readOnly,
        string $formId
    ): string {
        $draft = $variant->draft();
        $readonly = $readOnly ? ' readonly' : '';
        $form = ' form="' . $formId . '"';

        return '<div class="blogEditor__metadata" '
            . 'aria-labelledby="blog-editor-metadata-title">'
            . '<h3 id="blog-editor-metadata-title">Contenido y metadatos</h3>'
            . '<p id="blog-editor-h1-help">El H1 pertenece al art&iacute;culo '
            . 'y nunca forma parte de sus bloques.</p><div '
            . 'class="blogEditor__metadataGrid">'
            . '<div><label for="blog-editor-h1">H1</label><input '
            . 'id="blog-editor-h1" name="h1" type="text"' . $form
            . ' maxlength="'
            . BlogDraft::MAX_H1_BYTES . '" value="'
            . $this->escape($draft->h1())
            . '" aria-describedby="blog-editor-h1-help" required'
            . $readonly . '></div>'
            . '<div><label for="blog-editor-slug">Slug</label><input '
            . 'id="blog-editor-slug" name="slug" type="text"' . $form
            . ' maxlength="'
            . BlogDraft::MAX_SLUG_BYTES
            . '" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" value="'
            . $this->escape($draft->slug() ?? '')
            . '" autocapitalize="none" spellcheck="false"'
            . $readonly . '></div>'
            . '<div><label for="blog-editor-seo-title">Title SEO</label>'
            . '<input id="blog-editor-seo-title" name="seo_title" '
            . 'type="text"' . $form . ' maxlength="'
            . BlogDraft::MAX_SEO_TITLE_BYTES
            . '" value="' . $this->escape($draft->seoTitle() ?? '') . '"'
            . $readonly . '></div>'
            . '<div><label for="blog-editor-description">Meta description</label>'
            . '<textarea id="blog-editor-description" '
            . 'name="meta_description"' . $form . ' maxlength="'
            . BlogDraft::MAX_META_DESCRIPTION_BYTES . '"'
            . $readonly . '>'
            . $this->escape($draft->metaDescription() ?? '')
            . '</textarea></div>'
            . '<div class="blogEditor__metadataWide"><label '
            . 'for="blog-editor-excerpt">Extracto</label><textarea '
            . 'id="blog-editor-excerpt" name="excerpt"' . $form
            . ' maxlength="'
            . BlogDraft::MAX_EXCERPT_BYTES . '"' . $readonly . '>'
            . $this->escape($draft->excerpt() ?? '')
            . '</textarea></div></div></div>';
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
        foreach ($document->blocks() as $position => $block) {
            $label = self::BLOCK_LABELS[$block['type']] ?? null;
            if ($label === null) {
                throw new InvalidArgumentException(
                    'Invalid Blog editor document presentation.'
                );
            }
            $summary .= '<div data-blog-static-block data-block-id="'
                . $this->escape($block['id']) . '">Bloque '
                . ($position + 1) . ': ' . $label . '</div>';
        }
        if ($summary === '') {
            $summary = '<p data-blog-empty-state>El documento todav&iacute;a no '
                . 'contiene bloques.</p>';
        }

        $buttons = '<button type="button" data-blog-add-block="heading" '
            . 'data-blog-heading-level="2"'
            . ($readOnly ? ' disabled' : '')
            . '>A&ntilde;adir secci&oacute;n H2</button>'
            . '<button type="button" data-blog-add-block="heading" '
            . 'data-blog-heading-level="3"'
            . ($readOnly ? ' disabled' : '')
            . '>A&ntilde;adir art&iacute;culo H3</button>'
            . '<button type="button" data-blog-add-block="heading" '
            . 'data-blog-heading-level="4"'
            . ($readOnly ? ' disabled' : '')
            . '>A&ntilde;adir subapartado H4</button>'
            . '<button type="button" data-blog-add-block="heading" '
            . 'data-blog-heading-level="5"'
            . ($readOnly ? ' disabled' : '')
            . '>A&ntilde;adir subapartado H5</button>'
            . '<button type="button" data-blog-add-block="heading" '
            . 'data-blog-heading-level="6"'
            . ($readOnly ? ' disabled' : '')
            . '>A&ntilde;adir subapartado H6</button>';
        foreach (self::BLOCK_LABELS as $type => $label) {
            if ($type === 'heading') {
                continue;
            }
            $disabled = $readOnly
                || ($type === 'image' && $mediaOptions === []);
            $buttons .= '<button type="button" data-blog-add-block="'
                . $type . '"' . ($disabled ? ' disabled' : '')
                . '>A&ntilde;adir ' . lcfirst($label) . '</button>';
        }

        return '<section class="blogEditor__document" '
            . 'aria-labelledby="blog-editor-document-title">'
            . '<h2 id="blog-editor-document-title">Vista del art&iacute;culo</h2>'
            . '<p class="blogEditor__canvasHelp">Cada H2 abre una '
            . '<code>section</code>; cada H3 abre un <code>article</code> '
            . 'dentro de ella. Edita desde las acciones de cada bloque.</p>'
            . '<select data-blog-media-catalog hidden aria-hidden="true" '
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

    /** @param list<BlogEditorRevisionSummary> $summaries */
    private function revisionHistory(
        string $basePath,
        string $csrf,
        BlogPostVariant $variant,
        array $summaries,
        bool $readOnly
    ): string {
        $items = '';
        foreach ($summaries as $summary) {
            $detail = $this->query($basePath . '/editor/revisions', [
                'post' => $variant->postPublicId(),
                'locale' => $variant->locale(),
                'revision' => $summary->revisionPublicId(),
            ]);
            $items .= '<li><div class="blogEditor__revision"><p><a href="'
                . $detail . '">Revisi&oacute;n '
                . $summary->revisionNumber() . '</a> '
                . '<span>(versi&oacute;n editorial '
                . $summary->variantLockVersion() . ')</span></p><time datetime="'
                . $this->escape($summary->createdAt()->format(DATE_ATOM))
                . '">'
                . $this->escape($summary->createdAt()->format('Y-m-d H:i'))
                . ' UTC</time><form method="post" action="'
                . $this->path($basePath . '/editor/restore') . '">'
                . $this->hidden('csrf', $csrf)
                . $this->identityFields($variant)
                . $this->hidden(
                    'revision',
                    $summary->revisionPublicId()
                )
                . '<button type="submit"' . ($readOnly ? ' disabled' : '')
                . '>Restaurar esta revisi&oacute;n</button></form></div></li>';
        }
        if ($items === '') {
            $items = '<li>No hay revisiones guardadas.</li>';
        }

        return '<section class="blogEditor__revisions" '
            . 'aria-labelledby="blog-editor-revisions-title"><h2 '
            . 'id="blog-editor-revisions-title">Revisiones recientes</h2>'
            . '<ol>' . $items . '</ol></section>';
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
        string $canonicalJson
    ): void {
        $expected = $this->codec->encode($document);
        $projected = $this->projector->project($document);
        $storedBody = $variant->draft()->bodyText();
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
     */
    private function assertOptions(
        array $mediaOptions,
        array $revisionSummaries,
        array $categoryOptions
    ): void {
        if (
            !array_is_list($mediaOptions)
            || count($mediaOptions) > self::MAX_MEDIA_OPTIONS
            || !array_is_list($revisionSummaries)
            || count($revisionSummaries) > self::MAX_REVISION_SUMMARIES
            || !array_is_list($categoryOptions)
            || count($categoryOptions) > self::MAX_CATEGORY_OPTIONS
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

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8'
        );
    }
}
