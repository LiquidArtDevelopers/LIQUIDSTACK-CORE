<?php

declare(strict_types=1);

namespace App\Core\Commerce\Http;

use App\Core\Commerce\LocalizedProduct;
use App\Core\WebAdmin\Http\WebAdminShellContext;
use App\Core\WebAdmin\Http\WebAdminShellRenderer;

final class CommerceAdminHtmlRenderer
{
    public function __construct(
        private readonly WebAdminShellRenderer $shell =
            new WebAdminShellRenderer()
    ) {
    }

    /** @param list<array<string, mixed>> $products */
    public function products(
        string $basePath,
        array $products,
        bool $canEdit,
        WebAdminShellContext $context,
        bool $canSettings = false
    ): string {
        $rows = '';
        foreach ($products as $product) {
            $id = $this->scalar($product['public_id'] ?? null);
            $title = $this->scalar($product['title'] ?? null, 'Sin t&iacute;tulo');
            $locale = $this->scalar($product['locale'] ?? null, '');
            $status = $this->scalar($product['editorial_status'] ?? null);
            $availability = $this->scalar($product['availability_status'] ?? null);
            $price = $product['price_minor'] === null
                ? '&mdash;'
                : $this->money((int) $product['price_minor'], $this->scalar($product['currency'] ?? null, 'EUR'));
            $action = $canEdit
                ? '<a href="' . $this->path($basePath, '/products/edit')
                    . '?product=' . rawurlencode($id) . '&amp;locale=' . rawurlencode($locale)
                    . '">Editar</a>'
                : '<span>Solo lectura</span>';
            $rows .= '<tr><th scope="row">' . $this->escape($title, false)
                . '</th><td>' . $this->escape($this->scalar($product['sku'] ?? null, '&mdash;'), false)
                . '</td><td>' . $this->escape($locale)
                . '</td><td>' . $this->escape($status)
                . '</td><td>' . $this->escape($availability)
                . '</td><td>' . $price . '</td><td>' . $action . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7">A&uacute;n no hay productos.</td></tr>';
        }
        $actions = $canEdit
            ? '<p><a href="' . $this->path($basePath, '/products/new')
                . '">A&ntilde;adir producto</a></p>'
            : '';
        if ($canSettings) {
            $actions .= '<p><a href="' . $this->path($basePath, '/settings')
                . '">Ver ajustes del comercio</a></p>';
        }
        $main = '<header><h1>Productos</h1><p>Cat&aacute;logo multidioma de Commerce.</p>'
            . $actions . '</header><div class="webadminTableWrap"><table>'
            . '<thead><tr><th>Producto</th><th>SKU</th><th>Idioma</th>'
            . '<th>Estado</th><th>Disponibilidad</th><th>Precio</th>'
            . '<th>Acciones</th></tr></thead><tbody>' . $rows
            . '</tbody></table></div>';

        return $this->shell->render('Productos', $main, $context);
    }

    /** @param list<string> $languages @param array<string, mixed> $editor */
    public function productForm(
        string $basePath,
        array $languages,
        string $primaryLocale,
        string $csrf,
        WebAdminShellContext $context,
        ?LocalizedProduct $product = null,
        bool $failed = false,
        array $editor = [],
        bool $canUseTaxonomies = false,
        bool $canUseMedia = false
    ): string {
        $editing = $product instanceof LocalizedProduct;
        $action = $this->path(
            $basePath,
            $editing ? '/products/save' : '/products/create'
        );
        $locale = $editing ? $product->requestedLocale() : $primaryLocale;
        $localeControl = '<input type="hidden" name="locale" value="'
            . $this->escape($locale) . '">';
        if ($editing) {
            $localeLinks = '';
            foreach ($languages as $language) {
                $href = $this->path($basePath, '/products/edit') . '?product='
                    . rawurlencode($product->publicId()) . '&amp;locale='
                    . rawurlencode($language);
                $localeLinks .= '<li>' . ($language === $locale
                    ? '<strong aria-current="page">' . $this->escape(strtoupper($language)) . '</strong>'
                    : '<a href="' . $href . '">' . $this->escape(strtoupper($language)) . '</a>')
                    . '</li>';
            }
            $localeControl .= '<nav aria-label="Variante de idioma"><p>Idioma editado: <strong>'
                . $this->escape(strtoupper($locale))
                . '</strong>. Cambiar de idioma carga su variante guardada.</p><ul>'
                . $localeLinks . '</ul></nav>';
        } else {
            $localeControl .= '<p id="commerce-primary-locale">El alta inicial usa '
                . $this->escape(strtoupper($primaryLocale))
                . ' y reserva los dem&aacute;s idiomas como fallback.</p>';
        }
        $price = $product?->price();
        $priceValue = $price === null
            ? ''
            : number_format($price->minorUnits() / 100, 2, '.', '');
        $hidden = '<input type="hidden" name="csrf" value="' . $this->escape($csrf) . '">';
        if ($editing) {
            $hidden .= '<input type="hidden" name="product" value="'
                . $this->escape($product->publicId()) . '"><input type="hidden" '
                . 'name="lock_version" value="' . $product->lockVersion() . '">';
        }
        $status = $editing ? $product->editorialStatus()->value : 'draft';
        $availability = $editing ? $product->availabilityStatus()->value : 'available';
        $stateFields = $editing
            ? $this->select('status', 'Estado editorial', [
                'draft' => 'Borrador', 'active' => 'Activo',
                'inactive' => 'Inactivo',
                'archived' => 'Archivado (retirada segura, sin borrado)',
            ], $status) . $this->select('availability', 'Disponibilidad', [
                'available' => 'Disponible', 'reserved' => 'Reservado',
                'sold' => 'Vendido', 'unavailable' => 'No disponible',
            ], $availability)
            : '';
        $error = $failed
            ? '<p role="alert">No se pudo guardar. Revisa los datos, la versi&oacute;n y las categor&iacute;as requeridas para activar.</p>'
            : '';
        $main = '<header><h1>' . ($editing ? 'Editar producto' : 'Nuevo producto')
            . '</h1><p>La localizaci&oacute;n principal crea fallbacks para los dem&aacute;s idiomas activos.</p></header>'
            . $error . '<form method="post" action="' . $action . '">' . $hidden
            . $localeControl
            . $this->input('title', 'T&iacute;tulo', $product?->title() ?? '', true)
            . $this->input('slug', 'Slug', $product?->slug() ?? '', true, '[a-z0-9]+(?:-[a-z0-9]+)*')
            . ($editing
                ? '<p><label>SKU <input name="sku" value="'
                    . $this->escape($product?->sku() ?? '')
                    . '" readonly></label></p>'
                : $this->input('sku', 'SKU', ''))
            . $this->input('price', 'Precio', $priceValue, false, '(?:0|[1-9][0-9]*)(?:\\.[0-9]{1,2})?')
            . $this->input('currency', 'Moneda ISO', $price?->currency() ?? 'EUR', true, '[A-Z]{3}')
            . '<p><label>Resumen <textarea name="summary" maxlength="2000">'
            . $this->escape($product?->summary() ?? '') . '</textarea></label></p>'
            . '<p><label>Descripci&oacute;n <textarea name="description" maxlength="200000">'
            . $this->escape($product?->description() ?? '') . '</textarea></label></p>'
            . $stateFields . '<p><button type="submit">Guardar</button> '
            . '<a href="' . $this->path($basePath, '') . '">Cancelar</a></p></form>';
        if ($editing && $canUseTaxonomies) {
            $main .= $this->productTaxonomyEditor(
                $basePath,
                $csrf,
                $product,
                $editor
            );
            $main .= $this->productAttributeEditors(
                $basePath,
                $csrf,
                $product,
                $editor
            );
        }
        if ($editing && $canUseMedia) {
            $main .= $this->productMediaEditor(
                $basePath,
                $csrf,
                $product,
                $editor
            );
        }

        return $this->shell->render(
            $editing ? 'Editar producto' : 'Nuevo producto',
            $main,
            $context
        );
    }

    /**
     * @param list<array<string, mixed>> $categories
     * @param list<array<string, mixed>> $tags
     * @param list<string> $languages
     * @param list<array<string, mixed>> $attributes
     */
    public function taxonomies(
        string $basePath,
        array $categories,
        array $tags,
        string $csrf,
        bool $canEdit,
        WebAdminShellContext $context,
        bool $failed = false,
        array $languages = [],
        string $primaryLocale = 'es',
        array $attributes = []
    ): string {
        $categoryRows = '';
        $parentOptions = '<option value="">Sin categor&iacute;a superior</option>';
        $parentNames = [];
        foreach ($categories as $category) {
            if (($category['locale'] ?? null) !== $primaryLocale) {
                continue;
            }
            $name = $this->scalar($category['name'] ?? null, 'Sin nombre');
            $id = $this->scalar($category['public_id'] ?? null);
            $parentNames[$id] = $name;
            $parentOptions .= '<option value="' . $this->escape($id) . '">'
                . $this->escape($name) . '</option>';
        }
        foreach ($categories as $category) {
            $name = $this->scalar($category['name'] ?? null, 'Sin nombre');
            $id = $this->scalar($category['public_id'] ?? null);
            $locale = $this->scalar($category['locale'] ?? null);
            $parentId = $this->scalar($category['parent_public_id'] ?? null);
            $edit = '';
            if ($canEdit) {
                $edit = '<form method="post" action="'
                    . $this->path($basePath, '/taxonomies/categories/localization/save')
                    . '">' . $this->token($csrf)
                    . '<input type="hidden" name="category" value="' . $this->escape($id) . '">'
                    . '<input type="hidden" name="locale" value="' . $this->escape($locale) . '">'
                    . '<input type="hidden" name="lock_version" value="'
                    . (int) ($category['lock_version'] ?? 0) . '">'
                    . $this->input('name', 'Nombre', $name, true)
                    . $this->input('slug', 'Slug', $this->scalar($category['slug'] ?? null), true, '[a-z0-9]+(?:-[a-z0-9]+)*')
                    . '<button type="submit">Guardar traducci&oacute;n</button></form>';
                if ($locale === $primaryLocale) {
                    $options = str_replace(
                        'value="' . $this->escape($parentId) . '"',
                        'value="' . $this->escape($parentId) . '" selected',
                        $parentOptions
                    );
                    $edit .= '<form method="post" action="'
                        . $this->path($basePath, '/taxonomies/categories/parent/save')
                        . '">' . $this->token($csrf)
                        . '<input type="hidden" name="category" value="' . $this->escape($id) . '">'
                        . '<input type="hidden" name="lock_version" value="'
                        . (int) ($category['lock_version'] ?? 0) . '">'
                        . '<p><label>Superior <select name="parent">' . $options
                        . '</select></label></p><button type="submit">Guardar jerarqu&iacute;a</button></form>';
                    $edit .= '<form method="post" action="'
                        . $this->path($basePath, '/taxonomies/categories/order/save')
                        . '">' . $this->token($csrf)
                        . '<input type="hidden" name="category" value="' . $this->escape($id) . '">'
                        . '<input type="hidden" name="lock_version" value="'
                        . (int) ($category['lock_version'] ?? 0) . '">'
                        . '<p><label>Orden <input type="number" min="0" max="10000" '
                        . 'name="sort_order" value="' . (int) ($category['sort_order'] ?? 0)
                        . '" required></label></p><button type="submit">Guardar orden</button></form>';
                }
            }
            $categoryRows .= '<tr><th scope="row">' . $this->escape($name)
                . '</th><td>' . $this->escape(strtoupper($locale))
                . '</td><td>' . $this->escape($this->scalar($category['slug'] ?? null))
                . '</td><td>' . ($parentId === '' ? '&mdash;' : $this->escape($parentNames[$parentId] ?? 'Anidada'))
                . '</td><td>' . $edit . '</td></tr>';
        }
        $tagRows = '';
        foreach ($tags as $tag) {
            $id = $this->scalar($tag['public_id'] ?? null);
            $locale = $this->scalar($tag['locale'] ?? null);
            $name = $this->scalar($tag['name'] ?? null, 'Sin nombre');
            $edit = $canEdit
                ? '<form method="post" action="'
                    . $this->path($basePath, '/taxonomies/tags/localization/save')
                    . '">' . $this->token($csrf)
                    . '<input type="hidden" name="tag" value="' . $this->escape($id) . '">'
                    . '<input type="hidden" name="locale" value="' . $this->escape($locale) . '">'
                    . $this->input('name', 'Nombre', $name, true)
                    . $this->input('slug', 'Slug', $this->scalar($tag['slug'] ?? null), true, '[a-z0-9]+(?:-[a-z0-9]+)*')
                    . '<button type="submit">Guardar traducci&oacute;n</button></form>'
                : '';
            $tagRows .= '<tr><th scope="row">'
                . $this->escape($name)
                . '</th><td>' . $this->escape(strtoupper($locale))
                . '</td><td>' . $this->escape($this->scalar($tag['slug'] ?? null))
                . '</td><td>' . $edit . '</td></tr>';
        }
        $categoryRows = $categoryRows ?: '<tr><td colspan="5">Sin categor&iacute;as.</td></tr>';
        $tagRows = $tagRows ?: '<tr><td colspan="4">Sin etiquetas.</td></tr>';
        $forms = '';
        if ($canEdit) {
            $token = '<input type="hidden" name="csrf" value="' . $this->escape($csrf) . '">';
            $forms = '<section><h2>Nueva categor&iacute;a</h2><form method="post" action="'
                . $this->path($basePath, '/taxonomies/categories/create') . '">' . $token
                . $this->input('name', 'Nombre', '', true)
                . $this->input('slug', 'Slug', '', true, '[a-z0-9]+(?:-[a-z0-9]+)*')
                . '<p><label>Superior <select name="parent">' . $parentOptions
                . '</select></label></p><button type="submit">Crear categor&iacute;a</button></form></section>'
                . '<section><h2>Nueva etiqueta</h2><form method="post" action="'
                . $this->path($basePath, '/taxonomies/tags/create') . '">' . $token
                . $this->input('name', 'Nombre', '', true)
                . $this->input('slug', 'Slug', '', true, '[a-z0-9]+(?:-[a-z0-9]+)*')
                . '<button type="submit">Crear etiqueta</button></form></section>'
                . $this->attributeCreateForm(
                    $basePath,
                    $csrf,
                    $parentOptions,
                    $languages,
                    $attributes
                );
        }
        $error = $failed ? '<p role="alert">No se pudo completar la operaci&oacute;n.</p>' : '';
        $main = '<header><h1>Taxonom&iacute;as</h1></header>' . $error . $forms
            . '<section><h2>Categor&iacute;as</h2><table><thead><tr><th>Nombre</th><th>Idioma</th><th>Slug</th><th>Superior</th><th>Edici&oacute;n</th></tr></thead><tbody>'
            . $categoryRows . '</tbody></table></section><section><h2>Etiquetas</h2><table><thead><tr><th>Nombre</th><th>Idioma</th><th>Slug</th><th>Edici&oacute;n</th></tr></thead><tbody>'
            . $tagRows . '</tbody></table></section>'
            . $this->attributeTable($attributes);

        return $this->shell->render('Taxonom&iacute;as', $main, $context);
    }

    /** @param list<array<string, mixed>> $inquiries */
    public function inquiries(
        array $inquiries,
        WebAdminShellContext $context,
        string $basePath = '/admin/commerce'
    ): string
    {
        $rows = '';
        foreach ($inquiries as $inquiry) {
            $id = $this->scalar($inquiry['public_id'] ?? null);
            $rows .= '<tr><th scope="row"><a href="'
                . $this->path($basePath, '/inquiries/detail') . '?inquiry='
                . rawurlencode($id) . '">'
                . $this->escape($this->scalar($inquiry['contact_name'] ?? null))
                . '</a>'
                . '</th><td><a href="mailto:'
                . $this->escape($this->scalar($inquiry['email'] ?? null)) . '">'
                . $this->escape($this->scalar($inquiry['email'] ?? null))
                . '</a></td><td>' . $this->escape($this->scalar($inquiry['locale'] ?? null))
                . '</td><td>' . (int) ($inquiry['line_count'] ?? 0)
                . '</td><td>' . $this->escape($this->scalar($inquiry['created_at'] ?? null))
                . '</td></tr>';
        }
        $rows = $rows ?: '<tr><td colspan="5">A&uacute;n no hay solicitudes.</td></tr>';
        $main = '<header><h1>Solicitudes</h1><p>Consulta de solo lectura.</p></header>'
            . '<table><thead><tr><th>Contacto</th><th>Correo</th><th>Idioma</th>'
            . '<th>Items</th><th>Fecha UTC</th></tr></thead><tbody>' . $rows
            . '</tbody></table>';

        return $this->shell->render('Solicitudes', $main, $context);
    }

    /** @param array<string, mixed> $inquiry */
    public function inquiryDetail(
        string $basePath,
        array $inquiry,
        bool $canViewMedia,
        WebAdminShellContext $context
    ): string {
        $lines = '';
        $adminBase = (string) preg_replace('#/commerce\z#', '', rtrim($basePath, '/'));
        foreach ((array) ($inquiry['lines'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $media = '';
            $mediaId = $line['cover_media_public_id'] ?? null;
            $width = $line['cover_thumbnail_width'] ?? null;
            if ($canViewMedia
                && is_string($mediaId)
                && $mediaId !== ''
                && (is_int($width) || is_string($width))
                && (int) $width > 0
            ) {
                $src = $adminBase . '/media/file?' . http_build_query([
                    'asset' => $mediaId,
                    'width' => (string) (int) $width,
                ], '', '&', PHP_QUERY_RFC3986);
                $media = '<img src="' . $this->escape($src)
                    . '" alt="" width="160" loading="lazy">';
            }
            $price = ($line['unit_price_minor'] ?? null) === null
                ? '&mdash;'
                : $this->money(
                    (int) $line['unit_price_minor'],
                    $this->scalar($line['currency'] ?? null, 'EUR')
                );
            $path = $this->scalar($line['public_path'] ?? null);
            $lines .= '<tr><td>' . $media . '</td><th scope="row">'
                . $this->escape($this->scalar($line['title'] ?? null))
                . '</th><td>' . $this->escape($this->scalar($line['sku'] ?? null, '&mdash;'), false)
                . '</td><td><a href="' . $this->escape($path) . '">'
                . $this->escape($path) . '</a></td><td>' . $price
                . '</td><td>' . $this->escape($this->scalar($line['availability_status'] ?? null))
                . '</td><td>' . (int) ($line['quantity'] ?? 0) . '</td></tr>';
        }
        $lines = $lines === ''
            ? '<tr><td colspan="7">La solicitud no contiene items.</td></tr>'
            : $lines;
        $phone = $this->scalar($inquiry['phone'] ?? null, '&mdash;');
        $message = $this->scalar($inquiry['message'] ?? null, 'Sin mensaje.');
        $main = '<header><h1>Detalle de solicitud</h1><p>Snapshot inmutable recibido; consulta de solo lectura.</p></header>'
            . '<dl><dt>Contacto</dt><dd>'
            . $this->escape($this->scalar($inquiry['contact_name'] ?? null))
            . '</dd><dt>Correo</dt><dd><a href="mailto:'
            . $this->escape($this->scalar($inquiry['email'] ?? null)) . '">'
            . $this->escape($this->scalar($inquiry['email'] ?? null))
            . '</a></dd><dt>Tel&eacute;fono</dt><dd>' . $this->escape($phone, false)
            . '</dd><dt>Idioma</dt><dd>' . $this->escape($this->scalar($inquiry['locale'] ?? null))
            . '</dd><dt>Fecha UTC</dt><dd>' . $this->escape($this->scalar($inquiry['created_at'] ?? null))
            . '</dd><dt>Consentimiento</dt><dd>Versi&oacute;n de privacidad '
            . $this->escape($this->scalar($inquiry['privacy_version'] ?? null))
            . '</dd><dt>Mensaje</dt><dd>' . nl2br($this->escape($message))
            . '</dd></dl><section><h2>Items solicitados</h2><table><thead><tr>'
            . '<th>Imagen</th><th>Item</th><th>Referencia</th><th>URL guardada</th>'
            . '<th>Precio</th><th>Estado</th><th>Cantidad</th></tr></thead><tbody>'
            . $lines . '</tbody></table></section><p><a href="'
            . $this->path($basePath, '/inquiries') . '">Volver a solicitudes</a></p>';

        return $this->shell->render('Detalle de solicitud', $main, $context);
    }

    /** @param array<string, mixed> $config */
    public function settings(array $config, WebAdminShellContext $context): string
    {
        $public = is_array($config['public'] ?? null)
            && ($config['public']['enabled'] ?? false) === true;
        $main = '<header><h1>Ajustes de Commerce</h1><p>Resumen de solo lectura de la configuraci&oacute;n project-owned.</p></header>'
            . '<section><h2>Modo de transacci&oacute;n</h2>'
            . '<p><label><input type="radio" checked disabled> <strong>Solicitar informaci&oacute;n:</strong> activo</label>. La lista de inter&eacute;s finaliza enviando una consulta.</p>'
            . '<p><label><input type="radio" disabled> <strong>Venta online:</strong> no disponible</label> en esta versi&oacute;n. No puede seleccionarse ni se persiste una simulaci&oacute;n de pago.</p>'
            . '</section><section><h2>Publicaci&oacute;n</h2><p>Cat&aacute;logo p&uacute;blico: '
            . ($public ? 'activado' : 'desactivado') . '.</p></section>';

        return $this->shell->render('Ajustes de Commerce', $main, $context);
    }

    /** @param array<string, mixed> $editor */
    private function productTaxonomyEditor(
        string $basePath,
        string $csrf,
        LocalizedProduct $product,
        array $editor
    ): string {
        $categories = is_array($editor['categories'] ?? null)
            ? $editor['categories'] : [];
        $selectedCategories = is_array($editor['selected_categories'] ?? null)
            ? $editor['selected_categories'] : [];
        $canonical = is_string($editor['canonical_category'] ?? null)
            ? $editor['canonical_category'] : '';
        $categoryOptions = '';
        $canonicalOptions = '<option value="">Selecciona una categor&iacute;a</option>';
        foreach ($categories as $category) {
            if (!is_array($category)) {
                continue;
            }
            $id = $this->scalar($category['public_id'] ?? null);
            $name = $this->scalar($category['name'] ?? null, 'Sin nombre');
            $categoryOptions .= '<option value="' . $this->escape($id) . '"'
                . (in_array($id, $selectedCategories, true) ? ' selected' : '')
                . '>' . $this->escape($name) . '</option>';
            $canonicalOptions .= '<option value="' . $this->escape($id) . '"'
                . ($id === $canonical ? ' selected' : '') . '>'
                . $this->escape($name) . '</option>';
        }
        $tags = is_array($editor['tags'] ?? null) ? $editor['tags'] : [];
        $selectedTags = is_array($editor['selected_tags'] ?? null)
            ? $editor['selected_tags'] : [];
        $tagOptions = '';
        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $id = $this->scalar($tag['public_id'] ?? null);
            $tagOptions .= '<option value="' . $this->escape($id) . '"'
                . (in_array($id, $selectedTags, true) ? ' selected' : '')
                . '>' . $this->escape($this->scalar($tag['name'] ?? null, 'Sin nombre'))
                . '</option>';
        }
        if ($categoryOptions === '') {
            return '<section><h2>Familias y etiquetas</h2><p>Crea al menos una categor&iacute;a antes de activar el producto.</p></section>';
        }

        return '<section><h2>Familias y etiquetas</h2><form method="post" action="'
            . $this->path($basePath, '/products/taxonomies/save') . '">'
            . $this->token($csrf)
            . '<input type="hidden" name="product" value="'
            . $this->escape($product->publicId()) . '">'
            . '<p><label>Categor&iacute;as <select name="categories[]" multiple required>'
            . $categoryOptions . '</select></label></p>'
            . '<p><label>Categor&iacute;a can&oacute;nica <select name="canonical" required>'
            . $canonicalOptions . '</select></label></p>'
            . '<p>La categor&iacute;a can&oacute;nica construye la URL p&uacute;blica anidada.</p>'
            . '<p><label>Etiquetas <select name="tags[]" multiple>'
            . $tagOptions . '</select></label></p>'
            . '<button type="submit">Guardar familias y etiquetas</button></form></section>';
    }

    /** @param array<string, mixed> $editor */
    private function productAttributeEditors(
        string $basePath,
        string $csrf,
        LocalizedProduct $product,
        array $editor
    ): string {
        $attributes = is_array($editor['attributes'] ?? null)
            ? $editor['attributes'] : [];
        $values = is_array($editor['attribute_values'] ?? null)
            ? $editor['attribute_values'] : [];
        $selectedCategories = is_array($editor['selected_categories'] ?? null)
            ? $editor['selected_categories'] : [];
        $forms = '';
        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $category = $attribute['category_public_id'] ?? null;
            if (is_string($category)
                && $category !== ''
                && !in_array($category, $selectedCategories, true)
            ) {
                continue;
            }
            $id = $this->scalar($attribute['public_id'] ?? null);
            $type = $this->scalar($attribute['type'] ?? null);
            $storageLocale = $type === 'text' ? $product->requestedLocale() : '';
            $stored = $values[$id . ':' . $storageLocale] ?? [];
            $stored = is_array($stored) ? $stored : [];
            $field = $this->attributeValueField($attribute, $stored);
            if ($field === '') {
                continue;
            }
            $forms .= '<form method="post" action="'
                . $this->path($basePath, '/products/attributes/save') . '">'
                . $this->token($csrf)
                . '<input type="hidden" name="product" value="'
                . $this->escape($product->publicId()) . '">'
                . '<input type="hidden" name="attribute" value="'
                . $this->escape($id) . '">'
                . '<input type="hidden" name="locale" value="'
                . $this->escape($product->requestedLocale()) . '">'
                . '<h3>' . $this->escape($this->scalar($attribute['name'] ?? null, $id))
                . '</h3>' . $field
                . '<button type="submit">Guardar caracter&iacute;stica</button></form>';
        }

        return '<section><h2>Caracter&iacute;sticas</h2>'
            . ($forms === ''
                ? '<p>No hay definiciones aplicables a las familias asignadas.</p>'
                : $forms)
            . '</section>';
    }

    /** @param array<string, mixed> $attribute @param array<string, mixed> $stored */
    private function attributeValueField(array $attribute, array $stored): string
    {
        $type = $this->scalar($attribute['type'] ?? null);
        $unit = $this->scalar($attribute['unit'] ?? null);
        if ($type === 'text') {
            return $this->input('value', 'Valor', $this->scalar($stored['text_value'] ?? null), true);
        }
        if ($type === 'number') {
            return '<p><label>Valor' . ($unit === '' ? '' : ' (' . $this->escape($unit) . ')')
                . ' <input type="number" step="0.000001" name="value" required value="'
                . $this->escape($this->scalar($stored['number_value'] ?? null)) . '"></label></p>';
        }
        if ($type === 'boolean') {
            $selected = in_array($stored['boolean_value'] ?? null, [1, '1'], true) ? '1' : '0';
            return $this->select('value', 'Valor', ['1' => 'S&iacute;', '0' => 'No'], $selected);
        }
        if ($type === 'date') {
            return '<p><label>Valor <input type="date" name="value" required value="'
                . $this->escape($this->scalar($stored['date_value'] ?? null)) . '"></label></p>';
        }
        if (!in_array($type, ['select', 'multiselect'], true)) {
            return '';
        }
        $selected = is_array($stored['option_public_ids'] ?? null)
            ? $stored['option_public_ids'] : [];
        $options = '';
        foreach ((array) ($attribute['options'] ?? []) as $option) {
            if (!is_array($option)) {
                continue;
            }
            $id = $this->scalar($option['public_id'] ?? null);
            $options .= '<option value="' . $this->escape($id) . '"'
                . (in_array($id, $selected, true) ? ' selected' : '') . '>'
                . $this->escape($this->scalar($option['label'] ?? null, $id))
                . '</option>';
        }
        if ($options === '') {
            return '<p>A&ntilde;ade opciones a esta definici&oacute;n antes de asignarla.</p>';
        }

        return '<p><label>Valor <select name="value' . ($type === 'multiselect' ? '[]' : '')
            . '"' . ($type === 'multiselect' ? ' multiple' : '')
            . ' required>' . $options . '</select></label></p>';
    }

    /** @param array<string, mixed> $editor */
    private function productMediaEditor(
        string $basePath,
        string $csrf,
        LocalizedProduct $product,
        array $editor
    ): string {
        $assets = is_array($editor['media_assets'] ?? null)
            ? $editor['media_assets'] : [];
        $assigned = is_array($editor['product_media'] ?? null)
            ? $editor['product_media'] : [];
        $primaryLocale = $this->scalar($editor['primary_locale'] ?? null);
        $byId = [];
        foreach ($assigned as $media) {
            if (!is_array($media) || !is_string($media['public_id'] ?? null)) {
                continue;
            }
            $byId[$media['public_id']] = $media;
        }
        $adminBase = preg_replace('#/commerce\z#', '', rtrim($basePath, '/'));
        if ($product->requestedLocale() !== $primaryLocale) {
            $forms = '';
            foreach ($assigned as $media) {
                if (!is_array($media) || !is_string($media['public_id'] ?? null)) {
                    continue;
                }
                $id = $media['public_id'];
                $forms .= '<form method="post" action="'
                    . $this->path($basePath, '/products/media/localization/save')
                    . '">' . $this->token($csrf)
                    . '<input type="hidden" name="product" value="'
                    . $this->escape($product->publicId()) . '">'
                    . '<input type="hidden" name="media" value="'
                    . $this->escape($id) . '">'
                    . '<input type="hidden" name="locale" value="'
                    . $this->escape($product->requestedLocale()) . '">'
                    . '<h3>' . $this->escape($this->scalar($media['role'] ?? null))
                    . ' &middot; ' . $this->escape($id) . '</h3>'
                    . $this->input(
                        'alt_text',
                        'Texto alternativo',
                        $this->scalar($media['alt_text'] ?? null),
                        true
                    )
                    . '<p><label>Pie de imagen <textarea name="caption" maxlength="2000">'
                    . $this->escape($this->scalar($media['caption'] ?? null))
                    . '</textarea></label></p><p>Estado: '
                    . $this->escape($this->scalar($media['translation_status'] ?? null, 'fallback'))
                    . '</p><button type="submit">Guardar texto localizado</button></form>';
            }

            return '<section><h2>Textos de portada y galer&iacute;a</h2><p>La selecci&oacute;n de im&aacute;genes se gestiona en el idioma principal. Aqu&iacute; solo editas ALT y pie de la variante '
                . $this->escape(strtoupper($product->requestedLocale())) . '.</p>'
                . ($forms === '' ? '<p>Este producto no tiene medios asignados.</p>' : $forms)
                . '</section>';
        }
        $assetRows = '';
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $id = $this->scalar($asset['public_id'] ?? null);
            $label = $this->scalar($asset['label'] ?? null, $id);
            $stored = is_array($byId[$id] ?? null) ? $byId[$id] : [];
            $role = $this->scalar($stored['role'] ?? null, 'none');
            $assetRows .= '<fieldset><legend>' . $this->escape($label) . '</legend>'
                . $this->select('roles[' . $id . ']', 'Uso', [
                    'none' => 'No usar',
                    'cover' => 'Portada',
                    'gallery' => 'Galer&iacute;a',
                ], $role)
                . $this->input(
                    'alts[' . $id . ']',
                    'Texto alternativo',
                    $this->scalar($stored['alt_text'] ?? null)
                )
                . '<p><label>Pie de imagen <textarea name="captions['
                . $this->escape($id) . ']" maxlength="2000">'
                . $this->escape($this->scalar($stored['caption'] ?? null))
                . '</textarea></label></p></fieldset>';
        }
        if ($assets === []) {
            return '<section><h2>Medios</h2><p>No hay im&aacute;genes disponibles. '
                . '<a href="' . $this->escape((string) $adminBase . '/media')
                . '">Abrir la biblioteca de medios</a>.</p></section>';
        }

        return '<section><h2>Portada y galer&iacute;a</h2><p>Las im&aacute;genes se reutilizan desde WebAdmin Media; Commerce no crea otro almacenamiento.</p>'
            . '<form method="post" action="'
            . $this->path($basePath, '/products/media/save') . '">'
            . $this->token($csrf)
            . '<input type="hidden" name="product" value="'
            . $this->escape($product->publicId()) . '">'
            . '<input type="hidden" name="lock_version" value="'
            . $product->lockVersion() . '">'
            . '<input type="hidden" name="locale" value="'
            . $this->escape($product->requestedLocale()) . '">'
            . $assetRows
            . '<button type="submit">Guardar medios</button></form></section>';
    }

    /**
     * @param list<string> $languages
     * @param list<array<string, mixed>> $attributes
     */
    private function attributeCreateForm(
        string $basePath,
        string $csrf,
        string $categoryOptions,
        array $languages,
        array $attributes
    ): string {
        $types = [
            'text' => 'Texto localizado',
            'number' => 'N&uacute;mero',
            'boolean' => 'S&iacute;/No',
            'select' => 'Selecci&oacute;n &uacute;nica',
            'multiselect' => 'Selecci&oacute;n m&uacute;ltiple',
            'date' => 'Fecha',
        ];
        $form = '<section><h2>Nueva definici&oacute;n de caracter&iacute;stica</h2>'
            . '<form method="post" action="'
            . $this->path($basePath, '/taxonomies/attributes/create') . '">'
            . $this->token($csrf)
            . $this->input('code', 'C&oacute;digo', '', true, '[a-z][a-z0-9_]{0,63}')
            . $this->input('name', 'Nombre', '', true)
            . $this->select('type', 'Tipo', $types, 'text')
            . '<p><label>Familia opcional <select name="category">'
            . $categoryOptions . '</select></label></p>'
            . $this->input('unit', 'Unidad', '')
            . $this->select('filterable', 'Usable como filtro', ['0' => 'No', '1' => 'S&iacute;'], '0')
            . '<p><label>Orden <input type="number" min="0" max="10000" name="sort_order" value="0" required></label></p>'
            . '<button type="submit">Crear definici&oacute;n</button></form></section>';
        foreach ($attributes as $attribute) {
            if (!is_array($attribute)
                || !in_array($attribute['type'] ?? null, ['select', 'multiselect'], true)
            ) {
                continue;
            }
            $labels = '';
            foreach ($languages as $index => $locale) {
                $labels .= '<p><label>Etiqueta ' . $this->escape(strtoupper($locale))
                    . ' <input name="labels[' . $this->escape($locale) . ']" maxlength="180"'
                    . ($index === 0 ? ' required' : '') . '></label></p>';
            }
            $form .= '<section><h2>Nueva opci&oacute;n para '
                . $this->escape($this->scalar($attribute['name'] ?? null, 'caracter&iacute;stica'))
                . '</h2><form method="post" action="'
                . $this->path($basePath, '/taxonomies/attributes/options/create') . '">'
                . $this->token($csrf)
                . '<input type="hidden" name="attribute" value="'
                . $this->escape($this->scalar($attribute['public_id'] ?? null)) . '">'
                . $this->input('code', 'C&oacute;digo', '', true, '[a-z][a-z0-9_]{0,63}')
                . '<p><label>Orden <input type="number" min="0" max="10000" name="sort_order" value="0" required></label></p>'
                . $labels . '<button type="submit">A&ntilde;adir opci&oacute;n</button></form></section>';
        }

        return $form;
    }

    /** @param list<array<string, mixed>> $attributes */
    private function attributeTable(array $attributes): string
    {
        $rows = '';
        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $options = [];
            foreach ((array) ($attribute['options'] ?? []) as $option) {
                if (is_array($option)) {
                    $options[] = $this->scalar($option['label'] ?? null, $this->scalar($option['code'] ?? null));
                }
            }
            $rows .= '<tr><th scope="row">'
                . $this->escape($this->scalar($attribute['name'] ?? null))
                . '</th><td>' . $this->escape($this->scalar($attribute['code'] ?? null))
                . '</td><td>' . $this->escape($this->scalar($attribute['type'] ?? null))
                . '</td><td>' . $this->escape(implode(', ', $options)) . '</td></tr>';
        }
        $rows = $rows === ''
            ? '<tr><td colspan="4">Sin caracter&iacute;sticas.</td></tr>' : $rows;

        return '<section><h2>Definiciones de caracter&iacute;sticas</h2><table><thead><tr><th>Nombre</th><th>C&oacute;digo</th><th>Tipo</th><th>Opciones</th></tr></thead><tbody>'
            . $rows . '</tbody></table></section>';
    }

    private function token(string $csrf): string
    {
        return '<input type="hidden" name="csrf" value="'
            . $this->escape($csrf) . '">';
    }

    /** @param array<string, string> $options */
    private function select(string $name, string $label, array $options, string $selected): string
    {
        $html = '<p><label>' . $label . ' <select name="' . $name . '">';
        foreach ($options as $value => $copy) {
            $value = (string) $value;
            $html .= '<option value="' . $this->escape($value) . '"'
                . ($value === $selected ? ' selected' : '') . '>' . $copy . '</option>';
        }
        return $html . '</select></label></p>';
    }

    private function input(string $name, string $label, string $value, bool $required = false, ?string $pattern = null): string
    {
        return '<p><label>' . $label . ' <input name="' . $name . '" value="'
            . $this->escape($value) . '"' . ($required ? ' required' : '')
            . ($pattern === null ? '' : ' pattern="' . $this->escape($pattern) . '"')
            . '></label></p>';
    }

    private function money(int $minor, string $currency): string
    {
        return $this->escape(number_format($minor / 100, 2, ',', '.') . ' ' . $currency);
    }

    private function scalar(mixed $value, string $fallback = ''): string
    {
        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    private function path(string $basePath, string $suffix): string
    {
        return $this->escape(rtrim($basePath, '/') . $suffix);
    }

    private function escape(string $value, bool $doubleEncode = true): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', $doubleEncode);
    }
}
