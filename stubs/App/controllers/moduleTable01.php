<?php
/**
 * Directrices de copy para moduleTable01:
 * - Caption: 4-10 palabras que identifiquen la información tabular.
 * - Encabezados de columna: 1-4 palabras.
 * - Celdas: 1-30 palabras; pueden incluir <code>, <strong> y enlaces puntuales.
 * - Filas: 1-26; columnas: 1-8.
 */
function controller_moduleTable01(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);
    $letters = range('a', 'z');
    $columnLetters = array_slice($letters, 0, 8);

    $rowsCount = max(1, min(26, (int) ($params['items'] ?? 3)));
    $columnsCount = max(
        1,
        min(8, (int) ($params['list_items'] ?? 3))
    );
    unset($params['items'], $params['list_items']);

    $readText = static function (string $key): string {
        $value = $GLOBALS[$key] ?? null;

        if (is_object($value) && isset($value->text)) {
            return (string) $value->text;
        }

        if (is_array($value) && isset($value['text'])) {
            return (string) $value['text'];
        }

        return '';
    };

    $escapeAttribute = static fn (string $value): string => htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    // Las claves se declaran de forma explícita para que la hidratación
    // conozca el máximo de columnas aunque una instancia use menos.
    $headerKeys = [
        "moduleTable01_{$pad}_header_01",
        "moduleTable01_{$pad}_header_02",
        "moduleTable01_{$pad}_header_03",
        "moduleTable01_{$pad}_header_04",
        "moduleTable01_{$pad}_header_05",
        "moduleTable01_{$pad}_header_06",
        "moduleTable01_{$pad}_header_07",
        "moduleTable01_{$pad}_header_08",
    ];
    $headersHtml = '';
    for ($columnIndex = 0; $columnIndex < $columnsCount; $columnIndex++) {
        $key = $headerKeys[$columnIndex];
        $headersHtml .= '<th scope="col" data-lang="'
            . $escapeAttribute($key) . '">' . $readText($key)
            . '</th>';
    }

    $rowsHtml = '';
    for ($rowIndex = 0; $rowIndex < $rowsCount; $rowIndex++) {
        $rowLetter = $letters[$rowIndex];
        $rowsHtml .= '<tr>';

        for (
            $columnIndex = 0;
            $columnIndex < $columnsCount;
            $columnIndex++
        ) {
            $columnLetter = $columnLetters[$columnIndex];
            $key = "moduleTable01_{$pad}_{$rowLetter}_list_{$columnLetter}";
            $tag = $columnIndex === 0 ? 'th' : 'td';
            $scope = $columnIndex === 0 ? ' scope="row"' : '';

            $rowsHtml .= '<' . $tag . $scope . ' data-lang="'
                . $escapeAttribute($key) . '">' . $readText($key)
                . '</' . $tag . '>';
        }

        $rowsHtml .= '</tr>';
    }

    $captionKey = "moduleTable01_{$pad}_caption";
    $captionId = 'moduleTable01-' . $pad . '-caption';
    $captionText = $readText($captionKey);

    $vars = [
        '{classVar}' => "moduleTable01_{$pad}_classVar",
        '{columns-class}' => 'moduleTable01--columns-' . $columnsCount,
        '{caption-id}' => $captionId,
        '{caption-dl}' => $captionKey,
        '{caption-text}' => $captionText,
        '{headers}' => $headersHtml,
        '{rows}' => $rowsHtml,
    ];

    return render(
        'App/templates/_moduleTable01.html',
        array_replace($vars, $params)
    );
}
?>
