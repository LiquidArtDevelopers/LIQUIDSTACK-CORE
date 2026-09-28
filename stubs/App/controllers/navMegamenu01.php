<?php

/**
 * Megamenú global configurable desde su sniper.
 *
 * Copy recomendado: títulos de columna de 15–40 caracteres; enlaces de
 * 2–55 caracteres; direcciones de 5–120 caracteres.
 */
function controller_navMegamenu01(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $pref = "navMegamenu01_{$pad}_";
    $root = rtrim((string) ($_ENV['RAIZ'] ?? ''), '/');

    $escape = static fn (mixed $value): string => htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $catalogKey = static function (mixed $suffix) use ($pref): ?string {
        if (!is_string($suffix)) {
            return null;
        }

        $suffix = trim($suffix);
        if ($suffix === '' || preg_match('/^[A-Za-z0-9_-]+$/', $suffix) !== 1) {
            return null;
        }

        return str_starts_with($suffix, $pref) ? $suffix : $pref . $suffix;
    };

    $catalogField = static function (
        mixed $suffix,
        string $field,
        mixed $fallback = ''
    ) use ($catalogKey): mixed {
        $key = $catalogKey($suffix);
        if ($key === null || !array_key_exists($key, $GLOBALS)) {
            return $fallback;
        }

        $entry = $GLOBALS[$key];
        if (is_object($entry) && property_exists($entry, $field)) {
            return $entry->{$field};
        }
        if (is_array($entry) && array_key_exists($field, $entry)) {
            return $entry[$field];
        }
        if ($field === 'value' && is_scalar($entry)) {
            return $entry;
        }

        return $fallback;
    };

    $assetUrl = static function (mixed $source) use ($root): string {
        $source = trim((string) $source);
        if ($source === '') {
            return '';
        }
        if (
            str_starts_with($source, 'data:image/')
            || str_starts_with($source, '//')
            || preg_match('#^https?://#i', $source) === 1
        ) {
            return $source;
        }

        return ($root !== '' ? $root . '/' : '/') . ltrim($source, '/');
    };

    $safeHref = static function (mixed $value, string $fallback = ''): string {
        $href = trim((string) $value);
        if ($href === '') {
            return '';
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $href) === 1) {
            return $fallback;
        }

        $scheme = parse_url($href, PHP_URL_SCHEME);
        if (
            is_string($scheme)
            && $scheme !== ''
            && !in_array(
                strtolower($scheme),
                ['http', 'https', 'mailto', 'tel'],
                true
            )
        ) {
            return $fallback;
        }

        return $href;
    };

    $resolveHref = static function (array $config) use (
        $catalogField,
        $safeHref
    ): string {
        $hasOverride = array_key_exists('href', $config);
        $rawHref = $hasOverride
            ? (string) $config['href']
            : (string) $catalogField(
                $config['link_key'] ?? null,
                'href',
                ''
            );
        if (trim($rawHref) === '') {
            return '';
        }

        if (($config['resolve'] ?? true) === false) {
            return $safeHref($rawHref);
        }

        $resolved = resolve_localized_href($rawHref, [
            'absolute' => ($config['absolute'] ?? false) === true,
        ]);

        return $safeHref($resolved);
    };

    $linkWindowAttributes = static function (array $config): string {
        $external = ($config['external'] ?? false) === true;
        $target = $external ? '_blank' : ($config['target'] ?? '_self');
        if (
            !in_array($target, ['_self', '_blank'], true)
            || $target === '_self'
        ) {
            return '';
        }

        return ' target="_blank" rel="noopener noreferrer"';
    };

    $isVisible = static function (array $config): bool {
        $when = $config['when'] ?? 'always';
        if (!in_array($when, ['always', 'guest', 'authenticated'], true)) {
            return false;
        }

        $authenticated = isset($_SESSION['id_rol']);
        return $when === 'always'
            || ($when === 'guest' && !$authenticated)
            || ($when === 'authenticated' && $authenticated);
    };

    $renderImage = static function (
        mixed $suffix,
        string $fallbackSource = ''
    ) use ($assetUrl, $catalogField, $catalogKey, $escape): string {
        $key = $catalogKey($suffix);
        if ($key === null) {
            return '';
        }

        $source = (string) $catalogField($suffix, 'src', $fallbackSource);
        if ($source === '') {
            $source = $fallbackSource;
        }
        $source = $assetUrl($source);
        if ($source === '') {
            return '';
        }

        return '<img data-lang="' . $escape($key) . '" src="'
            . $escape($source) . '" alt="'
            . $escape($catalogField($suffix, 'alt', '')) . '" title="'
            . $escape($catalogField($suffix, 'title', '')) . '">';
    };

    $forwardIcon = $renderImage('forward');

    $renderNodes = null;
    $renderNodes = static function (mixed $nodes, int $depth = 1) use (
        &$renderNodes,
        $catalogField,
        $catalogKey,
        $escape,
        $forwardIcon,
        $isVisible,
        $linkWindowAttributes,
        $resolveHref
    ): string {
        if (!is_array($nodes) || $nodes === [] || $depth > 3) {
            return '';
        }

        $itemsHtml = '';
        foreach ($nodes as $node) {
            if (!is_array($node) || !$isVisible($node)) {
                continue;
            }

            $linkKey = $catalogKey($node['link_key'] ?? null);
            $textKey = $catalogKey($node['text_key'] ?? null);
            if ($linkKey === null || $textKey === null) {
                continue;
            }

            $text = (string) $catalogField($node['text_key'], 'text', '');
            $title = (string) $catalogField(
                $node['link_key'],
                'title',
                ''
            );
            $href = $resolveHref($node);
            if ($href === '') {
                continue;
            }

            $linkHtml = '<a data-lang="' . $escape($linkKey) . '" href="'
                . $escape($href) . '" title="' . $escape($title) . '"'
                . $linkWindowAttributes($node) . '>' . $forwardIcon
                . '<span data-lang="' . $escape($textKey) . '">'
                . $escape($text) . '</span></a>';

            $childrenHtml = $depth < 3
                ? $renderNodes($node['children'] ?? [], $depth + 1)
                : '';
            if ($childrenHtml === '') {
                $itemsHtml .= '<li>' . $linkHtml . '</li>';
                continue;
            }

            $itemsHtml .= '<li><div class="menu-group">' . $linkHtml
                . '<div class="submenu">' . $childrenHtml . '</div>'
                . '</div></li>';
        }

        return $itemsHtml === '' ? '' : '<ul>' . $itemsHtml . '</ul>';
    };

    $renderIntro = static function (mixed $suffix) use (
        $catalogField,
        $catalogKey,
        $escape
    ): string {
        $key = $catalogKey($suffix);
        if ($key === null) {
            return '';
        }

        return '<p data-lang="' . $escape($key) . '">'
            . $escape($catalogField($suffix, 'text', '')) . '</p>';
    };

    /*
     * Compatibilidad temporal con llamadas anteriores a la configuración por
     * columnas. Mantiene operativos BASE y consumidores aún no migrados sin
     * volver a mezclar su configuración con el renderer canónico.
     */
    $usesStructuredConfig = array_key_exists('col1', $params)
        || array_key_exists('col2', $params)
        || array_key_exists('col3', $params);
    if (!$usesStructuredConfig) {
        $routeDefinitions = $GLOBALS['arrayRutasGet'] ?? null;
        if (!is_array($routeDefinitions)) {
            $routeFile = dirname(__DIR__) . '/config/routes/get.php';
            $routeDefinitions = is_file($routeFile)
                ? require $routeFile
                : [];
            if (!is_array($routeDefinitions)) {
                $routeDefinitions = [];
            }
        }

        $resolveContentRoute = static function (array $contents) use (
            $routeDefinitions
        ): string {
            $lang = (string) (
                $GLOBALS['lang'] ?? ($_ENV['LANG_DEFAULT'] ?? '')
            );
            $routes = $routeDefinitions[$lang] ?? [];
            if (!is_array($routes)) {
                return '';
            }

            foreach ($routes as $route => $definition) {
                if (
                    is_string($route)
                    && is_array($definition)
                    && in_array(
                        $definition['content'] ?? null,
                        $contents,
                        true
                    )
                ) {
                    return $route;
                }
            }

            return '';
        };

        $legacyLegalHref = static function (
            string $linkKey,
            array $contents
        ) use ($catalogField, $resolveContentRoute): string {
            $configuredHref = trim((string) $catalogField(
                $linkKey,
                'href',
                ''
            ));
            if ($configuredHref !== '') {
                return resolve_localized_href(
                    $configuredHref,
                    ['absolute' => false]
                );
            }

            return $resolveContentRoute($contents);
        };

        $col1Items = [
            [
                'link_key' => 'home',
                'text_key' => 'homeText',
                'href' => homeUrl($GLOBALS['lang']),
                'resolve' => false,
            ],
            [
                'link_key' => 'services',
                'text_key' => 'servicesText',
                'children' => [[
                    'link_key' => 'servicesItem0',
                    'text_key' => 'servicesItem0Text',
                ]],
            ],
        ];

        $publicLinkKeys = $params['public_link_keys'] ?? [];
        if (is_array($publicLinkKeys)) {
            foreach ($publicLinkKeys as $publicLink) {
                if (!is_array($publicLink)) {
                    continue;
                }
                $linkKey = $publicLink['link'] ?? null;
                $textKey = $publicLink['text'] ?? null;
                if (!is_string($linkKey) || !is_string($textKey)) {
                    continue;
                }

                $node = [
                    'link_key' => $linkKey,
                    'text_key' => $textKey,
                ];
                if (array_key_exists('href', $publicLink)) {
                    if (!is_string($publicLink['href'])) {
                        continue;
                    }
                    $node['href'] = $publicLink['href'];
                    $node['resolve'] = false;
                }
                $col1Items[] = $node;
            }
        }

        $col1Items[] = [
            'link_key' => 'contactLink',
            'text_key' => 'contactText',
        ];

        if (($params['show_private_access'] ?? true) === true) {
            $col1Items[] = [
                'link_key' => 'login',
                'text_key' => 'loginText',
                'when' => 'guest',
            ];
            foreach (['link0', 'link4', 'link1', 'link2', 'link3'] as $key) {
                $col1Items[] = [
                    'link_key' => $key,
                    'text_key' => $key . 'Text',
                    'when' => 'authenticated',
                ];
            }
        }

        $legacyOffices = [];
        foreach (
            is_array($params['offices'] ?? null) ? $params['offices'] : []
            as $office
        ) {
            if (!is_array($office)) {
                continue;
            }
            if (is_array($office['phones'] ?? null)) {
                $legacyOffices[] = $office;
                continue;
            }

            $phones = [];
            foreach (
                is_array($office['tels'] ?? null) ? $office['tels'] : []
                as $phoneIndex => $number
            ) {
                if (!is_scalar($number) || trim((string) $number) === '') {
                    continue;
                }
                $phones[] = [
                    'number' => (string) $number,
                    'link_key' => 'tel_link',
                    'image_key' => $phoneIndex === 0 ? 'tel_img' : 'mp_img',
                ];
            }

            $legacyOffices[] = [
                'label' => is_scalar($office['label'] ?? null)
                    ? (string) $office['label']
                    : '',
                'phones' => $phones,
                'address' => is_scalar($office['addr'] ?? null)
                    ? (string) $office['addr']
                    : '',
                'map' => is_scalar($office['map'] ?? null)
                    ? (string) $office['map']
                    : '',
                'map_link_key' => 'ubicacion_link',
                'map_image_key' => 'ubicacion_img',
            ];
        }

        $params = [
            'col1' => [
                'intro_key' => 'content_of_this_website',
                'items' => $col1Items,
            ],
            'col2' => [
                'intro_key' => 'link_of_interest',
                'items' => [
                    [
                        'link_key' => 'col02link_01',
                        'text_key' => 'col02span2_01',
                        'href' => $legacyLegalHref(
                            'col02link_01',
                            ['politica-de-cookies', 'gestion-cookies']
                        ),
                        'resolve' => false,
                    ],
                    [
                        'link_key' => 'col02link_02',
                        'text_key' => 'col02span2_02',
                        'href' => $legacyLegalHref(
                            'col02link_02',
                            ['politica-de-privacidad']
                        ),
                        'resolve' => false,
                    ],
                    [
                        'link_key' => 'col02link_03',
                        'text_key' => 'col02span2_03',
                        'href' => $legacyLegalHref(
                            'col02link_03',
                            ['aviso-legal']
                        ),
                        'resolve' => false,
                    ],
                ],
                'cta_html' => '',
                'follow_key' => 'follow_us_social_media',
                'socials' => [
                    [
                        'link_key' => 'rrss_fb',
                        'href_key' => 'rrss_fb_href',
                        'image_key' => 'rrss_fb_img',
                        'default_src' => 'assets/img/system/fb.svg',
                        'label' => 'Facebook',
                    ],
                    [
                        'link_key' => 'rrss_in',
                        'href_key' => 'rrss_in_href',
                        'image_key' => 'rrss_in_img',
                        'default_src' => 'assets/img/system/in.svg',
                        'label' => 'LinkedIn',
                    ],
                    [
                        'link_key' => 'rrss_yt',
                        'href_key' => 'rrss_yt_href',
                        'image_key' => 'rrss_yt_img',
                        'default_src' => 'assets/img/system/yt.svg',
                        'label' => 'YouTube',
                    ],
                    [
                        'link_key' => 'rrss_ig',
                        'href_key' => 'rrss_ig_href',
                        'image_key' => 'rrss_ig_img',
                        'default_src' => 'assets/img/system/ig.svg',
                        'label' => 'Instagram',
                    ],
                ],
                'logo' => ['image_key' => 'logo_business'],
            ],
            'col3' => [
                'intro_key' => 'contact',
                'email' => [
                    'link_key' => 'correo_link',
                    'address_key' => 'correo_link_href',
                    'text_key' => 'correo_text',
                    'image_key' => 'correo_img',
                ],
                'offices' => $legacyOffices,
            ],
        ];
    }

    $col1 = is_array($params['col1'] ?? null) ? $params['col1'] : [];
    $col2 = is_array($params['col2'] ?? null) ? $params['col2'] : [];
    $col3 = is_array($params['col3'] ?? null) ? $params['col3'] : [];

    $socialItemsHtml = '';
    $socials = $col2['socials'] ?? false;
    if (is_array($socials)) {
        foreach ($socials as $social) {
            if (!is_array($social) || !$isVisible($social)) {
                continue;
            }

            $linkKey = $catalogKey($social['link_key'] ?? null);
            $imageKey = $catalogKey($social['image_key'] ?? null);
            if ($linkKey === null || $imageKey === null) {
                continue;
            }

            $href = array_key_exists('href', $social)
                ? (string) $social['href']
                : (string) $catalogField(
                    $social['href_key'] ?? null,
                    'value',
                    ''
                );
            $href = $safeHref($href, '');
            $explicitLabel = is_scalar($social['label'] ?? null)
                ? trim((string) $social['label'])
                : '';
            $linkTitle = trim((string) $catalogField(
                $social['link_key'],
                'title',
                ''
            ));
            $imageAlt = trim((string) $catalogField(
                $social['image_key'],
                'alt',
                ''
            ));
            $imageTitle = trim((string) $catalogField(
                $social['image_key'],
                'title',
                ''
            ));
            $label = $explicitLabel !== ''
                ? $explicitLabel
                : ($linkTitle !== ''
                    ? $linkTitle
                    : ($imageAlt !== '' ? $imageAlt : $imageTitle));
            $image = $renderImage(
                $social['image_key'],
                is_string($social['default_src'] ?? null)
                    ? $social['default_src']
                    : ''
            );
            if ($image === '' || $label === '') {
                continue;
            }

            $hrefAttributes = $href === ''
                ? ''
                : ' href="' . $escape($href) . '"'
                    . $linkWindowAttributes([
                        'target' => $social['target'] ?? '_blank',
                        'external' => $social['external'] ?? true,
                    ]);
            $socialItemsHtml .= '<a data-lang="' . $escape($linkKey) . '"'
                . $hrefAttributes . ' aria-label="' . $escape($label)
                . '" title="' . $escape($label) . '">' . $image . '</a>';
        }
    }

    $socialBlock = '';
    if ($socialItemsHtml !== '') {
        $followKey = $catalogKey($col2['follow_key'] ?? null);
        if ($followKey !== null) {
            $socialBlock .= '<span data-lang="' . $escape($followKey) . '">'
                . $escape($catalogField($col2['follow_key'], 'text', ''))
                . '</span>';
        }
        $socialBlock .= '<div class="rrss">' . $socialItemsHtml . '</div>';
    }

    $logoHtml = '';
    $logo = $col2['logo'] ?? false;
    if (is_array($logo) && $logo !== []) {
        $logoImage = $renderImage($logo['image_key'] ?? null);
        if ($logoImage !== '') {
            $logoHtml = '<div class="megamenu-business-logo">'
                . $logoImage . '</div>';
        }
    }

    $col3ItemsHtml = '';
    $email = $col3['email'] ?? false;
    if (is_array($email) && $email !== []) {
        $address = trim((string) $catalogField(
            $email['address_key'] ?? null,
            'value',
            ''
        ));
        $address = preg_replace('/[\r\n]+/', '', $address) ?? '';
        $linkKey = $catalogKey($email['link_key'] ?? null);
        $textKey = $catalogKey($email['text_key'] ?? null);
        if ($address !== '' && $linkKey !== null && $textKey !== null) {
            $col3ItemsHtml .= '<li><a data-lang="' . $escape($linkKey)
                . '" href="mailto:' . $escape($address) . '" title="'
                . $escape($catalogField($email['link_key'], 'title', ''))
                . '" class="si_select linkReducido">'
                . $renderImage($email['image_key'] ?? null)
                . '<span data-lang="' . $escape($textKey) . '">'
                . $escape($catalogField($email['text_key'], 'text', ''))
                . '</span></a></li>';
        }
    }

    $offices = $col3['offices'] ?? [];
    if (is_array($offices)) {
        foreach ($offices as $office) {
            if (!is_array($office) || !$isVisible($office)) {
                continue;
            }

            $labelKey = $catalogKey($office['label_key'] ?? null);
            $label = $labelKey !== null
                ? (string) $catalogField($office['label_key'], 'text', '')
                : (string) ($office['label'] ?? '');
            $officeHtml = $label === ''
                ? ''
                : '<p' . ($labelKey !== null
                    ? ' data-lang="' . $escape($labelKey) . '"'
                    : '') . ' class="resaltado">' . $escape($label) . '</p>';

            $phoneItemsHtml = '';
            $phones = $office['phones'] ?? [];
            if (is_array($phones)) {
                foreach ($phones as $phone) {
                    if (!is_array($phone)) {
                        continue;
                    }
                    $number = array_key_exists('number_key', $phone)
                        ? (string) $catalogField(
                            $phone['number_key'],
                            'value',
                            ''
                        )
                        : (string) ($phone['number'] ?? '');
                    $number = trim($number);
                    $dial = preg_replace('/[^0-9+]/', '', $number) ?? '';
                    $linkKey = $catalogKey($phone['link_key'] ?? null);
                    if ($number === '' || $dial === '' || $linkKey === null) {
                        continue;
                    }

                    $phoneItemsHtml .= '<a data-lang="' . $escape($linkKey)
                        . '" href="tel:' . $escape($dial) . '" title="'
                        . $escape($catalogField(
                            $phone['link_key'],
                            'title',
                            ''
                        ))
                        . '" class="si_select">'
                        . $renderImage($phone['image_key'] ?? null)
                        . '<span>' . $escape($number) . '</span></a>';
                }
            }
            if ($phoneItemsHtml !== '') {
                $officeHtml .= '<div>' . $phoneItemsHtml . '</div>';
            }

            $addressKey = $catalogKey($office['address_key'] ?? null);
            $address = $addressKey !== null
                ? (string) $catalogField($office['address_key'], 'text', '')
                : (string) ($office['address'] ?? '');
            if ($address !== '') {
                $addressLang = $addressKey !== null
                    ? ' data-lang="' . $escape($addressKey) . '"'
                    : '';
                $addressContent = $renderImage(
                    $office['map_image_key'] ?? null
                ) . '<span' . $addressLang . '>' . $escape($address)
                    . '</span>';
                $map = $safeHref($office['map'] ?? '', '');
                $mapLinkKey = $catalogKey(
                    $office['map_link_key'] ?? null
                );
                if ($map !== '' && $mapLinkKey !== null) {
                    $officeHtml .= '<a data-lang="' . $escape($mapLinkKey)
                        . '" href="' . $escape($map) . '" title="'
                        . $escape($catalogField(
                            $office['map_link_key'],
                            'title',
                            ''
                        ))
                        . '" class="si_select" target="_blank" '
                        . 'rel="noopener noreferrer">' . $addressContent
                        . '</a>';
                } else {
                    $officeHtml .= '<span class="si_select">'
                        . $addressContent . '</span>';
                }
            }

            if ($officeHtml !== '') {
                $col3ItemsHtml .= '<li>' . $officeHtml . '</li>';
            }
        }
    }

    $ctaHtml = is_string($col2['cta_html'] ?? null)
        ? $col2['cta_html']
        : '';
    $col3Links = $col3ItemsHtml === ''
        ? ''
        : '<ul>' . $col3ItemsHtml . '</ul>';

    $pageVars = [
        '{col1-intro}' => $renderIntro($col1['intro_key'] ?? null),
        '{col1-links}' => $renderNodes($col1['items'] ?? []),
        '{col2-intro}' => $renderIntro($col2['intro_key'] ?? null),
        '{col2-links}' => $renderNodes($col2['items'] ?? []),
        '{col2-button}' => $ctaHtml,
        '{col2-social-block}' => $socialBlock,
        '{col2-logo-business}' => $logoHtml,
        '{col3-intro}' => $renderIntro($col3['intro_key'] ?? null),
        '{col3-links}' => $col3Links,
    ];

    return render('App/templates/_navMegamenu01.html', $pageVars);
}
