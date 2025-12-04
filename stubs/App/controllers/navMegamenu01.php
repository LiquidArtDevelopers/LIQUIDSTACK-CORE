<?php
function controller_navMegamenu01(int $i = 0, array $params = []): string
{
    $pad  = sprintf('%02d', $i);
    $pref = "navMegamenu01_{$pad}_";

    $iconForward = '<img data-lang="forward" src="'.
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
        [
            'type'  => 'simple',
            'value' => $buildLink("{$pref}contactLink", "{$pref}contactText"),
        ],
    ];

    if (!isset($_SESSION["id_rol"])):
        $col1Items[] = [
            'type'  => 'simple',
            'value' => $buildLink("{$pref}login", "{$pref}loginText"),
        ];
    endif;

    if (isset($_SESSION["id_rol"])):
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

    $col1Html = '<ul>';
    foreach ($col1Items as $item) {
        if ($item['type'] === 'simple') {
            $s       = $item['value'];
            $spanTxt = $GLOBALS[$s['spanDL']]->text;
            $col1Html .= '<li><a data-lang="'.$s['aDL'].'" href="'.$s['href'].'" title="'.$s['title'].'">'.$iconForward.'<span data-lang="'.$s['spanDL'].'">'.$spanTxt.'</span></a></li>';
            continue;
        }

        $g        = $item['value'];
        $groupTxt = $GLOBALS[$g['spanDL']]->text;
        $col1Html .= '<li><div><a data-lang="'.$g['aDL'].'" href="'.$g['href'].'" title="'.$g['title'].'">'.$iconForward.'<span data-lang="'.$g['spanDL'].'">'.$groupTxt.'</span></a><div class="submenu"><ul>';
        foreach ($g['items'] as $sub) {
            $subTxt = $GLOBALS[$sub['spanDL']]->text;
            $col1Html .= '<li><a data-lang="'.$sub['aDL'].'" href="'.$sub['href'].'" title="'.$subTxt.'">'.$iconForward.'<span data-lang="'.$sub['spanDL'].'">'.$subTxt.'</span></a></li>';
        }
        $col1Html .= '</ul></div></div></li>';
    }
    $col1Html .= '</ul>';

    $col2Simple = [
        // [
        //     'aDL'   => "{$pref}col02link_01",
        //     'spanDL'=> "{$pref}col02span_01",
        //     'href'  => $GLOBALS["{$pref}col02link_01_href"],
        //     'title' => $GLOBALS["{$pref}col02link_01"]->title,
        //     'text'  => $GLOBALS["{$pref}col02span_01"]->text,
        // ],
        // [
        //     'aDL'   => "{$pref}col02link_02",
        //     'spanDL'=> "{$pref}col02span_02",
        //     'href'  => $GLOBALS["{$pref}col02link_02_href"],
        //     'title' => $GLOBALS["{$pref}col02link_02"]->title,
        //     'text'  => $GLOBALS["{$pref}col02span_02"]->text,
        // ],
    ];
    $col2Links = '<ul>';
    foreach ($col2Simple as $s) {
        $col2Links .= '<li><a data-lang="'.$s['aDL'].'" href="'.$s['href'].'" title="'.$s['title'].'" target="_blank">'.$iconForward.'<span data-lang="'.$s['spanDL'].'">'.$s['text'].'</span></a></li>';
    }
    $col2Links .= '</ul>';

    // términos y condiciones
    $col2Simple2 = [
        // Cookies
        [
            'aDL'   => "{$pref}col02link2_01",
            'spanDL'=> "{$pref}col02span2_01",            
            'text'  => $GLOBALS["{$pref}col02span2_01"]->text,
            'tipo'  => "cookies",
        ],
        // Privacidad
        [
            'aDL'   => "{$pref}col02link2_02",
            'spanDL'=> "{$pref}col02span2_02",            
            'text'  => $GLOBALS["{$pref}col02span2_02"]->text,
            'tipo'  => "privacidad",
        ],
        // Aviso legal
        [
            'aDL'   => "{$pref}col02link2_03",
            'spanDL'=> "{$pref}col02span2_03",            
            'text'  => $GLOBALS["{$pref}col02span2_03"]->text,
            'tipo'  => "",
        ],
    ];
    $col2Links2 = '<ul>';
    foreach ($col2Simple2 as $s) {
        $col2Links2 .= '<li><span data-lang="'.$s['aDL'].'" class="legal" data-tipo="'.$s['tipo'].'">'.$iconForward.'<span data-lang="'.$s['spanDL'].'">'.$s['text'].'</span></span></li>';
    }
    $col2Links2 .= '</ul>';

    $btn = $GLOBALS["{$pref}become_member_button"];
    $col2Button = '<a data-lang="'."{$pref}become_member_button".'" href="'.$btn->href.'" title="'.$btn->title.'" class="boton">'.$btn->text.'</a>';

    $col2Social = '<div class="rrss">'
        . '<a data-lang="'."{$pref}rrss_yt".'" href="'.$GLOBALS["{$pref}rrss_yt_href"].'" target="_blank" title="'.$GLOBALS["{$pref}rrss_yt"]->title.'"><img data-lang="'."{$pref}rrss_yt_img".'" src="'.$_ENV['RAIZ'].'/'.$GLOBALS["{$pref}rrss_yt_img"]->src.'" alt="'.$GLOBALS["{$pref}rrss_yt_img"]->alt.'" title="'.$GLOBALS["{$pref}rrss_yt_img"]->title.'"></a>'
        . '<a data-lang="'."{$pref}rrss_in".'" href="'.$GLOBALS["{$pref}rrss_in_href"].'" target="_blank" title="'.$GLOBALS["{$pref}rrss_in"]->title.'"><img data-lang="'."{$pref}rrss_in_img".'" src="'.$_ENV['RAIZ'].'/'.$GLOBALS["{$pref}rrss_in_img"]->src.'" alt="'.$GLOBALS["{$pref}rrss_in_img"]->alt.'" title="'.$GLOBALS["{$pref}rrss_in_img"]->title.'"></a>'
        . '<a data-lang="'."{$pref}rrss_fb".'" href="'.$GLOBALS["{$pref}rrss_fb_href"].'" target="_blank" title="'.$GLOBALS["{$pref}rrss_fb"]->title.'"><img data-lang="'."{$pref}rrss_fb_img".'" src="'.$_ENV['RAIZ'].'/'.$GLOBALS["{$pref}rrss_fb_img"]->src.'" alt="'.$GLOBALS["{$pref}rrss_fb_img"]->alt.'" title="'.$GLOBALS["{$pref}rrss_fb_img"]->title.'"></a>'
        . '<a data-lang="'."{$pref}rrss_ig".'" href="'.$GLOBALS["{$pref}rrss_ig_href"].'" target="_blank" title="'.$GLOBALS["{$pref}rrss_ig"]->title.'"><img data-lang="'."{$pref}rrss_ig_img".'" src="'.$_ENV['RAIZ'].'/'.$GLOBALS["{$pref}rrss_ig_img"]->src.'" alt="'.$GLOBALS["{$pref}rrss_ig_img"]->alt.'" title="'.$GLOBALS["{$pref}rrss_ig_img"]->title.'"></a>'
        . '</div>';

    $logo = $GLOBALS["{$pref}logo_business"];
    $col2Logo = '<div><img data-lang="'."{$pref}logo_business".'" src="'.$_ENV['RAIZ'].'/'.$logo->src.'" alt="'.$logo->alt.'" title="'.$logo->title.'"></div>';

    $col3Html = '<ul>';
    $col3Html .= '<li><a data-lang="'."{$pref}correo_link".'" href="mailto:'.$GLOBALS["{$pref}correo_link_href"].'" title="'.$GLOBALS["{$pref}correo_link"]->title.'" class="si_select linkReducido"><img data-lang="'."{$pref}correo_img".'" src="'.$_ENV['RAIZ'].'/'.$GLOBALS["{$pref}correo_img"]->src.'" alt="'.$GLOBALS["{$pref}correo_img"]->alt.'" title="'.$GLOBALS["{$pref}correo_img"]->title.'"><span data-lang="'."{$pref}correo_text".'">'.$GLOBALS["{$pref}correo_text"]->text.'</span></a></li>';

    $sedes = [
        ['label'=>'DONOSTIA','tels'=>['943 21 53 54'],'addr'=>'Hegaztien Pasealekua, 5, 20009 Donostia / San Sebastián, Gipuzkoa','map'=>'https://maps.app.goo.gl/1irmxtrYHNRD3HQe9'],
    ];

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
        '{col2-links}'         => $col2Links.$col2Links2,
        '{col2-button}'        => '',
        '{col2-follow-dl}'     => "{$pref}follow_us_social_media",
        '{col2-follow-text}'   => $GLOBALS["{$pref}follow_us_social_media"]->text,
        '{col2-social}'        => $col2Social,
        '{col2-logo-business}' => $col2Logo,
        '{col3-intro-dl}'      => "{$pref}contact",
        '{col3-intro-text}'    => $GLOBALS["{$pref}contact"]->text,
        '{col3-links}'         => $col3Html,
    ];
    $pageVars = array_replace($pageVars, $params);
    return render('App/templates/_navMegamenu01.html', $pageVars);
}
?>
