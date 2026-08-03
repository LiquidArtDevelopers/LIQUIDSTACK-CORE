<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

/**
 * Shared, dependency-free document shell for every private WebAdmin screen.
 *
 * Assets live in the module-owned public namespace and are synchronized by
 * the WebAdmin manifest. Keeping the shell here prevents each feature
 * renderer from growing its own head, CSP assumptions or body contract.
 */
final class WebAdminPageDocumentRenderer
{
    public const STYLESHEET_PATH = '/assets/modules/webadmin/webadmin.css';
    public const SCRIPT_PATH = '/assets/modules/webadmin/webadmin.js';

    public function render(
        string $title,
        string $body,
        ?WebAdminPageAssets $assets = null
    ): string
    {
        $assets ??= new WebAdminPageAssets();
        $stylesheets = '<link rel="stylesheet" href="'
            . self::STYLESHEET_PATH . '">';
        foreach ($assets->stylesheets() as $stylesheet) {
            if ($stylesheet === self::STYLESHEET_PATH) {
                continue;
            }
            $stylesheets .= '<link rel="stylesheet" href="'
                . $stylesheet . '">';
        }
        $scripts = '<script src="' . self::SCRIPT_PATH
            . '" defer></script>';
        foreach ($assets->scripts() as $script) {
            if ($script === self::SCRIPT_PATH) {
                continue;
            }
            $scripts .= '<script src="' . $script . '" defer></script>';
        }

        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow,noarchive">'
            . $stylesheets
            . '<title>' . htmlspecialchars(
                $title,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
                false
            ) . '</title></head><body class="webadmin">'
            . $body
            . $scripts
            . '</body></html>';
    }
}
