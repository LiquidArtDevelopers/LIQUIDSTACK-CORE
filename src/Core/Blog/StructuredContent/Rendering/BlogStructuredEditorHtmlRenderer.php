<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentCodec;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTextProjector;
use App\Core\WebAdmin\Http\WebAdminPageDocumentRenderer;
use InvalidArgumentException;

/** Private, dependency-free presentation for the structured Blog editor. */
final class BlogStructuredEditorHtmlRenderer
{
    public const STYLESHEET_PATH = '/assets/modules/blog/blog-admin.css';
    public const SCRIPT_PATH = '/assets/modules/blog/blog-editor.js';
    public const MAX_MEDIA_OPTIONS = 100;
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

    public function __construct(
        private readonly BlogDocumentCodec $codec = new BlogDocumentCodec(),
        private readonly BlogDocumentTextProjector $projector =
            new BlogDocumentTextProjector()
    ) {
    }

    /**
     * @param list<BlogEditorMediaOption> $mediaOptions
     * @param list<BlogEditorRevisionSummary> $revisionSummaries
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
        bool $canAssignCategories = false
    ): string {
        $basePath = $this->basePath($basePath);
        $this->assertCsrfPresentation($csrf);
        $this->assertDocumentPresentation(
            $variant,
            $document,
            $canonicalJson
        );
        $this->assertOptions($mediaOptions, $revisionSummaries);

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

        $body = '<main class="blogEditor"><article '
            . 'aria-labelledby="blog-editor-title">'
            . '<h1 id="blog-editor-title">Editor estructurado del Blog</h1>'
            . '<p>Idioma: <strong>' . $this->escape($variant->locale())
            . '</strong>. Estado: <strong>'
            . ($readOnly ? 'publicado' : 'borrador') . '</strong>.</p>'
            . ($readOnly
                ? '<p role="status">Retira la variante antes de modificarla '
                    . 'o restaurar una revisi&oacute;n.</p>'
                : '')
            . ($failed
                ? '<p role="alert" aria-live="assertive">No se pudieron '
                    . 'guardar los cambios. Revisa los campos y vuelve a '
                    . 'intentarlo.</p>'
                : '')
            . '<nav class="blogEditor__navigation" '
            . 'aria-label="Navegaci&oacute;n del editor"><ul><li><a href="'
            . $previewUrl . '" target="_blank" '
            . 'rel="noopener noreferrer">Vista previa guardada</a></li>'
            . '<li><a href="' . $revisionsUrl
            . '">Historial de revisiones</a></li>'
            . '<li><a href="' . $this->query(
                $basePath . '/posts/new',
                ['post' => $variant->postPublicId()]
            ) . '">A&ntilde;adir otro idioma</a></li>'
            . ($canAssignCategories ? '<li><a href="' . $this->query(
                $basePath . '/categories/assign',
                [
                    'post' => $variant->postPublicId(),
                    'locale' => $variant->locale(),
                ]
            ) . '">Asignar categor&iacute;as</a></li>' : '')
            . '</ul></nav>'
            . $this->publicationControl(
                $basePath,
                $csrf,
                $variant,
                $canPublish
            )
            . '<form class="blogEditor__form" method="post" action="'
            . $this->path($basePath . '/editor/save')
            . '" data-blog-editor data-blog-editor-readonly="'
            . ($readOnly ? 'true' : 'false') . '">'
            . $this->hidden('csrf', $csrf)
            . $identity
            . $this->hidden('document_json', $canonicalJson)
            . $this->metadata($variant, $readOnly)
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
            . '">Volver al Blog</a></p></article></main>';

        return $this->document($body);
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
        bool $readOnly
    ): string {
        $draft = $variant->draft();
        $readonly = $readOnly ? ' readonly' : '';

        return '<section class="blogEditor__metadata" '
            . 'aria-labelledby="blog-editor-metadata-title">'
            . '<h2 id="blog-editor-metadata-title">Metadatos del art&iacute;culo</h2>'
            . '<p id="blog-editor-h1-help">El H1 pertenece al art&iacute;culo '
            . 'y nunca forma parte de sus bloques.</p><div '
            . 'class="blogEditor__metadataGrid">'
            . '<div><label for="blog-editor-h1">H1</label><input '
            . 'id="blog-editor-h1" name="h1" type="text" maxlength="'
            . BlogDraft::MAX_H1_BYTES . '" value="'
            . $this->escape($draft->h1())
            . '" aria-describedby="blog-editor-h1-help" required'
            . $readonly . '></div>'
            . '<div><label for="blog-editor-slug">Slug</label><input '
            . 'id="blog-editor-slug" name="slug" type="text" maxlength="'
            . BlogDraft::MAX_SLUG_BYTES
            . '" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" value="'
            . $this->escape($draft->slug() ?? '')
            . '" autocapitalize="none" spellcheck="false"'
            . $readonly . '></div>'
            . '<div><label for="blog-editor-seo-title">Title SEO</label>'
            . '<input id="blog-editor-seo-title" name="seo_title" '
            . 'type="text" maxlength="' . BlogDraft::MAX_SEO_TITLE_BYTES
            . '" value="' . $this->escape($draft->seoTitle() ?? '') . '"'
            . $readonly . '></div>'
            . '<div><label for="blog-editor-description">Meta description</label>'
            . '<textarea id="blog-editor-description" '
            . 'name="meta_description" maxlength="'
            . BlogDraft::MAX_META_DESCRIPTION_BYTES . '"'
            . $readonly . '>'
            . $this->escape($draft->metaDescription() ?? '')
            . '</textarea></div>'
            . '<div class="blogEditor__metadataWide"><label '
            . 'for="blog-editor-excerpt">Extracto</label><textarea '
            . 'id="blog-editor-excerpt" name="excerpt" maxlength="'
            . BlogDraft::MAX_EXCERPT_BYTES . '"' . $readonly . '>'
            . $this->escape($draft->excerpt() ?? '')
            . '</textarea></div></div></section>';
    }

    /** @param list<BlogEditorMediaOption> $mediaOptions */
    private function documentEditor(
        BlogDocument $document,
        array $mediaOptions,
        bool $readOnly
    ): string {
        $templateOptions = '';
        foreach ([
            BlogDocumentTemplateRegistry::ARTICLE_BASIC =>
                'Art&iacute;culo b&aacute;sico',
            BlogDocumentTemplateRegistry::ARTICLE_COVER =>
                'Art&iacute;culo con portada',
        ] as $key => $label) {
            $templateOptions .= '<option value="' . $key . '"'
                . ($document->template() === $key ? ' selected' : '')
                . '>' . $label . '</option>';
        }

        $mediaCatalog = '';
        foreach ($mediaOptions as $option) {
            $mediaCatalog .= '<option value="'
                . $this->escape($option->publicId()) . '">'
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
            $summary .= '<li data-blog-static-block data-block-id="'
                . $this->escape($block['id']) . '">Bloque '
                . ($position + 1) . ': ' . $label . '</li>';
        }
        if ($summary === '') {
            $summary = '<li data-blog-empty-state>El documento todav&iacute;a no '
                . 'contiene bloques.</li>';
        }

        $buttons = '';
        foreach (self::BLOCK_LABELS as $type => $label) {
            $disabled = $readOnly
                || ($type === 'image' && $mediaOptions === []);
            $buttons .= '<button type="button" data-blog-add-block="'
                . $type . '"' . ($disabled ? ' disabled' : '')
                . '>A&ntilde;adir ' . lcfirst($label) . '</button>';
        }

        return '<section class="blogEditor__document" '
            . 'aria-labelledby="blog-editor-document-title">'
            . '<h2 id="blog-editor-document-title">Cuerpo por bloques</h2>'
            . '<p>El cuerpo admite H2 y H3. El editor mantiene la jerarqu&iacute;a '
            . 'y el servidor vuelve a validar el documento completo.</p>'
            . '<div class="blogEditor__template"><label '
            . 'for="blog-editor-template">Plantilla</label><select '
            . 'id="blog-editor-template" data-blog-template-select'
            . ($readOnly ? ' disabled' : '') . '>'
            . $templateOptions . '</select></div>'
            . '<select data-blog-media-catalog hidden aria-hidden="true" '
            . 'tabindex="-1">' . $mediaCatalog . '</select>'
            . '<div class="blogEditor__blockToolbar" role="group" '
            . 'aria-label="A&ntilde;adir un bloque">' . $buttons . '</div>'
            . '<noscript><p role="status">Sin JavaScript se conservan los '
            . 'bloques actuales sin cambios; los metadatos siguen siendo '
            . 'editables.</p></noscript>'
            . '<ol class="blogEditor__blockList" data-blog-block-list '
            . 'data-max-blocks="' . BlogDocument::MAX_BLOCKS . '">'
            . $summary . '</ol></section>';
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

    private function document(string $body): string
    {
        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow,noarchive">'
            . '<link rel="stylesheet" href="'
            . WebAdminPageDocumentRenderer::STYLESHEET_PATH . '">'
            . '<link rel="stylesheet" href="' . self::STYLESHEET_PATH . '">'
            . '<title>Editor estructurado del Blog</title></head>'
            . '<body class="webadmin blogAdmin">' . $body
            . '<script src="' . WebAdminPageDocumentRenderer::SCRIPT_PATH
            . '" defer></script><script src="' . self::SCRIPT_PATH
            . '" defer></script></body></html>';
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
     */
    private function assertOptions(
        array $mediaOptions,
        array $revisionSummaries
    ): void {
        if (
            !array_is_list($mediaOptions)
            || count($mediaOptions) > self::MAX_MEDIA_OPTIONS
            || !array_is_list($revisionSummaries)
            || count($revisionSummaries) > self::MAX_REVISION_SUMMARIES
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
