(function () {
    'use strict';

    var previous = window.__liquidStackMediaPickerRuntime;
    if (previous && typeof previous.destroy === 'function') {
        previous.destroy();
    }

    var runtime = new AbortController();
    var instances = [];
    var selectionEvent = 'liquidstack:webadmin-media-picker:selected';
    var uuidV4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

    function safeThumbnail(publicId, value) {
        if (!uuidV4.test(publicId) || typeof value !== 'string') {
            return false;
        }
        try {
            var thumbnail = new URL(value, window.location.href);
            return thumbnail.origin === window.location.origin
                && /\/media\/file$/.test(thumbnail.pathname)
                && thumbnail.searchParams.get('asset') === publicId
                && /^[1-9][0-9]{0,3}$/.test(
                    thumbnail.searchParams.get('width') || ''
                );
        } catch (error) {
            return false;
        }
    }

    function validMedia(item) {
        if (!item || typeof item !== 'object'
            || typeof item.public_id !== 'string'
            || !uuidV4.test(item.public_id)
            || typeof item.label !== 'string'
            || item.label.trim() === ''
            || !item.thumbnail || typeof item.thumbnail !== 'object'
            || typeof item.thumbnail.url !== 'string'
            || typeof item.created_at !== 'string'
            || !Number.isFinite(Date.parse(item.created_at))
            || !Number.isInteger(item.source_width)
            || !Number.isInteger(item.source_height)
            || !Array.isArray(item.variants)
            || item.variants.length === 0
            || !item.variants.every(function (variant) {
                return variant && typeof variant === 'object'
                    && Number.isInteger(variant.width)
                    && Number.isInteger(variant.height)
                    && Number.isInteger(variant.bytes)
                    && variant.width > 0
                    && variant.height > 0
                    && variant.bytes > 0;
            })) {
            return false;
        }
        return safeThumbnail(item.public_id, item.thumbnail.url);
    }

    function optionMedia(option) {
        if (!option || !uuidV4.test(String(option.value || ''))) {
            return null;
        }
        var thumbnail = option.getAttribute('data-thumbnail-url')
            || option.getAttribute('data-media-thumbnail');
        var label = String(option.textContent || '').trim();
        if (!thumbnail || label === '' || label.length > 120
            || !safeThumbnail(option.value, thumbnail)) {
            return null;
        }
        return {
            public_id: option.value,
            label: label,
            thumbnail: { url: thumbnail },
            source_width: 0,
            source_height: 0,
            variants: []
        };
    }

    function requestId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        var hex = Array.from(bytes, function (value) {
            return value.toString(16).padStart(2, '0');
        }).join('');
        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-'
            + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-'
            + hex.slice(20);
    }

    function validUpload(payload) {
        var media = payload && payload.ok === true ? payload.media : null;
        return media && typeof media === 'object'
            && uuidV4.test(String(media.public_id || ''))
            && typeof media.label === 'string'
            && media.label.trim() === media.label
            && media.label.length > 0
            && media.label.length <= 120
            && Number.isInteger(media.thumbnail_width)
            && media.thumbnail_width > 0
            && media.thumbnail_width <= 2560
            && safeThumbnail(
                media.public_id,
                String(media.thumbnail_url || '')
            );
    }

    function text(tag, value) {
        var node = document.createElement(tag);
        node.textContent = value;
        return node;
    }

    function initialize(dialog) {
        var endpoint = dialog.getAttribute(
            'data-webadmin-media-picker-catalog'
        );
        var search = dialog.querySelector(
            '[data-webadmin-media-picker-search]'
        );
        var searchInput = search && search.elements.namedItem('q');
        var results = dialog.querySelector(
            '[data-webadmin-media-picker-results]'
        );
        var status = dialog.querySelector(
            '[data-webadmin-media-picker-status]'
        );
        var active = dialog.querySelector(
            '[data-webadmin-media-picker-active]'
        );
        var previousButton = dialog.querySelector(
            '[data-webadmin-media-picker-previous]'
        );
        var nextButton = dialog.querySelector(
            '[data-webadmin-media-picker-next]'
        );
        var select = dialog.querySelector(
            '[data-webadmin-media-picker-select]'
        );
        var confirmButton = dialog.querySelector(
            '[data-webadmin-media-picker-confirm]'
        );
        var closeButton = dialog.querySelector(
            '[data-webadmin-media-picker-close]'
        );
        var upload = dialog.querySelector(
            '[data-webadmin-media-picker-upload]'
        );
        var uploadProgress = dialog.querySelector(
            '[data-webadmin-media-picker-progress]'
        );
        var uploadStatus = dialog.querySelector(
            '[data-webadmin-media-picker-upload-status]'
        );
        if (!endpoint || !search || !searchInput || !results || !status
            || !active || !previousButton || !nextButton || !select
            || !confirmButton || !closeButton) {
            return;
        }
        dialog.setAttribute('data-webadmin-media-picker-enhanced', 'true');

        var state = {
            page: 1,
            pageSize: 24,
            query: '',
            active: optionMedia(select.selectedOptions[0]),
            confirmed: optionMedia(select.selectedOptions[0]),
            request: null,
            sequence: 0,
            loaded: false,
            uploadRequest: null,
            uploadPending: false
        };

        function announce(message) {
            status.textContent = message;
        }

        function renderActive() {
            active.replaceChildren();
            if (!state.active) {
                active.hidden = true;
                confirmButton.disabled = true;
                return;
            }
            var figure = document.createElement('figure');
            var image = document.createElement('img');
            image.src = state.active.thumbnail.url;
            image.alt = '';
            image.loading = 'lazy';
            image.decoding = 'async';
            figure.append(image, text('figcaption', state.active.label));
            active.append(text('h3', 'Imagen seleccionada'), figure);
            active.hidden = false;
            confirmButton.disabled = false;
        }

        function setSelection(item) {
            var option = Array.prototype.find.call(
                select.options,
                function (candidate) {
                    return candidate.value === item.public_id;
                }
            );
            if (!option) {
                option = document.createElement('option');
                option.value = item.public_id;
                option.textContent = item.label;
                select.append(option);
            }
            option.setAttribute('data-thumbnail-url', item.thumbnail.url);
            option.setAttribute('data-media-thumbnail', item.thumbnail.url);
            select.value = item.public_id;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            state.active = optionMedia(option);
            renderActive();
        }

        function restoreConfirmedSelection() {
            select.value = state.confirmed ? state.confirmed.public_id : '';
            state.active = optionMedia(select.selectedOptions[0]);
            select.dispatchEvent(new Event('change', { bubbles: true }));
            renderActive();
        }

        function closeDialog(cancelSelection) {
            if (cancelSelection) {
                restoreConfirmedSelection();
            }
            if (state.request) {
                state.request.abort();
                state.request = null;
            }
            if (state.uploadRequest) {
                state.uploadRequest.abort();
                state.uploadRequest = null;
            }
            if (typeof dialog.close === 'function') {
                dialog.close();
            } else {
                dialog.removeAttribute('open');
            }
        }

        function confirmSelection() {
            var selected = optionMedia(select.selectedOptions[0]);
            if (!selected) {
                announce('Selecciona una imagen antes de continuar.');
                confirmButton.disabled = true;
                return false;
            }
            var detail = Object.freeze({
                public_id: selected.public_id,
                label: selected.label,
                thumbnail_url: selected.thumbnail.url
            });
            var accepted = dialog.dispatchEvent(new CustomEvent(
                selectionEvent,
                { bubbles: true, cancelable: true, detail: detail }
            ));
            if (!accepted) {
                announce('La imagen seleccionada no se pudo aplicar.');
                return false;
            }
            state.confirmed = selected;
            closeDialog(false);
            return true;
        }

        function choose(item) {
            if (!validMedia(item)) {
                return;
            }
            setSelection(item);
            results.querySelectorAll('[data-webadmin-media-picker-item]')
                .forEach(function (button) {
                    button.setAttribute(
                        'aria-pressed',
                        button.getAttribute('data-media-public-id')
                            === item.public_id ? 'true' : 'false'
                    );
                });
        }

        function render(items) {
            results.replaceChildren();
            if (items.length === 0) {
                results.append(text('p', 'No se encontraron imágenes.'));
                return;
            }
            var list = document.createElement('ul');
            list.className = 'webadminMediaPicker__grid';
            list.setAttribute('data-webadmin-media-picker-grid', '');
            items.forEach(function (item) {
                if (!validMedia(item)) {
                    return;
                }
                var entry = document.createElement('li');
                var button = document.createElement('button');
                var image = document.createElement('img');
                var created = document.createElement('time');
                button.type = 'button';
                button.setAttribute('data-webadmin-media-picker-item', '');
                button.setAttribute('data-media-public-id', item.public_id);
                button.setAttribute(
                    'aria-pressed',
                    state.active && state.active.public_id === item.public_id
                        ? 'true' : 'false'
                );
                image.src = item.thumbnail.url;
                image.alt = '';
                image.loading = 'lazy';
                image.decoding = 'async';
                created.dateTime = item.created_at;
                created.textContent = new Intl.DateTimeFormat(
                    document.documentElement.lang || 'es',
                    {
                        day: '2-digit',
                        month: '2-digit',
                        year: 'numeric',
                        timeZone: 'UTC'
                    }
                ).format(new Date(item.created_at));
                button.append(
                    image,
                    text('strong', item.label),
                    text(
                        'span',
                        item.source_width + ' × ' + item.source_height + ' px'
                    ),
                    text(
                        'small',
                        'Anchos: ' + item.variants.map(function (variant) {
                            return variant.width;
                        }).join(', ') + ' px'
                    ),
                    created
                );
                button.addEventListener('click', function () {
                    choose(item);
                }, { signal: runtime.signal });
                entry.append(button);
                list.append(entry);
            });
            results.append(list);
        }

        async function load(page) {
            if (state.request) {
                state.request.abort();
            }
            state.request = new AbortController();
            var sequence = ++state.sequence;
            var url = new URL(endpoint, window.location.href);
            if (state.query) {
                url.searchParams.set('q', state.query);
            }
            url.searchParams.set('page', String(page));
            url.searchParams.set('per_page', String(state.pageSize));
            announce('Cargando imágenes…');
            previousButton.disabled = true;
            nextButton.disabled = true;
            try {
                var response = await fetch(url, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-LiquidStack-Media-Picker': 'async'
                    },
                    signal: state.request.signal
                });
                if (!response.ok) {
                    throw new Error('catalog');
                }
                var payload = await response.json();
                if (sequence !== state.sequence || !payload
                    || payload.ok !== true || !Array.isArray(payload.items)
                    || !payload.query || !payload.pagination) {
                    throw new Error('contract');
                }
                state.page = payload.query.page;
                state.loaded = true;
                render(payload.items);
                previousButton.disabled = !payload.pagination.has_previous;
                nextButton.disabled = !payload.pagination.has_next;
                announce(
                    payload.items.length === 1
                        ? '1 imagen disponible.'
                        : payload.items.length + ' imágenes disponibles.'
                );
            } catch (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }
                announce(
                    'No se pudo actualizar la biblioteca. La selección actual se conserva.'
                );
            }
        }

        search.addEventListener('submit', function (event) {
            event.preventDefault();
            state.query = String(searchInput.value || '').trim();
            load(1);
        }, { signal: runtime.signal });
        previousButton.addEventListener('click', function () {
            if (state.page > 1) {
                load(state.page - 1);
            }
        }, { signal: runtime.signal });
        nextButton.addEventListener('click', function () {
            load(state.page + 1);
        }, { signal: runtime.signal });
        confirmButton.addEventListener('click', function () {
            confirmSelection();
        }, { signal: runtime.signal });
        closeButton.addEventListener('click', function () {
            closeDialog(true);
        }, { signal: runtime.signal });
        dialog.addEventListener('cancel', function (event) {
            event.preventDefault();
            closeDialog(true);
        }, { signal: runtime.signal });
        select.addEventListener('change', function () {
            var selected = optionMedia(select.selectedOptions[0]);
            state.active = selected;
            renderActive();
        }, { signal: runtime.signal });

        if (upload instanceof HTMLFormElement) {
            ['label', 'image'].forEach(function (name) {
                var control = upload.elements.namedItem(name);
                if (control instanceof HTMLElement) {
                    control.addEventListener('change', function () {
                        var idempotency = upload.elements.namedItem(
                            'idempotency_key'
                        );
                        if (idempotency instanceof HTMLInputElement) {
                            idempotency.value = '';
                        }
                    }, { signal: runtime.signal });
                }
            });
            upload.addEventListener('submit', function (event) {
                event.preventDefault();
                if (state.uploadPending || !upload.reportValidity()) {
                    return;
                }
                var idempotency = upload.elements.namedItem('idempotency_key');
                if (!(idempotency instanceof HTMLInputElement)) {
                    return;
                }
                if (!uuidV4.test(idempotency.value)) {
                    idempotency.value = requestId();
                }
                var submit = upload.querySelector('button[type="submit"]');
                state.uploadPending = true;
                if (submit instanceof HTMLButtonElement) {
                    submit.disabled = true;
                }
                if (uploadProgress instanceof HTMLProgressElement) {
                    uploadProgress.hidden = false;
                    uploadProgress.removeAttribute('value');
                }
                if (uploadStatus instanceof HTMLElement) {
                    uploadStatus.textContent =
                        'Subiendo y preparando versiones AVIF…';
                }
                state.uploadRequest = new AbortController();
                window.fetch(upload.action, {
                    method: 'POST',
                    body: new FormData(upload),
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-LiquidStack-Media-Manager': 'async'
                    },
                    signal: state.uploadRequest.signal
                }).then(function (response) {
                    return response.json().catch(function () { return null; })
                        .then(function (payload) {
                            if (!response.ok || !validUpload(payload)) {
                                throw new Error(
                                    payload && typeof payload.message === 'string'
                                        ? payload.message
                                        : 'No se pudo subir la imagen.'
                                );
                            }
                            return payload.media;
                        });
                }).then(function (uploaded) {
                    setSelection({
                        public_id: uploaded.public_id,
                        label: uploaded.label,
                        thumbnail: { url: uploaded.thumbnail_url }
                    });
                    if (uploadStatus instanceof HTMLElement) {
                        uploadStatus.textContent =
                            'Imagen subida y seleccionada.';
                    }
                    upload.reset();
                    confirmSelection();
                }).catch(function (error) {
                    if (error && error.name === 'AbortError') {
                        return;
                    }
                    if (uploadStatus instanceof HTMLElement) {
                        uploadStatus.textContent = error instanceof Error
                            ? error.message
                            : 'No se pudo subir la imagen.';
                    }
                }).finally(function () {
                    state.uploadPending = false;
                    state.uploadRequest = null;
                    if (submit instanceof HTMLButtonElement) {
                        submit.disabled = false;
                    }
                    if (uploadProgress instanceof HTMLProgressElement) {
                        uploadProgress.hidden = true;
                    }
                });
            }, { signal: runtime.signal });
        }

        var observer = new MutationObserver(function (records) {
            if (records.some(function (record) {
                return record.type === 'attributes'
                    && record.attributeName === 'open';
            }) && dialog.open) {
                var selected = optionMedia(select.selectedOptions[0]);
                state.active = selected;
                state.confirmed = selected;
                renderActive();
                if (!state.loaded) {
                    load(1);
                }
            }
            if (records.some(function (record) {
                return record.type === 'childList';
            })) {
                var uploaded = optionMedia(select.selectedOptions[0]);
                if (uploaded) {
                    state.active = uploaded;
                    renderActive();
                }
            }
        });
        observer.observe(dialog, { attributes: true, attributeFilter: ['open'] });
        observer.observe(select, { childList: true });
        renderActive();
        instances.push({
            destroy: function () {
                if (state.request) {
                    state.request.abort();
                }
                if (state.uploadRequest) {
                    state.uploadRequest.abort();
                }
                observer.disconnect();
            }
        });
    }

    document.querySelectorAll('[data-webadmin-media-picker]')
        .forEach(initialize);

    window.__liquidStackMediaPickerRuntime = {
        destroy: function () {
            runtime.abort();
            instances.forEach(function (instance) {
                instance.destroy();
            });
            instances = [];
        }
    };
}());
