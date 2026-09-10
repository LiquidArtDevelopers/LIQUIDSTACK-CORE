<?php
function controller_footerInfo01(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $image = $GLOBALS["footerInfo01_{$pad}_img1"] ?? null;
    $imageSource = is_object($image)
        ? trim((string) ($image->src ?? ''))
        : trim((string) ($image['src'] ?? ''));
    $vars = [
        '{img-01-dl}'      => "footerInfo01_{$pad}_img1",
        '{img-01-src}'     => $imageSource === ''
            ? ''
            : rtrim((string) $_ENV['RAIZ'], '/').'/'.ltrim($imageSource, '/'),
        '{img-01-alt}'     => is_object($image)
            ? (string) ($image->alt ?? '')
            : (string) ($image['alt'] ?? ''),
        '{img-01-title}'   => is_object($image)
            ? (string) ($image->title ?? '')
            : (string) ($image['title'] ?? ''),

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
    $resolvedImageSource = trim((string) ($vars['{img-01-src}'] ?? ''));
    $vars['{img-01}'] = $resolvedImageSource === ''
        ? ''
        : '<img data-lang="'.$vars['{img-01-dl}'].'" src="'
            .$resolvedImageSource.'" alt="'.$vars['{img-01-alt}']
            .'" title="'.$vars['{img-01-title}'].'">';

    return render('App/templates/_footerInfo01.html', $vars);
}
?>
