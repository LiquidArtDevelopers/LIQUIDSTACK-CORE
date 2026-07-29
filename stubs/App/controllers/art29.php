<?php
/**
 * Directrices de copy para art29:
 * - Encabezado principal: 45-75 caracteres con una idea editorial concreta.
 * - Párrafos: 28-55 palabras cada uno, con un máximo recomendado de dos.
 * - CTA opcional: 2-5 palabras; title de 4-8 palabras orientado a la acción.
 * - Alt/title de imagen: 5-10 palabras que describan la escena y su contexto.
 */
function controller_art29(int $i = 0, array $params = []): string
{
    global $lang;

    $pad = sprintf('%02d', $i);

    $currentLang = (string) ($lang ?? $GLOBALS['lang'] ?? $_ENV['LANG_DEFAULT'] ?? 'es');
    $currentLang = preg_match('/^[A-Za-z0-9_-]+$/', $currentLang) === 1
        ? $currentLang
        : 'es';

    $templateFile = __DIR__ . '/../config/languages/templates/' . $currentLang . '.json';
    $templateJson = is_readable($templateFile) ? file_get_contents($templateFile) : '{}';
    $templateLang = json_decode($templateJson);
    $templateLang = is_object($templateLang) ? $templateLang : new stdClass();

    $readObject = static function (string $key, array $fallback) use ($templateLang): object {
        $templateKey = preg_replace('/^art29_\d{2}_/', 'art29_00_', $key);
        $value = $GLOBALS[$key]
            ?? $templateLang->{$key}
            ?? ($templateKey !== null ? ($templateLang->{$templateKey} ?? null) : null);

        if (is_object($value)) {
            return (object) array_replace($fallback, get_object_vars($value));
        }

        if (is_array($value)) {
            return (object) array_replace($fallback, $value);
        }

        return (object) $fallback;
    };

    $escapeAttr = static fn ($value): string => htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $rootUrl = rtrim((string) ($_ENV['RAIZ'] ?? ''), '/');
    $assetUrl = static function (string $src) use ($rootUrl): string {
        $src = trim($src);
        if ($src === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#)~i', $src) === 1) {
            return $src;
        }

        return ($rootUrl !== '' ? $rootUrl . '/' : '/') . ltrim($src, '/');
    };

    $headerLevels = resolve_header_levels($params, '{header-primary}', 3);
    $baseLevel    = $headerLevels['base'];

    $headerKey = "art29_{$pad}_headerPrimary";
    $headerObj = $readObject($headerKey, [
        'text' => 'Una mirada editorial que conecta ideas y contexto',
    ]);

    $fallbackParagraphs = [
        'a' => 'Matrix ipsum dolor sit amet, consectetur adipisicing elit. Neo eligendi veritatis codicem et simulacrum, mientras Morpheus abre una nueva perspectiva sobre la realidad y sus posibilidades.',
        'b' => 'Trinity recorre las líneas del sistema y convierte una visión compleja en un relato claro, directo y fácil de recordar.',
    ];

    $copyHtml = '';
    foreach ($fallbackParagraphs as $letter => $fallbackText) {
        $paragraphKey = "art29_{$pad}_p_{$letter}";
        $paragraphObj = $readObject($paragraphKey, ['text' => $fallbackText]);
        $paragraphText = (string) ($paragraphObj->text ?? '');

        if (trim($paragraphText) === '') {
            continue;
        }

        $copyHtml .= '<p data-lang="' . $escapeAttr($paragraphKey) . '">'
            . $paragraphText
            . '</p>';
    }

    $imageKey = "art29_{$pad}_img";
    $imageObj = $readObject($imageKey, [
        'src'   => 'assets/img/dummy/dummy01.avif',
        'alt'   => 'Escena editorial de referencia para el contenido',
        'title' => 'Imagen editorial de referencia',
    ]);
    $imageSrc = $assetUrl((string) ($imageObj->src ?? ''));

    $imageHtml = $imageSrc === ''
        ? ''
        : '<div class="art29-media">'
            . '<img data-lang="' . $escapeAttr($imageKey)
            . '" src="' . $escapeAttr($imageSrc)
            . '" alt="' . $escapeAttr($imageObj->alt ?? '')
            . '" title="' . $escapeAttr($imageObj->title ?? '')
            . '" width="1200" height="800" loading="lazy" decoding="async">'
            . '</div>';

    $buttonKey = '{button-primary}';
    if (array_key_exists($buttonKey, $params)) {
        $buttonHtml = (string) $params[$buttonKey];
        unset($params[$buttonKey]);
    } else {
        $ctaKey = "art29_{$pad}_cta";
        $ctaObj = $readObject($ctaKey, [
            'text'  => '',
            'href'  => '',
            'title' => '',
        ]);
        $ctaText = trim((string) ($ctaObj->text ?? ''));
        $ctaHref = trim((string) ($ctaObj->href ?? ''));

        $buttonHtml = ($ctaText !== '' && $ctaHref !== '')
            ? '<a class="art29-cta boton" data-lang="' . $escapeAttr($ctaKey)
                . '" href="' . $escapeAttr(resolve_localized_href($ctaHref, ['lang' => $currentLang]))
                . '" title="' . $escapeAttr($ctaObj->title ?? '')
                . '">' . $ctaText . '</a>'
            : '';
    }

    $vars = [
        '{classVar}'       => "art29_{$pad}_classVar",
        '{header-primary}' => '<h' . $baseLevel . ' data-lang="' . $escapeAttr($headerKey)
            . '">' . ($headerObj->text ?? '') . '</h' . $baseLevel . '>',
        '{copy}'           => $copyHtml,
        '{image}'          => $imageHtml,
        '{button-primary}' => $buttonHtml,
    ];

    return render(
        'App/templates/_art29.html',
        array_replace($vars, $params)
    );
}
?>
