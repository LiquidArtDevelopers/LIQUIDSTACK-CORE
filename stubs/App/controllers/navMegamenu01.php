<?php
/**
 * Copy recomendado: títulos de columna 2-48 caracteres; etiquetas de enlace
 * 1-48 caracteres; textos de contacto 3-80 caracteres.
 */
function controller_navMegamenu01(int $i = 0, array $params = []): string
{
    $pad  = sprintf('%02d', $i);
    $pref = "navMegamenu01_{$pad}_";

    $escape = static fn (mixed $value): string => htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );

    $iconForward = '<img data-lang="'.$pref.'forward" src="'.
        $_ENV['RAIZ'].'/'.$GLOBALS["{$pref}forward"]->src.'" alt="'.
        $GLOBALS["{$pref}forward"]->alt.'" title="'.
        $GLOBALS["{$pref}forward"]->title.'">';


    $extractHref = static function (string $globalKey): string {
        $linkObj = $GLOBALS[$globalKey] ?? null;

        if (is_object($linkObj) && isset($linkObj->href)) {
            return (string) $linkObj->href;
        }

        if (is_array($linkObj) && isset($linkObj['href'])) {
            return (string) $linkObj['href'];
        }

        return '';
    };

    $extractTitle = static function (string $globalKey): string {
        $linkObj = $GLOBALS[$globalKey] ?? null;

        if (is_object($linkObj) && isset($linkObj->title)) {
            return (string) $linkObj->title;
        }

        if (is_array($linkObj) && isset($linkObj['title'])) {
            return (string) $linkObj['title'];
        }

        return '';
    };

    $buildLink = static function (
        string $linkKey,
        string $textKey,
        ?string $overrideHref = null,
        array $hrefOptions = ['absolute' => false]
    ) use ($extractHref, $extractTitle): array {
        $hrefValue = $overrideHref ?? $extractHref($linkKey);
        $href      = $overrideHref === null ? resolve_localized_href($hrefValue, $hrefOptions) : $hrefValue;

        return [
            'aDL'    => $linkKey,
            'href'   => $href,
            'title'  => $extractTitle($linkKey),
            'spanDL' => $textKey,
        ];
    };

    $routeDefinitions = $GLOBALS['arrayRutasGet'] ?? null;
    if (!is_array($routeDefinitions)) {
        $routeFile = dirname(__DIR__) . '/config/routes/get.php';
        $routeDefinitions = is_file($routeFile) ? require $routeFile : [];
        if (!is_array($routeDefinitions)) {
            $routeDefinitions = [];
        }
    }

    $resolveContentRoute = static function (array $contents) use (
        $routeDefinitions
    ): string {
        $lang = (string) ($GLOBALS['lang'] ?? ($_ENV['LANG_DEFAULT'] ?? ''));
        $routes = $routeDefinitions[$lang] ?? [];
        if (!is_array($routes)) {
            return '';
        }

        foreach ($routes as $route => $definition) {
            if (
                is_string($route)
                && is_array($definition)
                && in_array($definition['content'] ?? null, $contents, true)
            ) {
                return $route;
            }
        }

        return '';
    };

    $catalogText = static function (string $key): string {
        $entry = $GLOBALS[$key] ?? null;

        if (is_object($entry) && isset($entry->text)) {
            return (string) $entry->text;
        }
        if (is_array($entry) && isset($entry['text'])) {
            return (string) $entry['text'];
        }

        return '';
    };

    $renderMenuItems = static function (array $items) use (
        $catalogText,
        $escape,
        $iconForward
    ): string {
        $html = '<ul>';

        foreach ($items as $item) {
            if (!is_array($item) || !is_array($item['value'] ?? null)) {
                continue;
            }

            $value = $item['value'];
            $href = trim((string) ($value['href'] ?? ''));
            $linkKey = (string) ($value['aDL'] ?? '');
            $textKey = (string) ($value['spanDL'] ?? '');
            if ($href === '' || $linkKey === '' || $textKey === '') {
                continue;
            }

            $text = array_key_exists('text', $value)
                ? (string) $value['text']
                : $catalogText($textKey);
            $title = trim((string) ($value['title'] ?? ''));
            if ($title === '') {
                $title = $text;
            }

            if (($item['type'] ?? null) === 'simple') {
                $html .= '<li><a data-lang="'.$escape($linkKey).'" href="'
                    .$escape($href).'" title="'.$escape($title).'">'
                    .$iconForward.'<span data-lang="'.$escape($textKey).'">'
                    .$escape($text).'</span></a></li>';
                continue;
            }

            if (($item['type'] ?? null) !== 'group') {
                continue;
            }

            $html .= '<li><div class="menu-group"><a data-lang="'
                .$escape($linkKey).'" href="'.$escape($href).'" title="'
                .$escape($title).'">'.$iconForward.'<span data-lang="'
                .$escape($textKey).'">'.$escape($text)
                .'</span></a><div class="submenu"><ul>';

            foreach (($value['items'] ?? []) as $subItem) {
                if (!is_array($subItem)) {
                    continue;
                }
                $subHref = trim((string) ($subItem['href'] ?? ''));
                $subLinkKey = (string) ($subItem['aDL'] ?? '');
                $subTextKey = (string) ($subItem['spanDL'] ?? '');
                if ($subHref === '' || $subLinkKey === '' || $subTextKey === '') {
                    continue;
                }
                $subText = array_key_exists('text', $subItem)
                    ? (string) $subItem['text']
                    : $catalogText($subTextKey);
                $subTitle = trim((string) ($subItem['title'] ?? ''));
                if ($subTitle === '') {
                    $subTitle = $subText;
                }
                $html .= '<li><a data-lang="'.$escape($subLinkKey).'" href="'
                    .$escape($subHref).'" title="'.$escape($subTitle).'">'
                    .$iconForward.'<span data-lang="'.$escape($subTextKey).'">'
                    .$escape($subText).'</span></a></li>';
            }

            $html .= '</ul></div></div></li>';
        }

        return $html . '</ul>';
    };

    $col1Items = [
        [
            'type'  => 'simple',
            'value' => $buildLink("{$pref}home", "{$pref}homeText", homeUrl($GLOBALS['lang']), []),
        ],
        [
            'type'  => 'group',
            'value' => [
                'aDL'    => "{$pref}services",
                'href'   => resolve_localized_href($extractHref("{$pref}services"), ['absolute' => false]),
                'title'  => $extractTitle("{$pref}services"),
                'spanDL' => "{$pref}servicesText",
                'items'  => [
                    $buildLink("{$pref}servicesItem0", "{$pref}servicesItem0Text"),
                ],
            ],
        ],
    ];

    $publicLinkKeys = $params['public_link_keys'] ?? [];
    if (is_array($publicLinkKeys)) {
        foreach ($publicLinkKeys as $publicLinkKey) {
            if (!is_array($publicLinkKey)) {
                continue;
            }
            $linkKey = $publicLinkKey['link'] ?? null;
            $textKey = $publicLinkKey['text'] ?? null;
            $overrideHref = $publicLinkKey['href'] ?? null;
            if (
                !is_string($linkKey)
                || !is_string($textKey)
                || ($overrideHref !== null && !is_string($overrideHref))
                || !isset($GLOBALS[$linkKey], $GLOBALS[$textKey])
                || trim($overrideHref ?? $extractHref($linkKey)) === ''
            ) {
                continue;
            }
            $col1Items[] = [
                'type' => 'simple',
                'value' => $buildLink($linkKey, $textKey, $overrideHref),
            ];
        }
    }
    $col1Items[] = [
        'type'  => 'simple',
        'value' => $buildLink("{$pref}contactLink", "{$pref}contactText"),
    ];

    $showPrivateAccess = ($params['show_private_access'] ?? true) === true;
    if ($showPrivateAccess && !isset($_SESSION["id_rol"])):
        $col1Items[] = [
            'type'  => 'simple',
            'value' => $buildLink("{$pref}login", "{$pref}loginText"),
        ];
    endif;

    if ($showPrivateAccess && isset($_SESSION["id_rol"])):
        $privateLinks = [
            $buildLink("{$pref}link0", "{$pref}link0Text"),
            $buildLink("{$pref}link4", "{$pref}link4Text"),
            $buildLink("{$pref}link1", "{$pref}link1Text"),
            $buildLink("{$pref}link2", "{$pref}link2Text"),
            $buildLink("{$pref}link3", "{$pref}link3Text"),
        ];

        foreach ($privateLinks as $link) {
            $col1Items[] = [
                'type'  => 'simple',
                'value' => $link,
            ];
        }
    endif;

    $col1Html = $renderMenuItems($col1Items);

    $col2CookieLink = $GLOBALS["{$pref}col02link_01"] ?? null;
    $col2CookieText = $GLOBALS["{$pref}col02span2_01"] ?? null;
    $col2PrivacyLink = $GLOBALS["{$pref}col02link_02"] ?? null;
    $col2PrivacyText = $GLOBALS["{$pref}col02span2_02"] ?? null;
    $col2LegalLink = $GLOBALS["{$pref}col02link_03"] ?? null;
    $col2LegalText = $GLOBALS["{$pref}col02span2_03"] ?? null;

    $col2CookieHref = is_object($col2CookieLink)
        ? (string) ($col2CookieLink->href ?? '')
        : '';
    $col2CookieTitle = is_object($col2CookieLink)
        ? (string) ($col2CookieLink->title ?? '')
        : '';
    $col2CookieLabel = is_object($col2CookieText)
        ? (string) ($col2CookieText->text ?? '')
        : '';
    $col2PrivacyHref = is_object($col2PrivacyLink)
        ? (string) ($col2PrivacyLink->href ?? '')
        : '';
    $col2PrivacyTitle = is_object($col2PrivacyLink)
        ? (string) ($col2PrivacyLink->title ?? '')
        : '';
    $col2PrivacyLabel = is_object($col2PrivacyText)
        ? (string) ($col2PrivacyText->text ?? '')
        : '';
    $col2LegalHref = is_object($col2LegalLink)
        ? (string) ($col2LegalLink->href ?? '')
        : '';
    $col2LegalTitle = is_object($col2LegalLink)
        ? (string) ($col2LegalLink->title ?? '')
        : '';
    $col2LegalLabel = is_object($col2LegalText)
        ? (string) ($col2LegalText->text ?? '')
        : '';

    $resolveColumnTwoHref = static function (
        string $configuredHref,
        array $contents
    ) use ($resolveContentRoute): string {
        $configuredHref = trim($configuredHref);
        if ($configuredHref !== '') {
            return resolve_localized_href($configuredHref, ['absolute' => false]);
        }

        return $resolveContentRoute($contents);
    };

    /*
     * La columna 2 comparte exactamente el esquema simple/group de la columna
     * 1. Se pueden añadir, quitar, reordenar o agrupar entradas sin cambiar el
     * renderer; redes y logotipo permanecen como bloques independientes.
     */
    $col2Items = [
        [
            'type' => 'simple',
            'value' => [
                'aDL' => "{$pref}col02link_01",
                'href' => $resolveColumnTwoHref(
                    $col2CookieHref,
                    ['politica-de-cookies', 'gestion-cookies']
                ),
                'title' => $col2CookieTitle,
                'spanDL' => "{$pref}col02span2_01",
                'text' => $col2CookieLabel,
            ],
        ],
        [
            'type' => 'simple',
            'value' => [
                'aDL' => "{$pref}col02link_02",
                'href' => $resolveColumnTwoHref(
                    $col2PrivacyHref,
                    ['politica-de-privacidad']
                ),
                'title' => $col2PrivacyTitle,
                'spanDL' => "{$pref}col02span2_02",
                'text' => $col2PrivacyLabel,
            ],
        ],
        [
            'type' => 'simple',
            'value' => [
                'aDL' => "{$pref}col02link_03",
                'href' => $resolveColumnTwoHref(
                    $col2LegalHref,
                    ['aviso-legal']
                ),
                'title' => $col2LegalTitle,
                'spanDL' => "{$pref}col02span2_03",
                'text' => $col2LegalLabel,
            ],
        ],
    ];
    $col2Html = $renderMenuItems($col2Items);

    /* Añadir, quitar o reordenar entradas; un array vacío anula el bloque. */
    $socialItems = [
        [
            'link_key' => "{$pref}rrss_fb",
            'href' => $GLOBALS["{$pref}rrss_fb_href"] ?? '',
            'title' => $GLOBALS["{$pref}rrss_fb"]->title ?? '',
            'image_key' => "{$pref}rrss_fb_img",
            'src' => $GLOBALS["{$pref}rrss_fb_img"]->src ?? '',
            'alt' => $GLOBALS["{$pref}rrss_fb_img"]->alt ?? '',
            'image_title' => $GLOBALS["{$pref}rrss_fb_img"]->title ?? '',
            'default_src' => 'assets/img/system/fb.svg',
        ],
        [
            'link_key' => "{$pref}rrss_in",
            'href' => $GLOBALS["{$pref}rrss_in_href"] ?? '',
            'title' => $GLOBALS["{$pref}rrss_in"]->title ?? '',
            'image_key' => "{$pref}rrss_in_img",
            'src' => $GLOBALS["{$pref}rrss_in_img"]->src ?? '',
            'alt' => $GLOBALS["{$pref}rrss_in_img"]->alt ?? '',
            'image_title' => $GLOBALS["{$pref}rrss_in_img"]->title ?? '',
            'default_src' => 'assets/img/system/in.svg',
        ],
        [
            'link_key' => "{$pref}rrss_yt",
            'href' => $GLOBALS["{$pref}rrss_yt_href"] ?? '',
            'title' => $GLOBALS["{$pref}rrss_yt"]->title ?? '',
            'image_key' => "{$pref}rrss_yt_img",
            'src' => $GLOBALS["{$pref}rrss_yt_img"]->src ?? '',
            'alt' => $GLOBALS["{$pref}rrss_yt_img"]->alt ?? '',
            'image_title' => $GLOBALS["{$pref}rrss_yt_img"]->title ?? '',
            'default_src' => 'assets/img/system/yt.svg',
        ],
        [
            'link_key' => "{$pref}rrss_ig",
            'href' => $GLOBALS["{$pref}rrss_ig_href"] ?? '',
            'title' => $GLOBALS["{$pref}rrss_ig"]->title ?? '',
            'image_key' => "{$pref}rrss_ig_img",
            'src' => $GLOBALS["{$pref}rrss_ig_img"]->src ?? '',
            'alt' => $GLOBALS["{$pref}rrss_ig_img"]->alt ?? '',
            'image_title' => $GLOBALS["{$pref}rrss_ig_img"]->title ?? '',
            'default_src' => 'assets/img/system/ig.svg',
        ],
    ];

    $col2SocialItems = '';
    foreach ($socialItems as $social) {
        $href = trim((string) $social['href']);
        $imageSource = trim((string) $social['src']);
        if ($imageSource === '') {
            $imageSource = $social['default_src'];
        }
        $title = trim((string) $social['title']);
        $imageTitle = trim((string) $social['image_title']);
        $alt = trim((string) $social['alt']);
        $label = $title !== '' ? $title : ($alt !== '' ? $alt : $imageTitle);
        $linkAttributes = $href === ''
            ? ''
            : ' href="'.$escape($href).'" target="_blank" rel="noopener noreferrer"';

        $col2SocialItems .= '<a data-lang="'.$escape($social['link_key']).'"'
            .$linkAttributes.' aria-label="'.$escape($label).'" title="'
            .$escape($title).'"><img data-lang="'.$escape($social['image_key'])
            .'" src="'.$escape(rtrim((string) $_ENV['RAIZ'], '/').'/'.ltrim($imageSource, '/'))
            .'" alt="'.$escape($alt).'" title="'.$escape($imageTitle).'">'
            .'</a>';
    }
    $col2Social = $col2SocialItems === ''
        ? ''
        : '<div class="rrss">'.$col2SocialItems.'</div>';

    $logo = $GLOBALS["{$pref}logo_business"];
    $col2Logo = '<div><img data-lang="'."{$pref}logo_business".'" src="'.$_ENV['RAIZ'].'/'.$logo->src.'" alt="'.$logo->alt.'" title="'.$logo->title.'"></div>';

    $col3Html = '<ul>';
    $col3Html .= '<li><a data-lang="'."{$pref}correo_link".'" href="mailto:'.$GLOBALS["{$pref}correo_link_href"].'" title="'.$GLOBALS["{$pref}correo_link"]->title.'" class="si_select linkReducido"><img data-lang="'."{$pref}correo_img".'" src="'.$_ENV['RAIZ'].'/'.$GLOBALS["{$pref}correo_img"]->src.'" alt="'.$GLOBALS["{$pref}correo_img"]->alt.'" title="'.$GLOBALS["{$pref}correo_img"]->title.'"><span data-lang="'."{$pref}correo_text".'">'.$GLOBALS["{$pref}correo_text"]->text.'</span></a></li>';

    /* Las sedes son datos propios de cada proyecto, nunca defaults de CORE. */
    $sedes = is_array($params['offices'] ?? null)
        ? $params['offices']
        : [];

    foreach ($sedes as $s) {
        $col3Html .= '<li><p class="resaltado">'.$s['label'].'</p><div>';
        $idx = 0;
        foreach ($s['tels'] as $tel) {
            $iconTel = $idx++ === 0 ? "{$pref}tel_img" : "{$pref}mp_img";
            $col3Html .= '<a data-lang="'."{$pref}tel_link".'" href="tel:'.preg_replace('/[^0-9+]/','',$tel).'" title="'.$GLOBALS["{$pref}tel_link"]->title.'" class="si_select"><img data-lang="'.$iconTel.'" src="'.$_ENV['RAIZ'].'/'.$GLOBALS[$iconTel]->src.'" alt="'.$GLOBALS[$iconTel]->alt.'" title="'.$GLOBALS[$iconTel]->title.'"><span>'.$tel.'</span></a>';
        }
        $col3Html .= '</div><a data-lang="'."{$pref}ubicacion_link".'" href="'.$s['map'].'" target="_blank" title="'.$GLOBALS["{$pref}ubicacion_link"]->title.'" class="si_select"><img data-lang="'."{$pref}ubicacion_img".'" src="'.$_ENV['RAIZ'].'/'.$GLOBALS["{$pref}ubicacion_img"]->src.'" alt="'.$GLOBALS["{$pref}ubicacion_img"]->alt.'" title="'.$GLOBALS["{$pref}ubicacion_img"]->title.'"><span>'.$s['addr'].'</span></a></li>';
    }
    $col3Html .= '</ul>';

    $pageVars = [
        '{col1-intro-dl}'      => "{$pref}content_of_this_website",
        '{col1-intro-text}'    => $GLOBALS["{$pref}content_of_this_website"]->text,
        '{col1-links}'         => $col1Html,
        '{col2-intro-dl}'      => "{$pref}link_of_interest",
        '{col2-intro-text}'    => $GLOBALS["{$pref}link_of_interest"]->text,
        '{col2-links}'         => $col2Html,
        '{col2-button}'        => '',
        '{col2-follow-dl}'     => "{$pref}follow_us_social_media",
        '{col2-follow-text}'   => $GLOBALS["{$pref}follow_us_social_media"]->text,
        '{col2-social}'        => $col2Social,
        '{col2-logo-business}' => $col2Logo,
        '{col3-intro-dl}'      => "{$pref}contact",
        '{col3-intro-text}'    => $GLOBALS["{$pref}contact"]->text,
        '{col3-links}'         => $col3Html,
    ];
    unset(
        $params['offices'],
        $params['public_link_keys'],
        $params['show_private_access']
    );
    $pageVars = array_replace($pageVars, $params);
    return render('App/templates/_navMegamenu01.html', $pageVars);
}
?>
