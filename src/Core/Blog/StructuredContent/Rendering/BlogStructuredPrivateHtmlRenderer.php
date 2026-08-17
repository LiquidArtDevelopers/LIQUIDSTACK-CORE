<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogException;
use App\Core\Blog\StructuredContent\BlogStructuredContentException;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Document\BlogDocumentTemplateRegistry;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredRevisionRecord;
use App\Core\Blog\Preview\BlogPreviewAssetSet;
use App\Core\WebAdmin\Http\WebAdminPageDocumentRenderer;
use App\Core\WebAdmin\Http\WebAdminShellContext;
use App\Core\WebAdmin\Http\WebAdminShellRenderer;
use InvalidArgumentException;

/** Private preview and revision pages; no mutation or inline executable code. */
final class BlogStructuredPrivateHtmlRenderer
{
    private readonly WebAdminShellRenderer $shellRenderer;
    private readonly BlogArticleHeaderResourceRenderer $headerRenderer;

    public function __construct(
        private readonly BlogDocumentHtmlRenderer $documents,
        ?WebAdminShellRenderer $shellRenderer = null,
        ?BlogArticleHeaderResourceRenderer $headerRenderer = null
    ) {
        $this->shellRenderer = $shellRenderer ?? new WebAdminShellRenderer();
        $this->headerRenderer = $headerRenderer
            ?? new BlogArticleHeaderResourceRenderer();
    }

    public function preview(
        string $basePath,
        BlogPostVariant $variant,
        BlogDocument $document,
        ?BlogDraft $presentationDraft,
        BlogPreviewAssetSet $assets,
        #[\SensitiveParameter] ?string $styleNonce = null
    ): string {
        $body = $this->documents->renderMain($document);
        $customCss = $this->documents->renderScopedCss($document);
        $headerMedia = $this->documents->headerMedia($document);
        $draft = $presentationDraft ?? $variant->draft();
        $header = $this->headerRenderer->renderDocument(
            $document,
            $draft->h1(),
            $draft->excerpt(),
            null,
            $headerMedia
        );
        $modifier = BlogDocumentTemplateRegistry::hasCover(
            $document->template()
        ) ? 'cover' : 'basic';

        return $this->shell(
            'Vista previa privada',
            '<div id="smooth-wrapper"><div id="smooth-content">'
                . $header
                . '<main class="blogArticleMain blog-article '
                . 'artBlogArticle01 artBlogArticle01--' . $modifier . '">'
                . $body . '</main></div></div>',
            true,
            $variant->locale(),
            $assets,
            $customCss,
            $styleNonce
        );
    }

    /** @param list<BlogEditorRevisionSummary> $summaries */
    public function revisions(
        string $basePath,
        BlogPostVariant $variant,
        array $summaries,
        ?WebAdminShellContext $adminShell = null
    ): string {
        if (!array_is_list($summaries) || count($summaries) > 100) {
            throw new InvalidArgumentException('Invalid revision list.');
        }
        $items = '';
        foreach ($summaries as $summary) {
            if (!$summary instanceof BlogEditorRevisionSummary) {
                throw new InvalidArgumentException('Invalid revision list.');
            }
            $items .= '<li><a href="' . $this->query(
                $basePath . '/editor/revisions',
                [
                    'post' => $variant->postPublicId(),
                    'locale' => $variant->locale(),
                    'revision' => $summary->revisionPublicId(),
                ]
            ) . '">Revisi&oacute;n ' . $summary->revisionNumber()
                . '</a>, versi&oacute;n editorial '
                . $summary->variantLockVersion() . ', <time datetime="'
                . $this->escape($summary->createdAt()->format(DATE_ATOM))
                . '">' . $this->escape(
                    $summary->createdAt()->format('Y-m-d H:i')
                ) . ' UTC</time></li>';
        }
        if ($items === '') {
            $items = '<li>No hay revisiones guardadas.</li>';
        }

        $fragment = '<article class="blogAdminPage" '
                . 'aria-labelledby="blog-revisions-title">'
                . '<h1 id="blog-revisions-title">Historial de revisiones</h1>'
                . '<p>Art&iacute;culo: ' . $this->escape(
                    $variant->draft()->h1()
                ) . '. Idioma: ' . $this->escape($variant->locale()) . '.</p>'
                . '<section aria-labelledby="blog-revisions-list-title">'
                . '<h2 id="blog-revisions-list-title">Versiones guardadas</h2>'
                . '<ol>' . $items . '</ol></section>'
                . $this->backToEditor($basePath, $variant)
                . '</article>';

        return $adminShell === null
            ? $this->shell(
                'Historial del art&iacute;culo',
                '<main>' . $fragment . '</main>'
            )
            : $this->shellRenderer->render(
                'Historial del art&iacute;culo',
                $fragment,
                $adminShell
            );
    }

    public function revision(
        string $basePath,
        BlogPostVariant $variant,
        BlogStructuredRevisionRecord $revision,
        ?WebAdminShellContext $adminShell = null,
        #[\SensitiveParameter] ?string $restoreCsrf = null,
        bool $canRestore = false
    ): string {
        if (
            $revision->localizationPublicId()
                !== $variant->localizationPublicId()
        ) {
            throw new InvalidArgumentException('Invalid revision state.');
        }
        $snapshot = $revision->snapshot();
        $draft = $snapshot->compatibilityDraft();

        $title = 'Revisi&oacute;n ' . $revision->revisionNumber();
        $fragment = '<article class="blogAdminPage" '
                . 'aria-labelledby="blog-revision-title">'
                . '<h1 id="blog-revision-title">Revisi&oacute;n '
                . $revision->revisionNumber() . '</h1>'
                . '<p>Guardada el <time datetime="'
                . $this->escape($revision->createdAt()->format(DATE_ATOM))
                . '">' . $this->escape(
                    $revision->createdAt()->format('Y-m-d H:i')
                ) . ' UTC</time>. Versi&oacute;n editorial '
                . $revision->variantLockVersion() . '.</p>'
                . ($canRestore
                    ? $this->restoreRevision(
                        $basePath,
                        $variant,
                        $revision,
                        $restoreCsrf
                    )
                    : '')
                . '<section aria-labelledby="blog-revision-content-title">'
                . '<h2 id="blog-revision-content-title">Contenido de la '
                . 'revisi&oacute;n</h2><dl><div><dt>H1</dt><dd>'
                . $this->escape($draft->h1())
                . '</dd></div><div><dt>Slug</dt><dd>'
                . $this->escape($draft->slug() ?? '')
                . '</dd></div><div><dt>Title SEO</dt><dd>'
                . $this->escape($draft->seoTitle() ?? '')
                . '</dd></div><div><dt>Meta description</dt><dd>'
                . $this->escape($draft->metaDescription() ?? '')
                . '</dd></div></dl>'
                . $this->documents->renderHeaderMedia($snapshot->document())
                . $this->documents->renderMain($snapshot->document())
                . '</section>'
                . $this->backToRevisions($basePath, $variant)
                . '</article>';

        return $adminShell === null
            ? $this->shell($title, '<main>' . $fragment . '</main>')
            : $this->shellRenderer->render($title, $fragment, $adminShell);
    }

    public function restoreFailure(
        string $basePath,
        string $postPublicId,
        string $locale,
        string $issueCode,
        WebAdminShellContext $adminShell
    ): string {
        $message = match ($issueCode) {
            BlogException::LOCK_CONFLICT =>
                'El art&iacute;culo cambi&oacute; mientras ten&iacute;as abierta esta '
                    . 'revisi&oacute;n. No se ha restaurado contenido; vuelve al '
                    . 'historial y revisa la versi&oacute;n actual.',
            BlogStructuredContentException::REVISION_NOT_FOUND =>
                'Esta revisi&oacute;n ya no est&aacute; disponible. No se ha cambiado '
                    . 'el borrador actual.',
            BlogStructuredContentException::MEDIA_NOT_FOUND =>
                'La revisi&oacute;n depende de un medio que ya no est&aacute; '
                    . 'disponible. No se ha creado una restauraci&oacute;n parcial.',
            default =>
                'No se ha podido restaurar la revisi&oacute;n. El borrador y el '
                    . 'historial permanecen sin cambios.',
        };
        $revisions = $this->query($basePath . '/editor/revisions', [
            'post' => $postPublicId,
            'locale' => $locale,
        ]);
        $editor = $this->query($basePath . '/editor', [
            'post' => $postPublicId,
            'locale' => $locale,
        ]);
        $fragment = '<article class="blogAdminPage" '
            . 'aria-labelledby="blog-restore-failed-title">'
            . '<h1 id="blog-restore-failed-title">No se pudo restaurar la '
            . 'revisi&oacute;n</h1><p role="alert">' . $message . '</p>'
            . '<div class="webadminActionGroup"><a class="webadminAction '
            . 'webadminAction--primary" href="' . $revisions
            . '">Volver al historial</a><a class="webadminAction '
            . 'webadminAction--secondary" href="' . $editor
            . '">Volver al editor</a></div></article>';

        return $this->shellRenderer->render(
            'No se pudo restaurar la revisi&oacute;n',
            $fragment,
            $adminShell
        );
    }

    private function shell(
        string $title,
        string $body,
        bool $previewDocument = false,
        string $locale = 'es',
        ?BlogPreviewAssetSet $previewAssets = null,
        string $customCss = '',
        #[\SensitiveParameter] ?string $styleNonce = null
    ): string
    {
        if ($previewDocument && $previewAssets === null) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_PREVIEW_ASSETS
            );
        }
        $previewNonceMeta = '';
        if ($previewDocument && $styleNonce !== null) {
            if (!$this->isValidCspNonce($styleNonce)) {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_RENDER_STATE
                );
            }
            $previewNonceMeta = '<meta property="csp-nonce" nonce="'
                . $this->escape($styleNonce) . '">';
        }
        $headAssets = $previewDocument
            ? $this->previewAssetTags($previewAssets)
            : '<link rel="stylesheet" href="'
                . WebAdminPageDocumentRenderer::STYLESHEET_PATH . '">'
                . '<link rel="stylesheet" href="'
                . BlogStructuredEditorHtmlRenderer::STYLESHEET_PATH . '">'
                . '<link rel="stylesheet" href="'
                . '/assets/modules/blog/blog-public.css">';
        if ($customCss !== '') {
            if (
                !$previewDocument
                || !is_string($styleNonce)
                || !$this->isValidCspNonce($styleNonce)
            ) {
                throw new BlogRenderingException(
                    BlogRenderingException::INVALID_RENDER_STATE
                );
            }
            $headAssets .= '<style nonce="' . $this->escape($styleNonce)
                . '">' . $customCss . '</style>';
        }
        $footerScripts = $previewDocument
            ? ''
            : '<script src="' . WebAdminPageDocumentRenderer::SCRIPT_PATH
                . '" defer></script>';

        return '<!doctype html><html lang="' . $this->escape($locale)
            . '"' . ($previewDocument
                ? ' data-blog-preview-ready="true"'
                : '') . '><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow,noarchive">'
            . $previewNonceMeta
            . $headAssets
            . '<title>' . $title . '</title></head>'
            . '<body class="' . ($previewDocument
                ? 'blog-article-page blogPreviewDocument'
                : 'webadmin blogAdmin') . '">' . $body
            . $footerScripts . '</body></html>';
    }

    private function previewAssetTags(?BlogPreviewAssetSet $assets): string
    {
        if ($assets === null) {
            throw new BlogRenderingException(
                BlogRenderingException::INVALID_PREVIEW_ASSETS
            );
        }
        $html = '';
        foreach ($assets->stylesheets() as $url) {
            $html .= '<link rel="stylesheet" href="'
                . $this->escape($url) . '">';
        }
        foreach ($assets->moduleScripts() as $url) {
            $html .= '<script type="module" src="'
                . $this->escape($url) . '"></script>';
        }
        foreach ($assets->deferredScripts() as $url) {
            $html .= '<script src="' . $this->escape($url)
                . '" defer></script>';
        }

        return $html;
    }

    private function isValidCspNonce(string $nonce): bool
    {
        return preg_match(
            '/\A[A-Za-z0-9+\/_-]{16,128}={0,2}\z/D',
            $nonce
        ) === 1;
    }

    private function backToEditor(
        string $basePath,
        BlogPostVariant $variant
    ): string {
        return '<p><a href="' . $this->query($basePath . '/editor', [
            'post' => $variant->postPublicId(),
            'locale' => $variant->locale(),
        ]) . '">Volver al editor</a></p>';
    }

    private function backToRevisions(
        string $basePath,
        BlogPostVariant $variant
    ): string {
        return '<p><a href="' . $this->query(
            $basePath . '/editor/revisions',
            [
                'post' => $variant->postPublicId(),
                'locale' => $variant->locale(),
            ]
        ) . '">Volver al historial</a></p>';
    }

    private function restoreRevision(
        string $basePath,
        BlogPostVariant $variant,
        BlogStructuredRevisionRecord $revision,
        #[\SensitiveParameter] ?string $csrf
    ): string {
        if (!is_string($csrf) || $csrf === '') {
            throw new InvalidArgumentException('Invalid restore context.');
        }

        return '<details class="blogAdminPage__revisionRestore">'
            . '<summary>Restaurar esta revisi&oacute;n</summary>'
            . '<p>Se crear&aacute; una nueva revisi&oacute;n editable con este '
            . 'contenido. El historial existente se conservar&aacute;.</p>'
            . '<form method="post" action="'
            . $this->escape($basePath . '/editor/restore') . '">'
            . $this->hidden('csrf', $csrf)
            . $this->hidden('post', $variant->postPublicId())
            . $this->hidden('locale', $variant->locale())
            . $this->hidden('lock_version', (string) $variant->lockVersion())
            . $this->hidden('revision', $revision->revisionPublicId())
            . '<button class="webadminAction webadminAction--secondary" '
            . 'type="submit">Confirmar restauraci&oacute;n</button></form>'
            . '</details>';
    }

    private function hidden(string $name, string $value): string
    {
        return '<input type="hidden" name="' . $this->escape($name)
            . '" value="' . $this->escape($value) . '">';
    }

    /** @param array<string, string> $parameters */
    private function query(string $path, array $parameters): string
    {
        return $this->escape($path . '?' . http_build_query(
            $parameters,
            '',
            '&',
            PHP_QUERY_RFC3986
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
