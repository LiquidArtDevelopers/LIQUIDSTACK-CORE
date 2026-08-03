(function () {
    'use strict';

    var SCHEMA = 'liquidstack.blog.document';
    var VERSION = 1;
    var TEMPLATE_BASIC = 'article-basic-01';
    var TEMPLATE_COVER = 'article-cover-01';
    var BLOCK_TYPES = [
        'paragraph',
        'heading',
        'list',
        'callout',
        'link',
        'image',
        'video',
        'cta'
    ];
    var BLOCK_LABELS = {
        paragraph: 'Párrafo',
        heading: 'Encabezado',
        list: 'Lista',
        callout: 'Destacado',
        link: 'Enlace independiente',
        image: 'Imagen',
        video: 'Vídeo de YouTube',
        cta: 'Llamada a la acción'
    };
    var MAX_JSON_BYTES = 300000;
    var MAX_BLOCKS = 200;
    var MAX_LIST_ITEMS = 100;
    var MAX_INLINE_NODES = 500;
    var MAX_INLINE_TEXT_BYTES = 20000;
    var UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
    var instanceNumber = 0;

    function bytes(value) {
        if (typeof TextEncoder === 'function') {
            return new TextEncoder().encode(String(value)).length;
        }
        return new Blob([String(value)]).size;
    }

    function exactKeys(value, expected) {
        if (!value || typeof value !== 'object' || Array.isArray(value)) {
            return false;
        }

        var actual = Object.keys(value).sort();
        var wanted = expected.slice().sort();
        return actual.length === wanted.length
            && actual.every(function (key, index) {
                return key === wanted[index];
            });
    }

    function safePlainText(value) {
        return typeof value === 'string'
            && !/[\u0000-\u001F\u007F-\u009F]/u.test(value)
            && !/<\/?[A-Za-z][^>]*>/u.test(value);
    }

    function singleLine(value, maximum, allowEmpty) {
        return safePlainText(value)
            && value.trim() === value
            && (allowEmpty || value !== '')
            && bytes(value) <= maximum;
    }

    function optionalSingleLine(value, maximum) {
        return value === null || singleLine(value, maximum, false);
    }

    function safeRootRelativeUrl(value) {
        if (!value.startsWith('/') || value.startsWith('//')) {
            return false;
        }

        var path = value.split(/[?#]/u, 1)[0];
        if (path.includes('//') || /%(?:2f|5c)/iu.test(path)) {
            return false;
        }

        var decoded = path;
        var stable = false;
        for (var pass = 0; pass < 8; pass += 1) {
            if (/%(?:2f|5c)/iu.test(decoded)) {
                return false;
            }
            try {
                var next = decodeURIComponent(decoded);
                if (next === decoded) {
                    stable = true;
                    break;
                }
                decoded = next;
            } catch (error) {
                return false;
            }
        }
        if (
            !stable
            || decoded.includes('\\')
            || decoded.includes('//')
            || /[\u0000-\u001F\u007F]/u.test(decoded)
        ) {
            return false;
        }

        return decoded.split('/').every(function (segment) {
            return segment !== '.' && segment !== '..';
        });
    }

    function safeUrl(value) {
        if (
            typeof value !== 'string'
            || value === ''
            || value.trim() !== value
            || bytes(value) > 2048
            || /[\u0000-\u001F\u007F\\]/u.test(value)
            || /%(?![0-9A-Fa-f]{2})/u.test(value)
        ) {
            return false;
        }
        if (/^tel:\+?[0-9][0-9 .()\-]{2,31}$/u.test(value)) {
            return true;
        }
        if (/\s/u.test(value)) {
            return false;
        }
        if (safeRootRelativeUrl(value)) {
            return true;
        }
        if (value.startsWith('mailto:') && !value.includes('?')) {
            return /^[^@\s]+@[^@\s]+\.[^@\s]+$/u.test(value.slice(7));
        }
        if (!value.startsWith('https://')) {
            return false;
        }

        try {
            var parsed = new URL(value);
            return parsed.protocol === 'https:'
                && parsed.hostname !== ''
                && parsed.username === ''
                && parsed.password === '';
        } catch (error) {
            return false;
        }
    }

    function validMarks(marks) {
        return Array.isArray(marks)
            && (
                marks.length === 0
                || (marks.length === 1 && ['strong', 'em'].includes(marks[0]))
                || (
                    marks.length === 2
                    && marks[0] === 'strong'
                    && marks[1] === 'em'
                )
            );
    }

    function validInline(content, allowBreak) {
        if (
            !Array.isArray(content)
            || content.length < 1
            || content.length > MAX_INLINE_NODES
        ) {
            return false;
        }

        var meaningful = '';
        var valid = content.every(function (node) {
            if (node.type === 'break') {
                meaningful += '\n';
                return allowBreak && exactKeys(node, ['type']);
            }
            if (
                node.type === 'text'
                && exactKeys(node, ['type', 'text', 'marks'])
                && safePlainText(node.text)
                && node.text !== ''
                && bytes(node.text) <= MAX_INLINE_TEXT_BYTES
                && validMarks(node.marks)
            ) {
                meaningful += node.text;
                return true;
            }
            if (
                node.type === 'link'
                && exactKeys(
                    node,
                    ['type', 'text', 'marks', 'href', 'title', 'target']
                )
                && safePlainText(node.text)
                && node.text !== ''
                && bytes(node.text) <= MAX_INLINE_TEXT_BYTES
                && validMarks(node.marks)
                && safeUrl(node.href)
                && optionalSingleLine(node.title, 500)
                && ['same', 'new'].includes(node.target)
            ) {
                meaningful += node.text;
                return true;
            }
            return false;
        });

        return valid && meaningful.trim() !== '';
    }

    function validDocument(documentValue) {
        if (
            !exactKeys(
                documentValue,
                ['schema', 'version', 'template', 'blocks']
            )
            || documentValue.schema !== SCHEMA
            || documentValue.version !== VERSION
            || ![TEMPLATE_BASIC, TEMPLATE_COVER].includes(documentValue.template)
            || !Array.isArray(documentValue.blocks)
            || documentValue.blocks.length > MAX_BLOCKS
        ) {
            return false;
        }

        var seen = new Set();
        var lastHeadingLevel = 1;
        var coverPositions = [];
        var valid = documentValue.blocks.every(function (block, blockIndex) {
            if (
                !block
                || typeof block !== 'object'
                || !UUID_V4.test(block.id || '')
                || seen.has(block.id)
                || !BLOCK_TYPES.includes(block.type)
            ) {
                return false;
            }
            seen.add(block.id);

            if (block.type === 'paragraph') {
                return exactKeys(block, ['id', 'type', 'content'])
                    && validInline(block.content, true);
            }
            if (block.type === 'heading') {
                if (
                    !exactKeys(block, ['id', 'type', 'level', 'content'])
                    || ![2, 3, 4, 5, 6].includes(block.level)
                    || !validInline(block.content, false)
                    || block.level > lastHeadingLevel + 1
                ) {
                    return false;
                }
                lastHeadingLevel = block.level;
                return true;
            }
            if (block.type === 'list') {
                return exactKeys(block, ['id', 'type', 'ordered', 'items'])
                    && typeof block.ordered === 'boolean'
                    && Array.isArray(block.items)
                    && block.items.length >= 1
                    && block.items.length <= MAX_LIST_ITEMS
                    && block.items.every(function (item) {
                        if (
                            !exactKeys(item, ['id', 'content'])
                            || !UUID_V4.test(item.id || '')
                            || seen.has(item.id)
                            || !validInline(item.content, true)
                        ) {
                            return false;
                        }
                        seen.add(item.id);
                        return true;
                    });
            }
            if (block.type === 'callout') {
                return exactKeys(
                    block,
                    ['id', 'type', 'tone', 'content']
                )
                    && ['neutral', 'info', 'warning'].includes(block.tone)
                    && validInline(block.content, true);
            }
            if (block.type === 'link') {
                return exactKeys(
                    block,
                    ['id', 'type', 'label', 'href', 'title', 'target']
                )
                    && singleLine(block.label, 255, false)
                    && safeUrl(block.href)
                    && optionalSingleLine(block.title, 500)
                    && ['same', 'new'].includes(block.target);
            }
            if (block.type === 'image') {
                if (block.display === 'cover') {
                    coverPositions.push(blockIndex);
                }
                return exactKeys(
                    block,
                    [
                        'id', 'type', 'media_asset_public_id', 'alt',
                        'title', 'caption', 'decorative', 'display'
                    ]
                )
                    && UUID_V4.test(block.media_asset_public_id || '')
                    && typeof block.decorative === 'boolean'
                    && singleLine(block.alt, 500, true)
                    && (
                        (block.decorative && block.alt === '')
                        || (!block.decorative && block.alt !== '')
                    )
                    && optionalSingleLine(block.title, 500)
                    && optionalSingleLine(block.caption, 2000)
                    && ['content', 'wide', 'cover'].includes(block.display);
            }
            if (block.type === 'video') {
                return exactKeys(
                    block,
                    [
                        'id', 'type', 'provider', 'video_id', 'title',
                        'start_seconds'
                    ]
                )
                    && block.provider === 'youtube'
                    && /^[A-Za-z0-9_-]{11}$/u.test(block.video_id)
                    && singleLine(block.title, 500, false)
                    && Number.isInteger(block.start_seconds)
                    && block.start_seconds >= 0
                    && block.start_seconds <= 86400;
            }

            return exactKeys(
                block,
                [
                    'id', 'type', 'label', 'href', 'title', 'target',
                    'variant'
                ]
            )
                && singleLine(block.label, 255, false)
                && safeUrl(block.href)
                && optionalSingleLine(block.title, 500)
                && ['same', 'new'].includes(block.target)
                && ['primary', 'secondary'].includes(block.variant);
        });
        if (!valid) {
            return false;
        }
        if (
            documentValue.template === TEMPLATE_BASIC
            && coverPositions.length !== 0
        ) {
            return false;
        }
        if (
            documentValue.template === TEMPLATE_COVER
            && (
                coverPositions.length !== 1
                || coverPositions[0] !== 0
                || documentValue.blocks.length === 0
                || documentValue.blocks[0].type !== 'image'
            )
        ) {
            return false;
        }

        return bytes(JSON.stringify(documentValue)) <= MAX_JSON_BYTES;
    }

    function element(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (typeof text === 'string') {
            node.textContent = text;
        }
        return node;
    }

    function controlId(context, suffix) {
        context.controlNumber += 1;
        return 'blog-editor-' + context.instance + '-'
            + suffix + '-' + context.controlNumber;
    }

    function announce(context, message, isError) {
        context.announcementVersion += 1;
        var version = context.announcementVersion;
        context.status.textContent = '';
        context.status.dataset.state = isError ? 'error' : 'ok';

        var deliver = function () {
            if (context.announcementVersion === version) {
                context.status.textContent = message;
            }
        };
        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(deliver);
        } else if (typeof window.setTimeout === 'function') {
            window.setTimeout(deliver, 0);
        } else {
            deliver();
        }
    }

    function focusElement(elementValue) {
        if (!elementValue || typeof elementValue.focus !== 'function') {
            return false;
        }
        elementValue.focus();
        return true;
    }

    function focusAfterRender(context, target) {
        var candidates = [];
        var match = function () { return false; };
        var collect = function (selector) {
            var found = Array.from(
                context.blockList.querySelectorAll(selector)
            );
            if (
                context.blockInspector
                && typeof context.blockInspector.querySelectorAll === 'function'
            ) {
                found = found.concat(Array.from(
                    context.blockInspector.querySelectorAll(selector)
                ));
            }
            return found;
        };

        if (target && target.kind === 'block') {
            candidates = collect('[data-blog-block-title]');
            match = function (candidate) {
                return candidate.dataset.blogBlockTitle === target.id;
            };
        } else if (target && target.kind === 'inline') {
            candidates = collect(
                '[data-blog-inline-owner][data-blog-inline-index]'
            );
            match = function (candidate) {
                return candidate.dataset.blogInlineOwner === target.owner
                    && candidate.dataset.blogInlineIndex
                        === String(target.index);
            };
        } else if (target && target.kind === 'list-item') {
            candidates = collect('[data-blog-list-item-id]');
            match = function (candidate) {
                return candidate.dataset.blogListItemId === target.id;
            };
        }

        for (var index = 0; index < candidates.length; index += 1) {
            if (match(candidates[index])) {
                return focusElement(candidates[index]);
            }
        }

        return focusElement(context.form.querySelector(
            '[data-blog-add-block]:not([disabled])'
        )) || focusElement(context.templateSelect);
    }

    function sync(context) {
        var serialized = JSON.stringify(context.documentValue);
        context.documentInput.value = serialized;
        return bytes(serialized) <= MAX_JSON_BYTES;
    }

    function inputControl(
        context,
        fieldName,
        value,
        update,
        options
    ) {
        var settings = options || {};
        var control = document.createElement(settings.multiline ? 'textarea' : 'input');
        control.id = controlId(context, fieldName);
        control.dataset.blogField = fieldName;
        if (!settings.multiline) {
            control.type = settings.type || 'text';
        } else if (settings.rows) {
            control.rows = settings.rows;
        }
        control.value = value === null || value === undefined
            ? ''
            : String(value);
        if (context.form.id) {
            control.setAttribute('form', context.form.id);
        }
        if (settings.maxLength) {
            control.maxLength = settings.maxLength;
        }
        if (settings.min !== undefined) {
            control.min = String(settings.min);
        }
        if (settings.max !== undefined) {
            control.max = String(settings.max);
        }
        if (settings.pattern) {
            control.pattern = settings.pattern;
        }
        if (settings.inputMode) {
            control.inputMode = settings.inputMode;
        }
        control.disabled = context.readOnly || Boolean(settings.disabled);
        control.addEventListener('input', function () {
            update(control.value);
            sync(context);
            refreshVisualCanvas(context);
        });
        return control;
    }

    function selectControl(context, fieldName, value, options, update, disabled) {
        var select = document.createElement('select');
        select.id = controlId(context, fieldName);
        select.dataset.blogField = fieldName;
        if (context.form.id) {
            select.setAttribute('form', context.form.id);
        }
        options.forEach(function (optionDefinition) {
            var option = document.createElement('option');
            option.value = optionDefinition.value;
            option.textContent = optionDefinition.label;
            option.disabled = Boolean(optionDefinition.disabled);
            option.selected = optionDefinition.value === String(value);
            select.append(option);
        });
        select.disabled = context.readOnly || Boolean(disabled);
        select.addEventListener('change', function () {
            update(select.value);
            sync(context);
            refreshVisualCanvas(context);
        });
        return select;
    }

    function checkboxControl(context, fieldName, checked, update, disabled) {
        var checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.id = controlId(context, fieldName);
        checkbox.dataset.blogField = fieldName;
        if (context.form.id) {
            checkbox.setAttribute('form', context.form.id);
        }
        checkbox.checked = Boolean(checked);
        checkbox.disabled = context.readOnly || Boolean(disabled);
        checkbox.addEventListener('change', function () {
            update(checkbox.checked);
            sync(context);
            refreshVisualCanvas(context);
        });
        return checkbox;
    }

    function field(labelText, control) {
        var wrapper = element('div', 'blogEditor__field');
        var label = document.createElement('label');
        label.htmlFor = control.id;
        label.textContent = labelText;
        wrapper.append(label, control);
        return wrapper;
    }

    function actionButton(
        context,
        label,
        attribute,
        action,
        callback,
        disabled,
        allowReadOnly
    ) {
        var button = element('button', '', label);
        button.type = 'button';
        button.setAttribute(attribute, action);
        button.disabled = (!allowReadOnly && context.readOnly)
            || Boolean(disabled);
        button.addEventListener('click', callback);
        return button;
    }

    function generateUuid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID().toLowerCase();
        }
        if (!window.crypto || typeof window.crypto.getRandomValues !== 'function') {
            throw new Error('Secure UUID generation is unavailable.');
        }

        var data = new Uint8Array(16);
        window.crypto.getRandomValues(data);
        data[6] = (data[6] & 15) | 64;
        data[8] = (data[8] & 63) | 128;
        var hex = Array.from(data, function (byte) {
            return byte.toString(16).padStart(2, '0');
        }).join('');
        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-'
            + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-'
            + hex.slice(20);
    }

    function allStructuralIds(documentValue) {
        var ids = new Set();
        documentValue.blocks.forEach(function (block) {
            ids.add(block.id);
            if (block.type === 'list') {
                block.items.forEach(function (item) {
                    ids.add(item.id);
                });
            }
        });
        return ids;
    }

    function uniqueUuid(context, reserved) {
        var ids = allStructuralIds(context.documentValue);
        for (var attempt = 0; attempt < 8; attempt += 1) {
            var id = generateUuid();
            if (
                UUID_V4.test(id)
                && !ids.has(id)
                && !(reserved instanceof Set && reserved.has(id))
            ) {
                return id;
            }
        }
        throw new Error('A unique UUID could not be generated.');
    }

    function textNode(text) {
        return { type: 'text', text: text, marks: [] };
    }

    function makeBlock(context, type) {
        var id = uniqueUuid(context);
        if (type === 'paragraph') {
            return { id: id, type: type, content: [textNode('Nuevo párrafo')] };
        }
        if (type === 'heading') {
            return {
                id: id,
                type: type,
                level: 2,
                content: [textNode('Nuevo encabezado')]
            };
        }
        if (type === 'list') {
            return {
                id: id,
                type: type,
                ordered: false,
                items: [{
                    id: uniqueUuid(context, new Set([id])),
                    content: [textNode('Nuevo elemento')]
                }]
            };
        }
        if (type === 'callout') {
            return {
                id: id,
                type: type,
                tone: 'neutral',
                content: [textNode('Contenido destacado')]
            };
        }
        if (type === 'link') {
            return {
                id: id,
                type: type,
                label: 'Nuevo enlace',
                href: '/',
                title: null,
                target: 'same'
            };
        }
        if (type === 'image') {
            if (context.media.length === 0) {
                throw new Error('No media is available.');
            }
            return {
                id: id,
                type: type,
                media_asset_public_id: context.media[0].publicId,
                alt: 'Descripción de la imagen',
                title: null,
                caption: null,
                decorative: false,
                display: 'content'
            };
        }
        if (type === 'video') {
            return {
                id: id,
                type: type,
                provider: 'youtube',
                video_id: 'vKQi3bBA1y8',
                title: 'Vídeo de YouTube',
                start_seconds: 0
            };
        }
        if (type === 'cta') {
            return {
                id: id,
                type: type,
                label: 'Llamada a la acción',
                href: '/',
                title: null,
                target: 'same',
                variant: 'primary'
            };
        }
        throw new Error('Unsupported block type.');
    }

    function normalizeContracts(context, notify) {
        var value = context.documentValue;
        var changed = false;
        if (
            value.template === TEMPLATE_COVER
            && (
                value.blocks.length === 0
                || value.blocks[0].type !== 'image'
            )
        ) {
            value.template = TEMPLATE_BASIC;
            changed = true;
            if (notify) {
                announce(
                    context,
                    'La plantilla con portada necesita una imagen como primer bloque.',
                    true
                );
            }
        }

        value.blocks.forEach(function (block, index) {
            if (block.type !== 'image') {
                return;
            }
            var expected = value.template === TEMPLATE_COVER && index === 0
                ? 'cover'
                : (block.display === 'cover' ? 'content' : block.display);
            if (block.display !== expected) {
                block.display = expected;
                changed = true;
            }
        });

        context.templateSelect.value = value.template;

        return changed;
    }

    function nullable(value) {
        return value.trim() === '' ? null : value;
    }

    function renderMarks(context, node) {
        var wrapper = element('div', 'blogEditor__marks');
        ['strong', 'em'].forEach(function (mark) {
            var checkbox = checkboxControl(
                context,
                'inline-' + mark,
                node.marks.includes(mark),
                function (checked) {
                    var selected = new Set(node.marks);
                    if (checked) {
                        selected.add(mark);
                    } else {
                        selected.delete(mark);
                    }
                    node.marks = ['strong', 'em'].filter(function (candidate) {
                        return selected.has(candidate);
                    });
                }
            );
            var label = document.createElement('label');
            label.htmlFor = checkbox.id;
            label.append(checkbox, document.createTextNode(
                mark === 'strong' ? 'Negrita' : 'Énfasis'
            ));
            wrapper.append(label);
        });
        return wrapper;
    }

    function renderInlineEditor(context, content, allowBreak, title, ownerId) {
        var editor = element('div', 'blogEditor__inlineEditor');
        editor.append(element('h4', '', title));
        var list = element('ol', 'blogEditor__inlineList');

        content.forEach(function (node, index) {
            var item = element('li', 'blogEditor__inlineNode');
            item.dataset.blogInlineOwner = ownerId;
            item.dataset.blogInlineIndex = String(index);
            item.tabIndex = -1;
            var fieldset = document.createElement('fieldset');
            fieldset.append(element(
                'legend',
                '',
                'Nodo ' + (index + 1)
            ));
            var typeOptions = [
                { value: 'text', label: 'Texto' },
                { value: 'link', label: 'Enlace' }
            ];
            if (allowBreak) {
                typeOptions.push({ value: 'break', label: 'Salto de línea' });
            }
            var type = selectControl(
                context,
                'inline-type',
                node.type,
                typeOptions,
                function (nextType) {
                    if (nextType === 'break') {
                        content[index] = { type: 'break' };
                    } else {
                        var next = {
                            type: nextType,
                            text: node.text || 'Nuevo texto',
                            marks: Array.isArray(node.marks) ? node.marks : []
                        };
                        if (nextType === 'link') {
                            next.href = node.href || '/';
                            next.title = node.title || null;
                            next.target = node.target || 'same';
                        }
                        content[index] = next;
                    }
                    render(context);
                    focusAfterRender(context, {
                        kind: 'inline',
                        owner: ownerId,
                        index: index
                    });
                }
            );
            fieldset.append(field('Tipo de nodo', type));

            if (node.type !== 'break') {
                fieldset.append(field(
                    'Texto',
                    inputControl(
                        context,
                        'inline-text',
                        node.text,
                        function (value) { node.text = value; },
                        { multiline: true, rows: 2, maxLength: 20000 }
                    )
                ));
                fieldset.append(renderMarks(context, node));
            }
            if (node.type === 'link') {
                fieldset.append(field(
                    'URL',
                    inputControl(
                        context,
                        'inline-href',
                        node.href,
                        function (value) { node.href = value; },
                        { maxLength: 2048 }
                    )
                ));
                fieldset.append(field(
                    'Title opcional',
                    inputControl(
                        context,
                        'inline-title',
                        node.title,
                        function (value) { node.title = nullable(value); },
                        { maxLength: 500 }
                    )
                ));
                fieldset.append(field(
                    'Destino',
                    selectControl(
                        context,
                        'inline-target',
                        node.target,
                        [
                            { value: 'same', label: 'Misma ventana' },
                            { value: 'new', label: 'Nueva ventana' }
                        ],
                        function (value) { node.target = value; }
                    )
                ));
            }

            var actions = element('div', 'blogEditor__actions');
            actions.append(
                actionButton(
                    context,
                    'Subir nodo',
                    'data-blog-inline-action',
                    'up',
                    function () {
                        move(content, index, index - 1);
                        render(context);
                        focusAfterRender(context, {
                            kind: 'inline',
                            owner: ownerId,
                            index: index - 1
                        });
                    },
                    index === 0
                ),
                actionButton(
                    context,
                    'Bajar nodo',
                    'data-blog-inline-action',
                    'down',
                    function () {
                        move(content, index, index + 1);
                        render(context);
                        focusAfterRender(context, {
                            kind: 'inline',
                            owner: ownerId,
                            index: index + 1
                        });
                    },
                    index === content.length - 1
                ),
                actionButton(
                    context,
                    'Eliminar nodo',
                    'data-blog-inline-action',
                    'remove',
                    function () {
                        content.splice(index, 1);
                        render(context);
                        focusAfterRender(context, {
                            kind: 'inline',
                            owner: ownerId,
                            index: Math.min(index, content.length - 1)
                        });
                    },
                    content.length <= 1
                )
            );
            fieldset.append(actions);
            item.append(fieldset);
            list.append(item);
        });

        var toolbar = element('div', 'blogEditor__inlineToolbar');
        [
            ['text', 'Añadir texto'],
            ['link', 'Añadir enlace']
        ].concat(allowBreak ? [['break', 'Añadir salto']] : [])
            .forEach(function (definition) {
                toolbar.append(actionButton(
                    context,
                    definition[1],
                    'data-blog-add-inline',
                    definition[0],
                    function () {
                        if (content.length >= MAX_INLINE_NODES) {
                            announce(context, 'Se alcanzó el máximo de nodos.', true);
                            return;
                        }
                        if (definition[0] === 'break') {
                            content.push({ type: 'break' });
                        } else if (definition[0] === 'link') {
                            content.push({
                                type: 'link',
                                text: 'Nuevo enlace',
                                marks: [],
                                href: '/',
                                title: null,
                                target: 'same'
                            });
                        } else {
                            content.push(textNode('Nuevo texto'));
                        }
                        render(context);
                        focusAfterRender(context, {
                            kind: 'inline',
                            owner: ownerId,
                            index: content.length - 1
                        });
                    },
                    content.length >= MAX_INLINE_NODES
                ));
            });

        editor.append(list, toolbar);
        return editor;
    }

    function move(items, from, to) {
        if (to < 0 || to >= items.length || from === to) {
            return;
        }
        var moved = items.splice(from, 1)[0];
        items.splice(to, 0, moved);
    }

    function renderLinkFields(context, value) {
        var fragment = document.createDocumentFragment();
        fragment.append(
            field(
                'Texto del enlace',
                inputControl(
                    context,
                    'link-label',
                    value.label,
                    function (next) { value.label = next; },
                    { maxLength: 255 }
                )
            ),
            field(
                'URL',
                inputControl(
                    context,
                    'link-href',
                    value.href,
                    function (next) { value.href = next; },
                    { maxLength: 2048 }
                )
            ),
            field(
                'Title opcional',
                inputControl(
                    context,
                    'link-title',
                    value.title,
                    function (next) { value.title = nullable(next); },
                    { maxLength: 500 }
                )
            ),
            field(
                'Destino',
                selectControl(
                    context,
                    'link-target',
                    value.target,
                    [
                        { value: 'same', label: 'Misma ventana' },
                        { value: 'new', label: 'Nueva ventana' }
                    ],
                    function (next) { value.target = next; }
                )
            )
        );
        return fragment;
    }

    function headingLevelAllowed(context, blockIndex, level) {
        var candidate = JSON.parse(JSON.stringify(context.documentValue));
        candidate.blocks[blockIndex].level = level;
        return validDocument(candidate);
    }

    function renderListEditor(context, block) {
        var editor = element('div', 'blogEditor__listEditor');
        editor.append(field(
            'Lista ordenada',
            checkboxControl(
                context,
                'list-ordered',
                block.ordered,
                function (checked) { block.ordered = checked; }
            )
        ));
        var list = element('ol', 'blogEditor__listItems');
        block.items.forEach(function (itemValue, itemIndex) {
            var item = element('li', 'blogEditor__listItem');
            item.dataset.blogListItemId = itemValue.id;
            item.tabIndex = -1;
            var fieldset = document.createElement('fieldset');
            fieldset.append(element(
                'legend',
                '',
                'Elemento ' + (itemIndex + 1)
            ));
            fieldset.append(renderInlineEditor(
                context,
                itemValue.content,
                true,
                'Contenido del elemento',
                itemValue.id
            ));
            var actions = element('div', 'blogEditor__actions');
            actions.append(
                actionButton(
                    context,
                    'Subir elemento',
                    'data-blog-list-action',
                    'up',
                    function () {
                        move(block.items, itemIndex, itemIndex - 1);
                        render(context);
                        focusAfterRender(context, {
                            kind: 'list-item',
                            id: itemValue.id
                        });
                    },
                    itemIndex === 0
                ),
                actionButton(
                    context,
                    'Bajar elemento',
                    'data-blog-list-action',
                    'down',
                    function () {
                        move(block.items, itemIndex, itemIndex + 1);
                        render(context);
                        focusAfterRender(context, {
                            kind: 'list-item',
                            id: itemValue.id
                        });
                    },
                    itemIndex === block.items.length - 1
                ),
                actionButton(
                    context,
                    'Eliminar elemento',
                    'data-blog-list-action',
                    'remove',
                    function () {
                        var adjacent = block.items[itemIndex + 1]
                            || block.items[itemIndex - 1];
                        block.items.splice(itemIndex, 1);
                        render(context);
                        focusAfterRender(context, adjacent
                            ? { kind: 'list-item', id: adjacent.id }
                            : { kind: 'block', id: block.id });
                    },
                    block.items.length <= 1
                )
            );
            fieldset.append(actions);
            item.append(fieldset);
            list.append(item);
        });
        var add = actionButton(
            context,
            'Añadir elemento',
            'data-blog-add-list-item',
            'add',
            function () {
                if (block.items.length >= MAX_LIST_ITEMS) {
                    announce(context, 'Se alcanzó el máximo de elementos.', true);
                    return;
                }
                block.items.push({
                    id: uniqueUuid(context),
                    content: [textNode('Nuevo elemento')]
                });
                render(context);
                focusAfterRender(context, {
                    kind: 'list-item',
                    id: block.items[block.items.length - 1].id
                });
            },
            block.items.length >= MAX_LIST_ITEMS
        );
        editor.append(list, add);
        return editor;
    }

    function renderImageFields(context, block, blockIndex) {
        var fragment = document.createDocumentFragment();
        var mediaOptions = context.media.map(function (option) {
            return { value: option.publicId, label: option.label };
        });
        if (!mediaOptions.some(function (option) {
            return option.value === block.media_asset_public_id;
        })) {
            mediaOptions.unshift({
                value: block.media_asset_public_id,
                label: 'Imagen actual'
            });
        }
        fragment.append(field(
            'Imagen de la biblioteca',
            selectControl(
                context,
                'image-media',
                block.media_asset_public_id,
                mediaOptions,
                function (value) { block.media_asset_public_id = value; }
            )
        ));
        var decorative = checkboxControl(
            context,
            'image-decorative',
            block.decorative,
            function (checked) {
                block.decorative = checked;
                if (checked) {
                    block.alt = '';
                } else if (block.alt === '') {
                    block.alt = 'Descripción de la imagen';
                }
                render(context);
                focusAfterRender(context, { kind: 'block', id: block.id });
            }
        );
        fragment.append(field('Imagen decorativa', decorative));
        fragment.append(field(
            'Texto alternativo',
            inputControl(
                context,
                'image-alt',
                block.alt,
                function (value) { block.alt = value; },
                { maxLength: 500, disabled: block.decorative }
            )
        ));
        fragment.append(field(
            'Title opcional',
            inputControl(
                context,
                'image-title',
                block.title,
                function (value) { block.title = nullable(value); },
                { maxLength: 500 }
            )
        ));
        fragment.append(field(
            'Pie opcional',
            inputControl(
                context,
                'image-caption',
                block.caption,
                function (value) { block.caption = nullable(value); },
                { maxLength: 2000, multiline: true, rows: 2 }
            )
        ));
        var isCover = context.documentValue.template === TEMPLATE_COVER
            && blockIndex === 0;
        var displayOptions = isCover
            ? [{ value: 'cover', label: 'Portada' }]
            : [
                { value: 'content', label: 'Contenido' },
                { value: 'wide', label: 'Ancha' }
            ];
        fragment.append(field(
            'Presentación',
            selectControl(
                context,
                'image-display',
                block.display,
                displayOptions,
                function (value) { block.display = value; },
                isCover
            )
        ));
        return fragment;
    }

    function renderBlockFields(context, block, blockIndex) {
        var fragment = document.createDocumentFragment();
        if (block.type === 'paragraph') {
            fragment.append(renderInlineEditor(
                context,
                block.content,
                true,
                'Contenido del párrafo',
                block.id
            ));
        } else if (block.type === 'heading') {
            fragment.append(field(
                'Nivel del encabezado',
                selectControl(
                    context,
                    'heading-level',
                    String(block.level),
                    [2, 3, 4, 5, 6].map(function (level) {
                        return {
                            value: String(level),
                            label: 'H' + level,
                            disabled: !headingLevelAllowed(
                                context,
                                blockIndex,
                                level
                            )
                        };
                    }),
                    function (value) {
                        var level = Number(value);
                        block.level = Number.isInteger(level)
                            && level >= 2
                            && level <= 6
                            ? level
                            : 2;
                    }
                )
            ));
            fragment.append(renderInlineEditor(
                context,
                block.content,
                false,
                'Texto del encabezado',
                block.id
            ));
        } else if (block.type === 'list') {
            fragment.append(renderListEditor(context, block));
        } else if (block.type === 'callout') {
            fragment.append(field(
                'Tono',
                selectControl(
                    context,
                    'callout-tone',
                    block.tone,
                    [
                        { value: 'neutral', label: 'Neutral' },
                        { value: 'info', label: 'Información' },
                        { value: 'warning', label: 'Aviso' }
                    ],
                    function (value) { block.tone = value; }
                )
            ));
            fragment.append(renderInlineEditor(
                context,
                block.content,
                true,
                'Contenido destacado',
                block.id
            ));
        } else if (block.type === 'link') {
            fragment.append(renderLinkFields(context, block));
        } else if (block.type === 'image') {
            fragment.append(renderImageFields(context, block, blockIndex));
        } else if (block.type === 'video') {
            fragment.append(
                field(
                    'ID de YouTube',
                    inputControl(
                        context,
                        'video-id',
                        block.video_id,
                        function (value) { block.video_id = value; },
                        { maxLength: 11, pattern: '[A-Za-z0-9_-]{11}' }
                    )
                ),
                field(
                    'Título accesible',
                    inputControl(
                        context,
                        'video-title',
                        block.title,
                        function (value) { block.title = value; },
                        { maxLength: 500 }
                    )
                ),
                field(
                    'Segundo de inicio',
                    inputControl(
                        context,
                        'video-start',
                        block.start_seconds,
                        function (value) {
                            var number = Number(value);
                            block.start_seconds = Number.isInteger(number)
                                ? number
                                : 0;
                        },
                        { type: 'number', min: 0, max: 86400, inputMode: 'numeric' }
                    )
                )
            );
        } else if (block.type === 'cta') {
            fragment.append(renderLinkFields(context, block));
            fragment.append(field(
                'Variante',
                selectControl(
                    context,
                    'cta-variant',
                    block.variant,
                    [
                        { value: 'primary', label: 'Principal' },
                        { value: 'secondary', label: 'Secundaria' }
                    ],
                    function (value) { block.variant = value; }
                )
            ));
        }
        return fragment;
    }

    function appendInlinePreview(container, content) {
        content.forEach(function (nodeValue) {
            if (nodeValue.type === 'break') {
                container.append(document.createElement('br'));
                return;
            }

            var child = document.createTextNode(nodeValue.text);
            (nodeValue.marks || []).slice().reverse().forEach(function (mark) {
                var wrapper = document.createElement(mark === 'strong' ? 'strong' : 'em');
                wrapper.append(child);
                child = wrapper;
            });
            if (nodeValue.type === 'link') {
                var link = document.createElement('a');
                link.href = nodeValue.href;
                if (nodeValue.title) {
                    link.title = nodeValue.title;
                }
                if (nodeValue.target === 'new') {
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                }
                link.addEventListener('click', function (event) {
                    event.preventDefault();
                });
                link.append(child);
                child = link;
            }
            container.append(child);
        });
    }

    function mediaOption(context, publicId) {
        return context.media.find(function (option) {
            return option.publicId === publicId;
        }) || null;
    }

    function blockPreview(context, block) {
        var preview;
        if (block.type === 'paragraph') {
            preview = element('p', 'blogEditor__previewParagraph');
            appendInlinePreview(preview, block.content);
            return preview;
        }
        if (block.type === 'heading') {
            preview = element('div', 'blogEditor__previewHeading');
            preview.setAttribute('role', 'heading');
            preview.setAttribute('aria-level', String(block.level));
            preview.dataset.semanticTag = 'h' + block.level;
            appendInlinePreview(preview, block.content);
            return preview;
        }
        if (block.type === 'list') {
            preview = element(block.ordered ? 'ol' : 'ul', 'blogEditor__previewList');
            block.items.forEach(function (itemValue) {
                var item = document.createElement('li');
                appendInlinePreview(item, itemValue.content);
                preview.append(item);
            });
            return preview;
        }
        if (block.type === 'callout') {
            preview = element(
                'aside',
                'blogEditor__previewCallout blogEditor__previewCallout--' + block.tone
            );
            appendInlinePreview(preview, block.content);
            return preview;
        }
        if (block.type === 'link' || block.type === 'cta') {
            preview = element(
                'a',
                block.type === 'cta'
                    ? 'blogEditor__previewCta blogEditor__previewCta--' + block.variant
                    : 'blogEditor__previewLink',
                block.label
            );
            preview.href = block.href;
            if (block.title) {
                preview.title = block.title;
            }
            preview.addEventListener('click', function (event) {
                event.preventDefault();
            });
            return preview;
        }
        if (block.type === 'image') {
            preview = element('figure', 'blogEditor__previewImage');
            preview.dataset.display = block.display;
            var media = mediaOption(context, block.media_asset_public_id);
            if (media && media.thumbnailUrl) {
                var image = document.createElement('img');
                image.src = media.thumbnailUrl;
                image.alt = block.decorative ? '' : block.alt;
                image.loading = block.display === 'cover' ? 'eager' : 'lazy';
                preview.append(image);
            } else {
                preview.append(element(
                    'div',
                    'blogEditor__mediaPlaceholder',
                    media ? media.label : 'Imagen de la biblioteca'
                ));
            }
            if (block.caption) {
                preview.append(element('figcaption', '', block.caption));
            }
            return preview;
        }
        if (block.type === 'video') {
            preview = element('figure', 'blogEditor__previewVideo');
            var frame = element('div', 'blogEditor__videoPlaceholder');
            frame.append(
                element('span', '', 'YouTube'),
                element('strong', '', block.title)
            );
            preview.append(frame);
            return preview;
        }

        return element('p', '', BLOCK_LABELS[block.type] || 'Bloque');
    }

    function semanticBlockLabel(block) {
        if (block.type === 'heading' && block.level === 2) {
            return 'sección';
        }
        if (block.type === 'heading' && block.level === 3) {
            return 'artículo';
        }
        if (block.type === 'heading') {
            return 'apartado H' + block.level;
        }
        return 'bloque';
    }

    function semanticRange(blocks, blockIndex) {
        var block = blocks[blockIndex];
        var end = blockIndex + 1;
        if (block && block.type === 'heading') {
            while (end < blocks.length) {
                var candidate = blocks[end];
                if (
                    candidate.type === 'heading'
                    && candidate.level <= block.level
                ) {
                    break;
                }
                end += 1;
            }
        }
        return { start: blockIndex, end: end };
    }

    function semanticOwner(blocks, blockIndex) {
        var headings = [];
        blocks.slice(0, blockIndex).forEach(function (block) {
            if (block.type === 'heading') {
                headings = headings.filter(function (heading) {
                    return heading.level < block.level;
                });
                headings.push({ id: block.id, level: block.level });
            }
        });
        return headings.map(function (heading) {
            return heading.level + ':' + heading.id;
        }).join('|');
    }

    function semanticMoveTarget(blocks, blockIndex, direction) {
        var block = blocks[blockIndex];
        if (block.type === 'heading') {
            var parentStart = -1;
            var parentLevel = 1;
            for (var previous = blockIndex - 1; previous >= 0; previous -= 1) {
                if (
                    blocks[previous].type === 'heading'
                    && blocks[previous].level < block.level
                ) {
                    parentStart = previous;
                    parentLevel = blocks[previous].level;
                    break;
                }
            }
            var scopeEnd = blocks.length;
            for (var next = blockIndex + 1; next < blocks.length; next += 1) {
                if (
                    blocks[next].type === 'heading'
                    && blocks[next].level <= parentLevel
                ) {
                    scopeEnd = next;
                    break;
                }
            }
            var peers = [];
            for (
                var headingIndex = parentStart + 1;
                headingIndex < scopeEnd;
                headingIndex += 1
            ) {
                if (
                    blocks[headingIndex].type === 'heading'
                    && blocks[headingIndex].level === block.level
                ) {
                    peers.push(headingIndex);
                }
            }
            var peerPosition = peers.indexOf(blockIndex);
            var peerTarget = peers[peerPosition + direction];
            return peerTarget === undefined
                ? null
                : semanticRange(blocks, peerTarget);
        }

        var targetIndex = blockIndex + direction;
        if (
            targetIndex < 0
            || targetIndex >= blocks.length
            || semanticOwner(blocks, targetIndex)
                !== semanticOwner(blocks, blockIndex)
            || blocks[targetIndex].type === 'heading'
        ) {
            return null;
        }
        return { start: targetIndex, end: targetIndex + 1 };
    }

    function moveSemanticGroup(context, blockIndex, direction) {
        var blocks = context.documentValue.blocks;
        var current = semanticRange(blocks, blockIndex);
        var target = semanticMoveTarget(blocks, blockIndex, direction);
        if (!target) {
            return false;
        }
        var reordered;
        if (direction < 0) {
            reordered = blocks.slice(0, target.start)
                .concat(
                    blocks.slice(current.start, current.end),
                    blocks.slice(target.end, current.start),
                    blocks.slice(target.start, target.end),
                    blocks.slice(current.end)
                );
        } else {
            reordered = blocks.slice(0, current.start)
                .concat(
                    blocks.slice(target.start, target.end),
                    blocks.slice(current.end, target.start),
                    blocks.slice(current.start, current.end),
                    blocks.slice(target.end)
                );
        }
        blocks.splice(0, blocks.length);
        Array.prototype.push.apply(blocks, reordered);
        return true;
    }

    function activateInspectorTab(context, tabName) {
        if (!context.inspectorRoot) {
            return;
        }
        context.inspectorRoot.querySelectorAll('[data-blog-inspector-tab]')
            .forEach(function (button) {
                var active = button.dataset.blogInspectorTab === tabName;
                button.setAttribute('aria-selected', active ? 'true' : 'false');
                button.tabIndex = active ? 0 : -1;
            });
        context.inspectorRoot.querySelectorAll('[data-blog-inspector-panel]')
            .forEach(function (panel) {
                panel.hidden = panel.dataset.blogInspectorPanel !== tabName;
            });
    }

    function openInspector(context) {
        var shell = document.querySelector('[data-webadmin-shell]');
        if (shell instanceof HTMLElement) {
            if (
                shell.dataset.webadminShellBound === 'true'
                && typeof window.CustomEvent === 'function'
            ) {
                shell.dispatchEvent(new window.CustomEvent(
                    'webadmin:open-inspector',
                    { bubbles: false }
                ));
                return;
            }
            shell.dataset.webadminInspectorOpen = 'true';
            var inspector = shell.querySelector('[data-webadmin-shell-inspector]');
            if (inspector instanceof HTMLElement) {
                inspector.removeAttribute('inert');
                inspector.setAttribute('aria-hidden', 'false');
            }
            var toggle = shell.querySelector('[data-webadmin-inspector-toggle]');
            if (toggle instanceof HTMLButtonElement) {
                toggle.setAttribute('aria-expanded', 'true');
            }
        }
    }

    function focusSelectedInspector(context) {
        if (!context.blockInspector) {
            return;
        }
        window.requestAnimationFrame(function () {
            var target = context.blockInspector.querySelector(
                '[data-blog-field]:not([disabled])'
            ) || context.blockInspector.querySelector(
                '.blogEditor__inspectorTitle'
            );
            if (target instanceof HTMLElement) {
                if (!target.hasAttribute('tabindex') && !target.matches(
                    'input, select, textarea, button, a[href]'
                )) {
                    target.tabIndex = -1;
                }
                target.focus();
            }
        });
    }

    function selectBlock(context, blockId) {
        context.selectedBlockId = blockId;
        renderInspector(context);
        refreshVisualCanvas(context);
        activateInspectorTab(context, 'block');
        openInspector(context);
        focusSelectedInspector(context);
    }

    function visualActions(context, block, blockIndex) {
        var label = semanticBlockLabel(block);
        var positionLabel = label + ' ' + (blockIndex + 1);
        var actions = element('div', 'blogEditor__visualActions');
        actions.setAttribute('role', 'group');
        actions.setAttribute('aria-label', 'Acciones de ' + positionLabel);
        actions.append(
            actionButton(
                context,
                'Editar ' + positionLabel,
                'data-blog-action',
                'edit',
                function () {
                    selectBlock(context, block.id);
                },
                false,
                true
            ),
            actionButton(
                context,
                'Subir ' + positionLabel,
                'data-blog-action',
                'up',
                function () {
                    if (moveSemanticGroup(context, blockIndex, -1)) {
                        normalizeContracts(context, true);
                        render(context);
                        focusAfterRender(context, { kind: 'block', id: block.id });
                    }
                },
                semanticMoveTarget(context.documentValue.blocks, blockIndex, -1) === null
            ),
            actionButton(
                context,
                'Bajar ' + positionLabel,
                'data-blog-action',
                'down',
                function () {
                    if (moveSemanticGroup(context, blockIndex, 1)) {
                        normalizeContracts(context, true);
                        render(context);
                        focusAfterRender(context, { kind: 'block', id: block.id });
                    }
                },
                semanticMoveTarget(context.documentValue.blocks, blockIndex, 1) === null
            ),
            actionButton(
                context,
                'Eliminar ' + positionLabel,
                'data-blog-action',
                'remove',
                function () {
                    if (!window.confirm('¿Eliminar este ' + label + ' y su contenido?')) {
                        return;
                    }
                    var range = semanticRange(context.documentValue.blocks, blockIndex);
                    var adjacent = context.documentValue.blocks[range.end]
                        || context.documentValue.blocks[range.start - 1];
                    context.documentValue.blocks.splice(
                        range.start,
                        range.end - range.start
                    );
                    context.selectedBlockId = adjacent ? adjacent.id : null;
                    normalizeContracts(context, true);
                    render(context);
                    if (adjacent) {
                        focusAfterRender(context, { kind: 'block', id: adjacent.id });
                    }
                    announce(context, 'Contenido eliminado.', false);
                },
                false
            )
        );
        return actions;
    }

    function visualBlock(context, block, blockIndex) {
        var item = element(
            'div',
            'blogEditor__visualBlock blogEditor__visualBlock--' + block.type
        );
        item.dataset.blockId = block.id;
        item.dataset.blockType = block.type;
        item.dataset.selected = context.selectedBlockId === block.id
            ? 'true'
            : 'false';
        if (block.type === 'heading') {
            item.dataset.headingLevel = String(block.level);
        }
        var label = element(
            'span',
            'blogEditor__visualLabel',
            block.type === 'heading'
                ? 'H' + block.level + ' · ' + semanticBlockLabel(block)
                : BLOCK_LABELS[block.type]
        );
        var edit = visualActions(context, block, blockIndex);
        var preview = element('div', 'blogEditor__visualContent');
        preview.append(blockPreview(context, block));
        item.append(label, preview, edit);
        var editButton = edit.querySelector('[data-blog-action="edit"]');
        if (editButton instanceof HTMLButtonElement) {
            editButton.dataset.blogBlockTitle = block.id;
        }
        return item;
    }

    function refreshVisualCanvas(context) {
        var blocks = context.documentValue.blocks;
        context.blockList.replaceChildren();
        var post = element('div', 'blogEditor__postPreview');
        post.setAttribute('role', 'document');
        var header = element('div', 'blogEditor__postHeader');
        header.dataset.semanticTag = 'header';
        var locale = element(
            'p',
            'blogEditor__localeBadge',
            'Idioma: ' + context.locale.toUpperCase()
        );
        var h1 = element(
            'div',
            'blogEditor__postTitle',
            context.h1Input ? context.h1Input.value : 'Título del artículo'
        );
        h1.setAttribute('role', 'heading');
        h1.setAttribute('aria-level', '1');
        h1.dataset.semanticTag = 'h1';
        header.append(locale, h1);

        var firstContentIndex = 0;
        if (
            context.documentValue.template === TEMPLATE_COVER
            && blocks[0]
            && blocks[0].type === 'image'
        ) {
            header.append(visualBlock(context, blocks[0], 0));
            firstContentIndex = 1;
        }
        post.append(header);

        var main = element('div', 'blogEditor__postMain');
        main.dataset.semanticTag = 'main';
        var currentSection = null;
        var currentArticle = null;
        for (var index = firstContentIndex; index < blocks.length; index += 1) {
            var block = blocks[index];
            if (block.type === 'heading' && block.level === 2) {
                currentSection = element('div', 'blogEditor__previewSection');
                currentSection.dataset.semanticTag = 'section';
                currentArticle = null;
                main.append(currentSection);
                currentSection.append(visualBlock(context, block, index));
                continue;
            }
            if (block.type === 'heading' && block.level === 3) {
                currentSection = currentSection || main;
                currentArticle = element('div', 'blogEditor__previewArticle');
                currentArticle.dataset.semanticTag = 'article';
                currentSection.append(currentArticle);
                currentArticle.append(visualBlock(context, block, index));
                continue;
            }
            (currentArticle || currentSection || main).append(
                visualBlock(context, block, index)
            );
        }
        if (blocks.length === firstContentIndex) {
            main.append(element(
                'p',
                'blogEditor__empty',
                'Añade una sección H2 o contenido para empezar a construir.'
            ));
        }
        post.append(main);
        context.blockList.append(post);
    }

    function renderInspector(context) {
        if (!context.blockInspector) {
            return;
        }
        context.blockInspector.replaceChildren();
        var blockIndex = context.documentValue.blocks.findIndex(function (block) {
            return block.id === context.selectedBlockId;
        });
        if (blockIndex < 0) {
            context.blockInspector.append(element(
                'p',
                'blogEditor__inspectorEmpty',
                'Selecciona Editar en un bloque de la vista para cambiarlo.'
            ));
            return;
        }
        var block = context.documentValue.blocks[blockIndex];
        var heading = element(
            'h3',
            'blogEditor__inspectorTitle',
            'Editar ' + semanticBlockLabel(block)
        );
        heading.id = 'blog-editor-selected-block-' + context.instance;
        var fieldset = document.createElement('fieldset');
        fieldset.disabled = context.readOnly;
        fieldset.setAttribute('aria-labelledby', heading.id);
        fieldset.append(renderBlockFields(context, block, blockIndex));
        context.blockInspector.append(heading, fieldset);
    }

    function render(context) {
        normalizeContracts(context, false);
        context.controlNumber = 0;
        if (
            context.selectedBlockId
            && !context.documentValue.blocks.some(function (block) {
                return block.id === context.selectedBlockId;
            })
        ) {
            context.selectedBlockId = null;
        }
        refreshVisualCanvas(context);
        renderInspector(context);
        sync(context);
    }

    function readMedia(catalog) {
        if (!(catalog instanceof HTMLSelectElement)) {
            return [];
        }
        var seen = new Set();
        var media = [];
        Array.from(catalog.options).forEach(function (option) {
            var publicId = String(option.value);
            if (!UUID_V4.test(publicId) || seen.has(publicId)) {
                return;
            }
            seen.add(publicId);
            media.push({
                publicId: publicId,
                label: String(option.textContent || 'Imagen'),
                thumbnailUrl: safeRootRelativeUrl(
                    option.dataset.thumbnailUrl || ''
                ) ? option.dataset.thumbnailUrl : null
            });
        });
        return media;
    }

    function seoElement(tag, className, value) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (value !== undefined) {
            node.textContent = String(value);
        }
        return node;
    }

    function validSeoAnalysis(value) {
        return value
            && typeof value === 'object'
            && value.schema === 'liquidstack.blog.seo-analysis'
            && value.version === 1
            && value.advisory === true
            && value.summary
            && typeof value.summary === 'object'
            && Array.isArray(value.checks)
            && value.serp_preview
            && typeof value.serp_preview === 'object'
            && Array.isArray(value.competing_pages);
    }

    function renderSeoAnalysis(results, analysis) {
        var fragment = document.createDocumentFragment();
        var summary = seoElement('div', 'blogEditor__seoSummary');
        summary.setAttribute('aria-label', 'Resumen SEO');
        [
            ['good', 'Bien'],
            ['review', 'Revisar'],
            ['pending', 'Pendiente']
        ].forEach(function (definition) {
            var item = seoElement(
                'span',
                '',
                definition[1] + ': ' + Number(
                    analysis.summary[definition[0]] || 0
                )
            );
            item.dataset.status = definition[0];
            summary.append(item);
        });
        fragment.append(summary);

        var preview = seoElement('article', 'blogEditor__serp');
        var previewTitle = seoElement(
            'h3',
            '',
            'Vista previa SERP ('
                + String(analysis.serp_preview.locale || '') + ')'
        );
        preview.append(
            previewTitle,
            seoElement(
                'p',
                'blogEditor__serpTitle',
                analysis.serp_preview.title || ''
            ),
            seoElement(
                'p',
                'blogEditor__serpUrl',
                analysis.serp_preview.url || ''
            ),
            seoElement('p', '', analysis.serp_preview.description || '')
        );
        fragment.append(preview);

        var checks = seoElement('ul', 'blogEditor__seoChecks');
        analysis.checks.forEach(function (check) {
            if (!check || typeof check !== 'object') {
                return;
            }
            var status = ['good', 'review', 'pending'].includes(check.status)
                ? check.status
                : 'pending';
            var item = seoElement('li');
            item.dataset.status = status;
            var heading = seoElement('p');
            heading.append(seoElement(
                'strong',
                '',
                String(check.status_label || '') + ': '
                    + String(check.label || '')
            ));
            item.append(
                heading,
                seoElement('p', '', check.message || '')
            );
            checks.append(item);
        });
        fragment.append(checks);

        if (analysis.competing_pages.length > 0) {
            var details = seoElement('details', 'blogEditor__seoCompetition');
            details.append(seoElement('summary', '', 'URLs a revisar'));
            var list = seoElement('ul');
            analysis.competing_pages.slice(0, 5).forEach(function (page) {
                if (!page || typeof page !== 'object') {
                    return;
                }
                var item = seoElement('li');
                item.append(
                    seoElement('code', '', page.url || ''),
                    document.createTextNode(
                        ' — ' + String(page.h1 || '') + ' ('
                        + (page.match === 'complete'
                            ? 'coincidencia completa'
                            : 'coincidencia parcial') + ')'
                    )
                );
                list.append(item);
            });
            details.append(list);
            fragment.append(details);
        }
        results.replaceChildren(fragment);
    }

    function initSeoAnalysis(context) {
        var panel = document.querySelector(
            '[data-blog-seo-panel][data-blog-editor-form="'
                + context.form.id + '"]'
        );
        if (!(panel instanceof HTMLElement) || context.readOnly) {
            return;
        }
        var endpoint = panel.dataset.blogSeoEndpoint || '';
        var results = panel.querySelector('[data-blog-seo-results]');
        var live = panel.querySelector('[data-blog-seo-live]');
        if (
            !safeRootRelativeUrl(endpoint)
            || !(results instanceof HTMLElement)
            || !(live instanceof HTMLElement)
        ) {
            return;
        }

        var timer = 0;
        var activeController = null;
        var requestVersion = 0;

        function run() {
            sync(context);
            requestVersion += 1;
            var version = requestVersion;
            if (activeController) {
                activeController.abort();
            }
            activeController = typeof AbortController === 'function'
                ? new AbortController()
                : null;
            var body = new URLSearchParams();
            new FormData(context.form).forEach(function (value, key) {
                if (typeof value === 'string') {
                    body.append(key, value);
                }
            });
            live.textContent = 'Analizando cambios…';

            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: body.toString(),
                signal: activeController ? activeController.signal : undefined
            }).then(function (response) {
                return response.json().then(function (payload) {
                    return { response: response, payload: payload };
                });
            }).then(function (result) {
                if (version !== requestVersion) {
                    return;
                }
                if (!result.response.ok || !validSeoAnalysis(result.payload)) {
                    throw new Error('analysis-unavailable');
                }
                renderSeoAnalysis(results, result.payload);
                live.textContent = 'Análisis actualizado. Los avisos no bloquean.';
            }).catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }
                if (version === requestVersion) {
                    live.textContent = 'No se pudo actualizar el análisis. Puedes guardar igualmente.';
                }
            });
        }

        function schedule() {
            window.clearTimeout(timer);
            requestVersion += 1;
            if (activeController) {
                activeController.abort();
                activeController = null;
            }
            live.textContent = 'Cambios pendientes de analizar…';
            timer = window.setTimeout(run, 650);
        }
        function scheduleFromAction(event) {
            if (
                event.target instanceof Element
                && event.target.closest(
                    '[data-blog-add-block], [data-blog-action], '
                    + '[data-blog-inline-action], [data-blog-list-action], '
                    + '[data-blog-add-inline], [data-blog-add-list-item]'
                )
            ) {
                schedule();
            }
        }
        context.form.addEventListener('input', schedule);
        context.form.addEventListener('change', schedule);
        if (context.inspectorRoot) {
            context.inspectorRoot.addEventListener('input', schedule);
            context.inspectorRoot.addEventListener('change', schedule);
            context.inspectorRoot.addEventListener('click', scheduleFromAction);
        }
        context.form.addEventListener('click', scheduleFromAction);
    }

    function disableSubmission(form, status, message) {
        Array.from(form.elements).forEach(function (control) {
            if (
                'disabled' in control
                && !(control instanceof HTMLInputElement && control.type === 'hidden')
            ) {
                control.disabled = true;
            }
        });
        form.addEventListener('submit', function (event) {
            event.preventDefault();
        }, { capture: true });
        status.textContent = message;
        status.dataset.state = 'error';
    }

    function formBody(form) {
        var body = new URLSearchParams();
        new FormData(form).forEach(function (value, key) {
            if (typeof value === 'string') {
                body.append(key, value);
            }
        });
        return body;
    }

    function formFingerprint(form) {
        return formBody(form).toString();
    }

    function isExpectedEditorRedirect(form, response) {
        try {
            var submitted = new URL(form.action, window.location.href);
            var destination = new URL(response.url, window.location.href);
            var post = form.elements.namedItem('post');
            var locale = form.elements.namedItem('locale');
            var destinationKeys = Array.from(
                destination.searchParams.keys()
            ).sort();
            return response.redirected === true
                && response.ok === true
                && destination.origin === submitted.origin
                && destination.pathname
                    === submitted.pathname.replace(/\/save$/u, '')
                && destination.hash === ''
                && destinationKeys.join(',') === 'locale,post'
                && destination.searchParams.get('post')
                    === (post instanceof HTMLInputElement ? post.value : '')
                && destination.searchParams.get('locale')
                    === (locale instanceof HTMLInputElement ? locale.value : '');
        } catch (error) {
            return false;
        }
    }

    function isExpectedCategoryRedirect(form, response) {
        try {
            var submitted = new URL(form.action, window.location.href);
            var destination = new URL(response.url, window.location.href);
            return response.redirected === true
                && response.ok === true
                && destination.origin === submitted.origin
                && destination.pathname
                    === submitted.pathname.replace(/\/assign$/u, '/updated')
                && destination.search === ''
                && destination.hash === '';
        } catch (error) {
            return false;
        }
    }

    function initCategoryAssignment(context) {
        if (!context.inspectorRoot) {
            return;
        }
        var form = context.inspectorRoot.querySelector(
            '[data-blog-category-assignment-form]'
        );
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        var status = form.querySelector(
            '[data-blog-category-assignment-status]'
        );
        var submit = form.querySelector('button[type="submit"]');
        if (!(status instanceof HTMLElement)) {
            return;
        }
        form.addEventListener('submit', function (event) {
            if (typeof window.fetch !== 'function') {
                return;
            }
            event.preventDefault();
            if (form.getAttribute('aria-busy') === 'true') {
                return;
            }
            form.setAttribute('aria-busy', 'true');
            if (submit instanceof HTMLButtonElement) {
                submit.disabled = true;
            }
            status.textContent = 'Guardando categorías…';
            status.dataset.state = 'pending';

            window.fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'text/html',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: formBody(form).toString(),
                redirect: 'follow'
            }).then(function (response) {
                if (!isExpectedCategoryRedirect(form, response)) {
                    throw new Error('category-save-failed');
                }
                status.textContent = '';
                window.requestAnimationFrame(function () {
                    status.textContent = 'Categorías guardadas.';
                    status.dataset.state = 'ok';
                });
            }).catch(function () {
                status.textContent = '';
                window.requestAnimationFrame(function () {
                    status.textContent = 'No se pudieron guardar las categorías. La selección sigue disponible para reintentar.';
                    status.dataset.state = 'error';
                });
            }).finally(function () {
                form.removeAttribute('aria-busy');
                if (submit instanceof HTMLButtonElement) {
                    submit.disabled = false;
                }
            });
        });
    }

    function initEditor(form) {
        if (!(form instanceof HTMLFormElement) || form.dataset.blogEditorBound === 'true') {
            return;
        }
        form.dataset.blogEditorBound = 'true';

        var documentInput = form.querySelector('input[name="document_json"]');
        var blockList = form.querySelector('[data-blog-block-list]');
        var inspectorRoot = document.querySelector(
            '[data-blog-inspector][data-blog-editor-form="' + form.id + '"]'
        );
        var templateSelect = document.querySelector(
            '[data-blog-template-select][form="' + form.id + '"]'
        ) || form.querySelector('[data-blog-template-select]');
        var mediaCatalog = form.querySelector('[data-blog-media-catalog]');
        var status = form.querySelector('[data-blog-editor-status]');
        var blockInspector = inspectorRoot
            ? inspectorRoot.querySelector('[data-blog-block-inspector]')
            : form.querySelector('[data-blog-block-inspector]');
        var h1Input = document.getElementById('blog-editor-h1');
        var slugInput = document.getElementById('blog-editor-slug');
        var entryIdentity = document.querySelector('[data-blog-entry-identity]');
        var publicUrl = document.querySelector('[data-blog-public-url]');
        var localeInput = form.querySelector('input[name="locale"]');
        if (
            !(documentInput instanceof HTMLInputElement)
            || !(blockList instanceof HTMLElement)
            || !(templateSelect instanceof HTMLSelectElement)
            || !(status instanceof HTMLElement)
            || !(localeInput instanceof HTMLInputElement)
        ) {
            return;
        }

        var documentValue;
        try {
            documentValue = JSON.parse(documentInput.value);
        } catch (error) {
            disableSubmission(form, status, 'El documento guardado no se puede editar.');
            return;
        }
        if (!validDocument(documentValue)) {
            disableSubmission(form, status, 'El documento guardado no supera la validación.');
            return;
        }

        instanceNumber += 1;
        var context = {
            instance: instanceNumber,
            controlNumber: 0,
            form: form,
            documentInput: documentInput,
            blockList: blockList,
            templateSelect: templateSelect,
            status: status,
            media: readMedia(mediaCatalog),
            documentValue: documentValue,
            readOnly: form.dataset.blogEditorReadonly === 'true',
            announcementVersion: 0,
            selectedBlockId: null,
            blockInspector: blockInspector instanceof HTMLElement
                ? blockInspector
                : null,
            inspectorRoot: inspectorRoot instanceof HTMLElement
                ? inspectorRoot
                : null,
            h1Input: h1Input instanceof HTMLInputElement ? h1Input : null,
            locale: localeInput.value,
            initialFingerprint: '',
            allowNavigation: false,
            savePending: false,
            invalidFocusPending: false
        };

        function updatePublicUrl() {
            if (
                !(slugInput instanceof HTMLInputElement)
                || !(entryIdentity instanceof HTMLElement)
                || !(publicUrl instanceof HTMLElement)
            ) {
                return;
            }
            var base = entryIdentity.dataset.blogPublicBase || '';
            publicUrl.textContent = base === ''
                ? 'Se completará al guardar un slug.'
                : base + '/' + (slugInput.value || '…');
        }

        if (context.inspectorRoot) {
            var inspectorTabs = Array.from(
                context.inspectorRoot.querySelectorAll(
                    '[data-blog-inspector-tab]'
                )
            );
            inspectorTabs.forEach(function (button, tabIndex) {
                    button.addEventListener('click', function () {
                        activateInspectorTab(
                            context,
                            button.dataset.blogInspectorTab || 'entry'
                        );
                    });
                    button.addEventListener('keydown', function (event) {
                        var nextIndex = null;
                        if (event.key === 'ArrowRight') {
                            nextIndex = (tabIndex + 1) % inspectorTabs.length;
                        } else if (event.key === 'ArrowLeft') {
                            nextIndex = (
                                tabIndex - 1 + inspectorTabs.length
                            ) % inspectorTabs.length;
                        } else if (event.key === 'Home') {
                            nextIndex = 0;
                        } else if (event.key === 'End') {
                            nextIndex = inspectorTabs.length - 1;
                        }
                        if (nextIndex === null) {
                            return;
                        }
                        event.preventDefault();
                        var nextTab = inspectorTabs[nextIndex];
                        activateInspectorTab(
                            context,
                            nextTab.dataset.blogInspectorTab || 'entry'
                        );
                        nextTab.focus();
                    });
                });
            activateInspectorTab(context, 'entry');
        }
        if (context.h1Input) {
            context.h1Input.addEventListener('input', function () {
                refreshVisualCanvas(context);
            });
        }
        if (slugInput instanceof HTMLInputElement) {
            slugInput.addEventListener('input', updatePublicUrl);
            updatePublicUrl();
        }

        form.querySelectorAll('[data-blog-add-block]').forEach(function (button) {
            var type = button.getAttribute('data-blog-add-block') || '';
            if (!BLOCK_TYPES.includes(type)) {
                button.disabled = true;
                return;
            }
            button.addEventListener('click', function () {
                if (
                    context.readOnly
                    || context.documentValue.blocks.length >= MAX_BLOCKS
                ) {
                    return;
                }
                try {
                    var hasSection = context.documentValue.blocks.some(
                        function (block) {
                            return block.type === 'heading' && block.level === 2;
                        }
                    );
                    var createsInitialCover = type === 'image'
                        && context.documentValue.blocks.length === 0;
                    if (
                        type !== 'heading'
                        && !hasSection
                        && !createsInitialCover
                    ) {
                        announce(
                            context,
                            'Añade primero una sección H2. El contenido nuevo debe pertenecer a una sección.',
                            true
                        );
                        return;
                    }
                    var addedBlock = makeBlock(context, type);
                    var requestedHeadingLevel = Number(
                        button.dataset.blogHeadingLevel || 0
                    );
                    if (
                        type === 'heading'
                        && [2, 3, 4, 5, 6].includes(requestedHeadingLevel)
                    ) {
                        addedBlock.level = requestedHeadingLevel;
                        var candidate = JSON.parse(JSON.stringify(
                            context.documentValue
                        ));
                        candidate.blocks.push(addedBlock);
                        if (!validDocument(candidate)) {
                            announce(
                                context,
                                requestedHeadingLevel === 3
                                    ? 'Añade primero una sección H2 para incluir un artículo H3.'
                                    : 'Completa antes la jerarquía de encabezados hasta H'
                                        + (requestedHeadingLevel - 1) + '.',
                                true
                            );
                            return;
                        }
                    }
                    if (createsInitialCover) {
                        context.documentValue.template = TEMPLATE_COVER;
                        addedBlock.display = 'cover';
                    }
                    context.documentValue.blocks.push(addedBlock);
                    context.selectedBlockId = addedBlock.id;
                    normalizeContracts(context, true);
                    render(context);
                    activateInspectorTab(context, 'block');
                    openInspector(context);
                    focusSelectedInspector(context);
                    announce(context, 'Bloque añadido.', false);
                } catch (error) {
                    announce(context, 'No se pudo añadir el bloque.', true);
                }
            });
        });

        templateSelect.addEventListener('change', function () {
            if (context.readOnly) {
                return;
            }
            var requested = templateSelect.value;
            if (![TEMPLATE_BASIC, TEMPLATE_COVER].includes(requested)) {
                templateSelect.value = context.documentValue.template;
                return;
            }
            var previous = context.documentValue.template;
            if (requested === previous) {
                return;
            }
            if (requested === TEMPLATE_BASIC && previous === TEMPLATE_COVER) {
                var firstSection = context.documentValue.blocks.findIndex(
                    function (block) {
                        return block.type === 'heading' && block.level === 2;
                    }
                );
                if (firstSection < 0) {
                    templateSelect.value = previous;
                    announce(
                        context,
                        'Añade una sección H2 antes de retirar la portada.',
                        true
                    );
                    return;
                }
                var formerCover = context.documentValue.blocks.shift();
                if (!formerCover || formerCover.type !== 'image') {
                    templateSelect.value = previous;
                    announce(context, 'La portada no está disponible.', true);
                    return;
                }
                formerCover.display = 'content';
                firstSection -= 1;
                context.documentValue.blocks.splice(
                    firstSection + 1,
                    0,
                    formerCover
                );
            } else if (
                requested === TEMPLATE_COVER
                && previous === TEMPLATE_BASIC
            ) {
                var coverCandidate = context.documentValue.blocks.findIndex(
                    function (block) { return block.type === 'image'; }
                );
                if (coverCandidate < 0) {
                    templateSelect.value = previous;
                    announce(
                        context,
                        'Añade primero una imagen al artículo para usarla como portada.',
                        true
                    );
                    return;
                }
                var promotedCover = context.documentValue.blocks.splice(
                    coverCandidate,
                    1
                )[0];
                promotedCover.display = 'cover';
                context.documentValue.blocks.unshift(promotedCover);
            }
            context.documentValue.template = requested;
            normalizeContracts(context, true);
            render(context);
            announce(context, 'Plantilla actualizada.', false);
        });

        document.addEventListener('invalid', function (event) {
            var control = event.target;
            if (
                !(control instanceof HTMLElement)
                || !('form' in control)
                || control.form !== form
            ) {
                return;
            }
            event.preventDefault();
            var panel = control.closest('[data-blog-inspector-panel]');
            activateInspectorTab(
                context,
                panel && panel.dataset.blogInspectorPanel === 'block'
                    ? 'block'
                    : 'entry'
            );
            openInspector(context);
            if (!context.invalidFocusPending) {
                context.invalidFocusPending = true;
                window.requestAnimationFrame(function () {
                    context.invalidFocusPending = false;
                    focusElement(control);
                });
            }
            announce(
                context,
                'Revisa el campo indicado antes de guardar.',
                true
            );
        }, true);

        form.addEventListener('submit', function (event) {
            normalizeContracts(context, true);
            var withinSize = sync(context);
            if (
                context.readOnly
                || !withinSize
                || !validDocument(context.documentValue)
            ) {
                event.preventDefault();
                announce(
                    context,
                    'El documento contiene campos incompletos o no válidos.',
                    true
                );
                return;
            }

            if (typeof window.fetch !== 'function') {
                context.allowNavigation = true;
                return;
            }

            event.preventDefault();
            if (context.savePending) {
                return;
            }
            context.savePending = true;
            form.setAttribute('aria-busy', 'true');
            var submitters = Array.from(form.elements).filter(function (control) {
                return control instanceof HTMLButtonElement
                    && control.type === 'submit';
            });
            submitters.forEach(function (button) { button.disabled = true; });
            announce(context, 'Guardando cambios…', false);

            window.fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'text/html',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: formBody(form).toString(),
                redirect: 'follow'
            }).then(function (response) {
                if (isExpectedEditorRedirect(form, response)) {
                    context.initialFingerprint = formFingerprint(form);
                    context.allowNavigation = true;
                    window.location.assign(response.url);
                    return;
                }
                if (response.redirected) {
                    throw new Error('save-forbidden');
                }
                if (response.status === 409) {
                    throw new Error('save-conflict');
                }
                if (response.status === 422) {
                    throw new Error('save-invalid');
                }
                if (response.status === 403) {
                    throw new Error('save-forbidden');
                }
                throw new Error('save-unavailable');
            }).catch(function (error) {
                var message = 'No se pudo guardar ahora. Tus cambios siguen en el editor.';
                if (error && error.message === 'save-conflict') {
                    message = 'El artículo cambió en otra sesión. Tus cambios siguen aquí; abre otra pestaña para revisar la versión guardada.';
                } else if (error && error.message === 'save-invalid') {
                    message = 'Revisa el contenido indicado. Tus cambios siguen en el editor.';
                } else if (error && error.message === 'save-forbidden') {
                    message = 'No se pudo validar la sesión. Tus cambios siguen en el editor.';
                }
                announce(context, message, true);
            }).finally(function () {
                context.savePending = false;
                form.removeAttribute('aria-busy');
                submitters.forEach(function (button) {
                    button.disabled = context.readOnly;
                });
            });
        });

        render(context);
        sync(context);
        context.initialFingerprint = formFingerprint(form);
        window.addEventListener('beforeunload', function (event) {
            if (
                context.allowNavigation
                || context.readOnly
                || formFingerprint(form) === context.initialFingerprint
            ) {
                return;
            }
            event.preventDefault();
            event.returnValue = '';
        });
        initSeoAnalysis(context);
        initCategoryAssignment(context);
        form.dataset.blogEditorEnhanced = 'true';
    }

    function init() {
        document.querySelectorAll('[data-blog-editor]').forEach(initEditor);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}());
