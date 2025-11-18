<?php
/**
 * Directrices de copy para artForm01:
 * - Encabezado principal: 3-5 palabras orientadas a la acción inmediata.
 * - Subtítulo del formulario: 3-6 palabras explicando el siguiente paso.
 * - Intro: 35-55 palabras que anticipen el servicio y tiempos de respuesta.
 * - Etiquetas de campos: 2-4 palabras; placeholders 3-6 palabras con indicación clara.
 * - Avisos legales y botón: aviso de consentimiento 12-22 palabras, botón 2-3 palabras.
 * - Bloque lateral: subtítulo 3-5 palabras y párrafo 40-55 palabras detallando atención alternativa.
 * - Datos de contacto: textos 4-8 palabras; título de mapa 4-7 palabras.
 * - Estado de envío: encabezado 4-6 palabras, mensajes 6-12 palabras, CTA final 3-4 palabras.
 */
function controller_artForm01(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $loader = controller('moduleLoader01', $i + 1);
    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];
    $itemLevel    = $headerLevels['child'];
    $vars = [
        '{classVar}'               => "artForm01_{$pad}_classVar",
        '{header-primary}'         => '<h' . $baseLevel . ' data-lang="artForm01_' . $pad . '_headerPrimary">' . $GLOBALS["artForm01_{$pad}_headerPrimary"]->text . '</h' . $baseLevel . '>',
        '{grid1-header-secondary}' => '<h' . $itemLevel . ' data-lang="artForm01_' . $pad . '_headerSecondary">' . $GLOBALS["artForm01_{$pad}_headerSecondary"]->text . '</h' . $itemLevel . '>',
        '{grid2-p-dl}'             => "artForm01_{$pad}_intro_p",
        '{grid2-p-text}'           => $GLOBALS["artForm01_{$pad}_intro_p"]->text,
        '{label-name-dl}'          => "artForm01_{$pad}_label_name",
        '{label-name-text}'        => $GLOBALS["artForm01_{$pad}_label_name"]->text,
        '{ph-name-dl}'             => "artForm01_{$pad}_ph_name",
        '{ph-name-text}'           => $GLOBALS["artForm01_{$pad}_ph_name"]->placeholder,
        '{label-phone-dl}'         => "artForm01_{$pad}_label_phone",
        '{label-phone-text}'       => $GLOBALS["artForm01_{$pad}_label_phone"]->text,
        '{ph-phone-dl}'            => "artForm01_{$pad}_ph_phone",
        '{ph-phone-text}'          => $GLOBALS["artForm01_{$pad}_ph_phone"]->placeholder,
        '{label-mail-dl}'          => "artForm01_{$pad}_label_mail",
        '{label-mail-text}'        => $GLOBALS["artForm01_{$pad}_label_mail"]->text,
        '{ph-mail-dl}'             => "artForm01_{$pad}_ph_mail",
        '{ph-mail-text}'           => $GLOBALS["artForm01_{$pad}_ph_mail"]->placeholder,
        '{label-msg-dl}'           => "artForm01_{$pad}_label_msg",
        '{label-msg-text}'         => $GLOBALS["artForm01_{$pad}_label_msg"]->text,
        '{ph-msg-dl}'              => "artForm01_{$pad}_ph_msg",
        '{ph-msg-text}'            => $GLOBALS["artForm01_{$pad}_ph_msg"]->placeholder,
        '{terms-dl}'               => "artForm01_{$pad}_terms",
        '{terms-text}'             => $GLOBALS["artForm01_{$pad}_terms"]->text,
        '{privacy-dl}'             => "artForm01_{$pad}_privacy",
        '{privacy-text}'           => $GLOBALS["artForm01_{$pad}_privacy"]->text,
        '{label-captcha-dl}'       => "artForm01_{$pad}_label_captcha",
        '{label-captcha-text}'     => $GLOBALS["artForm01_{$pad}_label_captcha"]->text,
        '{ph-captcha-dl}'          => "artForm01_{$pad}_ph_captcha",
        '{ph-captcha-text}'        => $GLOBALS["artForm01_{$pad}_ph_captcha"]->placeholder,
        '{submit-dl}'              => "artForm01_{$pad}_submit",
        '{submit-text}'            => $GLOBALS["artForm01_{$pad}_submit"]->value,
        '{grid4-header-secondary}' => '<h' . $itemLevel . ' data-lang="artForm01_' . $pad . '_headerSecondary_side">' . $GLOBALS["artForm01_{$pad}_headerSecondary_side"]->text . '</h' . $itemLevel . '>',
        '{grid5-p-dl}'             => "artForm01_{$pad}_side_p",
        '{grid5-p-text}'           => $GLOBALS["artForm01_{$pad}_side_p"]->text,
        '{contact-tel-href}'       => $GLOBALS["artForm01_{$pad}_contact_tel_href"],
        '{contact-tel-title}'      => $GLOBALS["artForm01_{$pad}_contact_tel_title"],
        '{contact-tel-img-src}'    => $_ENV['RAIZ'].'/'.$GLOBALS["artForm01_{$pad}_contact_tel_img"]->src,
        '{contact-tel-img-alt}'    => $GLOBALS["artForm01_{$pad}_contact_tel_img"]->alt,
        '{contact-tel-img-title}'  => $GLOBALS["artForm01_{$pad}_contact_tel_img"]->title,
        '{contact-tel-dl}'         => "artForm01_{$pad}_contact_tel",
        '{contact-tel-text}'       => $GLOBALS["artForm01_{$pad}_contact_tel"]->text,
        '{contact-mail-href}'      => $GLOBALS["artForm01_{$pad}_contact_mail_href"],
        '{contact-mail-title}'     => $GLOBALS["artForm01_{$pad}_contact_mail_title"],
        '{contact-mail-img-src}'   => $_ENV['RAIZ'].'/'.$GLOBALS["artForm01_{$pad}_contact_mail_img"]->src,
        '{contact-mail-img-alt}'   => $GLOBALS["artForm01_{$pad}_contact_mail_img"]->alt,
        '{contact-mail-img-title}' => $GLOBALS["artForm01_{$pad}_contact_mail_img"]->title,
        '{contact-mail-dl}'        => "artForm01_{$pad}_contact_mail",
        '{contact-mail-text}'      => $GLOBALS["artForm01_{$pad}_contact_mail"]->text,
        '{contact-addr-href}'      => $GLOBALS["artForm01_{$pad}_contact_addr_href"],
        '{contact-addr-title}'     => $GLOBALS["artForm01_{$pad}_contact_addr_title"],
        '{contact-addr-img-src}'   => $_ENV['RAIZ'].'/'.$GLOBALS["artForm01_{$pad}_contact_addr_img"]->src,
        '{contact-addr-img-alt}'   => $GLOBALS["artForm01_{$pad}_contact_addr_img"]->alt,
        '{contact-addr-img-title}' => $GLOBALS["artForm01_{$pad}_contact_addr_img"]->title,
        '{contact-addr-dl}'        => "artForm01_{$pad}_contact_addr",
        '{contact-addr-text}'      => $GLOBALS["artForm01_{$pad}_contact_addr"]->text,
        '{contact-web-href}'       => $GLOBALS["artForm01_{$pad}_contact_web_href"],
        '{contact-web-title}'      => $GLOBALS["artForm01_{$pad}_contact_web_title"],
        '{contact-web-img-src}'    => $_ENV['RAIZ'].'/'.$GLOBALS["artForm01_{$pad}_contact_web_img"]->src,
        '{contact-web-img-alt}'    => $GLOBALS["artForm01_{$pad}_contact_web_img"]->alt,
        '{contact-web-img-title}'  => $GLOBALS["artForm01_{$pad}_contact_web_img"]->title,
        '{contact-web-dl}'         => "artForm01_{$pad}_contact_web",
        '{contact-web-text}'       => $GLOBALS["artForm01_{$pad}_contact_web"]->text,
        '{map-src}'                => $GLOBALS["artForm01_{$pad}_map_src"],
        '{map-width}'              => $GLOBALS["artForm01_{$pad}_map_width"],
        '{map-height}'             => $GLOBALS["artForm01_{$pad}_map_height"],
        '{map-title}'              => $GLOBALS["artForm01_{$pad}_map_title"],
        '{loader-slot}'            => $loader,
        '{sent-img-dl}'            => "artForm01_{$pad}_sent_img",
        '{sent-img-src}'           => $_ENV['RAIZ'].'/'.$GLOBALS["artForm01_{$pad}_sent_img"]->src,
        '{sent-img-alt}'           => $GLOBALS["artForm01_{$pad}_sent_img"]->alt,
        '{sent-img-title}'         => $GLOBALS["artForm01_{$pad}_sent_img"]->title,
        '{send-header-secondary}'  => '<h' . $itemLevel . ' data-lang="artForm01_' . $pad . '_headerSecondary_sent">' . $GLOBALS["artForm01_{$pad}_headerSecondary_sent"]->text . '</h' . $itemLevel . '>',
        '{sent-p1-dl}'             => "artForm01_{$pad}_sent_p1",
        '{sent-p1-text}'           => $GLOBALS["artForm01_{$pad}_sent_p1"]->text,
        '{sent-p2-dl}'             => "artForm01_{$pad}_sent_p2",
        '{sent-p2-text}'           => $GLOBALS["artForm01_{$pad}_sent_p2"]->text,
        '{new-query-dl}'           => "artForm01_{$pad}_new_query",
        '{new-query-text}'         => $GLOBALS["artForm01_{$pad}_new_query"]->text,
    ];
    $vars = array_replace($vars, $params);
    return render('App/templates/_artForm01.html', $vars);
}
?>
