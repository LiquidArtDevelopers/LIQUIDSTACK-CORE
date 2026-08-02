<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media\Http;

use App\Core\WebAdmin\Http\WebAdminPageDocumentRenderer;
use App\Core\WebAdmin\Media\MediaAssetPage;

final class WebAdminMediaHtmlRenderer
{
    public function __construct(
        private readonly WebAdminPageDocumentRenderer $documents =
            new WebAdminPageDocumentRenderer()
    ) {
    }

    public function index(
        string $basePath,
        string $csrf,
        MediaAssetPage $page,
        bool $canUpload
    ): string {
        $cards = '';
        foreach ($page->items() as $item) {
            $source = $this->path($basePath, '/media/file') . '?'
                . http_build_query([
                    'asset' => $item['public_id'],
                    'width' => (string) $item['thumbnail_width'],
                ], '', '&amp;', PHP_QUERY_RFC3986);
            $cards .= '<li><article><img src="' . $source . '" alt="" '
                . 'loading="lazy" decoding="async"><h3>'
                . $this->escape((string) $item['label']) . '</h3><dl>'
                . '<div><dt>Dimensiones de origen</dt><dd>'
                . (int) $item['source_width'] . ' &times; '
                . (int) $item['source_height'] . ' px</dd></div>'
                . '<div><dt>Creada</dt><dd><time datetime="'
                . $this->escape((string) $item['created_at']) . '">'
                . $this->escape(substr((string) $item['created_at'], 0, 10))
                . '</time></dd></div></dl></article></li>';
        }
        if ($cards === '') {
            $cards = '<li><p>No hay im&aacute;genes en la biblioteca.</p></li>';
        }
        $form = $canUpload
            ? '<section aria-labelledby="media-upload-title"><h2 id="media-upload-title">'
                . 'Subir una imagen</h2><p id="media-upload-help">JPEG, PNG o WebP. '
                . 'M&aacute;ximo 12 MiB, 12.000 px por lado y 40 megap&iacute;xeles. '
                . 'Se convertir&aacute; a AVIF. ALT y title se asignan al utilizarla.</p>'
                . '<form method="post" enctype="multipart/form-data" action="'
                . $this->path($basePath, '/media/upload')
                . '" aria-describedby="media-upload-help">'
                . '<input type="hidden" name="csrf" value="'
                . $this->escape($csrf) . '"><div><label for="media-label">'
                . 'Etiqueta interna</label><input id="media-label" name="label" '
                . 'type="text" maxlength="120" required></div><div><label '
                . 'for="media-image">Imagen</label><input id="media-image" '
                . 'name="image" type="file" accept="image/jpeg,image/png,image/webp" '
                . 'required></div><button type="submit">Procesar y guardar</button>'
                . '</form></section>'
            : '';
        $pagination = '';
        if ($page->page() > 1) {
            $pagination .= '<a rel="prev" href="' . $this->path(
                $basePath,
                '/media?page=' . ($page->page() - 1)
            ) . '">Anterior</a>';
        }
        if ($page->hasNext()) {
            $pagination .= '<a rel="next" href="' . $this->path(
                $basePath,
                '/media?page=' . ($page->page() + 1)
            ) . '">Siguiente</a>';
        }
        if ($pagination !== '') {
            $pagination = '<nav aria-label="Paginaci&oacute;n">' . $pagination . '</nav>';
        }

        return $this->documents->render(
            'Biblioteca de medios',
            '<main><article aria-labelledby="media-title"><h1 id="media-title">'
            . 'Biblioteca de medios</h1><p>Im&aacute;genes privadas reutilizables '
            . 'por los editores de la web.</p>' . $form
            . '<section aria-labelledby="media-list-title"><h2 id="media-list-title">'
            . 'Im&aacute;genes disponibles</h2><ul>' . $cards . '</ul>'
            . $pagination . '</section><p><a href="'
            . $this->path($basePath, '')
            . '">Volver a la gesti&oacute;n web</a></p></article></main>'
        );
    }

    public function updated(string $basePath): string
    {
        return $this->documents->render(
            'Imagen guardada',
            '<main><article aria-labelledby="media-updated-title">'
            . '<h1 id="media-updated-title">Imagen guardada</h1>'
            . '<p role="status" aria-live="polite">La imagen y sus variantes '
            . 'AVIF se han guardado correctamente.</p><p><a href="'
            . $this->path($basePath, '/media')
            . '">Volver a la biblioteca</a></p></article></main>'
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
