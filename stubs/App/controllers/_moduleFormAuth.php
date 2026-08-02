<?php

if (!function_exists('render_module_form_auth')) {
    /**
     * Renderiza la familia visual moduleFormAuth sin asumir rutas ni backend.
     *
     * `hidden-fields`, `feedback-slot` y `secondary-action-slot` aceptan HTML
     * de confianza compuesto por el consumidor. El resto de valores usados en
     * atributos se normalizan y escapan aquí.
     */
    function render_module_form_auth(
        string $resource,
        int $i,
        array $params,
        string $template
    ): string {
        $resources = [
            'moduleFormAuthLogin01' => '_moduleFormAuthLogin01.html',
            'moduleFormAuthRecover01' => '_moduleFormAuthRecover01.html',
            'moduleFormAuthPassword01' => '_moduleFormAuthPassword01.html',
        ];

        if (($resources[$resource] ?? null) !== $template) {
            return '';
        }

        $pad = sprintf('%02d', max(0, $i));
        $escapeAttribute = static fn (string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $escapeText = static fn (string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $readEntry = static fn (string $key) => $GLOBALS[$key] ?? null;
        $readField = static function ($entry, string $field): string {
            if (is_object($entry) && isset($entry->{$field})) {
                return (string) $entry->{$field};
            }

            if (is_array($entry) && isset($entry[$field])) {
                return (string) $entry[$field];
            }

            if ($field === 'text' && is_scalar($entry)) {
                return (string) $entry;
            }

            return '';
        };

        $idPrefix = trim((string) ($params['id_prefix'] ?? "{$resource}-{$pad}"));
        unset($params['id_prefix']);
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $idPrefix) !== 1) {
            $idPrefix = "{$resource}-{$pad}";
        }

        $action = trim((string) ($params['action'] ?? ''));
        unset($params['action']);

        $method = strtolower(trim((string) ($params['method'] ?? 'post')));
        unset($params['method']);
        if (!in_array($method, ['get', 'post'], true)) {
            $method = 'post';
        }

        $languageAttributes = !array_key_exists('language_attributes', $params)
            || (bool) $params['language_attributes'];
        unset($params['language_attributes']);

        $key = static fn (string $suffix): string => "{$resource}_{$pad}_{$suffix}";
        $text = static function (string $suffix) use (
            $key,
            $readEntry,
            $readField
        ): string {
            return $readField($readEntry($key($suffix)), 'text');
        };
        $placeholder = static function (string $suffix) use (
            $key,
            $readEntry,
            $readField
        ): string {
            return $readField($readEntry($key($suffix)), 'placeholder');
        };
        $langAttr = static function (string $languageKey) use (
            $escapeAttribute,
            $languageAttributes
        ): string {
            return $languageAttributes
                ? ' data-lang="' . $escapeAttribute($languageKey) . '"'
                : '';
        };

        $secondaryKey = $key('secondaryAction');
        $secondaryEntry = $readEntry($secondaryKey);
        $secondaryText = $readField($secondaryEntry, 'text');
        $secondaryAction = '';
        if ($secondaryText !== '') {
            $secondaryAction = '<a'
                . $langAttr($secondaryKey)
                . ' href="' . $escapeAttribute(
                    $readField($secondaryEntry, 'href')
                ) . '" title="' . $escapeAttribute(
                    $readField($secondaryEntry, 'title')
                ) . '">' . $escapeText($secondaryText) . '</a>';
        }

        $feedbackKey = $key('feedback');
        $defaultFeedback = '<p hidden'
            . $langAttr($feedbackKey)
            . '>' . $escapeText($text('feedback')) . '</p>';

        $id = static fn (string $suffix): string => $escapeAttribute(
            $idPrefix . '-' . $suffix
        );

        $vars = [
            '{root-id}'                       => $id('root'),
            '{form-id}'                       => $id('form'),
            '{legend-id}'                     => $id('legend'),
            '{classVar}'                      => "{$resource}_{$pad}_classVar",
            '{form-action}'                   => $escapeAttribute($action),
            '{form-method}'                   => $escapeAttribute($method),
            '{hidden-fields}'                 => '',
            '{legend-lang-attr}'              => $langAttr($key('legend')),
            '{legend-text}'                   => $escapeText($text('legend')),
            '{intro-lang-attr}'               => $langAttr($key('intro')),
            '{intro-text}'                    => $escapeText($text('intro')),
            '{email-id}'                      => $id('email'),
            '{email-name}'                    => 'email',
            '{email-autocomplete}'            => 'email',
            '{email-hint-id}'                 => $id('email-hint'),
            '{email-error-id}'                => $id('email-error'),
            '{email-label-lang-attr}'         => $langAttr($key('emailLabel')),
            '{email-label-text}'              => $escapeText($text('emailLabel')),
            '{email-placeholder-lang-attr}'   => $langAttr($key('emailPlaceholder')),
            '{email-placeholder}'             => $escapeAttribute(
                $placeholder('emailPlaceholder')
            ),
            '{email-hint-lang-attr}'          => $langAttr($key('emailHint')),
            '{email-hint-text}'               => $escapeText($text('emailHint')),
            '{email-error-slot}'              => '',
            '{password-id}'                   => $id('password'),
            '{password-name}'                 => 'password',
            '{password-hint-id}'              => $id('password-hint'),
            '{password-error-id}'             => $id('password-error'),
            '{password-label-lang-attr}'      => $langAttr($key('passwordLabel')),
            '{password-label-text}'           => $escapeText($text('passwordLabel')),
            '{password-placeholder-lang-attr}'=> $langAttr($key('passwordPlaceholder')),
            '{password-placeholder}'          => $escapeAttribute(
                $placeholder('passwordPlaceholder')
            ),
            '{password-hint-lang-attr}'       => $langAttr($key('passwordHint')),
            '{password-hint-text}'            => $escapeText($text('passwordHint')),
            '{password-error-slot}'           => '',
            '{confirmation-id}'               => $id('confirmation'),
            '{confirmation-name}'             => 'password_confirmation',
            '{confirmation-hint-id}'          => $id('confirmation-hint'),
            '{confirmation-error-id}'         => $id('confirmation-error'),
            '{confirmation-label-lang-attr}'  => $langAttr($key('confirmationLabel')),
            '{confirmation-label-text}'       => $escapeText(
                $text('confirmationLabel')
            ),
            '{confirmation-placeholder-lang-attr}' => $langAttr(
                $key('confirmationPlaceholder')
            ),
            '{confirmation-placeholder}'      => $escapeAttribute(
                $placeholder('confirmationPlaceholder')
            ),
            '{confirmation-hint-lang-attr}'   => $langAttr(
                $key('confirmationHint')
            ),
            '{confirmation-hint-text}'        => $escapeText(
                $text('confirmationHint')
            ),
            '{confirmation-error-slot}'       => '',
            '{toggle-show-lang-attr}'         => $langAttr($key('toggleShow')),
            '{toggle-show-text}'              => $escapeAttribute($text('toggleShow')),
            '{toggle-hide-text}'              => $escapeAttribute($text('toggleHide')),
            '{requirements-label}'            => $escapeAttribute(
                $text('requirementsLabel')
            ),
            '{requirement-length-lang-attr}'  => $langAttr($key('requirementLength')),
            '{requirement-length-text}'       => $escapeText(
                $text('requirementLength')
            ),
            '{submit-lang-attr}'              => $langAttr($key('submit')),
            '{submit-text}'                   => $escapeText($text('submit')),
            '{feedback-slot}'                 => $defaultFeedback,
            '{secondary-action-slot}'         => $secondaryAction,
        ];

        return render(
            'App/templates/' . $template,
            array_replace($vars, $params)
        );
    }
}
