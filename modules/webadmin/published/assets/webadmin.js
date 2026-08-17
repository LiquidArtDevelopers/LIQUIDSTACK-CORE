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

    function setWebAdminLoader(loader, active) {
        if (!(loader instanceof HTMLElement)) {
            return;
        }

        loader.hidden = !active;
        loader.setAttribute('aria-hidden', 'true');
    }

    function setMediaUploadSubmitting(form, submitting) {
        var submit = form.querySelector('[data-webadmin-media-submit]');
        var label = form.querySelector('[data-webadmin-media-submit-label]');
        var loader = form.querySelector('[data-webadmin-loader]');

        form.dataset.webadminMediaSubmitting = submitting ? 'true' : 'false';
        form.setAttribute('aria-busy', submitting ? 'true' : 'false');
        if (submit instanceof HTMLButtonElement) {
            submit.disabled = submitting;
            submit.setAttribute('aria-disabled', submitting ? 'true' : 'false');
            submit.setAttribute('aria-busy', submitting ? 'true' : 'false');
        }
        if (label instanceof HTMLElement) {
            if (!label.dataset.webadminMediaIdleLabel) {
                label.dataset.webadminMediaIdleLabel = label.textContent || '';
            }
            label.textContent = submitting
                ? 'Procesando y guardando…'
                : label.dataset.webadminMediaIdleLabel;
        }
        setWebAdminLoader(loader, submitting);
    }

    function bindMediaUploadForm(form) {
        if (
            !(form instanceof HTMLFormElement)
            || form.dataset.webadminMediaUploadBound === 'true'
        ) {
            return;
        }

        var input = form.querySelector('[data-webadmin-media-file]');
        var fileName = form.querySelector('[data-webadmin-media-file-name]');

        function updateFileName() {
            if (!(input instanceof HTMLInputElement)) {
                return;
            }
            var selected = input.files && input.files.length === 1
                ? input.files[0]
                : null;
            if (fileName instanceof HTMLElement) {
                fileName.textContent = selected
                    ? selected.name
                    : 'Ningún archivo seleccionado';
                fileName.dataset.webadminMediaFileSelected = selected
                    ? 'true'
                    : 'false';
                fileName.title = selected ? selected.name : '';
            }
            input.dataset.webadminMediaFileBound = 'true';
        }

        if (input instanceof HTMLInputElement) {
            input.addEventListener('change', updateFileName);
            updateFileName();
        }
        form.addEventListener('submit', function (event) {
            if (form.dataset.webadminMediaSubmitting === 'true') {
                event.preventDefault();
                return;
            }
            if (
                typeof form.checkValidity === 'function'
                && !form.checkValidity()
            ) {
                return;
            }
            setMediaUploadSubmitting(form, true);
        });
        form.dataset.webadminMediaUploadBound = 'true';
        setMediaUploadSubmitting(form, false);
    }

    function bindMediaDelete(root) {
        if (
            !(root instanceof HTMLElement)
            || root.dataset.webadminMediaDeleteBound === 'true'
        ) {
            return;
        }

        var dialog = root.querySelector('[data-webadmin-media-delete-dialog]');
        var cancel = root.querySelector('[data-webadmin-media-delete-cancel]');
        var confirm = root.querySelector('[data-webadmin-media-delete-confirm]');
        var label = root.querySelector('[data-webadmin-media-delete-label]');
        var status = root.querySelector('[data-webadmin-media-delete-status]');
        var dialogStatus = root.querySelector(
            '[data-webadmin-media-delete-dialog-status]'
        );
        var pendingForm = null;
        var submitting = false;

        if (
            !(dialog instanceof HTMLDialogElement)
            || !(cancel instanceof HTMLButtonElement)
            || !(confirm instanceof HTMLButtonElement)
            || typeof dialog.showModal !== 'function'
            || typeof window.fetch !== 'function'
            || typeof window.URL !== 'function'
            || typeof window.URLSearchParams !== 'function'
        ) {
            root.dataset.webadminMediaDeleteBound = 'fallback';
            return;
        }

        var deleteFieldContract = [
            ['csrf', 256],
            ['asset', 64],
            ['asset_version', 128],
            ['idempotency_key', 64],
            ['page', 6]
        ];

        function encodedDeletePayload(form) {
            var namedControls = form.querySelectorAll('[name]');
            if (namedControls.length !== deleteFieldContract.length) {
                return null;
            }

            var payload = new window.URLSearchParams();
            for (var index = 0; index < deleteFieldContract.length; index += 1) {
                var contract = deleteFieldContract[index];
                var control = form.elements.namedItem(contract[0]);
                if (
                    !(control instanceof HTMLInputElement)
                    || control.type !== 'hidden'
                    || control.value.length < 1
                    || control.value.length > contract[1]
                ) {
                    return null;
                }
                payload.append(contract[0], control.value);
            }

            return payload.toString();
        }

        function setFeedback(message, failed) {
            if (!(status instanceof HTMLElement)) {
                return;
            }
            status.textContent = message;
            status.dataset.webadminMediaDeleteFeedback = failed
                ? 'error'
                : 'success';
        }

        function setSubmitting(active) {
            submitting = active;
            dialog.setAttribute('aria-busy', active ? 'true' : 'false');
            cancel.disabled = active;
            confirm.disabled = active;
            confirm.textContent = active ? 'Retirando…' : 'Confirmar';
        }

        function setDialogFeedback(message) {
            if (dialogStatus instanceof HTMLElement) {
                dialogStatus.textContent = message;
            }
        }

        function closeDialog(restoreFocus) {
            var focusTarget = pendingForm instanceof HTMLFormElement
                ? pendingForm.querySelector('button[type="submit"]')
                : null;
            pendingForm = null;
            setSubmitting(false);
            if (dialog.open) {
                dialog.close();
            }
            if (restoreFocus && focusTarget instanceof HTMLButtonElement) {
                focusTarget.focus();
            }
        }

        function catalogRegionFromHtml(html) {
            if (typeof html !== 'string' || html.length > 1048576) {
                return null;
            }
            var template = document.createElement('template');
            template.innerHTML = html.trim();
            var regions = template.content.querySelectorAll(
                '[data-webadmin-media-catalog-region]'
            );
            if (
                regions.length !== 1
                || template.content.children.length !== 1
                || !(regions[0] instanceof HTMLDivElement)
            ) {
                return null;
            }

            return regions[0];
        }

        root.addEventListener('submit', function (event) {
            var form = event.target;
            if (
                !(form instanceof HTMLFormElement)
                || !form.matches('[data-webadmin-media-delete-form]')
                || submitting
            ) {
                return;
            }
            event.preventDefault();
            pendingForm = form;
            var card = form.closest('[data-webadmin-media-card]');
            var heading = card instanceof HTMLElement
                ? card.querySelector('h3')
                : null;
            if (label instanceof HTMLElement) {
                label.textContent = heading instanceof HTMLElement
                    ? heading.textContent.trim()
                    : 'esta imagen';
            }
            setFeedback('', false);
            setDialogFeedback('');
            dialog.showModal();
            confirm.focus();
        });

        cancel.addEventListener('click', function () {
            closeDialog(true);
        });
        dialog.addEventListener('cancel', function (event) {
            if (submitting) {
                event.preventDefault();
                return;
            }
            window.setTimeout(function () {
                pendingForm = null;
            }, 0);
        });
        dialog.addEventListener('click', function (event) {
            if (!submitting && event.target === dialog) {
                closeDialog(true);
            }
        });
        confirm.addEventListener('click', function () {
            if (!(pendingForm instanceof HTMLFormElement) || submitting) {
                return;
            }

            var action = new window.URL(
                pendingForm.action,
                window.location.href
            );
            var payload = encodedDeletePayload(pendingForm);
            if (action.origin !== window.location.origin || payload === null) {
                setDialogFeedback(
                    'No se pudo validar la operación solicitada.'
                );
                return;
            }
            setSubmitting(true);
            window.fetch(action.toString(), {
                method: 'POST',
                body: payload,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-LiquidStack-Media-Manager': 'async'
                }
            }).then(function (response) {
                var contentType = response.headers.get('content-type') || '';
                if (!/\bapplication\/json\b/i.test(contentType)) {
                    throw new Error('invalid_response');
                }
                return response.json().then(function (body) {
                    return { response: response, body: body };
                });
            }).then(function (result) {
                var body = result.body;
                if (
                    !result.response.ok
                    || !body
                    || body.ok !== true
                ) {
                    throw new Error(
                        body && typeof body.message === 'string'
                            ? body.message
                            : 'No se pudo retirar la imagen.'
                    );
                }
                var nextRegion = catalogRegionFromHtml(body.catalog_html);
                var currentRegion = root.querySelector(
                    '[data-webadmin-media-catalog-region]'
                );
                if (
                    !(nextRegion instanceof HTMLDivElement)
                    || !(currentRegion instanceof HTMLDivElement)
                ) {
                    throw new Error('invalid_catalog');
                }
                currentRegion.replaceWith(nextRegion);
                if (
                    Number.isInteger(body.page)
                    && body.page > 0
                    && typeof window.URL === 'function'
                    && window.history
                    && typeof window.history.replaceState === 'function'
                ) {
                    var nextUrl = new window.URL(window.location.href);
                    if (body.page === 1) {
                        nextUrl.searchParams.delete('page');
                    } else {
                        nextUrl.searchParams.set('page', String(body.page));
                    }
                    window.history.replaceState(
                        window.history.state,
                        '',
                        nextUrl.toString()
                    );
                }
                closeDialog(false);
                setFeedback(
                    'La imagen se ha retirado de la biblioteca y permanece en cuarentena.',
                    false
                );
                if (status instanceof HTMLElement) {
                    status.focus();
                }
            }).catch(function (error) {
                setSubmitting(false);
                setDialogFeedback(
                    error instanceof Error
                        && error.message !== 'invalid_response'
                        && error.message !== 'invalid_catalog'
                        ? error.message
                        : 'No se pudo retirar la imagen. Inténtalo de nuevo.'
                );
            });
        });

        root.dataset.webadminMediaDeleteBound = 'true';
    }

    function bindAdminShell(root) {
        if (
            !(root instanceof HTMLElement)
            || root.dataset.webadminShellBound === 'true'
        ) {
            return;
        }

        var menuToggle = root.querySelector('[data-webadmin-shell-toggle]');
        var inspectorToggle = root.querySelector(
            '[data-webadmin-inspector-toggle]'
        );
        var sidebar = root.querySelector('.webadminShell-sidebar');
        var inspector = root.querySelector('.webadminShell-inspector');
        var sidebarClose = root.querySelector('[data-webadmin-sidebar-close]');
        var inspectorClose = root.querySelector('[data-webadmin-inspector-close]');
        var desktop = typeof window.matchMedia === 'function'
            ? window.matchMedia('(min-width: 64rem)')
            : null;
        var inspectorReturnFocus = inspectorToggle instanceof HTMLElement
            ? inspectorToggle
            : null;

        function isDesktop() {
            return desktop === null || desktop.matches;
        }

        function updateToggle(button, open, kind) {
            if (button instanceof HTMLButtonElement) {
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
                var opensFromStart = kind === 'sidebar';
                var icon = button.querySelector('[data-webadmin-toggle-icon]');
                var label = button.querySelector('[data-webadmin-toggle-label]');
                var action = open ? 'Cerrar' : 'Abrir';
                var target = opensFromStart ? 'menú' : 'herramientas';
                var text = action + ' ' + target;
                button.setAttribute('aria-label', text);
                button.dataset.webadminToggleState = open ? 'open' : 'closed';
                if (label instanceof HTMLElement) {
                    label.textContent = text;
                }
                if (icon instanceof HTMLElement) {
                    icon.textContent = opensFromStart
                        ? (open ? '‹' : '›')
                        : (open ? '›' : '‹');
                }
            }
        }

        function setSidebar(open) {
            var restoreFocus = !open
                && sidebar instanceof HTMLElement
                && sidebar.contains(document.activeElement);
            root.dataset.webadminSidebarOpen = open ? 'true' : 'false';
            updateToggle(menuToggle, open, 'sidebar');
            if (sidebar instanceof HTMLElement) {
                if (open) {
                    sidebar.removeAttribute('aria-hidden');
                    sidebar.removeAttribute('inert');
                } else {
                    sidebar.setAttribute('aria-hidden', 'true');
                    sidebar.setAttribute('inert', '');
                }
            }
            if (restoreFocus && menuToggle instanceof HTMLButtonElement) {
                window.requestAnimationFrame(function () {
                    menuToggle.focus();
                });
            }
        }

        function setInspector(open) {
            var restoreFocus = !open
                && inspector instanceof HTMLElement
                && inspector.contains(document.activeElement);
            var returnFocus = inspectorReturnFocus instanceof HTMLElement
                && document.contains(inspectorReturnFocus)
                && inspectorReturnFocus.closest('[inert]') === null
                ? inspectorReturnFocus
                : inspectorToggle;
            root.dataset.webadminInspectorOpen = open ? 'true' : 'false';
            updateToggle(inspectorToggle, open, 'inspector');
            if (inspector instanceof HTMLElement) {
                if (open) {
                    inspector.removeAttribute('aria-hidden');
                    inspector.removeAttribute('inert');
                } else {
                    inspector.setAttribute('aria-hidden', 'true');
                    inspector.setAttribute('inert', '');
                }
            }
            if (
                restoreFocus
                && returnFocus instanceof HTMLElement
            ) {
                window.requestAnimationFrame(function () {
                    returnFocus.focus();
                });
            }
        }

        function rememberInspectorReturnFocus(element) {
            if (
                element instanceof HTMLElement
                && root.contains(element)
                && !(
                    inspector instanceof HTMLElement
                    && inspector.contains(element)
                )
            ) {
                inspectorReturnFocus = element;
            } else if (inspectorToggle instanceof HTMLElement) {
                inspectorReturnFocus = inspectorToggle;
            }
        }

        function focusDrawer(container, closeButton) {
            if (isDesktop()) {
                return;
            }
            window.requestAnimationFrame(function () {
                if (closeButton instanceof HTMLButtonElement) {
                    closeButton.focus();
                } else if (container instanceof HTMLElement) {
                    container.focus();
                }
            });
        }

        if (sidebarClose instanceof HTMLButtonElement) {
            sidebarClose.setAttribute('aria-controls', 'webadmin-navigation');
        }
        if (inspectorClose instanceof HTMLButtonElement) {
            inspectorClose.setAttribute('aria-controls', 'webadmin-inspector');
        }

        setSidebar(
            !(menuToggle instanceof HTMLButtonElement) || isDesktop()
        );
        setInspector(
            !(inspectorToggle instanceof HTMLButtonElement) || isDesktop()
        );

        if (menuToggle instanceof HTMLButtonElement) {
            menuToggle.addEventListener('click', function () {
                var open = root.dataset.webadminSidebarOpen !== 'true';
                if (open && !isDesktop()) {
                    setInspector(false);
                }
                setSidebar(open);
                if (open) {
                    focusDrawer(sidebar, sidebarClose);
                }
            });
        }
        if (inspectorToggle instanceof HTMLButtonElement) {
            inspectorToggle.addEventListener('click', function () {
                var open = root.dataset.webadminInspectorOpen !== 'true';
                if (open && !isDesktop()) {
                    setSidebar(false);
                }
                if (open) {
                    rememberInspectorReturnFocus(inspectorToggle);
                }
                setInspector(open);
                if (open) {
                    focusDrawer(inspector, inspectorClose);
                }
            });
        }
        if (sidebarClose instanceof HTMLButtonElement) {
            sidebarClose.addEventListener('click', function () {
                setSidebar(false);
                if (menuToggle instanceof HTMLButtonElement) {
                    menuToggle.focus();
                }
            });
        }
        if (inspectorClose instanceof HTMLButtonElement) {
            inspectorClose.addEventListener('click', function () {
                setInspector(false);
            });
        }

        // Public bridge for module-owned editors. `detail.returnFocus` may
        // identify the in-shell control that should regain focus on close.
        root.addEventListener('webadmin:open-inspector', function (event) {
            if (!(inspector instanceof HTMLElement)) {
                return;
            }
            var requestedReturnFocus = event.detail
                && event.detail.returnFocus;
            var active = document.activeElement;
            rememberInspectorReturnFocus(
                requestedReturnFocus instanceof HTMLElement
                    ? requestedReturnFocus
                    : active
            );
            if (!isDesktop()) {
                setSidebar(false);
            }
            setInspector(true);
            focusDrawer(inspector, inspectorClose);
        });

        function viewportChanged(event) {
            var open = Boolean(event.matches);
            setSidebar(open);
            setInspector(open);
        }

        if (desktop !== null) {
            if (typeof desktop.addEventListener === 'function') {
                desktop.addEventListener('change', viewportChanged);
            } else if (typeof desktop.addListener === 'function') {
                desktop.addListener(viewportChanged);
            }
        }

        root.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape' || isDesktop()) {
                return;
            }
            if (root.dataset.webadminInspectorOpen === 'true') {
                setInspector(false);
                return;
            }
            if (root.dataset.webadminSidebarOpen === 'true') {
                setSidebar(false);
                if (menuToggle instanceof HTMLButtonElement) {
                    menuToggle.focus();
                }
            }
        });

        root.dataset.webadminShellBound = 'true';
    }

    function bindProfileTimeZone(input) {
        if (
            !(input instanceof HTMLInputElement)
            || input.dataset.webadminProfileTimeZoneBound === 'true'
        ) {
            return;
        }
        input.dataset.webadminProfileTimeZoneBound = 'true';
        if (input.value.trim() !== '') {
            return;
        }

        var proposal = '';
        try {
            if (
                typeof Intl === 'object'
                && typeof Intl.DateTimeFormat === 'function'
            ) {
                proposal = Intl.DateTimeFormat()
                    .resolvedOptions().timeZone || '';
            }
        } catch (error) {
            proposal = '';
        }
        if (
            typeof proposal !== 'string'
            || proposal.length < 1
            || proposal.length > 64
            || !/^[A-Za-z0-9_+\/-]+$/.test(proposal)
        ) {
            return;
        }

        input.value = proposal;
        var status = input.parentElement
            ? input.parentElement.querySelector(
                '[data-webadmin-profile-time-zone-status]'
            )
            : null;
        if (status instanceof HTMLElement) {
            status.hidden = false;
            status.textContent = 'Zona horaria detectada. Revísala antes de guardar.';
        }
    }

    function init() {
        document.querySelectorAll('[data-auth-password-toggle]')
            .forEach(bindPasswordToggle);
        document.querySelectorAll('[data-auth02-password-toggle]')
            .forEach(bindAuth02PasswordToggle);
        document.querySelectorAll('[data-auth02-password-policy]')
            .forEach(bindAuth02PasswordPolicy);
        document.querySelectorAll('.webadminShell')
            .forEach(bindAdminShell);
        document.querySelectorAll('[data-webadmin-media-upload]')
            .forEach(bindMediaUploadForm);
        document.querySelectorAll('.webadminMedia')
            .forEach(bindMediaDelete);
        document.querySelectorAll('[data-webadmin-profile-time-zone]')
            .forEach(bindProfileTimeZone);
    }

    window.addEventListener('pageshow', function () {
        document.querySelectorAll('[data-webadmin-media-upload]')
            .forEach(function (form) {
                if (form instanceof HTMLFormElement) {
                    setMediaUploadSubmitting(form, false);
                }
            });
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}());
