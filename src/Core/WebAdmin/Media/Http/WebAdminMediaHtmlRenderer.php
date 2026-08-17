<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media\Http;

use App\Core\WebAdmin\Http\WebAdminPageDocumentRenderer;
use App\Core\WebAdmin\Http\WebAdminShellContext;
use App\Core\WebAdmin\Http\WebAdminShellRenderer;
use App\Core\WebAdmin\Media\MediaAssetPage;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;

final class WebAdminMediaHtmlRenderer
{
    private readonly WebAdminShellRenderer $shellRenderer;
    private readonly UuidGeneratorInterface $requestIds;

    public function __construct(
        ?WebAdminPageDocumentRenderer $documents = null,
        ?WebAdminShellRenderer $shellRenderer = null,
        ?UuidGeneratorInterface $requestIds = null
    ) {
        $this->shellRenderer = $shellRenderer
            ?? new WebAdminShellRenderer(
                $documents ?? new WebAdminPageDocumentRenderer()
            );
        $this->requestIds = $requestIds ?? new RandomUuidV4Generator();
    }

    public function index(
        string $basePath,
        string $csrf,
        MediaAssetPage $page,
        bool $canUpload,
        ?WebAdminShellContext $shell = null,
        bool $acceptAvifSource = true,
        bool $canDelete = false
    ): string {
        $catalogRegion = $this->catalogRegionFragment(
            $basePath,
            $csrf,
            $page,
            $canDelete
        );
        $sourceFormats = $acceptAvifSource
            ? 'JPEG, PNG, WebP o AVIF.'
            : 'JPEG, PNG o WebP.';
        $acceptedMimes = $acceptAvifSource
            ? 'image/jpeg,image/png,image/webp,image/avif'
            : 'image/jpeg,image/png,image/webp';
        $avifAvailability = $acceptAvifSource
            ? ''
            : ' La entrada AVIF se habilitar&aacute; al completar la actualizaci&oacute;n '
                . 'del esquema de medios.';
        $form = $canUpload
            ? '<section aria-labelledby="media-upload-title"><h2 id="media-upload-title">'
                . 'Subir una imagen</h2><p id="media-upload-help">'
                . $sourceFormats . $avifAvailability . ' '
                . 'M&aacute;ximo 12 MiB, 12.000 px por lado y 40 megap&iacute;xeles. '
                . 'Se convertir&aacute; a AVIF y el original temporal no se conservar&aacute;. '
                . 'ALT y title se asignan al utilizarla.</p>'
                . '<form method="post" enctype="multipart/form-data" action="'
                . $this->path($basePath, '/media/upload')
                . '" aria-describedby="media-upload-help" '
                . 'data-webadmin-media-upload>'
                . '<input type="hidden" name="csrf" value="'
                . $this->escape($csrf) . '"><input type="hidden" '
                . 'name="idempotency_key" value="'
                . $this->escape($this->requestIds->generateV4())
                . '"><div><label for="media-label">'
                . 'Etiqueta interna</label><input id="media-label" name="label" '
                . 'type="text" maxlength="120" required></div>'
                . '<div class="webadminMedia__fileField"><label '
                . 'for="media-image">Imagen</label><input id="media-image" '
                . 'name="image" type="file" accept="'
                . $acceptedMimes . '" required data-webadmin-media-file>'
                . '<output class="webadminMedia__fileName" '
                . 'data-webadmin-media-file-name aria-live="polite">'
                . 'Ning&uacute;n archivo seleccionado</output></div>'
                . '<button class="webadminAction webadminAction--primary" '
                . 'type="submit" data-webadmin-media-submit>'
                . '<span data-webadmin-media-submit-label>Procesar y guardar</span>'
                . '<span class="webadminLoader webadminLoader--cube" '
                . 'data-webadmin-loader hidden aria-hidden="true">'
                . '<span class="webadminLoader__cube">'
                . '<span></span><span></span><span></span></span>'
                . '<span class="webadminLoader__text">Procesando</span></span>'
                . '</button>'
                . '</form></section>'
            : '';
        $deleteDialog = $canDelete
            ? '<p class="webadminMedia__deleteFeedback" role="status" '
                . 'aria-live="polite" tabindex="-1" '
                . 'data-webadmin-media-delete-status></p>'
                . '<dialog class="webadminMedia__deleteDialog" '
                . 'data-webadmin-media-delete-dialog '
                . 'aria-labelledby="media-delete-title" '
                . 'aria-describedby="media-delete-description">'
                . '<h2 id="media-delete-title">Enviar imagen a cuarentena</h2>'
                . '<p id="media-delete-description">La imagen dejar&aacute; de '
                . 'aparecer en la biblioteca, pero se conservar&aacute; de forma '
                . 'recuperable. Confirma que quieres continuar con '
                . '<strong data-webadmin-media-delete-label>esta imagen</strong>.</p>'
                . '<p class="webadminMedia__deleteFeedback" role="alert" '
                . 'aria-live="assertive" '
                . 'data-webadmin-media-delete-dialog-status></p>'
                . '<div class="webadminMedia__deleteDialogActions '
                . 'webadminActionGroup">'
                . '<button class="webadminAction webadminAction--secondary" '
                . 'type="button" data-webadmin-media-delete-cancel>'
                . 'Cancelar</button><button class="webadminAction '
                . 'webadminAction--danger" type="button" '
                . 'data-webadmin-media-delete-confirm>Confirmar</button>'
                . '</div></dialog>'
            : '';

        $shell ??= new WebAdminShellContext(
            basePath: $basePath,
            logoutCsrf: null,
            activePath: '/media'
        );

        return $this->shellRenderer->render(
            'Biblioteca de medios',
            '<article class="webadminMedia" aria-labelledby="media-title"><h1 id="media-title">'
            . 'Biblioteca de medios</h1><p>Im&aacute;genes privadas reutilizables '
            . 'por los editores de la web.</p>' . $form
            . '<section aria-labelledby="media-list-title"><h2 id="media-list-title">'
            . 'Im&aacute;genes disponibles</h2>' . $catalogRegion
            . '</section>' . $deleteDialog . '</article>',
            $shell
        );
    }

    public function catalogRegionFragment(
        string $basePath,
        string $csrf,
        MediaAssetPage $page,
        bool $canDelete
    ): string {
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
            $pagination = '<nav aria-label="Paginaci&oacute;n">'
                . $pagination . '</nav>';
        }

        return '<div data-webadmin-media-catalog-region>'
            . $this->catalogFragment($basePath, $csrf, $page, $canDelete)
            . $pagination . '</div>';
    }

    public function catalogFragment(
        string $basePath,
        string $csrf,
        MediaAssetPage $page,
        bool $canDelete
    ): string {
        $cards = '';
        foreach ($page->items() as $item) {
            $source = $this->path($basePath, '/media/file') . '?'
                . http_build_query([
                    'asset' => $item['public_id'],
                    'width' => (string) $item['thumbnail_width'],
                ], '', '&amp;', PHP_QUERY_RFC3986);
            $variantItems = '';
            foreach (($item['variants'] ?? []) as $variant) {
                $variantItems .= '<li>' . (int) $variant['width']
                    . ' &times; ' . (int) $variant['height'] . ' px '
                    . '<span>(' . $this->kilobytes((int) $variant['bytes'])
                    . ')</span></li>';
            }
            if ($variantItems === '') {
                $variantItems = '<li>Informaci&oacute;n no disponible</li>';
            }
            $usageStatus = (string) ($item['usage_status'] ?? 'unknown');
            [$usageLabel, $usageModifier] = $this->usagePresentation(
                $usageStatus
            );
            $deleteAction = '';
            $assetVersion = $item['delete_version'] ?? null;
            if (
                $canDelete
                && $usageStatus === 'unused'
                && is_string($assetVersion)
                && preg_match('/\A[0-9a-f]{64}\z/', $assetVersion) === 1
            ) {
                $deleteAction = '<form method="post" action="'
                    . $this->path($basePath, '/media/delete')
                    . '" data-webadmin-media-delete-form><input type="hidden" '
                    . 'name="csrf" value="' . $this->escape($csrf) . '">'
                    . '<input type="hidden" name="asset" value="'
                    . $this->escape((string) $item['public_id']) . '">'
                    . '<input type="hidden" name="asset_version" value="'
                    . $this->escape($assetVersion) . '"><input type="hidden" '
                    . 'name="idempotency_key" value="'
                    . $this->escape($this->requestIds->generateV4()) . '">'
                    . '<input type="hidden" name="page" value="'
                    . $page->page() . '"><button class="webadminAction '
                    . 'webadminAction--danger webadminAction--compact" '
                    . 'type="submit" '
                    . 'data-webadmin-media-delete-open>Enviar a '
                    . 'cuarentena</button></form>';
            }
            $cards .= '<li data-webadmin-media-card data-media-public-id="'
                . $this->escape((string) $item['public_id'])
                . '"><article><img src="' . $source . '" alt="" '
                . 'loading="lazy" decoding="async"><h3>'
                . $this->escape((string) $item['label']) . '</h3><dl>'
                . '<div><dt>Dimensiones de origen</dt><dd>'
                . (int) $item['source_width'] . ' &times; '
                . (int) $item['source_height'] . ' px</dd></div>'
                . '<div><dt>Dimensiones disponibles</dt><dd><ul>'
                . $variantItems . '</ul></dd></div>'
                . '<div><dt>Estado de uso</dt><dd><span class="'
                . 'webadminMedia__usage webadminMedia__usage--'
                . $usageModifier . '">' . $usageLabel . '</span></dd></div>'
                . '<div><dt>Creada</dt><dd><time datetime="'
                . $this->escape((string) $item['created_at']) . '">'
                . $this->escape(substr((string) $item['created_at'], 0, 10))
                . '</time></dd></div></dl>' . $deleteAction
                . '</article></li>';
        }
        if ($cards === '') {
            $cards = '<li data-webadmin-media-empty><p>No hay im&aacute;genes '
                . 'en la biblioteca.</p></li>';
        }

        return '<ul data-webadmin-media-catalog>' . $cards . '</ul>';
    }

    public function updated(
        string $basePath,
        ?WebAdminShellContext $shell = null
    ): string
    {
        $shell ??= new WebAdminShellContext(
            basePath: $basePath,
            logoutCsrf: null,
            activePath: '/media'
        );

        return $this->shellRenderer->render(
            'Imagen guardada',
            '<article class="webadminMedia" aria-labelledby="media-updated-title">'
            . '<h1 id="media-updated-title">Imagen guardada</h1>'
            . '<p role="status" aria-live="polite">La imagen y sus variantes '
            . 'AVIF se han guardado correctamente.</p><p><a href="'
            . $this->path($basePath, '/media')
            . '">Volver a la biblioteca</a></p></article>',
            $shell
        );
    }

    public function deleted(
        string $basePath,
        ?WebAdminShellContext $shell = null
    ): string {
        $shell ??= new WebAdminShellContext(
            basePath: $basePath,
            logoutCsrf: null,
            activePath: '/media'
        );

        return $this->shellRenderer->render(
            'Imagen enviada a cuarentena',
            '<article class="webadminMedia" aria-labelledby="media-deleted-title">'
            . '<h1 id="media-deleted-title">Imagen enviada a cuarentena</h1>'
            . '<p role="status">La imagen se ha retirado de la biblioteca y '
            . 'permanece conservada para una recuperaci&oacute;n operativa.</p>'
            . '<p><a href="' . $this->path($basePath, '/media')
            . '">Volver a la biblioteca</a></p></article>',
            $shell
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

    private function kilobytes(int $bytes): string
    {
        return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }

    /** @return array{string, 'used'|'unused'|'unknown'} */
    private function usagePresentation(string $status): array
    {
        return match ($status) {
            'used' => ['Usada', 'used'],
            'unused' => ['Sin usar', 'unused'],
            default => ['Estado no disponible', 'unknown'],
        };
    }
}
