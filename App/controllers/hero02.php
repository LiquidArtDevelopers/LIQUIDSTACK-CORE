<?php
/**
 * Directrices de copy para hero02:
 * - Contenido {hero02-content}: 30-55 palabras entre titular, subtítulo y CTA.
 * - Atributo title del vídeo: 3-6 palabras describiendo la pieza audiovisual.
 * Evita overlays densos para no ocultar la reproducción principal.
 */
function controller_hero02(int $i = 0, array $params = []): string
{
    $vars = [
        '{hero02-content}'  => '',
        '{video-webm-dl}'   => 'hero02_video_webm',
        '{video-webm}'      => $_ENV['RAIZ'].'/'.$GLOBALS['hero02_video_webm']->src,
        '{video-mp4-dl}'    => 'hero02_video_mp4',
        '{video-mp4}'       => $_ENV['RAIZ'].'/'.$GLOBALS['hero02_video_mp4']->src,
        // '{video-poster-dl}' => '',
        // '{video-poster}'    => '',
        '{video-title-dl}'  => 'hero02_video_title',
        '{video-title}'     => $GLOBALS['hero02_video_title']->title,
    ];
    $vars = array_replace($vars, $params);
    return render('App/templates/_hero02.html', $vars);
}
?>
