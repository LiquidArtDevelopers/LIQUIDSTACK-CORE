<?php

declare(strict_types=1);

namespace App\Core\Blog\Http;

use App\Core\Blog\Categories\BlogCategoryDraft;
use App\Core\Blog\Categories\BlogCategoryLocalization;
use InvalidArgumentException;

final class BlogCategoryAdminHtmlRenderer
{
    /** @param list<BlogCategoryLocalization> $categories */
    public function index(
        string $basePath,
        array $categories,
        bool $canEdit
    ): string {
        $rows = '';
        foreach ($categories as $category) {
            if (!$category instanceof BlogCategoryLocalization) {
                throw new InvalidArgumentException('Invalid category list.');
            }
            $action = $canEdit
                ? '<a href="' . $this->query($basePath . '/edit', [
                    'category' => $category->categoryPublicId(),
                    'locale' => $category->locale(),
                ]) . '">Editar</a>'
                : '<span>Solo lectura</span>';
            $rows .= '<tr><th scope="row">'
                . $this->escape($category->draft()->name()) . '</th><td>'
                . $this->escape($category->locale()) . '</td><td>'
                . $this->escape($category->draft()->slug()) . '</td><td>'
                . $action . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">No hay categor&iacute;as.</td></tr>';
        }
        $tools = $canEdit
            ? '<p><a href="' . $this->path($basePath . '/new')
                . '">Crear categor&iacute;a</a></p>'
                . '<form method="get" action="'
                . $this->path($basePath . '/assign') . '"><fieldset>'
                . '<legend>Asignar categor&iacute;as a un art&iacute;culo</legend>'
                . '<label for="category-post">ID p&uacute;blico del art&iacute;culo</label>'
                . '<input id="category-post" name="post" type="text" required>'
                . '<label for="category-locale">Idioma</label>'
                . '<input id="category-locale" name="locale" value="es" '
                . 'pattern="[a-z]{2,3}(?:-[a-z0-9]{2,8})*" required>'
                . '<button type="submit">Gestionar asignaci&oacute;n</button>'
                . '</fieldset></form>'
            : '';

        return $this->document(
            'Categor&iacute;as del Blog',
            '<main><article aria-labelledby="category-title">'
            . '<h1 id="category-title">Categor&iacute;as del Blog</h1>'
            . $tools
            . '<table><caption>Traducciones de categor&iacute;as</caption>'
            . '<thead><tr><th scope="col">Nombre</th><th scope="col">Idioma</th>'
            . '<th scope="col">Slug</th><th scope="col">Acciones</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . $this->back($basePath) . '</article></main>'
        );
    }

    /** @param list<string> $languages */
    public function createForm(
        string $basePath,
        string $csrf,
        array $languages,
        ?string $categoryPublicId = null
    ): string {
        $options = '';
        foreach ($languages as $index => $language) {
            $options .= '<option value="' . $this->escape($language) . '"'
                . ($index === 0 ? ' selected' : '') . '>'
                . $this->escape($language) . '</option>';
        }
        if ($options === '') {
            throw new InvalidArgumentException('Missing category languages.');
        }

        return $this->document(
            'Crear categor&iacute;a',
            '<main><article aria-labelledby="category-create-title">'
            . '<h1 id="category-create-title">'
            . ($categoryPublicId === null
                ? 'Crear categor&iacute;a'
                : 'A&ntilde;adir idioma a la categor&iacute;a')
            . '</h1><form method="post" action="'
            . $this->path($basePath . '/create') . '">'
            . $this->csrf($csrf)
            . '<input type="hidden" name="category" value="'
            . $this->escape($categoryPublicId ?? '') . '">'
            . '<label for="category-create-locale">Idioma</label><select '
            . 'id="category-create-locale" name="locale" required>'
            . $options . '</select>' . $this->draftFields(null)
            . '<button type="submit">Guardar categor&iacute;a</button></form>'
            . $this->back($basePath) . '</article></main>'
        );
    }

    public function editForm(
        string $basePath,
        string $csrf,
        BlogCategoryLocalization $category,
        bool $canAddLocalization = true
    ): string {
        $localizationAction = $canAddLocalization
            ? '<p><a href="' . $this->query($basePath . '/new', [
                'category' => $category->categoryPublicId(),
            ]) . '">A&ntilde;adir otro idioma</a></p>'
            : '<p role="status">Esta categor&iacute;a ya est&aacute; traducida a '
                . 'todos los idiomas activos.</p>';

        return $this->document(
            'Editar categor&iacute;a',
            '<main><article aria-labelledby="category-edit-title">'
            . '<h1 id="category-edit-title">Editar categor&iacute;a</h1>'
            . '<p>Idioma: <strong>' . $this->escape($category->locale())
            . '</strong>.</p><form method="post" action="'
            . $this->path($basePath . '/save') . '">'
            . $this->csrf($csrf)
            . '<input type="hidden" name="category" value="'
            . $this->escape($category->categoryPublicId()) . '">'
            . '<input type="hidden" name="locale" value="'
            . $this->escape($category->locale()) . '">'
            . '<input type="hidden" name="lock_version" value="'
            . $category->lockVersion() . '">'
            . $this->draftFields($category)
            . '<button type="submit">Guardar cambios</button></form>'
            . $localizationAction
            . $this->back($basePath) . '</article></main>'
        );
    }

    public function localizationsComplete(string $basePath): string
    {
        return $this->document(
            'Idiomas de la categor&iacute;a',
            '<main><article aria-labelledby="category-locales-title">'
            . '<h1 id="category-locales-title">Idiomas de la categor&iacute;a</h1>'
            . '<p role="status">Esta categor&iacute;a ya est&aacute; traducida a '
            . 'todos los idiomas activos.</p>'
            . $this->back($basePath) . '</article></main>'
        );
    }

    /**
     * @param list<BlogCategoryLocalization> $categories
     * @param list<string> $assignedPublicIds
     */
    public function assignmentForm(
        string $basePath,
        string $csrf,
        string $postPublicId,
        string $locale,
        array $categories,
        array $assignedPublicIds
    ): string {
        $items = '';
        foreach ($categories as $category) {
            if (!$category instanceof BlogCategoryLocalization) {
                throw new InvalidArgumentException('Invalid assignment list.');
            }
            $checked = in_array(
                $category->categoryPublicId(),
                $assignedPublicIds,
                true
            ) ? ' checked' : '';
            $items .= '<li><label><input type="checkbox" name="categories[]" '
                . 'value="' . $this->escape($category->categoryPublicId())
                . '"' . $checked . '> '
                . $this->escape($category->draft()->name()) . '</label></li>';
        }
        if ($items === '') {
            $items = '<li>No hay categor&iacute;as para este idioma.</li>';
        }

        return $this->document(
            'Asignar categor&iacute;as',
            '<main><article aria-labelledby="category-assign-title">'
            . '<h1 id="category-assign-title">Asignar categor&iacute;as</h1>'
            . '<p>Idioma de presentaci&oacute;n: '
            . $this->escape($locale) . '.</p><form method="post" action="'
            . $this->path($basePath . '/assign') . '">'
            . $this->csrf($csrf)
            . '<input type="hidden" name="post" value="'
            . $this->escape($postPublicId) . '"><fieldset>'
            . '<legend>Categor&iacute;as del art&iacute;culo</legend><ul>' . $items
            . '</ul></fieldset><button type="submit">Guardar asignaci&oacute;n</button>'
            . '</form>' . $this->back($basePath) . '</article></main>'
        );
    }

    public function completed(string $basePath): string
    {
        return $this->document(
            'Operaci&oacute;n completada',
            '<main><article aria-labelledby="category-updated-title">'
            . '<h1 id="category-updated-title">Operaci&oacute;n completada</h1>'
            . '<p role="status">Los cambios se han guardado correctamente.</p>'
            . $this->back($basePath) . '</article></main>'
        );
    }

    private function draftFields(
        ?BlogCategoryLocalization $category
    ): string {
        return '<label for="category-name">Nombre</label><input '
            . 'id="category-name" name="name" type="text" maxlength="'
            . BlogCategoryDraft::MAX_NAME_BYTES . '" value="'
            . $this->escape($category?->draft()->name() ?? '') . '" required>'
            . '<label for="category-slug">Slug</label><input '
            . 'id="category-slug" name="slug" type="text" maxlength="'
            . BlogCategoryDraft::MAX_SLUG_BYTES . '" pattern="[a-z0-9]+'
            . '(?:-[a-z0-9]+)*" value="'
            . $this->escape($category?->draft()->slug() ?? '') . '" required>';
    }

    private function csrf(string $csrf): string
    {
        return '<input type="hidden" name="csrf" value="'
            . $this->escape($csrf) . '">';
    }

    private function back(string $basePath): string
    {
        return '<p><a href="' . $this->path($basePath)
            . '">Volver a categor&iacute;as</a> &middot; <a href="'
            . $this->path(substr($basePath, 0, -strlen('/categories')))
            . '">Volver al Blog</a></p>';
    }

    private function document(string $title, string $body): string
    {
        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow,noarchive">'
            . '<title>' . $title . '</title></head><body>' . $body
            . '</body></html>';
    }

    /** @param array<string, string> $values */
    private function query(string $path, array $values): string
    {
        return $this->escape(
            rtrim($path, '/') . '?'
            . http_build_query($values, '', '&', PHP_QUERY_RFC3986)
        );
    }

    private function path(string $path): string
    {
        return $this->escape(rtrim($path, '/'));
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
