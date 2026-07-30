<?php

if (!function_exists('render_module_form_contact')) {
    /**
     * Renderiza el contrato compartido de la familia moduleFormContact.
     *
     * Los módulos conservan el payload del endpoint `/form`, pero generan IDs
     * únicos y hooks data-* para admitir varias instancias en una misma vista.
     */
    function render_module_form_contact(
        string $resource,
        int $i,
        array $params,
        string $template
    ): string {
        if (
            preg_match('/^moduleFormContact0[1-3]$/', $resource) !== 1
            || preg_match('/^_moduleFormContact0[1-3]\.html$/', $template) !== 1
        ) {
            return '';
        }

        $pad = sprintf('%02d', max(0, $i));

        $endpoint = trim((string) ($params['endpoint'] ?? '/form'));
        unset($params['endpoint']);
        if (preg_match('#^/(?!/)[A-Za-z0-9/_-]+$#', $endpoint) !== 1) {
            $endpoint = '/form';
        }

        $currentLang = strtolower(
            (string) ($GLOBALS['lang'] ?? $_ENV['LANG_DEFAULT'] ?? 'es')
        );
        if (preg_match('/^[a-z]{2}$/', $currentLang) !== 1) {
            $currentLang = 'es';
        }

        $readEntry = static function (string $key) {
            return $GLOBALS[$key] ?? null;
        };

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

        $escapeAttribute = static fn (string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $key = static fn (string $suffix): string => $resource
            . '_'
            . $pad
            . '_'
            . $suffix;

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

        $privacyKey   = $key('privacy');
        $privacyEntry = $readEntry($privacyKey);
        $privacyHref  = resolve_localized_href(
            $readField($privacyEntry, 'href')
        );

        $domId = $resource . '-' . $pad;

        $formVars = [
            '{resource-class}'       => $resource,
            '{form-id}'              => $escapeAttribute($domId . '-form'),
            '{form-action}'          => $escapeAttribute($endpoint),
            '{form-lang}'            => $escapeAttribute($currentLang),
            '{legend-id}'            => $escapeAttribute($domId . '-legend'),
            '{legend-dl}'            => $key('legend'),
            '{legend-text}'          => $text('legend'),
            '{intro-dl}'             => $key('intro'),
            '{intro-text}'           => $text('intro'),
            '{name-id}'              => $escapeAttribute($domId . '-name'),
            '{name-error-id}'        => $escapeAttribute($domId . '-name-error'),
            '{label-name-dl}'        => $key('label_name'),
            '{label-name-text}'      => $text('label_name'),
            '{ph-name-dl}'           => $key('ph_name'),
            '{ph-name-text}'         => $escapeAttribute($placeholder('ph_name')),
            '{phone-id}'             => $escapeAttribute($domId . '-phone'),
            '{phone-error-id}'       => $escapeAttribute($domId . '-phone-error'),
            '{label-phone-dl}'       => $key('label_phone'),
            '{label-phone-text}'     => $text('label_phone'),
            '{ph-phone-dl}'          => $key('ph_phone'),
            '{ph-phone-text}'        => $escapeAttribute($placeholder('ph_phone')),
            '{mail-id}'              => $escapeAttribute($domId . '-mail'),
            '{mail-error-id}'        => $escapeAttribute($domId . '-mail-error'),
            '{label-mail-dl}'        => $key('label_mail'),
            '{label-mail-text}'      => $text('label_mail'),
            '{ph-mail-dl}'           => $key('ph_mail'),
            '{ph-mail-text}'         => $escapeAttribute($placeholder('ph_mail')),
            '{message-id}'           => $escapeAttribute($domId . '-message'),
            '{message-error-id}'     => $escapeAttribute($domId . '-message-error'),
            '{label-message-dl}'     => $key('label_message'),
            '{label-message-text}'   => $text('label_message'),
            '{ph-message-dl}'        => $key('ph_message'),
            '{ph-message-text}'      => $escapeAttribute($placeholder('ph_message')),
            '{terms-id}'             => $escapeAttribute($domId . '-terms'),
            '{terms-error-id}'       => $escapeAttribute($domId . '-terms-error'),
            '{terms-dl}'             => $key('terms'),
            '{terms-text}'           => $text('terms'),
            '{terms-error-text}'     => $escapeAttribute($text('terms_error')),
            '{privacy-dl}'           => $privacyKey,
            '{privacy-text}'         => $readField($privacyEntry, 'text'),
            '{privacy-href}'         => $escapeAttribute($privacyHref),
            '{privacy-title}'        => $escapeAttribute(
                $readField($privacyEntry, 'title')
            ),
            '{captcha-id}'           => $escapeAttribute($domId . '-captcha'),
            '{captcha-error-id}'     => $escapeAttribute($domId . '-captcha-error'),
            '{label-captcha-dl}'     => $key('label_captcha'),
            '{label-captcha-text}'   => $text('label_captcha'),
            '{ph-captcha-dl}'        => $key('ph_captcha'),
            '{ph-captcha-text}'      => $escapeAttribute(
                $placeholder('ph_captcha')
            ),
            '{submit-dl}'            => $key('submit'),
            '{submit-text}'          => $text('submit'),
            '{sending-dl}'           => $key('sending'),
            '{sending-text}'         => $text('sending'),
            '{success-title-dl}'     => $key('success_title'),
            '{success-title-text}'   => $text('success_title'),
            '{success-text-dl}'      => $key('success_text'),
            '{success-text}'         => $text('success_text'),
            '{new-query-dl}'         => $key('new_query'),
            '{new-query-text}'       => $text('new_query'),
            '{network-error-text}'   => $escapeAttribute(
                $text('network_error')
            ),
            '{server-error-text}'    => $escapeAttribute(
                $text('server_error')
            ),
        ];

        $form = render(
            'App/templates/_moduleFormContact.html',
            array_replace($formVars, $params)
        );

        $vars = [
            '{classVar}' => $resource . '_' . $pad . '_classVar',
            '{form}'     => $form,
        ];

        return render(
            'App/templates/' . $template,
            array_replace($vars, $params)
        );
    }
}
