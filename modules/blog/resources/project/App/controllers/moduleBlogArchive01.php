<?php

declare(strict_types=1);

require_once __DIR__ . '/_moduleBlogResources.php';

/**
 * moduleBlogArchive01: navegación por periodos de archivo ya proyectados.
 * Encabezado: 2-5 palabras. Etiqueta de periodo: 1-4 palabras.
 * Cada periodo admite `active: true`; solo el primero se expone como actual.
 * Recibe solo arrays de presentación; sin periodos válidos no renderiza nada.
 */
function controller_moduleBlogArchive01(
    int $i = 0,
    array $params = []
): string {
    $context = liquidstack_blog_resource_context(
        'moduleBlogArchive01',
        $i,
        $params,
        2,
        0
    );
    $rawPeriods = is_array($params['periods_data'] ?? null)
        ? array_values($params['periods_data'])
        : [];
    $limit = array_key_exists('items', $params)
        ? max(0, min(120, (int) $params['items']))
        : min(120, count($rawPeriods));
    $singular = trim((string) ($params['count_label_singular'] ?? 'entry'));
    $plural = trim((string) ($params['count_label_plural'] ?? 'entries'));
    if ($singular === '') {
        $singular = 'entry';
    }
    if ($plural === '') {
        $plural = 'entries';
    }

    $periods = [];
    $activeAssigned = false;
    foreach (array_slice($rawPeriods, 0, $limit) as $rawPeriod) {
        $value = static function (string $field) use ($rawPeriod) {
            if (is_array($rawPeriod)) {
                return $rawPeriod[$field] ?? null;
            }
            if (is_object($rawPeriod) && isset($rawPeriod->{$field})) {
                return $rawPeriod->{$field};
            }

            return null;
        };
        $url = trim((string) $value('url'));
        $label = trim((string) $value('label'));
        $count = (int) $value('count');
        $urlIsValid = !str_contains($url, '\\') && (
            (
                str_starts_with($url, '/')
                && !str_starts_with($url, '//')
            ) || (
                filter_var($url, FILTER_VALIDATE_URL) !== false
                && in_array(
                    strtolower((string) parse_url($url, PHP_URL_SCHEME)),
                    ['http', 'https'],
                    true
                )
            )
        );
        if (!$urlIsValid || $label === '' || $count < 1) {
            continue;
        }

        $active = !$activeAssigned && $value('active') === true;
        $activeAssigned = $activeAssigned || $active;
        $periods[] = [
            'url' => $url,
            'label' => $label,
            'count' => $count,
            'count_label' => $count === 1 ? $singular : $plural,
            'active' => $active,
        ];
    }
    if ($periods === []) {
        return '';
    }

    $items = '';
    foreach ($periods as $period) {
        $countText = $period['count'] . ' ' . $period['count_label'];
        $activeClass = $period['active']
            ? ' moduleBlogArchive01-item--active'
            : '';
        $ariaCurrent = $period['active'] ? ' aria-current="date"' : '';
        $items .= '<li class="moduleBlogArchive01-item' . $activeClass
            . '"><a href="'
            . liquidstack_blog_resource_escape($period['url']) . '"'
            . $ariaCurrent . '><span>'
            . liquidstack_blog_resource_escape($period['label'])
            . '</span><span class="moduleBlogArchive01-count" aria-label="'
            . liquidstack_blog_resource_escape($countText) . '">'
            . $period['count'] . '</span></a></li>';
    }

    return render('App/templates/_moduleBlogArchive01.html', [
        '{nav-id}' => liquidstack_blog_resource_escape($context['id']),
        '{heading-id}' => liquidstack_blog_resource_escape(
            $context['heading_id']
        ),
        '{classVar}' => liquidstack_blog_resource_escape(
            $context['class_var']
        ),
        '{items-count}' => (string) count($periods),
        '{header-primary}' => liquidstack_blog_resource_heading($context),
        '{items}' => $items,
    ]);
}
