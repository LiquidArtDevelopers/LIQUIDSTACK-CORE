<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media\Http;

use App\Core\WebAdmin\Media\MediaException;
use App\Core\WebAdmin\Media\MediaPickerPage;
use App\Core\WebAdmin\Media\MediaPickerReference;

/** Reusable, feature-agnostic WebAdmin media picker presentation. */
final class WebAdminMediaPickerHtmlRenderer
{
    public const SELECTION_EVENT =
        'liquidstack:webadmin-media-picker:selected';
    public const STYLESHEET_PATH =
        '/assets/modules/webadmin/webadmin-media-picker.css';
    public const SCRIPT_PATH =
        '/assets/modules/webadmin/webadmin-media-picker.js';

    /**
     * @return array<string, mixed>
     */
    public function pagePayload(
        string $basePath,
        MediaPickerPage $page,
        bool $canUpload,
        bool $acceptAvifSource
    ): array {
        $cards = array_map(
            static fn ($item): WebAdminMediaPickerCardView =>
                new WebAdminMediaPickerCardView($item, $basePath),
            $page->items()
        );
        $query = $page->query();

        return [
            'ok' => true,
            'query' => [
                'q' => $query->search(),
                'page' => $query->page(),
                'per_page' => $query->pageSize(),
            ],
            'pagination' => [
                'has_previous' => $page->hasPrevious(),
                'has_next' => $page->hasNext(),
            ],
            'permissions' => ['upload' => $canUpload],
            'upload' => [
                'endpoint' => rtrim($basePath, '/') . '/media/upload',
                'accepted_mimes' => $acceptAvifSource
                    ? ['image/jpeg', 'image/png', 'image/webp', 'image/avif']
                    : ['image/jpeg', 'image/png', 'image/webp'],
            ],
            'items' => array_map(
                static fn (WebAdminMediaPickerCardView $card): array =>
                    $card->toSafeArray(),
                $cards
            ),
            'catalog_html' => $this->catalog($cards),
        ];
    }

    /**
     * Progressive SSR fallback plus hooks for the standalone picker asset.
     *
     * @param list<MediaPickerReference> $fallback
     */
    public function dialog(
        string $basePath,
        #[\SensitiveParameter] string $csrf,
        string $ownerFormId,
        array $fallback,
        bool $canUpload,
        bool $acceptAvifSource = true
    ): string {
        $basePath = rtrim($basePath, '/');
        if (
            preg_match('#\A/[a-z0-9][a-z0-9/-]*\z#', $basePath) !== 1
            || str_contains($basePath, '//')
            || preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,127}\z/', $ownerFormId)
                !== 1
            || !array_is_list($fallback)
        ) {
            throw new MediaException(
                'webadmin.media.picker_presentation_invalid'
            );
        }
        $options = '<option value="">Selecciona una imagen</option>';
        foreach ($fallback as $reference) {
            if (!$reference instanceof MediaPickerReference) {
                throw new MediaException(
                    'webadmin.media.picker_presentation_invalid'
                );
            }
            $thumbnail = $reference->thumbnailUrl() === null
                ? '' : ' data-media-thumbnail="'
                    . $this->escape($reference->thumbnailUrl())
                    . '" data-thumbnail-url="'
                    . $this->escape($reference->thumbnailUrl()) . '"';
            $options .= '<option value="'
                . $this->escape($reference->publicId()) . '"'
                . $thumbnail . '>' . $this->escape($reference->label())
                . '</option>';
        }
        $acceptedMimes = $acceptAvifSource
            ? 'image/jpeg,image/png,image/webp,image/avif'
            : 'image/jpeg,image/png,image/webp';
        $upload = $canUpload
            ? '<form method="post" enctype="multipart/form-data" action="'
                . $this->escape($basePath . '/media/upload')
                . '" data-webadmin-media-picker-upload novalidate>'
                . '<input type="hidden" '
                . 'name="csrf" value="' . $this->escape($csrf) . '">'
                . '<input type="hidden" name="idempotency_key" value="">'
                . '<label>Etiqueta interna<input name="label" type="text" '
                . 'maxlength="120" required></label><label>Subir nueva '
                . 'imagen<input name="image" type="file" accept="'
                . $acceptedMimes . '" required></label><button type="submit">'
                . 'Procesar y seleccionar</button><progress '
                . 'data-webadmin-media-picker-progress hidden max="100" '
                . 'value="0"></progress><p '
                . 'data-webadmin-media-picker-upload-status role="status" '
                . 'aria-live="polite"></p></form>'
            : '';

        $searchId = $ownerFormId . '-media-picker-search';
        $titleId = $ownerFormId . '-media-picker-title';
        $fallbackId = $ownerFormId . '-media-picker-fallback-select';

        return '<dialog class="webadminMediaPicker blogEditor__mediaDialog" '
            . 'data-webadmin-media-picker data-webadmin-media-picker-owner="'
            . $this->escape($ownerFormId) . '" aria-labelledby="'
            . $this->escape($titleId) . '" '
            . 'data-webadmin-media-picker-catalog="'
            . $this->escape($basePath . '/media/catalog') . '">'
            . '<div class="webadminMediaPicker__inner '
            . 'blogEditor__mediaDialogInner"><header '
            . 'class="webadminMediaPicker__header '
            . 'blogEditor__mediaDialogHeader"><h2 id="'
            . $this->escape($titleId) . '">Elegir imagen</h2>'
            . '<button type="button" data-webadmin-media-picker-close '
            . 'aria-label="Cerrar">&times;</button>'
            . '</header><form role="search" '
            . 'data-webadmin-media-picker-search><label for="'
            . $this->escape($searchId)
            . '">Buscar en la biblioteca</label><input id="'
            . $this->escape($searchId) . '" name="q" type="search" '
            . 'maxlength="120" autocomplete="off"><button type="submit">'
            . 'Buscar</button></form><p data-webadmin-media-picker-status '
            . 'role="status" aria-live="polite"></p><section '
            . 'aria-label="Imagen seleccionada" '
            . 'data-webadmin-media-picker-active hidden></section><div '
            . 'data-webadmin-media-picker-results></div><nav '
            . 'aria-label="Paginaci&oacute;n de im&aacute;genes"><button '
            . 'type="button" data-webadmin-media-picker-previous disabled>'
            . 'Anterior</button><button type="button" '
            . 'data-webadmin-media-picker-next disabled>Siguiente</button>'
            . '</nav><div data-webadmin-media-picker-fallback><label for="'
            . $this->escape($fallbackId) . '">Biblioteca</label><select id="'
            . $this->escape($fallbackId) . '" '
            . 'data-webadmin-media-picker-select>' . $options . '</select>'
            . '<button type="button" data-webadmin-media-picker-confirm'
            . (count($fallback) === 0 ? ' disabled' : '')
            . '>Usar imagen</button>'
            . '</div>' . $upload . '</div></dialog>';
    }

    /** @param list<WebAdminMediaPickerCardView> $cards */
    private function catalog(array $cards): string
    {
        if ($cards === []) {
            return '<p data-webadmin-media-picker-empty>No se encontraron '
                . 'im&aacute;genes.</p>';
        }
        $html = '<ul class="webadminMediaPicker__grid" '
            . 'data-webadmin-media-picker-grid>';
        foreach ($cards as $card) {
            $variants = implode(', ', array_map(
                static fn (array $variant): string =>
                    (string) $variant['width'],
                $card->variants()
            ));
            $createdAt = $card->createdAt()->format(DATE_ATOM);
            $html .= '<li><button type="button" '
                . 'data-webadmin-media-picker-item data-media-public-id="'
                . $this->escape($card->publicId()) . '" '
                . 'data-media-label="' . $this->escape($card->label())
                . '" data-media-thumbnail="'
                . $this->escape($card->thumbnailUrl())
                . '" aria-pressed="false"><img src="'
                . $this->escape($card->thumbnailUrl())
                . '" alt="" loading="lazy" decoding="async"><strong>'
                . $this->escape($card->label()) . '</strong><span>'
                . $card->sourceWidth() . ' &times; '
                . $card->sourceHeight() . ' px</span><small>Anchos: '
                . $this->escape($variants) . ' px</small><time datetime="'
                . $this->escape($createdAt) . '">'
                . $this->escape($card->createdAt()->format('d/m/Y'))
                . '</time></button></li>';
        }

        return $html . '</ul>';
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
