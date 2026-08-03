<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Rendering;

use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\StructuredContent\Document\BlogDocument;
use App\Core\Blog\StructuredContent\Persistence\BlogStructuredRevisionRecord;
use App\Core\WebAdmin\Http\WebAdminPageDocumentRenderer;
use App\Core\WebAdmin\Http\WebAdminShellContext;
use App\Core\WebAdmin\Http\WebAdminShellRenderer;
use InvalidArgumentException;

/** Private preview and revision pages; no mutation or inline executable code. */
final class BlogStructuredPrivateHtmlRenderer
{
    private readonly WebAdminShellRenderer $shellRenderer;

    public function __construct(
        private readonly BlogDocumentHtmlRenderer $documents,
        ?WebAdminShellRenderer $shellRenderer = null
    ) {
        $this->shellRenderer = $shellRenderer ?? new WebAdminShellRenderer();
    }

    public function preview(
        string $basePath,
        BlogPostVariant $variant,
        BlogDocument $document
    ): string {
        $body = $this->documents->renderMain($document);
        $headerMedia = $this->documents->renderHeaderMedia($document);
        $draft = $variant->draft();

        return $this->shell(
            'Vista previa privada',
            '<p class="blogPreviewNotice" role="status"><strong>'
                . 'Vista previa privada de la '
                . 'versi&oacute;n guardada.</strong> No publica ni modifica '
                . 'el art&iacute;culo.</p><header class="blogArticleHeader" lang="'
                . $this->escape($variant->locale()) . '" '
                . 'aria-labelledby="blog-structured-preview-title">'
                . '<h1 id="blog-structured-preview-title">'
                . $this->escape($draft->h1()) . '</h1>'
                . ($draft->excerpt() === null ? '' : '<p>'
                    . $this->escape($draft->excerpt()) . '</p>')
                . $headerMedia . '</header><main class="blogArticleMain">'
                . $body . '</main>'
                . $this->backToEditor($basePath, $variant)
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
        ?WebAdminShellContext $adminShell = null
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
                . '</section>' . $this->backToRevisions($basePath, $variant)
                . '</article>';

        return $adminShell === null
            ? $this->shell($title, '<main>' . $fragment . '</main>')
            : $this->shellRenderer->render($title, $fragment, $adminShell);
    }

    private function shell(string $title, string $body): string
    {
        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow,noarchive">'
            . '<link rel="stylesheet" href="'
            . WebAdminPageDocumentRenderer::STYLESHEET_PATH . '">'
            . '<link rel="stylesheet" href="'
            . BlogStructuredEditorHtmlRenderer::STYLESHEET_PATH . '">'
            . '<link rel="stylesheet" href="/assets/modules/blog/blog-public.css">'
            . '<title>' . $title . '</title></head>'
            . '<body class="webadmin blogAdmin">' . $body
            . '<script src="' . WebAdminPageDocumentRenderer::SCRIPT_PATH
            . '" defer></script></body></html>';
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
