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

    function auth02LengthOf(value) {
        return Array.from(value).length;
    }

    function auth02PasswordRules(password, confirmation) {
        return {
            length: auth02LengthOf(password) >= 8,
            lowercase: /\p{Ll}/u.test(password),
            uppercase: /\p{Lu}/u.test(password),
            number: /\p{N}/u.test(password),
            symbol: /[\p{P}\p{S}]/u.test(password),
            match: confirmation.length > 0 && password === confirmation
        };
    }

    function auth02Summary(template, completed, total) {
        return template
            .replace('%complete%', String(completed))
            .replace('%total%', String(total));
    }

    function resetAuth02PasswordToggles(root) {
        root.querySelectorAll('[data-auth02-password-toggle]')
            .forEach(function (button) {
                var field = button.closest('.moduleFormAuth02-passwordControl');
                var input = field
                    ? field.querySelector('[data-auth02-password-input]')
                    : null;
                var toggleText = button.querySelector(
                    '[data-auth02-password-toggle-text]'
                );
                var label = button.dataset.authLabelShow;

                if (!(input instanceof HTMLInputElement)) {
                    return;
                }

                input.type = 'password';
                button.setAttribute('aria-pressed', 'false');
                if (label) {
                    button.setAttribute('aria-label', label);
                    if (toggleText) {
                        toggleText.textContent = label;
                    }
                }
            });
    }

    function bindAuth02PasswordToggle(button) {
        if (
            !(button instanceof HTMLButtonElement)
            || button.dataset.auth02Bound === 'true'
        ) {
            return;
        }

        var field = button.closest('.moduleFormAuth02-passwordControl');
        var input = field
            ? field.querySelector('[data-auth02-password-input]')
            : null;
        if (!(input instanceof HTMLInputElement)) {
            return;
        }

        button.dataset.auth02Bound = 'true';
        button.addEventListener('click', function () {
            var reveal = input.type === 'password';
            var label = reveal
                ? button.dataset.authLabelHide
                : button.dataset.authLabelShow;
            var toggleText = button.querySelector(
                '[data-auth02-password-toggle-text]'
            );

            input.type = reveal ? 'text' : 'password';
            button.setAttribute('aria-pressed', reveal ? 'true' : 'false');
            if (label) {
                button.setAttribute('aria-label', label);
                if (toggleText) {
                    toggleText.textContent = label;
                }
            }
        });
    }

    function bindAuth02PasswordPolicy(root) {
        if (
            !(root instanceof HTMLElement)
            || root.dataset.auth02PolicyBound === 'true'
        ) {
            return;
        }

        var form = root.querySelector('form');
        var password = root.querySelector('[data-auth02-new-password]');
        var confirmation = root.querySelector(
            '[data-auth02-password-confirmation]'
        );
        var submit = root.querySelector('[type="submit"]');
        var summary = root.querySelector(
            '[data-auth02-requirements-summary]'
        );

        if (
            !(form instanceof HTMLFormElement)
            || !(password instanceof HTMLInputElement)
            || !(confirmation instanceof HTMLInputElement)
            || !(submit instanceof HTMLButtonElement)
        ) {
            return;
        }

        root.dataset.auth02PolicyBound = 'true';
        var passwordTouched = false;
        var confirmationTouched = false;

        function update() {
            var rules = auth02PasswordRules(
                password.value,
                confirmation.value
            );
            var names = Object.keys(rules);
            var completed = names.filter(function (name) {
                return rules[name];
            }).length;
            var allMet = completed === names.length;
            var passwordMet = names
                .filter(function (name) { return name !== 'match'; })
                .every(function (name) { return rules[name]; });

            names.forEach(function (name) {
                var item = root.querySelector(
                    '[data-auth02-rule="' + name + '"]'
                );
                if (item) {
                    item.dataset.state = rules[name] ? 'met' : 'pending';
                }
            });

            password.setAttribute(
                'aria-invalid',
                passwordTouched && !passwordMet ? 'true' : 'false'
            );
            confirmation.setAttribute(
                'aria-invalid',
                confirmationTouched && !rules.match ? 'true' : 'false'
            );
            submit.disabled = !allMet;

            if (summary) {
                var template = allMet
                    ? summary.dataset.authSummaryComplete
                    : summary.dataset.authSummaryProgress;
                summary.textContent = auth02Summary(
                    template || '%complete%/%total%',
                    completed,
                    names.length
                );
            }

            return allMet;
        }

        password.addEventListener('input', function () {
            passwordTouched = true;
            update();
        });
        confirmation.addEventListener('input', function () {
            confirmationTouched = true;
            update();
        });
        form.addEventListener('submit', function (event) {
            passwordTouched = true;
            confirmationTouched = true;
            if (!update()) {
                event.preventDefault();
                (
                    password.getAttribute('aria-invalid') === 'true'
                        ? password
                        : confirmation
                ).focus();
            }
        });
        form.addEventListener('reset', function () {
            window.setTimeout(function () {
                passwordTouched = false;
                confirmationTouched = false;
                resetAuth02PasswordToggles(root);
                update();
            }, 0);
        });

        update();
    }

    function init() {
        document.querySelectorAll('[data-auth-password-toggle]')
            .forEach(bindPasswordToggle);
        document.querySelectorAll('[data-auth02-password-toggle]')
            .forEach(bindAuth02PasswordToggle);
        document.querySelectorAll('[data-auth02-password-policy]')
            .forEach(bindAuth02PasswordPolicy);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}());
