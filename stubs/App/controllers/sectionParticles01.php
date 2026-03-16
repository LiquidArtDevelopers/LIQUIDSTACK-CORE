<?php
/**
 * Directrices de copy para sectionParticles01:
 * - Contenido opcional: titulo 3-6 palabras, texto 12-20 palabras.
 * - CTA (si aplica): 2-4 palabras.
 */
function controller_sectionParticles01(int $i = 0, array $params = []): string
{
    $pad = sprintf('%02d', $i);

    $buildDataAttrs = static function (array $item): string {
        $attrs = [];

        if (isset($item['data']) && is_array($item['data'])) {
            foreach ($item['data'] as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $rawKey = (string) $key;
                $attrKey = strpos($rawKey, 'data-') === 0
                    ? $rawKey
                    : 'data-particles-' . str_replace('_', '-', $rawKey);
                $attrs[] = $attrKey . '="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"';
            }
        }

        foreach ($item as $key => $value) {
            if ($key === 'content' || $key === 'data') {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $rawKey = (string) $key;
            $attrKey = strpos($rawKey, 'data-') === 0
                ? $rawKey
                : 'data-particles-' . str_replace('_', '-', $rawKey);
            $attrs[] = $attrKey . '="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"';
        }

        return implode(' ', $attrs);
    };

    $vars = [
        '{classVar}'         => "sectionParticles01_{$pad}_classVar",
        '{content}'          => '',
        '{particles-count}'       => '20000',
        '{particles-bg-count}'    => '8000',
        '{particles-size}'        => '1.5',
        '{particles-radius}'      => '28',
        '{particles-depth}'       => '20',
        '{particles-speed}'       => '0.75',
        '{particles-shape-ratio}' => '0.88',
        '{particles-shape-scale}' => '0.8',
        '{particles-step-vh}'      => '100',
    ];

    $items = $params['items'] ?? null;
    if (is_array($items)) {
        $itemsHtml = '';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $content = isset($item['content']) ? (string) $item['content'] : '';
            $attrs = $buildDataAttrs($item);
            $itemsHtml .= '<article data-particles-step' . ($attrs ? ' ' . $attrs : '') . '>'
                . $content
                . '</article>';
        }
        $vars['{content}'] = $itemsHtml;
        unset($params['items']);
    } elseif (isset($params['content'])) {
        $vars['{content}'] = (string) $params['content'];
        unset($params['content']);
    } elseif (isset($params['{content}'])) {
        $vars['{content}'] = (string) $params['{content}'];
        unset($params['{content}']);
    }

    $vars = array_replace($vars, $params);

    return render('App/templates/_sectionParticles01.html', $vars);
}
?>
