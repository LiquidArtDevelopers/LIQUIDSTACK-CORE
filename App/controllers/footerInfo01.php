<?php
function controller_footerInfo01(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $vars = [
        '{img-01-dl}'      => "footerInfo01_{$pad}_img1",
        '{img-01-src}'     => $_ENV['RAIZ'].'/'.$GLOBALS["footerInfo01_{$pad}_img1"]->src,
        '{img-01-alt}'     => $GLOBALS["footerInfo01_{$pad}_img1"]->alt,
        '{img-01-title}'   => $GLOBALS["footerInfo01_{$pad}_img1"]->title,

        '{cookie-policy}'      => "footerInfo01{$pad}_cookie-policy",
        '{cookie-policy-text}' => $GLOBALS["footerInfo01{$pad}_cookie-policy"]->text,

        '{terms-privacy}'      => "footerInfo01{$pad}_terms-privacy",
        '{terms-privacy-text}' => $GLOBALS["footerInfo01{$pad}_terms-privacy"]->text,

        '{legal-notice}'      => "footerInfo01{$pad}_legal-notice",
        '{legal-notice-text}' => $GLOBALS["footerInfo01{$pad}_legal-notice"]->text,

        '{year}' => "© ".date('Y'),

        '{rights-reserved}'      => "footerInfo01{$pad}_rights-reserved",
        '{rights-reserved-text}' => $GLOBALS["footerInfo01{$pad}_rights-reserved"]->text,

        '{lad-info-a}'       => "footerInfo01{$pad}_lad-info-a",
        '{lad-info-a-title}' => $GLOBALS["footerInfo01{$pad}_lad-info-a"]->title,
        '{lad-info-a-href}'  => $GLOBALS['footerInfo01_lad_info_href'],

        '{lad-info-p}'       => "footerInfo01{$pad}_lad-info-p",
        '{lad-info-p-text}'  => $GLOBALS["footerInfo01{$pad}_lad-info-p"]->text,
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_footerInfo01.html', $vars);
}
?>
