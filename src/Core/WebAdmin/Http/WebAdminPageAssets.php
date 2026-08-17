<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Http;

use InvalidArgumentException;

/**
 * Same-origin assets added to the common WebAdmin document.
 *
 * CORE's WebAdmin stylesheet and script are always emitted by the document
 * renderer. This value object only carries the additional assets required by
 * a private feature such as Blog. Scripts stay module-owned; stylesheets may
 * additionally reference a flat, hashed Vite build below /assets/css.
 */
final class WebAdminPageAssets
{
    /** @var list<string> */
    private readonly array $stylesheets;

    /** @var list<string> */
    private readonly array $scripts;

    /**
     * @param list<string> $stylesheets
     * @param list<string> $scripts
     */
    public function __construct(array $stylesheets = [], array $scripts = [])
    {
        $this->stylesheets = $this->normalize(
            $stylesheets,
            'css',
            'stylesheet'
        );
        $this->scripts = $this->normalize($scripts, 'js', 'script');
    }

    /** @return list<string> */
    public function stylesheets(): array
    {
        return $this->stylesheets;
    }

    /** @return list<string> */
    public function scripts(): array
    {
        return $this->scripts;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function normalize(
        array $paths,
        string $extension,
        string $kind
    ): array {
        if (!array_is_list($paths)) {
            throw new InvalidArgumentException(
                'WebAdmin page ' . $kind . ' paths must be a list.'
            );
        }

        $normalized = [];
        foreach ($paths as $path) {
            $moduleOwned = is_string($path) && preg_match(
                '#\A/assets/modules/[a-z][a-z0-9-]*/'
                    . '[A-Za-z0-9][A-Za-z0-9._/-]*\.'
                    . preg_quote($extension, '#') . '\z#D',
                $path
            ) === 1;
            $projectStylesheet = $extension === 'css'
                && is_string($path)
                && preg_match(
                    '#\A/assets/css/[A-Za-z0-9][A-Za-z0-9._-]*\.css\z#D',
                    $path
                ) === 1;
            if (
                !is_string($path)
                || (!$moduleOwned && !$projectStylesheet)
                || str_contains($path, '..')
                || str_contains($path, '//')
            ) {
                throw new InvalidArgumentException(
                    'Invalid WebAdmin page ' . $kind . ' path.'
                );
            }

            $normalized[$path] = true;
        }

        return array_keys($normalized);
    }
}
