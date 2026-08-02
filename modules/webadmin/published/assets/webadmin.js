(function () {
    'use strict';

    function bindPasswordToggle(button) {
        if (!(button instanceof HTMLButtonElement) || button.dataset.bound === 'true') {
            return;
        }

        var field = button.closest('.moduleFormAuth-passwordControl');
        var input = field
            ? field.querySelector('[data-auth-password-input]')
            : null;
        if (!(input instanceof HTMLInputElement) || input.type !== 'password') {
            return;
        }

        button.dataset.bound = 'true';
        button.addEventListener('click', function () {
            var reveal = input.type === 'password';
            input.type = reveal ? 'text' : 'password';
            button.setAttribute('aria-pressed', reveal ? 'true' : 'false');
            var label = reveal
                ? (button.dataset.authLabelHide || 'Ocultar')
                : (button.dataset.authLabelShow || 'Mostrar');
            var toggleText = button.querySelector(
                '[data-auth-password-toggle-text]'
            );
            button.setAttribute('aria-label', label);
            if (toggleText) {
                toggleText.textContent = label;
            }
        });
    }

    function init() {
        document.querySelectorAll('[data-auth-password-toggle]')
            .forEach(bindPasswordToggle);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}());
