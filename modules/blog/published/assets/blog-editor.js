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
        var hasH2 = false;
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
                    || ![2, 3].includes(block.level)
                    || !validInline(block.content, false)
                    || (block.level === 3 && !hasH2)
                ) {
                    return false;
                }
                hasH2 = hasH2 || block.level === 2;
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

        if (target && target.kind === 'block') {
            candidates = context.blockList.querySelectorAll(
                '[data-blog-block-title]'
            );
            match = function (candidate) {
                return candidate.dataset.blogBlockTitle === target.id;
            };
        } else if (target && target.kind === 'inline') {
            candidates = context.blockList.querySelectorAll(
                '[data-blog-inline-owner][data-blog-inline-index]'
            );
            match = function (candidate) {
                return candidate.dataset.blogInlineOwner === target.owner
                    && candidate.dataset.blogInlineIndex
                        === String(target.index);
            };
        } else if (target && target.kind === 'list-item') {
            candidates = context.blockList.querySelectorAll(
                '[data-blog-list-item-id]'
            );
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
        });
        return control;
    }

    function selectControl(context, fieldName, value, options, update, disabled) {
        var select = document.createElement('select');
        select.id = controlId(context, fieldName);
        select.dataset.blogField = fieldName;
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
        });
        return select;
    }

    function checkboxControl(context, fieldName, checked, update, disabled) {
        var checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.id = controlId(context, fieldName);
        checkbox.dataset.blogField = fieldName;
        checkbox.checked = Boolean(checked);
        checkbox.disabled = context.readOnly || Boolean(disabled);
        checkbox.addEventListener('change', function () {
            update(checkbox.checked);
            sync(context);
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

    function actionButton(context, label, attribute, action, callback, disabled) {
        var button = element('button', '', label);
        button.type = 'button';
        button.setAttribute(attribute, action);
        button.disabled = context.readOnly || Boolean(disabled);
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

        var hasH2 = false;
        value.blocks.forEach(function (block) {
            if (block.type !== 'heading') {
                return;
            }
            if (block.level === 3 && !hasH2) {
                block.level = 2;
                changed = true;
            }
            hasH2 = hasH2 || block.level === 2;
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

    function hasPreviousH2(context, blockIndex) {
        return context.documentValue.blocks.slice(0, blockIndex)
            .some(function (block) {
                return block.type === 'heading' && block.level === 2;
            });
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
                    [
                        { value: '2', label: 'H2' },
                        {
                            value: '3',
                            label: 'H3',
                            disabled: !hasPreviousH2(context, blockIndex)
                        }
                    ],
                    function (value) {
                        block.level = value === '3' ? 3 : 2;
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

    function render(context) {
        normalizeContracts(context, false);
        context.controlNumber = 0;
        context.blockList.replaceChildren();
        if (context.documentValue.blocks.length === 0) {
            context.blockList.append(element(
                'li',
                'blogEditor__empty',
                'El documento todavía no contiene bloques.'
            ));
            sync(context);
            return;
        }

        context.documentValue.blocks.forEach(function (block, blockIndex) {
            var item = element('li', 'blogEditor__block');
            item.dataset.blockId = block.id;
            item.dataset.blockType = block.type;
            var titleId = 'blog-editor-block-title-'
                + context.instance + '-' + (blockIndex + 1);
            var title = element(
                'h3',
                'blogEditor__blockTitle',
                'Bloque ' + (blockIndex + 1) + ': ' + BLOCK_LABELS[block.type]
            );
            title.id = titleId;
            title.dataset.blogBlockTitle = block.id;
            title.tabIndex = -1;
            var fieldset = document.createElement('fieldset');
            fieldset.disabled = context.readOnly;
            fieldset.setAttribute('aria-labelledby', titleId);
            var actions = element('div', 'blogEditor__actions');
            actions.append(
                actionButton(
                    context,
                    'Subir bloque',
                    'data-blog-action',
                    'up',
                    function () {
                        move(context.documentValue.blocks, blockIndex, blockIndex - 1);
                        normalizeContracts(context, true);
                        render(context);
                        focusAfterRender(context, {
                            kind: 'block',
                            id: block.id
                        });
                    },
                    blockIndex === 0
                ),
                actionButton(
                    context,
                    'Bajar bloque',
                    'data-blog-action',
                    'down',
                    function () {
                        move(context.documentValue.blocks, blockIndex, blockIndex + 1);
                        normalizeContracts(context, true);
                        render(context);
                        focusAfterRender(context, {
                            kind: 'block',
                            id: block.id
                        });
                    },
                    blockIndex === context.documentValue.blocks.length - 1
                ),
                actionButton(
                    context,
                    'Eliminar bloque',
                    'data-blog-action',
                    'remove',
                    function () {
                        if (!window.confirm('¿Eliminar este bloque?')) {
                            return;
                        }
                        var adjacent = context.documentValue.blocks[
                            blockIndex + 1
                        ] || context.documentValue.blocks[blockIndex - 1];
                        context.documentValue.blocks.splice(blockIndex, 1);
                        normalizeContracts(context, true);
                        render(context);
                        focusAfterRender(context, adjacent
                            ? { kind: 'block', id: adjacent.id }
                            : { kind: 'add-block' });
                        announce(context, 'Bloque eliminado.', false);
                    },
                    false
                )
            );
            fieldset.append(actions);
            fieldset.append(renderBlockFields(context, block, blockIndex));
            item.append(title, fieldset);
            context.blockList.append(item);
        });
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
                label: String(option.textContent || 'Imagen')
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
        var panel = context.form.querySelector('[data-blog-seo-panel]');
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
            live.textContent = 'Cambios pendientes de analizar…';
            timer = window.setTimeout(run, 650);
        }
        context.form.addEventListener('input', schedule);
        context.form.addEventListener('change', schedule);
        context.form.addEventListener('click', function (event) {
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
        });
    }

    function disableSubmission(form, status, message) {
        form.querySelectorAll('button, select, textarea, input:not([type="hidden"])')
            .forEach(function (control) { control.disabled = true; });
        status.textContent = message;
        status.dataset.state = 'error';
    }

    function initEditor(form) {
        if (!(form instanceof HTMLFormElement) || form.dataset.blogEditorBound === 'true') {
            return;
        }
        form.dataset.blogEditorBound = 'true';

        var documentInput = form.querySelector('input[name="document_json"]');
        var blockList = form.querySelector('[data-blog-block-list]');
        var templateSelect = form.querySelector('[data-blog-template-select]');
        var mediaCatalog = form.querySelector('[data-blog-media-catalog]');
        var status = form.querySelector('[data-blog-editor-status]');
        if (
            !(documentInput instanceof HTMLInputElement)
            || !(blockList instanceof HTMLOListElement)
            || !(templateSelect instanceof HTMLSelectElement)
            || !(status instanceof HTMLElement)
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
            announcementVersion: 0
        };

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
                    var addedBlock = makeBlock(context, type);
                    context.documentValue.blocks.push(addedBlock);
                    normalizeContracts(context, true);
                    render(context);
                    focusAfterRender(context, {
                        kind: 'block',
                        id: addedBlock.id
                    });
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
            context.documentValue.template = requested;
            normalizeContracts(context, true);
            render(context);
        });

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
            }
        });

        render(context);
        initSeoAnalysis(context);
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
