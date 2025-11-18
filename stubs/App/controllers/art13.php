<?php
/**
 * Directrices de copy para art13:
 * - Encabezado principal: 7-10 palabras destacando la voz del cliente.
 * - Testimonio por ficha: 38-46 palabras relatando transformación, soporte y métricas compartidas.
 * - Nombre de la persona: 2-3 palabras con nombre y apellido.
 * - Etiqueta de valoración: 2-3 palabras o símbolos que expliquen la nota.
 * - Atributos alt/title del retrato: 4-6 palabras con contexto de la persona.
 * - Atributos alt/title de la imagen lateral: 5-7 palabras reflejando la escena conjunta.
 */
function controller_art13(int $i = 0, array $params = []): string
{
    $pad     = sprintf('%02d', $i);
    $letters = range('a', 'z');

    $itemsParam     = $params['items'] ?? null;
    $customByLetter = [];
    $itemsOverride  = null;
    $lettersToRender = [];
    $useDefault      = false;

    if (is_array($itemsParam)) {
        $letterIndex = 0;
        foreach ($itemsParam as $key => $value) {
            if (is_string($key) && strlen($key) === 1 && ctype_alpha($key)) {
                $letter = strtolower($key);
            } else {
                $letter = $letters[$letterIndex] ?? null;
                $letterIndex++;
            }

            if ($letter === null) {
                break;
            }

            $customByLetter[$letter] = (string) $value;
            $lettersToRender[]       = $letter;
        }
    } elseif (is_numeric($itemsParam)) {
        $itemsCount      = max(0, (int) $itemsParam);
        $lettersToRender = array_slice($letters, 0, $itemsCount);
    } elseif ($itemsParam !== null) {
        $itemsOverride = (string) $itemsParam;
    } else {
        $useDefault = true;
    }

    unset($params['items']);

    if ($useDefault && $itemsOverride === null) {
        $lettersToRender = [$letters[0]];
    }

    $lettersToRender = array_values(array_unique($lettersToRender));

    $itemsHtml = '';

    if ($itemsOverride !== null) {
        $itemsHtml = $itemsOverride;
    } else {
        foreach ($lettersToRender as $letter) {
            if (!in_array($letter, $letters, true)) {
                continue;
            }

            if (array_key_exists($letter, $customByLetter)) {
                $itemsHtml .= $customByLetter[$letter];
                continue;
            }

            $quoteKey       = "art13_{$pad}_{$letter}_quote";
            $authorImgKey   = "art13_{$pad}_{$letter}_author_img";
            $authorNameKey  = "art13_{$pad}_{$letter}_author_name";
            $authorStarsKey = "art13_{$pad}_{$letter}_author_stars";
            $sideImgKey     = "art13_{$pad}_{$letter}_side_img";

            $quoteObj       = $GLOBALS[$quoteKey] ?? null;
            $authorImgObj   = $GLOBALS[$authorImgKey] ?? null;
            $authorNameObj  = $GLOBALS[$authorNameKey] ?? null;
            $authorStarsObj = $GLOBALS[$authorStarsKey] ?? null;
            $sideImgObj     = $GLOBALS[$sideImgKey] ?? null;

            $quoteText       = (is_object($quoteObj) && isset($quoteObj->text)) ? $quoteObj->text : '';
            $authorNameText  = (is_object($authorNameObj) && isset($authorNameObj->text)) ? $authorNameObj->text : '';
            $authorStarsText = (is_object($authorStarsObj) && isset($authorStarsObj->text)) ? $authorStarsObj->text : '';

            $authorImgSrcValue = (is_object($authorImgObj) && isset($authorImgObj->src)) ? $authorImgObj->src : '';
            $authorImgSrc      = $authorImgSrcValue !== '' ? $_ENV['RAIZ'] . '/' . ltrim($authorImgSrcValue, '/') : '';
            $authorImgAlt      = (is_object($authorImgObj) && isset($authorImgObj->alt)) ? $authorImgObj->alt : '';
            $authorImgTitle    = (is_object($authorImgObj) && isset($authorImgObj->title)) ? $authorImgObj->title : '';

            $sideImgSrcValue = (is_object($sideImgObj) && isset($sideImgObj->src)) ? $sideImgObj->src : '';
            $sideImgSrc      = $sideImgSrcValue !== '' ? $_ENV['RAIZ'] . '/' . ltrim($sideImgSrcValue, '/') : '';
            $sideImgAlt      = (is_object($sideImgObj) && isset($sideImgObj->alt)) ? $sideImgObj->alt : '';
            $sideImgTitle    = (is_object($sideImgObj) && isset($sideImgObj->title)) ? $sideImgObj->title : '';

            $itemsHtml .= <<<HTML
    <div class="art13-body">
        <div class="art13-card">
            <span class="art13-quote" aria-hidden="true">❝</span>

            <p data-lang="{$quoteKey}" class="art13-text">{$quoteText}</p>

            <div class="art13-author">
                <img data-lang="{$authorImgKey}"
                        src="{$authorImgSrc}" alt="{$authorImgAlt}" title="{$authorImgTitle}"
                        width="48" height="48">

                <div>
                    <span data-lang="{$authorNameKey}" class="author-name">{$authorNameText}</span>
                    <span data-lang="{$authorStarsKey}" class="author-stars">{$authorStarsText}</span>
                </div>
            </div>
        </div>

        <div class="art13-img">
            <img data-lang="{$sideImgKey}" src="{$sideImgSrc}"
                    alt="{$sideImgAlt}" title="{$sideImgTitle}">
        </div>
    </div>

HTML;
        }
    }

    $headerPrimaryObj = $GLOBALS["art13_{$pad}_headerPrimary"] ?? null;
    $headerPrimary    = (is_object($headerPrimaryObj) && isset($headerPrimaryObj->text)) ? $headerPrimaryObj->text : '';

    $vars = [
        '{classVar}'       => "art13_{$pad}_classVar",
        '{header-primary}' => '<h3 data-lang="art13_' . $pad . '_headerPrimary">' . $headerPrimary . '</h3>',
        '{items}'          => $itemsHtml,
    ];

    $vars = array_replace($vars, $params);

    return render('App/templates/_art13.html', $vars);
}
?>
