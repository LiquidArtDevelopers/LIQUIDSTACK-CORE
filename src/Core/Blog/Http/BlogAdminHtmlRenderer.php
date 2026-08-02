<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\BlogDraft;
use App\Core\Blog\BlogPostSummary;
use App\Core\Blog\BlogPostVariant;
use App\Core\Blog\BlogService;
use InvalidArgumentException;

final class BlogAdminHtmlRenderer
{
    /** @param list<BlogPostSummary> $summaries */
    public function index(
        string $basePath,
        array $summaries,
        bool $canEdit,
        int $offset = 0,
        bool $hasNext = false,
        bool $canPublish = false,
        bool $canViewMedia = false
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
            $action = '<a href="' . $preview
                . '" target="_blank" rel="noopener">Vista previa '
                . 'guardada</a>';
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
                $action .= ' <a href="' . $edit
                    . '">Editar variante</a>';
            } else {
                $action .= ' <span>Solo lectura</span>';
            }
            $rows .= '<tr><th scope="row">'
                . $this->escape($summary->h1()) . '</th><td>'
                . $this->escape($summary->locale()) . '</td><td>'
                . $this->statusLabel($summary->status()) . '</td><td>'
                . $this->escape($summary->updatedAt()->format('Y-m-d H:i'))
                . '</td><td>' . $action . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5">No hay art&iacute;culos.</td></tr>';
        }
        $create = $canEdit && $canViewMedia
            ? '<p><a href="' . $this->path($basePath, '/posts/new')
                . '">Crear art&iacute;culo</a></p>'
            : '';

        return $this->document(
            'Art&iacute;culos del Blog',
            '<main><article aria-labelledby="blog-admin-title">'
            . '<h1 id="blog-admin-title">Art&iacute;culos del Blog</h1>'
            . '<p>Gestiona cada variante de idioma de forma independiente.</p>'
            . $create
            . '<table><caption>Variantes editoriales</caption><thead><tr>'
            . '<th scope="col">H1</th><th scope="col">Idioma</th>'
            . '<th scope="col">Estado</th><th scope="col">Actualizado</th>'
            . '<th scope="col">Acciones</th></tr></thead><tbody>'
            . $rows . '</tbody></table>'
            . $this->pagination($basePath, $offset, $hasNext)
            . $this->backToDashboard($basePath)
            . '</article></main>'
        );
    }

    /**
     * @param list<string> $languages
     */
    public function createForm(
        string $basePath,
        string $csrf,
        array $languages,
        ?string $postPublicId = null,
        bool $failed = false
    ): string {
        $localeOptions = '';
        foreach ($languages as $language) {
            if (
                !is_string($language)
                || preg_match('/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})*\z/', $language)
                    !== 1
            ) {
                throw new InvalidArgumentException(
                    'Invalid Blog language presentation.'
                );
            }
            $localeOptions .= '<option value="' . $this->escape($language)
                . '">' . $this->escape($language) . '</option>';
        }
        if ($localeOptions === '') {
            throw new InvalidArgumentException(
                'Blog needs at least one presentation language.'
            );
        }

        return $this->document(
            $postPublicId === null
                ? 'Crear art&iacute;culo'
                : 'A&ntilde;adir idioma',
            '<main><article aria-labelledby="blog-create-title">'
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
            . '<div><label for="blog-locale">Idioma</label><select '
            . 'id="blog-locale" name="locale" required>'
            . $localeOptions . '</select></div>'
            . $this->editorialFields(null)
            . '<button type="submit">Guardar borrador</button></form>'
            . $this->backToBlog($basePath)
            . '</article></main>'
        );
    }

    public function editForm(
        string $basePath,
        string $csrf,
        BlogPostVariant $variant,
        bool $canPublish,
        bool $failed = false
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

        return $this->document(
            'Editar art&iacute;culo',
            '<main><article aria-labelledby="blog-edit-title">'
            . '<h1 id="blog-edit-title">Editar art&iacute;culo</h1>'
            . '<p>Idioma: <strong>' . $this->escape($variant->locale())
            . '</strong>. Estado: ' . $this->statusLabel($variant->status())
            . '.</p>' . $this->formError($failed)
            . $preview
            . $editor
            . $publish
            . '<p><a href="' . $this->pathWithQuery(
                $basePath,
                '/posts/new',
                ['post' => $variant->postPublicId()]
            ) . '">A&ntilde;adir otro idioma</a></p>'
            . $this->backToBlog($basePath)
            . '</article></main>'
        );
    }

    public function preview(
        string $basePath,
        BlogPostVariant $variant,
        bool $canEdit
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

        return $this->document(
            'Vista previa privada',
            '<main><p role="status"><strong>Vista previa privada de la '
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
            . '">Volver al Blog</a></li></ul></nav></main>'
        );
    }

    public function operationCompleted(string $basePath): string
    {
        return $this->document(
            'Operaci&oacute;n completada',
            '<main><article aria-labelledby="blog-updated-title">'
            . '<h1 id="blog-updated-title">Operaci&oacute;n completada</h1>'
            . '<p role="status" aria-live="polite">Los cambios se han '
            . 'guardado correctamente.</p>'
            . $this->backToBlog($basePath)
            . '</article></main>'
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

    private function document(string $title, string $body): string
    {
        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow,noarchive">'
            . '<title>' . $title . '</title></head><body>'
            . $body . '</body></html>';
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
        bool $hasNext
    ): string {
        $items = '';
        if ($offset > 0) {
            $previous = max(
                0,
                $offset - BlogService::DEFAULT_LIST_LIMIT
            );
            $href = $previous === 0
                ? $this->path($basePath, '')
                : $this->pathWithQuery(
                    $basePath,
                    '',
                    ['offset' => (string) $previous]
                );
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
                    $basePath,
                    '',
                    [
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
