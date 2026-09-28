<?php

/**
 * Configuración project-owned compartida por el navegador y el footer.
 *
 * Los nodos de col1/col2 admiten children hasta tres niveles. Para desactivar
 * CTA, logo, redes, correo o sedes se usa '', false o [], respectivamente.
 */
echo controller('navMegamenu01', 0, [
    'col1' => [
        'intro_key' => 'content_of_this_website',
        'items' => [
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
            [
                'link_key' => 'contactLink',
                'text_key' => 'contactText',
                'href' => '#contact',
                'resolve' => false,
            ],
        ],
    ],
    'col2' => [
        'intro_key' => 'link_of_interest',
        'items' => [
            [
                'link_key' => 'col02link_01',
                'text_key' => 'col02span2_01',
            ],
            [
                'link_key' => 'col02link_02',
                'text_key' => 'col02span2_02',
            ],
            [
                'link_key' => 'col02link_03',
                'text_key' => 'col02span2_03',
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
            ],
            [
                'link_key' => 'rrss_in',
                'href_key' => 'rrss_in_href',
                'image_key' => 'rrss_in_img',
                'default_src' => 'assets/img/system/in.svg',
            ],
            [
                'link_key' => 'rrss_yt',
                'href_key' => 'rrss_yt_href',
                'image_key' => 'rrss_yt_img',
                'default_src' => 'assets/img/system/yt.svg',
            ],
            [
                'link_key' => 'rrss_ig',
                'href_key' => 'rrss_ig_href',
                'image_key' => 'rrss_ig_img',
                'default_src' => 'assets/img/system/ig.svg',
            ],
        ],
        'logo' => [
            'image_key' => 'logo_business',
        ],
    ],
    'col3' => [
        'intro_key' => 'contact',
        'email' => [
            'link_key' => 'correo_link',
            'address_key' => 'correo_link_href',
            'text_key' => 'correo_text',
            'image_key' => 'correo_img',
        ],
        // Las sedes y sus teléfonos pertenecen a cada proyecto.
        'offices' => [],
    ],
]);
