<?php
/**
 * Directrices de copy para artScale01:
 * - Lineas principales: 1-3 palabras por linea, tono aspiracional.
 * - Mantén el ritmo corto para lectura en mayusculas.
 * - Titulo del video: 2-4 palabras descriptivas.
 */
function controller_artScale01(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);

    $vars = [
        '{classVar}'     => "artScale01_{$pad}_classVar",
        '{line1a-dl}'    => "artScale01_{$pad}_line1a",
        '{line1a-text}'  => $GLOBALS["artScale01_{$pad}_line1a"]->text ?? '',
        '{line1b-dl}'    => "artScale01_{$pad}_line1b",
        '{line1b-text}'  => $GLOBALS["artScale01_{$pad}_line1b"]->text ?? '',
        '{line2a-dl}'    => "artScale01_{$pad}_line2a",
        '{line2a-text}'  => $GLOBALS["artScale01_{$pad}_line2a"]->text ?? '',
        '{line2b-dl}'    => "artScale01_{$pad}_line2b",
        '{line2b-text}'  => $GLOBALS["artScale01_{$pad}_line2b"]->text ?? '',
        '{line3-dl}'     => "artScale01_{$pad}_line3",
        '{line3-text}'   => $GLOBALS["artScale01_{$pad}_line3"]->text ?? '',
        '{video-webm-dl}' => "artScale01_{$pad}_video_webm",
        '{video-webm}'    => $_ENV['RAIZ'] . '/' . ($GLOBALS["artScale01_{$pad}_video_webm"]->src ?? ''),
        '{video-mp4-dl}'  => "artScale01_{$pad}_video_mp4",
        '{video-mp4}'     => $_ENV['RAIZ'] . '/' . ($GLOBALS["artScale01_{$pad}_video_mp4"]->src ?? ''),
        '{video-title-dl}' => "artScale01_{$pad}_video_title",
        '{video-title}'    => $GLOBALS["artScale01_{$pad}_video_title"]->title ?? '',
        '{button-primary}' => '',
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_artScale01.html', $vars);
}
?>
