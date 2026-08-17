(() => {
    'use strict';

    const stateKey = Symbol.for('liquidstack.blog.admin-list');
    const previous = window[stateKey];
    if (previous && typeof previous.dispose === 'function') {
        previous.dispose();
    }

    const controller = new AbortController();
    const confirmFormSelector = '[data-blog-confirm-form]';
    const previewSelector = '[data-blog-private-preview]';
    const filterFormSelector = '[data-blog-admin-filter-form]';
    const resultsSelector = '[data-blog-admin-results]';
    const filterStatusSelector = '[data-blog-admin-filter-status]';
    const paginationSelector = '[data-blog-admin-pagination] a[href]';
    const sortSelector = '[data-blog-admin-sort][href]';
    const resetSelector = '[data-blog-admin-filter-reset]';
    const liveSearchSelector = '[data-blog-admin-live-search]';
    const languageFlowSelector = '[data-blog-language-flow]';
    const languagePanelSelector = '[data-blog-language-panel]';
    const previewLoadTimeoutMs = 12000;
    let previewState = null;
    let catalogForm = null;
    let catalogPath = '';
    let catalogRequestController = null;
    let catalogGeneration = 0;
    let liveSearchTimer = null;
    let liveSearchCommitted = false;
    let committedCatalogValues = null;
    let confirmationState = null;
    const approvedConfirmationForms = new WeakSet();
    const languageFlowStates = new Set();

    const element = (tag, className, text = '') => {
        const node = document.createElement(tag);
        if (className !== '') {
            node.className = className;
        }
        if (text !== '') {
            node.textContent = text;
        }
        return node;
    };

    const closeConfirmation = (state) => {
        if (state.dialog.open && typeof state.dialog.close === 'function') {
            state.dialog.close();
            return;
        }
        state.dialog.removeAttribute('open');
    };

    const confirmationCopy = (form) => {
        const title = form.dataset.blogTitle?.trim() || 'este art\u00edculo';
        if (form.dataset.blogConfirmAction === 'unpublish') {
            return {
                title: 'Retirar publicaci\u00f3n',
                message: `\u00bfRetirar \u201c${title}\u201d? Dejar\u00e1 de mostrarse en la web, los feeds y el sitemap. El contenido y las revisiones se conservar\u00e1n para poder republicarlo; su URL responder\u00e1 temporalmente con 404 hasta que se republique o se defina expresamente un 301 o 410.`,
                confirm: 'Retirar publicaci\u00f3n',
                variant: 'warning',
            };
        }
        if (form.dataset.blogConfirmAction === 'category-delete') {
            return {
                title: 'Eliminar traducci\u00f3n de la categor\u00eda',
                message: `\u00bfEliminar la traducci\u00f3n de \u201c${title}\u201d en este idioma? Esta acci\u00f3n no se puede deshacer. Las dem\u00e1s traducciones se conservar\u00e1n; si es la \u00faltima, desaparecer\u00e1 tambi\u00e9n la categor\u00eda.`,
                confirm: 'Eliminar traducci\u00f3n',
                variant: 'danger',
            };
        }
        if (form.dataset.blogConfirmAction === 'gone') {
            return {
                title: 'Confirmar retirada definitiva',
                message: `\u00bfMarcar la URL de \u201c${title}\u201d como retirada definitivamente? Responder\u00e1 410. Conserva el 404 temporal si todav\u00eda existe la posibilidad de republicarla.`,
                confirm: 'Confirmar 410',
                variant: 'danger',
            };
        }
        if (form.dataset.blogConfirmAction === 'redirect') {
            return {
                title: 'Confirmar redirecci\u00f3n permanente',
                message: `\u00bfCrear una redirecci\u00f3n 301 para \u201c${title}\u201d? Confirma que la publicaci\u00f3n seleccionada responde realmente a la misma intenci\u00f3n y sustituye su contenido.`,
                confirm: 'Confirmar 301',
                variant: 'warning',
            };
        }

        return {
            title: 'Mover a la papelera',
            message: `\u00bfMover \u201c${title}\u201d a la papelera? El borrador dejar\u00e1 de aparecer en la gesti\u00f3n habitual, pero podr\u00e1s recuperarlo despu\u00e9s.`,
            confirm: 'Mover a la papelera',
            variant: 'danger',
        };
    };

    const createConfirmation = () => {
        const dialog = document.createElement('dialog');
        dialog.className = 'blogEditor__confirmDialog';
        dialog.setAttribute('aria-labelledby', 'blog-admin-confirm-title');
        dialog.setAttribute(
            'aria-describedby',
            'blog-admin-confirm-message'
        );
        const shell = element('div', 'blogEditor__confirmDialogShell');
        const title = element('h2', 'blogEditor__confirmDialogTitle');
        title.id = 'blog-admin-confirm-title';
        const message = element('p', 'blogEditor__confirmDialogMessage');
        message.id = 'blog-admin-confirm-message';
        const actions = element('div', 'blogEditor__confirmDialogActions');
        const cancel = element('button', '', 'Cancelar');
        cancel.type = 'button';
        const confirm = element('button', 'blogEditor__confirmDialogConfirm');
        confirm.type = 'button';
        actions.append(cancel, confirm);
        shell.append(title, message, actions);
        dialog.append(shell);
        (document.querySelector('.webadmin') || document.body).append(dialog);
        const state = {
            dialog,
            title,
            message,
            cancel,
            confirm,
            form: null,
            submitter: null,
        };
        cancel.addEventListener('click', () => {
            closeConfirmation(state);
        }, { signal: controller.signal });
        confirm.addEventListener('click', () => {
            const form = state.form;
            const submitter = state.submitter;
            if (!(form instanceof HTMLFormElement)) {
                closeConfirmation(state);
                return;
            }
            approvedConfirmationForms.add(form);
            closeConfirmation(state);
            form.requestSubmit(
                submitter instanceof HTMLElement ? submitter : undefined
            );
        }, { signal: controller.signal });
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            closeConfirmation(state);
        }, { signal: controller.signal });
        dialog.addEventListener('close', () => {
            state.submitter?.focus();
            state.form = null;
            state.submitter = null;
        }, { signal: controller.signal });

        return state;
    };

    const openConfirmation = (form, submitter) => {
        confirmationState ||= createConfirmation();
        const copy = confirmationCopy(form);
        confirmationState.form = form;
        confirmationState.submitter = submitter;
        confirmationState.title.textContent = copy.title;
        confirmationState.message.textContent = copy.message;
        confirmationState.confirm.textContent = copy.confirm;
        confirmationState.confirm.dataset.variant = copy.variant;
        confirmationState.dialog.showModal();
        window.requestAnimationFrame(() => {
            confirmationState.cancel.focus();
        });
    };

    const closeLanguageFlow = (state) => {
        if (state.dialog.open && typeof state.dialog.close === 'function') {
            state.dialog.close();
            return;
        }
        state.dialog.removeAttribute('open');
    };

    const syncLanguageFlow = (state) => {
        const selected = state.form.querySelector(
            'input[name="destination_locale"]:checked'
        );
        if (!(selected instanceof HTMLInputElement)) {
            state.operation.value = state.fallbackOperationId;
            state.outcome.textContent =
                'Selecciona un idioma para confirmar el destino.';
            state.submit.textContent = 'Crear borrador';
            state.submit.disabled = true;
            return;
        }
        state.operation.value = selected.dataset.blogLanguageOperationId
            || state.fallbackOperationId;
        state.outcome.textContent = selected.dataset.blogLanguageOutcome
            || 'Se crear\u00e1 un borrador privado.';
        state.submit.textContent = selected.dataset.blogLanguageSubmitLabel
            || 'Crear borrador';
        state.submit.disabled = Boolean(state.submitting);
    };

    const restoreLanguageSubmission = (state) => {
        state.submitting = false;
        state.form.removeAttribute('aria-busy');
        state.submit.removeAttribute('aria-busy');
        state.dialog.removeAttribute('tabindex');
        state.submissionDestination?.remove();
        state.submissionDestination = null;
        state.radios.forEach(({ radio, disabled }) => {
            radio.disabled = disabled;
            radio.removeAttribute('aria-disabled');
        });
        state.close.disabled = false;
        syncLanguageFlow(state);
    };

    const submitLanguageFlow = (state, event) => {
        if (state.submitting) {
            event.preventDefault();
            return;
        }
        const selected = state.form.querySelector(
            'input[name="destination_locale"]:checked:not(:disabled)'
        );
        if (!(selected instanceof HTMLInputElement)) {
            return;
        }

        state.submitting = true;
        state.submit.setAttribute('aria-busy', 'true');
        state.outcome.textContent = 'Creando el borrador privado\u2026';
        state.submit.textContent = 'Creando borrador\u2026';
        state.submit.disabled = true;
        state.close.disabled = true;
        state.dialog.setAttribute('tabindex', '-1');

        const destination = document.createElement('input');
        destination.type = 'hidden';
        destination.name = 'destination_locale';
        destination.value = selected.value;
        destination.dataset.blogLanguageSubmissionDestination = 'true';
        state.form.append(destination);
        state.submissionDestination = destination;
        state.radios.forEach(({ radio }) => {
            radio.disabled = true;
            radio.setAttribute('aria-disabled', 'true');
        });
        window.requestAnimationFrame(() => {
            if (state.submitting && state.dialog.open) {
                state.dialog.focus({ preventScroll: true });
            }
        });
    };

    const restoreLanguageFlow = (state) => {
        if (!languageFlowStates.has(state)) {
            return;
        }
        restoreLanguageSubmission(state);
        closeLanguageFlow(state);
        state.close.hidden = true;
        state.details.append(state.panel);
        state.details.hidden = false;
        delete state.details.dataset.blogLanguageEnhanced;
        state.trigger.remove();
        state.dialog.remove();
        languageFlowStates.delete(state);
    };

    const destroyLanguageFlows = (root = null) => {
        [...languageFlowStates].forEach((state) => {
            if (
                root === null
                || root === state.details
                || (typeof root.contains === 'function'
                    && root.contains(state.details))
            ) {
                restoreLanguageFlow(state);
            }
        });
    };

    const initializeLanguageFlows = (root = document) => {
        if (
            typeof HTMLDialogElement !== 'function'
            || typeof HTMLInputElement !== 'function'
            || typeof root.querySelectorAll !== 'function'
        ) {
            return;
        }
        root.querySelectorAll(languageFlowSelector).forEach((details) => {
            if (details.dataset.blogLanguageEnhanced === 'true') {
                return;
            }
            const summary = details.querySelector('summary');
            const panel = details.querySelector(languagePanelSelector);
            const heading = panel?.querySelector('h2[id]');
            const form = panel?.querySelector('[data-blog-language-form]');
            const outcome = panel?.querySelector(
                '[data-blog-language-outcome-status]'
            );
            const submit = panel?.querySelector(
                '[data-blog-language-submit]'
            );
            const close = panel?.querySelector('[data-blog-language-close]');
            const operation = form?.querySelector(
                '[data-blog-language-operation]'
            );
            if (
                !(summary instanceof Element)
                || !(panel instanceof Element)
                || !(heading instanceof Element)
                || !(form instanceof HTMLFormElement)
                || !(outcome instanceof Element)
                || !(submit instanceof HTMLButtonElement)
                || !(close instanceof HTMLButtonElement)
                || !(operation instanceof HTMLInputElement)
                || typeof details.before !== 'function'
                || typeof details.after !== 'function'
            ) {
                return;
            }

            const trigger = element('button', summary.className);
            const triggerLabel = summary.getAttribute('aria-label')?.trim()
                || 'Duplicar / A\u00f1adir idioma';
            const triggerContent = Array.from(summary.childNodes);
            if (triggerContent.length === 0) {
                trigger.textContent = triggerLabel;
            } else {
                trigger.append(...triggerContent.map(
                    (node) => node.cloneNode(true)
                ));
            }
            trigger.type = 'button';
            trigger.title = summary.getAttribute('title') || triggerLabel;
            trigger.setAttribute('aria-label', triggerLabel);
            const describedBy = summary.getAttribute('aria-describedby');
            if (describedBy) {
                trigger.setAttribute('aria-describedby', describedBy);
            }
            trigger.setAttribute('aria-haspopup', 'dialog');
            const dialog = document.createElement('dialog');
            dialog.className = 'blogAdminPage__languageDialog';
            dialog.id = `${heading.id}-dialog`;
            dialog.setAttribute('aria-labelledby', heading.id);
            const description = panel.querySelector(':scope > p[id]');
            const dialogDescribedBy = [
                description instanceof Element ? description.id : '',
                outcome.id,
            ].filter((id) => id !== '').join(' ');
            if (dialogDescribedBy !== '') {
                dialog.setAttribute('aria-describedby', dialogDescribedBy);
            }
            trigger.setAttribute('aria-controls', dialog.id);

            details.before(trigger);
            details.after(dialog);
            dialog.append(panel);
            details.hidden = true;
            details.dataset.blogLanguageEnhanced = 'true';
            close.hidden = false;

            const state = {
                details,
                panel,
                form,
                outcome,
                submit,
                close,
                operation,
                fallbackOperationId: operation.value,
                trigger,
                dialog,
                submitting: false,
                submissionDestination: null,
                radios: Array.from(form.querySelectorAll(
                    'input[name="destination_locale"]'
                )).filter((radio) => radio instanceof HTMLInputElement)
                    .map((radio) => ({ radio, disabled: radio.disabled })),
            };
            languageFlowStates.add(state);
            syncLanguageFlow(state);

            trigger.addEventListener('click', () => {
                cancelCatalogInteraction();
                syncLanguageFlow(state);
                if (typeof dialog.showModal === 'function') {
                    dialog.showModal();
                } else {
                    dialog.setAttribute('open', '');
                }
                window.requestAnimationFrame(() => {
                    const selected = form.querySelector(
                        'input[name="destination_locale"]:checked'
                    );
                    const first = form.querySelector(
                        'input[name="destination_locale"]:not(:disabled)'
                    );
                    (selected || first || close).focus();
                });
            }, { signal: controller.signal });
            close.addEventListener('click', () => {
                closeLanguageFlow(state);
            }, { signal: controller.signal });
            dialog.addEventListener('cancel', (event) => {
                event.preventDefault();
                if (state.submitting) {
                    return;
                }
                closeLanguageFlow(state);
            }, { signal: controller.signal });
            dialog.addEventListener('close', () => {
                trigger.focus();
            }, { signal: controller.signal });
            form.addEventListener('change', (event) => {
                if (
                    event.target instanceof HTMLInputElement
                    && event.target.name === 'destination_locale'
                ) {
                    syncLanguageFlow(state);
                }
            }, { signal: controller.signal });
            form.addEventListener('submit', (event) => {
                submitLanguageFlow(state, event);
            }, { signal: controller.signal });
        });
    };

    const previewDeviceStatus = (device) => ({
        desktop: 'Vista de escritorio.',
        tablet: 'Vista de tablet.',
        mobile: 'Vista m\u00f3vil.',
    })[device] || 'Vista de escritorio.';

    const clearPreviewLoadTimeout = (state) => {
        if (state.loadTimeoutId === null) {
            return;
        }
        window.clearTimeout(state.loadTimeoutId);
        state.loadTimeoutId = null;
    };

    const setPreviewDeviceAvailability = (state, enabled) => {
        state.deviceButtons.forEach((button) => {
            button.disabled = !enabled;
        });
    };

    const failPreviewLoad = (state, generation, expectedUrl) => {
        if (
            state.loadGeneration !== generation
            || state.url !== expectedUrl
        ) {
            return;
        }
        clearPreviewLoadTimeout(state);
        state.url = '';
        state.frame.hidden = true;
        state.frame.src = 'about:blank';
        state.status.textContent = 'No se pudo cargar una vista previa '
            + 'completa y con estilos.';
        state.status.dataset.state = 'error';
        setPreviewDeviceAvailability(state, false);
    };

    const resetPreviewLoad = (state) => {
        clearPreviewLoadTimeout(state);
        state.loadGeneration += 1;
        state.url = '';
        state.frame.hidden = true;
        state.frame.src = 'about:blank';
        state.status.textContent = 'Vista previa cerrada.';
        state.status.dataset.state = 'idle';
        setPreviewDeviceAvailability(state, false);
    };

    const beginPreviewLoad = (state, expectedUrl) => {
        clearPreviewLoadTimeout(state);
        const generation = ++state.loadGeneration;
        state.url = expectedUrl;
        state.status.textContent = 'Cargando vista previa SSR\u2026';
        state.status.dataset.state = 'loading';
        setPreviewDeviceAvailability(state, false);
        state.frame.hidden = false;
        state.loadTimeoutId = window.setTimeout(() => {
            failPreviewLoad(state, generation, expectedUrl);
        }, previewLoadTimeoutMs);
        state.frame.src = expectedUrl;
    };

    const setPreviewDevice = (state, device) => {
        if (
            state.status.dataset.state !== 'ready'
            || !['desktop', 'tablet', 'mobile'].includes(device)
        ) {
            return;
        }
        state.stage.dataset.device = device;
        state.deviceButtons.forEach((button) => {
            button.setAttribute(
                'aria-pressed',
                button.dataset.blogPreviewDevice === device
                    ? 'true'
                    : 'false'
            );
        });
        state.status.textContent = previewDeviceStatus(device);
        state.status.dataset.state = 'ready';
    };

    const closePreview = (state) => {
        if (state.dialog.open && typeof state.dialog.close === 'function') {
            state.dialog.close();
            return;
        }
        state.dialog.removeAttribute('open');
    };

    const createPreview = () => {
        const dialog = document.createElement('dialog');
        dialog.className = 'blogEditor__immersivePreview';
        dialog.setAttribute('aria-labelledby', 'blog-admin-preview-title');

        const stage = element('div', 'blogEditor__immersivePreviewStage');
        stage.dataset.device = 'desktop';
        const viewport = element(
            'div',
            'blogEditor__immersivePreviewViewport'
        );
        const frame = document.createElement('iframe');
        frame.className = 'blogEditor__immersivePreviewFrame';
        frame.title = 'Vista previa SSR guardada del art\u00edculo';
        frame.referrerPolicy = 'no-referrer';
        viewport.append(frame);
        stage.append(viewport);

        const toolbar = element(
            'div',
            'blogEditor__immersivePreviewToolbar'
        );
        const title = element(
            'h2',
            'blogEditor__immersivePreviewTitle',
            'Vista previa del art\u00edculo'
        );
        title.id = 'blog-admin-preview-title';
        const devices = element(
            'div',
            'blogEditor__immersivePreviewDevices'
        );
        devices.setAttribute('role', 'group');
        devices.setAttribute('aria-label', 'Tama\u00f1o de la vista previa');
        const deviceButtons = ['desktop', 'tablet', 'mobile'].map((device) => {
            const label = {
                desktop: 'Desktop',
                tablet: 'Tablet',
                mobile: 'M\u00f3vil',
            }[device];
            const button = element('button', '', label);
            button.type = 'button';
            button.dataset.blogPreviewDevice = device;
            button.setAttribute(
                'aria-pressed',
                device === 'desktop' ? 'true' : 'false'
            );
            devices.append(button);
            return button;
        });
        const status = element(
            'p',
            'blogEditor__immersivePreviewStatus',
            'Vista de escritorio.'
        );
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.dataset.state = 'idle';
        const actions = element(
            'div',
            'blogEditor__immersivePreviewActions'
        );
        const close = element('button', '', 'Volver a gesti\u00f3n');
        close.type = 'button';
        close.dataset.blogPreviewClose = 'true';
        actions.append(close);
        toolbar.append(title, devices, status, actions);
        deviceButtons.forEach((button) => {
            button.disabled = true;
        });
        dialog.append(stage, toolbar);

        const host = document.querySelector('.webadmin') || document.body;
        host.append(dialog);
        const state = {
            dialog,
            stage,
            frame,
            title,
            status,
            deviceButtons,
            close,
            trigger: null,
            url: '',
            loadGeneration: 0,
            loadTimeoutId: null,
        };
        frame.addEventListener('load', () => {
            if (state.url === '') {
                return;
            }
            const generation = state.loadGeneration;
            const expectedUrl = state.url;
            let ready = false;
            try {
                const actual = new URL(
                    state.frame.contentWindow.location.href,
                    window.location.href
                );
                // A superseded iframe navigation may still dispatch its load
                // after a newer preview has started. It belongs to the old
                // document and must not fail (or complete) the current
                // generation; the current navigation keeps its own timeout.
                if (actual.href !== expectedUrl) {
                    return;
                }
                const previewDocument = state.frame.contentDocument;
                const stylesheets = previewDocument === null
                    ? []
                    : Array.from(previewDocument.querySelectorAll(
                        'link[rel~="stylesheet"]'
                    ));
                ready = previewDocument?.documentElement.dataset
                        .blogPreviewReady === 'true'
                    && stylesheets.length > 0
                    && stylesheets.every((stylesheet) => stylesheet.sheet !== null);
            } catch (error) {
                ready = false;
            }
            if (ready) {
                clearPreviewLoadTimeout(state);
                state.status.textContent = previewDeviceStatus(
                    state.stage.dataset.device || 'desktop'
                );
                state.status.dataset.state = 'ready';
                setPreviewDeviceAvailability(state, true);
                return;
            }
            failPreviewLoad(state, generation, expectedUrl);
        }, { signal: controller.signal });
        deviceButtons.forEach((button) => {
            button.addEventListener('click', () => {
                setPreviewDevice(
                    state,
                    button.dataset.blogPreviewDevice || 'desktop'
                );
            }, { signal: controller.signal });
        });
        close.addEventListener('click', () => {
            closePreview(state);
        }, { signal: controller.signal });
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            closePreview(state);
        }, { signal: controller.signal });
        dialog.addEventListener('close', () => {
            resetPreviewLoad(state);
            state.trigger?.focus();
        }, { signal: controller.signal });

        return state;
    };

    const catalogStatus = (message, state) => {
        if (typeof document.querySelector !== 'function') {
            return;
        }
        const status = document.querySelector(filterStatusSelector);
        if (!status) {
            return;
        }
        status.hidden = message === '';
        status.dataset.state = state;
        status.textContent = message;
    };

    const setCatalogBusy = (busy) => {
        if (typeof document.querySelector !== 'function') {
            return;
        }
        const results = document.querySelector(resultsSelector);
        if (!results) {
            return;
        }
        if (busy) {
            results.setAttribute('aria-busy', 'true');
            catalogStatus('Actualizando art\u00edculos\u2026', 'busy');
            return;
        }
        results.removeAttribute('aria-busy');
    };

    const clearLiveSearchTimer = () => {
        if (liveSearchTimer !== null) {
            window.clearTimeout(liveSearchTimer);
            liveSearchTimer = null;
        }
    };

    const cancelCatalogRequest = () => {
        catalogGeneration += 1;
        catalogRequestController?.abort();
        catalogRequestController = null;
        setCatalogBusy(false);
        catalogStatus('', 'neutral');
    };

    const rememberCatalogValues = () => {
        if (!(catalogForm instanceof HTMLFormElement)) {
            return;
        }
        committedCatalogValues = {};
        ['q', 'status', 'locale', 'period', 'per_page', 'sort', 'dir']
            .forEach((name) => {
                const field = catalogForm.elements.namedItem(name);
                if (field && typeof field.value === 'string') {
                    committedCatalogValues[name] = field.value;
                }
            });
    };

    const restoreCommittedCatalogValues = () => {
        if (
            !(catalogForm instanceof HTMLFormElement)
            || committedCatalogValues === null
        ) {
            return;
        }
        Object.entries(committedCatalogValues).forEach(([name, value]) => {
            const field = catalogForm.elements.namedItem(name);
            if (field && typeof field.value === 'string') {
                field.value = value;
            }
        });
    };

    const cancelCatalogInteraction = () => {
        clearLiveSearchTimer();
        cancelCatalogRequest();
        restoreCommittedCatalogValues();
    };

    const catalogUrlFromForm = () => {
        if (!(catalogForm instanceof HTMLFormElement)) {
            return null;
        }
        if (
            typeof catalogForm.checkValidity === 'function'
            && !catalogForm.checkValidity()
        ) {
            return null;
        }
        const destination = new URL(
            catalogForm.action,
            window.location.href
        );
        destination.search = new URLSearchParams(
            new FormData(catalogForm)
        ).toString();
        destination.hash = '';

        return destination;
    };

    const syncCatalogForm = (incomingForm) => {
        if (!(catalogForm instanceof HTMLFormElement)) {
            return;
        }
        ['q', 'status', 'locale', 'period', 'per_page', 'sort', 'dir']
            .forEach((name) => {
                const current = catalogForm.elements.namedItem(name);
                const incoming = incomingForm.elements.namedItem(name);
                if (
                    current
                    && incoming
                    && typeof current.value === 'string'
                    && typeof incoming.value === 'string'
                ) {
                    current.value = incoming.value;
                }
            });
    };

    const fallbackCatalogNavigation = (destination) => {
        window.location.assign(destination.href);
    };

    const loadCatalog = async (
        destination,
        historyMode,
        fallbackOnFailure,
        focusResults = false
    ) => {
        cancelCatalogRequest();
        const requestController = new AbortController();
        catalogRequestController = requestController;
        const generation = ++catalogGeneration;
        setCatalogBusy(true);

        try {
            const response = await window.fetch(destination.href, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { Accept: 'text/html' },
                signal: requestController.signal,
            });
            if (generation !== catalogGeneration) {
                return false;
            }
            const responseUrl = new URL(
                response.url || destination.href,
                destination.href
            );
            if (
                responseUrl.origin !== destination.origin
                || responseUrl.pathname !== catalogPath
            ) {
                fallbackCatalogNavigation(responseUrl);
                return false;
            }
            const contentType = response.headers.get('Content-Type') || '';
            if (!response.ok || !contentType.includes('text/html')) {
                if (fallbackOnFailure) {
                    fallbackCatalogNavigation(destination);
                } else {
                    catalogStatus(
                        'No se pudo actualizar el listado.',
                        'error'
                    );
                }
                return false;
            }

            const source = await response.text();
            if (generation !== catalogGeneration) {
                return false;
            }
            const parsed = new window.DOMParser().parseFromString(
                source,
                'text/html'
            );
            const incomingResults = parsed.querySelector(resultsSelector);
            const incomingForm = parsed.querySelector(filterFormSelector);
            const currentResults = document.querySelector(resultsSelector);
            if (!incomingResults || !incomingForm || !currentResults) {
                if (fallbackOnFailure) {
                    fallbackCatalogNavigation(destination);
                } else {
                    catalogStatus(
                        'No se pudo actualizar el listado.',
                        'error'
                    );
                }
                return false;
            }

            const importedResults = document.importNode(
                incomingResults,
                true
            );
            destroyLanguageFlows(currentResults);
            currentResults.replaceWith(importedResults);
            initializeLanguageFlows(importedResults);
            syncCatalogForm(incomingForm);
            rememberCatalogValues();
            if (typeof parsed.title === 'string' && parsed.title !== '') {
                document.title = parsed.title;
            }
            if (historyMode === 'push') {
                window.history.pushState({}, '', destination.href);
            } else if (historyMode === 'replace') {
                window.history.replaceState({}, '', destination.href);
            }

            const count = Number.parseInt(
                importedResults.dataset.blogAdminResultCount || '',
                10
            );
            catalogStatus(
                Number.isInteger(count) && count >= 0
                    ? `${count} art\u00edculos mostrados.`
                    : 'Listado actualizado.',
                'success'
            );
            if (focusResults) {
                importedResults.querySelector('[role="region"]')?.focus();
            }

            return true;
        } catch (error) {
            if (
                requestController.signal.aborted
                || generation !== catalogGeneration
                || (error && error.name === 'AbortError')
            ) {
                return false;
            }
            if (fallbackOnFailure) {
                fallbackCatalogNavigation(destination);
            } else {
                catalogStatus(
                    'No se pudo actualizar el listado.',
                    'error'
                );
            }

            return false;
        } finally {
            if (generation === catalogGeneration) {
                catalogRequestController = null;
                setCatalogBusy(false);
            }
        }
    };

    const initializeCatalog = () => {
        if (
            typeof document.querySelector !== 'function'
            || typeof window.fetch !== 'function'
            || typeof window.DOMParser !== 'function'
            || typeof window.history?.pushState !== 'function'
            || typeof window.history?.replaceState !== 'function'
            || typeof window.addEventListener !== 'function'
            || typeof FormData !== 'function'
            || typeof URLSearchParams !== 'function'
        ) {
            return;
        }
        const form = document.querySelector(filterFormSelector);
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        const action = new URL(form.action, window.location.href);
        if (action.origin !== window.location.origin) {
            return;
        }
        catalogForm = form;
        catalogPath = action.pathname;
        rememberCatalogValues();
        catalogStatus('', 'neutral');

        document.addEventListener('input', (event) => {
            const input = event.target instanceof Element
                ? event.target.closest(liveSearchSelector)
                : null;
            if (!input || !catalogForm.contains(input)) {
                return;
            }
            clearLiveSearchTimer();
            cancelCatalogRequest();
            liveSearchTimer = window.setTimeout(async () => {
                liveSearchTimer = null;
                const destination = catalogUrlFromForm();
                if (destination === null) {
                    catalogStatus(
                        'Escribe al menos dos caracteres para buscar.',
                        'neutral'
                    );
                    return;
                }
                const historyMode = liveSearchCommitted
                    ? 'replace'
                    : 'push';
                if (await loadCatalog(
                    destination,
                    historyMode,
                    false
                )) {
                    liveSearchCommitted = true;
                }
            }, 350);
        }, { signal: controller.signal });

        document.addEventListener('change', (event) => {
            const field = event.target instanceof Element
                ? event.target.closest('select')
                : null;
            if (!field || !catalogForm.contains(field)) {
                return;
            }
            clearLiveSearchTimer();
            cancelCatalogRequest();
            liveSearchCommitted = false;
            const destination = catalogUrlFromForm();
            if (destination !== null) {
                void loadCatalog(destination, 'push', false);
            }
        }, { signal: controller.signal });

        document.addEventListener('submit', (event) => {
            if (event.target !== catalogForm) {
                return;
            }
            const destination = catalogUrlFromForm();
            if (destination === null) {
                return;
            }
            event.preventDefault();
            clearLiveSearchTimer();
            liveSearchCommitted = false;
            void loadCatalog(destination, 'push', true, true);
        }, { signal: controller.signal });

        document.addEventListener('click', (event) => {
            if (
                event.button !== 0
                || event.ctrlKey
                || event.metaKey
                || event.shiftKey
                || event.altKey
            ) {
                return;
            }
            const link = event.target instanceof Element
                ? event.target.closest(
                    `${paginationSelector}, ${sortSelector}, ${resetSelector}`
                )
                : null;
            if (
                !(link instanceof HTMLAnchorElement)
                || link.target !== ''
                || link.hasAttribute('download')
            ) {
                return;
            }
            const destination = new URL(link.href, window.location.href);
            if (
                destination.origin !== window.location.origin
                || destination.pathname !== catalogPath
            ) {
                return;
            }
            event.preventDefault();
            clearLiveSearchTimer();
            liveSearchCommitted = false;
            void loadCatalog(destination, 'push', true, true);
        }, { signal: controller.signal });

        window.addEventListener('popstate', () => {
            clearLiveSearchTimer();
            liveSearchCommitted = false;
            const destination = new URL(window.location.href);
            void loadCatalog(destination, 'none', true, true);
        }, { signal: controller.signal });
    };

    initializeCatalog();
    initializeLanguageFlows();

    if (typeof window.addEventListener === 'function') {
        window.addEventListener('pageshow', () => {
            languageFlowStates.forEach((state) => {
                if (state.submitting) {
                    restoreLanguageSubmission(state);
                }
            });
        }, { signal: controller.signal });
    }

    document.addEventListener(
        'submit',
        (event) => {
            const form = event.target;
            if (
                !(form instanceof HTMLFormElement)
                || !form.matches(confirmFormSelector)
            ) {
                return;
            }
            if (approvedConfirmationForms.has(form)) {
                approvedConfirmationForms.delete(form);
                return;
            }
            if (
                typeof HTMLDialogElement !== 'function'
                || typeof form.requestSubmit !== 'function'
            ) {
                return;
            }
            event.preventDefault();
            openConfirmation(form, event.submitter);
        },
        { signal: controller.signal }
    );

    document.addEventListener(
        'click',
        (event) => {
            if (
                event.button !== 0
                || event.ctrlKey
                || event.metaKey
                || event.shiftKey
                || event.altKey
            ) {
                return;
            }
            const trigger = event.target instanceof Element
                ? event.target.closest(previewSelector)
                : null;
            if (!(trigger instanceof HTMLAnchorElement)) {
                return;
            }
            let destination;
            try {
                destination = new URL(trigger.href, window.location.href);
            } catch (error) {
                return;
            }
            if (destination.origin !== window.location.origin) {
                return;
            }
            if (!destination.pathname.endsWith('/editor/preview')) {
                return;
            }
            if (typeof HTMLDialogElement !== 'function') {
                return;
            }

            event.preventDefault();
            cancelCatalogInteraction();
            previewState ||= createPreview();
            resetPreviewLoad(previewState);
            previewState.trigger = trigger;
            previewState.title.textContent = trigger.dataset.blogPreviewTitle
                || 'Vista previa del art\u00edculo';
            beginPreviewLoad(previewState, destination.href);
            if (typeof previewState.dialog.showModal === 'function') {
                previewState.dialog.showModal();
            } else {
                previewState.dialog.setAttribute('open', '');
            }
            window.requestAnimationFrame(() => {
                const active = previewState.deviceButtons.find(
                    (button) => button.getAttribute('aria-pressed') === 'true'
                );
                (active && !active.disabled
                    ? active
                    : previewState.close).focus();
            });
        },
        { signal: controller.signal }
    );

    window[stateKey] = {
        dispose() {
            clearLiveSearchTimer();
            cancelCatalogRequest();
            controller.abort();
            destroyLanguageFlows();
            if (previewState !== null) {
                resetPreviewLoad(previewState);
            }
            previewState?.dialog.remove();
            confirmationState?.dialog.remove();
            previewState = null;
            confirmationState = null;
            catalogForm = null;
            delete window[stateKey];
        },
    };
})();
