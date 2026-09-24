(function () {
    'use strict';

    var SCHEMA = 'liquidstack.blog.document';
    var VERSION = 2;
    var LEGACY_VERSION = 1;
    var TEMPLATE_BASIC = 'article-basic-01';
    var TEMPLATE_COVER = 'article-cover-01';
    var TEMPLATE_HERO00 = 'article-hero00-01';
    var TEMPLATE_HERO06 = 'article-hero06-01';
    var TEMPLATES = [
        TEMPLATE_BASIC,
        TEMPLATE_HERO00,
        TEMPLATE_HERO06,
        TEMPLATE_COVER
    ];
    var DEFAULT_HEADING_POLICY = {
        allowed_levels: [2, 3, 4, 5, 6],
        defaults: { section: 2, article: 3, div: 3 }
    };
    var HEADING_LEVELS = DEFAULT_HEADING_POLICY.allowed_levels.slice();
    var COVER_TEMPLATES = [
        TEMPLATE_HERO00,
        TEMPLATE_HERO06,
        TEMPLATE_COVER
    ];
    var DEFAULT_HERO_CATALOG = [
        { key: 'hero00', resource: 'hero00', label: 'Hero 00' },
        { key: 'hero06', resource: 'hero06', label: 'Hero 06' },
        { key: 'hero07', resource: 'hero07', label: 'Hero 07' }
    ];
    var DEFAULT_H1_MODULE_CATALOG = [
        {
            key: 'moduleH1Type01',
            resource: 'moduleH1Type01',
            label: 'Módulo H1 tipo 01'
        },
        {
            key: 'moduleH1Type03',
            resource: 'moduleH1Type03',
            label: 'Módulo H1 tipo 03'
        },
        {
            key: 'moduleH1Type04',
            resource: 'moduleH1Type04',
            label: 'Módulo H1 tipo 04'
        }
    ];
    var HERO_KEYS = DEFAULT_HERO_CATALOG.map(function (item) {
        return item.key;
    });
    var H1_MODULE_KEYS = DEFAULT_H1_MODULE_CATALOG.map(function (item) {
        return item.key;
    });

    function templateHasCover(template) {
        return COVER_TEMPLATES.includes(template);
    }

    function templateHeroPreset(template) {
        return {
            'article-hero00-01': 'hero00',
            'article-hero06-01': 'hero06',
            'article-cover-01': 'hero07'
        }[template] || 'basic';
    }

    function templateForHero(hero) {
        return {
            hero00: TEMPLATE_HERO00,
            hero06: TEMPLATE_HERO06,
            hero07: TEMPLATE_COVER
        }[hero] || TEMPLATE_BASIC;
    }
    var LEGACY_BLOCK_TYPES = [
        'paragraph',
        'heading',
        'list',
        'callout',
        'link',
        'image',
        'video',
        'cta'
    ];
    var BLOCK_TYPES = [
        'paragraph',
        'heading',
        'list',
        'callout',
        'quote',
        'link',
        'image',
        'video',
        'embed',
        'cta',
        'separator'
    ];
    var LEGACY_TEXT_BLOCK_TYPES = ['heading', 'list', 'callout', 'quote'];
    var INSERTABLE_BLOCK_TYPES = BLOCK_TYPES.filter(function (type) {
        return !LEGACY_TEXT_BLOCK_TYPES.includes(type) && type !== 'link';
    });
    var BLOCK_LABELS = {
        paragraph: 'Texto',
        heading: 'Título',
        list: 'Lista',
        callout: 'Destacado',
        quote: 'Cita',
        link: 'Enlace',
        image: 'Imagen',
        video: 'Vídeo de YouTube',
        embed: 'HTML',
        cta: 'Botón',
        separator: 'Separador'
    };
    var CONTAINER_LABELS = {
        section: 'Secci\u00f3n',
        article: 'Art\u00edculo',
        div: 'Contenedor'
    };
    var PRESENTATION_WIDTHS = ['full', '80', '60', '40'];
    var PRESENTATION_ALIGNS = ['start', 'center', 'end'];
    var PRESENTATION_TEXT_ALIGNS = ['start', 'center', 'end', 'justify'];
    var PRESENTATION_SIZES = ['s', 'm', 'l', 'xl'];
    var PRESENTATION_SPACINGS = ['none', 's', 'm', 'l', 'xl'];
    var CONTAINER_PADDINGS = ['none', 's', 'm', 'l'];
    var SEPARATOR_LINE_STYLES = ['solid', 'dashed', 'dotted', 'double'];
    var SEPARATOR_THICKNESSES = ['thin', 'medium', 'thick'];
    var PRESENTATION_FONT_SIZES = [
        'default', 'small', 'large', 'xlarge'
    ];
    var PRESENTATION_FONT_WEIGHTS = [
        'default', 'regular', 'medium', 'semibold', 'bold'
    ];
    var PRESENTATION_TEXT_COLORS = [
        'default', 'color00', 'color01', 'color02', 'color03', 'color04',
        'color05'
    ];
    var HEADING_PREFERENCE_TEXT_COLORS = [
        'default', 'color00', 'color01', 'color02', 'color03', 'color04',
        'color05'
    ];
    var PRESENTATION_BACKGROUNDS = [
        'color00', 'color01', 'color02', 'color03', 'color04', 'color05'
    ];
    var PRESENTATION_IMAGE_RADII = [
        'default', 'none', 'small', 'medium', 'large'
    ];
    var PRESENTATION_RGBA = /^rgba\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*(0(?:\.[0-9]{1,6})?|1(?:\.0{1,6})?)\s*\)$/i;
    var DEFAULT_HEADING_PRESET_CATALOG = [
        {
            token: 'default',
            label: 'Base',
            preview_class: 'blogEditor__headingPreset--base'
        },
        {
            token: 'accent-line',
            label: 'L\u00ednea',
            preview_class: 'blogEditor__headingPreset--moduleH2Type01'
        },
        {
            token: 'accent-block',
            label: 'Degradado',
            preview_class: 'blogEditor__headingPreset--moduleH2Type02'
        }
    ];
    var HEADING_PRESETS = DEFAULT_HEADING_PRESET_CATALOG.map(
        function (preset) {
            return preset.token;
        }
    );
    var LIST_MARKERS = [
        'disc', 'circle', 'square', 'decimal', 'lower-alpha', 'upper-alpha'
    ];
    var QUOTE_PRESETS = ['default', 'accent', 'minimal'];
    var CALLOUT_TONES = ['neutral', 'info', 'warning'];
    var CTA_PRESETS = ['primary', 'secondary', 'type03', 'type04'];
    var LEGACY_MARK_ORDER = ['strong', 'em'];
    var RICH_MARK_ORDER = [
        'strong',
        'em',
        'underline',
        'size-small',
        'size-large',
        'size-xlarge',
        'text-color00',
        'text-color01',
        'text-color02',
        'text-color03',
        'text-color04',
        'text-color05',
        'text-basic-red',
        'text-basic-orange',
        'text-basic-yellow',
        'text-basic-green',
        'text-basic-blue',
        'text-basic-purple',
        'text-basic-pink',
        'text-basic-gray',
        'background-color00',
        'background-color01',
        'background-color02',
        'background-color03',
        'background-color04',
        'background-color05',
        'background-basic-red',
        'background-basic-orange',
        'background-basic-yellow',
        'background-basic-green',
        'background-basic-blue',
        'background-basic-purple',
        'background-basic-pink',
        'background-basic-gray'
    ];
    var RICH_MARK_GROUPS = {
        size: ['size-small', 'size-large', 'size-xlarge'],
        color: [
            'text-color00',
            'text-color01',
            'text-color02',
            'text-color03',
            'text-color04',
            'text-color05',
            'text-basic-red',
            'text-basic-orange',
            'text-basic-yellow',
            'text-basic-green',
            'text-basic-blue',
            'text-basic-purple',
            'text-basic-pink',
            'text-basic-gray'
        ],
        background: [
            'background-color00',
            'background-color01',
            'background-color02',
            'background-color03',
            'background-color04',
            'background-color05',
            'background-basic-red',
            'background-basic-orange',
            'background-basic-yellow',
            'background-basic-green',
            'background-basic-blue',
            'background-basic-purple',
            'background-basic-pink',
            'background-basic-gray'
        ]
    };
    var RICH_MARK_CLASSES = {
        'size-small': 'blogEditor__inline--size-small',
        'size-large': 'blogEditor__inline--size-large',
        'size-xlarge': 'blogEditor__inline--size-xlarge',
        'text-color00': 'blogEditor__inline--text-color00',
        'text-color01': 'blogEditor__inline--text-color01',
        'text-color02': 'blogEditor__inline--text-color02',
        'text-color03': 'blogEditor__inline--text-color03',
        'text-color04': 'blogEditor__inline--text-color04',
        'text-color05': 'blogEditor__inline--text-color05',
        'text-basic-red': 'blogEditor__inline--text-basic-red',
        'text-basic-orange': 'blogEditor__inline--text-basic-orange',
        'text-basic-yellow': 'blogEditor__inline--text-basic-yellow',
        'text-basic-green': 'blogEditor__inline--text-basic-green',
        'text-basic-blue': 'blogEditor__inline--text-basic-blue',
        'text-basic-purple': 'blogEditor__inline--text-basic-purple',
        'text-basic-pink': 'blogEditor__inline--text-basic-pink',
        'text-basic-gray': 'blogEditor__inline--text-basic-gray',
        'background-color00': 'blogEditor__inline--background-color00',
        'background-color01': 'blogEditor__inline--background-color01',
        'background-color02': 'blogEditor__inline--background-color02',
        'background-color03': 'blogEditor__inline--background-color03',
        'background-color04': 'blogEditor__inline--background-color04',
        'background-color05': 'blogEditor__inline--background-color05',
        'background-basic-red': 'blogEditor__inline--background-basic-red',
        'background-basic-orange': 'blogEditor__inline--background-basic-orange',
        'background-basic-yellow': 'blogEditor__inline--background-basic-yellow',
        'background-basic-green': 'blogEditor__inline--background-basic-green',
        'background-basic-blue': 'blogEditor__inline--background-basic-blue',
        'background-basic-purple': 'blogEditor__inline--background-basic-purple',
        'background-basic-pink': 'blogEditor__inline--background-basic-pink',
        'background-basic-gray': 'blogEditor__inline--background-basic-gray'
    };
    var RICH_ADVANCED_MARK_ATTRIBUTES = {
        size: 'data-content-format-size',
        textColor: 'data-content-format-text-color',
        backgroundColor: 'data-content-format-background-color',
        textRgba: 'data-content-format-text-rgba',
        backgroundRgba: 'data-content-format-background-rgba'
    };
    var RICH_PALETTE_OPTIONS = {
        color: [
            { value: '', label: 'Heredado' },
            { value: 'text-color00', label: 'Claro (00)' },
            { value: 'text-color01', label: 'Oscuro (01)' },
            { value: 'text-color02', label: 'Principal (02)' },
            { value: 'text-color03', label: 'Secundario (03)' },
            { value: 'text-color04', label: 'Color 04' },
            { value: 'text-color05', label: 'Color 05' },
            { value: 'text-basic-red', label: 'Rojo' },
            { value: 'text-basic-orange', label: 'Naranja' },
            { value: 'text-basic-yellow', label: 'Amarillo' },
            { value: 'text-basic-green', label: 'Verde' },
            { value: 'text-basic-blue', label: 'Azul' },
            { value: 'text-basic-purple', label: 'Morado' },
            { value: 'text-basic-pink', label: 'Rosa' },
            { value: 'text-basic-gray', label: 'Gris' }
        ],
        background: [
            { value: '', label: 'Sin fondo' },
            { value: 'background-color00', label: 'Claro (00)' },
            { value: 'background-color01', label: 'Oscuro (01)' },
            { value: 'background-color02', label: 'Principal (02)' },
            { value: 'background-color03', label: 'Secundario (03)' },
            { value: 'background-color04', label: 'Color 04' },
            { value: 'background-color05', label: 'Color 05' },
            { value: 'background-basic-red', label: 'Rojo suave' },
            { value: 'background-basic-orange', label: 'Naranja suave' },
            { value: 'background-basic-yellow', label: 'Amarillo suave' },
            { value: 'background-basic-green', label: 'Verde suave' },
            { value: 'background-basic-blue', label: 'Azul suave' },
            { value: 'background-basic-purple', label: 'Morado suave' },
            { value: 'background-basic-pink', label: 'Rosa suave' },
            { value: 'background-basic-gray', label: 'Gris suave' }
        ]
    };
    var RICH_TEXT_BLOCK_TYPES = [
        'paragraph', 'heading', 'list', 'callout', 'quote'
    ];
    var RICH_MODAL_BLOCK_TYPES = RICH_TEXT_BLOCK_TYPES.concat(['embed']);
    var RICH_TEXT_FLOW_TYPES = [
        'paragraph', 'heading', 'list', 'quote', 'callout', 'break'
    ];
    var RICH_TEXT_FLOW_BLOCK_SELECTOR = [
        'p', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'aside', 'li'
    ].join(',');
    var RICH_SOURCE_INDENT = '    ';
    var RICH_SOURCE_MAX_GUTTER_LINES = 5000;
    var RICH_CODE_HISTORY_LIMIT = 100;
    var RICH_VISUAL_SAFE_CSS_PROPERTIES = [
        'background', 'background-color', 'background-position',
        'background-repeat', 'background-size',
        'border', 'border-block', 'border-block-color', 'border-block-end',
        'border-block-end-color', 'border-block-end-style',
        'border-block-end-width', 'border-block-start',
        'border-block-start-color', 'border-block-start-style',
        'border-block-start-width', 'border-block-style',
        'border-block-width', 'border-bottom', 'border-bottom-color',
        'border-bottom-left-radius', 'border-bottom-right-radius',
        'border-bottom-style', 'border-bottom-width', 'border-color',
        'border-inline', 'border-inline-color', 'border-inline-end',
        'border-inline-end-color', 'border-inline-end-style',
        'border-inline-end-width', 'border-inline-start',
        'border-inline-start-color', 'border-inline-start-style',
        'border-inline-start-width', 'border-inline-style',
        'border-inline-width', 'border-left', 'border-left-color',
        'border-left-style', 'border-left-width', 'border-radius',
        'border-right', 'border-right-color', 'border-right-style',
        'border-right-width', 'border-style', 'border-top',
        'border-top-color', 'border-top-left-radius',
        'border-top-right-radius', 'border-top-style', 'border-top-width',
        'border-width', 'box-shadow', 'caret-color', 'color',
        'font-family', 'font-feature-settings', 'font-kerning', 'font-size',
        'font-stretch', 'font-style', 'font-variant', 'font-weight',
        'letter-spacing', 'line-height', 'opacity', 'outline',
        'outline-color', 'outline-offset', 'outline-style', 'outline-width',
        'text-align', 'text-decoration', 'text-decoration-color',
        'text-decoration-line', 'text-decoration-style',
        'text-decoration-thickness', 'text-emphasis',
        'text-emphasis-color', 'text-emphasis-style', 'text-shadow',
        'text-transform', 'text-underline-offset', 'word-spacing'
    ];
    var RICH_SOURCE_TAG_DEFINITIONS = [
        {
            tag: 'p',
            label: 'Párrafo',
            scope: 'flow',
            snippet: '<p>|</p>'
        },
        {
            tag: 'br',
            label: 'Salto de línea',
            scope: 'inline',
            snippet: '<br>'
        },
        {
            tag: 'ul',
            label: 'Lista sin ordenar',
            scope: 'flow',
            snippet: '<ul>\n    <li>|</li>\n</ul>'
        },
        {
            tag: 'ol',
            label: 'Lista ordenada',
            scope: 'flow',
            snippet: '<ol>\n    <li>|</li>\n</ol>'
        },
        {
            tag: 'li',
            label: 'Elemento de lista',
            scope: 'list-item',
            snippet: '<li>|</li>'
        },
        {
            tag: 'h2',
            label: 'Título de sección H2',
            scope: 'heading',
            snippet: '<h2>|</h2>'
        },
        {
            tag: 'h3',
            label: 'Título de artículo H3',
            scope: 'heading',
            snippet: '<h3>|</h3>'
        },
        {
            tag: 'h4',
            label: 'Título H4',
            scope: 'heading',
            snippet: '<h4>|</h4>'
        },
        {
            tag: 'h5',
            label: 'Título H5',
            scope: 'heading',
            snippet: '<h5>|</h5>'
        },
        {
            tag: 'h6',
            label: 'Título H6',
            scope: 'heading',
            snippet: '<h6>|</h6>'
        },
        {
            tag: 'blockquote',
            label: 'Cita',
            scope: 'flow-quote',
            snippet: '<blockquote>|</blockquote>'
        },
        {
            tag: 'aside',
            label: 'Texto destacado',
            scope: 'flow-callout',
            snippet: '<aside data-content-callout="true" role="note">|</aside>'
        },
        {
            tag: 'a',
            label: 'Enlace relativo seguro',
            scope: 'inline',
            snippet: '<a href="/">|</a>'
        },
        {
            tag: 'strong',
            label: 'Negrita semántica',
            scope: 'inline',
            snippet: '<strong>|</strong>'
        },
        {
            tag: 'em',
            label: 'Énfasis',
            scope: 'inline',
            snippet: '<em>|</em>'
        },
        {
            tag: 'u',
            label: 'Subrayado',
            scope: 'inline',
            snippet: '<u>|</u>'
        },
        {
            tag: 'span',
            label: 'Fragmento de texto',
            scope: 'inline',
            snippet: '<span>|</span>'
        }
    ];
    var RICH_ADVANCED_SOURCE_TAG_DEFINITIONS = [
        {
            tag: 'div',
            label: 'Contenedor genérico',
            scope: 'advanced-flow',
            snippet: '<div>|</div>'
        },
        {
            tag: 'small',
            label: 'Texto secundario',
            scope: 'inline',
            snippet: '<small>|</small>'
        },
        {
            tag: 'mark',
            label: 'Texto marcado',
            scope: 'inline',
            snippet: '<mark>|</mark>'
        },
        {
            tag: 'sup',
            label: 'Superíndice',
            scope: 'inline',
            snippet: '<sup>|</sup>'
        },
        {
            tag: 'sub',
            label: 'Subíndice',
            scope: 'inline',
            snippet: '<sub>|</sub>'
        },
        {
            tag: 'code',
            label: 'Código en línea',
            scope: 'inline',
            snippet: '<code>|</code>'
        },
        {
            tag: 'iframe',
            label: 'Iframe externo permitido',
            scope: 'advanced-flow',
            snippet: '<iframe src="https://www.youtube-nocookie.com/embed/|" title="Contenido incrustado" loading="lazy"></iframe>'
        }
    ];
    var RICH_EMBED_SOURCE_TAG_DEFINITIONS = [
        {
            tag: 'figure',
            label: 'Figura',
            scope: 'advanced-flow',
            snippet: '<figure>\n    |\n</figure>'
        },
        {
            tag: 'figcaption',
            label: 'Pie de figura',
            scope: 'advanced-flow',
            snippet: '<figcaption>|</figcaption>'
        },
        {
            tag: 'img',
            label: 'Imagen local',
            scope: 'advanced-flow',
            snippet: '<img src="/|" alt="">'
        },
        {
            tag: 'pre',
            label: 'Texto preformateado',
            scope: 'advanced-flow',
            snippet: '<pre>|</pre>'
        },
        {
            tag: 'b',
            label: 'Negrita visual',
            scope: 'inline',
            snippet: '<b>|</b>'
        },
        {
            tag: 'i',
            label: 'Cursiva visual',
            scope: 'inline',
            snippet: '<i>|</i>'
        },
        {
            tag: 'cite',
            label: 'Título de una obra',
            scope: 'inline',
            snippet: '<cite>|</cite>'
        }
    ];
    var RICH_SOURCE_TAG_NAMES = RICH_SOURCE_TAG_DEFINITIONS.concat(
        RICH_ADVANCED_SOURCE_TAG_DEFINITIONS,
        RICH_EMBED_SOURCE_TAG_DEFINITIONS
    ).map(
        function (definition) {
            return definition.tag;
        }
    );
    var RICH_CSS_VALUE_SUGGESTIONS = {
        display: ['block', 'inline', 'inline-block', 'flex', 'grid', 'none'],
        position: ['relative', 'static'],
        color: [
            'currentColor', 'transparent', 'red', 'orange', 'green', 'blue',
            'rgba(0, 0, 0, 1)'
        ],
        'background-color': [
            'transparent', 'currentColor', 'rgba(0, 0, 0, 0.08)'
        ],
        background: ['transparent', 'currentColor', 'rgba(0, 0, 0, 0.08)'],
        'font-style': ['normal', 'italic'],
        'font-weight': ['400', '500', '600', '700'],
        'font-size': ['0.875rem', '1rem', '1.25rem', '1.5rem'],
        'line-height': ['1', '1.25', '1.5', '1.65'],
        'text-align': ['start', 'center', 'end', 'justify'],
        'text-transform': ['none', 'uppercase', 'lowercase', 'capitalize'],
        'text-decoration': ['none', 'underline', 'line-through'],
        'list-style-type': [
            'disc', 'circle', 'square', 'decimal', 'lower-alpha',
            'upper-alpha', 'none'
        ],
        'border-style': ['none', 'solid', 'dashed', 'dotted', 'double'],
        'border-width': ['0', '1px', '2px', '0.125rem'],
        'border-radius': ['0', '0.25rem', '0.5rem', '1rem'],
        opacity: ['0', '0.25', '0.5', '0.75', '1'],
        width: ['auto', '100%', 'fit-content'],
        'max-width': ['none', '100%', '60rem'],
        height: ['auto', '100%', 'fit-content'],
        gap: ['0', '0.5rem', '1rem', '1.5rem'],
        margin: ['0', '0.5rem', '1rem', 'auto'],
        'margin-block': ['0', '0.5rem', '1rem', '1.5rem'],
        'margin-inline': ['0', '0.5rem', '1rem', 'auto'],
        padding: ['0', '0.5rem', '1rem', '1.5rem'],
        'padding-block': ['0', '0.5rem', '1rem', '1.5rem'],
        'padding-inline': ['0', '0.5rem', '1rem', '1.5rem']
    };
    var COLUMN_PRESETS = {
        '1': 1,
        '2-50-50': 2,
        '2-40-60': 2,
        '2-60-40': 2,
        '2-30-70': 2,
        '2-70-30': 2,
        '3': 3,
        '4': 4,
        '5': 5
    };
    var MAX_DIV_DEPTH = 3;
    var MAX_JSON_BYTES = 300000;
    var MAX_BLOCKS = 200;
    var MAX_LIST_ITEMS = 100;
    var MAX_INLINE_NODES = 500;
    var MAX_INLINE_TEXT_BYTES = 20000;
    var MAX_EMBED_HTML_BYTES = 50000;
    var MAX_CUSTOM_TEXT_HTML_BYTES = 200000;
    var MAX_CUSTOM_CSS_BYTES = 30000;
    var CUSTOM_TEXT_POLICY = null;
    var EMBED_POLICY = null;
    var IMAGE_PRESENTATION_POLICY = null;
    var UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
    var TAG_SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
    var MAX_TAGS_PER_VARIANT = 30;
    var MAX_TAG_CSV_BYTES = 4096;
    var MAX_TAG_NAME_CHARACTERS = 64;
    var MAX_TAG_NAME_BYTES = 255;
    var UNSAFE_TAG_TEXT =
        /(?:\p{Cc}|\p{Zl}|\p{Zp}|(?!\u200D)\p{Cf})/u;
    var instanceNumber = 0;

    function bytes(value) {
        if (typeof TextEncoder === 'function') {
            return new TextEncoder().encode(String(value)).length;
        }
        return new Blob([String(value)]).size;
    }

    function characters(value) {
        return Array.from(String(value)).length;
    }

    function readTechnicalLimits(form) {
        try {
            var limits = JSON.parse(form.dataset.blogTechnicalLimits || '');
            if (
                !plainObject(limits)
                || !plainObject(limits.entry)
                || !plainObject(limits.document)
                || !plainObject(limits.block)
            ) {
                return null;
            }
            var required = {
                entry: ['h1', 'slug', 'seo_title', 'meta_description', 'excerpt'],
                document: ['document_json'],
                block: [
                    'content', 'text_content', 'label', 'alt', 'title',
                    'caption', 'href', 'html', 'css'
                ]
            };
            // Consumers from the immediately preceding editor contract do not
            // expose the aggregate Texto limit yet. Keep them operable until
            // Composer publishes the new catalog, without weakening the
            // server-side per-leaf limit.
            if (
                !plainObject(limits.block.text_content)
                && plainObject(limits.block.content)
            ) {
                limits.block.text_content = Object.assign(
                    {},
                    limits.block.content
                );
            }
            if (
                !plainObject(limits.block.embed_html)
                && plainObject(limits.block.html)
            ) {
                limits.block.embed_html = Object.assign({}, limits.block.html);
            }
            var valid = Object.keys(required).every(function (scope) {
                return required[scope].every(function (field) {
                    var definition = limits[scope][field];
                    return plainObject(definition)
                        && Number.isInteger(definition.bytes)
                        && definition.bytes > 0;
                });
            });
            var customTextPolicy = richNormalizeCustomTextPolicy(
                limits.custom_text_policy
            );
            var embedPolicy = richNormalizeEmbedPolicy(limits.embed_policy);
            var imagePresentationPolicy = normalizeImagePresentationPolicy(
                limits.image_presentation_policy
            );
            if (
                !valid
                || customTextPolicy === null
                || embedPolicy === null
                || imagePresentationPolicy === null
            ) {
                return null;
            }
            limits.custom_text_policy = customTextPolicy;
            limits.embed_policy = embedPolicy;
            limits.image_presentation_policy = imagePresentationPolicy;
            return limits;
        } catch (error) {
            return null;
        }
    }

    function normalizeImagePresentationPolicy(value) {
        function integerRange(candidate, minimum, maximum) {
            return plainObject(candidate)
                && exactKeys(candidate, ['min', 'max', 'step'])
                && Number.isInteger(candidate.min)
                && Number.isInteger(candidate.max)
                && Number.isInteger(candidate.step)
                && candidate.min >= minimum
                && candidate.max <= maximum
                && candidate.min <= candidate.max
                && candidate.step > 0
                && candidate.step <= candidate.max - candidate.min + 1;
        }
        function uniqueStrings(candidate, allowed) {
            return Array.isArray(candidate)
                && candidate.length > 0
                && new Set(candidate).size === candidate.length
                && candidate.every(function (item) {
                    return allowed.includes(item);
                });
        }
        if (
            !plainObject(value)
            || !exactKeys(value, [
                'height_dvh', 'object_fit', 'object_position_y',
                'radius_percent', 'legacy_radius', 'overlay'
            ])
            || !integerRange(value.height_dvh, 5, 100)
            || !integerRange(value.radius_percent, 0, 50)
            || !plainObject(value.object_fit)
            || !exactKeys(value.object_fit, ['default', 'values'])
            || !uniqueStrings(value.object_fit.values, ['cover', 'contain'])
            || !value.object_fit.values.includes(value.object_fit.default)
            || !plainObject(value.object_position_y)
            || !exactKeys(value.object_position_y, ['default', 'values'])
            || !uniqueStrings(
                value.object_position_y.values,
                ['top', 'center', 'bottom']
            )
            || !value.object_position_y.values.includes(
                value.object_position_y.default
            )
            || !plainObject(value.legacy_radius)
            || !exactKeys(value.legacy_radius, ['values'])
            || !uniqueStrings(
                value.legacy_radius.values,
                PRESENTATION_IMAGE_RADII
            )
            || !plainObject(value.overlay)
            || !exactKeys(value.overlay, ['modes', 'colors', 'opacity'])
            || !uniqueStrings(
                value.overlay.modes,
                ['normal', 'multiply', 'screen', 'overlay']
            )
            || !uniqueStrings(
                value.overlay.colors,
                PRESENTATION_BACKGROUNDS
            )
            || !integerRange(value.overlay.opacity, 1, 100)
        ) {
            return null;
        }
        return {
            height_dvh: Object.assign({}, value.height_dvh),
            object_fit: {
                default: value.object_fit.default,
                values: value.object_fit.values.slice()
            },
            object_position_y: {
                default: value.object_position_y.default,
                values: value.object_position_y.values.slice()
            },
            radius_percent: Object.assign({}, value.radius_percent),
            legacy_radius: { values: value.legacy_radius.values.slice() },
            overlay: {
                modes: value.overlay.modes.slice(),
                colors: value.overlay.colors.slice(),
                opacity: Object.assign({}, value.overlay.opacity)
            }
        };
    }

    function defaultHeadingPolicy() {
        return {
            allowed_levels: DEFAULT_HEADING_POLICY.allowed_levels.slice(),
            defaults: Object.assign({}, DEFAULT_HEADING_POLICY.defaults)
        };
    }

    function readHeadingPolicy(form) {
        var fallback = defaultHeadingPolicy();
        var decoded;
        try {
            decoded = JSON.parse(
                form.getAttribute('data-blog-heading-policy') || ''
            );
        } catch (error) {
            return fallback;
        }
        if (
            !plainObject(decoded)
            || !Array.isArray(decoded.allowed_levels)
            || decoded.allowed_levels.length === 0
            || decoded.allowed_levels.length > 5
            || new Set(decoded.allowed_levels).size
                !== decoded.allowed_levels.length
            || !decoded.allowed_levels.every(function (level) {
                return Number.isInteger(level)
                    && level >= 2
                    && level <= 6;
            })
            || !plainObject(decoded.defaults)
            || !['section', 'article', 'div'].every(function (ownerType) {
                return decoded.allowed_levels.includes(
                    decoded.defaults[ownerType]
                );
            })
        ) {
            return fallback;
        }
        return {
            allowed_levels: decoded.allowed_levels.slice().sort(function (
                left,
                right
            ) {
                return left - right;
            }),
            defaults: {
                section: decoded.defaults.section,
                article: decoded.defaults.article,
                div: decoded.defaults.div
            }
        };
    }

    function headingPolicyForContext(context) {
        return context && plainObject(context.headingPolicy)
            ? context.headingPolicy
            : defaultHeadingPolicy();
    }

    function entryTechnicalIssue(context, control) {
        var field = control.dataset.blogLimitField || '';
        var definition = context.technicalLimits.entry[field];
        var value = control.value;
        if (bytes(value) > definition.bytes) {
            return {
                scope: 'entry',
                field: field,
                block_id: null,
                code: 'bytes_exceeded',
                limit: definition.bytes
            };
        }
        var controls = field === 'excerpt'
            ? /[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F-\u009F]/u
            : /[\u0000-\u001F\u007F-\u009F]/u;
        if (controls.test(value)) {
            return {
                scope: 'entry',
                field: field,
                block_id: null,
                code: 'control_character',
                limit: null
            };
        }
        if (definition.required === true && value.trim() === '') {
            return {
                scope: 'entry',
                field: field,
                block_id: null,
                code: 'required',
                limit: null
            };
        }
        if (
            field === 'slug'
            && value !== ''
            && !/^[a-z0-9]+(?:-[a-z0-9]+)*$/u.test(value)
        ) {
            return {
                scope: 'entry',
                field: field,
                block_id: null,
                code: 'invalid_format',
                limit: null
            };
        }
        return null;
    }

    function entryFeedback(context, control, forcedIssue) {
        var field = control.dataset.blogLimitField || '';
        var definition = context.technicalLimits.entry[field];
        var feedback = document.querySelector(
            '[data-blog-field-feedback="' + field + '"]'
        );
        if (!(feedback instanceof HTMLElement) || !definition) {
            return;
        }
        var count = characters(control.value);
        var byteCount = bytes(control.value);
        var issue = forcedIssue || entryTechnicalIssue(context, control);
        var message = count + ' caracteres \u00b7 ' + byteCount + ' de '
            + definition.bytes + ' bytes.';
        if (
            Number.isInteger(definition.editorial_min_characters)
            && Number.isInteger(definition.editorial_max_characters)
        ) {
            message += ' Recomendaci\u00f3n SEO orientativa: '
                + definition.editorial_min_characters + '\u2013'
                + definition.editorial_max_characters + ' caracteres.';
        } else if (Number.isInteger(definition.editorial_max_characters)) {
            message += ' Recomendaci\u00f3n SEO orientativa: hasta '
                + definition.editorial_max_characters + ' caracteres.';
        }
        var editorial = control.value !== '' && (
            (
                Number.isInteger(definition.editorial_min_characters)
                && count < definition.editorial_min_characters
            )
            || (
                Number.isInteger(definition.editorial_max_characters)
                && count > definition.editorial_max_characters
            )
        );
        if (issue) {
            message += issue.code === 'bytes_exceeded'
                ? ' Supera el l\u00edmite t\u00e9cnico; reduce el contenido para guardar.'
                : ' Corrige este valor para guardar.';
            feedback.dataset.state = 'error';
            control.setAttribute('aria-invalid', 'true');
        } else if (editorial) {
            message += ' No cumple esa recomendaci\u00f3n; el borrador se puede guardar.';
            feedback.dataset.state = 'advisory';
            control.setAttribute('aria-invalid', 'false');
        } else {
            feedback.dataset.state = 'ok';
            control.setAttribute('aria-invalid', 'false');
        }
        feedback.textContent = message;
    }

    function bindEntryLimitFeedback(context) {
        context.entryLimitControls = Array.from(
            context.form.elements
        ).filter(function (control) {
            return (
                control instanceof HTMLInputElement
                || control instanceof HTMLTextAreaElement
            ) && control.dataset.blogLimitScope === 'entry';
        });
        context.entryLimitControls.forEach(function (control) {
            entryFeedback(context, control, null);
            control.addEventListener('input', function () {
                entryFeedback(context, control, null);
            });
        });
    }

    function firstEntryTechnicalIssue(context) {
        for (var index = 0; index < context.entryLimitControls.length; index += 1) {
            var issue = entryTechnicalIssue(
                context,
                context.entryLimitControls[index]
            );
            if (issue) {
                return issue;
            }
        }
        return null;
    }

    function blockPlainTechnicalIssue(context, field, value, blockId) {
        var definition = context.technicalLimits.block[field];
        if (!definition || typeof value !== 'string') {
            return null;
        }
        if (bytes(value) > definition.bytes) {
            return {
                scope: 'block',
                field: field,
                block_id: blockId,
                code: 'bytes_exceeded',
                limit: definition.bytes
            };
        }
        if (
            !['html', 'css'].includes(field)
            && /[\u0000-\u001F\u007F-\u009F]/u.test(value)
        ) {
            return {
                scope: 'block',
                field: field,
                block_id: blockId,
                code: 'control_character',
                limit: null
            };
        }
        return null;
    }

    function inlinePlainText(content) {
        if (!Array.isArray(content)) {
            return '';
        }
        return content.map(function (node) {
            if (!node || typeof node !== 'object') {
                return '';
            }
            if (['text', 'link'].includes(node.type)) {
                return typeof node.text === 'string' ? node.text : '';
            }
            if (node.type === 'break') {
                return ' ';
            }
            if (
                ['paragraph', 'heading', 'quote', 'callout'].includes(
                    node.type
                )
            ) {
                return inlinePlainText(node.content) + ' ';
            }
            if (node.type === 'list' && Array.isArray(node.items)) {
                return node.items.map(function (item) {
                    return inlinePlainText(item.content);
                }).join(' ') + ' ';
            }
            return '';
        }).join('');
    }

    function inlineTechnicalText(content) {
        if (!Array.isArray(content)) {
            return '';
        }
        return content.map(function (node) {
            return node && typeof node.text === 'string' ? node.text : '';
        }).join('');
    }

    function textFlowTechnicalText(content) {
        if (!Array.isArray(content)) {
            return '';
        }
        return content.map(function (flowNode) {
            if (!flowNode || typeof flowNode !== 'object') {
                return '';
            }
            if (flowNode.type === 'break') {
                return ' ';
            }
            if (flowNode.type === 'list' && Array.isArray(flowNode.items)) {
                return flowNode.items.map(function (item) {
                    return inlineTechnicalText(item.content);
                }).join('');
            }
            var value = inlineTechnicalText(flowNode.content);
            if (flowNode.type === 'quote') {
                ['author', 'source'].forEach(function (credit) {
                    if (typeof flowNode[credit] === 'string') {
                        value += flowNode[credit];
                    }
                });
            }
            return value;
        }).join('');
    }

    function firstDocumentTechnicalIssue(context) {
        var documentLimit = context.technicalLimits.document.document_json.bytes;
        var serialized = context.documentInput.value;
        if (bytes(serialized) > documentLimit) {
            return {
                scope: 'document',
                field: 'document_json',
                block_id: null,
                code: 'bytes_exceeded',
                limit: documentLimit
            };
        }

        function inspect(value, inheritedId) {
            if (!value || typeof value !== 'object') {
                return null;
            }
            var blockId = typeof value.id === 'string' && UUID_V4.test(value.id)
                ? value.id
                : inheritedId;
            var unifiedText = value.type === 'paragraph'
                && isTextFlowContent(value.content);
            if (unifiedText) {
                var contentIssue = blockPlainTechnicalIssue(
                    context,
                    'text_content',
                    textFlowTechnicalText(value.content),
                    blockId
                );
                if (contentIssue) {
                    return contentIssue;
                }
            }
            var fields = {
                label: 'label',
                alt: 'alt',
                title: 'title',
                caption: 'caption',
                href: 'href',
                url: 'href',
                html: 'html',
                css: 'css',
                text: 'content'
            };
            var names = Object.keys(fields);
            for (var fieldIndex = 0; fieldIndex < names.length; fieldIndex += 1) {
                var source = names[fieldIndex];
                if (typeof value[source] !== 'string') {
                    continue;
                }
                var technicalField = source === 'html'
                    && value.type === 'embed'
                    ? 'embed_html'
                    : fields[source];
                var issue = blockPlainTechnicalIssue(
                    context,
                    technicalField,
                    value[source],
                    blockId
                );
                if (issue) {
                    return issue;
                }
            }
            var children = Object.keys(value);
            for (var index = 0; index < children.length; index += 1) {
                var child = value[children[index]];
                if (Array.isArray(child)) {
                    for (var itemIndex = 0; itemIndex < child.length; itemIndex += 1) {
                        var itemIssue = inspect(child[itemIndex], blockId);
                        if (itemIssue) {
                            return itemIssue;
                        }
                    }
                } else if (child && typeof child === 'object') {
                    var childIssue = inspect(child, blockId);
                    if (childIssue) {
                        return childIssue;
                    }
                }
            }
            return null;
        }

        return inspect(context.documentValue, null);
    }

    function validationIssue(value) {
        if (!plainObject(value)) {
            return null;
        }
        var scopes = ['entry', 'document', 'block'];
        var codes = [
            'required',
            'invalid_utf8',
            'control_character',
            'invalid_format',
            'bytes_exceeded',
            'invalid_json',
            'invalid_structure',
            'incomplete',
            'unavailable'
        ];
        if (
            !scopes.includes(value.scope)
            || typeof value.field !== 'string'
            || !/^[a-z_]{1,32}$/u.test(value.field)
            || !codes.includes(value.code)
            || !(value.limit === null || (
                Number.isInteger(value.limit) && value.limit > 0
            ))
            || !(value.block_id === null || (
                typeof value.block_id === 'string'
                && UUID_V4.test(value.block_id)
            ))
        ) {
            return null;
        }
        return value;
    }

    function validationIssueMessage(issue) {
        if (issue.code === 'bytes_exceeded' && issue.limit) {
            return 'Supera el límite técnico de ' + issue.limit
                + ' bytes. El contenido sigue en el editor.';
        }
        if (issue.code === 'required') {
            return 'Completa este campo para guardar.';
        }
        if (issue.code === 'incomplete') {
            return 'Completa los datos necesarios antes de publicar. El borrador se conserva.';
        }
        return 'Revisa este contenido técnico antes de guardar.';
    }

    function v2ApplyValidationIssue(context, rawIssue) {
        var issue = validationIssue(rawIssue);
        if (!issue) {
            return false;
        }
        if (issue.scope === 'entry') {
            var control = context.form.elements.namedItem(issue.field);
            if (
                control instanceof HTMLInputElement
                || control instanceof HTMLTextAreaElement
            ) {
                entryFeedback(context, control, issue);
                control.focus();
                if (typeof control.select === 'function') {
                    control.select();
                }
                return true;
            }
        }
        if (issue.scope === 'block' && issue.block_id) {
            context.selectedNodeId = issue.block_id;
            var location = v2Location(
                context.documentValue,
                issue.block_id
            );
            context.inspectorMode = location
                && RICH_TEXT_BLOCK_TYPES.includes(location.node.type)
                ? 'config'
                : 'edit';
            renderV2(context);
            var node = context.blockList.querySelector(
                '[data-blog-v2-node="' + issue.block_id + '"]'
            );
            if (node instanceof HTMLElement) {
                node.setAttribute('aria-invalid', 'true');
                var feedback = element(
                    'p',
                    'blogEditor__blockFeedback',
                    validationIssueMessage(issue)
                );
                feedback.setAttribute('role', 'alert');
                feedback.dataset.blogBlockFeedback = issue.field;
                node.append(feedback);
                node.focus();
                return true;
            }
        }
        context.blockList.setAttribute('aria-invalid', 'true');
        context.blockList.focus();
        return true;
    }

    function v2ApplyErrorIssue(context, error) {
        return v2SaveErrorCode(error) === 'invalid_draft'
            && error
            && error.payload
            && v2ApplyValidationIssue(context, error.payload.issue);
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

    function requiredAndOptionalKeys(value, required, optional) {
        if (!plainObject(value)) {
            return false;
        }
        var allowed = required.concat(optional);
        var actual = Object.keys(value);
        return required.every(function (key) {
            return Object.prototype.hasOwnProperty.call(value, key);
        }) && actual.every(function (key) {
            return allowed.includes(key);
        });
    }

    function plainObject(value) {
        if (!value || typeof value !== 'object' || Array.isArray(value)) {
            return false;
        }
        var prototype = Object.getPrototypeOf(value);
        return prototype === Object.prototype || prototype === null;
    }

    function safePlainText(value) {
        return typeof value === 'string'
            && !/[\u0000-\u001F\u007F-\u009F]/u.test(value);
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

    function canonicalDynamicRichMark(mark) {
        if (typeof mark !== 'string') {
            return null;
        }
        var prefix = mark.startsWith('text-rgba:')
            ? 'text-rgba:'
            : (
                mark.startsWith('background-rgba:')
                    ? 'background-rgba:'
                    : null
            );
        if (prefix === null) {
            return null;
        }
        var rgba = canonicalRgba(mark.slice(prefix.length));
        return rgba === null ? null : prefix + rgba;
    }

    function richInlineMarkAttribute(
        node,
        editorAttribute,
        advancedAttribute
    ) {
        var editorValue = node.getAttribute(editorAttribute);
        var advancedValue = node.getAttribute(advancedAttribute);
        if (editorValue !== null && advancedValue !== null) {
            throw new Error('rich-html-not-allowed');
        }
        return editorValue !== null ? editorValue : advancedValue;
    }

    function richMarkInGroup(groupName, mark) {
        if (!Object.prototype.hasOwnProperty.call(
            RICH_MARK_GROUPS,
            groupName
        )) {
            return false;
        }
        if (RICH_MARK_GROUPS[groupName].includes(mark)) {
            return true;
        }
        return (groupName === 'color' && mark.startsWith('text-rgba:'))
            || (
                groupName === 'background'
                && mark.startsWith('background-rgba:')
            );
    }

    function canonicalMarks(marks, rich) {
        var order = rich ? RICH_MARK_ORDER : LEGACY_MARK_ORDER;
        var source = Array.isArray(marks) ? marks : [];
        var values = new Set(source);
        var normalized = order.filter(function (mark) {
            return values.has(mark);
        });
        if (!rich) {
            return normalized;
        }
        ['text-rgba:', 'background-rgba:'].forEach(function (prefix) {
            var dynamic = source.map(canonicalDynamicRichMark).find(
                function (mark) {
                    return mark !== null && mark.startsWith(prefix);
                }
            );
            if (dynamic !== undefined) {
                normalized.push(dynamic);
            }
        });
        return normalized;
    }

    function validMarks(marks, rich) {
        if (!Array.isArray(marks) || new Set(marks).size !== marks.length) {
            return false;
        }
        var order = rich ? RICH_MARK_ORDER : LEGACY_MARK_ORDER;
        if (!marks.every(function (mark) {
            return order.includes(mark)
                || (rich && canonicalDynamicRichMark(mark) === mark);
        })) {
            return false;
        }
        if (rich && Object.keys(RICH_MARK_GROUPS).some(function (group) {
            return marks.filter(function (mark) {
                return richMarkInGroup(group, mark);
            }).length > 1;
        })) {
            return false;
        }
        var normalized = canonicalMarks(marks, rich);
        return normalized.length === marks.length
            && normalized.every(function (mark, index) {
                return mark === marks[index];
            });
    }

    function validInline(content, allowBreak, rich, allowIncomplete) {
        var incomplete = allowIncomplete === true;
        if (
            !Array.isArray(content)
            || (!incomplete && content.length < 1)
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
                && (incomplete || node.text !== '')
                && bytes(node.text) <= MAX_INLINE_TEXT_BYTES
                && validMarks(node.marks, rich)
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
                && (incomplete || node.text !== '')
                && bytes(node.text) <= MAX_INLINE_TEXT_BYTES
                && validMarks(node.marks, rich)
                && safeUrl(node.href)
                && optionalSingleLine(node.title, 500)
                && ['same', 'new'].includes(node.target)
            ) {
                meaningful += node.text;
                return true;
            }
            return false;
        });

        return valid && (incomplete || meaningful.trim() !== '');
    }

    function isTextFlowContent(content) {
        if (!Array.isArray(content) || content.length === 0) {
            return Array.isArray(content);
        }
        var firstMeaningful = content.find(function (node) {
            return node && typeof node === 'object' && node.type !== 'break';
        });
        return firstMeaningful === undefined
            || RICH_TEXT_FLOW_TYPES.includes(firstMeaningful.type);
    }

    function validTextFlowContent(content, allowIncomplete, seen) {
        var incomplete = allowIncomplete === true;
        var structuralIds = seen instanceof Set ? seen : new Set();
        return Array.isArray(content)
            && (incomplete || content.length > 0)
            && content.length <= MAX_BLOCKS
            && content.every(function (flowNode) {
                if (!flowNode || typeof flowNode !== 'object') {
                    return false;
                }
                if (flowNode.type === 'break') {
                    return exactKeys(flowNode, ['type']);
                }
                if (flowNode.type === 'paragraph') {
                    return exactKeys(flowNode, ['type', 'content'])
                        && validInline(
                            flowNode.content,
                            true,
                            true,
                            incomplete
                        );
                }
                if (flowNode.type === 'heading') {
                    return requiredAndOptionalKeys(
                        flowNode,
                        ['type', 'level', 'content'],
                        ['preset']
                    )
                        && HEADING_LEVELS.includes(flowNode.level)
                        && (
                            flowNode.preset === undefined
                            || HEADING_PRESETS.includes(flowNode.preset)
                        )
                        && validInline(
                            flowNode.content,
                            true,
                            true,
                            incomplete
                        );
                }
                if (flowNode.type === 'quote') {
                    return requiredAndOptionalKeys(
                        flowNode,
                        ['type', 'content'],
                        ['author', 'source', 'preset']
                    )
                        && (
                            flowNode.author === undefined
                            || optionalSingleLine(flowNode.author, 255)
                        )
                        && (
                            flowNode.source === undefined
                            || optionalSingleLine(flowNode.source, 500)
                        )
                        && (
                            flowNode.preset === undefined
                            || QUOTE_PRESETS.includes(flowNode.preset)
                        )
                        && validInline(
                            flowNode.content,
                            true,
                            true,
                            incomplete
                        );
                }
                if (flowNode.type === 'callout') {
                    return requiredAndOptionalKeys(
                        flowNode,
                        ['type', 'content'],
                        ['tone']
                    )
                        && (
                            flowNode.tone === undefined
                            || CALLOUT_TONES.includes(flowNode.tone)
                        )
                        && validInline(
                            flowNode.content,
                            true,
                            true,
                            incomplete
                        );
                }
                return flowNode.type === 'list'
                    && requiredAndOptionalKeys(
                        flowNode,
                        ['type', 'ordered', 'items'],
                        ['marker']
                    )
                    && typeof flowNode.ordered === 'boolean'
                    && (
                        flowNode.marker === undefined
                        || (
                            LIST_MARKERS.includes(flowNode.marker)
                            && (flowNode.ordered
                                ? [
                                    'decimal', 'lower-alpha', 'upper-alpha'
                                ].includes(flowNode.marker)
                                : [
                                    'disc', 'circle', 'square'
                                ].includes(flowNode.marker))
                        )
                    )
                    && Array.isArray(flowNode.items)
                    && (incomplete || flowNode.items.length > 0)
                    && flowNode.items.length <= MAX_LIST_ITEMS
                    && flowNode.items.every(function (item) {
                        return item
                            && requiredAndOptionalKeys(
                                item,
                                ['content'],
                                ['id']
                            )
                            && (
                                item.id === undefined
                                || trackV2Id(structuralIds, item.id)
                            )
                            && validInline(
                                item.content,
                                true,
                                true,
                                incomplete
                            );
                    });
            });
    }

    function validLegacyDocument(documentValue, allowIncomplete) {
        var incomplete = allowIncomplete === true;
        if (
            !exactKeys(
                documentValue,
                ['schema', 'version', 'template', 'blocks']
            )
            || documentValue.schema !== SCHEMA
            || documentValue.version !== LEGACY_VERSION
            || !TEMPLATES.includes(documentValue.template)
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
                || !LEGACY_BLOCK_TYPES.includes(block.type)
            ) {
                return false;
            }
            seen.add(block.id);

            if (block.type === 'paragraph') {
                return exactKeys(block, ['id', 'type', 'content'])
                    && validInline(block.content, true, incomplete);
            }
            if (block.type === 'heading') {
                if (
                    !exactKeys(block, ['id', 'type', 'level', 'content'])
                    || ![2, 3, 4, 5, 6].includes(block.level)
                    || !validInline(block.content, false, incomplete)
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
                            || !validInline(item.content, true, false)
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
                    && validInline(block.content, true, false);
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
            !templateHasCover(documentValue.template)
            && coverPositions.length !== 0
        ) {
            return false;
        }
        if (
            templateHasCover(documentValue.template)
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

    function validImagePresentation(value) {
        var policy = IMAGE_PRESENTATION_POLICY;
        var shapeValue = Object.assign({}, value);
        delete shapeValue.spacing_before;
        delete shapeValue.spacing_after;
        var baseKeys = ['width', 'align', 'text_align', 'size'];
        var optionalKeys = [
            'radius', 'height_dvh', 'object_fit', 'object_position_y',
            'radius_percent', 'overlay_mode', 'overlay_color',
            'overlay_opacity'
        ];
        if (
            !Object.prototype.hasOwnProperty.call(shapeValue, 'width')
            || !Object.prototype.hasOwnProperty.call(shapeValue, 'align')
            || !Object.keys(shapeValue).every(function (key) {
                return baseKeys.includes(key) || optionalKeys.includes(key);
            })
            || !PRESENTATION_WIDTHS.includes(value.width)
            || !PRESENTATION_ALIGNS.includes(value.align)
            || (
                value.text_align !== undefined
                && !PRESENTATION_TEXT_ALIGNS.includes(value.text_align)
            )
            || (
                value.size !== undefined
                && !PRESENTATION_SIZES.includes(value.size)
            )
            || !['spacing_before', 'spacing_after'].every(function (key) {
                return value[key] === undefined
                    || PRESENTATION_SPACINGS.includes(value[key]);
            })
        ) {
            return false;
        }
        if (policy === null) {
            return Object.keys(shapeValue).every(function (key) {
                return baseKeys.includes(key) || key === 'radius';
            }) && (
                value.radius === undefined
                || PRESENTATION_IMAGE_RADII.includes(value.radius)
            );
        }
        var hasLegacyRadius = Object.prototype.hasOwnProperty.call(
            value,
            'radius'
        );
        var hasRadiusPercent = Object.prototype.hasOwnProperty.call(
            value,
            'radius_percent'
        );
        if (
            (hasLegacyRadius && hasRadiusPercent)
            || (
                hasLegacyRadius
                && !policy.legacy_radius.values.includes(value.radius)
            )
            || (
                hasRadiusPercent
                && (
                    !Number.isInteger(value.radius_percent)
                    || value.radius_percent < policy.radius_percent.min
                    || value.radius_percent > policy.radius_percent.max
                    || (value.radius_percent - policy.radius_percent.min)
                        % policy.radius_percent.step !== 0
                )
            )
            || (
                value.height_dvh !== undefined
                && (
                    !Number.isInteger(value.height_dvh)
                    || value.height_dvh < policy.height_dvh.min
                    || value.height_dvh > policy.height_dvh.max
                    || (value.height_dvh - policy.height_dvh.min)
                        % policy.height_dvh.step !== 0
                )
            )
            || (
                value.object_fit !== undefined
                && !policy.object_fit.values.includes(value.object_fit)
            )
            || (
                value.object_position_y !== undefined
                && !policy.object_position_y.values.includes(
                    value.object_position_y
                )
            )
        ) {
            return false;
        }
        var overlayKeys = [
            'overlay_mode', 'overlay_color', 'overlay_opacity'
        ];
        var overlayCount = overlayKeys.filter(function (key) {
            return Object.prototype.hasOwnProperty.call(value, key);
        }).length;
        return (overlayCount === 0 || overlayCount === overlayKeys.length)
            && (
                overlayCount === 0
                || (
                    policy.overlay.modes.includes(value.overlay_mode)
                    && (
                        policy.overlay.colors.includes(value.overlay_color)
                        || canonicalRgba(value.overlay_color)
                            === value.overlay_color
                    )
                    && Number.isInteger(value.overlay_opacity)
                    && value.overlay_opacity >= policy.overlay.opacity.min
                    && value.overlay_opacity <= policy.overlay.opacity.max
                    && (value.overlay_opacity - policy.overlay.opacity.min)
                        % policy.overlay.opacity.step === 0
                )
            );
    }

    function validPresentation(value, blockType) {
        if (!plainObject(value)) {
            return false;
        }
        if (blockType === 'image') {
            return validImagePresentation(value);
        }
        var shapeValue = Object.assign({}, value);
        delete shapeValue.spacing_before;
        delete shapeValue.spacing_after;
        var legacyShape = exactKeys(shapeValue, ['width', 'align'])
            || exactKeys(shapeValue, ['width', 'align', 'text_align']);
        var legacyTypographyShape = ['paragraph', 'heading'].includes(blockType)
            && exactKeys(shapeValue, [
                'width',
                'align',
                'text_align',
                'font_size',
                'font_weight',
                'text_color'
            ]);
        var canonicalShape = exactKeys(shapeValue, [
            'width', 'align', 'text_align', 'size'
        ]);
        var canonicalTypographyShape = ['paragraph', 'heading'].includes(
            blockType
        ) && exactKeys(shapeValue, [
            'width', 'align', 'text_align', 'size', 'font_weight',
            'text_color'
        ]);
        var canonicalListColorShape = blockType === 'list'
            && exactKeys(shapeValue, [
                'width', 'align', 'text_align', 'size', 'text_color'
            ]);
        var canonicalImageShape = blockType === 'image'
            && exactKeys(shapeValue, [
                'width', 'align', 'text_align', 'size', 'radius'
            ]);
        return (
            legacyShape
            || legacyTypographyShape
            || canonicalShape
            || canonicalTypographyShape
            || canonicalListColorShape
            || canonicalImageShape
        )
            && PRESENTATION_WIDTHS.includes(value.width)
            && PRESENTATION_ALIGNS.includes(value.align)
            && (
                value.text_align === undefined
                || (
                    PRESENTATION_TEXT_ALIGNS.includes(value.text_align)
                    && !(blockType === 'cta' && value.text_align === 'justify')
                )
            )
            && (
                value.size === undefined
                || PRESENTATION_SIZES.includes(value.size)
            )
            && (
                !legacyTypographyShape
                || (
                    PRESENTATION_FONT_SIZES.includes(value.font_size)
                    && PRESENTATION_FONT_WEIGHTS.includes(value.font_weight)
                    && validPresentationTextColor(value.text_color)
                )
            )
            && (
                !canonicalTypographyShape
                || (
                    PRESENTATION_FONT_WEIGHTS.includes(value.font_weight)
                    && validPresentationTextColor(value.text_color)
                )
            )
            && (
                !canonicalListColorShape
                || validPresentationTextColor(value.text_color)
            )
            && (
                !canonicalImageShape
                || PRESENTATION_IMAGE_RADII.includes(value.radius)
            )
            && ['spacing_before', 'spacing_after'].every(function (key) {
                return value[key] === undefined
                    || PRESENTATION_SPACINGS.includes(value[key]);
            });
    }

    function validContainerPresentation(value, containerType) {
        if (!plainObject(value) || Object.keys(value).length === 0) {
            return false;
        }
        var hasBackground = Object.prototype.hasOwnProperty.call(
            value,
            'background'
        );
        var hasWidth = Object.prototype.hasOwnProperty.call(
            value,
            'width'
        );
        var hasAlign = Object.prototype.hasOwnProperty.call(
            value,
            'align'
        );
        var allowedKeys = ['background', 'padding', 'text_color'];
        if (['article', 'div'].includes(containerType)) {
            allowedKeys.push('width', 'align');
        }
        if (
            !Object.keys(value).every(function (key) {
                return allowedKeys.includes(key);
            })
            || hasWidth !== hasAlign
            || (containerType === 'section' && (hasWidth || hasAlign))
            || (hasWidth && !PRESENTATION_WIDTHS.includes(value.width))
            || (hasAlign && !PRESENTATION_ALIGNS.includes(value.align))
            || (
                value.padding !== undefined
                && !CONTAINER_PADDINGS.includes(value.padding)
            )
            || (
                value.text_color !== undefined
                && (
                    value.text_color === 'default'
                    || !validPresentationTextColor(value.text_color)
                )
            )
        ) {
            return false;
        }
        if (!hasBackground) {
            return true;
        }
        if (PRESENTATION_BACKGROUNDS.includes(value.background)) {
            return true;
        }
        var match = typeof value.background === 'string'
            ? value.background.match(PRESENTATION_RGBA)
            : null;
        return match !== null
            && [match[1], match[2], match[3]].every(function (channel) {
                var number = Number(channel);
                return Number.isInteger(number) && number >= 0 && number <= 255;
            });
    }

    function validPresentationTextColor(value) {
        return PRESENTATION_TEXT_COLORS.includes(value)
            || canonicalRgba(value) !== null;
    }

    function optionalPresetShape(block, baseKeys, presetKey, presets) {
        return exactKeys(block, baseKeys)
            || (
                exactKeys(block, baseKeys.concat([presetKey]))
                && presets.includes(block[presetKey])
            );
    }

    function validEmbedHtml(value, customPolicy, allowIncomplete) {
        if (
            allowIncomplete === true
            && typeof value === 'string'
            && value.trim() === ''
        ) {
            return true;
        }
        try {
            return richParseEmbedHtml(value, customPolicy) !== null;
        } catch (error) {
            return false;
        }
    }

    var RICH_ADVANCED_HTML_TAGS = [
        'div', 'p', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li',
        'blockquote', 'aside', 'span', 'strong', 'em', 'u', 'a', 'br',
        'small', 'mark', 'sup', 'sub', 'code', 'iframe'
    ];
    var RICH_EMBED_HTML_TAGS = [
        'a', 'b', 'blockquote', 'br', 'cite', 'code', 'div', 'em',
        'figcaption', 'figure', 'i', 'iframe', 'img', 'li', 'ol', 'p',
        'pre', 'span', 'strong', 'u', 'ul'
    ];
    var RICH_CSS_TAGS = [
        'a', 'aside', 'b', 'blockquote', 'br', 'cite', 'code', 'div', 'em',
        'figcaption', 'figure', 'h2', 'h3', 'h4', 'h5', 'h6', 'i', 'iframe',
        'img', 'li', 'mark', 'ol', 'p', 'pre', 'small', 'span', 'strong',
        'sub', 'sup', 'u', 'ul'
    ];
    var RICH_ADVANCED_REL_TOKENS = [
        'nofollow', 'noopener', 'noreferrer', 'sponsored', 'ugc'
    ];
    var RICH_ADVANCED_CSS_AT_RULES = ['media', 'supports'];
    var RICH_ADVANCED_ROLES = [
        'group', 'list', 'listitem', 'none', 'note', 'presentation', 'region'
    ];
    var RICH_ADVANCED_GLOBAL_ATTRIBUTES = [
        'class', 'id', 'title', 'lang', 'dir', 'role', 'aria-label',
        'aria-hidden', 'aria-labelledby', 'aria-describedby'
    ];
    var RICH_ADVANCED_SPECIAL_ATTRIBUTES = {
        a: ['href', 'target', 'rel'],
        blockquote: ['cite'],
        aside: ['data-content-callout', 'role'],
        ol: ['start', 'reversed', 'type'],
        li: ['value'],
        iframe: [
            'src', 'title', 'width', 'height', 'loading', 'referrerpolicy',
            'allow', 'allowfullscreen', 'sandbox'
        ]
    };
    var RICH_EMBED_GLOBAL_ATTRIBUTES = [
        'class', 'id', 'title', 'aria-label'
    ];
    var RICH_EMBED_SPECIAL_ATTRIBUTES = {
        a: ['href', 'target', 'rel'],
        img: ['src', 'alt', 'width', 'height', 'loading', 'decoding'],
        iframe: RICH_ADVANCED_SPECIAL_ATTRIBUTES.iframe
    };
    var RICH_ADVANCED_IFRAME_SOURCES = [
        { host: 'www.youtube-nocookie.com', path_prefix: '/embed/' },
        { host: 'youtube-nocookie.com', path_prefix: '/embed/' },
        { host: 'www.youtube.com', path_prefix: '/embed/' },
        { host: 'youtube.com', path_prefix: '/embed/' },
        { host: 'player.vimeo.com', path_prefix: '/video/' },
        { host: 'www.google.com', path_prefix: '/maps/embed' },
        { host: 'maps.google.com', path_prefix: '/maps/embed' }
    ];
    var RICH_IFRAME_CANONICAL_ALLOW = 'accelerometer; autoplay; '
        + 'clipboard-write; encrypted-media; fullscreen; gyroscope; '
        + 'picture-in-picture; web-share';
    var RICH_IFRAME_CANONICAL_SANDBOX = 'allow-scripts allow-same-origin '
        + 'allow-presentation';
    var RICH_ADVANCED_RESERVED_CLASS_PREFIXES = [
        'blogdocument', 'blog-', 'liquidstack', 'ls-', 'webadmin'
    ];
    var RICH_ADVANCED_TOKEN = /^[A-Za-z_][A-Za-z0-9_-]{0,63}$/u;

    function richSameStringSet(actual, expected) {
        return Array.isArray(actual)
            && actual.length === expected.length
            && expected.every(function (value) {
                return actual.includes(value);
            });
    }

    function richSameIframeSources(actual, expected) {
        return Array.isArray(actual)
            && actual.length === expected.length
            && expected.every(function (definition) {
                return actual.some(function (candidate) {
                    return plainObject(candidate)
                        && Object.keys(candidate).length === 2
                        && candidate.host === definition.host
                        && candidate.path_prefix === definition.path_prefix;
                });
            });
    }

    function richValidCssPolicy(value) {
        return plainObject(value)
            && Array.isArray(value.properties)
            && value.properties.length > 0
            && value.properties.every(function (property) {
                return /^[a-z][a-z0-9-]*$/u.test(property);
            })
            && Array.isArray(value.root_properties)
            && value.root_properties.length > 0
            && value.root_properties.every(function (property) {
                return value.properties.includes(property);
            })
            && richSameStringSet(value.tags, RICH_CSS_TAGS)
            && richSameStringSet(value.at_rules, RICH_ADVANCED_CSS_AT_RULES)
            && Array.isArray(value.simple_pseudos)
            && Array.isArray(value.pseudo_elements)
            && richSameStringSet(value.attribute_selectors, [
                'aria-hidden', 'dir', 'lang', 'data-content-*'
            ])
            && richSameStringSet(
                value.reserved_class_prefixes,
                RICH_ADVANCED_RESERVED_CLASS_PREFIXES
            )
            && [
                'max_depth', 'max_rules', 'max_declarations',
                'max_selector_bytes', 'max_value_bytes', 'max_css_bytes',
                'max_rendered_css_bytes', 'max_numeric_tokens_per_value',
                'max_root_numeric_total'
            ].every(function (field) {
                return Number.isInteger(value[field]) && value[field] > 0;
            });
    }

    function richNormalizeCustomTextPolicy(value) {
        if (
            !plainObject(value)
            || !plainObject(value.html)
            || !plainObject(value.css)
            || !richSameStringSet(value.html.tags, RICH_ADVANCED_HTML_TAGS)
            || !richSameStringSet(
                value.html.global_attributes,
                RICH_ADVANCED_GLOBAL_ATTRIBUTES
            )
            || value.html.data_attribute_prefix !== 'data-content-'
            || !richSameStringSet(value.html.roles, RICH_ADVANCED_ROLES)
            || !plainObject(value.html.special_attributes)
            || !Object.keys(RICH_ADVANCED_SPECIAL_ATTRIBUTES).every(
                function (tag) {
                    return richSameStringSet(
                        value.html.special_attributes[tag],
                        RICH_ADVANCED_SPECIAL_ATTRIBUTES[tag]
                    );
                }
            )
            || !richSameIframeSources(
                value.html.iframe_sources,
                RICH_ADVANCED_IFRAME_SOURCES
            )
            || !Number.isInteger(value.html.max_nodes)
            || value.html.max_nodes < 1
            || !Number.isInteger(value.html.max_depth)
            || value.html.max_depth < 1
            || !richValidCssPolicy(value.css)
        ) {
            return null;
        }
        return value;
    }

    function richNormalizeEmbedPolicy(value) {
        if (
            !plainObject(value)
            || !plainObject(value.html)
            || !plainObject(value.css)
            || !richSameStringSet(value.html.tags, RICH_EMBED_HTML_TAGS)
            || !richSameStringSet(
                value.html.global_attributes,
                RICH_EMBED_GLOBAL_ATTRIBUTES
            )
            || !plainObject(value.html.special_attributes)
            || !Object.keys(RICH_EMBED_SPECIAL_ATTRIBUTES).every(function (tag) {
                return richSameStringSet(
                    value.html.special_attributes[tag],
                    RICH_EMBED_SPECIAL_ATTRIBUTES[tag]
                );
            })
            || !richSameIframeSources(
                value.html.iframe_sources,
                RICH_ADVANCED_IFRAME_SOURCES
            )
            || !Number.isInteger(value.html.max_html_bytes)
            || value.html.max_html_bytes < 1
            || !richValidCssPolicy(value.css)
        ) {
            return null;
        }
        return value;
    }

    function richAdvancedPolicy(policy) {
        if (plainObject(policy) && plainObject(policy.html)) {
            return policy;
        }
        return CUSTOM_TEXT_POLICY;
    }

    function richStatePolicy(state) {
        return state && state.sourceOnlyMode
            ? state.context.technicalLimits.embed_policy
            : state.context.technicalLimits.custom_text_policy;
    }

    function richAdvancedPlainAttribute(value) {
        var normalized = value.trim();
        return normalized !== ''
            && bytes(normalized) <= 500
            && !/[\u0000-\u001F\u007F]/u.test(normalized);
    }

    function richAdvancedSafeUrl(value, allowFragment) {
        var normalized = value.trim();
        if (normalized === ''
            || bytes(normalized) > 2048
            || /[\u0000-\u0020\u007F\\]/u.test(normalized)
            || /%(?![0-9A-Fa-f]{2})/u.test(normalized)) {
            return false;
        }
        if (allowFragment !== false && normalized.startsWith('#')) {
            return /^#[A-Za-z_][A-Za-z0-9_-]{0,63}$/u.test(normalized);
        }
        if (normalized.startsWith('/') && !normalized.startsWith('//')) {
            var decoded = normalized;
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
            return stable
                && !/[\u0000-\u001F\u007F\\]/u.test(decoded)
                && !decoded.includes('//')
                && decoded.split('/').every(function (segment) {
                    return segment !== '.' && segment !== '..';
                });
        }
        if (/^tel:\+?[0-9][0-9 .()\-]{2,31}$/u.test(normalized)) {
            return true;
        }
        if (normalized.startsWith('mailto:') && !normalized.includes('?')) {
            return /^[^@\s]+@[^@\s]+\.[^@\s]+$/u
                .test(normalized.slice(7));
        }
        if (!normalized.startsWith('https://') || /[^\x21-\x7E]/u.test(normalized)) {
            return false;
        }
        try {
            var parsed = new URL(normalized);
            return parsed.protocol === 'https:'
                && parsed.hostname !== ''
                && parsed.username === ''
                && parsed.password === '';
        } catch (error) {
            return false;
        }
    }

    function richAdvancedIframeUrl(value, sources) {
        if (typeof value !== 'string' || !Array.isArray(sources)) {
            return false;
        }
        var normalized = value.trim();
        if (
            normalized === ''
            || bytes(normalized) > 2048
            || /[\u0000-\u0020\u007F\\]/u.test(normalized)
        ) {
            return false;
        }
        try {
            var parsed = new URL(normalized);
            if (
                parsed.protocol !== 'https:'
                || parsed.username !== ''
                || parsed.password !== ''
                || parsed.port !== ''
                || parsed.hash !== ''
            ) {
                return false;
            }
            var authorityEnd = normalized.indexOf('/', 'https://'.length);
            var rawAuthority = normalized.slice(
                'https://'.length,
                authorityEnd < 0 ? normalized.length : authorityEnd
            ).split(/[?#]/u, 1)[0];
            if (rawAuthority.toLowerCase() !== parsed.hostname.toLowerCase()) {
                return false;
            }
            var rawPath = authorityEnd < 0
                ? ''
                : normalized.slice(authorityEnd).split(/[?#]/u, 1)[0];
            if (
                rawPath === ''
                || rawPath.includes('%')
                || rawPath.split('/').some(function (segment) {
                    return segment === '.' || segment === '..';
                })
            ) {
                return false;
            }
            var host = parsed.hostname.toLowerCase();
            return sources.some(function (definition) {
                return plainObject(definition)
                    && host === definition.host.toLowerCase()
                    && (
                        definition.path_prefix.endsWith('/')
                            ? (
                                parsed.pathname.startsWith(
                                    definition.path_prefix
                                )
                                && parsed.pathname.length
                                    > definition.path_prefix.length
                            )
                            : parsed.pathname === definition.path_prefix
                    );
            });
        } catch (error) {
            return false;
        }
    }

    function richAdvancedAttributeAllowed(
        node,
        attribute,
        validation,
        policy
    ) {
        var name = attribute.name.toLowerCase();
        var value = attribute.value;
        var tag = node.nodeName.toLowerCase();
        var htmlPolicy = policy.html;
        var special = htmlPolicy.special_attributes[tag] || [];
        var dataPrefix = typeof htmlPolicy.data_attribute_prefix === 'string'
            ? htmlPolicy.data_attribute_prefix : '';
        var isDataAttribute = dataPrefix !== '' && new RegExp(
            '^' + dataPrefix.replace(/[.*+?^${}()|[\]\\]/gu, '\\$&')
                + '[a-z][a-z0-9_-]{0,47}$',
            'u'
        ).test(name);
        if (
            /^on/iu.test(name)
            || name === 'style'
            || /[\u0000-\u001F\u007F-\u009F]/u.test(value)
            || (
                !htmlPolicy.global_attributes.includes(name)
                && !special.includes(name)
                && !isDataAttribute
            )
        ) {
            return false;
        }
        if (name === 'id') {
            var id = value.trim();
            if (!RICH_ADVANCED_TOKEN.test(id) || validation.ids.has(id)) {
                return false;
            }
            validation.ids.add(id);
            return true;
        }
        if (name === 'class') {
            var classes = value.trim().split(/\s+/u).filter(Boolean);
            return classes.length > 0
                && classes.length <= 16
                && classes.every(function (token) {
                    var lower = token.toLowerCase();
                    return RICH_ADVANCED_TOKEN.test(token)
                        && !policy.css.reserved_class_prefixes.some(
                            function (prefix) {
                                return lower.startsWith(prefix);
                            }
                        );
                });
        }
        if (name === 'title' || name === 'aria-label' || isDataAttribute) {
            return richAdvancedPlainAttribute(value);
        }
        if (['aria-labelledby', 'aria-describedby'].includes(name)) {
            var references = value.trim().split(/\s+/u).filter(Boolean);
            if (
                references.length === 0
                || references.length > 8
                || !references.every(function (token) {
                    return RICH_ADVANCED_TOKEN.test(token);
                })
            ) {
                return false;
            }
            references.forEach(function (reference) {
                validation.references.push(reference);
            });
            return true;
        }
        if (name === 'aria-hidden') {
            return ['true', 'false'].includes(value.trim().toLowerCase());
        }
        if (name === 'lang') {
            return /^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8}){0,3}$/u
                .test(value.trim());
        }
        if (name === 'role') {
            return Array.isArray(htmlPolicy.roles)
                && htmlPolicy.roles.includes(value.trim().toLowerCase());
        }
        if (name === 'dir') {
            return ['ltr', 'rtl', 'auto'].includes(value.trim().toLowerCase());
        }
        if (tag === 'a' && name === 'href') {
            var href = value.trim();
            if (!richAdvancedSafeUrl(href, true)) {
                return false;
            }
            if (href.startsWith('#')) {
                validation.references.push(href.slice(1));
            }
            return true;
        }
        if (tag === 'a' && name === 'target') {
            return ['_self', '_blank'].includes(value.trim().toLowerCase());
        }
        if (tag === 'a' && name === 'rel') {
            var tokens = value.trim().toLowerCase().split(/\s+/u)
                .filter(Boolean);
            return tokens.every(function (token) {
                return RICH_ADVANCED_REL_TOKENS.includes(token);
            });
        }
        if (tag === 'blockquote' && name === 'cite') {
            return richAdvancedSafeUrl(value, false);
        }
        if (tag === 'iframe' && name === 'src') {
            return richAdvancedIframeUrl(value, htmlPolicy.iframe_sources);
        }
        if (tag === 'iframe' && name === 'title') {
            return richAdvancedPlainAttribute(value);
        }
        if (
            tag === 'iframe'
            && ['width', 'height'].includes(name)
        ) {
            return /^[1-9][0-9]{0,3}$/u.test(value.trim());
        }
        if (tag === 'iframe' && name === 'loading') {
            return value.trim().toLowerCase() === 'lazy';
        }
        if (tag === 'iframe' && name === 'referrerpolicy') {
            return value.trim().toLowerCase()
                === 'strict-origin-when-cross-origin';
        }
        if (tag === 'iframe' && name === 'allowfullscreen') {
            return value === '' || value.toLowerCase() === 'allowfullscreen';
        }
        if (tag === 'iframe' && name === 'allow') {
            return value.trim() === RICH_IFRAME_CANONICAL_ALLOW;
        }
        if (tag === 'iframe' && name === 'sandbox') {
            return value.trim() === RICH_IFRAME_CANONICAL_SANDBOX;
        }
        if ((tag === 'ol' && name === 'start') || (
            tag === 'li' && name === 'value'
        )) {
            return /^-?[0-9]{1,6}$/u.test(value.trim());
        }
        if (tag === 'ol' && name === 'reversed') {
            return true;
        }
        if (tag === 'ol' && name === 'type') {
            return ['1', 'a', 'A', 'i', 'I'].includes(value.trim());
        }
        return false;
    }

    function richEmbedClassAllowed(value) {
        var classes = value.trim().split(/\s+/u).filter(Boolean);
        return classes.length > 0
            && classes.length <= 16
            && classes.every(function (token) {
                return RICH_ADVANCED_TOKEN.test(token);
            });
    }

    function richEmbedLinkUrl(value) {
        var normalized = value.trim();
        if (/^#[A-Za-z][A-Za-z0-9_-]*$/u.test(normalized)) {
            return true;
        }
        if (
            normalized.startsWith('/')
            && !normalized.startsWith('//')
        ) {
            return !/[\s\\]/u.test(normalized);
        }
        if (/^mailto:[^@\s?]+@[^@\s?]+\.[^@\s?]+$/u.test(normalized)) {
            return true;
        }
        if (/^tel:\+?[0-9][0-9 .()\-]{2,31}$/u.test(normalized)) {
            return true;
        }
        if (
            normalized === ''
            || bytes(normalized) > 2048
            || /\s|\\/u.test(normalized)
        ) {
            return false;
        }
        try {
            var parsed = new URL(normalized);
            return parsed.protocol === 'https:'
                && parsed.hostname !== ''
                && parsed.username === ''
                && parsed.password === '';
        } catch (error) {
            return false;
        }
    }

    function richEmbedImageUrl(value) {
        var normalized = value.trim();
        return normalized.startsWith('/')
            && !normalized.startsWith('//')
            && !/[\s\\]/u.test(normalized);
    }

    function richEmbedAttributeAllowed(node, attribute, validation, policy) {
        var name = attribute.name.toLowerCase();
        var value = attribute.value;
        var tag = node.nodeName.toLowerCase();
        var htmlPolicy = policy.html;
        var special = htmlPolicy.special_attributes[tag] || [];
        if (
            /^on/iu.test(name)
            || name === 'style'
            || /[\u0000-\u001F\u007F-\u009F]/u.test(value)
            || (
                !htmlPolicy.global_attributes.includes(name)
                && !special.includes(name)
            )
        ) {
            return false;
        }
        if (name === 'id') {
            var id = value.trim();
            if (!RICH_ADVANCED_TOKEN.test(id) || validation.ids.has(id)) {
                return false;
            }
            validation.ids.add(id);
            return true;
        }
        if (name === 'class') {
            return richEmbedClassAllowed(value);
        }
        if (name === 'title' || name === 'aria-label') {
            return richAdvancedPlainAttribute(value);
        }
        if (tag === 'a' && name === 'href') {
            return richEmbedLinkUrl(value);
        }
        if (tag === 'a' && name === 'target') {
            return value.trim() === '_blank';
        }
        if (tag === 'a' && name === 'rel') {
            return node.getAttribute('target') === '_blank'
                && value.trim().toLowerCase() === 'noopener noreferrer';
        }
        if (tag === 'img' && name === 'src') {
            return richEmbedImageUrl(value);
        }
        if (tag === 'img' && name === 'alt') {
            return value.trim() === '' || richAdvancedPlainAttribute(value);
        }
        if (
            ['img', 'iframe'].includes(tag)
            && ['width', 'height'].includes(name)
        ) {
            return /^[1-9][0-9]{0,3}$/u.test(value.trim());
        }
        if (tag === 'img' && name === 'loading') {
            return value.trim().toLowerCase() === 'lazy';
        }
        if (tag === 'img' && name === 'decoding') {
            return value.trim().toLowerCase() === 'async';
        }
        if (tag === 'iframe' && name === 'src') {
            return richAdvancedIframeUrl(value, htmlPolicy.iframe_sources);
        }
        if (tag === 'iframe' && name === 'loading') {
            return value.trim().toLowerCase() === 'lazy';
        }
        if (tag === 'iframe' && name === 'referrerpolicy') {
            return value.trim().toLowerCase()
                === 'strict-origin-when-cross-origin';
        }
        if (tag === 'iframe' && name === 'allowfullscreen') {
            return value === '' || value.toLowerCase() === 'allowfullscreen';
        }
        if (tag === 'iframe' && name === 'allow') {
            return value.trim() === RICH_IFRAME_CANONICAL_ALLOW;
        }
        if (tag === 'iframe' && name === 'sandbox') {
            return value.trim() === RICH_IFRAME_CANONICAL_SANDBOX;
        }
        return false;
    }

    function richParseEmbedHtml(source, customPolicy) {
        var policy = plainObject(customPolicy) ? customPolicy : EMBED_POLICY;
        if (
            policy === null
            || !plainObject(policy.html)
            || typeof source !== 'string'
            || source.trim() === ''
            || bytes(source) > policy.html.max_html_bytes
            || /[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F-\u009F]/u
                .test(source)
            || /<\s*[!?]/u.test(source)
            || /<\s*\/?\s*(?:html|head|body)\b/iu.test(source)
            || /<\s*\/?\s*(?:script|style|form|object|embed|svg|math)\b/iu
                .test(source)
            || /\son[a-z0-9_-]*\s*=/iu.test(source)
            || typeof DOMParser !== 'function'
        ) {
            throw new Error('rich-embed-html-not-allowed');
        }
        var parsed = new DOMParser().parseFromString(source, 'text/html');
        if (
            !parsed
            || !parsed.body
            || parsed.doctype !== null
            || parsed.head.childNodes.length > 0
            || parsed.documentElement.attributes.length > 0
            || parsed.body.attributes.length > 0
            || parsed.querySelector('parsererror') !== null
        ) {
            throw new Error('rich-embed-html-not-allowed');
        }

        var validation = { ids: new Set() };
        function inspect(node) {
            if (node.nodeType === 3) {
                if (
                    /[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F-\u009F]/u
                        .test(node.nodeValue || '')
                ) {
                    throw new Error('rich-embed-html-not-allowed');
                }
                return;
            }
            if (
                node.nodeType !== 1
                || !policy.html.tags.includes(node.nodeName.toLowerCase())
                || node.attributes.length > 24
                || !Array.from(node.attributes).every(function (attribute) {
                    return richEmbedAttributeAllowed(
                        node,
                        attribute,
                        validation,
                        policy
                    );
                })
            ) {
                throw new Error('rich-embed-html-not-allowed');
            }
            var tag = node.nodeName.toLowerCase();
            if (
                (tag === 'a' && !node.hasAttribute('href'))
                || (tag === 'img' && !node.hasAttribute('src'))
                || (
                    tag === 'iframe'
                    && !node.hasAttribute('src')
                )
                || (
                    ['br', 'img', 'iframe'].includes(tag)
                    && node.childNodes.length > 0
                )
            ) {
                throw new Error('rich-embed-html-not-allowed');
            }
            Array.from(node.childNodes).forEach(inspect);
        }
        Array.from(parsed.body.childNodes).forEach(inspect);
        if (parsed.body.childNodes.length === 0) {
            throw new Error('rich-embed-html-not-allowed');
        }
        return parsed;
    }

    function richParseAdvancedHtml(source, customPolicy) {
        var policy = richAdvancedPolicy(customPolicy);
        if (
            policy === null
            || typeof source !== 'string'
            || bytes(source) > MAX_CUSTOM_TEXT_HTML_BYTES
            || source.trim() === ''
            || /[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F-\u009F]/u
                .test(source)
            || /<\s*[!?]/u.test(source)
            || /<\s*\/?\s*(?:html|head|body)\b/iu.test(source)
            || /<\s*\/?\s*(?:script|style|form|object|embed|svg|math)\b/iu
                .test(source)
            || /\son[a-z0-9_-]*\s*=/iu.test(source)
        ) {
            throw new Error('rich-advanced-html-not-allowed');
        }
        if (typeof DOMParser !== 'function') {
            return null;
        }
        var parsed = new DOMParser().parseFromString(source, 'text/html');
        if (
            !parsed
            || !parsed.body
            || parsed.doctype !== null
            || parsed.head.childNodes.length > 0
            || parsed.documentElement.attributes.length > 0
            || parsed.body.attributes.length > 0
        ) {
            throw new Error('rich-advanced-html-not-allowed');
        }

        var validation = {
            ids: new Set(),
            references: [],
            nodes: 0
        };
        function inspect(node, depth, listDepth) {
            validation.nodes += 1;
            if (
                validation.nodes > policy.html.max_nodes
                || depth > policy.html.max_depth
            ) {
                throw new Error('rich-advanced-html-not-allowed');
            }
            if (node.nodeType === Node.TEXT_NODE) {
                if (
                    /[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F-\u009F]/u
                        .test(node.nodeValue || '')
                ) {
                    throw new Error('rich-advanced-html-not-allowed');
                }
                return;
            }
            if (node.nodeType === 8) {
                throw new Error('rich-advanced-html-not-allowed');
            }
            if (
                node.nodeType !== Node.ELEMENT_NODE
                || !policy.html.tags.includes(
                    node.nodeName.toLowerCase()
                )
                || node.attributes.length > 24
                || !Array.from(node.attributes).every(function (attribute) {
                    return richAdvancedAttributeAllowed(
                        node,
                        attribute,
                        validation,
                        policy
                    );
                })
                || (
                    node.nodeName.toLowerCase() === 'a'
                    && !node.hasAttribute('href')
                )
                || (
                    node.nodeName.toLowerCase() === 'br'
                    && node.childNodes.length > 0
                )
                || (
                    node.nodeName.toLowerCase() === 'iframe'
                    && (
                        !node.hasAttribute('src')
                        || node.childNodes.length > 0
                    )
                )
            ) {
                throw new Error('rich-advanced-html-not-allowed');
            }
            var nextListDepth = listDepth + (
                ['ul', 'ol'].includes(node.nodeName.toLowerCase()) ? 1 : 0
            );
            if (nextListDepth > 4) {
                throw new Error('rich-advanced-html-not-allowed');
            }
            Array.from(node.childNodes).forEach(function (child) {
                inspect(child, depth + 1, nextListDepth);
            });
        }
        Array.from(parsed.body.childNodes).forEach(function (child) {
            inspect(child, 1, 0);
        });
        if (validation.references.some(function (reference) {
            return !validation.ids.has(reference);
        })) {
            throw new Error('rich-advanced-html-not-allowed');
        }
        return parsed;
    }

    function validAdvancedHtml(value, allowIncomplete, customPolicy) {
        try {
            if (allowIncomplete === true && value === '') {
                return true;
            }
            var parsed = richParseAdvancedHtml(value, customPolicy);
            return allowIncomplete === true
                || parsed === null
                || parsed.body.textContent.trim() !== ''
                || parsed.body.querySelector('iframe') !== null;
        } catch (error) {
            return false;
        }
    }

    function richCssStripComments(value) {
        var result = '';
        var quote = '';
        for (var index = 0; index < value.length; index += 1) {
            var current = value.charAt(index);
            var next = value.charAt(index + 1);
            if (quote !== '') {
                if (current === '\n' || current === '\r') {
                    throw new Error('rich-advanced-css-not-allowed');
                }
                result += current;
                if (current === quote) {
                    quote = '';
                }
                continue;
            }
            if (current === '"' || current === "'") {
                quote = current;
                result += current;
                continue;
            }
            if (current === '/' && next === '*') {
                var end = value.indexOf('*/', index + 2);
                if (end < 0) {
                    throw new Error('rich-advanced-css-not-allowed');
                }
                result += ' ';
                index = end + 1;
                continue;
            }
            result += current;
        }
        if (quote !== '') {
            throw new Error('rich-advanced-css-not-allowed');
        }
        return result.trim();
    }

    function richCssSkipWhitespace(parser) {
        while (/\s/u.test(parser.source.charAt(parser.offset))) {
            parser.offset += 1;
        }
    }

    function richCssReadStatement(parser) {
        var start = parser.offset;
        var quote = '';
        var parentheses = 0;
        var brackets = 0;
        for (; parser.offset < parser.source.length; parser.offset += 1) {
            var current = parser.source.charAt(parser.offset);
            if (quote !== '') {
                if (current === quote) {
                    quote = '';
                }
                continue;
            }
            if (current === '"' || current === "'") {
                quote = current;
                continue;
            }
            if (current === '(') {
                parentheses += 1;
                continue;
            }
            if (current === ')') {
                parentheses -= 1;
            } else if (current === '[') {
                brackets += 1;
            } else if (current === ']') {
                brackets -= 1;
            }
            if (parentheses < 0 || brackets < 0) {
                throw new Error('rich-advanced-css-not-allowed');
            }
            if (parentheses !== 0 || brackets !== 0) {
                continue;
            }
            if ([';', '{', '}'].includes(current)) {
                var segment = parser.source.slice(start, parser.offset);
                parser.offset += 1;
                return [segment, current];
            }
        }
        if (quote !== '' || parentheses !== 0 || brackets !== 0) {
            throw new Error('rich-advanced-css-not-allowed');
        }
        return [parser.source.slice(start), ''];
    }

    function richCssDeclaration(parser, segment, root) {
        parser.declarations += 1;
        var colon = segment.indexOf(':');
        var property = colon < 0
            ? '' : segment.slice(0, colon).trim().toLowerCase();
        var value = colon < 0 ? '' : segment.slice(colon + 1).trim();
        var policy = parser.policy.css;
        if (
            parser.declarations > policy.max_declarations
            || !policy.properties.includes(property)
            || (root && !policy.root_properties.includes(property))
            || value === ''
            || bytes(value) > policy.max_value_bytes
            || value.includes('!')
            || /(?:^|[^a-z0-9_-])(?:attr|calc|clamp|cross-fade|element|env|expression|image|image-set|max|min|paint|repeat|src|url|var|-moz-element|-webkit-cross-fade)\s*\(/iu
                .test(value)
            || /(?:javascript|data|blob|https?)\s*:|\/\//iu.test(value)
            || value.includes('@')
            || (
                property === 'position'
                && !['absolute', 'relative', 'static'].includes(
                    value.toLowerCase()
                )
            )
            || (
                property === 'display'
                && value.toLowerCase() === 'contents'
            )
            || (
                property === 'column-count'
                && (!/^[0-9]+$/u.test(value) || Number(value) > 24)
            )
            || (
                property === 'content'
                && !/^(?:"[^"\r\n]*"|'[^'\r\n]*'|none|normal|open-quote|close-quote)$/u
                    .test(value)
            )
        ) {
            throw new Error('rich-advanced-css-not-allowed');
        }
        var magnitudeSource = value.replace(
            /(?<![A-Za-z0-9_-])#(?:[0-9A-Fa-f]{8}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{4}|[0-9A-Fa-f]{3})\b/gu,
            ''
        );
        var numbers = magnitudeSource.match(
            /(?<![A-Za-z0-9_-])-?[0-9]+(?:\.[0-9]+)?/gu
        ) || [];
        if (
            /(?:[0-9]|\.)[eE][+-]?[0-9]/u.test(magnitudeSource)
            || numbers.length > policy.max_numeric_tokens_per_value
        ) {
            throw new Error('rich-advanced-css-not-allowed');
        }
        var numericTotal = 0;
        numbers.forEach(function (number) {
            var numeric = Math.abs(Number(number));
            if (numeric > 100000) {
                throw new Error('rich-advanced-css-not-allowed');
            }
            numericTotal += numeric;
        });
        if (root && numericTotal > policy.max_root_numeric_total) {
            throw new Error('rich-advanced-css-not-allowed');
        }
        if (Array.isArray(parser.properties)) {
            parser.properties.push(property);
        }
    }

    function richCssAtRule(parser, segment) {
        var match = /^@(media|supports)\s+(.+)$/iu.exec(segment.trim());
        var condition = match ? match[2] : '';
        if (
            !match
            || !parser.policy.css.at_rules.includes(match[1].toLowerCase())
            || bytes(condition) > 512
            || /[{};@\\"']/u.test(condition)
            || /\b(?:url|var|selector|style|font-tech)\s*\(/iu
                .test(condition)
            || !/^[A-Za-z0-9\s().,:\/%_-]+$/u.test(condition)
        ) {
            throw new Error('rich-advanced-css-not-allowed');
        }
    }

    function richCssReadToken(source, offset) {
        var start = offset;
        while (/[A-Za-z0-9_-]/u.test(source.charAt(offset))) {
            offset += 1;
        }
        var token = source.slice(start, offset);
        if (!RICH_ADVANCED_TOKEN.test(token)) {
            throw new Error('rich-advanced-css-not-allowed');
        }
        return [token, offset];
    }

    function richCssAttributeSelector(value) {
        return /^\[(?:aria-hidden|dir|lang|data-content-[a-z][a-z0-9_-]{0,47})(?:\s*=\s*(?:"[A-Za-z0-9_-]{1,64}"|'[A-Za-z0-9_-]{1,64}'))?\]$/u
            .test(value);
    }

    function richCssSplitSelectors(raw) {
        var selectors = [];
        var start = 0;
        var brackets = 0;
        var parentheses = 0;
        var quote = '';
        for (var index = 0; index < raw.length; index += 1) {
            var current = raw.charAt(index);
            if (quote !== '') {
                if (current === quote) {
                    quote = '';
                }
                continue;
            }
            if (current === '"' || current === "'") {
                quote = current;
            } else if (current === '[') {
                brackets += 1;
            } else if (current === ']') {
                brackets -= 1;
            } else if (current === '(') {
                parentheses += 1;
            } else if (current === ')') {
                parentheses -= 1;
            } else if (current === ',' && brackets === 0 && parentheses === 0) {
                selectors.push(raw.slice(start, index));
                start = index + 1;
            }
            if (brackets < 0 || parentheses < 0) {
                throw new Error('rich-advanced-css-not-allowed');
            }
        }
        if (quote !== '' || brackets !== 0 || parentheses !== 0) {
            throw new Error('rich-advanced-css-not-allowed');
        }
        selectors.push(raw.slice(start));
        if (selectors.length > 16) {
            throw new Error('rich-advanced-css-not-allowed');
        }
        return selectors;
    }

    function richCssSelector(parser, raw, selectorDepth) {
        if (bytes(raw) > parser.policy.css.max_selector_bytes) {
            throw new Error('rich-advanced-css-not-allowed');
        }
        richCssSplitSelectors(raw).forEach(function (rawSelector) {
            var selector = rawSelector.trim();
            if (selector === '' || /[+~]/u.test(selector)) {
                throw new Error('rich-advanced-css-not-allowed');
            }
            var offset = 0;
            var ampersands = 0;
            while (offset < selector.length) {
                var current = selector.charAt(offset);
                if (/\s/u.test(current)) {
                    while (/\s/u.test(selector.charAt(offset))) {
                        offset += 1;
                    }
                    continue;
                }
                if (current === '&') {
                    if (ampersands > 0 || selector.slice(0, offset).trim() !== '') {
                        throw new Error('rich-advanced-css-not-allowed');
                    }
                    ampersands += 1;
                    offset += 1;
                    continue;
                }
                if (current === '>') {
                    offset += 1;
                    continue;
                }
                if (current === '.' || current === '#') {
                    var tokenResult = richCssReadToken(selector, offset + 1);
                    if (current === '.' && parser.policy.css
                        .reserved_class_prefixes
                        .some(function (prefix) {
                            return tokenResult[0].toLowerCase()
                                .startsWith(prefix);
                        })) {
                        throw new Error('rich-advanced-css-not-allowed');
                    }
                    offset = tokenResult[1];
                    continue;
                }
                if (current === '[') {
                    var end = selector.indexOf(']', offset + 1);
                    if (
                        end < 0
                        || !richCssAttributeSelector(
                            selector.slice(offset, end + 1)
                        )
                    ) {
                        throw new Error('rich-advanced-css-not-allowed');
                    }
                    offset = end + 1;
                    continue;
                }
                if (current === ':') {
                    var double = selector.charAt(offset + 1) === ':';
                    var pseudoResult = richCssReadToken(
                        selector,
                        offset + (double ? 2 : 1)
                    );
                    var pseudo = pseudoResult[0].toLowerCase();
                    offset = pseudoResult[1];
                    if (double) {
                        if (!parser.policy.css.pseudo_elements.includes(pseudo)) {
                            throw new Error('rich-advanced-css-not-allowed');
                        }
                        continue;
                    }
                    if (['nth-child', 'nth-of-type'].includes(pseudo)) {
                        var nth = /^\((odd|even|[1-9][0-9]{0,3})\)/iu
                            .exec(selector.slice(offset));
                        if (!nth) {
                            throw new Error('rich-advanced-css-not-allowed');
                        }
                        offset += nth[0].length;
                        continue;
                    }
                    if (!parser.policy.css.simple_pseudos.includes(pseudo)) {
                        throw new Error('rich-advanced-css-not-allowed');
                    }
                    continue;
                }
                if (current === '*') {
                    offset += 1;
                    continue;
                }
                if (/[A-Za-z]/u.test(current)) {
                    var tagResult = richCssReadToken(selector, offset);
                    if (!parser.policy.css.tags.includes(
                        tagResult[0].toLowerCase()
                    )) {
                        throw new Error('rich-advanced-css-not-allowed');
                    }
                    offset = tagResult[1];
                    continue;
                }
                throw new Error('rich-advanced-css-not-allowed');
            }
            if (
                (selectorDepth === 0 && ampersands > 0
                    && !/^&(?:\s|>)/u.test(selector))
                || selector === '&'
            ) {
                throw new Error('rich-advanced-css-not-allowed');
            }
        });
    }

    function richCssParseBlock(
        parser,
        allowDeclarations,
        depth,
        selectorDepth,
        expectsClosingBrace
    ) {
        if (depth > parser.policy.css.max_depth) {
            throw new Error('rich-advanced-css-not-allowed');
        }
        var found = false;
        while (true) {
            richCssSkipWhitespace(parser);
            if (parser.offset >= parser.source.length) {
                if (expectsClosingBrace) {
                    throw new Error('rich-advanced-css-not-allowed');
                }
                return found;
            }
            if (parser.source.charAt(parser.offset) === '}') {
                if (!expectsClosingBrace) {
                    throw new Error('rich-advanced-css-not-allowed');
                }
                parser.offset += 1;
                return found;
            }
            var statement = richCssReadStatement(parser);
            var segment = statement[0].trim();
            var delimiter = statement[1];
            if (segment === '') {
                throw new Error('rich-advanced-css-not-allowed');
            }
            if (delimiter === ';' || delimiter === '}') {
                if (
                    !allowDeclarations
                    || (delimiter === '}' && !expectsClosingBrace)
                ) {
                    throw new Error('rich-advanced-css-not-allowed');
                }
                richCssDeclaration(parser, segment, selectorDepth === 0);
                found = true;
                if (delimiter === '}') {
                    return found;
                }
                continue;
            }
            if (delimiter !== '{') {
                throw new Error('rich-advanced-css-not-allowed');
            }
            parser.rules += 1;
            if (parser.rules > parser.policy.css.max_rules) {
                throw new Error('rich-advanced-css-not-allowed');
            }
            if (segment.startsWith('@')) {
                richCssAtRule(parser, segment);
                if (!richCssParseBlock(
                    parser,
                    allowDeclarations,
                    depth + 1,
                    selectorDepth,
                    true
                )) {
                    throw new Error('rich-advanced-css-not-allowed');
                }
            } else {
                richCssSelector(parser, segment, selectorDepth);
                if (!richCssParseBlock(
                    parser,
                    true,
                    depth + 1,
                    selectorDepth + 1,
                    true
                )) {
                    throw new Error('rich-advanced-css-not-allowed');
                }
            }
            found = true;
        }
    }

    function richValidateAdvancedCss(value, customPolicy, propertyCollector) {
        var policy = richAdvancedPolicy(customPolicy);
        if (
            policy === null
            || typeof value !== 'string'
            || bytes(value) > MAX_CUSTOM_CSS_BYTES
            || /[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F-\u009F]/u
                .test(value)
            || /[\\<\u2028\u2029]/u.test(value)
        ) {
            throw new Error('rich-advanced-css-not-allowed');
        }
        if (/^[ \t\r\n]*$/u.test(value)) {
            return '';
        }
        var executable = richCssStripComments(value);
        if (executable === '') {
            throw new Error('rich-advanced-css-not-allowed');
        }
        var parser = {
            source: executable,
            offset: 0,
            rules: 0,
            declarations: 0,
            properties: Array.isArray(propertyCollector)
                ? propertyCollector : null,
            policy: policy
        };
        // Root declarations style the isolated module wrapper. The backend
        // keeps containment properties immutable with !important.
        if (!richCssParseBlock(parser, true, 0, 0, false)) {
            throw new Error('rich-advanced-css-not-allowed');
        }
        richCssSkipWhitespace(parser);
        if (parser.offset !== parser.source.length) {
            throw new Error('rich-advanced-css-not-allowed');
        }
        return value;
    }

    function richAdvancedCssVisualSafe(value, customPolicy) {
        if (typeof value === 'string' && /^[ \t\r\n]*$/u.test(value)) {
            return true;
        }
        var properties = [];
        try {
            richValidateAdvancedCss(value, customPolicy, properties);
        } catch (error) {
            return false;
        }
        return properties.length > 0 && properties.every(function (property) {
            return RICH_VISUAL_SAFE_CSS_PROPERTIES.includes(property);
        });
    }

    function validAdvancedCss(value, customPolicy) {
        try {
            richValidateAdvancedCss(value, customPolicy);
            return true;
        } catch (error) {
            return false;
        }
    }

    function trackV2Id(seen, value) {
        if (!UUID_V4.test(value || '') || seen.has(value)) {
            return false;
        }
        seen.add(value);
        return true;
    }

    function validV2Module(block, seen, isCover, allowIncomplete) {
        var incomplete = allowIncomplete === true;
        if (
            !block
            || typeof block !== 'object'
            || !BLOCK_TYPES.includes(block.type)
            || !trackV2Id(seen, block.id)
            || !validPresentation(block.presentation, block.type)
        ) {
            return false;
        }

        if (block.type === 'paragraph') {
            var standardParagraph = exactKeys(block, [
                'id', 'type', 'content', 'presentation'
            ]) && (
                validInline(block.content, true, true, incomplete)
                || validTextFlowContent(block.content, incomplete, seen)
            );
            var advancedParagraph = exactKeys(block, [
                'id', 'type', 'html', 'css', 'presentation'
            ])
                && validAdvancedHtml(block.html, incomplete)
                && validAdvancedCss(block.css);
            return standardParagraph || advancedParagraph;
        }
        if (block.type === 'heading') {
            return optionalPresetShape(
                block,
                ['id', 'type', 'level', 'content', 'presentation'],
                'preset',
                HEADING_PRESETS
            )
                && HEADING_LEVELS.includes(block.level)
                && validInline(block.content, false, true, incomplete);
        }
        if (block.type === 'list') {
            return optionalPresetShape(
                block,
                ['id', 'type', 'ordered', 'items', 'presentation'],
                'marker',
                LIST_MARKERS
            )
                && typeof block.ordered === 'boolean'
                && (
                    block.marker === undefined
                    || (
                        LIST_MARKERS.includes(block.marker)
                        && (block.ordered
                            ? ['decimal', 'lower-alpha', 'upper-alpha'].includes(block.marker)
                            : ['disc', 'circle', 'square'].includes(block.marker))
                    )
                )
                && Array.isArray(block.items)
                && (incomplete || block.items.length >= 1)
                && block.items.length <= MAX_LIST_ITEMS
                && block.items.every(function (item) {
                    return exactKeys(item, ['id', 'content'])
                        && trackV2Id(seen, item.id)
                        && validInline(
                            item.content,
                            true,
                            true,
                            incomplete
                        );
                });
        }
        if (block.type === 'callout') {
            return exactKeys(block, [
                'id', 'type', 'tone', 'content', 'presentation'
            ])
                && ['neutral', 'info', 'warning'].includes(block.tone)
                && validInline(block.content, true, true, incomplete);
        }
        if (block.type === 'quote') {
            return exactKeys(block, [
                'id', 'type', 'content', 'author', 'source', 'preset',
                'presentation'
            ])
                && validInline(block.content, true, true, incomplete)
                && optionalSingleLine(block.author, 255)
                && optionalSingleLine(block.source, 500)
                && QUOTE_PRESETS.includes(block.preset);
        }
        if (block.type === 'link') {
            return exactKeys(block, [
                'id', 'type', 'label', 'href', 'title', 'target',
                'presentation'
            ])
                && singleLine(block.label, 255, false)
                && safeUrl(block.href)
                && optionalSingleLine(block.title, 500)
                && ['same', 'new'].includes(block.target);
        }
        if (block.type === 'image') {
            return exactKeys(block, [
                'id', 'type', 'media_asset_public_id', 'alt', 'title',
                'caption', 'decorative', 'display', 'presentation'
            ])
                && UUID_V4.test(block.media_asset_public_id || '')
                && typeof block.decorative === 'boolean'
                && singleLine(block.alt, 500, true)
                && (
                    (block.decorative && block.alt === '')
                    || (!block.decorative && block.alt !== '')
                )
                && optionalSingleLine(block.title, 500)
                && optionalSingleLine(block.caption, 2000)
                && (isCover
                    ? block.display === 'cover'
                    : ['content', 'wide'].includes(block.display));
        }
        if (block.type === 'video') {
            return exactKeys(block, [
                'id', 'type', 'provider', 'video_id', 'title',
                'start_seconds', 'presentation'
            ])
                && block.provider === 'youtube'
                && /^[A-Za-z0-9_-]{11}$/u.test(block.video_id)
                && singleLine(block.title, 500, false)
                && Number.isInteger(block.start_seconds)
                && block.start_seconds >= 0
                && block.start_seconds <= 86400;
        }
        if (block.type === 'embed') {
            return exactKeys(block, [
                'id', 'type', 'html', 'css', 'caption', 'presentation'
            ])
                && validEmbedHtml(block.html, EMBED_POLICY, incomplete)
                && validAdvancedCss(block.css, EMBED_POLICY)
                && optionalSingleLine(block.caption, 2000);
        }
        if (block.type === 'separator') {
            return exactKeys(block, [
                'id', 'type', 'line_style', 'thickness', 'color',
                'presentation'
            ])
                && SEPARATOR_LINE_STYLES.includes(block.line_style)
                && SEPARATOR_THICKNESSES.includes(block.thickness)
                && validPresentationTextColor(block.color)
                && block.color !== 'default';
        }

        return exactKeys(block, [
            'id', 'type', 'label', 'href', 'title', 'target', 'variant',
            'presentation'
        ])
            && singleLine(block.label, 255, false)
            && safeUrl(block.href)
            && optionalSingleLine(block.title, 500)
            && ['same', 'new'].includes(block.target)
            && CTA_PRESETS.includes(block.variant);
    }

    function validV2Children(
        children,
        ownerType,
        seen,
        depth,
        counter,
        allowIncomplete
    ) {
        if (!Array.isArray(children)) {
            return false;
        }

        return children.every(function (child) {
            counter.value += 1;
            if (counter.value > MAX_BLOCKS || !child || typeof child !== 'object') {
                return false;
            }
            if (BLOCK_TYPES.includes(child.type)) {
                return validV2Module(
                    child,
                    seen,
                    false,
                    allowIncomplete
                );
            }
            if (child.type === 'article') {
                return ownerType === 'section'
                    && validV2Container(
                        child,
                        seen,
                        0,
                        counter,
                        allowIncomplete
                    );
            }
            if (child.type === 'div') {
                var nextDepth = ownerType === 'div' ? depth + 1 : 1;
                return nextDepth <= MAX_DIV_DEPTH
                    && validV2Container(
                        child,
                        seen,
                        nextDepth,
                        counter,
                        allowIncomplete
                    );
            }
            return false;
        });
    }

    function v2SectionHeadingModule(node) {
        if (!node || typeof node !== 'object') {
            return false;
        }
        if (node.type === 'heading') {
            return HEADING_LEVELS.includes(node.level);
        }
        if (node.type !== 'paragraph') {
            return false;
        }
        var leadingHeading = v2LeadingTextHeading(node);
        if (leadingHeading !== null) {
            return HEADING_LEVELS.includes(leadingHeading.level);
        }
        if (typeof node.html === 'string') {
            try {
                var parsed = richParseAdvancedHtml(node.html);
                if (parsed === null) {
                    return false;
                }
                var firstRoot = Array.from(parsed.body.childNodes).find(
                    function (child) {
                        return (
                            child.nodeType === Node.ELEMENT_NODE
                            && child.nodeName.toLowerCase() !== 'br'
                        )
                            || (
                                child.nodeType === Node.TEXT_NODE
                                && (child.nodeValue || '').trim() !== ''
                            );
                    }
                );
                return firstRoot
                    && firstRoot.nodeType === Node.ELEMENT_NODE
                    && /^h[2-6]$/u.test(firstRoot.nodeName.toLowerCase())
                    && HEADING_LEVELS.includes(
                        Number(firstRoot.nodeName.slice(1))
                    );
            } catch (error) {
                return false;
            }
        }
        return false;
    }

    function v2TextHeadings(node) {
        if (
            !node
            || node.type !== 'paragraph'
            || !isTextFlowContent(node.content)
        ) {
            return [];
        }
        return node.content.filter(function (flowNode) {
            return flowNode
                && flowNode.type === 'heading'
                && HEADING_LEVELS.includes(flowNode.level);
        });
    }

    function v2LeadingTextHeading(node) {
        if (
            !node
            || node.type !== 'paragraph'
            || !Array.isArray(node.content)
            || !node.content.find(function (flowNode) {
                return flowNode && flowNode.type !== 'break';
            })
        ) {
            return null;
        }
        var firstMeaningful = node.content.find(function (flowNode) {
            return flowNode && flowNode.type !== 'break';
        });
        return firstMeaningful.type === 'heading' ? firstMeaningful : null;
    }

    function validV2Container(
        container,
        seen,
        depth,
        counter,
        allowIncomplete
    ) {
        var incomplete = allowIncomplete === true;
        if (!trackV2Id(seen, container.id)) {
            return false;
        }
        var hasPresentation = Object.prototype.hasOwnProperty.call(
            container,
            'presentation'
        );
        if (hasPresentation && !validContainerPresentation(
            container.presentation,
            container.type
        )) {
            return false;
        }
        if (container.type === 'section') {
            return exactKeys(
                container,
                hasPresentation
                    ? ['id', 'type', 'children', 'presentation']
                    : ['id', 'type', 'children']
            )
                && Array.isArray(container.children)
                && (
                    incomplete
                        ? (
                            container.children.length === 0
                            || v2SectionHeadingModule(container.children[0])
                        )
                        : (
                            container.children.length >= 1
                            && v2SectionHeadingModule(container.children[0])
                        )
                )
                && validV2Children(
                    container.children,
                    'section',
                    seen,
                    0,
                    counter,
                    incomplete
                );
        }
        if (
            !['article', 'div'].includes(container.type)
            || !exactKeys(
                container,
                hasPresentation
                    ? ['id', 'type', 'layout', 'presentation']
                    : ['id', 'type', 'layout']
            )
            || !exactKeys(container.layout, ['preset', 'columns'])
            || !Object.prototype.hasOwnProperty.call(
                COLUMN_PRESETS,
                container.layout.preset
            )
            || !Array.isArray(container.layout.columns)
            || container.layout.columns.length
                !== COLUMN_PRESETS[container.layout.preset]
        ) {
            return false;
        }

        var columnsValid = container.layout.columns.every(function (column) {
            return exactKeys(column, ['id', 'children'])
                && trackV2Id(seen, column.id)
                && validV2Children(
                    column.children,
                    container.type,
                    seen,
                    depth,
                    counter,
                    incomplete
                );
        });
        return columnsValid;
    }

    function countV2Heading(node, level) {
        var count = 0;
        function visit(value) {
            if (!value || typeof value !== 'object') {
                return;
            }
            if (value.type === 'heading' && value.level === level) {
                count += 1;
            }
            if (value.type === 'section' && Array.isArray(value.children)) {
                value.children.forEach(visit);
            }
            if (
                ['article', 'div'].includes(value.type)
                && value.layout
                && Array.isArray(value.layout.columns)
            ) {
                value.layout.columns.forEach(function (column) {
                    column.children.forEach(visit);
                });
            }
        }
        visit(node);
        return count;
    }

    function validHeaderSelection(value, template) {
        if (
            !plainObject(value)
            || !exactKeys(value, ['hero', 'h1_module'])
            || !(value.hero === null || HERO_KEYS.includes(value.hero))
            || !H1_MODULE_KEYS.includes(value.h1_module)
        ) {
            return false;
        }
        return value.hero === null
            ? template === TEMPLATE_BASIC
            : templateForHero(value.hero) === template;
    }

    function validV2DocumentWithMode(documentValue, allowIncomplete) {
        var incomplete = allowIncomplete === true;
        var hasHeader = plainObject(documentValue)
            && Object.prototype.hasOwnProperty.call(documentValue, 'header');
        if (
            !exactKeys(
                documentValue,
                hasHeader
                    ? ['schema', 'version', 'template', 'header', 'blocks']
                    : ['schema', 'version', 'template', 'blocks']
            )
            || documentValue.schema !== SCHEMA
            || documentValue.version !== VERSION
            || !TEMPLATES.includes(documentValue.template)
            || (hasHeader && !validHeaderSelection(
                documentValue.header,
                documentValue.template
            ))
            || !Array.isArray(documentValue.blocks)
        ) {
            return false;
        }

        var seen = new Set();
        var counter = { value: 0 };
        var offset = 0;
        if (templateHasCover(documentValue.template)) {
            var cover = documentValue.blocks[0];
            if (cover && typeof cover === 'object' && cover.type === 'image') {
                if (!validV2Module(cover, seen, true, incomplete)) {
                    return false;
                }
                counter.value += 1;
                offset = 1;
            } else if (!incomplete) {
                return false;
            }
        }
        if (!incomplete && documentValue.blocks.length <= offset) {
            return false;
        }

        for (var index = offset; index < documentValue.blocks.length; index += 1) {
            counter.value += 1;
            if (
                counter.value > MAX_BLOCKS
                || !documentValue.blocks[index]
                || typeof documentValue.blocks[index] !== 'object'
                || documentValue.blocks[index].type !== 'section'
                || !validV2Container(
                    documentValue.blocks[index],
                    seen,
                    0,
                    counter,
                    incomplete
                )
            ) {
                return false;
            }
        }

        return bytes(JSON.stringify(documentValue)) <= MAX_JSON_BYTES;
    }

    function validV2Document(documentValue) {
        return validV2DocumentWithMode(documentValue, false);
    }

    function validV2DraftDocument(documentValue) {
        return validV2DocumentWithMode(documentValue, true);
    }

    function validDocument(documentValue) {
        if (documentValue && documentValue.version === LEGACY_VERSION) {
            return validLegacyDocument(documentValue);
        }
        return validV2Document(documentValue);
    }

    function validEditorDocument(documentValue) {
        if (documentValue && documentValue.version === VERSION) {
            return validV2DraftDocument(documentValue);
        }
        return validLegacyDocument(documentValue, true);
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
        if (settings.placeholder) {
            control.placeholder = settings.placeholder;
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

    function tooltip(button, label) {
        if (!(button instanceof HTMLElement) || typeof label !== 'string') {
            return button;
        }
        button.dataset.blogTooltip = label;
        var visual = button.querySelector(':scope > .blogEditor__tooltip');
        if (!(visual instanceof HTMLElement)) {
            visual = element('span', 'blogEditor__tooltip');
            visual.setAttribute('aria-hidden', 'true');
            button.append(visual);
        }
        visual.textContent = label;
        return button;
    }

    function createEditorDialog(context) {
        var dialog = document.createElement('dialog');
        var titleId = controlId(context, 'editor-dialog-title');
        var messageId = controlId(context, 'editor-dialog-message');
        dialog.className = 'blogEditor__confirmDialog';
        dialog.setAttribute('aria-labelledby', titleId);
        dialog.setAttribute('aria-describedby', messageId);
        dialog.setAttribute('aria-modal', 'true');

        var shell = element('div', 'blogEditor__confirmDialogShell');
        var title = element('h2', 'blogEditor__confirmDialogTitle');
        title.id = titleId;
        var message = element('p', 'blogEditor__confirmDialogMessage');
        message.id = messageId;
        var actions = element('div', 'blogEditor__confirmDialogActions');
        var cancelButton = element(
            'button',
            'blogEditor__confirmDialogCancel',
            'Cancelar'
        );
        cancelButton.type = 'button';
        var confirmButton = element(
            'button',
            'blogEditor__confirmDialogConfirm',
            'Confirmar'
        );
        confirmButton.type = 'button';
        actions.append(cancelButton, confirmButton);
        shell.append(title, message, actions);
        dialog.append(shell);
        document.body.append(dialog);

        var pending = null;

        function restoreFocus(request) {
            if (
                request
                && request.trigger instanceof HTMLElement
                && request.trigger.isConnected
            ) {
                request.trigger.focus();
            }
        }

        function settle(confirmed) {
            if (!pending) {
                return;
            }
            var request = pending;
            pending = null;
            request.resolve(Boolean(confirmed));
            window.requestAnimationFrame(function () {
                restoreFocus(request);
            });
        }

        function close(confirmed) {
            dialog.returnValue = confirmed ? 'confirm' : 'cancel';
            if (dialog.open && typeof dialog.close === 'function') {
                dialog.close(dialog.returnValue);
                return;
            }
            dialog.removeAttribute('open');
            settle(confirmed);
        }

        cancelButton.addEventListener('click', function () {
            close(false);
        });
        confirmButton.addEventListener('click', function () {
            close(true);
        });
        dialog.addEventListener('cancel', function (event) {
            event.preventDefault();
            close(false);
        });
        dialog.addEventListener('close', function () {
            settle(dialog.returnValue === 'confirm');
        });
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                close(false);
            }
        });

        return {
            show: function (options, trigger) {
                var settings = options || {};
                if (pending) {
                    var previous = pending;
                    pending = null;
                    previous.resolve(false);
                    if (dialog.open && typeof dialog.close === 'function') {
                        dialog.close('cancel');
                    } else {
                        dialog.removeAttribute('open');
                    }
                }
                title.textContent = settings.title || 'Confirmar acci\u00f3n';
                message.textContent = settings.message || '';
                confirmButton.textContent = settings.confirmLabel || 'Confirmar';
                cancelButton.textContent = settings.cancelLabel || 'Cancelar';
                cancelButton.hidden = settings.notice === true;
                confirmButton.dataset.variant = settings.danger
                    ? 'danger'
                    : 'default';
                return new Promise(function (resolve) {
                    pending = {
                        resolve: resolve,
                        trigger: trigger instanceof HTMLElement
                            ? trigger
                            : document.activeElement
                    };
                    if (typeof dialog.showModal === 'function') {
                        dialog.showModal();
                    } else {
                        dialog.setAttribute('open', '');
                    }
                    window.requestAnimationFrame(function () {
                        (settings.notice ? confirmButton : cancelButton).focus();
                    });
                });
            }
        };
    }

    function confirmEditorAction(context, options, trigger) {
        if (!context.editorDialog) {
            context.editorDialog = createEditorDialog(context);
        }
        return context.editorDialog.show(options, trigger);
    }

    function createEditorNavigationDialog(context) {
        var dialog = document.createElement('dialog');
        var titleId = controlId(context, 'navigation-dialog-title');
        var messageId = controlId(context, 'navigation-dialog-message');
        dialog.className = 'blogEditor__confirmDialog';
        dialog.setAttribute('aria-labelledby', titleId);
        dialog.setAttribute('aria-describedby', messageId);
        dialog.setAttribute('aria-modal', 'true');

        var shell = element('div', 'blogEditor__confirmDialogShell');
        var title = element(
            'h2',
            'blogEditor__confirmDialogTitle',
            'Hay cambios sin guardar'
        );
        title.id = titleId;
        var message = element(
            'p',
            'blogEditor__confirmDialogMessage',
            'Elige si quieres guardar el borrador antes de salir.'
        );
        message.id = messageId;
        var actions = element('div', 'blogEditor__confirmDialogActions');
        var stayButton = element(
            'button',
            'blogEditor__confirmDialogCancel',
            'Seguir editando'
        );
        stayButton.type = 'button';
        var discardButton = element(
            'button',
            'blogEditor__confirmDialogConfirm',
            'Salir sin guardar el contenido'
        );
        discardButton.type = 'button';
        discardButton.dataset.variant = 'danger';
        var saveButton = element(
            'button',
            'blogEditor__confirmDialogConfirm',
            'Guardar y salir'
        );
        saveButton.type = 'button';
        actions.append(stayButton, discardButton, saveButton);
        shell.append(title, message, actions);
        dialog.append(shell);
        var host = context.form.closest('.webadmin') || document.body;
        host.append(dialog);

        var pending = null;

        function restoreFocus(request) {
            if (
                request
                && request.trigger instanceof HTMLElement
                && request.trigger.isConnected
            ) {
                request.trigger.focus();
            }
        }

        function settle(choice) {
            if (!pending) {
                return;
            }
            var request = pending;
            pending = null;
            request.resolve(choice);
            window.requestAnimationFrame(function () {
                restoreFocus(request);
            });
        }

        function close(choice) {
            var selected = ['save', 'discard'].includes(choice)
                ? choice
                : 'stay';
            dialog.returnValue = selected;
            if (dialog.open && typeof dialog.close === 'function') {
                dialog.close(selected);
                return;
            }
            dialog.removeAttribute('open');
            settle(selected);
        }

        stayButton.addEventListener('click', function () {
            close('stay');
        });
        discardButton.addEventListener('click', function () {
            close('discard');
        });
        saveButton.addEventListener('click', function () {
            close('save');
        });
        dialog.addEventListener('cancel', function (event) {
            event.preventDefault();
            close('stay');
        });
        dialog.addEventListener('close', function () {
            settle(dialog.returnValue);
        });
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                close('stay');
            }
        });

        return {
            dialog: dialog,
            show: function (trigger, canSave) {
                if (pending) {
                    return pending.promise;
                }
                saveButton.hidden = !canSave;
                message.textContent = canSave
                    ? 'Elige si quieres guardar el contenido antes de salir. Las categor\u00edas y etiquetas confirmadas se guardan aparte en el borrador privado.'
                    : 'El guardado autom\u00e1tico del contenido no est\u00e1 disponible. Puedes seguir editando o salir sin guardar el contenido. Las categor\u00edas y etiquetas confirmadas se guardan aparte en el borrador privado.';
                var promise = new Promise(function (resolve) {
                    pending = {
                        promise: null,
                        resolve: resolve,
                        trigger: trigger instanceof HTMLElement
                            ? trigger
                            : document.activeElement
                    };
                    if (typeof dialog.showModal === 'function') {
                        dialog.showModal();
                    } else {
                        dialog.setAttribute('open', '');
                    }
                    window.requestAnimationFrame(function () {
                        stayButton.focus();
                    });
                });
                pending.promise = promise;
                return promise;
            }
        };
    }

    function requestEditorNavigation(context, trigger, canSave) {
        if (!context.navigationDialog) {
            context.navigationDialog = createEditorNavigationDialog(context);
        }
        return context.navigationDialog.show(trigger, canSave);
    }

    function submitEditorLogout(context, logoutForm, submitter) {
        context.logoutBypassForm = logoutForm;
        context.allowNavigation = true;
        try {
            if (typeof logoutForm.requestSubmit === 'function') {
                if (
                    submitter instanceof HTMLElement
                    && submitter.form === logoutForm
                ) {
                    logoutForm.requestSubmit(submitter);
                } else {
                    logoutForm.requestSubmit();
                }
            } else {
                HTMLFormElement.prototype.submit.call(logoutForm);
            }
        } catch (error) {
            context.logoutBypassForm = null;
            context.allowNavigation = false;
            context.navigationPending = false;
            throw error;
        }
    }

    function bindEditorLogout(
        context,
        shell,
        hasUnsavedChanges,
        prepareSave
    ) {
        shell.addEventListener('submit', function (event) {
            var logoutForm = event.target;
            if (
                !(logoutForm instanceof HTMLFormElement)
                || !logoutForm.classList.contains('webadminShell-logout')
                || logoutForm.method.toLowerCase() !== 'post'
            ) {
                return;
            }
            if (context.logoutBypassForm === logoutForm) {
                context.logoutBypassForm = null;
                return;
            }
            if (context.readOnly) {
                return;
            }

            var dirty = true;
            try {
                dirty = hasUnsavedChanges();
            } catch (error) {
                event.preventDefault();
                announce(
                    context,
                    'No se pudo comprobar el borrador. Sigues en el editor.',
                    true
                );
                return;
            }
            if (!dirty) {
                return;
            }

            event.preventDefault();
            if (context.navigationPending) {
                return;
            }
            context.navigationPending = true;
            var canSave = typeof prepareSave === 'function'
                && typeof window.fetch === 'function';
            Promise.resolve().then(function () {
                return requestEditorNavigation(
                    context,
                    event.submitter || logoutForm,
                    canSave
                );
            }).then(function (choice) {
                if (choice === 'stay') {
                    context.navigationPending = false;
                    return;
                }
                if (choice === 'discard') {
                    return discardEditorialFacets(context).then(
                        function (ready) {
                            if (!ready) {
                                context.allowNavigation = false;
                                context.navigationPending = false;
                                announce(
                                    context,
                                    'No se complet\u00f3 el guardado pendiente de categor\u00edas o etiquetas. La sesi\u00f3n sigue abierta para reintentarlo.',
                                    true
                                );
                                return;
                            }
                            submitEditorLogout(
                                context,
                                logoutForm,
                                event.submitter
                            );
                        }
                    );
                }
                if (choice !== 'save' || !canSave) {
                    context.navigationPending = false;
                    return;
                }
                return prepareSave().then(function (ready) {
                    if (!ready) {
                        context.navigationPending = false;
                        return;
                    }
                    submitEditorLogout(
                        context,
                        logoutForm,
                        event.submitter
                    );
                });
            }).catch(function () {
                context.allowNavigation = false;
                context.navigationPending = false;
                announce(
                    context,
                    'No se pudo cerrar la sesión. Sigues en el editor.',
                    true
                );
            });
        });
    }

    function showEditorServerNotice(context, error, message, trigger) {
        if (![
            'session_expired',
            'forbidden',
            'lock_conflict',
            'not_found'
        ].includes(v2SaveErrorCode(error))) {
            return;
        }
        confirmEditorAction(context, {
            title: 'No se pudo completar la acci\u00f3n',
            message: message,
            confirmLabel: 'Entendido',
            notice: true
        }, trigger);
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
        function visit(node) {
            if (!node || typeof node !== 'object') {
                return;
            }
            if (typeof node.id === 'string') {
                ids.add(node.id);
            }
            if (node.type === 'list' && Array.isArray(node.items)) {
                node.items.forEach(visit);
            }
            if (
                node.type === 'paragraph'
                && isTextFlowContent(node.content)
            ) {
                node.content.forEach(function (flowNode) {
                    if (flowNode.type === 'list' && Array.isArray(flowNode.items)) {
                        flowNode.items.forEach(visit);
                    }
                });
            }
            if (node.type === 'section' && Array.isArray(node.children)) {
                node.children.forEach(visit);
            }
            if (
                ['article', 'div'].includes(node.type)
                && node.layout
                && Array.isArray(node.layout.columns)
            ) {
                node.layout.columns.forEach(function (column) {
                    visit(column);
                    if (Array.isArray(column.children)) {
                        column.children.forEach(visit);
                    }
                });
            }
        }
        documentValue.blocks.forEach(visit);
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

    function makeLegacyBlock(context, type) {
        var id = uniqueUuid(context);
        if (type === 'paragraph') {
            return { id: id, type: type, content: [textNode('')] };
        }
        if (type === 'heading') {
            return {
                id: id,
                type: type,
                level: 2,
                content: [textNode('')]
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
        if (type === 'quote') {
            return {
                id: id,
                type: type,
                content: [textNode('Nueva cita')],
                author: null,
                source: null,
                preset: 'default'
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
        if (type === 'embed') {
            return {
                id: id,
                type: type,
                html: '<p>Contenido HTML seguro</p>',
                css: '',
                caption: null
            };
        }
        if (type === 'separator') {
            return {
                id: id,
                type: type,
                line_style: 'solid',
                thickness: 'thin',
                color: 'color01'
            };
        }
        if (type === 'cta') {
            return {
                id: id,
                type: type,
                label: 'Nuevo botón',
                href: '/',
                title: null,
                target: 'same',
                variant: 'primary'
            };
        }
        throw new Error('Unsupported block type.');
    }

    function defaultPresentation(type) {
        var presentation = {
            width: 'full',
            align: 'start',
            text_align: 'start',
            size: 'm',
            spacing_before: 'none',
            spacing_after: 'none'
        };
        if (['paragraph', 'heading'].includes(type)) {
            presentation.font_weight = 'default';
            presentation.text_color = 'default';
        } else if (type === 'list') {
            presentation.text_color = 'default';
        }
        if (type === 'image') {
            presentation.radius = 'default';
        }
        return presentation;
    }

    function canonicalPresentationSize(value) {
        return {
            default: 'm',
            small: 's',
            large: 'l',
            xlarge: 'xl',
            s: 's',
            m: 'm',
            l: 'l',
            xl: 'xl'
        }[value] || null;
    }

    function canonicalRgba(value) {
        var match = typeof value === 'string'
            ? value.match(PRESENTATION_RGBA)
            : null;
        if (match === null) {
            return null;
        }
        var channels = [Number(match[1]), Number(match[2]), Number(match[3])];
        if (!channels.every(function (channel) {
            return Number.isInteger(channel) && channel >= 0 && channel <= 255;
        })) {
            return null;
        }
        var alpha = Number(match[4]);
        if (!Number.isFinite(alpha) || alpha < 0 || alpha > 1) {
            return null;
        }
        var alphaValue = alpha === 0 || alpha === 1
            ? String(alpha)
            : String(Number(alpha.toFixed(6)));
        return 'rgba(' + channels.join(', ') + ', ' + alphaValue + ')';
    }

    function colorChannels(value) {
        if (typeof value !== 'string') {
            return null;
        }
        var hex = value.trim().match(
            /^#([0-9a-f]{3}|[0-9a-f]{6})$/i
        );
        if (hex !== null) {
            var source = hex[1].length === 3
                ? hex[1].split('').map(function (channel) {
                    return channel + channel;
                }).join('')
                : hex[1];
            return [0, 2, 4].map(function (offset) {
                return Number.parseInt(source.slice(offset, offset + 2), 16);
            });
        }
        var rgba = canonicalRgba(value);
        var match = rgba === null ? null : rgba.match(PRESENTATION_RGBA);
        if (match === null) {
            return null;
        }
        var alpha = Number(match[4]);
        return [Number(match[1]), Number(match[2]), Number(match[3])].map(
            function (channel) {
                return (channel * alpha) + (255 * (1 - alpha));
            }
        );
    }

    function colorLuminance(value) {
        var channels = colorChannels(value);
        if (channels === null) {
            return null;
        }
        var linear = channels.map(function (channel) {
            channel /= 255;
            return channel <= 0.04045
                ? channel / 12.92
                : Math.pow((channel + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * linear[0] + 0.7152 * linear[1]
            + 0.0722 * linear[2];
    }

    function automaticContainerTextColor(background, palette) {
        var resolved = PRESENTATION_BACKGROUNDS.includes(background)
            ? palette[background]
            : canonicalRgba(background);
        var luminance = colorLuminance(resolved);
        return luminance !== null && luminance <= 0.179
            ? palette.color00
            : palette.color01;
    }

    function byteToHex(value) {
        return Math.max(0, Math.min(255, value))
            .toString(16)
            .padStart(2, '0');
    }

    function rgbaControls(value) {
        var canonical = canonicalRgba(value);
        var match = canonical ? canonical.match(PRESENTATION_RGBA) : null;
        return {
            color: match
                ? '#' + byteToHex(Number(match[1]))
                    + byteToHex(Number(match[2]))
                    + byteToHex(Number(match[3]))
                : '#ffffff',
            alpha: match ? Number(match[4]) : 1
        };
    }

    function rgbaFromControls(color, alpha) {
        var match = typeof color === 'string'
            ? color.match(/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i)
            : null;
        var opacity = Number(alpha);
        if (
            match === null
            || !Number.isFinite(opacity)
            || opacity < 0
            || opacity > 1
        ) {
            return null;
        }
        return canonicalRgba(
            'rgba('
                + parseInt(match[1], 16) + ', '
                + parseInt(match[2], 16) + ', '
                + parseInt(match[3], 16) + ', '
                + opacity + ')'
        );
    }

    function validHeadingPresetDefinition(value) {
        return plainObject(value)
            && typeof value.token === 'string'
            && /^[a-z0-9-]+$/.test(value.token)
            && typeof value.label === 'string'
            && value.label.trim() !== ''
            && value.label.length <= 80
            && typeof value.preview_class === 'string'
            && /^[A-Za-z_][A-Za-z0-9_-]*$/.test(value.preview_class);
    }

    function validHeaderCatalogDefinition(value) {
        return plainObject(value)
            && exactKeys(value, ['key', 'resource', 'label'])
            && typeof value.key === 'string'
            && /^[A-Za-z][A-Za-z0-9]*$/.test(value.key)
            && typeof value.resource === 'string'
            && /^[A-Za-z][A-Za-z0-9]*$/.test(value.resource)
            && typeof value.label === 'string'
            && value.label.trim() !== ''
            && value.label.length <= 80;
    }

    function readHeaderCatalog(form, attribute, fallback) {
        var decoded;
        try {
            decoded = JSON.parse(form.getAttribute(attribute) || '');
        } catch (error) {
            decoded = null;
        }
        if (
            !Array.isArray(decoded)
            || decoded.length === 0
            || decoded.length > 24
            || !decoded.every(validHeaderCatalogDefinition)
            || new Set(decoded.map(function (item) {
                return item.key;
            })).size !== decoded.length
        ) {
            return fallback.map(function (item) {
                return Object.assign({}, item);
            });
        }
        return decoded.map(function (item) {
            return {
                key: item.key,
                resource: item.resource,
                label: item.label
            };
        });
    }

    function readHeaderSelection(form, template) {
        var decoded;
        try {
            decoded = JSON.parse(
                form.getAttribute('data-blog-header-selection') || ''
            );
        } catch (error) {
            decoded = null;
        }
        return validHeaderSelection(decoded, template) ? decoded : null;
    }

    function readHeadingPresetCatalog(form) {
        var encoded = form.getAttribute('data-blog-heading-presets') || '';
        var decoded;
        try {
            decoded = JSON.parse(encoded);
        } catch (error) {
            decoded = null;
        }
        if (
            !Array.isArray(decoded)
            || decoded.length === 0
            || decoded.length > 16
            || !decoded.every(validHeadingPresetDefinition)
        ) {
            return DEFAULT_HEADING_PRESET_CATALOG.slice();
        }
        var tokens = decoded.map(function (preset) {
            return preset.token;
        });
        if (
            !tokens.includes('default')
            || new Set(tokens).size !== tokens.length
        ) {
            return DEFAULT_HEADING_PRESET_CATALOG.slice();
        }
        return decoded.map(function (preset) {
            return {
                token: preset.token,
                label: preset.label,
                preview_class: preset.preview_class
            };
        });
    }

    function defaultHeadingPreference() {
        return {
            preset: 'default',
            font_size: 'default',
            font_weight: 'default',
            text_color: 'default',
            text_align: 'start'
        };
    }

    function validHeadingPreference(value, presetTokens) {
        return plainObject(value)
            && exactKeys(value, [
                'preset',
                'font_size',
                'font_weight',
                'text_color',
                'text_align'
            ])
            && presetTokens.includes(value.preset)
            && PRESENTATION_FONT_SIZES.includes(value.font_size)
            && PRESENTATION_FONT_WEIGHTS.includes(value.font_weight)
            && HEADING_PREFERENCE_TEXT_COLORS.includes(value.text_color)
            && PRESENTATION_TEXT_ALIGNS.includes(value.text_align);
    }

    function readHeadingDefaults(form, presetCatalog) {
        var defaults = {};
        [2, 3, 4, 5, 6].forEach(function (level) {
            defaults['h' + level] = defaultHeadingPreference();
        });
        var encoded = form.getAttribute('data-blog-heading-defaults') || '';
        var decoded;
        try {
            decoded = JSON.parse(encoded);
        } catch (error) {
            return defaults;
        }
        var expectedKeys = ['h2', 'h3', 'h4', 'h5', 'h6'];
        var presetTokens = presetCatalog.map(function (preset) {
            return preset.token;
        });
        if (
            !plainObject(decoded)
            || !exactKeys(decoded, expectedKeys)
            || !expectedKeys.every(function (key) {
                return validHeadingPreference(decoded[key], presetTokens);
            })
        ) {
            return defaults;
        }
        expectedKeys.forEach(function (key) {
            defaults[key] = Object.assign({}, decoded[key]);
        });
        return defaults;
    }

    function applyHeadingDefault(context, block, level) {
        var preference = context.headingDefaults['h' + level]
            || defaultHeadingPreference();
        block.level = level;
        block.preset = preference.preset;
        block.presentation = defaultPresentation('heading');
        block.presentation.size = canonicalPresentationSize(
            preference.font_size
        ) || 'm';
        block.presentation.font_weight = preference.font_weight;
        block.presentation.text_color = preference.text_color;
        block.presentation.text_align = preference.text_align;
        return block;
    }

    function makeBlock(context, type) {
        var block = makeLegacyBlock(context, type);
        if (context.documentValue.version === VERSION) {
            block.presentation = defaultPresentation(type);
            if (type === 'paragraph') {
                block.content = [{
                    type: 'paragraph',
                    content: [textNode('')]
                }];
            } else if (type === 'heading') {
                block.preset = 'default';
            } else if (type === 'list') {
                block.marker = 'disc';
            }
        }
        return block;
    }

    function nextUuid(reserved) {
        for (var attempt = 0; attempt < 8; attempt += 1) {
            var id = generateUuid();
            if (UUID_V4.test(id) && !reserved.has(id)) {
                reserved.add(id);
                return id;
            }
        }
        throw new Error('A unique UUID could not be generated.');
    }

    function moduleForV2(block, presentation) {
        var converted = JSON.parse(JSON.stringify(block));
        converted.presentation = presentation || {
            width: 'full',
            align: 'start',
            text_align: 'start'
        };
        return converted;
    }

    function unifiedTextPresentation(presentation) {
        var source = presentation || {};
        var completesListTypography = Object.prototype.hasOwnProperty.call(
            source,
            'text_color'
        ) && !Object.prototype.hasOwnProperty.call(source, 'font_weight');
        var converted = {
            width: source.width,
            align: source.align,
            text_align: source.text_align || 'start'
        };
        if (Object.prototype.hasOwnProperty.call(source, 'size')) {
            converted.size = source.size;
        } else if (Object.prototype.hasOwnProperty.call(source, 'font_size')) {
            converted.font_size = source.font_size;
        } else if (completesListTypography) {
            converted.size = 'm';
        }
        if (
            Object.prototype.hasOwnProperty.call(source, 'font_weight')
            || completesListTypography
        ) {
            converted.font_weight = completesListTypography
                ? 'default'
                : source.font_weight;
        }
        if (Object.prototype.hasOwnProperty.call(source, 'text_color')) {
            converted.text_color = source.text_color;
        }
        ['spacing_before', 'spacing_after'].forEach(function (key) {
            if (Object.prototype.hasOwnProperty.call(source, key)) {
                converted[key] = source[key];
            }
        });
        return converted;
    }

    function unifiedTextFlowNode(block) {
        var flowNode = {
            type: block.type,
            content: richClone(block.content || [])
        };
        if (block.type === 'heading') {
            flowNode.level = block.level;
            if (Object.prototype.hasOwnProperty.call(block, 'preset')) {
                flowNode.preset = block.preset;
            }
            return flowNode;
        }
        if (block.type === 'list') {
            flowNode = {
                type: 'list',
                ordered: block.ordered === true,
                items: (block.items || []).map(function (item) {
                    var convertedItem = {
                        content: richClone(item.content || [])
                    };
                    if (Object.prototype.hasOwnProperty.call(item, 'id')) {
                        convertedItem.id = item.id;
                    }
                    return convertedItem;
                })
            };
            if (Object.prototype.hasOwnProperty.call(block, 'marker')) {
                flowNode.marker = block.marker;
            }
            return flowNode;
        }
        if (block.type === 'quote') {
            ['author', 'source', 'preset'].forEach(function (key) {
                if (Object.prototype.hasOwnProperty.call(block, key)) {
                    flowNode[key] = block[key];
                }
            });
            return flowNode;
        }
        if (block.type === 'callout'
            && Object.prototype.hasOwnProperty.call(block, 'tone')) {
            flowNode.tone = block.tone;
        }
        return flowNode;
    }

    function unifiedTextModule(block) {
        return {
            id: block.id,
            type: 'paragraph',
            content: [unifiedTextFlowNode(block)],
            presentation: unifiedTextPresentation(block.presentation)
        };
    }

    function normalizeUnifiedTextModules(documentValue) {
        if (
            !documentValue
            || documentValue.version !== VERSION
            || !Array.isArray(documentValue.blocks)
        ) {
            return false;
        }
        var changed = false;

        function normalizeChildren(children) {
            children.forEach(function (child, index) {
                if (!child || typeof child !== 'object') {
                    return;
                }
                if (child.type === 'link') {
                    child.type = 'cta';
                    child.variant = 'primary';
                    if (child.presentation.text_align === 'justify') {
                        child.presentation.text_align = 'start';
                    }
                    children[index] = child;
                    changed = true;
                } else if (LEGACY_TEXT_BLOCK_TYPES.includes(child.type)) {
                    child = unifiedTextModule(child);
                    children[index] = child;
                    changed = true;
                } else if (
                    child.type === 'paragraph'
                    && Array.isArray(child.content)
                    && !isTextFlowContent(child.content)
                ) {
                    child.content = [{
                        type: 'paragraph',
                        content: richClone(child.content)
                    }];
                    changed = true;
                }
                if (child.type === 'section' && Array.isArray(child.children)) {
                    normalizeChildren(child.children);
                    return;
                }
                if (
                    ['article', 'div'].includes(child.type)
                    && child.layout
                    && Array.isArray(child.layout.columns)
                ) {
                    child.layout.columns.forEach(function (column) {
                        if (column && Array.isArray(column.children)) {
                            normalizeChildren(column.children);
                        }
                    });
                }
            });
        }

        normalizeChildren(documentValue.blocks || []);
        return changed;
    }

    function legacyDocumentToV2(documentValue) {
        if (!validLegacyDocument(documentValue)) {
            return null;
        }

        var legacyBlocks = documentValue.blocks.slice();
        var converted = {
            schema: SCHEMA,
            version: VERSION,
            template: documentValue.template,
            blocks: []
        };
        var reserved = allStructuralIds(documentValue);
        if (
            templateHasCover(documentValue.template)
            && legacyBlocks[0]
            && legacyBlocks[0].type === 'image'
        ) {
            converted.blocks.push(moduleForV2(
                legacyBlocks.shift(),
                { width: 'full', align: 'center', text_align: 'start' }
            ));
        }
        if (
            legacyBlocks.length > 0
            && !(
                legacyBlocks[0].type === 'heading'
                && legacyBlocks[0].level === 2
            )
        ) {
            return null;
        }

        var section = null;
        var articleColumn = null;
        legacyBlocks.forEach(function (legacyBlock) {
            var block = moduleForV2(legacyBlock);
            if (legacyBlock.type === 'heading' && legacyBlock.level === 2) {
                section = {
                    id: nextUuid(reserved),
                    type: 'section',
                    children: [block]
                };
                converted.blocks.push(section);
                articleColumn = null;
                return;
            }
            if (legacyBlock.type === 'heading' && legacyBlock.level === 3) {
                var article = {
                    id: nextUuid(reserved),
                    type: 'article',
                    layout: {
                        preset: '1',
                        columns: [{
                            id: nextUuid(reserved),
                            children: [block]
                        }]
                    }
                };
                section.children.push(article);
                articleColumn = article.layout.columns[0];
                return;
            }
            (articleColumn ? articleColumn.children : section.children).push(block);
        });

        var headerOnly = converted.blocks.length === (
            templateHasCover(converted.template) ? 1 : 0
        );
        if (!(headerOnly || validV2Document(converted))) {
            return null;
        }
        normalizeUnifiedTextModules(converted);
        return headerOnly || validV2Document(converted) ? converted : null;
    }

    function freshColumn(context, reserved) {
        return {
            id: reserved
                ? nextUuid(reserved)
                : uniqueUuid(context),
            children: []
        };
    }

    function makeV2Section(context) {
        var reserved = allStructuralIds(context.documentValue);
        var defaultLevel = headingPolicyForContext(context).defaults.section;
        var textModule = {
            id: nextUuid(reserved),
            type: 'paragraph',
            content: [{
                type: 'heading',
                level: defaultLevel,
                content: [textNode('')]
            }],
            presentation: defaultPresentation('paragraph')
        };
        textModule.presentation.width = '60';
        textModule.presentation.align = 'center';
        return {
            id: nextUuid(reserved),
            type: 'section',
            children: [textModule]
        };
    }

    function makeV2Container(context, type) {
        var reserved = allStructuralIds(context.documentValue);
        var article = type === 'article';
        return {
            id: nextUuid(reserved),
            type: type,
            presentation: {
                width: article ? '60' : 'full',
                align: article ? 'center' : 'start'
            },
            layout: {
                preset: '1',
                columns: [freshColumn(context, reserved)]
            }
        };
    }

    function normalizeContracts(context, notify) {
        var value = context.documentValue;
        var changed = false;
        if (
            templateHasCover(value.template)
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
            var expected = templateHasCover(value.template) && index === 0
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

    function normalizeV2Presentations(documentValue) {
        var changed = false;
        function visit(node) {
            if (!node || typeof node !== 'object') {
                return;
            }
            if (
                BLOCK_TYPES.includes(node.type)
                && node.presentation
            ) {
                if (!Object.prototype.hasOwnProperty.call(
                    node.presentation,
                    'text_align'
                )) {
                    node.presentation.text_align = 'start';
                    changed = true;
                }
            }
            if (node.type === 'section' && Array.isArray(node.children)) {
                node.children.forEach(visit);
            }
            if (
                ['article', 'div'].includes(node.type)
                && node.layout
                && Array.isArray(node.layout.columns)
            ) {
                node.layout.columns.forEach(function (column) {
                    if (Array.isArray(column.children)) {
                        column.children.forEach(visit);
                    }
                });
            }
        }
        documentValue.blocks.forEach(visit);
        return changed;
    }

    function nullable(value) {
        return value.trim() === '' ? null : value;
    }

    function renderMarks(context, node) {
        var wrapper = element('div', 'blogEditor__marks');
        var marks = Array.isArray(node.marks) ? node.marks : [];
        ['strong', 'em'].forEach(function (mark) {
            var checkbox = checkboxControl(
                context,
                'inline-' + mark,
                marks.includes(mark),
                function (checked) {
                    var selected = new Set(marks);
                    if (checked) {
                        selected.add(mark);
                    } else {
                        selected.delete(mark);
                    }
                    node.marks = canonicalMarks(
                        Array.from(selected),
                        context.documentValue.version === VERSION
                    );
                    marks = node.marks;
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
        var placeholder = title.includes('párrafo')
            ? 'Escribe el párrafo'
            : (
                title.includes('encabezado') || title.includes('Título H2')
                    ? 'Escribe el título'
                    : ''
            );

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
                        {
                            multiline: true,
                            rows: 2,
                            maxLength: 20000,
                            placeholder: placeholder
                        }
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
        if (context.documentValue.version === VERSION) {
            return headingPolicyForContext(context).allowed_levels.includes(level);
        }
        var candidate = JSON.parse(JSON.stringify(context.documentValue));
        candidate.blocks[blockIndex].level = level;
        return validLegacyDocument(candidate, true);
    }

    function listItemsFromLines(context, existingItems, value) {
        var reserved = new Set(existingItems.map(function (item) {
            return item.id;
        }));
        var lines = String(value || '')
            .replace(/\r\n?/gu, '\n')
            .split('\n')
            .map(function (line) { return line.trim(); })
            .filter(function (line) { return line !== ''; })
            .slice(0, MAX_LIST_ITEMS);
        return lines.map(function (line, index) {
            var existing = existingItems[index];
            if (
                existing
                && richInlinePlainText(existing.content) === line
            ) {
                return richClone(existing);
            }
            var id = existing && UUID_V4.test(existing.id || '')
                    ? existing.id
                    : uniqueUuid(context, reserved);
            reserved.add(id);
            return {
                id: id,
                content: [textNode(line)]
            };
        });
    }

    function listItemsAsLines(items) {
        return items.map(function (item) {
            return richInlineTextValue(item.content).trim();
        }).join('\n');
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
        var textarea = inputControl(
            context,
            'list-lines',
            listItemsAsLines(block.items),
            function (value) {
                block.items = listItemsFromLines(context, block.items, value);
            },
            {
                multiline: true,
                rows: 10,
                placeholder: 'Un elemento por línea'
            }
        );
        textarea.className = 'blogEditor__listTextarea';
        editor.append(
            field('Elementos de la lista', textarea),
            element(
                'p',
                'blogEditor__fieldHelp',
                'Cada línea no vacía se convierte en un elemento.'
            )
        );
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
        var mediaField = field(
            'Imagen de la biblioteca',
            selectControl(
                context,
                'image-media',
                block.media_asset_public_id,
                mediaOptions,
                function (value) { block.media_asset_public_id = value; }
            )
        );
        if (context.mediaDialog && !context.readOnly) {
            var chooseMedia = element(
                'button',
                'blogEditor__mediaChooseButton',
                'Elegir o subir imagen'
            );
            chooseMedia.type = 'button';
            chooseMedia.addEventListener('click', function () {
                openMediaDialog(
                    context,
                    block.media_asset_public_id,
                    function (media) {
                        block.media_asset_public_id = media.publicId;
                        render(context);
                        announce(context, 'Imagen del bloque actualizada.', false);
                    }
                );
            });
            mediaField.append(chooseMedia);
        }
        fragment.append(mediaField);
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
        var isCover = templateHasCover(context.documentValue.template)
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
        } else if (block.type === 'quote') {
            fragment.append(renderInlineEditor(
                context,
                block.content,
                true,
                'Texto de la cita',
                block.id
            ));
            fragment.append(
                field(
                    'Autor opcional',
                    inputControl(
                        context,
                        'quote-author',
                        block.author || '',
                        function (value) { block.author = nullable(value); },
                        { maxLength: 255 }
                    )
                ),
                field(
                    'Fuente opcional',
                    inputControl(
                        context,
                        'quote-source',
                        block.source || '',
                        function (value) { block.source = nullable(value); },
                        { maxLength: 500 }
                    )
                )
            );
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
        } else if (block.type === 'embed') {
            fragment.append(
                field(
                    'Descripción opcional',
                    inputControl(
                        context,
                        'embed-caption',
                        block.caption || '',
                        function (value) { block.caption = nullable(value); },
                        { maxLength: 2000 }
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
            (nodeValue.marks || []).filter(function (mark) {
                return ['strong', 'em', 'underline'].includes(mark);
            }).slice().reverse().forEach(function (mark) {
                var wrapper = document.createElement(
                    mark === 'underline' ? 'u' : mark
                );
                wrapper.append(child);
                child = wrapper;
            });
            var styleMarks = (nodeValue.marks || []).filter(function (mark) {
                return Object.prototype.hasOwnProperty.call(
                    RICH_MARK_CLASSES,
                    mark
                ) || canonicalDynamicRichMark(mark) === mark;
            });
            if (styleMarks.length > 0) {
                var style = document.createElement('span');
                style.className = styleMarks.map(function (mark) {
                    if (mark.startsWith('text-rgba:')) {
                        return 'blogEditor__inline--text-rgba';
                    }
                    if (mark.startsWith('background-rgba:')) {
                        return 'blogEditor__inline--background-rgba';
                    }
                    return RICH_MARK_CLASSES[mark] || '';
                }).filter(Boolean).join(' ');
                styleMarks.forEach(function (mark) {
                    if (richMarkInGroup('size', mark)) {
                        style.dataset.lsSize = mark.slice(5);
                    } else if (mark.startsWith('text-rgba:')) {
                        var textRgba = mark.slice('text-rgba:'.length);
                        style.dataset.lsTextRgba = textRgba;
                        style.style.setProperty(
                            '--blog-editor-inline-text-rgba',
                            textRgba
                        );
                    } else if (mark.startsWith('background-rgba:')) {
                        var backgroundRgba = mark.slice(
                            'background-rgba:'.length
                        );
                        style.dataset.lsBackgroundRgba = backgroundRgba;
                        style.style.setProperty(
                            '--blog-editor-inline-background-rgba',
                            backgroundRgba
                        );
                    } else if (richMarkInGroup('color', mark)) {
                        style.dataset.lsTextColor = mark.slice(5);
                    } else if (richMarkInGroup('background', mark)) {
                        style.dataset.lsBackgroundColor = mark.slice(11);
                    }
                });
                style.append(child);
                child = style;
            }
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

    function richNodeLength(node) {
        return node.type === 'break' ? 1 : node.text.length;
    }

    function richTextChunks(value, maxBytes) {
        var textValue = String(value || '');
        var limit = Number.isInteger(maxBytes) && maxBytes > 0
            ? maxBytes : MAX_INLINE_TEXT_BYTES;
        if (textValue === '' || bytes(textValue) <= limit) {
            return [textValue];
        }
        var points = Array.from(textValue);
        var chunks = [];
        var offset = 0;
        while (offset < points.length) {
            var low = 1;
            var high = points.length - offset;
            var accepted = 1;
            while (low <= high) {
                var middle = Math.floor((low + high) / 2);
                var candidate = points.slice(offset, offset + middle).join('');
                if (bytes(candidate) <= limit) {
                    accepted = middle;
                    low = middle + 1;
                } else {
                    high = middle - 1;
                }
            }
            chunks.push(points.slice(offset, offset + accepted).join(''));
            offset += accepted;
        }
        return chunks;
    }

    function richCloneSegment(node, text) {
        var clone = {
            type: node.type,
            text: text,
            marks: canonicalMarks(node.marks || [], true)
        };
        if (node.type === 'link') {
            clone.href = node.href;
            clone.title = node.title;
            clone.target = node.target;
        }
        return clone;
    }

    function richSameNodeFormat(left, right) {
        if (
            left.type !== right.type
            || left.type === 'break'
            || right.type === 'break'
            || left.marks.length !== right.marks.length
            || !left.marks.every(function (mark, index) {
                return mark === right.marks[index];
            })
        ) {
            return false;
        }
        return left.type !== 'link' || (
            left.href === right.href
            && left.title === right.title
            && left.target === right.target
        );
    }

    function richMergeContent(content) {
        var merged = [];
        content.forEach(function (node) {
            if (node.type !== 'break' && node.text === '') {
                return;
            }
            var previous = merged[merged.length - 1];
            if (
                previous
                && richSameNodeFormat(previous, node)
                && bytes(previous.text + node.text)
                    <= MAX_INLINE_TEXT_BYTES
            ) {
                previous.text += node.text;
                return;
            }
            merged.push(node.type === 'break'
                ? { type: 'break' }
                : richCloneSegment(node, node.text));
        });
        return merged;
    }

    function richSelectedNodes(content, start, end) {
        var cursor = 0;
        var selected = [];
        content.forEach(function (node) {
            var next = cursor + richNodeLength(node);
            if (node.type !== 'break' && start < next && end > cursor) {
                selected.push(node);
            }
            cursor = next;
        });
        return selected;
    }

    function richTransformRange(content, start, end, transform) {
        var cursor = 0;
        var output = [];
        content.forEach(function (node) {
            var length = richNodeLength(node);
            var next = cursor + length;
            if (
                node.type === 'break'
                || end <= cursor
                || start >= next
            ) {
                output.push(node.type === 'break'
                    ? { type: 'break' }
                    : richCloneSegment(node, node.text));
                cursor = next;
                return;
            }

            var localStart = Math.max(0, start - cursor);
            var localEnd = Math.min(length, end - cursor);
            if (localStart > 0) {
                output.push(richCloneSegment(
                    node,
                    node.text.slice(0, localStart)
                ));
            }
            var selected = richCloneSegment(
                node,
                node.text.slice(localStart, localEnd)
            );
            var transformed = transform(selected);
            if (Array.isArray(transformed)) {
                transformed.forEach(function (candidate) {
                    output.push(candidate);
                });
            } else {
                output.push(transformed);
            }
            if (localEnd < length) {
                output.push(richCloneSegment(
                    node,
                    node.text.slice(localEnd)
                ));
            }
            cursor = next;
        });
        return richMergeContent(output);
    }

    function richApplyMark(content, start, end, mark, group, removeOverride) {
        var selected = richSelectedNodes(content, start, end);
        if (selected.length === 0) {
            return content;
        }
        var shouldRemove = !group
            && (
                typeof removeOverride === 'boolean'
                    ? removeOverride
                    : selected.every(function (node) {
                        return node.marks.includes(mark);
                    })
            );
        return richTransformRange(content, start, end, function (node) {
            var marks = node.marks.slice();
            if (typeof group === 'string') {
                marks = marks.filter(function (candidate) {
                    return !richMarkInGroup(group, candidate);
                });
                if (mark !== '') {
                    marks.push(mark);
                }
            } else if (shouldRemove) {
                marks = marks.filter(function (candidate) {
                    return candidate !== mark;
                });
            } else if (!marks.includes(mark)) {
                marks.push(mark);
            }
            node.marks = canonicalMarks(marks, true);
            return node;
        });
    }

    function richClearMarks(content, start, end) {
        return richTransformRange(content, start, end, function (node) {
            node.marks = [];
            return node;
        });
    }

    function richApplyLink(content, start, end, linkValue) {
        return richTransformRange(content, start, end, function (node) {
            node.type = 'link';
            node.href = linkValue.href;
            node.title = linkValue.title;
            node.target = linkValue.target;
            return node;
        });
    }

    function richRemoveLink(content, start, end) {
        return richTransformRange(content, start, end, function (node) {
            if (node.type === 'link') {
                node = {
                    type: 'text',
                    text: node.text,
                    marks: node.marks
                };
            }
            return node;
        });
    }

    function richPlainLength(node) {
        if (node.nodeType === Node.TEXT_NODE) {
            return node.nodeValue ? node.nodeValue.length : 0;
        }
        if (
            node.nodeType === Node.ELEMENT_NODE
            && node.nodeName.toLowerCase() === 'br'
        ) {
            return 1;
        }
        return Array.from(node.childNodes).reduce(function (total, child) {
            return total + richPlainLength(child);
        }, 0);
    }

    function richSelectionOffsets(editor) {
        var selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) {
            return null;
        }
        var range = selection.getRangeAt(0);
        if (
            !editor.contains(range.startContainer)
            || !editor.contains(range.endContainer)
        ) {
            return null;
        }
        var startRange = document.createRange();
        startRange.selectNodeContents(editor);
        startRange.setEnd(range.startContainer, range.startOffset);
        var endRange = document.createRange();
        endRange.selectNodeContents(editor);
        endRange.setEnd(range.endContainer, range.endOffset);
        var start = richPlainLength(startRange.cloneContents());
        var end = richPlainLength(endRange.cloneContents());
        return {
            start: Math.min(start, end),
            end: Math.max(start, end)
        };
    }

    function richFlowSelection(state) {
        var selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) {
            return null;
        }
        var range = selection.getRangeAt(0);
        if (!state.visual.contains(range.commonAncestorContainer)) {
            return null;
        }
        function editableParent(node) {
            var elementValue = node.nodeType === Node.ELEMENT_NODE
                ? node
                : node.parentElement;
            return elementValue instanceof Element
                ? elementValue.closest(RICH_TEXT_FLOW_BLOCK_SELECTOR)
                : null;
        }
        var startElement = editableParent(range.startContainer);
        var endElement = editableParent(range.endContainer);
        if (
            !(startElement instanceof HTMLElement)
            || startElement !== endElement
            || !state.visual.contains(startElement)
        ) {
            return null;
        }
        var offsets = richSelectionOffsets(startElement);
        if (!offsets) {
            return null;
        }
        var flowElement = startElement.nodeName.toLowerCase() === 'li'
            ? startElement.closest('ul, ol')
            : startElement;
        var flowIndex = flowElement instanceof Element
            ? Array.from(state.visual.children).indexOf(flowElement)
            : -1;
        var itemIndex = startElement.nodeName.toLowerCase() === 'li'
            && flowElement instanceof Element
            ? Array.from(flowElement.children).indexOf(startElement)
            : null;
        if (flowIndex < 0 || (itemIndex !== null && itemIndex < 0)) {
            return null;
        }
        return {
            element: startElement,
            flowIndex: flowIndex,
            itemIndex: itemIndex,
            start: offsets.start,
            end: offsets.end
        };
    }

    function richFlowBoundaryOffset(root, container, offset) {
        if (
            !(root instanceof HTMLElement)
            || !(root === container || root.contains(container))
        ) {
            return null;
        }
        var range = document.createRange();
        range.selectNodeContents(root);
        try {
            range.setEnd(container, offset);
        } catch (error) {
            return null;
        }
        return richPlainLength(range.cloneContents());
    }

    function richFlowMultiListSelection(state) {
        var selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) {
            return [];
        }
        var range = selection.getRangeAt(0);
        if (
            range.collapsed
            || !state.visual.contains(range.commonAncestorContainer)
        ) {
            return [];
        }
        var selectedBlocks = Array.from(
            state.visual.querySelectorAll(RICH_TEXT_FLOW_BLOCK_SELECTOR)
        ).map(function (block) {
            try {
                if (!range.intersectsNode(block)) {
                    return null;
                }
            } catch (error) {
                return null;
            }
            var start = block === range.startContainer
                || block.contains(range.startContainer)
                ? richFlowBoundaryOffset(
                    block,
                    range.startContainer,
                    range.startOffset
                )
                : 0;
            var end = block === range.endContainer
                || block.contains(range.endContainer)
                ? richFlowBoundaryOffset(
                    block,
                    range.endContainer,
                    range.endOffset
                )
                : richPlainLength(block);
            return start !== null && end !== null && end > start
                ? { element: block, start: start, end: end }
                : null;
        }).filter(Boolean);
        if (
            selectedBlocks.length < 2
            || selectedBlocks.some(function (selected) {
                return selected.element.nodeName.toLowerCase() !== 'li';
            })
        ) {
            return [];
        }
        var list = selectedBlocks[0].element.parentElement;
        if (
            !(list instanceof HTMLElement)
            || !['ul', 'ol'].includes(list.nodeName.toLowerCase())
            || list.parentElement !== state.visual
            || selectedBlocks.some(function (selected) {
                return selected.element.parentElement !== list;
            })
        ) {
            return [];
        }
        var flowIndex = Array.from(state.visual.children).indexOf(list);
        if (flowIndex < 0) {
            return [];
        }
        var items = Array.from(list.children);
        return selectedBlocks.map(function (selected) {
            return {
                element: selected.element,
                flowIndex: flowIndex,
                itemIndex: items.indexOf(selected.element),
                start: selected.start,
                end: selected.end
            };
        }).filter(function (selected) {
            return selected.itemIndex >= 0;
        });
    }

    function richFlowElementAt(state, selection) {
        if (!selection || !Number.isInteger(selection.flowIndex)) {
            return null;
        }
        var flowElement = state.visual.children[selection.flowIndex];
        if (!(flowElement instanceof HTMLElement)) {
            return null;
        }
        if (selection.itemIndex === null) {
            return richFlowTypeForTag(flowElement.nodeName.toLowerCase())
                !== null ? flowElement : null;
        }
        var item = flowElement.children[selection.itemIndex];
        return item instanceof HTMLElement
            && item.nodeName.toLowerCase() === 'li'
            ? item
            : null;
    }

    function richFlowContentAt(state, selection) {
        if (!selection || !Number.isInteger(selection.flowIndex)) {
            return null;
        }
        var flowNode = state.flowDraft[selection.flowIndex];
        if (!flowNode) {
            return null;
        }
        if (selection.itemIndex === null) {
            return flowNode.type !== 'list' ? flowNode.content : null;
        }
        var item = flowNode.type === 'list'
            ? flowNode.items[selection.itemIndex]
            : null;
        return item ? item.content : null;
    }

    function richSetFlowContentAt(state, selection, content) {
        var flowNode = state.flowDraft[selection.flowIndex];
        if (selection.itemIndex === null) {
            flowNode.content = content;
            return;
        }
        flowNode.items[selection.itemIndex].content = content;
    }

    function richFlowRangeTargets(flowDraft, selections) {
        if (!Array.isArray(flowDraft) || !Array.isArray(selections)) {
            return null;
        }
        var targets = selections.map(function (selection) {
            if (
                !selection
                || !Number.isInteger(selection.flowIndex)
                || !Number.isInteger(selection.start)
                || !Number.isInteger(selection.end)
                || selection.end <= selection.start
            ) {
                return null;
            }
            var flowNode = flowDraft[selection.flowIndex];
            var content = selection.itemIndex === null
                ? (
                    flowNode && flowNode.type !== 'list'
                        ? flowNode.content
                        : null
                )
                : (
                    flowNode
                    && flowNode.type === 'list'
                    && Number.isInteger(selection.itemIndex)
                    && flowNode.items[selection.itemIndex]
                        ? flowNode.items[selection.itemIndex].content
                        : null
                );
            return Array.isArray(content)
                ? { selection: selection, content: content }
                : null;
        });
        return targets.length > 0 && targets.every(Boolean) ? targets : null;
    }

    function richFlowRangesShareList(flowDraft, selections) {
        if (!Array.isArray(selections) || selections.length < 2) {
            return false;
        }
        var flowIndex = selections[0].flowIndex;
        var flowNode = Number.isInteger(flowIndex) ? flowDraft[flowIndex] : null;
        if (!flowNode || flowNode.type !== 'list') {
            return false;
        }
        var itemIndexes = selections.map(function (selection) {
            return selection.flowIndex === flowIndex
                && Number.isInteger(selection.itemIndex)
                && flowNode.items[selection.itemIndex]
                ? selection.itemIndex
                : -1;
        });
        return itemIndexes.every(function (itemIndex) {
            return itemIndex >= 0;
        }) && new Set(itemIndexes).size === itemIndexes.length;
    }

    function richFlowRangeSelectedNodes(flowDraft, selections) {
        var targets = richFlowRangeTargets(flowDraft, selections);
        if (targets === null) {
            return [];
        }
        return targets.reduce(function (nodes, target) {
            return nodes.concat(richSelectedNodes(
                target.content,
                target.selection.start,
                target.selection.end
            ));
        }, []);
    }

    function richTransformFlowSelectionRanges(
        flowDraft,
        selections,
        transform
    ) {
        var targets = richFlowRangeTargets(flowDraft, selections);
        if (targets === null || typeof transform !== 'function') {
            return null;
        }
        var selectedNodes = richFlowRangeSelectedNodes(flowDraft, selections);
        if (selectedNodes.length === 0) {
            return null;
        }
        var transformed = targets.map(function (target) {
            return transform(
                target.content,
                target.selection.start,
                target.selection.end,
                selectedNodes
            );
        });
        if (!transformed.every(Array.isArray)) {
            return null;
        }
        targets.forEach(function (target, index) {
            var selection = target.selection;
            var flowNode = flowDraft[selection.flowIndex];
            if (selection.itemIndex === null) {
                flowNode.content = transformed[index];
            } else {
                flowNode.items[selection.itemIndex].content = transformed[index];
            }
        });
        return transformed;
    }

    function richFlowMarkTransform(mark, groupName) {
        return function (content, start, end, selectedNodes) {
            var remove = !groupName
                && selectedNodes.length > 0
                && selectedNodes.every(function (node) {
                    return node.marks.includes(mark);
                });
            return richApplyMark(
                content,
                start,
                end,
                mark,
                groupName,
                remove
            );
        };
    }

    function richFlowSelectedContent(state) {
        var selection = state.flowSelection;
        if (!selection) {
            return [];
        }
        return richFlowContentAt(state, selection) || [];
    }

    function richFlowTransform(state, transform, allowMultiple) {
        var selections = state.flowSelection
            ? [state.flowSelection]
            : (
                allowMultiple === true && Array.isArray(state.flowSelections)
                    ? state.flowSelections
                    : []
            );
        if (selections.length === 0) {
            return false;
        }
        if (
            selections.length > 1
            && !richFlowRangesShareList(state.flowDraft, selections)
        ) {
            return false;
        }
        var elements = selections.map(function (selection) {
            return richFlowElementAt(state, selection);
        });
        if (!elements.every(function (elementValue) {
            return elementValue instanceof HTMLElement;
        })) {
            return false;
        }
        var transformed = richTransformFlowSelectionRanges(
            state.flowDraft,
            selections,
            transform
        );
        if (transformed === null) {
            return false;
        }
        elements.forEach(function (elementValue, index) {
            elementValue.replaceChildren();
            appendInlinePreview(elementValue, transformed[index]);
        });
        state.visual.focus();
        if (selections.length === 1) {
            richRestoreSelection(elements[0], selections[0]);
            state.flowSelection = {
                element: elements[0],
                flowIndex: selections[0].flowIndex,
                itemIndex: selections[0].itemIndex,
                start: selections[0].start,
                end: selections[0].end
            };
            state.selection = {
                start: selections[0].start,
                end: selections[0].end
            };
        } else {
            var firstPoint = richPointAtOffset(
                elements[0],
                selections[0].start
            );
            var last = selections.length - 1;
            var lastPoint = richPointAtOffset(
                elements[last],
                selections[last].end
            );
            var range = document.createRange();
            range.setStart(firstPoint.node, firstPoint.offset);
            range.setEnd(lastPoint.node, lastPoint.offset);
            var domSelection = window.getSelection();
            if (domSelection) {
                domSelection.removeAllRanges();
                domSelection.addRange(range);
            }
            state.flowSelection = null;
        }
        state.flowSelections = selections.map(function (selection, index) {
            return {
                element: elements[index],
                flowIndex: selection.flowIndex,
                itemIndex: selection.itemIndex,
                start: selection.start,
                end: selection.end
            };
        });
        state.visualTouched = true;
        var synced = richSyncVisual(state);
        if (synced) {
            state.selectionRevision = state.documentRevision;
            state.toolbarSelectedFlowIndexes = selections.map(function (item) {
                return item.flowIndex;
            }).filter(function (flowIndex, index, values) {
                return values.indexOf(flowIndex) === index;
            });
        }
        return synced;
    }

    function richFlowSelectedIndexes(state) {
        function remembered() {
            return state.flowSelection
                && Number.isInteger(state.flowSelection.flowIndex)
                ? [state.flowSelection.flowIndex]
                : [];
        }
        var selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) {
            return remembered();
        }
        var range = selection.getRangeAt(0);
        if (!state.visual.contains(range.commonAncestorContainer)) {
            return remembered();
        }
        var children = Array.from(state.visual.children);
        if (range.collapsed) {
            var anchor = range.startContainer.nodeType === Node.ELEMENT_NODE
                ? range.startContainer
                : range.startContainer.parentElement;
            var editable = anchor instanceof Element
                ? anchor.closest(RICH_TEXT_FLOW_BLOCK_SELECTOR)
                : null;
            var flowElement = editable instanceof Element
                && editable.nodeName.toLowerCase() === 'li'
                ? editable.closest('ul,ol')
                : editable;
            var currentIndex = children.indexOf(flowElement);
            return currentIndex < 0 ? [] : [currentIndex];
        }
        return children.map(function (child, index) {
            try {
                return range.intersectsNode(child) ? index : null;
            } catch (error) {
                return null;
            }
        }).filter(function (index) {
            return index !== null;
        });
    }

    function richSelectionBreaksLeadingHeading(state, indexes, nextType) {
        var leadingIndex = Array.isArray(state.flowDraft)
            ? state.flowDraft.findIndex(function (flowNode) {
                return flowNode && flowNode.type !== 'break';
            })
            : -1;
        return state.requiresLeadingHeading === true
            && Array.isArray(indexes)
            && leadingIndex >= 0
            && indexes.includes(leadingIndex)
            && nextType !== 'heading';
    }

    function richFlowListMarkers(ordered) {
        return ordered
            ? ['decimal', 'lower-alpha', 'upper-alpha']
            : ['disc', 'circle', 'square'];
    }

    function richFlowListMarkerAllowed(ordered, marker) {
        return typeof marker === 'string'
            && richFlowListMarkers(ordered).includes(marker);
    }

    function richFlowListMarkerValue(flowNode) {
        return flowNode && flowNode.type === 'list'
            ? (flowNode.marker || (flowNode.ordered ? 'decimal' : 'disc'))
            : '';
    }

    function richFlowListStyleDraft(
        content,
        selectedIndexes,
        ordered,
        marker,
        remove
    ) {
        if (remove === true) {
            return richConvertFlowBlocks(
                content,
                selectedIndexes,
                false,
                { forceUnwrap: true }
            );
        }
        if (!richFlowListMarkerAllowed(ordered, marker)) {
            return null;
        }
        return richConvertFlowBlocks(
            content,
            selectedIndexes,
            ordered,
            { forceList: true, marker: marker }
        );
    }

    function richCapturePendingFlowListIndexes(state, target) {
        var select = target instanceof Element
            ? target.closest('[data-blog-rich-list-style]')
            : null;
        if (!(select instanceof HTMLSelectElement)) {
            return false;
        }
        var selectedIndexes = Array.isArray(state.toolbarSelectedFlowIndexes)
            ? state.toolbarSelectedFlowIndexes
            : [];
        state.pendingFlowListIndexes = selectedIndexes.length > 0
            ? selectedIndexes.slice()
            : null;
        state.pendingFlowListRevision = state.pendingFlowListIndexes
            ? state.documentRevision
            : -1;
        return state.pendingFlowListIndexes !== null;
    }

    function richConsumePendingFlowListIndexes(state) {
        var pending = Array.isArray(state.pendingFlowListIndexes)
            ? state.pendingFlowListIndexes.slice()
            : [];
        var revision = state.pendingFlowListRevision;
        state.pendingFlowListIndexes = null;
        state.pendingFlowListRevision = -1;
        if (
            revision !== state.documentRevision
            || pending.length < 1
            || !Array.isArray(state.flowDraft)
        ) {
            return [];
        }
        var seen = new Set();
        var valid = pending.every(function (index) {
            if (
                !Number.isInteger(index)
                || index < 0
                || index >= state.flowDraft.length
                || seen.has(index)
            ) {
                return false;
            }
            seen.add(index);
            return true;
        });
        return valid ? pending.sort(function (left, right) {
            return left - right;
        }) : [];
    }

    function richApplyFlowListStyle(state, ordered, marker, trigger) {
        var pendingIndexes = richConsumePendingFlowListIndexes(state);
        if (!state.textFlowMode || state.context.readOnly) {
            return false;
        }
        if (state.advancedMode && state.advancedVisualStructureLocked) {
            richStatus(
                state,
                'Edita la estructura de este bloque desde HTML para conservar sus clases y atributos.',
                true
            );
            return false;
        }
        var remove = marker === 'remove';
        if (!remove && !richFlowListMarkerAllowed(ordered, marker)) {
            richStatus(state, 'El estilo de lista elegido no está permitido.', true);
            return false;
        }
        var selectedIndexes = pendingIndexes.length > 0
            ? pendingIndexes
            : richFlowSelectedIndexes(state);
        if (richSelectionBreaksLeadingHeading(
            state,
            selectedIndexes,
            remove ? 'paragraph' : 'list'
        )) {
            richStatus(
                state,
                'El primer bloque de una sección debe seguir siendo un encabezado H2-H6.',
                true
            );
            return false;
        }
        if (selectedIndexes.length === 0 || !richSyncVisual(state)) {
            richStatus(
                state,
                'Sitúa el cursor o selecciona los párrafos que quieres convertir.',
                true
            );
            return false;
        }
        var selectedNodes = state.flowDraft.filter(function (unused, index) {
            return selectedIndexes.includes(index);
        });
        if (selectedNodes.some(function (node) {
            return !['paragraph', 'list'].includes(node.type);
        })) {
            richStatus(
                state,
                'Convierte primero el encabezado, la cita o el destacado en párrafo.',
                true
            );
            return false;
        }

        var markerOnly = !remove && selectedNodes.length > 0
            && selectedNodes.every(function (node) {
                return node.type === 'list' && node.ordered === ordered;
            });
        if (markerOnly) {
            var markerChanged = false;
            selectedIndexes.forEach(function (index) {
                var flowNode = state.flowDraft[index];
                if (richFlowListMarkerValue(flowNode) === marker) {
                    return;
                }
                flowNode.marker = marker;
                richSyncFlowMetadataElement(state, index);
                markerChanged = true;
            });
            if (markerChanged) {
                state.inputTouched = true;
                state.visualTouched = true;
                richCommitAdvancedVisualFlow(state);
                richUpdateDirty(state);
                richUpdateLimitFeedback(state);
                richRefreshToolbar(state);
                richStatus(state, 'Marcador de la lista actualizado.', false);
            } else {
                richStatus(state, 'La lista ya usa ese marcador.', false);
            }
            if (trigger instanceof HTMLElement) {
                trigger.focus();
            }
            return true;
        }

        var next = richFlowListStyleDraft(
            state.flowDraft,
            selectedIndexes,
            ordered,
            marker,
            remove
        );
        if (
            next === null
            || JSON.stringify(next) === JSON.stringify(state.flowDraft)
        ) {
            richRefreshToolbar(state);
            if (trigger instanceof HTMLElement) {
                trigger.focus();
            }
            return false;
        }
        var rememberedIndex = Math.min(
            selectedIndexes[0],
            Math.max(0, next.length - 1)
        );
        state.flowDraft = next;
        state.inputTouched = true;
        state.visualTouched = true;
        richCommitAdvancedVisualFlow(state);
        state.selection = null;
        state.flowSelection = {
            element: null,
            flowIndex: rememberedIndex,
            itemIndex: null,
            start: 0,
            end: 0
        };
        richRenderDraft(state, null);
        state.flowSelection.element = state.visual.children[rememberedIndex]
            || null;
        richRefreshToolbar(state);
        if (state.legacyListMode && !richFlowCanStayLegacyList(next)) {
            richStatus(
                state,
                'Al aplicar, esta lista se convertirá en Texto para conservar el nuevo flujo. La revisión anterior no se modifica.',
                false
            );
        } else {
            richStatus(
                state,
                remove
                    ? 'Lista convertida en párrafos.'
                    : (ordered
                        ? 'Lista numerada y marcador actualizados.'
                        : 'Lista con viñetas y marcador actualizados.'),
                false
            );
        }
        if (trigger instanceof HTMLElement) {
            trigger.focus();
        }
        return true;
    }

    function richFlowTransformableBlocks(state) {
        var selectedIndexes = richFlowSelectedIndexes(state);
        if (selectedIndexes.length === 0) {
            return null;
        }
        var allowed = ['paragraph', 'heading', 'quote', 'callout'];
        return selectedIndexes.every(function (index) {
            return state.flowDraft[index]
                && allowed.includes(state.flowDraft[index].type);
        }) ? selectedIndexes : null;
    }

    function richSetFlowBlockType(state, requestedType) {
        if (
            !state.textFlowMode
            || state.context.readOnly
            || !richSyncVisual(state)
        ) {
            return false;
        }
        var indexes = richFlowTransformableBlocks(state);
        if (indexes === null) {
            richStatus(
                state,
                'Sitúa el cursor o selecciona bloques de texto; las listas se transforman con sus propios controles.',
                true
            );
            return false;
        }
        var headingMatch = /^h([2-6])$/u.exec(requestedType);
        if (requestedType !== 'paragraph' && headingMatch === null) {
            return false;
        }
        if (richSelectionBreaksLeadingHeading(
            state,
            indexes,
            headingMatch === null ? 'paragraph' : 'heading'
        )) {
            richStatus(
                state,
                'El primer bloque de una sección debe seguir siendo un encabezado H2-H6.',
                true
            );
            return false;
        }
        indexes.forEach(function (index) {
            var current = state.flowDraft[index];
            if (headingMatch === null) {
                state.flowDraft[index] = {
                    type: 'paragraph',
                    content: richClone(current.content)
                };
                return;
            }
            var level = Number(headingMatch[1]);
            var nextHeading = {
                    type: 'heading',
                    level: level,
                    content: richClone(current.content)
                };
            if (
                current.type === 'heading'
                && Object.prototype.hasOwnProperty.call(current, 'preset')
            ) {
                nextHeading.preset = current.preset;
            } else {
                var preference = state.context.headingDefaults['h' + level];
                nextHeading.preset = preference
                    && HEADING_PRESETS.includes(preference.preset)
                    ? preference.preset
                    : 'default';
            }
            state.flowDraft[index] = nextHeading;
        });
        state.inputTouched = true;
        state.visualTouched = true;
        richCommitAdvancedVisualFlow(state);
        state.selection = null;
        state.selectionRevision = -1;
        state.flowSelection = null;
        state.flowSelections = [];
        richRenderDraft(state, null);
        richStatus(
            state,
            headingMatch === null
                ? 'Bloque convertido en párrafo.'
                : 'Bloque convertido en encabezado ' + requestedType.toUpperCase() + '.',
            false
        );
        state.visual.focus();
        return true;
    }

    function richToggleFlowBlockType(state, requestedType) {
        if (!['quote', 'callout'].includes(requestedType)) {
            return false;
        }
        if (
            !state.textFlowMode
            || state.context.readOnly
            || !richSyncVisual(state)
        ) {
            return false;
        }
        var indexes = richFlowTransformableBlocks(state);
        if (indexes === null) {
            richStatus(
                state,
                'Selecciona párrafos, encabezados, citas o destacados; una lista conserva su estructura.',
                true
            );
            return false;
        }
        if (richSelectionBreaksLeadingHeading(
            state,
            indexes,
            requestedType
        )) {
            richStatus(
                state,
                'El primer bloque de una sección debe seguir siendo un encabezado H2-H6.',
                true
            );
            return false;
        }
        var unwrap = indexes.every(function (index) {
            return state.flowDraft[index].type === requestedType;
        });
        indexes.forEach(function (index) {
            var current = state.flowDraft[index];
            state.flowDraft[index] = {
                type: unwrap ? 'paragraph' : requestedType,
                content: richClone(current.content)
            };
            if (!unwrap && requestedType === 'quote') {
                state.flowDraft[index].author = null;
                state.flowDraft[index].source = null;
                state.flowDraft[index].preset = 'default';
            } else if (!unwrap && requestedType === 'callout') {
                state.flowDraft[index].tone = 'neutral';
            }
        });
        state.inputTouched = true;
        state.visualTouched = true;
        richCommitAdvancedVisualFlow(state);
        state.selection = null;
        state.flowSelection = null;
        state.flowSelections = [];
        richRenderDraft(state, null);
        richStatus(
            state,
            unwrap
                ? 'Bloque restaurado como párrafo.'
                : (requestedType === 'quote'
                    ? 'Bloque convertido en cita.'
                    : 'Bloque convertido en destacado.'),
            false
        );
        state.visual.focus();
        return true;
    }

    function richSetFlowMetadataValue(flowNode, key, value) {
        if (!flowNode || typeof value !== 'string') {
            return false;
        }
        if (flowNode.type === 'list' && key === 'marker') {
            if (!richFlowListMarkerAllowed(flowNode.ordered, value)) {
                return false;
            }
            flowNode.marker = value;
            return true;
        }
        if (flowNode.type === 'quote') {
            if (key === 'author' || key === 'source') {
                var normalized = value === '' ? null : value;
                var maximum = key === 'author' ? 255 : 500;
                if (!optionalSingleLine(normalized, maximum)) {
                    return false;
                }
                flowNode[key] = normalized;
                return true;
            }
            if (key === 'preset' && QUOTE_PRESETS.includes(value)) {
                flowNode.preset = value;
                return true;
            }
            return false;
        }
        if (
            flowNode.type === 'callout'
            && key === 'tone'
            && CALLOUT_TONES.includes(value)
        ) {
            flowNode.tone = value;
            return true;
        }
        return false;
    }

    function richPointAtOffset(root, requested) {
        var cursor = 0;
        var found = null;

        function visit(node) {
            if (found !== null) {
                return;
            }
            if (node.nodeType === Node.TEXT_NODE) {
                var length = node.nodeValue ? node.nodeValue.length : 0;
                if (requested <= cursor + length) {
                    found = {
                        node: node,
                        offset: Math.max(0, requested - cursor)
                    };
                    return;
                }
                cursor += length;
                return;
            }
            if (
                node.nodeType === Node.ELEMENT_NODE
                && node.nodeName.toLowerCase() === 'br'
            ) {
                var parent = node.parentNode;
                var index = parent
                    ? Array.prototype.indexOf.call(parent.childNodes, node)
                    : 0;
                if (requested <= cursor) {
                    found = { node: parent || root, offset: index };
                    return;
                }
                cursor += 1;
                if (requested <= cursor) {
                    found = { node: parent || root, offset: index + 1 };
                }
                return;
            }
            Array.from(node.childNodes).forEach(visit);
        }

        visit(root);
        return found || { node: root, offset: root.childNodes.length };
    }

    function richRestoreSelection(editor, offsets) {
        if (!offsets) {
            return;
        }
        var start = richPointAtOffset(editor, offsets.start);
        var end = richPointAtOffset(editor, offsets.end);
        var range = document.createRange();
        range.setStart(start.node, start.offset);
        range.setEnd(end.node, end.offset);
        var selection = window.getSelection();
        if (selection) {
            selection.removeAllRanges();
            selection.addRange(range);
        }
    }

    function richSourceTagTokens(source, allowTrailingIncomplete) {
        source = String(source);
        var tokens = [];
        var index = 0;
        while (index < source.length) {
            if (source.startsWith('<!--', index)) {
                var commentEnd = source.indexOf('-->', index + 4);
                index = commentEnd < 0 ? source.length : commentEnd + 3;
                continue;
            }
            if (source.charAt(index) !== '<') {
                index += 1;
                continue;
            }
            var start = index;
            var cursor = start + 1;
            while (/\s/u.test(source.charAt(cursor))) {
                cursor += 1;
            }
            var closing = source.charAt(cursor) === '/';
            if (closing) {
                cursor += 1;
                while (/\s/u.test(source.charAt(cursor))) {
                    cursor += 1;
                }
            }
            var nameStart = cursor;
            if (!/[a-z]/iu.test(source.charAt(cursor))) {
                index = start + 1;
                continue;
            }
            cursor += 1;
            while (/[a-z0-9-]/iu.test(source.charAt(cursor))) {
                cursor += 1;
            }
            var name = source.slice(nameStart, cursor).toLowerCase();
            var next = source.charAt(cursor);
            if (next !== '' && next !== '>' && next !== '/'
                && !/\s/u.test(next)) {
                index = start + 1;
                continue;
            }
            var quote = '';
            var completed = false;
            while (cursor < source.length) {
                var character = source.charAt(cursor);
                if (quote !== '') {
                    if (character === quote) {
                        quote = '';
                    }
                    cursor += 1;
                    continue;
                }
                if (character === '"' || character === "'") {
                    quote = character;
                    cursor += 1;
                    continue;
                }
                if (character === '<') {
                    break;
                }
                if (character === '>') {
                    tokens.push({
                        start: start,
                        end: cursor + 1,
                        tag: name,
                        closing: closing,
                        selfClosing: !closing && /\/\s*$/u.test(
                            source.slice(nameStart + name.length, cursor)
                        ),
                        complete: true
                    });
                    index = cursor + 1;
                    completed = true;
                    break;
                }
                cursor += 1;
            }
            if (completed) {
                continue;
            }
            if (allowTrailingIncomplete && cursor === source.length
                && quote === '') {
                tokens.push({
                    start: start,
                    end: source.length,
                    tag: name,
                    closing: closing,
                    selfClosing: !closing && /\/\s*$/u.test(
                        source.slice(nameStart + name.length)
                    ),
                    complete: false
                });
                break;
            }
            index = start + 1;
        }
        return tokens;
    }

    function richSourceOpenTagStack(source, caret) {
        var stack = [];
        var prefix = String(source).slice(0, Math.max(0, caret));
        richSourceTagTokens(prefix, false).forEach(function (token) {
            var tag = token.tag;
            if (!RICH_SOURCE_TAG_NAMES.includes(tag)) {
                return;
            }
            if (token.closing) {
                var openIndex = stack.lastIndexOf(tag);
                if (openIndex >= 0) {
                    stack = stack.slice(0, openIndex);
                }
                return;
            }
            if (!token.selfClosing && !['br', 'img'].includes(tag)) {
                stack.push(tag);
            }
        });
        return stack;
    }

    function richSourceDefinitions(
        textFlowMode,
        allowBreak,
        source,
        caret,
        headingLevels,
        advancedMode
    ) {
        var stack = richSourceOpenTagStack(source, caret);
        var insideLink = stack.includes('a');
        var allowedHeadingLevels = Array.isArray(headingLevels)
            ? headingLevels.filter(function (level) {
                return [2, 3, 4, 5, 6].includes(level);
            })
            : [];
        var headingContainer = null;
        for (var headingIndex = stack.length - 1; headingIndex >= 0; headingIndex -= 1) {
            if (/^h[2-6]$/u.test(stack[headingIndex])) {
                headingContainer = stack[headingIndex];
                break;
            }
        }
        var flowContainer = null;
        var embedMode = advancedMode === 'embed';
        var flowTags = embedMode
            ? RICH_EMBED_HTML_TAGS
            : (advancedMode === true
            ? [
                'div', 'p', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol',
                'li', 'blockquote', 'aside', 'span', 'strong', 'em', 'u',
                'a', 'small', 'mark', 'sup', 'sub', 'code'
            ]
            : [
                'p', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li',
                'blockquote', 'aside'
            ]);
        for (var index = stack.length - 1; index >= 0; index -= 1) {
            if (flowTags.includes(stack[index])) {
                flowContainer = stack[index];
                break;
            }
        }
        var scope = 'inline';
        var scopes = ['inline'];
        if (embedMode) {
            if (['iframe', 'img', 'br'].includes(flowContainer)) {
                scopes = [];
            } else if (['ul', 'ol'].includes(flowContainer)) {
                scopes = ['list-item'];
            } else if (
                [
                    'p', 'li', 'figcaption', 'blockquote', 'pre', 'span',
                    'strong', 'em', 'u', 'a', 'b', 'i', 'cite', 'code'
                ].includes(flowContainer)
            ) {
                scopes = ['inline'];
            } else {
                scopes = ['flow', 'flow-quote', 'advanced-flow', 'inline'];
            }
        } else if (advancedMode === true) {
            if (['ul', 'ol'].includes(flowContainer)) {
                scopes = ['list-item'];
            } else if (
                [
                    'p', 'h2', 'h3', 'h4', 'h5', 'h6', 'li',
                    'blockquote', 'aside', 'span', 'strong', 'em', 'u',
                    'a', 'small', 'mark', 'sup', 'sub', 'code'
                ].includes(flowContainer)
            ) {
                scopes = ['inline'];
            } else {
                scopes = [
                    'flow', 'heading', 'flow-quote', 'flow-callout',
                    'advanced-flow'
                ];
            }
        } else if (allowedHeadingLevels.length > 0 && !textFlowMode) {
            scope = headingContainer === null ? 'heading' : 'inline';
            scopes = [scope];
        } else if (textFlowMode) {
            if (['ul', 'ol'].includes(flowContainer)) {
                scope = 'list-item';
            } else if (![
                'p', 'h2', 'h3', 'h4', 'h5', 'h6', 'li',
                'blockquote', 'aside'
            ].includes(flowContainer)) {
                scopes = [
                    'flow', 'heading', 'flow-quote', 'flow-callout'
                ];
            }
            if (scopes.length === 1) {
                scopes = [scope];
            }
        }
        var definitions = advancedMode === true
            ? RICH_SOURCE_TAG_DEFINITIONS.concat(
                RICH_ADVANCED_SOURCE_TAG_DEFINITIONS
            )
            : RICH_SOURCE_TAG_DEFINITIONS;
        if (embedMode) {
            definitions = RICH_EMBED_HTML_TAGS.map(function (tag) {
                var known = RICH_SOURCE_TAG_DEFINITIONS.concat(
                    RICH_ADVANCED_SOURCE_TAG_DEFINITIONS,
                    RICH_EMBED_SOURCE_TAG_DEFINITIONS
                ).find(function (definition) {
                    return definition.tag === tag;
                });
                return known || {
                    tag: tag,
                    label: 'Etiqueta HTML permitida',
                    scope: 'advanced-flow',
                    snippet: '<' + tag + '>|</' + tag + '>'
                };
            });
        }
        return definitions.filter(function (definition) {
            if (!scopes.includes(definition.scope)) {
                return false;
            }
            if (
                definition.scope === 'heading'
                && !allowedHeadingLevels.includes(Number(definition.tag.slice(1)))
            ) {
                return false;
            }
            if (definition.tag === 'br' && (!allowBreak || insideLink)) {
                return false;
            }
            return definition.tag !== 'a' || !insideLink;
        });
    }

    function richSourceSuggestions(
        source,
        caret,
        textFlowMode,
        allowBreak,
        force,
        headingLevels,
        advancedMode
    ) {
        source = String(source);
        caret = Math.max(0, Math.min(source.length, caret));
        var prefix = source.slice(0, caret);
        var token = /<([a-z]*)$/iu.exec(prefix);
        var bareToken = token === null
            ? /(?:^|\n)[\t ]*([a-z][a-z0-9-]*)$/iu.exec(prefix)
            : null;
        if (token === null && bareToken === null && force !== true) {
            return { start: caret, query: '', items: [] };
        }
        var query = token !== null
            ? token[1].toLowerCase()
            : (bareToken !== null ? bareToken[1].toLowerCase() : '');
        var start = token !== null
            ? caret - token[0].length
            : (bareToken !== null ? caret - bareToken[1].length : caret);
        if (query === '' && force !== true) {
            return { start: start, query: query, items: [] };
        }
        var items = richSourceDefinitions(
            textFlowMode,
            allowBreak,
            source,
            start,
            headingLevels,
            advancedMode
        ).filter(function (definition) {
            return definition.tag.startsWith(query);
        }).slice(
            0,
            RICH_SOURCE_TAG_DEFINITIONS.length
                + RICH_ADVANCED_SOURCE_TAG_DEFINITIONS.length
                + RICH_EMBED_SOURCE_TAG_DEFINITIONS.length
        );
        return { start: start, query: query, items: items };
    }

    function richSourceReplaceEdit(value, start, end, replacement, caretOffset) {
        value = String(value);
        start = Math.max(0, Math.min(value.length, start));
        end = Math.max(start, Math.min(value.length, end));
        var nextValue = value.slice(0, start) + replacement + value.slice(end);
        var nextCaret = start + Math.max(
            0,
            Math.min(replacement.length, caretOffset)
        );
        return {
            value: nextValue,
            start: nextCaret,
            end: nextCaret
        };
    }

    function richSourceSuggestionEdit(value, start, end, definition) {
        if (!RICH_SOURCE_TAG_DEFINITIONS.includes(definition)
            && !RICH_ADVANCED_SOURCE_TAG_DEFINITIONS.includes(definition)
            && !RICH_EMBED_SOURCE_TAG_DEFINITIONS.includes(definition)) {
            return null;
        }
        var marker = definition.snippet.indexOf('|');
        var replacement = definition.snippet.replace('|', '');
        return richSourceReplaceEdit(
            value,
            start,
            end,
            replacement,
            marker < 0 ? replacement.length : marker
        );
    }

    function richCssCaretDepth(source, caret) {
        var depth = 0;
        var quote = '';
        var comment = false;
        for (var index = 0; index < caret; index += 1) {
            var current = source.charAt(index);
            var next = source.charAt(index + 1);
            if (comment) {
                if (current === '*' && next === '/') {
                    comment = false;
                    index += 1;
                }
                continue;
            }
            if (quote !== '') {
                if (current === '\\') {
                    index += 1;
                } else if (current === quote) {
                    quote = '';
                }
                continue;
            }
            if (current === '/' && next === '*') {
                comment = true;
                index += 1;
            } else if (current === '"' || current === "'") {
                quote = current;
            } else if (current === '{') {
                depth += 1;
            } else if (current === '}') {
                depth = Math.max(0, depth - 1);
            }
        }
        return comment || quote !== '' ? -1 : depth;
    }

    function richCssSuggestionAllowed(property, value, nested, policy) {
        var declaration = property + ':' + value + ';';
        try {
            richValidateAdvancedCss(
                nested ? '.preview{' + declaration + '}' : declaration,
                policy
            );
            return true;
        } catch (error) {
            return false;
        }
    }

    function richCssSuggestions(source, caret, force, customPolicy) {
        source = String(source);
        caret = Math.max(0, Math.min(source.length, caret));
        var policy = richAdvancedPolicy(customPolicy);
        var depth = richCssCaretDepth(source, caret);
        if (policy === null || depth < 0) {
            return { start: caret, query: '', kind: '', items: [] };
        }
        var prefix = source.slice(0, caret);
        var boundary = Math.max(
            prefix.lastIndexOf(';'),
            prefix.lastIndexOf('{'),
            prefix.lastIndexOf('}'),
            prefix.lastIndexOf('\n'),
            prefix.lastIndexOf('\r')
        );
        var segmentStart = boundary + 1;
        var segment = prefix.slice(segmentStart);
        var valueMatch = /^[\t ]*([a-z][a-z0-9-]*)\s*:\s*([^;{}]*)$/iu
            .exec(segment);
        if (valueMatch !== null) {
            var property = valueMatch[1].toLowerCase();
            var rawQuery = valueMatch[2];
            var leading = (/^\s*/u.exec(rawQuery) || [''])[0].length;
            var query = rawQuery.slice(leading).toLowerCase();
            if (query === '' && force !== true) {
                return {
                    start: caret,
                    query: query,
                    kind: 'value',
                    items: []
                };
            }
            var values = RICH_CSS_VALUE_SUGGESTIONS[property] || [];
            var valueItems = values.filter(function (value) {
                return value.toLowerCase().startsWith(query)
                    && richCssSuggestionAllowed(
                        property,
                        value,
                        depth > 0,
                        policy
                    );
            }).map(function (value) {
                return {
                    kind: 'value',
                    property: property,
                    value: value,
                    label: value,
                    snippet: value + '|'
                };
            });
            return {
                start: caret - query.length,
                query: query,
                kind: 'value',
                items: valueItems
            };
        }
        var propertyMatch = /^[\t ]*([a-z-]*)$/iu.exec(segment);
        if (propertyMatch === null && force !== true) {
            return { start: caret, query: '', kind: '', items: [] };
        }
        var propertyQuery = propertyMatch === null
            ? ''
            : propertyMatch[1].toLowerCase();
        var propertyStart = propertyMatch === null
            ? caret
            : caret - propertyMatch[1].length;
        if (propertyQuery === '' && force !== true) {
            return {
                start: propertyStart,
                query: propertyQuery,
                kind: 'property',
                items: []
            };
        }
        var allowedProperties = depth > 0
            ? policy.css.properties
            : policy.css.root_properties;
        return {
            start: propertyStart,
            query: propertyQuery,
            kind: 'property',
            items: allowedProperties.filter(function (propertyName) {
                return propertyName.startsWith(propertyQuery);
            }).slice(0, 40).map(function (propertyName) {
                return {
                    kind: 'property',
                    property: propertyName,
                    value: propertyName,
                    label: propertyName,
                    snippet: propertyName + ': |;'
                };
            })
        };
    }

    function richCssSuggestionEdit(value, start, end, definition) {
        if (
            !definition
            || !['property', 'value'].includes(definition.kind)
            || typeof definition.snippet !== 'string'
        ) {
            return null;
        }
        var marker = definition.snippet.indexOf('|');
        var replacement = definition.snippet.replace('|', '');
        return richSourceReplaceEdit(
            value,
            start,
            end,
            replacement,
            marker < 0 ? replacement.length : marker
        );
    }

    function richSuggestionKeyboardAction(
        key,
        open,
        shiftKey,
        ctrlKey,
        metaKey,
        altKey
    ) {
        if (
            open !== true
            || ctrlKey === true
            || metaKey === true
            || altKey === true
        ) {
            return '';
        }
        if (key === 'ArrowDown') {
            return 'next';
        }
        if (key === 'ArrowUp') {
            return 'previous';
        }
        if (
            (key === 'Enter' || key === 'Tab')
            && shiftKey !== true
        ) {
            return 'accept';
        }
        return '';
    }

    function richSuggestionCode(value, query) {
        value = String(value);
        query = String(query || '');
        var code = element('code');
        var matchStart = query === ''
            ? -1
            : value.toLowerCase().indexOf(query.toLowerCase());
        if (matchStart < 0) {
            code.textContent = value;
            return code;
        }
        var matchEnd = matchStart + query.length;
        code.append(
            document.createTextNode(value.slice(0, matchStart)),
            element(
                'mark',
                'blogEditor__richSourceSuggestionMatch',
                value.slice(matchStart, matchEnd)
            ),
            document.createTextNode(value.slice(matchEnd))
        );
        return code;
    }

    function richSourceIndentEdit(value, start, end, outdent) {
        value = String(value);
        start = Math.max(0, Math.min(value.length, start));
        end = Math.max(start, Math.min(value.length, end));
        if (start === end && !outdent) {
            return richSourceReplaceEdit(
                value,
                start,
                end,
                RICH_SOURCE_INDENT,
                RICH_SOURCE_INDENT.length
            );
        }
        var firstLine = value.lastIndexOf('\n', Math.max(0, start - 1)) + 1;
        var effectiveEnd = end > start && value.charAt(end - 1) === '\n'
            ? end - 1
            : end;
        var lineStarts = [firstLine];
        var nextLine = value.indexOf('\n', firstLine);
        while (nextLine >= 0 && nextLine < effectiveEnd) {
            lineStarts.push(nextLine + 1);
            nextLine = value.indexOf('\n', nextLine + 1);
        }
        var edits = lineStarts.map(function (lineStart) {
            if (!outdent) {
                return {
                    at: lineStart,
                    remove: 0,
                    insert: RICH_SOURCE_INDENT
                };
            }
            var leading = value.slice(
                lineStart,
                lineStart + RICH_SOURCE_INDENT.length
            );
            var remove = leading.charAt(0) === '\t'
                ? 1
                : ((/^ {1,4}/u.exec(leading) || [''])[0].length);
            return { at: lineStart, remove: remove, insert: '' };
        }).filter(function (edit) {
            return edit.remove > 0 || edit.insert !== '';
        });

        function mapPosition(position) {
            var shift = 0;
            for (var editIndex = 0; editIndex < edits.length; editIndex += 1) {
                var edit = edits[editIndex];
                if (position < edit.at) {
                    break;
                }
                if (position <= edit.at + edit.remove) {
                    return edit.at + shift + edit.insert.length;
                }
                shift += edit.insert.length - edit.remove;
            }
            return position + shift;
        }

        var nextValue = value;
        edits.slice().reverse().forEach(function (edit) {
            nextValue = nextValue.slice(0, edit.at) + edit.insert
                + nextValue.slice(edit.at + edit.remove);
        });
        return {
            value: nextValue,
            start: mapPosition(start),
            end: mapPosition(end)
        };
    }

    function richSourcePairEdit(value, start, end, key) {
        value = String(value);
        start = Math.max(0, Math.min(value.length, start));
        end = Math.max(start, Math.min(value.length, end));
        var pairs = {
            '{': '}',
            '[': ']',
            '(': ')',
            '"': '"',
            "'": "'"
        };
        var closing = pairs[key];
        if (typeof closing === 'string') {
            if (
                start === end
                && key === closing
                && value.charAt(start) === closing
            ) {
                return {
                    value: value,
                    start: start + 1,
                    end: start + 1
                };
            }
            var selected = value.slice(start, end);
            if (selected !== '') {
                return {
                    value: value.slice(0, start) + key + selected + closing
                        + value.slice(end),
                    start: start + 1,
                    end: end + 1
                };
            }
            return richSourceReplaceEdit(
                value,
                start,
                end,
                key + selected + closing,
                1
            );
        }
        var openingForClosing = {
            '}': '{',
            ']': '[',
            ')': '('
        }[key];
        if (
            openingForClosing
            && start === end
            && value.charAt(start) === key
        ) {
            return {
                value: value,
                start: start + 1,
                end: start + 1
            };
        }
        return null;
    }

    function richSourcePairDeleteEdit(value, start, end) {
        value = String(value);
        if (start !== end || start <= 0 || start >= value.length) {
            return null;
        }
        var pair = value.charAt(start - 1) + value.charAt(start);
        if (!['{}', '[]', '()', '""', "''"].includes(pair)) {
            return null;
        }
        return richSourceReplaceEdit(value, start - 1, start + 1, '', 0);
    }

    function richSourceExitPairEdit(value, start, end) {
        value = String(value);
        start = Math.max(0, Math.min(value.length, start));
        end = Math.max(start, Math.min(value.length, end));
        if (start !== end) {
            return null;
        }
        var tokens = richSourceTagTokens(value.slice(0, start), false);
        var opening = tokens.length > 0 ? tokens[tokens.length - 1] : null;
        if (
            opening === null
            || !opening.complete
            || opening.closing
            || opening.selfClosing
            || opening.end !== start
        ) {
            return null;
        }
        var closing = '</' + opening.tag + '>';
        if (!value.slice(start).startsWith(closing)) {
            return null;
        }
        var closingEnd = start + closing.length;
        var lineStart = value.lastIndexOf(
            '\n',
            Math.max(0, opening.start - 1)
        ) + 1;
        var indent = (/^[\t ]*/u.exec(
            value.slice(lineStart, opening.start)
        ) || [''])[0];
        var followingBreak = /^[\t ]*(\r\n|\r|\n)/u.exec(
            value.slice(closingEnd)
        );
        if (followingBreak !== null) {
            var nextLineStart = closingEnd + followingBreak[0].length;
            var nextBreak = /\r\n|\r|\n/u.exec(value.slice(nextLineStart));
            var nextLineEnd = nextBreak === null
                ? value.length
                : nextLineStart + nextBreak.index;
            var nextLine = value.slice(nextLineStart, nextLineEnd);
            if (/^[\t ]*$/u.test(nextLine)) {
                return richSourceReplaceEdit(
                    value,
                    nextLineStart,
                    nextLineEnd,
                    indent,
                    indent.length
                );
            }
            return richSourceReplaceEdit(
                value,
                nextLineStart,
                nextLineStart,
                indent + followingBreak[1],
                indent.length
            );
        }
        var sourceBreak = /\r\n|\r|\n/u.exec(value);
        var newline = sourceBreak === null ? '\n' : sourceBreak[0];
        return {
            value: value.slice(0, closingEnd) + newline + indent
                + value.slice(closingEnd),
            start: closingEnd + newline.length + indent.length,
            end: closingEnd + newline.length + indent.length
        };
    }

    function richCodeAltGraphInput(event) {
        if (!event || event.metaKey) {
            return false;
        }
        if (
            typeof event.getModifierState === 'function'
            && event.getModifierState('AltGraph')
        ) {
            return true;
        }
        return event.ctrlKey === true
            && event.altKey === true
            && ['{', '}', '[', ']', '(', ')', '"', "'"].includes(event.key);
    }

    function richCodeHasCommandModifier(event) {
        return event.metaKey === true
            || (
                (event.ctrlKey === true || event.altKey === true)
                && !richCodeAltGraphInput(event)
            );
    }

    function richCodeEnterEdit(value, start, end) {
        value = String(value);
        start = Math.max(0, Math.min(value.length, start));
        end = Math.max(start, Math.min(value.length, end));
        var lineStart = value.lastIndexOf('\n', Math.max(0, start - 1)) + 1;
        var indent = (/^[\t ]*/u.exec(
            value.slice(lineStart, start)
        ) || [''])[0];
        var prefix = value.slice(0, start).trimEnd();
        var next = value.slice(end).charAt(0);
        var open = prefix.charAt(prefix.length - 1);
        var close = { '{': '}', '[': ']', '(': ')' }[open];
        var nested = close ? indent + RICH_SOURCE_INDENT : indent;
        var replacement = '\n' + nested;
        var caretOffset = replacement.length;
        if (close && next === close) {
            replacement += '\n' + indent;
        }
        return richSourceReplaceEdit(
            value,
            start,
            end,
            replacement,
            caretOffset
        );
    }

    function richSourceEnterEdit(
        value,
        start,
        end,
        textFlowMode,
        allowBreak,
        headingLevels,
        advancedMode
    ) {
        value = String(value);
        start = Math.max(0, Math.min(value.length, start));
        end = Math.max(start, Math.min(value.length, end));
        var lineStart = value.lastIndexOf('\n', Math.max(0, start - 1)) + 1;
        var linePrefix = value.slice(lineStart, start);
        var indent = (/^[\t ]*/u.exec(linePrefix) || [''])[0];
        var trimmedLinePrefix = linePrefix.replace(/\s+$/u, '');
        var lineTokens = richSourceTagTokens(trimmedLinePrefix, false);
        var opening = lineTokens.length > 0
            ? lineTokens[lineTokens.length - 1]
            : null;
        var replacement = '\n' + indent;
        var caretOffset = replacement.length;
        if (opening !== null && opening.complete
            && opening.end === trimmedLinePrefix.length
            && !opening.closing && !opening.selfClosing
            && !['br', 'img'].includes(opening.tag)) {
            var tag = opening.tag;
            var tagStart = lineStart + opening.start;
            var allowed = richSourceDefinitions(
                textFlowMode,
                allowBreak,
                value,
                tagStart,
                headingLevels,
                advancedMode
            ).some(function (definition) {
                return definition.tag === tag;
            });
            if (allowed) {
                var nestedIndent = indent + RICH_SOURCE_INDENT;
                var closing = '</' + tag + '>';
                replacement = '\n' + nestedIndent;
                caretOffset = replacement.length;
                if (value.slice(end).startsWith(closing)) {
                    replacement += '\n' + indent;
                }
            }
        }
        return richSourceReplaceEdit(
            value,
            start,
            end,
            replacement,
            caretOffset
        );
    }

    function richSourceAutoCloseEdit(
        value,
        start,
        end,
        textFlowMode,
        allowBreak,
        headingLevels,
        advancedMode
    ) {
        value = String(value);
        if (start !== end || start < 1 || start > value.length) {
            return null;
        }
        var prefix = value.slice(0, start);
        var tokens = richSourceTagTokens(prefix, true);
        var opening = tokens.length > 0 ? tokens[tokens.length - 1] : null;
        if (opening === null || opening.complete || opening.closing
            || opening.selfClosing || opening.end !== prefix.length) {
            return null;
        }
        var tag = opening.tag;
        var tagStart = opening.start;
        var allowed = richSourceDefinitions(
            textFlowMode,
            allowBreak,
            value,
            tagStart,
            headingLevels,
            advancedMode
        ).some(function (definition) {
            return definition.tag === tag;
        });
        if (!allowed || ['br', 'img'].includes(tag)) {
            return null;
        }
        var closing = '</' + tag + '>';
        var replacement = value.slice(end).startsWith(closing)
            ? '>'
            : '>' + closing;
        return richSourceReplaceEdit(value, start, end, replacement, 1);
    }

    function richAttributesAllowed(node, allowed) {
        return Array.from(node.attributes).every(function (attribute) {
            return allowed.includes(attribute.name);
        });
    }

    function richParseInlineRoot(root, allowBreak, strict, allowIncomplete) {
        var content = [];

        function appendText(value, marks, link) {
            var pieces = value.split(/(\r\n?|\n)/u);
            pieces.forEach(function (piece) {
                if (/^(?:\r\n?|\n)$/u.test(piece)) {
                    if (allowBreak) {
                        content.push({ type: 'break' });
                    } else if (content.length > 0) {
                        var previous = content[content.length - 1];
                        if (previous.type !== 'break') {
                            previous.text += ' ';
                        }
                    }
                    return;
                }
                if (piece === '') {
                    return;
                }
                if (!safePlainText(piece)) {
                    throw new Error('rich-text-invalid');
                }
                richTextChunks(piece, MAX_INLINE_TEXT_BYTES).forEach(
                    function (chunk) {
                        var node = {
                            type: link ? 'link' : 'text',
                            text: chunk,
                            marks: canonicalMarks(marks, true)
                        };
                        if (link) {
                            node.href = link.href;
                            node.title = link.title;
                            node.target = link.target;
                        }
                        content.push(node);
                    }
                );
            });
        }

        function withGroup(marks, group, mark) {
            var next = marks.filter(function (candidate) {
                return !richMarkInGroup(group, candidate);
            });
            next.push(mark);
            return canonicalMarks(next, true);
        }

        function visit(node, inheritedMarks, link, depth) {
            if (depth > 32 || content.length > MAX_INLINE_NODES) {
                throw new Error('rich-text-too-large');
            }
            if (node.nodeType === Node.TEXT_NODE) {
                appendText(node.nodeValue || '', inheritedMarks, link);
                return;
            }
            if (node.nodeType !== Node.ELEMENT_NODE) {
                if (strict && node.nodeType !== Node.DOCUMENT_FRAGMENT_NODE) {
                    throw new Error('rich-html-not-allowed');
                }
                return;
            }

            var tag = node.nodeName.toLowerCase();
            if (tag === 'br') {
                var caretFiller = !strict
                    && allowIncomplete
                    && allowBreak
                    && !link
                    && richCaretFillerNode(node);
                if (caretFiller) {
                    return;
                }
                if (!richAttributesAllowed(node, []) || !allowBreak || link) {
                    throw new Error('rich-html-not-allowed');
                }
                content.push({ type: 'break' });
                return;
            }

            var marks = inheritedMarks.slice();
            var nextLink = link;
            if (['strong', 'b'].includes(tag)) {
                if (
                    !richAttributesAllowed(node, [])
                    || (strict && tag === 'b')
                ) {
                    throw new Error('rich-html-not-allowed');
                }
                marks.push('strong');
            } else if (['em', 'i'].includes(tag)) {
                if (
                    !richAttributesAllowed(node, [])
                    || (strict && tag === 'i')
                ) {
                    throw new Error('rich-html-not-allowed');
                }
                marks.push('em');
            } else if (tag === 'u') {
                if (!richAttributesAllowed(node, [])) {
                    throw new Error('rich-html-not-allowed');
                }
                marks.push('underline');
            } else if (tag === 'span') {
                var spanAttributes = [
                    'data-ls-size',
                    'data-ls-text-color',
                    'data-ls-background-color',
                    'data-ls-text-rgba',
                    'data-ls-background-rgba',
                    RICH_ADVANCED_MARK_ATTRIBUTES.size,
                    RICH_ADVANCED_MARK_ATTRIBUTES.textColor,
                    RICH_ADVANCED_MARK_ATTRIBUTES.backgroundColor,
                    RICH_ADVANCED_MARK_ATTRIBUTES.textRgba,
                    RICH_ADVANCED_MARK_ATTRIBUTES.backgroundRgba
                ];
                if (!richAttributesAllowed(node, spanAttributes)) {
                    if (strict) {
                        throw new Error('rich-html-not-allowed');
                    }
                }
                var size = richInlineMarkAttribute(
                    node,
                    'data-ls-size',
                    RICH_ADVANCED_MARK_ATTRIBUTES.size
                );
                var color = richInlineMarkAttribute(
                    node,
                    'data-ls-text-color',
                    RICH_ADVANCED_MARK_ATTRIBUTES.textColor
                );
                var background = richInlineMarkAttribute(
                    node,
                    'data-ls-background-color',
                    RICH_ADVANCED_MARK_ATTRIBUTES.backgroundColor
                );
                var textRgba = richInlineMarkAttribute(
                    node,
                    'data-ls-text-rgba',
                    RICH_ADVANCED_MARK_ATTRIBUTES.textRgba
                );
                var backgroundRgba = richInlineMarkAttribute(
                    node,
                    'data-ls-background-rgba',
                    RICH_ADVANCED_MARK_ATTRIBUTES.backgroundRgba
                );
                if (size !== null) {
                    var sizeMark = 'size-' + size;
                    if (!RICH_MARK_GROUPS.size.includes(sizeMark)) {
                        throw new Error('rich-html-not-allowed');
                    }
                    marks = withGroup(marks, 'size', sizeMark);
                }
                if (color !== null) {
                    var colorMark = 'text-' + color;
                    if (!richMarkInGroup('color', colorMark)) {
                        throw new Error('rich-html-not-allowed');
                    }
                    marks = withGroup(
                        marks,
                        'color',
                        colorMark
                    );
                }
                if (background !== null) {
                    var backgroundMark = 'background-' + background;
                    if (!richMarkInGroup('background', backgroundMark)) {
                        throw new Error('rich-html-not-allowed');
                    }
                    marks = withGroup(
                        marks,
                        'background',
                        backgroundMark
                    );
                }
                if (textRgba !== null) {
                    var dynamicTextMark = canonicalDynamicRichMark(
                        'text-rgba:' + textRgba
                    );
                    if (dynamicTextMark === null) {
                        throw new Error('rich-html-not-allowed');
                    }
                    marks = withGroup(marks, 'color', dynamicTextMark);
                }
                if (backgroundRgba !== null) {
                    var dynamicBackgroundMark = canonicalDynamicRichMark(
                        'background-rgba:' + backgroundRgba
                    );
                    if (dynamicBackgroundMark === null) {
                        throw new Error('rich-html-not-allowed');
                    }
                    marks = withGroup(
                        marks,
                        'background',
                        dynamicBackgroundMark
                    );
                }
                if (
                    strict
                    && size === null
                    && color === null
                    && background === null
                    && textRgba === null
                    && backgroundRgba === null
                ) {
                    throw new Error('rich-html-not-allowed');
                }
            } else if (tag === 'a') {
                var linkAttributes = strict
                    ? ['href', 'title', 'target']
                    : ['href', 'title', 'target', 'rel'];
                if (
                    link
                    || !richAttributesAllowed(node, linkAttributes)
                ) {
                    throw new Error('rich-html-not-allowed');
                }
                var href = node.getAttribute('href') || '';
                var title = node.hasAttribute('title')
                    ? node.getAttribute('title')
                    : null;
                var rawTarget = node.getAttribute('target');
                if (
                    !safeUrl(href)
                    || !optionalSingleLine(title, 500)
                    || ![null, '', '_self', '_blank'].includes(rawTarget)
                ) {
                    throw new Error('rich-link-invalid');
                }
                nextLink = {
                    href: href,
                    title: title,
                    target: rawTarget === '_blank' ? 'new' : 'same'
                };
            } else if (strict) {
                throw new Error('rich-html-not-allowed');
            }

            marks = canonicalMarks(marks, true);
            Array.from(node.childNodes).forEach(function (child) {
                visit(child, marks, nextLink, depth + 1);
            });
        }

        Array.from(root.childNodes).forEach(function (node) {
            visit(node, [], null, 0);
        });
        content = richMergeContent(content);
        if (!validInline(
            content,
            allowBreak,
            true,
            allowIncomplete === true
        )) {
            throw new Error('rich-text-invalid');
        }
        return content;
    }

    function richParseHtml(source, allowBreak) {
        if (typeof source !== 'string' || bytes(source) > MAX_JSON_BYTES) {
            throw new Error('rich-text-too-large');
        }
        if (/<\s*\/?\s*(?:html|head|body)\b|<!doctype\b/iu.test(source)) {
            throw new Error('rich-html-not-allowed');
        }
        var parsed = new DOMParser().parseFromString(source, 'text/html');
        if (
            !parsed
            || !parsed.body
            || parsed.doctype !== null
            || parsed.head.childNodes.length > 0
            || parsed.documentElement.attributes.length > 0
            || parsed.body.attributes.length > 0
        ) {
            throw new Error('rich-html-not-allowed');
        }
        return richParseInlineRoot(parsed.body, allowBreak, true);
    }

    function richParseHeadingRoot(root, allowedLevels) {
        var levels = Array.isArray(allowedLevels) ? allowedLevels : [];
        var heading = null;
        Array.from(root.childNodes).forEach(function (node) {
            if (node.nodeType === Node.TEXT_NODE) {
                if ((node.nodeValue || '').trim() !== '') {
                    throw new Error('rich-html-not-allowed');
                }
                return;
            }
            if (
                node.nodeType !== Node.ELEMENT_NODE
                || heading !== null
                || !/^h[2-6]$/u.test(node.nodeName.toLowerCase())
                || !richAttributesAllowed(node, [])
            ) {
                throw new Error('rich-html-not-allowed');
            }
            heading = node;
        });
        if (heading === null) {
            throw new Error('rich-html-not-allowed');
        }
        var level = Number(heading.nodeName.slice(1));
        if (!levels.includes(level)) {
            throw new Error('rich-heading-level-not-allowed');
        }
        return {
            level: level,
            content: richParseInlineRoot(heading, false, true, true)
        };
    }

    function richParseHeadingHtml(source, allowedLevels) {
        if (typeof source !== 'string' || bytes(source) > MAX_JSON_BYTES) {
            throw new Error('rich-text-too-large');
        }
        if (/<\s*\/?\s*(?:html|head|body)\b|<!doctype\b/iu.test(source)) {
            throw new Error('rich-html-not-allowed');
        }
        var parsed = new DOMParser().parseFromString(source, 'text/html');
        if (
            !parsed
            || !parsed.body
            || parsed.doctype !== null
            || parsed.head.childNodes.length > 0
            || parsed.documentElement.attributes.length > 0
            || parsed.body.attributes.length > 0
        ) {
            throw new Error('rich-html-not-allowed');
        }
        return richParseHeadingRoot(parsed.body, allowedLevels);
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/gu, '&amp;')
            .replace(/</gu, '&lt;')
            .replace(/>/gu, '&gt;')
            .replace(/"/gu, '&quot;')
            .replace(/'/gu, '&#039;');
    }

    function richSourceAttributesHtml(node) {
        return Array.from(node.attributes || []).map(function (attribute) {
            return ' ' + attribute.name + '="'
                + escapeHtml(node.getAttribute(attribute.name) || '') + '"';
        }).join('');
    }

    function richInlineSourceNode(node) {
        if (node.nodeType === Node.TEXT_NODE) {
            return escapeHtml(node.nodeValue || '');
        }
        if (node.nodeType !== Node.ELEMENT_NODE) {
            return '';
        }
        var tag = node.nodeName.toLowerCase();
        var opening = '<' + tag + richSourceAttributesHtml(node) + '>';
        if (tag === 'br') {
            return opening;
        }
        return opening + Array.from(node.childNodes).map(function (child) {
            return richInlineSourceNode(child);
        }).join('') + '</' + tag + '>';
    }

    function richFormatSourceNode(node, depth) {
        var indent = RICH_SOURCE_INDENT.repeat(depth);
        if (node.nodeType === Node.TEXT_NODE) {
            var textValue = node.nodeValue || '';
            return textValue.trim() === '' ? '' : indent + escapeHtml(textValue);
        }
        if (node.nodeType !== Node.ELEMENT_NODE) {
            return '';
        }
        var blockTags = [
            'div', 'p', 'ul', 'ol', 'li', 'blockquote', 'aside',
            'h2', 'h3', 'h4', 'h5', 'h6'
        ];
        var tag = node.nodeName.toLowerCase();
        if (!blockTags.includes(tag)) {
            return indent + richInlineSourceNode(node);
        }
        var opening = '<' + tag + richSourceAttributesHtml(node) + '>';
        var closing = '</' + tag + '>';
        var hasBlockChild = Array.from(node.children || []).some(function (
            child
        ) {
            return blockTags.includes(child.nodeName.toLowerCase());
        });
        if (!hasBlockChild) {
            return indent + opening + Array.from(node.childNodes).map(
                function (child) {
                    return richInlineSourceNode(child);
                }
            ).join('') + closing;
        }

        // Splitting mixed inline/block content over formatted lines would add
        // or trim text-node whitespace. Keep that exceptional subtree inline
        // so a later one-character edit cannot join words or change spacing.
        var hasMeaningfulInlineChild = Array.from(node.childNodes).some(
            function (child) {
                if (
                    child.nodeType === Node.ELEMENT_NODE
                    && blockTags.includes(child.nodeName.toLowerCase())
                ) {
                    return false;
                }
                if (child.nodeType === Node.TEXT_NODE) {
                    return (child.nodeValue || '').trim() !== '';
                }
                return child.nodeType === Node.ELEMENT_NODE;
            }
        );
        if (hasMeaningfulInlineChild) {
            return indent + opening + Array.from(node.childNodes).map(
                function (child) {
                    return richInlineSourceNode(child);
                }
            ).join('') + closing;
        }

        var lines = [indent + opening];
        var inlineBuffer = '';
        function flushInline() {
            if (inlineBuffer.trim() !== '') {
                lines.push(
                    RICH_SOURCE_INDENT.repeat(depth + 1) + inlineBuffer.trim()
                );
            }
            inlineBuffer = '';
        }
        Array.from(node.childNodes).forEach(function (child) {
            if (
                child.nodeType === Node.ELEMENT_NODE
                && blockTags.includes(child.nodeName.toLowerCase())
            ) {
                flushInline();
                var formatted = richFormatSourceNode(child, depth + 1);
                if (formatted !== '') {
                    lines.push(formatted);
                }
                return;
            }
            inlineBuffer += richInlineSourceNode(child);
        });
        flushInline();
        lines.push(indent + closing);
        return lines.join('\n');
    }

    function richFormatSourceRoot(root) {
        return Array.from(root.childNodes).map(function (node) {
            return richFormatSourceNode(node, 0);
        }).filter(function (line) {
            return line !== '';
        }).join('\n');
    }

    function richFormatAdvancedHtml(source) {
        source = String(source);
        if (source.trim() === '') {
            return '';
        }
        var parsed = richParseAdvancedHtml(source);
        if (parsed === null) {
            throw new Error('rich-advanced-html-not-allowed');
        }
        return richFormatSourceRoot(parsed.body);
    }

    function richSerializeHtml(content) {
        return content.map(function (nodeValue) {
            if (nodeValue.type === 'break') {
                return '<br>';
            }
            var html = escapeHtml(nodeValue.text);
            (nodeValue.marks || []).filter(function (mark) {
                return ['strong', 'em', 'underline'].includes(mark);
            }).slice().reverse().forEach(function (mark) {
                var tag = mark === 'underline' ? 'u' : mark;
                html = '<' + tag + '>' + html + '</' + tag + '>';
            });
            var styleMarks = (nodeValue.marks || []).filter(function (mark) {
                return Object.prototype.hasOwnProperty.call(
                    RICH_MARK_CLASSES,
                    mark
                ) || canonicalDynamicRichMark(mark) === mark;
            });
            if (styleMarks.length > 0) {
                var attributes = [];
                styleMarks.forEach(function (mark) {
                    if (richMarkInGroup('size', mark)) {
                        attributes.push(
                            'data-ls-size="' + escapeHtml(mark.slice(5)) + '"'
                        );
                    } else if (mark.startsWith('text-rgba:')) {
                        attributes.push(
                            'data-ls-text-rgba="'
                                + escapeHtml(mark.slice('text-rgba:'.length))
                                + '"'
                        );
                    } else if (mark.startsWith('background-rgba:')) {
                        attributes.push(
                            'data-ls-background-rgba="'
                                + escapeHtml(
                                    mark.slice('background-rgba:'.length)
                                ) + '"'
                        );
                    } else if (richMarkInGroup('color', mark)) {
                        attributes.push(
                            'data-ls-text-color="'
                                + escapeHtml(mark.slice(5)) + '"'
                        );
                    } else if (richMarkInGroup('background', mark)) {
                        attributes.push(
                            'data-ls-background-color="'
                                + escapeHtml(mark.slice(11)) + '"'
                        );
                    }
                });
                html = '<span ' + attributes.join(' ') + '>'
                    + html + '</span>';
            }
            if (nodeValue.type === 'link') {
                var linkAttributes = [
                    'href="' + escapeHtml(nodeValue.href) + '"'
                ];
                if (nodeValue.title) {
                    linkAttributes.push(
                        'title="' + escapeHtml(nodeValue.title) + '"'
                    );
                }
                if (nodeValue.target === 'new') {
                    linkAttributes.push('target="_blank"');
                }
                html = '<a ' + linkAttributes.join(' ') + '>'
                    + html + '</a>';
            }
            return html;
        }).join('');
    }

    function richSerializeHeadingHtml(level, content) {
        if (!HEADING_LEVELS.includes(level)) {
            throw new Error('rich-heading-level-not-allowed');
        }
        return '<h' + level + '>' + richSerializeHtml(content)
            + '</h' + level + '>';
    }

    function richSerializeEditorHtml(state) {
        if (state.sourceOnlyMode) {
            return state.advancedHtmlDraft;
        }
        if (state.advancedMode) {
            return richFormatAdvancedHtml(state.advancedHtmlDraft);
        }
        if (state.textFlowMode) {
            return richSerializeTextFlowHtml(state.flowDraft);
        }
        if (state.headingMode) {
            return richSerializeHeadingHtml(state.headingLevel, state.draft);
        }
        return richSerializeHtml(state.draft);
    }

    function richParseEditorHtml(state, source) {
        if (
            state.block
            && ['paragraph', 'embed'].includes(state.block.type)
            && (
                state.advancedMode
                || state.wasAdvanced
                || state.cssSource.value.trim() !== ''
                || !richSourceUsesStandardParagraph(state, source)
            )
        ) {
            richCommitAdvancedEditors(state);
            return;
        }
        if (state.textFlowMode) {
            state.flowDraft = richParseTextFlowHtml(source);
            return;
        }
        if (state.headingMode) {
            var heading = richParseHeadingHtml(
                source,
                state.allowedHeadingLevels
            );
            state.headingLevel = heading.level;
            state.draft = heading.content;
            state.title.textContent = 'Editar encabezado H' + heading.level;
            return;
        }
        state.draft = richParseHtml(source, state.allowBreak);
    }

    function richNormalizeTextFlow(content) {
        if (isTextFlowContent(content)) {
            return richClone(content);
        }
        return [{
            type: 'paragraph',
            content: richClone(content)
        }];
    }

    function richLegacyListFlow(block) {
        var flowList = {
            type: 'list',
            ordered: block.ordered === true,
            items: (block.items || []).map(function (item) {
                var projected = { content: richClone(item.content || []) };
                if (Object.prototype.hasOwnProperty.call(item, 'id')) {
                    projected.id = item.id;
                }
                return projected;
            })
        };
        if (Object.prototype.hasOwnProperty.call(block, 'marker')) {
            flowList.marker = block.marker;
        }
        return [flowList];
    }

    function richFlowCanStayLegacyList(content) {
        return Array.isArray(content)
            && content.length === 1
            && content[0]
            && content[0].type === 'list';
    }

    function richConvertFlowBlocks(
        content,
        selectedIndexes,
        ordered,
        conversion
    ) {
        var options = conversion && typeof conversion === 'object'
            ? conversion
            : {};
        var selected = new Set(
            (selectedIndexes || []).filter(function (index) {
                return Number.isInteger(index)
                    && index >= 0
                    && index < content.length;
            })
        );
        if (selected.size === 0) {
            return richClone(content);
        }
        var selectedNodes = content.filter(function (unused, index) {
            return selected.has(index);
        });
        var unwrap = options.forceUnwrap === true || (
            options.forceList !== true
            && selectedNodes.length > 0
            && selectedNodes.every(function (node) {
                return node.type === 'list' && node.ordered === ordered;
            })
        );
        var converted = [];
        var pendingItems = [];

        function flushPending() {
            if (pendingItems.length === 0) {
                return;
            }
            var listNode = {
                type: 'list',
                ordered: ordered,
                items: pendingItems
            };
            if (
                typeof options.marker === 'string'
                && richFlowListMarkerAllowed(ordered, options.marker)
            ) {
                listNode.marker = options.marker;
            } else if (
                selectedNodes.length === 1
                && selectedNodes[0].type === 'list'
                && selectedNodes[0].ordered === ordered
                && Object.prototype.hasOwnProperty.call(
                    selectedNodes[0],
                    'marker'
                )
            ) {
                listNode.marker = selectedNodes[0].marker;
            }
            converted.push(listNode);
            pendingItems = [];
        }

        content.forEach(function (node, index) {
            if (!selected.has(index)) {
                flushPending();
                converted.push(richClone(node));
                return;
            }
            if (unwrap) {
                if (node.type === 'list') {
                    node.items.forEach(function (item) {
                        converted.push({
                            type: 'paragraph',
                            content: richClone(item.content)
                        });
                    });
                } else {
                    converted.push(richClone(node));
                }
                return;
            }
            if (node.type === 'paragraph') {
                pendingItems.push({ content: richClone(node.content) });
                return;
            }
            if (node.type === 'list') {
                Array.prototype.push.apply(
                    pendingItems,
                    node.items.map(function (item) {
                        return richClone(item);
                    })
                );
            }
        });
        flushPending();
        return converted;
    }

    function richParagraphPresentationFromList(presentation) {
        var source = presentation || {};
        var converted = defaultPresentation('paragraph');
        ['width', 'align', 'text_align', 'spacing_before', 'spacing_after']
            .forEach(function (key) {
                if (typeof source[key] === 'string') {
                    converted[key] = source[key];
                }
            });
        converted.size = canonicalPresentationSize(
            source.size || source.font_size
        ) || 'm';
        converted.text_color = validPresentationTextColor(source.text_color)
            ? source.text_color
            : 'default';
        converted.font_weight = 'default';
        return converted;
    }

    function richLegacyListItems(context, existingItems, projectedItems) {
        var reserved = new Set((existingItems || []).map(function (item) {
            return item.id;
        }));
        return (projectedItems || []).map(function (item, index) {
            var existing = existingItems[index];
            var id = existing && UUID_V4.test(existing.id || '')
                ? existing.id
                : uniqueUuid(context, reserved);
            reserved.add(id);
            return {
                id: id,
                content: richClone(item.content || [])
            };
        });
    }

    function richFlowTypeForTag(tag) {
        if (tag === 'p') {
            return 'paragraph';
        }
        if (/^h[2-6]$/u.test(tag)) {
            return 'heading';
        }
        if (tag === 'blockquote') {
            return 'quote';
        }
        if (tag === 'aside') {
            return 'callout';
        }
        return null;
    }

    function richFlowBlockAttributesAllowed(node, type) {
        if (type === 'paragraph') {
            return richAttributesAllowed(node, []);
        }
        if (type === 'heading') {
            return richAttributesAllowed(node, [
                'data-content-heading-preset'
            ]) && (
                !node.hasAttribute('data-content-heading-preset')
                || HEADING_PRESETS.includes(
                    node.getAttribute('data-content-heading-preset')
                )
            );
        }
        if (type === 'quote') {
            var quoteAttributes = [
                'data-content-quote-author',
                'data-content-quote-source',
                'data-content-quote-preset'
            ];
            if (!richAttributesAllowed(node, quoteAttributes)) {
                return false;
            }
            var author = node.hasAttribute('data-content-quote-author')
                ? (node.getAttribute('data-content-quote-author') || null)
                : undefined;
            var source = node.hasAttribute('data-content-quote-source')
                ? (node.getAttribute('data-content-quote-source') || null)
                : undefined;
            return (
                author === undefined || optionalSingleLine(author, 255)
            ) && (
                source === undefined || optionalSingleLine(source, 500)
            ) && (
                !node.hasAttribute('data-content-quote-preset')
                || QUOTE_PRESETS.includes(
                    node.getAttribute('data-content-quote-preset')
                )
            );
        }
        if (node.attributes.length === 0) {
            return true;
        }
        return richAttributesAllowed(node, [
            'data-content-callout', 'role', 'data-content-callout-tone'
        ])
            && node.getAttribute('data-content-callout') === 'true'
            && node.getAttribute('role') === 'note'
            && (
                !node.hasAttribute('data-content-callout-tone')
                || CALLOUT_TONES.includes(
                    node.getAttribute('data-content-callout-tone')
                )
            );
    }

    function richFlowListAttributesAllowed(node, ordered, strict) {
        var markers = ordered
            ? ['decimal', 'lower-alpha', 'upper-alpha']
            : ['disc', 'circle', 'square'];
        var marker = node.hasAttribute('data-content-list-marker')
            ? node.getAttribute('data-content-list-marker')
            : null;
        if (
            !richAttributesAllowed(
                node,
                strict
                    ? ['data-content-list-marker']
                    : ['data-content-list-marker', 'style']
            )
            || (marker !== null && !markers.includes(marker))
        ) {
            return false;
        }
        if (!node.hasAttribute('style')) {
            return true;
        }
        return !strict
            && marker !== null
            && node.style.length === 1
            && node.style.item(0) === 'list-style-type'
            && node.style.getPropertyPriority('list-style-type') === ''
            && node.style.getPropertyValue('list-style-type').trim()
                === marker;
    }

    function richFlowListItemAttributesAllowed(node) {
        return richAttributesAllowed(node, ['data-content-list-item-id'])
            && (
                !node.hasAttribute('data-content-list-item-id')
                || UUID_V4.test(
                    node.getAttribute('data-content-list-item-id') || ''
                )
            );
    }

    function richCaretFillerNode(node) {
        return node
            && node.nodeType === Node.ELEMENT_NODE
            && node.nodeName.toLowerCase() === 'br'
            && richAttributesAllowed(node, [
                'data-blog-rich-caret-filler'
            ])
            && node.getAttribute('data-blog-rich-caret-filler') === 'true';
    }

    function richPendingCaretParagraph(node) {
        return node
            && node.nodeType === Node.ELEMENT_NODE
            && node.nodeName.toLowerCase() === 'p'
            && node.childNodes.length > 0
            && richCaretFillerNode(node.lastChild)
            && Array.from(node.childNodes).slice(0, -1).every(
                function (child) {
                    return child.nodeType === Node.ELEMENT_NODE
                        && child.nodeName.toLowerCase() === 'br'
                        && richAttributesAllowed(child, []);
                }
            );
    }

    function richParseTextFlowRoot(root, strict) {
        var flow = [];
        Array.from(root.childNodes).forEach(function (node) {
            if (node.nodeType === Node.TEXT_NODE) {
                if ((node.nodeValue || '').trim() !== '') {
                    throw new Error('rich-html-not-allowed');
                }
                return;
            }
            if (node.nodeType !== Node.ELEMENT_NODE) {
                throw new Error('rich-html-not-allowed');
            }
            if (!strict && richPendingCaretParagraph(node)) {
                return;
            }
            var tag = node.nodeName.toLowerCase();
            if (tag === 'br' && richAttributesAllowed(node, [])) {
                flow.push({ type: 'break' });
                return;
            }
            var flowType = richFlowTypeForTag(tag);
            if (flowType !== null) {
                if (!richFlowBlockAttributesAllowed(node, flowType)) {
                    throw new Error('rich-html-not-allowed');
                }
                var flowContent = richParseInlineRoot(
                    node,
                    true,
                    strict,
                    true
                );
                if (flowType === 'paragraph' && flowContent.length === 0) {
                    flow.push({ type: 'break' });
                    return;
                }
                var flowBlock = {
                    type: flowType,
                    content: flowContent
                };
                if (flowType === 'heading') {
                    flowBlock.level = Number(tag.slice(1));
                    if (node.hasAttribute('data-content-heading-preset')) {
                        flowBlock.preset = node.getAttribute(
                            'data-content-heading-preset'
                        );
                    }
                } else if (flowType === 'quote') {
                    if (node.hasAttribute('data-content-quote-author')) {
                        flowBlock.author = node.getAttribute(
                            'data-content-quote-author'
                        ) || null;
                    }
                    if (node.hasAttribute('data-content-quote-source')) {
                        flowBlock.source = node.getAttribute(
                            'data-content-quote-source'
                        ) || null;
                    }
                    if (node.hasAttribute('data-content-quote-preset')) {
                        flowBlock.preset = node.getAttribute(
                            'data-content-quote-preset'
                        );
                    }
                } else if (
                    flowType === 'callout'
                    && node.hasAttribute('data-content-callout-tone')
                ) {
                    flowBlock.tone = node.getAttribute(
                        'data-content-callout-tone'
                    );
                }
                flow.push(flowBlock);
                return;
            }
            if (
                !['ul', 'ol'].includes(tag)
                || !richFlowListAttributesAllowed(
                    node,
                    tag === 'ol',
                    strict
                )
            ) {
                throw new Error('rich-html-not-allowed');
            }
            var items = [];
            Array.from(node.childNodes).forEach(function (item) {
                if (item.nodeType === Node.TEXT_NODE) {
                    if ((item.nodeValue || '').trim() !== '') {
                        throw new Error('rich-html-not-allowed');
                    }
                    return;
                }
                if (
                    item.nodeType !== Node.ELEMENT_NODE
                    || item.nodeName.toLowerCase() !== 'li'
                    || !richFlowListItemAttributesAllowed(item)
                ) {
                    throw new Error('rich-html-not-allowed');
                }
                var flowItem = {
                    content: richParseInlineRoot(item, true, strict, true)
                };
                if (item.hasAttribute('data-content-list-item-id')) {
                    flowItem.id = item.getAttribute(
                        'data-content-list-item-id'
                    );
                }
                items.push(flowItem);
            });
            var flowList = {
                type: 'list',
                ordered: tag === 'ol',
                items: items
            };
            if (node.hasAttribute('data-content-list-marker')) {
                flowList.marker = node.getAttribute(
                    'data-content-list-marker'
                );
            }
            flow.push(flowList);
        });
        if (flow.length === 0) {
            flow.push({ type: 'paragraph', content: [] });
        }
        if (!validTextFlowContent(flow, true)) {
            throw new Error('rich-text-invalid');
        }
        return flow;
    }

    function richParseTextFlowHtml(source) {
        if (typeof source !== 'string' || bytes(source) > MAX_JSON_BYTES) {
            throw new Error('rich-text-too-large');
        }
        if (/<\s*\/?\s*(?:html|head|body)\b|<!doctype\b/iu.test(source)) {
            throw new Error('rich-html-not-allowed');
        }
        var parsed = new DOMParser().parseFromString(source, 'text/html');
        if (
            !parsed
            || !parsed.body
            || parsed.doctype !== null
            || parsed.head.childNodes.length > 0
            || parsed.documentElement.attributes.length > 0
            || parsed.body.attributes.length > 0
        ) {
            throw new Error('rich-html-not-allowed');
        }
        return richParseTextFlowRoot(parsed.body, true);
    }

    function richAdvancedVisualFlowElements(root) {
        var elements = [];
        Array.from(root.childNodes).forEach(function (node) {
            if (node.nodeType === Node.TEXT_NODE) {
                if ((node.nodeValue || '').trim() !== '') {
                    throw new Error('rich-html-not-allowed');
                }
                return;
            }
            if (
                node.nodeType !== Node.ELEMENT_NODE
                || ![
                    'p', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol',
                    'blockquote', 'aside', 'br'
                ].includes(node.nodeName.toLowerCase())
            ) {
                throw new Error('rich-html-not-allowed');
            }
            elements.push(node);
        });
        return elements;
    }

    function richParseAdvancedVisualFlowHtml(source) {
        if (source === '') {
            return [{ type: 'paragraph', content: [] }];
        }
        var parsed = richParseAdvancedHtml(source);
        if (parsed === null) {
            throw new Error('rich-html-not-allowed');
        }
        var flow = richAdvancedVisualFlowElements(parsed.body).map(
            function (node) {
                var tag = node.nodeName.toLowerCase();
                if (tag === 'br') {
                    return { type: 'break' };
                }
                var flowType = richFlowTypeForTag(tag);
                if (flowType !== null) {
                    var flowContent = richParseInlineRoot(
                        node,
                        true,
                        true,
                        true
                    );
                    if (
                        flowType === 'paragraph'
                        && flowContent.length === 0
                    ) {
                        return { type: 'break' };
                    }
                    var block = {
                        type: flowType,
                        content: flowContent
                    };
                    if (flowType === 'heading') {
                        block.level = Number(tag.slice(1));
                    }
                    return block;
                }
                var items = [];
                Array.from(node.childNodes).forEach(function (item) {
                    if (item.nodeType === Node.TEXT_NODE) {
                        if ((item.nodeValue || '').trim() !== '') {
                            throw new Error('rich-html-not-allowed');
                        }
                        return;
                    }
                    if (
                        item.nodeType !== Node.ELEMENT_NODE
                        || item.nodeName.toLowerCase() !== 'li'
                    ) {
                        throw new Error('rich-html-not-allowed');
                    }
                    items.push({
                        content: richParseInlineRoot(item, true, true, true)
                    });
                });
                return {
                    type: 'list',
                    ordered: tag === 'ol',
                    items: items
                };
            }
        );
        if (flow.length === 0) {
            flow.push({ type: 'paragraph', content: [] });
        }
        if (!validTextFlowContent(flow, true)) {
            throw new Error('rich-text-invalid');
        }
        return flow;
    }

    function richAdvancedVisualStructureLocked(source) {
        if (source === '') {
            return false;
        }
        var parsed = richParseAdvancedHtml(source);
        if (parsed === null) {
            return true;
        }
        return richAdvancedVisualFlowElements(parsed.body).some(function (node) {
            return node.attributes.length > 0 || (
                ['ul', 'ol'].includes(node.nodeName.toLowerCase())
                && Array.from(node.children).some(function (item) {
                    return item.attributes.length > 0;
                })
            );
        });
    }

    function richSerializeTextFlowHtml(content) {
        return content.map(function (flowNode) {
            if (flowNode.type === 'break') {
                return '<br>';
            }
            if (flowNode.type !== 'list') {
                var tag = flowNode.type === 'heading'
                    ? 'h' + flowNode.level
                    : (
                        flowNode.type === 'quote'
                            ? 'blockquote'
                            : (flowNode.type === 'callout' ? 'aside' : 'p')
                    );
                var attributes = [];
                if (
                    flowNode.type === 'heading'
                    && Object.prototype.hasOwnProperty.call(
                        flowNode,
                        'preset'
                    )
                ) {
                    attributes.push(
                        'data-content-heading-preset="'
                            + escapeHtml(flowNode.preset) + '"'
                    );
                } else if (flowNode.type === 'quote') {
                    ['author', 'source', 'preset'].forEach(function (key) {
                        if (!Object.prototype.hasOwnProperty.call(flowNode, key)) {
                            return;
                        }
                        attributes.push(
                            'data-content-quote-' + key + '="'
                                + escapeHtml(flowNode[key] === null
                                    ? ''
                                    : flowNode[key]) + '"'
                        );
                    });
                } else if (flowNode.type === 'callout') {
                    attributes.push(
                        'data-content-callout="true"',
                        'role="note"'
                    );
                    if (Object.prototype.hasOwnProperty.call(flowNode, 'tone')) {
                        attributes.push(
                            'data-content-callout-tone="'
                                + escapeHtml(flowNode.tone) + '"'
                        );
                    }
                }
                var attributeHtml = attributes.length > 0
                    ? ' ' + attributes.join(' ')
                    : '';
                return '<' + tag + attributeHtml + '>'
                    + richSerializeHtml(flowNode.content)
                    + '</' + tag + '>';
            }
            var tag = flowNode.ordered ? 'ol' : 'ul';
            var listAttributes = Object.prototype.hasOwnProperty.call(
                flowNode,
                'marker'
            ) ? ' data-content-list-marker="'
                + escapeHtml(flowNode.marker) + '"' : '';
            return '<' + tag + listAttributes + '>\n'
                + flowNode.items.map(function (item) {
                var itemAttributes = Object.prototype.hasOwnProperty.call(
                    item,
                    'id'
                ) ? ' data-content-list-item-id="'
                    + escapeHtml(item.id) + '"' : '';
                return RICH_SOURCE_INDENT + '<li' + itemAttributes + '>'
                    + richSerializeHtml(item.content) + '</li>';
            }).join('\n') + '\n</' + tag + '>';
        }).join('\n');
    }

    function richAppendInlineSourceDom(container, content) {
        content.forEach(function (nodeValue) {
            if (nodeValue.type === 'break') {
                container.append(document.createElement('br'));
                return;
            }
            var child = document.createTextNode(nodeValue.text);
            (nodeValue.marks || []).filter(function (mark) {
                return ['strong', 'em', 'underline'].includes(mark);
            }).slice().reverse().forEach(function (mark) {
                var wrapper = document.createElement(
                    mark === 'underline' ? 'u' : mark
                );
                wrapper.append(child);
                child = wrapper;
            });
            var styleMarks = (nodeValue.marks || []).filter(function (mark) {
                return Object.prototype.hasOwnProperty.call(
                    RICH_MARK_CLASSES,
                    mark
                ) || canonicalDynamicRichMark(mark) === mark;
            });
            if (styleMarks.length > 0) {
                var style = document.createElement('span');
                styleMarks.forEach(function (mark) {
                    if (richMarkInGroup('size', mark)) {
                        style.setAttribute(
                            RICH_ADVANCED_MARK_ATTRIBUTES.size,
                            mark.slice(5)
                        );
                    } else if (mark.startsWith('text-rgba:')) {
                        style.setAttribute(
                            RICH_ADVANCED_MARK_ATTRIBUTES.textRgba,
                            mark.slice('text-rgba:'.length)
                        );
                    } else if (mark.startsWith('background-rgba:')) {
                        style.setAttribute(
                            RICH_ADVANCED_MARK_ATTRIBUTES.backgroundRgba,
                            mark.slice('background-rgba:'.length)
                        );
                    } else if (richMarkInGroup('color', mark)) {
                        style.setAttribute(
                            RICH_ADVANCED_MARK_ATTRIBUTES.textColor,
                            mark.slice(5)
                        );
                    } else if (richMarkInGroup('background', mark)) {
                        style.setAttribute(
                            RICH_ADVANCED_MARK_ATTRIBUTES.backgroundColor,
                            mark.slice(11)
                        );
                    }
                });
                style.append(child);
                child = style;
            }
            if (nodeValue.type === 'link') {
                var link = document.createElement('a');
                link.setAttribute('href', nodeValue.href);
                if (nodeValue.title) {
                    link.setAttribute('title', nodeValue.title);
                }
                if (nodeValue.target === 'new') {
                    link.setAttribute('target', '_blank');
                }
                link.append(child);
                child = link;
            }
            container.append(child);
        });
    }

    function richSerializeAdvancedVisualFlowHtml(source, content) {
        if (source === '') {
            return richSerializeTextFlowHtml(content);
        }
        content = content.map(function (flowNode) {
            return flowNode
                && flowNode.type === 'paragraph'
                && Array.isArray(flowNode.content)
                && flowNode.content.length === 0
                ? { type: 'break' }
                : flowNode;
        });
        var parsed = richParseAdvancedHtml(source);
        if (parsed === null) {
            throw new Error('rich-html-not-allowed');
        }
        Array.from(parsed.body.children).forEach(function (node) {
            if (
                node.nodeName.toLowerCase() === 'p'
                && Array.from(node.childNodes).every(function (child) {
                    return child.nodeType === Node.TEXT_NODE
                        && (child.nodeValue || '').trim() === '';
                })
            ) {
                node.replaceWith(parsed.createElement('br'));
            }
        });
        var elements = richAdvancedVisualFlowElements(parsed.body);
        var baseline = richParseAdvancedVisualFlowHtml(source);

        function splitMatches(original, left, right) {
            return original
                && left
                && right
                && original.type !== 'list'
                && original.type !== 'break'
                && left.type === original.type
                && right.type === 'paragraph'
                && !emptyParagraph(left)
                && !emptyParagraph(right)
                && (
                    original.type !== 'heading'
                    || original.level === left.level
                );
        }

        function flowFingerprint(flowNode) {
            if (flowNode && flowNode.type === 'break') {
                return 'break';
            }
            if (!flowNode || flowNode.type === 'list') {
                return JSON.stringify(flowNode || null);
            }
            return JSON.stringify({
                type: flowNode.type,
                level: flowNode.type === 'heading' ? flowNode.level : null,
                content: richMergeContent(
                    richClone(flowNode.content || [])
                )
            });
        }

        function copySplitAttributes(sourceNode, targetNode) {
            Array.from(sourceNode.attributes).forEach(function (attribute) {
                var name = attribute.name.toLowerCase();
                var shared = [
                    'class', 'title', 'lang', 'dir', 'role',
                    'aria-label', 'aria-hidden'
                ].includes(name) || name.startsWith('data-content-');
                if (
                    !shared
                    || name === 'data-content-callout'
                    || (
                        name === 'role'
                        && attribute.value.toLowerCase() === 'note'
                    )
                ) {
                    return;
                }
                targetNode.setAttribute(attribute.name, attribute.value);
            });
        }

        function inlineFingerprint(flowItem) {
            return JSON.stringify(richMergeContent(richClone(
                flowItem && Array.isArray(flowItem.content)
                    ? flowItem.content
                    : []
            )));
        }

        function inlineIsEmpty(flowItem) {
            return richInlineTextValue(
                flowItem && Array.isArray(flowItem.content)
                    ? flowItem.content
                    : []
            ).trim() === '';
        }

        function otherFlowNodesStayStable(candidate, insertedAfter) {
            return baseline.every(function (flowNode, index) {
                if (index === candidate) {
                    return true;
                }
                var nextIndex = index > candidate
                    ? index + insertedAfter
                    : index;
                return flowFingerprint(flowNode)
                    === flowFingerprint(content[nextIndex]);
            });
        }

        function reconcileListItemSplit() {
            if (elements.length !== content.length) {
                return false;
            }
            for (
                var flowIndex = 0;
                flowIndex < baseline.length;
                flowIndex += 1
            ) {
                var original = baseline[flowIndex];
                var nextFlow = content[flowIndex];
                if (
                    !original
                    || original.type !== 'list'
                    || !nextFlow
                    || nextFlow.type !== 'list'
                    || original.ordered !== nextFlow.ordered
                    || nextFlow.items.length !== original.items.length + 1
                    || !otherFlowNodesStayStable(flowIndex, 0)
                ) {
                    continue;
                }
                var sourceItems = Array.from(elements[flowIndex].children);
                if (sourceItems.length !== original.items.length) {
                    continue;
                }
                for (
                    var itemIndex = 0;
                    itemIndex < original.items.length;
                    itemIndex += 1
                ) {
                    var beforeStable = original.items.slice(0, itemIndex)
                        .every(function (item, index) {
                            return inlineFingerprint(item)
                                === inlineFingerprint(nextFlow.items[index]);
                        });
                    var afterStable = original.items.slice(itemIndex + 1)
                        .every(function (item, index) {
                            return inlineFingerprint(item)
                                === inlineFingerprint(
                                    nextFlow.items[itemIndex + index + 2]
                                );
                        });
                    if (!beforeStable || !afterStable) {
                        continue;
                    }
                    var splitItem = parsed.createElement('li');
                    copySplitAttributes(sourceItems[itemIndex], splitItem);
                    splitItem.removeAttribute('data-content-list-item-id');
                    sourceItems[itemIndex].after(splitItem);
                    return true;
                }
            }
            return false;
        }

        function emptyParagraph(flowNode) {
            return flowNode
                && flowNode.type === 'paragraph'
                && inlineIsEmpty(flowNode);
        }

        function priorListItemsStayStable(original, nextFlow) {
            return nextFlow.items.every(function (item, index) {
                return inlineFingerprint(item)
                    === inlineFingerprint(original.items[index]);
            });
        }

        function reconcileListExit() {
            for (
                var flowIndex = 0;
                flowIndex < baseline.length;
                flowIndex += 1
            ) {
                var original = baseline[flowIndex];
                if (
                    !original
                    || original.type !== 'list'
                    || original.items.length === 0
                    || !inlineIsEmpty(
                        original.items[original.items.length - 1]
                    )
                ) {
                    continue;
                }
                var sourceList = elements[flowIndex];
                var sourceItems = sourceList
                    ? Array.from(sourceList.children)
                    : [];
                if (sourceItems.length !== original.items.length) {
                    continue;
                }

                if (original.items.length === 1) {
                    if (
                        content.length !== baseline.length
                        || !content[flowIndex]
                        || content[flowIndex].type !== 'break'
                        || !otherFlowNodesStayStable(flowIndex, 0)
                    ) {
                        continue;
                    }
                    sourceList.replaceWith(parsed.createElement('br'));
                    elements = richAdvancedVisualFlowElements(parsed.body);
                    return true;
                }

                var nextFlow = content[flowIndex];
                if (
                    content.length !== baseline.length + 1
                    || !nextFlow
                    || nextFlow.type !== 'list'
                    || nextFlow.ordered !== original.ordered
                    || nextFlow.items.length !== original.items.length - 1
                    || !priorListItemsStayStable(original, nextFlow)
                    || !content[flowIndex + 1]
                    || content[flowIndex + 1].type !== 'break'
                    || !otherFlowNodesStayStable(flowIndex, 1)
                ) {
                    continue;
                }
                sourceItems[sourceItems.length - 1].remove();
                sourceList.after(parsed.createElement('br'));
                elements = richAdvancedVisualFlowElements(parsed.body);
                return true;
            }
            return false;
        }

        function reconcileEmptyParagraphRemoval() {
            if (baseline.length !== content.length + 1) {
                return false;
            }
            for (
                var flowIndex = 0;
                flowIndex < baseline.length;
                flowIndex += 1
            ) {
                if (
                    baseline[flowIndex].type !== 'break'
                    || baseline.slice(0, flowIndex).some(function (
                        flowNode,
                        index
                    ) {
                        return flowFingerprint(flowNode)
                            !== flowFingerprint(content[index]);
                    })
                    || baseline.slice(flowIndex + 1).some(function (
                        flowNode,
                        index
                    ) {
                        return flowFingerprint(flowNode)
                            !== flowFingerprint(content[flowIndex + index]);
                    })
                ) {
                    continue;
                }
                elements[flowIndex].remove();
                elements = richAdvancedVisualFlowElements(parsed.body);
                return true;
            }
            return false;
        }

        reconcileListItemSplit();
        reconcileListExit();
        reconcileEmptyParagraphRemoval();

        if (elements.length === content.length) {
            elements.forEach(function (sourceNode, index) {
                var sourceBreak = sourceNode.nodeName.toLowerCase() === 'br';
                var flowBreak = content[index]
                    && content[index].type === 'break';
                if (sourceBreak === flowBreak) {
                    return;
                }
                sourceNode.replaceWith(parsed.createElement(
                    flowBreak ? 'br' : 'p'
                ));
            });
            elements = richAdvancedVisualFlowElements(parsed.body);
        }

        var baselineBlocks = baseline.filter(function (flowNode) {
            return flowNode.type !== 'break';
        });
        var contentBlocks = content.filter(function (flowNode) {
            return flowNode.type !== 'break';
        });
        if (
            baselineBlocks.length === contentBlocks.length
            && elements.filter(function (node) {
                return node.nodeName.toLowerCase() !== 'br';
            }).length === contentBlocks.length
        ) {
            var sourceBlocks = elements.filter(function (node) {
                return node.nodeName.toLowerCase() !== 'br';
            });
            var rootNodes = [];
            var sourceBlockIndex = 0;
            content.forEach(function (flowNode) {
                if (flowNode.type === 'break') {
                    rootNodes.push(parsed.createElement('br'));
                    return;
                }
                rootNodes.push(sourceBlocks[sourceBlockIndex]);
                sourceBlockIndex += 1;
            });
            parsed.body.replaceChildren.apply(parsed.body, rootNodes);
            elements = richAdvancedVisualFlowElements(parsed.body);
        }

        if (elements.length + 1 === content.length) {
            var splitIndex = -1;
            for (var candidate = 0; candidate < baseline.length; candidate += 1) {
                var beforeStable = baseline.slice(0, candidate).every(
                    function (node, index) {
                        return flowFingerprint(node)
                            === flowFingerprint(content[index]);
                    }
                );
                var afterStable = baseline.slice(candidate + 1).every(
                    function (node, index) {
                        return flowFingerprint(node)
                            === flowFingerprint(content[candidate + index + 2]);
                    }
                );
                if (
                    beforeStable
                    && afterStable
                    && splitMatches(
                        baseline[candidate],
                        content[candidate],
                        content[candidate + 1]
                    )
                ) {
                    splitIndex = candidate;
                    break;
                }
            }
            if (splitIndex >= 0) {
                var splitParagraph = parsed.createElement('p');
                copySplitAttributes(elements[splitIndex], splitParagraph);
                elements[splitIndex].after(splitParagraph);
                elements = richAdvancedVisualFlowElements(parsed.body);
            }
        }
        if (elements.length !== content.length) {
            throw new Error('rich-advanced-structure-locked');
        }
        function retag(node, flowNode) {
            var targetTag = flowNode.type === 'heading'
                ? 'h' + flowNode.level
                : (
                    flowNode.type === 'quote'
                        ? 'blockquote'
                        : (flowNode.type === 'callout' ? 'aside' : 'p')
                );
            if (node.nodeName.toLowerCase() === targetTag) {
                return node;
            }
            var replacement = parsed.createElement(targetTag);
            var oldTag = node.nodeName.toLowerCase();
            Array.from(node.attributes).forEach(function (attribute) {
                var name = attribute.name.toLowerCase();
                if (
                    ['cite', 'start', 'reversed', 'type', 'value'].includes(name)
                    || name === 'data-content-callout'
                    || (
                        name === 'role'
                        && oldTag === 'aside'
                        && attribute.value.toLowerCase() === 'note'
                    )
                ) {
                    return;
                }
                replacement.setAttribute(attribute.name, attribute.value);
            });
            if (flowNode.type === 'callout') {
                replacement.setAttribute('data-content-callout', 'true');
                replacement.setAttribute('role', 'note');
            }
            while (node.firstChild) {
                replacement.append(node.firstChild);
            }
            node.replaceWith(replacement);
            return replacement;
        }

        elements.forEach(function (sourceNode, index) {
            var flowNode = content[index];
            var node = sourceNode;
            var tag = node.nodeName.toLowerCase();
            if (flowNode.type === 'break') {
                if (tag !== 'br') {
                    throw new Error('rich-advanced-structure-locked');
                }
                return;
            }
            var flowType = richFlowTypeForTag(tag);
            if (
                flowType !== null
                && flowNode.type !== 'list'
            ) {
                node = retag(node, flowNode);
                node.replaceChildren();
                richAppendInlineSourceDom(node, flowNode.content);
                return;
            }
            if (
                !['ul', 'ol'].includes(tag)
                || flowNode.type !== 'list'
                || (tag === 'ol') !== flowNode.ordered
            ) {
                throw new Error('rich-advanced-structure-locked');
            }
            var items = Array.from(node.children);
            if (
                items.length !== flowNode.items.length
                || !items.every(function (item) {
                    return item.nodeName.toLowerCase() === 'li';
                })
            ) {
                throw new Error('rich-advanced-structure-locked');
            }
            items.forEach(function (item, itemIndex) {
                item.replaceChildren();
                richAppendInlineSourceDom(
                    item,
                    flowNode.items[itemIndex].content
                );
            });
        });
        return richFormatSourceRoot(parsed.body);
    }

    function richApplyTextFlowMetadata(node, flowNode) {
        if (flowNode.type === 'heading'
            && Object.prototype.hasOwnProperty.call(flowNode, 'preset')) {
            node.setAttribute('data-content-heading-preset', flowNode.preset);
            return;
        }
        if (flowNode.type === 'quote') {
            ['author', 'source', 'preset'].forEach(function (key) {
                if (Object.prototype.hasOwnProperty.call(flowNode, key)) {
                    node.setAttribute(
                        'data-content-quote-' + key,
                        flowNode[key] === null ? '' : flowNode[key]
                    );
                }
            });
            return;
        }
        if (flowNode.type === 'callout') {
            node.setAttribute('data-content-callout', 'true');
            node.setAttribute('role', 'note');
            if (Object.prototype.hasOwnProperty.call(flowNode, 'tone')) {
                node.setAttribute('data-content-callout-tone', flowNode.tone);
            }
            return;
        }
        if (flowNode.type === 'list'
            && Object.prototype.hasOwnProperty.call(flowNode, 'marker')) {
            node.setAttribute('data-content-list-marker', flowNode.marker);
        }
    }

    function appendTextFlowPreview(container, content) {
        content.forEach(function (flowNode) {
            if (flowNode.type === 'break') {
                container.append(document.createElement('br'));
                return;
            }
            if (flowNode.type !== 'list') {
                var tag = flowNode.type === 'heading'
                    ? 'h' + flowNode.level
                    : (
                        flowNode.type === 'quote'
                            ? 'blockquote'
                            : (flowNode.type === 'callout' ? 'aside' : 'p')
                );
                var block = document.createElement(tag);
                richApplyTextFlowMetadata(block, flowNode);
                appendInlinePreview(block, flowNode.content);
                container.append(block);
                return;
            }
            var list = document.createElement(flowNode.ordered ? 'ol' : 'ul');
            richApplyTextFlowMetadata(list, flowNode);
            if (Object.prototype.hasOwnProperty.call(flowNode, 'marker')) {
                list.style.listStyleType = flowNode.marker;
            }
            flowNode.items.forEach(function (item) {
                var listItem = document.createElement('li');
                if (Object.prototype.hasOwnProperty.call(item, 'id')) {
                    listItem.setAttribute(
                        'data-content-list-item-id',
                        item.id
                    );
                }
                appendInlinePreview(listItem, item.content);
                list.append(listItem);
            });
            container.append(list);
        });
    }

    function richAdvancedVisualSemanticAttributes(node) {
        var tag = node.nodeName.toLowerCase();
        var type = richFlowTypeForTag(tag);
        var allowed = type === 'heading'
            ? ['data-content-heading-preset']
            : (type === 'quote' ? [
                'data-content-quote-author',
                'data-content-quote-source',
                'data-content-quote-preset'
            ] : (type === 'callout' ? [
                'data-content-callout',
                'role',
                'data-content-callout-tone'
            ] : (['ul', 'ol'].includes(tag) ? [
                'data-content-list-marker', 'style'
            ] : (tag === 'li'
                ? ['data-content-list-item-id'] : []))));
        var probe = document.createElement(tag);
        allowed.forEach(function (name) {
            if (node.hasAttribute(name)) {
                probe.setAttribute(name, node.getAttribute(name));
            }
        });
        var valid = ['ul', 'ol'].includes(tag)
            ? richFlowListAttributesAllowed(probe, tag === 'ol', false)
            : (tag === 'li'
                ? richFlowListItemAttributesAllowed(probe)
                : (type === null
                    || richFlowBlockAttributesAllowed(probe, type)));
        return valid ? allowed : [];
    }

    function richAdvancedVisualProjectNodeAttributes(state, source, visual) {
        Array.from(source.attributes).forEach(function (attribute) {
            if (
                attribute.name.toLowerCase() !== 'style'
                && visual.getAttribute(attribute.name) !== attribute.value
            ) {
                visual.setAttribute(attribute.name, attribute.value);
            }
        });
        var semantic = richAdvancedVisualSemanticAttributes(visual);
        var projected = new Map();
        Array.from(source.attributes).forEach(function (attribute) {
            var name = attribute.name.toLowerCase();
            if (name !== 'style' && !semantic.includes(name)) {
                projected.set(name, attribute.value);
            }
        });
        if (projected.size > 0) {
            state.advancedVisualProjectedAttrs.set(visual, projected);
        }
    }

    function richAdvancedVisualProjectAttributes(state) {
        state.advancedVisualProjectedAttrs = new WeakMap();
        if (
            !state.advancedMode
            || !state.advancedVisualEditable
            || state.advancedHtmlDraft === ''
        ) {
            return;
        }
        var parsed = richParseAdvancedHtml(state.advancedHtmlDraft);
        if (parsed === null) {
            throw new Error('rich-html-not-allowed');
        }
        var sourceElements = richAdvancedVisualFlowElements(parsed.body);
        var visualElements = Array.from(state.visual.children);
        if (sourceElements.length !== visualElements.length) {
            throw new Error('rich-advanced-structure-locked');
        }
        sourceElements.forEach(function (sourceNode, index) {
            var visualNode = visualElements[index];
            var sourceTag = sourceNode.nodeName.toLowerCase();
            var visualTag = visualNode.nodeName.toLowerCase();
            if (
                sourceTag === 'p'
                && visualTag === 'br'
                && richParseInlineRoot(sourceNode, true, true, true).length === 0
            ) {
                return;
            }
            if (sourceTag !== visualTag) {
                throw new Error('rich-advanced-structure-locked');
            }
            richAdvancedVisualProjectNodeAttributes(
                state,
                sourceNode,
                visualNode
            );
            if (['ul', 'ol'].includes(sourceTag)) {
                var sourceItems = Array.from(sourceNode.children);
                var visualItems = Array.from(visualNode.children);
                if (sourceItems.length !== visualItems.length) {
                    throw new Error('rich-advanced-structure-locked');
                }
                sourceItems.forEach(function (sourceItem, itemIndex) {
                    if (
                        sourceItem.nodeName.toLowerCase() !== 'li'
                        || visualItems[itemIndex].nodeName.toLowerCase() !== 'li'
                    ) {
                        throw new Error('rich-advanced-structure-locked');
                    }
                    richAdvancedVisualProjectNodeAttributes(
                        state,
                        sourceItem,
                        visualItems[itemIndex]
                    );
                });
            }
        });
    }

    function richAdvancedVisualStripProjectedAttributes(
        state,
        liveNode,
        cloneNode
    ) {
        var projected = state.advancedVisualProjectedAttrs instanceof WeakMap
            ? state.advancedVisualProjectedAttrs.get(liveNode) : null;
        if (projected) {
            projected.forEach(function (value, name) {
                if (liveNode.getAttribute(name) !== value) {
                    throw new Error('rich-html-not-allowed');
                }
            });
        }
        var semantic = richAdvancedVisualSemanticAttributes(liveNode);
        Array.from(cloneNode.attributes).forEach(function (attribute) {
            var name = attribute.name.toLowerCase();
            if (semantic.includes(name)) {
                return;
            }
            if (!projected || projected.get(name) !== attribute.value) {
                throw new Error('rich-html-not-allowed');
            }
            cloneNode.removeAttribute(attribute.name);
        });
    }

    function richParseAdvancedVisualRoot(state) {
        var clone = state.visual.cloneNode(true);
        var liveElements = Array.from(state.visual.children);
        var cloneElements = Array.from(clone.children);
        if (liveElements.length !== cloneElements.length) {
            throw new Error('rich-html-not-allowed');
        }
        cloneElements.forEach(function (cloneNode, index) {
            var liveNode = liveElements[index];
            richAdvancedVisualStripProjectedAttributes(
                state,
                liveNode,
                cloneNode
            );
            if (['ul', 'ol'].includes(liveNode.nodeName.toLowerCase())) {
                var liveItems = Array.from(liveNode.children);
                var cloneItems = Array.from(cloneNode.children);
                if (liveItems.length !== cloneItems.length) {
                    throw new Error('rich-html-not-allowed');
                }
                cloneItems.forEach(function (cloneItem, itemIndex) {
                    richAdvancedVisualStripProjectedAttributes(
                        state,
                        liveItems[itemIndex],
                        cloneItem
                    );
                });
            }
        });
        return richParseTextFlowRoot(clone, false);
    }

    function richAdvancedPlainText(source, customPolicy) {
        if (source === '') {
            return '';
        }
        if (customPolicy === EMBED_POLICY) {
            if (!validEmbedHtml(source, customPolicy)) {
                throw new Error('rich-advanced-html-not-allowed');
            }
            var embedParsed = typeof DOMParser === 'function'
                ? new DOMParser().parseFromString(source, 'text/html')
                : null;
            return embedParsed && embedParsed.body
                ? embedParsed.body.textContent : String(source);
        }
        var parsed = richParseAdvancedHtml(source, customPolicy);
        return parsed === null ? String(source).replace(/<[^>]*>/gu, ' ') :
            parsed.body.textContent;
    }

    function richAdvancedPreviewPolicy(styleNonce) {
        var styleSource = "'nonce-" + styleNonce + "'";
        return "default-src 'none'; style-src " + styleSource
            + '; style-src-elem ' + styleSource
            + "; style-src-attr 'none'; img-src 'none'; "
            + "font-src 'none'; script-src 'none'; script-src-attr 'none'; "
            + "connect-src 'none'; frame-src 'none'; media-src 'none'; "
            + "worker-src 'none'; form-action 'none'; base-uri 'none'; "
            + "object-src 'none'";
    }

    function richAdvancedPreviewSecurity(form) {
        var styleNonce = form.dataset.blogAdvancedPreviewStyleNonce || '';
        var contentSecurityPolicy = form.dataset.blogAdvancedPreviewCsp || '';
        if (
            !/^[A-Za-z0-9+\/_-]{16,128}={0,2}$/u.test(styleNonce)
            || contentSecurityPolicy !== richAdvancedPreviewPolicy(styleNonce)
            || contentSecurityPolicy.includes("'unsafe-inline'")
        ) {
            return null;
        }
        return {
            styleNonce: styleNonce,
            contentSecurityPolicy: contentSecurityPolicy
        };
    }

    function richAdvancedPreviewPalette(form) {
        var palette = {
            color00: '#fff',
            color01: '#272727',
            color02: '#24658e',
            color03: '#092f64',
            color04: '#6b7280',
            color05: '#a16207'
        };
        if (
            typeof HTMLElement === 'undefined'
            || !(form instanceof HTMLElement)
            || typeof window.getComputedStyle !== 'function'
        ) {
            return palette;
        }
        var computed = window.getComputedStyle(form);
        Object.keys(palette).forEach(function (key) {
            var value = computed.getPropertyValue(
                '--ls-blog-editor-' + key
            ).trim();
            // These values enter a nonce-bound stylesheet. Limit the accepted
            // theme token syntax so a consumer variable cannot end a rule.
            if (/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/iu.test(value)) {
                palette[key] = value;
            }
        });
        return palette;
    }

    function richAdvancedPreviewMarkCss(palette) {
        palette = palette || richAdvancedPreviewPalette(null);
        var rules = {
            '[data-content-format-size="small"]': 'font-size:.875em!important',
            '[data-content-format-size="large"]': 'font-size:1.2em!important',
            '[data-content-format-size="xlarge"]': 'font-size:1.5em!important;line-height:1.35!important',
            '[data-content-format-text-color="color00"]': 'color:' + palette.color00 + '!important',
            '[data-content-format-text-color="color01"]': 'color:' + palette.color01 + '!important',
            '[data-content-format-text-color="color02"]': 'color:' + palette.color02 + '!important',
            '[data-content-format-text-color="color03"]': 'color:' + palette.color03 + '!important',
            '[data-content-format-text-color="color04"]': 'color:' + palette.color04 + '!important',
            '[data-content-format-text-color="color05"]': 'color:' + palette.color05 + '!important',
            '[data-content-format-text-color="basic-red"]': 'color:#b42318!important',
            '[data-content-format-text-color="basic-orange"]': 'color:#9a3412!important',
            '[data-content-format-text-color="basic-yellow"]': 'color:#713f12!important',
            '[data-content-format-text-color="basic-green"]': 'color:#176a45!important',
            '[data-content-format-text-color="basic-blue"]': 'color:#1d4ed8!important',
            '[data-content-format-text-color="basic-purple"]': 'color:#6b21a8!important',
            '[data-content-format-text-color="basic-pink"]': 'color:#9d174d!important',
            '[data-content-format-text-color="basic-gray"]': 'color:#475569!important',
            '[data-content-format-background-color="color00"]': 'background:' + palette.color00 + '!important',
            '[data-content-format-background-color="color01"]': 'background:' + palette.color01 + '!important',
            '[data-content-format-background-color="color02"]': 'background:' + palette.color02 + '!important',
            '[data-content-format-background-color="color03"]': 'background:' + palette.color03 + '!important',
            '[data-content-format-background-color="color04"]': 'background:' + palette.color04 + '!important',
            '[data-content-format-background-color="color05"]': 'background:' + palette.color05 + '!important',
            '[data-content-format-background-color="color00"]:not([data-content-format-text-color]):not([data-content-format-text-rgba])': 'color:' + palette.color01 + '!important',
            '[data-content-format-background-color="color01"]:not([data-content-format-text-color]):not([data-content-format-text-rgba]),[data-content-format-background-color="color02"]:not([data-content-format-text-color]):not([data-content-format-text-rgba]),[data-content-format-background-color="color03"]:not([data-content-format-text-color]):not([data-content-format-text-rgba])': 'color:' + palette.color00 + '!important',
            '[data-content-format-background-color="basic-red"]': 'background:#fee4e2!important',
            '[data-content-format-background-color="basic-orange"]': 'background:#ffedd5!important',
            '[data-content-format-background-color="basic-yellow"]': 'background:#fef3c7!important',
            '[data-content-format-background-color="basic-green"]': 'background:#dcfce7!important',
            '[data-content-format-background-color="basic-blue"]': 'background:#dbeafe!important',
            '[data-content-format-background-color="basic-purple"]': 'background:#f3e8ff!important',
            '[data-content-format-background-color="basic-pink"]': 'background:#fce7f3!important',
            '[data-content-format-background-color="basic-gray"]': 'background:#e2e8f0!important',
            '[data-content-format-text-rgba]': 'color:attr(data-content-format-text-rgba type(<color>),inherit)!important',
            '[data-content-format-background-rgba]': 'background:attr(data-content-format-background-rgba type(<color>),transparent)!important'
        };
        return Object.keys(rules).map(function (selector) {
            return selector + '{' + rules[selector] + '}';
        }).join('');
    }

    function richAdvancedPreviewDocument(
        blockId,
        html,
        css,
        security,
        palette
    ) {
        if (
            !security
            || typeof security.styleNonce !== 'string'
            || typeof security.contentSecurityPolicy !== 'string'
            || security.contentSecurityPolicy
                !== richAdvancedPreviewPolicy(security.styleNonce)
        ) {
            throw new Error('rich-preview-security-invalid');
        }
        // An incomplete V2 draft may deliberately keep an exact empty HTML
        // source while its scoped CSS is already being prepared.
        var parsed = html === '' ? null : richParseAdvancedHtml(html);
        css = richValidateAdvancedCss(css);
        var sanitizedHtml = parsed === null
            ? (html === '' ? '' : escapeHtml(html)) :
            parsed.body.innerHTML;
        var safeCss = String(css).replace(/</gu, '\\3c ');
        var scope = UUID_V4.test(blockId || '') ? blockId : '';
        return '<!doctype html><html><head>'
            + '<meta charset="utf-8">'
            + '<meta http-equiv="Content-Security-Policy" content="'
            + escapeHtml(security.contentSecurityPolicy) + '">'
            + '<meta name="referrer" content="no-referrer">'
            + '<style nonce="' + escapeHtml(security.styleNonce)
            + '">html,body{margin:0;padding:0;background:#fff;color:#272727;}'
            + 'body{font:16px/1.65 system-ui,sans-serif;overflow-wrap:anywhere;}'
            + '[data-ls-blog-custom]{box-sizing:border-box;width:100%;padding:1rem;'
            + 'position:relative!important;isolation:isolate!important;'
            + 'contain:paint!important;overflow:clip!important;'
            + 'box-sizing:border-box!important;max-inline-size:100%!important;}'
            + '[data-ls-blog-custom] :is(h2,h3,h4,h5,h6){'
            + 'margin-block:0;padding-block-end:clamp(1.5rem,3vw,2.25rem);'
            + 'line-height:1.2;}'
            + '[data-ls-blog-custom] :is(ul,ol){'
            + 'max-inline-size:none;margin-inline:0;padding-inline-start:1.25rem;}'
            + '[data-ls-blog-custom] :is(ul,ol) :is(ul,ol){'
            + 'padding-inline-start:1.15rem;}'
            + '[data-ls-blog-custom] blockquote{'
            + 'margin-block:1.25rem;padding-block:.35rem;'
            + 'padding-inline:1.15rem .25rem;border:0;'
            + 'border-inline-start:.22rem solid #24658e;'
            + 'background:transparent;font-size:1.05em;font-style:italic;}'
            + '[data-ls-blog-custom] aside[data-content-callout="true"]{'
            + 'margin-block:1.25rem;padding:1rem 1.25rem;border:0;'
            + 'border-radius:.65rem;background:rgba(36,101,142,.08);}'
            + '[data-ls-blog-custom="' + escapeHtml(scope) + '"]{'
            + safeCss + '}' + richAdvancedPreviewMarkCss(palette)
            + '</style></head><body><div data-ls-blog-custom="'
            + escapeHtml(scope) + '">' + sanitizedHtml
            + '</div></body></html>';
    }

    function richAdvancedPreviewFrame(
        context,
        blockId,
        html,
        css,
        className
    ) {
        var frame = element(
            'iframe',
            className || 'blogEditor__advancedPreview'
        );
        frame.setAttribute('sandbox', '');
        frame.setAttribute('referrerpolicy', 'no-referrer');
        frame.setAttribute('title', 'Vista previa aislada del módulo');
        frame.srcdoc = richAdvancedPreviewDocument(
            blockId,
            html,
            css,
            context.advancedPreviewSecurity,
            richAdvancedPreviewPalette(context.form)
        );
        return frame;
    }

    function richClearAdvancedVisualStyle(state) {
        state.visual.removeAttribute('data-blog-rich-css-scope');
        if (
            state.advancedVisualStyle
            && typeof state.advancedVisualStyle.remove === 'function'
        ) {
            state.advancedVisualStyle.remove();
        }
        state.advancedVisualStyle = null;
        state.advancedVisualStyleSignature = '';
    }

    function richAdvancedVisualScopedCss(state) {
        var scope = state.advancedVisualScope || '';
        if (
            !/^rich-visual-[1-9][0-9]*$/u.test(scope)
            || !richAdvancedCssVisualSafe(
                state.cssDraft,
                richStatePolicy(state)
            )
        ) {
            throw new Error('rich-advanced-css-not-allowed');
        }
        return '[data-blog-rich-css-scope="' + scope
            + '"][data-advanced="editable"].blogEditor__richCanvas{'
            + String(state.cssDraft).replace(/</gu, '\\3c ') + '}';
    }

    function richRefreshAdvancedVisualStyle(state) {
        if (!state.advancedMode || !state.advancedVisualEditable) {
            richClearAdvancedVisualStyle(state);
            return false;
        }
        var stylesheet = richAdvancedVisualScopedCss(state);
        var scope = state.advancedVisualScope;
        state.visual.setAttribute('data-blog-rich-css-scope', scope);
        if (state.cssDraft.trim() === '') {
            var removed = state.advancedVisualStyle !== null;
            if (removed) {
                state.advancedVisualStyle.remove();
                state.advancedVisualStyle = null;
            }
            state.advancedVisualStyleSignature = stylesheet;
            return removed;
        }
        if (
            state.advancedVisualStyleSignature === stylesheet
            && state.advancedVisualStyle
            && state.advancedVisualStyle.isConnected
        ) {
            return false;
        }
        if (!state.advancedVisualStyle) {
            state.advancedVisualStyle = document.createElement('style');
            state.advancedVisualStyle.setAttribute(
                'nonce',
                state.context.advancedPreviewSecurity.styleNonce
            );
            state.advancedVisualStyle.setAttribute(
                'data-blog-rich-visual-style',
                scope
            );
            state.dialog.append(state.advancedVisualStyle);
        }
        state.advancedVisualStyle.textContent = stylesheet;
        state.advancedVisualStyleSignature = stylesheet;
        return true;
    }

    function richRenderAdvancedDraft(state) {
        richClearAdvancedVisualStyle(state);
        state.visual.replaceChildren(richAdvancedPreviewFrame(
            state.context,
            state.block ? state.block.id : '',
            state.advancedHtmlDraft,
            state.cssDraft,
            'blogEditor__advancedPreview blogEditor__advancedPreview--modal'
        ));
        state.visual.contentEditable = 'false';
        state.visual.dataset.advanced = 'true';
        richSetDatasetValue(
            state.visual,
            'empty',
            state.advancedHtmlDraft.trim() === '' ? 'true' : 'false'
        );
        state.toolbar.hidden = true;
        state.linkPanel.hidden = true;
        richUpdateLimitFeedback(state);
    }

    function richSourceHasStandardParagraphStructure(state, source) {
        if (!state.textFlowMode || state.legacyListMode || state.headingMode) {
            return false;
        }
        try {
            richParseTextFlowHtml(source);
            return true;
        } catch (error) {
            return false;
        }
    }

    function richSourceUsesStandardParagraph(state, source, css) {
        var cssValue = typeof css === 'string'
            ? css
            : (typeof state.cssDraft === 'string' ? state.cssDraft : '');
        return richSourceHasStandardParagraphStructure(state, source)
            && richAdvancedCssVisualSafe(cssValue);
    }

    function richSourceSupportsAdvancedVisualFlow(state, source, css) {
        if (!state.textFlowMode || state.legacyListMode || state.headingMode) {
            return false;
        }
        var cssValue = typeof css === 'string'
            ? css
            : (typeof state.cssDraft === 'string' ? state.cssDraft : '');
        if (!richAdvancedCssVisualSafe(cssValue)) {
            return false;
        }
        try {
            richParseAdvancedVisualFlowHtml(source);
            return true;
        } catch (error) {
            return false;
        }
    }

    function richResetAdvancedTouchState(state) {
        state.sourceTouched = false;
        state.cssTouched = false;
        richResetAdvancedVisualBaseline(state);
        if (state.source && typeof state.source.value === 'string') {
            state.sourceBaseline = state.source.value;
        }
        if (state.cssSource && typeof state.cssSource.value === 'string') {
            state.cssBaseline = state.cssSource.value;
        }
        if (state.sourceHistory) {
            richResetCodeHistory(state.sourceHistory, state.source);
        }
        if (state.cssHistory) {
            richResetCodeHistory(state.cssHistory, state.cssSource);
        }
    }

    function richCommitAdvancedEditors(state) {
        var policy = richStatePolicy(state);
        var css = richValidateAdvancedCss(state.cssSource.value, policy);
        var source = state.wasAdvanced && !state.sourceTouched
            ? state.advancedHtmlDraft
            : state.source.value;
        if (state.sourceOnlyMode) {
            if (!validEmbedHtml(source, policy)) {
                throw new Error('rich-advanced-html-not-allowed');
            }
            state.advancedMode = true;
            state.advancedHtmlDraft = source;
            state.cssDraft = css;
            state.advancedVisualEditable = false;
            state.advancedVisualStructureLocked = true;
            state.flowDraft = [];
            richResetAdvancedTouchState(state);
            return;
        }
        var standardConvertible = richSourceUsesStandardParagraph(
            state,
            source,
            css
        );
        var visualEditable = richSourceSupportsAdvancedVisualFlow(
            state,
            source,
            css
        );
        var keepAdvanced = css.trim() !== ''
            || !standardConvertible
            || (
                state.wasAdvanced
                && !state.sourceTouched
                && !state.cssTouched
            );
        if (!keepAdvanced) {
            state.flowDraft = richParseTextFlowHtml(source);
            state.advancedMode = false;
            state.advancedVisualEditable = false;
            state.advancedVisualStructureLocked = false;
            state.wasAdvanced = false;
            state.advancedHtmlDraft = '';
            state.cssDraft = '';
            richResetAdvancedTouchState(state);
            return;
        }
        if (!validAdvancedHtml(source, true)) {
            throw new Error('rich-advanced-html-not-allowed');
        }
        state.advancedMode = true;
        state.advancedHtmlDraft = source;
        state.cssDraft = css;
        state.advancedVisualEditable = visualEditable;
        state.advancedVisualStructureLocked = visualEditable
            && richAdvancedVisualStructureLocked(source);
        state.flowDraft = visualEditable
            ? richParseAdvancedVisualFlowHtml(source)
            : [];
        richResetAdvancedTouchState(state);
    }

    function richInsertPlainText(state, value) {
        var selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) {
            return;
        }
        var range = selection.getRangeAt(0);
        if (!state.visual.contains(range.commonAncestorContainer)) {
            return;
        }
        if (
            !/(?:\r\n?|\n)/u.test(value)
            && richReplaceRootBreakWithParagraph(state, value, selection)
        ) {
            return;
        }
        var rangeElement = range.commonAncestorContainer.nodeType
            === Node.ELEMENT_NODE
            ? range.commonAncestorContainer
            : range.commonAncestorContainer.parentElement;
        if (
            rangeElement instanceof Element
            && rangeElement.closest('a')
            && /(?:\r\n?|\n)/u.test(value)
        ) {
            value = value.replace(/(?:\r\n?|\n)+/gu, ' ');
        }
        range.deleteContents();
        var fragment = document.createDocumentFragment();
        value.split(/(\r\n?|\n)/u).forEach(function (piece) {
            if (/^(?:\r\n?|\n)$/u.test(piece)) {
                if (state.allowBreak) {
                    fragment.append(document.createElement('br'));
                } else {
                    fragment.append(document.createTextNode(' '));
                }
            } else if (piece !== '') {
                fragment.append(document.createTextNode(piece));
            }
        });
        var last = fragment.lastChild;
        range.insertNode(fragment);
        if (last) {
            range.setStartAfter(last);
            range.collapse(true);
            selection.removeAllRanges();
            selection.addRange(range);
        }
    }

    function richPlacePendingFlowCaret(state, paragraph, selection) {
        if (
            !(paragraph instanceof HTMLElement)
            || paragraph.nodeName.toLowerCase() !== 'p'
        ) {
            return false;
        }
        Array.from(paragraph.childNodes).forEach(function (child) {
            if (
                child.nodeType === Node.TEXT_NODE
                && child.nodeValue === ''
            ) {
                paragraph.removeChild(child);
            }
        });
        if (paragraph.childNodes.length === 0) {
            var caretFiller = document.createElement('br');
            caretFiller.setAttribute(
                'data-blog-rich-caret-filler',
                'true'
            );
            paragraph.append(caretFiller);
        }
        if (!richPendingCaretParagraph(paragraph)) {
            return false;
        }
        var fillerIndex = Array.prototype.indexOf.call(
            paragraph.childNodes,
            paragraph.lastChild
        );
        var caret = document.createRange();
        caret.setStart(paragraph, fillerIndex);
        caret.collapse(true);
        selection.removeAllRanges();
        selection.addRange(caret);
        state.caretExitParagraph = paragraph;
        return true;
    }

    function richNodeHasCaretContent(node) {
        if (node.nodeType === Node.TEXT_NODE) {
            return (node.nodeValue || '') !== '';
        }
        if (node.nodeType !== Node.ELEMENT_NODE) {
            return false;
        }
        if (node.nodeName.toLowerCase() === 'br') {
            return true;
        }
        return Array.from(node.childNodes).some(richNodeHasCaretContent);
    }

    function richCaretStartsFlowBlock(range, current) {
        if (
            range.startContainer !== range.endContainer
            || range.startOffset !== range.endOffset
            || !current.contains(range.startContainer)
        ) {
            return false;
        }
        var cursor = range.startContainer;
        if (cursor.nodeType === Node.TEXT_NODE) {
            if ((cursor.nodeValue || '').slice(0, range.startOffset) !== '') {
                return false;
            }
        } else if (cursor.nodeType === Node.ELEMENT_NODE) {
            if (Array.from(cursor.childNodes)
                .slice(0, range.startOffset)
                .some(richNodeHasCaretContent)) {
                return false;
            }
        } else {
            return false;
        }
        while (cursor !== current) {
            var parent = cursor.parentNode;
            if (!parent || !current.contains(parent)) {
                return false;
            }
            var index = Array.prototype.indexOf.call(
                parent.childNodes,
                cursor
            );
            if (
                index < 0
                || Array.from(parent.childNodes)
                    .slice(0, index)
                    .some(richNodeHasCaretContent)
            ) {
                return false;
            }
            cursor = parent;
        }
        return true;
    }

    function richCaretEndsFlowBlock(range, current) {
        if (
            range.startContainer !== range.endContainer
            || range.startOffset !== range.endOffset
            || !current.contains(range.startContainer)
        ) {
            return false;
        }
        var cursor = range.startContainer;
        if (cursor.nodeType === Node.TEXT_NODE) {
            if ((cursor.nodeValue || '').slice(range.startOffset) !== '') {
                return false;
            }
        } else if (cursor.nodeType === Node.ELEMENT_NODE) {
            if (Array.from(cursor.childNodes).slice(range.startOffset)
                .some(richNodeHasCaretContent)) {
                return false;
            }
        } else {
            return false;
        }
        while (cursor !== current) {
            var parent = cursor.parentNode;
            if (!parent || !current.contains(parent)) {
                return false;
            }
            var index = Array.prototype.indexOf.call(
                parent.childNodes,
                cursor
            );
            if (
                index < 0
                || Array.from(parent.childNodes).slice(index + 1)
                    .some(richNodeHasCaretContent)
            ) {
                return false;
            }
            cursor = parent;
        }
        return true;
    }

    function richRootBreakAtSelection(state, selection) {
        var selected = selection || window.getSelection();
        if (!selected || selected.rangeCount === 0) {
            return null;
        }
        var range = selected.getRangeAt(0);
        if (
            range.startContainer !== state.visual
            || range.endContainer !== state.visual
            || range.startOffset !== range.endOffset
        ) {
            return null;
        }
        var candidate = state.visual.childNodes[range.startOffset] || null;
        if (
            !candidate
            || candidate.nodeType !== Node.ELEMENT_NODE
            || candidate.nodeName.toLowerCase() !== 'br'
        ) {
            candidate = state.visual.childNodes[range.startOffset - 1] || null;
        }
        return candidate
            && candidate.nodeType === Node.ELEMENT_NODE
            && candidate.nodeName.toLowerCase() === 'br'
            ? candidate
            : null;
    }

    function richPlaceRootBreakCaret(state, rootBreak, selection) {
        if (
            !(rootBreak instanceof HTMLElement)
            || rootBreak.nodeName.toLowerCase() !== 'br'
            || rootBreak.parentElement !== state.visual
        ) {
            return false;
        }
        var index = Array.prototype.indexOf.call(
            state.visual.childNodes,
            rootBreak
        );
        if (index < 0) {
            return false;
        }
        var caret = document.createRange();
        caret.setStart(state.visual, index);
        caret.collapse(true);
        selection.removeAllRanges();
        selection.addRange(caret);
        state.caretExitParagraph = null;
        return true;
    }

    function richReplaceRootBreakWithParagraph(state, value, selection) {
        var selected = selection || window.getSelection();
        var rootBreak = richRootBreakAtSelection(state, selected);
        if (!(rootBreak instanceof HTMLElement)) {
            return false;
        }
        var paragraph = document.createElement('p');
        if (value !== '') {
            paragraph.append(document.createTextNode(String(value)));
        }
        rootBreak.replaceWith(paragraph);
        var caret = document.createRange();
        caret.selectNodeContents(paragraph);
        caret.collapse(false);
        selected.removeAllRanges();
        selected.addRange(caret);
        state.caretExitParagraph = null;
        return true;
    }

    function richInsertRootBreak(state, reference, after, selection) {
        var rootBreak = document.createElement('br');
        if (after) {
            reference.after(rootBreak);
        } else {
            reference.before(rootBreak);
        }
        return richPlaceRootBreakCaret(state, rootBreak, selection);
    }

    function richInsertFlowParagraph(state) {
        var selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) {
            return false;
        }
        var range = selection.getRangeAt(0);
        var selectedRootBreak = richRootBreakAtSelection(state, selection);
        if (selectedRootBreak instanceof HTMLElement) {
            return richInsertRootBreak(
                state,
                selectedRootBreak,
                true,
                selection
            );
        }
        var anchor = range.startContainer.nodeType === Node.ELEMENT_NODE
            ? range.startContainer
            : range.startContainer.parentElement;
        var current = anchor instanceof Element
            ? anchor.closest(RICH_TEXT_FLOW_BLOCK_SELECTOR)
            : null;
        var endAnchor = range.endContainer.nodeType === Node.ELEMENT_NODE
            ? range.endContainer
            : range.endContainer.parentElement;
        var endCurrent = endAnchor instanceof Element
            ? endAnchor.closest(RICH_TEXT_FLOW_BLOCK_SELECTOR)
            : null;
        if (
            !(current instanceof HTMLElement)
            || current !== endCurrent
            || !state.visual.contains(current)
        ) {
            return false;
        }
        if (
            current === state.caretExitParagraph
            && richPendingCaretParagraph(current)
        ) {
            var pendingBreak = document.createElement('br');
            current.replaceWith(pendingBreak);
            state.caretExitParagraph = null;
            return richInsertRootBreak(
                state,
                pendingBreak,
                true,
                selection
            );
        }
        if (
            current.parentElement === state.visual
            && current.nodeName.toLowerCase() !== 'li'
            && richCaretStartsFlowBlock(range, current)
        ) {
            if (state.caretExitParagraph !== null) {
                richDiscardEmptyCaretExit(state);
            }
            return richInsertRootBreak(
                state,
                current,
                false,
                selection
            );
        }
        if (state.caretExitParagraph !== null) {
            richDiscardEmptyCaretExit(state);
        }
        var tag = current.nodeName.toLowerCase();
        var currentList = tag === 'li' ? current.closest('ul,ol') : null;
        var currentIsLastListItem = currentList instanceof HTMLElement
            && currentList.lastElementChild === current;
        if (
            tag === 'li'
            && currentIsLastListItem
            && current.textContent.trim() === ''
        ) {
            var list = currentList;
            if (!(list instanceof HTMLElement)) {
                return false;
            }
            var breakAfterList = document.createElement('br');
            list.after(breakAfterList);
            current.remove();
            if (list.querySelector('li') === null) {
                list.remove();
            }
            return richPlaceRootBreakCaret(
                state,
                breakAfterList,
                selection
            );
        }
        if (
            current.parentElement === state.visual
            && tag !== 'li'
            && richCaretEndsFlowBlock(range, current)
        ) {
            return richInsertRootBreak(state, current, true, selection);
        }
        range.deleteContents();
        var tail = document.createRange();
        tail.setStart(range.startContainer, range.startOffset);
        tail.setEnd(current, current.childNodes.length);
        var trailing = tail.extractContents();
        var next = document.createElement(tag === 'li' ? 'li' : 'p');
        next.append(trailing);
        current.after(next);
        if (
            next.nodeName.toLowerCase() === 'p'
            && !richNodeHasCaretContent(next)
        ) {
            var rootBreak = document.createElement('br');
            next.replaceWith(rootBreak);
            return richPlaceRootBreakCaret(state, rootBreak, selection);
        }
        var caret = document.createRange();
        caret.selectNodeContents(next);
        caret.collapse(true);
        selection.removeAllRanges();
        selection.addRange(caret);
        return true;
    }

    function richCaretExitParagraphHasContent(paragraph) {
        if (richPendingCaretParagraph(paragraph)) {
            return false;
        }
        if (paragraph.textContent.trim() !== '') {
            return true;
        }
        return Array.from(paragraph.childNodes).some(function (child) {
            return child.nodeType === Node.ELEMENT_NODE
                && child.nodeName.toLowerCase() === 'br'
                && !richCaretFillerNode(child);
        });
    }

    function richDiscardCaretExitOutsideSelection(state) {
        var paragraph = state.caretExitParagraph;
        if (!(paragraph instanceof HTMLElement)) {
            return false;
        }
        var selection = window.getSelection();
        if (selection && selection.rangeCount > 0) {
            var range = selection.getRangeAt(0);
            if (
                paragraph.contains(range.startContainer)
                && paragraph.contains(range.endContainer)
            ) {
                return false;
            }
        }
        return richDiscardEmptyCaretExit(state);
    }

    function richResolveCaretExitAfterInput(state) {
        var paragraph = state.caretExitParagraph;
        if (
            !(paragraph instanceof HTMLElement)
            || !state.visual.contains(paragraph)
        ) {
            state.caretExitParagraph = null;
            return false;
        }
        if (!richCaretExitParagraphHasContent(paragraph)) {
            return false;
        }
        var filler = paragraph.querySelector(
            'br[data-blog-rich-caret-filler="true"]'
        );
        if (filler) {
            filler.remove();
        }
        state.caretExitParagraph = null;
        return true;
    }

    function richDiscardEmptyCaretExit(state) {
        var paragraph = state.caretExitParagraph;
        if (
            !(paragraph instanceof HTMLElement)
            || !state.visual.contains(paragraph)
        ) {
            state.caretExitParagraph = null;
            return false;
        }
        if (richCaretExitParagraphHasContent(paragraph)) {
            richResolveCaretExitAfterInput(state);
            return false;
        }
        paragraph.remove();
        state.caretExitParagraph = null;
        return true;
    }

    function richPlaceFlowLineBreakCaret(state, current, selection) {
        var range = selection.getRangeAt(0);
        if (range.startContainer.nodeType !== Node.ELEMENT_NODE) {
            return false;
        }
        var parent = range.startContainer;
        var previous = parent.childNodes[range.startOffset - 1] || null;
        if (
            !previous
            || previous.nodeType !== Node.ELEMENT_NODE
            || previous.nodeName.toLowerCase() !== 'br'
            || richCaretFillerNode(previous)
        ) {
            return false;
        }
        var caretFiller = current.querySelector(
            'br[data-blog-rich-caret-filler="true"]'
        );
        if (!caretFiller) {
            caretFiller = document.createElement('br');
            caretFiller.setAttribute(
                'data-blog-rich-caret-filler',
                'true'
            );
            range.insertNode(caretFiller);
        }
        var fillerParent = caretFiller.parentNode;
        if (!fillerParent) {
            return false;
        }
        var fillerIndex = Array.prototype.indexOf.call(
            fillerParent.childNodes,
            caretFiller
        );
        if (fillerIndex < 0) {
            return false;
        }
        var caret = document.createRange();
        caret.setStart(fillerParent, fillerIndex);
        caret.collapse(true);
        selection.removeAllRanges();
        selection.addRange(caret);
        state.caretExitParagraph = current;
        return true;
    }

    function richInsertFlowLineBreak(state) {
        var selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) {
            return false;
        }
        var range = selection.getRangeAt(0);
        var anchor = range.startContainer.nodeType === Node.ELEMENT_NODE
            ? range.startContainer
            : range.startContainer.parentElement;
        var current = anchor instanceof Element
            ? anchor.closest(RICH_TEXT_FLOW_BLOCK_SELECTOR)
            : null;
        if (!(current instanceof HTMLElement) || !state.visual.contains(current)) {
            return false;
        }
        if (
            state.caretExitParagraph !== null
            && current !== state.caretExitParagraph
        ) {
            richDiscardEmptyCaretExit(state);
        }
        richInsertPlainText(state, '\n');
        richPlaceFlowLineBreakCaret(state, current, selection);
        return true;
    }

    function richAdvancedVisualCanSplitSelection(state) {
        if (
            !state.advancedMode
            || !state.advancedVisualEditable
            || !state.advancedVisualStructureLocked
        ) {
            return true;
        }
        var selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) {
            return false;
        }
        if (richRootBreakAtSelection(state, selection) !== null) {
            return true;
        }
        var range = selection.getRangeAt(0);
        var start = range.startContainer.nodeType === Node.ELEMENT_NODE
            ? range.startContainer
            : range.startContainer.parentElement;
        var end = range.endContainer.nodeType === Node.ELEMENT_NODE
            ? range.endContainer
            : range.endContainer.parentElement;
        var startBlock = start instanceof Element
            ? start.closest(RICH_TEXT_FLOW_BLOCK_SELECTOR)
            : null;
        var endBlock = end instanceof Element
            ? end.closest(RICH_TEXT_FLOW_BLOCK_SELECTOR)
            : null;
        if (
            !(startBlock instanceof HTMLElement)
            || startBlock !== endBlock
            || !state.visual.contains(startBlock)
        ) {
            return false;
        }
        if (startBlock.nodeName.toLowerCase() !== 'li') {
            return richFlowTypeForTag(startBlock.nodeName.toLowerCase())
                !== null;
        }
        var list = startBlock.closest('ul,ol');
        return list instanceof HTMLElement
            && list.parentElement === state.visual
            && Array.from(list.children).every(function (item) {
                return item.nodeName.toLowerCase() === 'li';
            });
    }

    function richInsertFlowText(state, value) {
        var parts = String(value || '').replace(/\r\n?/gu, '\n').split('\n');
        parts.forEach(function (part, index) {
            if (index > 0 && !richInsertFlowParagraph(state)) {
                richInsertPlainText(state, '\n');
            }
            if (part !== '') {
                richInsertPlainText(state, part);
            }
        });
    }

    function richSeedAdvancedVisualText(state, value) {
        if (
            !state.advancedMode
            || !state.advancedVisualEditable
            || !state.textFlowMode
            || state.advancedHtmlDraft.trim() !== ''
            || typeof value !== 'string'
            || value === ''
            || state.flowDraft.length !== 1
            || state.flowDraft[0].type !== 'paragraph'
            || state.flowDraft[0].content.length !== 0
        ) {
            return false;
        }
        state.flowDraft = [{
            type: 'paragraph',
            content: [{ type: 'text', text: value, marks: [] }]
        }];
        return true;
    }

    function richPlaceEmptyFlowCaret(state) {
        if (
            !state.textFlowMode
            || inlinePlainText(state.flowDraft).trim() !== ''
        ) {
            return false;
        }
        var first = state.visual.querySelector('p, li, br');
        if (!(first instanceof HTMLElement)) {
            return false;
        }
        var selection = window.getSelection();
        if (!selection) {
            return false;
        }
        if (
            first.nodeName.toLowerCase() === 'br'
            && first.parentElement === state.visual
        ) {
            return richPlaceRootBreakCaret(state, first, selection);
        }
        var range = document.createRange();
        range.selectNodeContents(first);
        range.collapse(true);
        selection.removeAllRanges();
        selection.addRange(range);
        return true;
    }

    function richSetTextContent(node, value) {
        if (!node) {
            return false;
        }
        var next = value === null || value === undefined ? '' : String(value);
        if (node.textContent === next) {
            return false;
        }
        node.textContent = next;
        return true;
    }

    function richSetDomProperty(node, property, value) {
        if (!node) {
            return false;
        }
        if (node[property] === value) {
            return false;
        }
        node[property] = value;
        return true;
    }

    function richSetAttributeValue(node, attribute, value) {
        if (!node) {
            return false;
        }
        var next = String(value);
        if (
            typeof node.getAttribute === 'function'
            && node.getAttribute(attribute) === next
        ) {
            return false;
        }
        if (typeof node.setAttribute === 'function') {
            node.setAttribute(attribute, next);
        } else {
            node[attribute] = next;
        }
        return true;
    }

    function richRemoveAttributeValue(node, attribute) {
        if (!node) {
            return false;
        }
        if (
            typeof node.hasAttribute === 'function'
            && !node.hasAttribute(attribute)
        ) {
            return false;
        }
        if (typeof node.removeAttribute === 'function') {
            node.removeAttribute(attribute);
        } else if (Object.prototype.hasOwnProperty.call(node, attribute)) {
            delete node[attribute];
        } else {
            return false;
        }
        return true;
    }

    function richSetDatasetValue(node, property, value) {
        if (!node || !node.dataset) {
            return false;
        }
        var next = String(value);
        if (node.dataset[property] === next) {
            return false;
        }
        node.dataset[property] = next;
        return true;
    }

    function richSetStyleProperty(node, property, value) {
        if (!node || !node.style) {
            return false;
        }
        var next = value === null || value === undefined ? '' : String(value);
        if (node.style.getPropertyValue(property) === next) {
            return false;
        }
        if (next === '') {
            node.style.removeProperty(property);
        } else {
            node.style.setProperty(property, next);
        }
        return true;
    }

    function richStatus(state, message, error, validationSource) {
        richSetTextContent(state.modalStatus, message || '');
        richSetDatasetValue(
            state.modalStatus,
            'state',
            error ? 'error' : 'ok'
        );
        if (error && ['html', 'css'].includes(validationSource)) {
            richSetDatasetValue(
                state.modalStatus,
                'validationSource',
                validationSource
            );
        } else {
            richRemoveAttributeValue(
                state.modalStatus,
                'data-validation-source'
            );
        }
    }

    function richAdvancedValidationSource(error, fallbackSource) {
        var code = error && typeof error.message === 'string'
            ? error.message : '';
        if (code === 'rich-advanced-css-not-allowed') {
            return 'css';
        }
        if (code === 'rich-advanced-html-not-allowed') {
            return 'html';
        }
        return fallbackSource === 'css' ? 'css' : 'html';
    }

    function richReportAdvancedValidationFailure(
        state,
        error,
        fallbackSource
    ) {
        var source = richAdvancedValidationSource(error, fallbackSource);
        richStatus(
            state,
            source === 'css'
                ? 'El CSS contiene sintaxis o valores no permitidos.'
                : 'El HTML contiene etiquetas, atributos o valores no permitidos.',
            true,
            source
        );
    }

    function richRefreshAdvancedValidationStatus(state, source) {
        if (
            !state.modalStatus
            || state.modalStatus.dataset.validationSource !== source
        ) {
            return;
        }
        var valid = source === 'css'
            ? validAdvancedCss(state.cssSource.value, richStatePolicy(state))
            : (state.sourceOnlyMode
                ? validEmbedHtml(state.source.value, richStatePolicy(state))
                : validAdvancedHtml(
                    state.source.value,
                    true,
                    richStatePolicy(state)
                ));
        if (valid) {
            richStatus(state, '', false);
        }
    }

    function richSvgIcon(name) {
        var namespace = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(namespace, 'svg');
        svg.classList.add('blogEditor__richToolIcon');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '1.8');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        var paths = {
            bold: ['M8 4h5a4 4 0 0 1 0 8H8z', 'M8 12h6a4 4 0 0 1 0 8H8z'],
            italic: ['M14 4h4', 'M6 20h4', 'M15 4 9 20'],
            underline: ['M7 4v6a5 5 0 0 0 10 0V4', 'M5 21h14'],
            'format-size': ['M11 4v3h3v13h4V7h4V4z', 'M2 10v2h2v8h3v-8h2v-2z'],
            unordered: ['M9 6h11', 'M9 12h11', 'M9 18h11', 'M4 6h.01', 'M4 12h.01', 'M4 18h.01'],
            ordered: ['M10 6h10', 'M10 12h10', 'M10 18h10', 'M4 5h2v4', 'M4 13h2l-2 3h2', 'M4 19h2v2H4'],
            'text-color': ['m7 17 5-12 5 12', 'M9 13h6', 'M5 21h14'],
            background: ['m12 3 7 7-9 9-5-5z', 'M4 21h16'],
            palette: ['M12 3a9 9 0 1 0 0 18h1.5a2 2 0 0 0 0-4H12a2 2 0 0 1 0-4h2a7 7 0 0 0-2-5Z', 'M7.5 10h.01', 'M10 6.5h.01', 'M15 7h.01', 'M17 11h.01'],
            link: ['M10 13a4 4 0 0 0 5.7.3l2-2a4 4 0 0 0-5.7-5.7l-1.1 1.1', 'M14 11a4 4 0 0 0-5.7-.3l-2 2A4 4 0 0 0 12 18.4l1.1-1.1'],
            unlink: ['m4 4 16 16', 'M10 13a4 4 0 0 0 4.7.7', 'm15.7 13.3 2-2a4 4 0 0 0-5.7-5.7l-1.1 1.1', 'M8.3 10.7l-2 2A4 4 0 0 0 12 18.4l1.1-1.1'],
            clear: ['m5 15 8-8 6 6-6 6H9z', 'M4 21h16'],
            paragraph: ['M13 5v14', 'M17 5v14', 'M13 5H9a4 4 0 0 0 0 8h4'],
            quote: ['M5 17h4l2-5V6H5v6h3', 'M13 17h4l2-5V6h-6v6h3'],
            callout: ['M4 5h16v12H9l-5 3z', 'M12 8v4', 'M12 15h.01'],
            chevron: ['m8 10 4 4 4-4'],
            'align-start': ['M4 6h16', 'M4 10h10', 'M4 14h16', 'M4 18h10'],
            'align-center': ['M4 6h16', 'M7 10h10', 'M4 14h16', 'M7 18h10'],
            'align-end': ['M4 6h16', 'M10 10h10', 'M4 14h16', 'M10 18h10'],
            'align-justify': ['M4 6h16', 'M4 10h16', 'M4 14h16', 'M4 18h16']
        };
        (paths[name] || paths.clear).forEach(function (definition) {
            var path = document.createElementNS(namespace, 'path');
            path.setAttribute('d', definition);
            svg.append(path);
        });
        return svg;
    }

    function richToolbarButton(label, action, shortLabel) {
        var button = element(
            'button',
            'blogEditor__richTool',
            shortLabel || label
        );
        button.type = 'button';
        button.dataset.blogRichAction = action;
        button.setAttribute('aria-label', label);
        tooltip(button, label);
        return button;
    }

    function richToolbarIconButton(label, action, icon) {
        var button = richToolbarButton(label, action, '');
        button.classList.add('blogEditor__richTool--icon');
        button.replaceChildren(richSvgIcon(icon));
        tooltip(button, label);
        return button;
    }

    function richToolbarSelect(context, labelText, group, options, icon) {
        var label = element(
            'label',
            'blogEditor__richSelect blogEditor__richSelect--icon'
        );
        label.append(
            element('span', 'webadmin-srOnly', labelText),
            richSvgIcon(icon)
        );
        tooltip(label, labelText);
        var select = document.createElement('select');
        select.id = 'blog-rich-' + group + '-' + context.instance;
        select.dataset.blogRichFormat = group;
        select.dataset.blogRichRequiresSelection = 'true';
        select.setAttribute('aria-label', labelText);
        var mixed = document.createElement('option');
        mixed.value = '__mixed';
        mixed.textContent = 'Varios formatos';
        mixed.disabled = true;
        select.append(mixed);
        options.forEach(function (definition) {
            var option = document.createElement('option');
            option.value = definition.value;
            option.textContent = definition.label;
            select.append(option);
        });
        label.append(select);
        return { label: label, select: select };
    }

    function richToolbarBlockTypePicker(context, definitions) {
        var root = element('div', 'blogEditor__richBlockType');
        root.hidden = true;

        var trigger = richToolbarButton(
            'Tipo de bloque',
            'toggle-block-type',
            ''
        );
        trigger.className = 'blogEditor__richBlockTypeTrigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.replaceChildren();
        var triggerValue = element(
            'span',
            'blogEditor__richBlockTypeValue',
            'P'
        );
        trigger.append(
            richSvgIcon('paragraph'),
            triggerValue,
            richSvgIcon('chevron')
        );
        tooltip(trigger, 'Tipo de bloque');

        var menu = element('div', 'blogEditor__richBlockTypeMenu');
        menu.id = 'blog-rich-block-type-' + context.instance;
        menu.hidden = true;
        menu.setAttribute('role', 'listbox');
        menu.setAttribute('aria-label', 'Tipo de bloque');
        trigger.setAttribute('aria-controls', menu.id);

        var options = {};
        definitions.forEach(function (definition) {
            var option = richToolbarButton(
                definition.label,
                'block-type-value',
                ''
            );
            option.className = 'blogEditor__richTool '
                + 'blogEditor__richBlockTypeOption';
            option.id = menu.id + '-' + definition.value;
            option.dataset.blogRichBlockTypeValue = definition.value;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.tabIndex = -1;
            option.replaceChildren(
                element(
                    'span',
                    'blogEditor__richBlockTypeSample',
                    definition.shortLabel
                ),
                element(
                    'span',
                    'blogEditor__richBlockTypeLabel',
                    definition.label
                )
            );
            menu.append(option);
            options[definition.value] = option;
        });
        root.append(trigger, menu);
        return {
            root: root,
            trigger: trigger,
            triggerValue: triggerValue,
            menu: menu,
            options: options,
            definitions: definitions
        };
    }

    function richColorSwatch(value) {
        var swatch = element('span', 'blogEditor__richSwatch');
        richSetColorSwatch(swatch, value);
        swatch.setAttribute('aria-hidden', 'true');
        return swatch;
    }

    function richSetColorSwatch(swatch, value) {
        var mark = typeof value === 'string' ? value : '';
        var dynamic = canonicalDynamicRichMark(mark);
        if (dynamic !== null) {
            var rgba = dynamic.slice(dynamic.indexOf(':') + 1);
            richSetDatasetValue(swatch, 'blogRichSwatch', 'rgba');
            richSetStyleProperty(swatch, '--blog-rich-swatch', rgba);
            return;
        }
        richSetDatasetValue(swatch, 'blogRichSwatch', mark || 'none');
        richSetStyleProperty(swatch, '--blog-rich-swatch', '');
    }

    function richRgbaControl(context, labelText, group) {
        var root = element('div', 'blogEditor__richRgbaControl');
        root.append(element('span', '', labelText));
        var color = document.createElement('input');
        color.type = 'color';
        color.value = '#ffffff';
        color.setAttribute('aria-label', labelText + ': color');
        var alpha = document.createElement('input');
        alpha.type = 'number';
        alpha.min = '0';
        alpha.max = '1';
        alpha.step = '0.05';
        alpha.value = '1';
        alpha.setAttribute('aria-label', labelText + ': opacidad');
        var apply = richToolbarButton(
            'Aplicar ' + labelText.toLowerCase() + ' RGBA a la selecci\u00f3n',
            'apply-rgba',
            'Aplicar RGBA'
        );
        apply.dataset.blogRichPalette = group;
        apply.dataset.blogRichRequiresSelection = 'true';
        root.hidden = true;
        root.append(color, alpha, apply);
        return {
            root: root,
            color: color,
            alpha: alpha,
            apply: apply
        };
    }

    function richToolbarPalette(context, labelText, group, options) {
        var palette = element('div', 'blogEditor__richPalette');
        palette.dataset.blogRichPalette = group;
        palette.append(element(
            'span',
            'blogEditor__richPaletteLabel webadmin-srOnly',
            labelText
        ));

        var trigger = richToolbarButton(
            'Elegir ' + labelText.toLowerCase(),
            'toggle-palette',
            ''
        );
        trigger.className = 'blogEditor__richPaletteTrigger';
        trigger.dataset.blogRichPalette = group;
        trigger.dataset.blogRichRequiresSelection = 'true';
        trigger.setAttribute('aria-haspopup', 'dialog');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.replaceChildren();

        var triggerSwatch = richColorSwatch('none');
        var triggerValue = element(
            'span',
            'blogEditor__richPaletteValue webadmin-srOnly',
            options[0].label
        );
        trigger.append(
            richSvgIcon(group === 'color' ? 'text-color' : 'background'),
            triggerSwatch,
            triggerValue,
            richSvgIcon('chevron')
        );
        tooltip(trigger, 'Elegir ' + labelText.toLowerCase());

        var menu = element('div', 'blogEditor__richPaletteMenu');
        menu.id = 'blog-rich-palette-' + group + '-' + context.instance;
        menu.hidden = true;
        menu.setAttribute('role', 'dialog');
        menu.setAttribute('aria-label', labelText);
        trigger.setAttribute('aria-controls', menu.id);
        options.forEach(function (definition, optionIndex) {
            if (optionIndex === 1 || optionIndex === 7) {
                var separator = element(
                    'span',
                    'blogEditor__richPaletteSeparator'
                );
                separator.setAttribute('role', 'separator');
                separator.setAttribute('aria-hidden', 'true');
                menu.append(separator);
            }
            var option = richToolbarButton(
                definition.label,
                'palette-value',
                ''
            );
            option.className = 'blogEditor__richPaletteOption';
            option.dataset.blogRichPalette = group;
            option.dataset.blogRichPaletteValue = definition.value;
            option.dataset.blogRichPaletteKind = optionIndex === 0
                ? 'reset'
                : (optionIndex < 7 ? 'theme' : 'common');
            option.dataset.blogRichRequiresSelection = 'true';
            option.setAttribute('aria-pressed', 'false');
            option.replaceChildren();
            option.append(richColorSwatch(definition.value));
            tooltip(option, definition.label);
            menu.append(option);
        });
        palette.append(trigger, menu);
        return {
            root: palette,
            trigger: trigger,
            triggerSwatch: triggerSwatch,
            triggerValue: triggerValue,
            menu: menu,
            options: options
        };
    }

    function richLabeledControl(labelText, control) {
        var label = element('label', 'blogEditor__richField');
        label.append(element('span', '', labelText), control);
        return label;
    }

    function richHtmlSourceTokenRanges(value) {
        var source = String(value);
        var ranges = [];
        var index = 0;
        while (index < source.length) {
            if (source.startsWith('<!--', index)) {
                var commentEnd = source.indexOf('-->', index + 4);
                var commentLimit = commentEnd < 0
                    ? source.length
                    : commentEnd + 3;
                ranges.push({
                    start: index,
                    end: commentLimit,
                    type: 'comment'
                });
                index = commentLimit;
                continue;
            }
            if (source.charAt(index) !== '<') {
                index += 1;
                continue;
            }
            var nameIndex = index + 1;
            if (source.charAt(nameIndex) === '/') {
                nameIndex += 1;
            }
            while (/\s/u.test(source.charAt(nameIndex))) {
                nameIndex += 1;
            }
            if (!/[a-z]/iu.test(source.charAt(nameIndex))) {
                index += 1;
                continue;
            }
            var quote = '';
            var end = nameIndex + 1;
            while (end < source.length) {
                var current = source.charAt(end);
                if (quote !== '') {
                    if (current === quote) {
                        quote = '';
                    }
                    end += 1;
                    continue;
                }
                if (current === '"' || current === "'") {
                    quote = current;
                    end += 1;
                    continue;
                }
                if (current === '>') {
                    ranges.push({
                        start: index,
                        end: end + 1,
                        type: 'tag'
                    });
                    index = end + 1;
                    break;
                }
                if (current === '<') {
                    index += 1;
                    break;
                }
                end += 1;
            }
            if (end >= source.length) {
                index += 1;
            }
        }
        return ranges;
    }

    function richHighlightHtmlSource(value) {
        value = String(value);
        var output = '';
        var cursor = 0;
        richHtmlSourceTokenRanges(value).forEach(function (range) {
            output += escapeHtml(value.slice(cursor, range.start));
            var token = value.slice(range.start, range.end);
            if (token.startsWith('<!--')) {
                output += '<span class="blogEditor__syntaxComment">'
                    + escapeHtml(token) + '</span>';
                cursor = range.end;
                return;
            }
            var tag = /^<(\/?)\s*([a-z][a-z0-9-]*)([\s\S]*?)(\/?)>$/iu
                .exec(token);
            if (tag === null) {
                output += escapeHtml(token);
                cursor = range.end;
                return;
            }
            output += '<span class="blogEditor__syntaxPunctuation">&lt;'
                + escapeHtml(tag[1]) + '</span>'
                + '<span class="blogEditor__syntaxTag">'
                + escapeHtml(tag[2]) + '</span>';
            var attributes = tag[3];
            var attributeCursor = 0;
            var attributeMatcher = /(\s+)([a-z_:][a-z0-9_.:-]*)(\s*=\s*)?("[^"]*"|'[^']*'|[^\s"'=<>`]+)?/giu;
            var attribute;
            while ((attribute = attributeMatcher.exec(attributes)) !== null) {
                output += escapeHtml(
                    attributes.slice(attributeCursor, attribute.index)
                ) + escapeHtml(attribute[1])
                    + '<span class="blogEditor__syntaxAttribute">'
                    + escapeHtml(attribute[2]) + '</span>';
                if (attribute[3]) {
                    output += '<span class="blogEditor__syntaxPunctuation">'
                        + escapeHtml(attribute[3]) + '</span>';
                }
                if (attribute[4]) {
                    output += '<span class="blogEditor__syntaxString">'
                        + escapeHtml(attribute[4]) + '</span>';
                }
                attributeCursor = attributeMatcher.lastIndex;
            }
            output += escapeHtml(attributes.slice(attributeCursor))
                + '<span class="blogEditor__syntaxPunctuation">'
                + escapeHtml(tag[4]) + '&gt;</span>';
            cursor = range.end;
        });
        return output + escapeHtml(value.slice(cursor));
    }

    function richHighlightCssSource(value) {
        value = String(value);
        var output = '';
        var index = 0;
        var depth = 0;
        var mode = 'property';

        function token(className, raw) {
            return '<span class="' + className + '">'
                + escapeHtml(raw) + '</span>';
        }

        while (index < value.length) {
            var current = value.charAt(index);
            var next = value.charAt(index + 1);
            if (current === '/' && next === '*') {
                var commentEnd = value.indexOf('*/', index + 2);
                commentEnd = commentEnd < 0 ? value.length : commentEnd + 2;
                output += token(
                    'blogEditor__syntaxComment',
                    value.slice(index, commentEnd)
                );
                index = commentEnd;
                continue;
            }
            if (current === '"' || current === "'") {
                var quote = current;
                var stringEnd = index + 1;
                while (stringEnd < value.length) {
                    if (value.charAt(stringEnd) === '\\') {
                        stringEnd += 2;
                        continue;
                    }
                    if (value.charAt(stringEnd) === quote) {
                        stringEnd += 1;
                        break;
                    }
                    stringEnd += 1;
                }
                output += token(
                    'blogEditor__syntaxString',
                    value.slice(index, stringEnd)
                );
                index = stringEnd;
                continue;
            }
            var atRule = /^@[a-z][a-z0-9-]*/iu.exec(value.slice(index));
            if (atRule !== null) {
                output += token('blogEditor__syntaxAtRule', atRule[0]);
                index += atRule[0].length;
                continue;
            }
            var word = /^(?:--)?[a-z_][a-z0-9_-]*|^#[a-f0-9]{3,8}|^-?(?:\d*\.)?\d+(?:[a-z%]+)?/iu
                .exec(value.slice(index));
            if (word !== null) {
                var tail = value.slice(index + word[0].length);
                var nextDelimiter = /[{}:;]/u.exec(tail);
                var delimiter = nextDelimiter ? nextDelimiter[0] : '';
                var className = 'blogEditor__syntaxValue';
                if (mode === 'selector' || delimiter === '{') {
                    className = 'blogEditor__syntaxSelector';
                } else if (mode === 'property' && delimiter === ':') {
                    className = 'blogEditor__syntaxProperty';
                }
                output += token(className, word[0]);
                index += word[0].length;
                continue;
            }
            output += escapeHtml(current);
            if (current === '{') {
                depth += 1;
                mode = 'property';
            } else if (current === '}') {
                depth = Math.max(0, depth - 1);
                mode = 'property';
            } else if (current === ':' && depth > 0 && mode === 'property') {
                mode = 'value';
            } else if (current === ';' && depth > 0) {
                mode = 'property';
            }
            index += 1;
        }
        return output;
    }

    function richSyncHighlight(textarea, highlight, language) {
        if (!(highlight instanceof HTMLElement)) {
            return;
        }
        highlight.innerHTML = (language === 'css'
            ? richHighlightCssSource(textarea.value)
            : richHighlightHtmlSource(textarea.value)) + '\n';
        highlight.scrollTop = textarea.scrollTop;
        highlight.scrollLeft = textarea.scrollLeft;
    }

    function richSourceLinePosition(source) {
        var caret = Math.max(0, source.selectionStart || 0);
        var prefix = source.value.slice(0, caret);
        var lastBreak = prefix.lastIndexOf('\n');
        return {
            line: (prefix.match(/\n/gu) || []).length + 1,
            column: caret - lastBreak
        };
    }

    function richSourceUpdatePosition(state) {
        var position = richSourceLinePosition(state.source);
        var message = 'Línea ' + position.line + ', columna '
            + position.column + '.';
        if (!state.sourceSuggestions.hidden) {
            message += ' ' + state.sourceSuggestionResult.items.length
                + ' sugerencias disponibles.';
        }
        state.sourcePosition.textContent = message;
    }

    function richSourceGutterModel(value, heights, fallbackHeight) {
        var lines = String(value).split('\n');
        var fallback = Number.isFinite(fallbackHeight) && fallbackHeight > 0
            ? fallbackHeight
            : 24;
        return lines.map(function (unused, index) {
            var measured = Array.isArray(heights) ? heights[index] : null;
            return {
                number: index + 1,
                height: Number.isFinite(measured) && measured > 0
                    ? measured
                    : fallback
            };
        });
    }

    function richSourceMeasureWrappedLines(textarea, mirror) {
        var width = Math.max(0, textarea.clientWidth || 0);
        if (
            width === 0
            || !(mirror instanceof HTMLElement)
            || typeof mirror.getBoundingClientRect !== 'function'
        ) {
            return null;
        }
        mirror.style.inlineSize = width + 'px';
        var computed = typeof window.getComputedStyle === 'function'
            ? window.getComputedStyle(textarea)
            : null;
        if (computed) {
            [
                'fontFamily', 'fontSize', 'fontStyle', 'fontWeight',
                'fontStretch', 'letterSpacing', 'lineHeight', 'wordSpacing',
                'paddingBlockStart', 'paddingBlockEnd', 'paddingInlineStart',
                'paddingInlineEnd', 'tabSize', 'whiteSpace', 'overflowWrap',
                'wordBreak'
            ].forEach(function (property) {
                mirror.style[property] = computed[property];
            });
        }
        var lines = String(textarea.value).split('\n');
        var fragment = document.createDocumentFragment();
        lines.forEach(function (line) {
            var row = element('span', 'blogEditor__richSourceMeasureLine');
            row.textContent = line === '' ? '\u200b' : line;
            fragment.append(row);
        });
        mirror.replaceChildren(fragment);
        var fallback = computed ? Number.parseFloat(computed.lineHeight) : 0;
        if (!Number.isFinite(fallback) || fallback <= 0) {
            fallback = 24;
        }
        return Array.from(mirror.children).map(function (row) {
            var height = row.getBoundingClientRect().height;
            return Number.isFinite(height) && height > 0 ? height : fallback;
        });
    }

    function richSyncMeasuredSourceGutter(state, kind) {
        var css = kind === 'css';
        var source = css ? state.cssSource : state.source;
        var editor = css ? state.cssEditor : state.sourceEditor;
        var gutter = css ? state.cssGutter : state.sourceGutter;
        var gutterLines = css
            ? state.cssGutterLines
            : state.sourceGutterLines;
        var mirror = css ? state.cssMeasure : state.sourceMeasure;
        var lineCountKey = css ? 'cssLineCount' : 'sourceLineCount';
        var signatureKey = css
            ? 'cssGutterSignature'
            : 'sourceGutterSignature';
        var lineCount = (source.value.match(/\n/gu) || []).length + 1;
        if (lineCount > RICH_SOURCE_MAX_GUTTER_LINES) {
            editor.dataset.gutter = 'off';
            gutterLines.replaceChildren();
            mirror.replaceChildren();
            state[lineCountKey] = 0;
            state[signatureKey] = 'cap:' + lineCount;
            gutter.scrollTop = source.scrollTop;
            return false;
        }
        // Measure against the final two-column geometry, including the gutter.
        editor.dataset.gutter = 'on';
        var width = Math.max(0, source.clientWidth || 0);
        var signature = width + ':' + source.value;
        if (width === 0) {
            editor.dataset.gutter = 'off';
            gutterLines.replaceChildren();
            state[lineCountKey] = 0;
            state[signatureKey] = '';
            gutter.scrollTop = source.scrollTop;
            return false;
        }
        if (state[signatureKey] !== signature) {
            var heights = richSourceMeasureWrappedLines(source, mirror);
            if (heights === null) {
                editor.dataset.gutter = 'off';
                gutterLines.replaceChildren();
                state[lineCountKey] = 0;
                state[signatureKey] = '';
                gutter.scrollTop = source.scrollTop;
                return false;
            }
            var model = richSourceGutterModel(source.value, heights, 24);
            var fragment = document.createDocumentFragment();
            model.forEach(function (entry) {
                var number = element(
                    'span',
                    'blogEditor__richSourceGutterLine',
                    String(entry.number)
                );
                number.style.blockSize = entry.height + 'px';
                fragment.append(number);
            });
            gutterLines.replaceChildren(fragment);
            editor.dataset.gutter = 'on';
            state[lineCountKey] = lineCount;
            state[signatureKey] = signature;
        }
        gutter.scrollTop = source.scrollTop;
        return true;
    }

    function richSourceSyncGutter(state) {
        return richSyncMeasuredSourceGutter(state, 'source');
    }

    function richCssSourceSyncGutter(state) {
        return richSyncMeasuredSourceGutter(state, 'css');
    }

    function richScheduleSourceGutter(state, kind) {
        var frameKey = kind === 'css'
            ? 'cssGutterFrame'
            : 'sourceGutterFrame';
        if (state[frameKey]) {
            return;
        }
        var sync = function () {
            state[frameKey] = 0;
            if (kind === 'css') {
                richCssSourceSyncGutter(state);
            } else {
                richSourceSyncGutter(state);
            }
        };
        if (typeof window.requestAnimationFrame === 'function') {
            state[frameKey] = window.requestAnimationFrame(sync);
            return;
        }
        sync();
    }

    function richSourceHideSuggestions(state) {
        state.sourceSuggestions.hidden = true;
        state.sourceSuggestionList.replaceChildren();
        state.sourceSuggestionResult = { start: 0, query: '', items: [] };
        state.sourceSuggestionIndex = -1;
        state.source.setAttribute('aria-expanded', 'false');
        state.source.removeAttribute('aria-activedescendant');
        richSourceUpdatePosition(state);
    }

    function richSourceSetSuggestionIndex(state, index) {
        var options = Array.from(
            state.sourceSuggestionList.querySelectorAll('[role="option"]')
        );
        if (options.length === 0) {
            richSourceHideSuggestions(state);
            return;
        }
        state.sourceSuggestionIndex = (
            index % options.length + options.length
        ) % options.length;
        options.forEach(function (option, optionIndex) {
            var selected = optionIndex === state.sourceSuggestionIndex;
            option.setAttribute('aria-selected', selected ? 'true' : 'false');
            if (selected) {
                state.source.setAttribute('aria-activedescendant', option.id);
                if (typeof option.scrollIntoView === 'function') {
                    option.scrollIntoView({ block: 'nearest' });
                }
            }
        });
        richSourceUpdatePosition(state);
    }

    function richSourceRefreshSuggestions(state, force) {
        var result = richSourceSuggestions(
            state.source.value,
            state.source.selectionStart || 0,
            state.textFlowMode,
            state.allowBreak,
            force === true,
            state.allowedHeadingLevels,
            state.sourceOnlyMode ? 'embed' : state.advancedSourceEnabled
        );
        if (result.items.length === 0) {
            richSourceHideSuggestions(state);
            return;
        }
        state.sourceSuggestionResult = result;
        state.sourceSuggestionList.replaceChildren();
        result.items.forEach(function (definition, index) {
            var option = element('button', 'blogEditor__richSourceSuggestion');
            option.type = 'button';
            option.id = 'blog-rich-source-option-' + state.context.instance
                + '-' + index;
            option.dataset.blogRichSourceSuggestion = String(index);
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.append(
                richSuggestionCode(
                    '<' + definition.tag + '>',
                    result.query
                ),
                element('span', '', definition.label)
            );
            state.sourceSuggestionList.append(option);
        });
        state.sourceSuggestions.hidden = false;
        state.source.setAttribute('aria-expanded', 'true');
        richSourceSetSuggestionIndex(state, 0);
    }

    function richSourceSyncChrome(state, refreshSuggestions) {
        state.source.scrollLeft = 0;
        richScheduleSourceGutter(state, 'source');
        richSyncHighlight(state.source, state.sourceHighlight, 'html');
        richSourceUpdatePosition(state);
        if (refreshSuggestions) {
            richSourceRefreshSuggestions(state, false);
        }
    }

    function richCssHideSuggestions(state) {
        state.cssSuggestions.hidden = true;
        state.cssSuggestionList.replaceChildren();
        state.cssSuggestionResult = {
            start: 0,
            query: '',
            kind: '',
            items: []
        };
        state.cssSuggestionIndex = -1;
        state.cssSource.setAttribute('aria-expanded', 'false');
        state.cssSource.removeAttribute('aria-activedescendant');
    }

    function richCssSetSuggestionIndex(state, index) {
        var options = Array.from(
            state.cssSuggestionList.querySelectorAll('[role="option"]')
        );
        if (options.length === 0) {
            richCssHideSuggestions(state);
            return;
        }
        state.cssSuggestionIndex = (
            index % options.length + options.length
        ) % options.length;
        options.forEach(function (option, optionIndex) {
            var selected = optionIndex === state.cssSuggestionIndex;
            option.setAttribute('aria-selected', selected ? 'true' : 'false');
            if (selected) {
                state.cssSource.setAttribute(
                    'aria-activedescendant',
                    option.id
                );
                if (typeof option.scrollIntoView === 'function') {
                    option.scrollIntoView({ block: 'nearest' });
                }
            }
        });
    }

    function richCssRefreshSuggestions(state, force) {
        var result = richCssSuggestions(
            state.cssSource.value,
            state.cssSource.selectionStart || 0,
            force === true,
            richStatePolicy(state)
        );
        if (result.items.length === 0) {
            richCssHideSuggestions(state);
            return;
        }
        state.cssSuggestionResult = result;
        state.cssSuggestionList.replaceChildren();
        result.items.forEach(function (definition, index) {
            var option = element('button', 'blogEditor__richSourceSuggestion');
            option.type = 'button';
            option.id = 'blog-rich-css-option-' + state.context.instance
                + '-' + index;
            option.dataset.blogRichCssSuggestion = String(index);
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.append(
                richSuggestionCode(definition.value, result.query),
                element(
                    'span',
                    '',
                    definition.kind === 'property'
                        ? 'Propiedad permitida'
                        : 'Valor permitido para ' + definition.property
                )
            );
            state.cssSuggestionList.append(option);
        });
        state.cssSuggestions.hidden = false;
        state.cssSource.setAttribute('aria-expanded', 'true');
        richCssSetSuggestionIndex(state, 0);
    }

    function richCssSourceSyncChrome(state, refreshSuggestions) {
        state.cssSource.scrollLeft = 0;
        richScheduleSourceGutter(state, 'css');
        richSyncHighlight(state.cssSource, state.cssHighlight, 'css');
        if (refreshSuggestions) {
            richCssRefreshSuggestions(state, false);
        }
        var position = richSourceLinePosition(state.cssSource);
        var message = 'Línea ' + position.line
            + ', columna ' + position.column + '.';
        if (!state.cssSuggestions.hidden) {
            message += ' ' + state.cssSuggestionResult.items.length
                + ' sugerencias disponibles.';
        }
        state.cssPosition.textContent = message;
    }

    function richCodeHistory() {
        return {
            undo: [],
            redo: [],
            pending: null,
            current: null,
            composition: null
        };
    }

    function richResetCodeHistory(history, control) {
        history.undo = [];
        history.redo = [];
        history.pending = null;
        history.current = control ? richCodeSnapshot(control) : null;
        history.composition = null;
    }

    function richCodeSnapshot(control) {
        return {
            value: control.value,
            start: Math.max(0, control.selectionStart || 0),
            end: Math.max(0, control.selectionEnd || 0)
        };
    }

    function richCodeTouched(control, baseline) {
        return control.value !== baseline;
    }

    function richCodeSnapshotsEqual(left, right) {
        return Boolean(left && right)
            && left.value === right.value
            && left.start === right.start
            && left.end === right.end;
    }

    function richCodeRecordHistory(history, before, after, inputType) {
        if (
            !history
            || before.value === after.value
            || !Array.isArray(history.undo)
            || !Array.isArray(history.redo)
        ) {
            if (history) {
                history.current = after;
                history.pending = null;
            }
            return;
        }
        var normalizedInputType = String(inputType || 'programmatic');
        var previous = history.undo[history.undo.length - 1];
        if (
            normalizedInputType === 'insertText'
            && previous
            && previous.inputType === 'insertText'
            && previous.after.value === before.value
            && previous.after.start === before.start
            && previous.after.end === before.end
            && before.start === before.end
            && after.start === after.end
            && after.value.length > before.value.length
        ) {
            previous.after = after;
            history.redo = [];
            history.current = after;
            history.pending = null;
            return;
        }
        history.undo.push({
            before: before,
            after: after,
            inputType: normalizedInputType
        });
        if (history.undo.length > RICH_CODE_HISTORY_LIMIT) {
            history.undo.splice(
                0,
                history.undo.length - RICH_CODE_HISTORY_LIMIT
            );
        }
        history.redo = [];
        history.current = after;
        history.pending = null;
    }

    function richCodeHistoryBeforeInput(control, history, event) {
        if (!history) {
            return null;
        }
        var inputType = String(event.inputType || '');
        if (inputType === 'historyUndo' || inputType === 'historyRedo') {
            var direction = inputType === 'historyUndo' ? 'undo' : 'redo';
            history.pending = null;
            if (typeof event.preventDefault === 'function') {
                event.preventDefault();
            }
            richCodeHistoryStep(control, history, direction);
            return direction;
        }
        history.pending = {
            before: richCodeSnapshot(control),
            inputType: inputType || 'native',
            composing: event.isComposing === true
                || inputType === 'insertCompositionText'
        };
        if (history.pending.composing && !history.composition) {
            history.composition = history.pending.before;
        }
        return null;
    }

    function richCodeHistoryCommitInput(control, history, event) {
        if (!history) {
            return false;
        }
        var inputType = String(event && event.inputType || 'native');
        if (inputType === 'historyUndo' || inputType === 'historyRedo') {
            history.pending = null;
            history.current = richCodeSnapshot(control);
            return false;
        }
        var before = history.pending && history.pending.before
            ? history.pending.before
            : history.current;
        var effectiveInputType = history.pending && history.pending.inputType
            ? history.pending.inputType
            : inputType;
        var after = richCodeSnapshot(control);
        if (
            history.pending
            && (
                history.pending.composing
                || (event && event.isComposing === true)
            )
        ) {
            history.current = after;
            history.pending = null;
            return false;
        }
        if (!before) {
            history.current = after;
            history.pending = null;
            return false;
        }
        richCodeRecordHistory(history, before, after, effectiveInputType);
        return !richCodeSnapshotsEqual(before, after);
    }

    function richCodeHistoryCommitComposition(control, history) {
        if (!history || !history.composition) {
            return false;
        }
        var before = history.composition;
        var after = richCodeSnapshot(control);
        history.composition = null;
        history.pending = null;
        richCodeRecordHistory(
            history,
            before,
            after,
            'insertCompositionText'
        );
        return !richCodeSnapshotsEqual(before, after);
    }

    function richCodeHistoryDirection(event) {
        if (
            event.isComposing
            || event.keyCode === 229
            || event.altKey
            || !(event.ctrlKey || event.metaKey)
        ) {
            return null;
        }
        var key = String(event.key || '').toLowerCase();
        if (key === 'z') {
            return event.shiftKey ? 'redo' : 'undo';
        }
        if (key === 'y' && !event.shiftKey) {
            return 'redo';
        }
        return null;
    }

    function richCodeHistoryStep(control, history, direction) {
        if (!history || !['undo', 'redo'].includes(direction)) {
            return false;
        }
        var source = direction === 'undo' ? history.undo : history.redo;
        var target = direction === 'undo' ? history.redo : history.undo;
        if (!Array.isArray(source) || !Array.isArray(target)) {
            return false;
        }
        var entry = source[source.length - 1];
        var replacement = direction === 'undo' ? entry && entry.before : entry && entry.after;
        if (!entry) {
            return false;
        }
        source.pop();
        target.push(entry);
        control.value = replacement.value;
        control.setSelectionRange(replacement.start, replacement.end);
        history.current = richCodeSnapshot(control);
        history.pending = null;
        return true;
    }

    function richApplyTextareaEdit(control, edit, history) {
        var before = richCodeSnapshot(control);
        var previous = control.value;
        var next = edit.value;
        var prefix = 0;
        while (
            prefix < previous.length
            && prefix < next.length
            && previous.charAt(prefix) === next.charAt(prefix)
        ) {
            prefix += 1;
        }
        var suffix = 0;
        while (
            suffix < previous.length - prefix
            && suffix < next.length - prefix
            && previous.charAt(previous.length - suffix - 1)
                === next.charAt(next.length - suffix - 1)
        ) {
            suffix += 1;
        }
        if (typeof control.setRangeText === 'function') {
            control.setRangeText(
                next.slice(prefix, next.length - suffix),
                prefix,
                previous.length - suffix,
                'end'
            );
        } else {
            control.value = next;
        }
        control.setSelectionRange(edit.start, edit.end);
        richCodeRecordHistory(
            history,
            before,
            richCodeSnapshot(control),
            'programmatic'
        );
    }

    function richCssSourceApplyEdit(state, edit) {
        if (edit === null) {
            return false;
        }
        richApplyTextareaEdit(state.cssSource, edit, state.cssHistory);
        state.inputTouched = true;
        state.cssTouched = richCodeTouched(
            state.cssSource,
            state.cssBaseline
        );
        richCssHideSuggestions(state);
        richCssSourceSyncChrome(state);
        richUpdateLimitFeedback(state);
        richRefreshAdvancedValidationStatus(state, 'css');
        return true;
    }

    function richCssApplySuggestion(state, index) {
        var result = state.cssSuggestionResult;
        var definition = result.items[index];
        if (!definition) {
            return false;
        }
        var applied = richCssSourceApplyEdit(state, richCssSuggestionEdit(
            state.cssSource.value,
            result.start,
            state.cssSource.selectionStart || result.start,
            definition
        ));
        if (applied && definition.kind === 'property') {
            richCssRefreshSuggestions(state, true);
            richCssSourceSyncChrome(state, false);
        }
        return applied;
    }

    function richSourceApplyEdit(state, edit) {
        if (edit === null) {
            return false;
        }
        richApplyTextareaEdit(state.source, edit, state.sourceHistory);
        state.inputTouched = true;
        state.sourceTouched = richCodeTouched(
            state.source,
            state.sourceBaseline
        );
        richSourceHideSuggestions(state);
        richSourceSyncChrome(state, false);
        richUpdateLimitFeedback(state);
        richRefreshAdvancedValidationStatus(state, 'html');
        return true;
    }

    function richSourceApplySuggestion(state, index) {
        var result = state.sourceSuggestionResult;
        var definition = result.items[index];
        if (!definition) {
            return false;
        }
        return richSourceApplyEdit(state, richSourceSuggestionEdit(
            state.source.value,
            result.start,
            state.source.selectionStart || result.start,
            definition
        ));
    }

    function richBuildModal(context) {
        if (context.richEditor) {
            return context.richEditor;
        }

        var dialog = document.createElement('dialog');
        dialog.className = 'blogEditor__richDialog';
        var titleId = 'blog-rich-title-' + context.instance;
        dialog.setAttribute('aria-labelledby', titleId);
        dialog.setAttribute('aria-modal', 'true');

        var shell = element('div', 'blogEditor__richModal');
        var header = element('header', 'blogEditor__richHeader');
        var headingGroup = element('div', 'blogEditor__richHeading');
        var title = element('h2', '', 'Editar texto');
        title.id = titleId;
        headingGroup.append(
            title,
            element(
                'p',
                '',
                'Selecciona el texto y aplica s\u00f3lo el formato necesario.'
            )
        );
        var close = richToolbarButton('Cerrar sin aplicar', 'cancel', '\u00d7');
        close.className = 'blogEditor__richClose';
        var expand = richToolbarButton(
            'Ampliar el editor hasta el alto disponible',
            'toggle-size',
            'Ampliar'
        );
        expand.setAttribute('aria-pressed', 'false');
        var headerActions = element('div', 'blogEditor__richHeaderActions');
        headerActions.append(expand, close);
        header.append(headingGroup, headerActions);

        var modeTabs = element('div', 'blogEditor__richTabs');
        modeTabs.setAttribute('role', 'tablist');
        modeTabs.setAttribute('aria-label', 'Modo de edici\u00f3n');
        var visualTab = richToolbarButton(
            'Edici\u00f3n visual',
            'mode-visual',
            'Visual'
        );
        var sourceTab = richToolbarButton(
            'Edici\u00f3n HTML permitida',
            'mode-source',
            'HTML'
        );
        var cssTab = richToolbarButton(
            'CSS exclusivo de este bloque',
            'mode-css',
            'CSS'
        );
        visualTab.setAttribute('role', 'tab');
        sourceTab.setAttribute('role', 'tab');
        cssTab.setAttribute('role', 'tab');
        visualTab.id = 'blog-rich-visual-tab-' + context.instance;
        sourceTab.id = 'blog-rich-source-tab-' + context.instance;
        cssTab.id = 'blog-rich-css-tab-' + context.instance;
        modeTabs.append(visualTab, sourceTab, cssTab);

        var visualPanel = element('section', 'blogEditor__richVisualPanel');
        visualPanel.setAttribute('role', 'tabpanel');
        visualPanel.id = 'blog-rich-visual-panel-' + context.instance;
        visualPanel.setAttribute('aria-labelledby', visualTab.id);
        visualTab.setAttribute('aria-controls', visualPanel.id);
        var toolbar = element('div', 'blogEditor__richToolbar');
        toolbar.setAttribute('role', 'toolbar');
        toolbar.setAttribute('aria-label', 'Formato del texto seleccionado');
        var strong = richToolbarIconButton('Negrita', 'strong', 'bold');
        var emphasis = richToolbarIconButton('Cursiva', 'em', 'italic');
        var underline = richToolbarIconButton(
            'Subrayado',
            'underline',
            'underline'
        );
        [strong, emphasis, underline].forEach(function (button) {
            button.dataset.blogRichRequiresSelection = 'true';
        });
        strong.setAttribute('aria-pressed', 'false');
        emphasis.setAttribute('aria-pressed', 'false');
        underline.setAttribute('aria-pressed', 'false');

        var size = richToolbarSelect(context, 'Tama\u00f1o', 'size', [
            { value: '', label: 'Normal' },
            { value: 'size-small', label: 'Peque\u00f1o' },
            { value: 'size-large', label: 'Grande' },
            { value: 'size-xlarge', label: 'Muy grande' }
        ], 'format-size');
        var color = richToolbarPalette(
            context,
            'Color',
            'color',
            RICH_PALETTE_OPTIONS.color
        );
        var background = richToolbarPalette(
            context,
            'Fondo',
            'background',
            RICH_PALETTE_OPTIONS.background
        );
        var textRgba = richRgbaControl(
            context,
            'Color de texto',
            'color'
        );
        var backgroundRgba = richRgbaControl(
            context,
            'Fondo de texto',
            'background'
        );
        [
            { palette: color, control: textRgba, group: 'color' },
            { palette: background, control: backgroundRgba, group: 'background' }
        ].forEach(function (definition) {
            var custom = richToolbarIconButton(
                'Elegir un color RGBA personalizado',
                'toggle-rgba',
                'palette'
            );
            custom.classList.add('blogEditor__richCustomColor');
            custom.dataset.blogRichPalette = definition.group;
            custom.dataset.blogRichRequiresSelection = 'true';
            definition.palette.menu.append(custom, definition.control.root);
        });
        var linkButton = richToolbarIconButton(
            'A\u00f1adir o editar enlace',
            'link',
            'link'
        );
        var unlinkButton = richToolbarIconButton(
            'Quitar enlace',
            'unlink',
            'unlink'
        );
        var clearButton = richToolbarIconButton(
            'Limpiar formato',
            'clear',
            'clear'
        );
        [linkButton, unlinkButton, clearButton].forEach(function (button) {
            button.dataset.blogRichRequiresSelection = 'true';
        });
        var unorderedListStyle = richToolbarSelect(
            context,
            'Tipo de lista con viñetas',
            'list-unordered-style',
            [
                { value: 'disc', label: 'Punto' },
                { value: 'circle', label: 'Círculo' },
                { value: 'square', label: 'Cuadrado' },
                { value: 'remove', label: 'Quitar lista' }
            ],
            'unordered'
        );
        var orderedListStyle = richToolbarSelect(
            context,
            'Tipo de lista numerada',
            'list-ordered-style',
            [
                { value: 'decimal', label: '1, 2, 3' },
                { value: 'lower-alpha', label: 'a, b, c' },
                { value: 'upper-alpha', label: 'A, B, C' },
                { value: 'remove', label: 'Quitar lista' }
            ],
            'ordered'
        );
        var flowListStyles = {
            unordered: unorderedListStyle,
            ordered: orderedListStyle
        };
        Object.keys(flowListStyles).forEach(function (listType) {
            var listStyle = flowListStyles[listType];
            delete listStyle.select.dataset.blogRichFormat;
            delete listStyle.select.dataset.blogRichRequiresSelection;
            listStyle.select.dataset.blogRichListStyle = listType;
            listStyle.select.options[0].textContent = 'Elegir estilo';
            listStyle.label.classList.add('blogEditor__richListStyle');
            listStyle.label.dataset.blogRichListStyleType = listType;
            listStyle.label.hidden = true;
        });
        var blockType = richToolbarBlockTypePicker(context, [
            {
                value: 'paragraph',
                shortLabel: 'P',
                label: 'Párrafo'
            },
            { value: 'h2', shortLabel: 'H2', label: 'Encabezado 2' },
            { value: 'h3', shortLabel: 'H3', label: 'Encabezado 3' },
            { value: 'h4', shortLabel: 'H4', label: 'Encabezado 4' },
            { value: 'h5', shortLabel: 'H5', label: 'Encabezado 5' },
            { value: 'h6', shortLabel: 'H6', label: 'Encabezado 6' }
        ]);
        var quoteButton = richToolbarIconButton(
            'Convertir bloque en cita',
            'block-quote',
            'quote'
        );
        var calloutButton = richToolbarIconButton(
            'Convertir bloque en destacado',
            'block-callout',
            'callout'
        );
        [quoteButton, calloutButton].forEach(function (button) {
            button.hidden = true;
            button.setAttribute('aria-pressed', 'false');
        });
        var flowMetadataPanel = element(
            'section',
            'blogEditor__richFlowMetadata'
        );
        flowMetadataPanel.hidden = true;
        var flowMetadataTitle = element(
            'h3',
            '',
            'Ajustes del elemento'
        );
        flowMetadataTitle.id = 'blog-rich-flow-metadata-title-'
            + context.instance;
        flowMetadataPanel.setAttribute(
            'aria-labelledby',
            flowMetadataTitle.id
        );
        var flowMetadataContext = element(
            'p',
            'blogEditor__richFlowMetadataContext'
        );
        flowMetadataContext.setAttribute('aria-live', 'polite');

        var quoteMetadataGroup = element(
            'fieldset',
            'blogEditor__richFlowMetadataFields'
        );
        quoteMetadataGroup.hidden = true;
        quoteMetadataGroup.append(element('legend', 'webadmin-srOnly', 'Cita'));
        var flowQuoteAuthor = document.createElement('input');
        flowQuoteAuthor.type = 'text';
        flowQuoteAuthor.maxLength = 255;
        flowQuoteAuthor.autocomplete = 'off';
        flowQuoteAuthor.dataset.blogRichFlowMetadata = 'author';
        var flowQuoteSource = document.createElement('input');
        flowQuoteSource.type = 'text';
        flowQuoteSource.maxLength = 500;
        flowQuoteSource.autocomplete = 'off';
        flowQuoteSource.dataset.blogRichFlowMetadata = 'source';
        var flowQuotePreset = document.createElement('select');
        flowQuotePreset.dataset.blogRichFlowMetadata = 'preset';
        [
            { value: 'default', label: 'Base' },
            { value: 'accent', label: 'Acento' },
            { value: 'minimal', label: 'Mínimo' }
        ].forEach(function (definition) {
            var option = document.createElement('option');
            option.value = definition.value;
            option.textContent = definition.label;
            flowQuotePreset.append(option);
        });
        quoteMetadataGroup.append(
            richLabeledControl(
                'Autor opcional (máx. 255 bytes)',
                flowQuoteAuthor
            ),
            richLabeledControl(
                'Fuente opcional (máx. 500 bytes)',
                flowQuoteSource
            ),
            richLabeledControl('Estilo de la cita', flowQuotePreset)
        );

        var calloutMetadataGroup = element(
            'fieldset',
            'blogEditor__richFlowMetadataFields'
        );
        calloutMetadataGroup.hidden = true;
        calloutMetadataGroup.append(element(
            'legend',
            'webadmin-srOnly',
            'Destacado'
        ));
        var flowCalloutTone = document.createElement('select');
        flowCalloutTone.dataset.blogRichFlowMetadata = 'tone';
        [
            { value: 'neutral', label: 'Neutral' },
            { value: 'info', label: 'Informativo' },
            { value: 'warning', label: 'Aviso' }
        ].forEach(function (definition) {
            var option = document.createElement('option');
            option.value = definition.value;
            option.textContent = definition.label;
            flowCalloutTone.append(option);
        });
        calloutMetadataGroup.append(richLabeledControl(
            'Estilo del destacado',
            flowCalloutTone
        ));
        flowMetadataPanel.append(
            flowMetadataTitle,
            flowMetadataContext,
            quoteMetadataGroup,
            calloutMetadataGroup
        );
        var alignment = element('div', 'blogEditor__richAlignment');
        alignment.append(element(
            'span',
            'blogEditor__richAlignmentLabel webadmin-srOnly',
            'Alineaci\u00f3n'
        ));
        var alignmentButtons = {};
        var alignmentButtonsRoot = element(
            'div',
            'blogEditor__richAlignmentButtons'
        );
        [
            { value: 'start', label: 'Izquierda' },
            { value: 'center', label: 'Centro' },
            { value: 'end', label: 'Derecha' },
            { value: 'justify', label: 'Justificar' }
        ].forEach(function (definition) {
            var button = richToolbarIconButton(
                'Alinear texto: ' + definition.label,
                'text-align-' + definition.value,
                'align-' + definition.value
            );
            button.setAttribute('aria-pressed', 'false');
            alignmentButtons[definition.value] = button;
            alignmentButtonsRoot.append(button);
        });
        alignment.append(alignmentButtonsRoot);
        toolbar.append(
            blockType.root,
            quoteButton,
            calloutButton,
            unorderedListStyle.label,
            orderedListStyle.label,
            strong,
            emphasis,
            underline,
            size.label,
            color.root,
            background.root,
            linkButton,
            unlinkButton,
            clearButton
        );

        var listPanel = element('section', 'blogEditor__richListPanel');
        listPanel.hidden = true;
        listPanel.append(element('h3', '', 'Elementos de la lista'));
        var listTextarea = document.createElement('textarea');
        listTextarea.className = 'blogEditor__listTextarea';
        listTextarea.rows = 12;
        listTextarea.placeholder = 'Un elemento por l\u00ednea';
        listTextarea.setAttribute('aria-label', 'Elementos de la lista');
        listPanel.append(
            listTextarea,
            element(
                'p',
                'blogEditor__fieldHelp',
                'Cada l\u00ednea no vac\u00eda se convierte en un elemento. El tipo y el marcador se configuran desde los iconos de lista.'
            )
        );

        var linkPanel = element('div', 'blogEditor__richLinkPanel');
        linkPanel.hidden = true;
        var linkHref = document.createElement('input');
        linkHref.id = 'blog-rich-link-href-' + context.instance;
        linkHref.type = 'url';
        linkHref.maxLength = 2048;
        linkHref.placeholder = 'https://example.com o /ruta';
        var linkTitle = document.createElement('input');
        linkTitle.id = 'blog-rich-link-title-' + context.instance;
        linkTitle.type = 'text';
        linkTitle.maxLength = 500;
        var linkTarget = document.createElement('select');
        linkTarget.id = 'blog-rich-link-target-' + context.instance;
        [
            { value: 'same', label: 'Misma ventana' },
            { value: 'new', label: 'Nueva ventana' }
        ].forEach(function (definition) {
            var option = document.createElement('option');
            option.value = definition.value;
            option.textContent = definition.label;
            linkTarget.append(option);
        });
        var linkActions = element('div', 'blogEditor__richLinkActions');
        linkActions.append(
            richToolbarButton('Aplicar enlace', 'apply-link', 'Aplicar'),
            richToolbarButton('Cancelar enlace', 'cancel-link', 'Cancelar')
        );
        linkPanel.append(
            richLabeledControl('URL', linkHref),
            richLabeledControl('Title opcional', linkTitle),
            richLabeledControl('Destino', linkTarget),
            linkActions
        );

        var visual = element('div', 'blogEditor__richCanvas');
        visual.contentEditable = context.readOnly ? 'false' : 'true';
        visual.spellcheck = true;
        visual.setAttribute('role', 'textbox');
        visual.setAttribute('aria-multiline', 'true');
        visual.setAttribute('aria-label', 'Contenido del bloque');
        visualPanel.append(
            listPanel,
            toolbar,
            flowMetadataPanel,
            linkPanel,
            visual
        );

        var sourcePanel = element('section', 'blogEditor__richSourcePanel');
        sourcePanel.setAttribute('role', 'tabpanel');
        sourcePanel.id = 'blog-rich-source-panel-' + context.instance;
        sourcePanel.setAttribute('aria-labelledby', sourceTab.id);
        sourceTab.setAttribute('aria-controls', sourcePanel.id);
        sourcePanel.hidden = true;
        var sourceHelp = element(
            'p',
            'blogEditor__richHelp',
            'HTML permitido: strong, em, u, a, br y span con tokens LiquidStack.'
        );
        sourceHelp.id = 'blog-rich-source-help-' + context.instance;
        var source = document.createElement('textarea');
        source.id = 'blog-rich-source-' + context.instance;
        source.className = 'blogEditor__richSource';
        source.spellcheck = false;
        // Soft wrapping keeps long HTML inside the editor viewport.
        source.wrap = 'soft';
        source.autocomplete = 'off';
        source.setAttribute('autocapitalize', 'off');
        source.setAttribute('autocorrect', 'off');
        source.setAttribute('aria-label', 'HTML permitido del bloque');
        source.setAttribute('aria-autocomplete', 'list');
        source.setAttribute('aria-expanded', 'false');

        var sourceEditor = element('div', 'blogEditor__richSourceEditor');
        sourceEditor.dataset.gutter = 'on';
        var sourceBar = element('div', 'blogEditor__richSourceBar');
        sourceBar.append(
            element('code', 'blogEditor__richSourceIcon', '</>'),
            element('strong', '', 'Fuente segura'),
            element('span', '', 'Modelo LiquidStack')
        );
        var sourceGutter = element('div', 'blogEditor__richSourceGutter');
        sourceGutter.setAttribute('aria-hidden', 'true');
        var sourceGutterLines = element(
            'pre',
            'blogEditor__richSourceGutterLines',
            '1'
        );
        sourceGutter.append(sourceGutterLines);
        var sourceStage = element('div', 'blogEditor__richSourceStage');
        var sourceHighlight = element(
            'pre',
            'blogEditor__richSourceHighlight'
        );
        sourceHighlight.setAttribute('aria-hidden', 'true');
        var sourceMeasure = element(
            'div',
            'blogEditor__richSourceMeasure'
        );
        sourceMeasure.setAttribute('aria-hidden', 'true');
        var sourceSuggestions = element(
            'div',
            'blogEditor__richSourceSuggestions'
        );
        sourceSuggestions.hidden = true;
        var sourceSuggestionList = element(
            'div',
            'blogEditor__richSourceSuggestionList'
        );
        sourceSuggestionList.id = 'blog-rich-source-suggestions-'
            + context.instance;
        sourceSuggestionList.setAttribute('role', 'listbox');
        sourceSuggestionList.setAttribute(
            'aria-label',
            'Etiquetas HTML permitidas'
        );
        sourceSuggestions.append(sourceSuggestionList);
        source.setAttribute('aria-controls', sourceSuggestionList.id);
        sourceStage.append(
            sourceHighlight,
            source,
            sourceMeasure,
            sourceSuggestions
        );
        sourceEditor.append(sourceBar, sourceGutter, sourceStage);

        var sourceMeta = element('div', 'blogEditor__richSourceMeta');
        var sourceInstructions = element(
            'p',
            '',
            'Tab y Mayús+Tab ajustan la sangría. Enter conserva el nivel. '
                + 'Ctrl+Enter crea una línea hermana tras un par HTML vacío. '
                + 'Escribe una etiqueta o pulsa Ctrl+Espacio para sugerencias. '
                + 'Pulsa Esc y después Tab para salir; Esc dos veces cierra.'
        );
        sourceInstructions.id = 'blog-rich-source-instructions-'
            + context.instance;
        var sourcePosition = element(
            'p',
            'blogEditor__richSourcePosition',
            'Línea 1, columna 1.'
        );
        sourcePosition.id = 'blog-rich-source-position-' + context.instance;
        sourcePosition.setAttribute('role', 'status');
        sourcePosition.setAttribute('aria-live', 'polite');
        sourceMeta.append(sourceInstructions, sourcePosition);
        sourcePanel.append(sourceHelp, sourceEditor, sourceMeta);

        var cssPanel = element('section', 'blogEditor__richSourcePanel');
        cssPanel.setAttribute('role', 'tabpanel');
        cssPanel.id = 'blog-rich-css-panel-' + context.instance;
        cssPanel.setAttribute('aria-labelledby', cssTab.id);
        cssTab.setAttribute('aria-controls', cssPanel.id);
        cssPanel.hidden = true;
        var cssHelp = element(
            'p',
            'blogEditor__richHelp',
            'CSS exclusivo de este m\u00f3dulo. Admite nesting y @media/@supports; no admite recursos externos.'
        );
        cssHelp.id = 'blog-rich-css-help-' + context.instance;
        var cssSource = document.createElement('textarea');
        cssSource.id = 'blog-rich-css-source-' + context.instance;
        cssSource.className = 'blogEditor__richSource';
        cssSource.spellcheck = false;
        cssSource.wrap = 'soft';
        cssSource.autocomplete = 'off';
        cssSource.setAttribute('autocapitalize', 'off');
        cssSource.setAttribute('autocorrect', 'off');
        cssSource.setAttribute('aria-label', 'CSS exclusivo del bloque');
        cssSource.setAttribute('aria-autocomplete', 'list');
        cssSource.setAttribute('aria-expanded', 'false');
        var cssEditor = element(
            'div',
            'blogEditor__richSourceEditor blogEditor__richSourceEditor--css'
        );
        cssEditor.dataset.gutter = 'on';
        var cssBar = element('div', 'blogEditor__richSourceBar');
        cssBar.append(
            element('code', 'blogEditor__richSourceIcon', '{}'),
            element('strong', '', 'CSS del m\u00f3dulo'),
            element('span', '', 'Scope aislado')
        );
        var cssGutter = element('div', 'blogEditor__richSourceGutter');
        cssGutter.setAttribute('aria-hidden', 'true');
        var cssGutterLines = element(
            'pre',
            'blogEditor__richSourceGutterLines',
            '1'
        );
        cssGutter.append(cssGutterLines);
        var cssStage = element('div', 'blogEditor__richSourceStage');
        var cssHighlight = element(
            'pre',
            'blogEditor__richSourceHighlight'
        );
        cssHighlight.setAttribute('aria-hidden', 'true');
        var cssMeasure = element(
            'div',
            'blogEditor__richSourceMeasure'
        );
        cssMeasure.setAttribute('aria-hidden', 'true');
        var cssSuggestions = element(
            'div',
            'blogEditor__richSourceSuggestions'
        );
        cssSuggestions.hidden = true;
        var cssSuggestionList = element(
            'div',
            'blogEditor__richSourceSuggestionList'
        );
        cssSuggestionList.id = 'blog-rich-css-suggestions-'
            + context.instance;
        cssSuggestionList.setAttribute('role', 'listbox');
        cssSuggestionList.setAttribute(
            'aria-label',
            'Propiedades y valores CSS permitidos'
        );
        cssSuggestions.append(cssSuggestionList);
        cssSource.setAttribute('aria-controls', cssSuggestionList.id);
        cssStage.append(
            cssHighlight,
            cssSource,
            cssMeasure,
            cssSuggestions
        );
        cssEditor.append(cssBar, cssGutter, cssStage);
        var cssMeta = element('div', 'blogEditor__richSourceMeta');
        var cssInstructions = element(
            'p',
            '',
            'Tab y May\u00fas+Tab ajustan la sangr\u00eda. Escribe una propiedad o un valor, o pulsa Ctrl+Espacio, para ver sugerencias permitidas.'
        );
        cssInstructions.id = 'blog-rich-css-instructions-' + context.instance;
        var cssPosition = element(
            'p',
            'blogEditor__richSourcePosition',
            'L\u00ednea 1, columna 1.'
        );
        cssPosition.id = 'blog-rich-css-position-' + context.instance;
        cssPosition.setAttribute('role', 'status');
        cssPosition.setAttribute('aria-live', 'polite');
        cssMeta.append(cssInstructions, cssPosition);
        cssPanel.append(cssHelp, cssEditor, cssMeta);

        var richFeedback = element(
            'p',
            'blogEditor__fieldFeedback blogEditor__richFeedback'
        );
        richFeedback.id = 'blog-rich-feedback-' + context.instance;
        richFeedback.dataset.blogRichFeedback = 'content';
        richFeedback.setAttribute('aria-live', 'polite');
        visual.setAttribute('aria-describedby', richFeedback.id);
        source.setAttribute('aria-describedby', [
            sourceHelp.id,
            sourceInstructions.id,
            sourcePosition.id,
            richFeedback.id
        ].join(' '));
        cssSource.setAttribute('aria-describedby', [
            cssHelp.id,
            cssInstructions.id,
            cssPosition.id,
            richFeedback.id
        ].join(' '));
        var modalStatus = element('p', 'blogEditor__richStatus');
        modalStatus.setAttribute('role', 'status');
        modalStatus.setAttribute('aria-live', 'polite');
        var footer = element('footer', 'blogEditor__richFooter');
        footer.append(
            richToolbarButton('Cancelar cambios', 'cancel', 'Cancelar'),
            richToolbarButton('Aplicar cambios', 'apply', 'Aplicar cambios')
        );
        shell.append(
            header,
            modeTabs,
            visualPanel,
            sourcePanel,
            cssPanel,
            richFeedback,
            modalStatus,
            footer
        );
        dialog.append(shell);
        document.body.append(dialog);

        var state = {
            context: context,
            dialog: dialog,
            title: title,
            expand: expand,
            visualTab: visualTab,
            sourceTab: sourceTab,
            cssTab: cssTab,
            modeTabs: modeTabs,
            visualPanel: visualPanel,
            sourcePanel: sourcePanel,
            cssPanel: cssPanel,
            toolbar: toolbar,
            palettes: {
                color: color,
                background: background
            },
            rgbaControls: {
                color: textRgba,
                background: backgroundRgba
            },
            alignmentButtons: alignmentButtons,
            flowListStyles: flowListStyles,
            pendingFlowListIndexes: null,
            pendingFlowListRevision: -1,
            blockType: blockType,
            flowBlockButtons: {
                quote: quoteButton,
                callout: calloutButton
            },
            flowMetadata: {
                panel: flowMetadataPanel,
                context: flowMetadataContext,
                groups: {
                    quote: quoteMetadataGroup,
                    callout: calloutMetadataGroup
                },
                author: flowQuoteAuthor,
                source: flowQuoteSource,
                quotePreset: flowQuotePreset,
                tone: flowCalloutTone,
                index: -1
            },
            visual: visual,
            source: source,
            sourceHighlight: sourceHighlight,
            sourceMeasure: sourceMeasure,
            sourceHelp: sourceHelp,
            sourceEditor: sourceEditor,
            sourceGutter: sourceGutter,
            sourceGutterLines: sourceGutterLines,
            sourceSuggestions: sourceSuggestions,
            sourceSuggestionList: sourceSuggestionList,
            sourcePosition: sourcePosition,
            sourceSuggestionResult: { start: 0, query: '', items: [] },
            sourceSuggestionIndex: -1,
            sourceLineCount: 1,
            sourceGutterSignature: '',
            sourceGutterFrame: 0,
            sourceTabExitArmed: false,
            cssSource: cssSource,
            cssEditor: cssEditor,
            cssGutter: cssGutter,
            cssGutterLines: cssGutterLines,
            cssHighlight: cssHighlight,
            cssMeasure: cssMeasure,
            cssPosition: cssPosition,
            cssSuggestions: cssSuggestions,
            cssSuggestionList: cssSuggestionList,
            cssSuggestionResult: {
                start: 0,
                query: '',
                kind: '',
                items: []
            },
            cssSuggestionIndex: -1,
            cssLineCount: 1,
            cssGutterSignature: '',
            cssGutterFrame: 0,
            sourceResizeObserver: null,
            cssTabExitArmed: false,
            richFeedback: richFeedback,
            linkPanel: linkPanel,
            linkHref: linkHref,
            linkTitle: linkTitle,
            linkTarget: linkTarget,
            listPanel: listPanel,
            listTextarea: listTextarea,
            modalStatus: modalStatus,
            draft: [],
            block: null,
            listMode: false,
            legacyListMode: false,
            requiresLeadingHeading: false,
            listDraft: [],
            listOrdered: false,
            textFlowMode: false,
            advancedMode: false,
            advancedVisualEditable: false,
            advancedVisualStructureLocked: false,
            wasAdvanced: false,
            advancedSourceEnabled: false,
            sourceOnlyMode: false,
            advancedHtmlDraft: '',
            cssDraft: '',
            sourceTouched: false,
            cssTouched: false,
            visualTouched: false,
            advancedVisualScope: 'rich-visual-' + context.instance,
            advancedVisualStyle: null,
            advancedVisualStyleSignature: '',
            advancedVisualProjectedAttrs: new WeakMap(),
            caretExitParagraph: null,
            advancedVisualBaseline: '[]',
            advancedVisualRawBaseline: '',
            sourceBaseline: '',
            cssBaseline: '',
            sourceHistory: richCodeHistory(),
            cssHistory: richCodeHistory(),
            flowDraft: [],
            flowSelection: null,
            flowSelections: [],
            headingMode: false,
            headingLevel: null,
            allowedHeadingLevels: [],
            textAlign: 'start',
            allowBreak: true,
            mode: 'visual',
            selection: null,
            documentRevision: 0,
            selectionRevision: -1,
            toolbarSelectedFlowIndexes: [],
            toolbarRefreshFrame: 0,
            toolbarRefreshEpoch: 0,
            returnFocus: null,
            baseline: '[]',
            pristineBaseline: '[]',
            baselineHtml: '',
            inputTouched: false,
            active: false
        };
        context.richEditor = state;

        dialog.addEventListener('cancel', function (event) {
            event.preventDefault();
            richCloseModal(state);
        });
        dialog.addEventListener('close', function () {
            richCancelToolbarRefresh(state);
            richClearAdvancedVisualStyle(state);
            state.active = false;
            if (
                state.returnFocus instanceof HTMLElement
                && state.returnFocus.isConnected
            ) {
                state.returnFocus.focus();
            }
            state.returnFocus = null;
        });
        dialog.addEventListener('pointerdown', function (event) {
            if (!event.target.closest('[data-blog-rich-list-style]')) {
                state.pendingFlowListIndexes = null;
                state.pendingFlowListRevision = -1;
            }
            richCloseBlockTypePickerOnPointerDown(state, event.target);
            richClosePalettesOnPointerDown(state, event.target);
        });
        dialog.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-blog-rich-action]');
            if (!(trigger instanceof HTMLButtonElement)) {
                return;
            }
            richHandleAction(
                state,
                trigger.dataset.blogRichAction || '',
                trigger
            );
        });
        toolbar.addEventListener('mousedown', function (event) {
            var button = event.target.closest('button');
            var activePalette = document.activeElement
                && typeof document.activeElement.closest === 'function'
                ? document.activeElement.closest('.blogEditor__richPalette')
                : null;
            if (
                button
                && !(
                    activePalette
                    && !event.target.closest('.blogEditor__richPalette')
                )
            ) {
                event.preventDefault();
            }
        });
        toolbar.addEventListener('pointerdown', function (event) {
            state.pendingFlowListIndexes = null;
            state.pendingFlowListRevision = -1;
            richCapturePendingFlowListIndexes(state, event.target);
        });
        toolbar.addEventListener('focusin', function (event) {
            var listStyleSelect = event.target.closest(
                '[data-blog-rich-list-style]'
            );
            if (!(listStyleSelect instanceof HTMLSelectElement)) {
                state.pendingFlowListIndexes = null;
                state.pendingFlowListRevision = -1;
                return;
            }
            if (!Array.isArray(state.pendingFlowListIndexes)) {
                richCapturePendingFlowListIndexes(state, listStyleSelect);
            }
        });
        toolbar.addEventListener('change', function (event) {
            var listStyleSelect = event.target.closest(
                '[data-blog-rich-list-style]'
            );
            if (listStyleSelect instanceof HTMLSelectElement) {
                richApplyFlowListStyle(
                    state,
                    listStyleSelect.dataset.blogRichListStyle === 'ordered',
                    listStyleSelect.value,
                    listStyleSelect
                );
                return;
            }
            var select = event.target.closest('[data-blog-rich-format]');
            if (!(select instanceof HTMLSelectElement)) {
                return;
            }
            richApplyGroup(
                state,
                select.dataset.blogRichFormat || '',
                select.value
            );
        });
        toolbar.addEventListener('keydown', function (event) {
            richHandleBlockTypePickerKeydown(state, event);
            richHandlePaletteKeydown(state, event);
        });
        toolbar.addEventListener('focusout', function (event) {
            richCloseBlockTypePickerOnFocusLeave(
                state,
                event.relatedTarget
            );
            richClosePalettesOnFocusLeave(
                state,
                event.relatedTarget
            );
        });
        flowMetadataPanel.addEventListener('input', function (event) {
            var control = event.target.closest(
                '[data-blog-rich-flow-metadata]'
            );
            if (control instanceof HTMLInputElement) {
                control.removeAttribute('aria-invalid');
            }
        });
        flowMetadataPanel.addEventListener('change', function (event) {
            var control = event.target.closest(
                '[data-blog-rich-flow-metadata]'
            );
            if (
                control instanceof HTMLInputElement
                || control instanceof HTMLSelectElement
            ) {
                richCommitFlowMetadataControl(state, control);
            }
        });
        modeTabs.addEventListener('keydown', function (event) {
            var tabs = [visualTab, sourceTab, cssTab].filter(function (tab) {
                return !tab.hidden;
            });
            var current = tabs.indexOf(event.target);
            if (current < 0) {
                return;
            }
            var next = null;
            if (event.key === 'ArrowRight') {
                next = (current + 1) % tabs.length;
            } else if (event.key === 'ArrowLeft') {
                next = (current - 1 + tabs.length) % tabs.length;
            } else if (event.key === 'Home') {
                next = 0;
            } else if (event.key === 'End') {
                next = tabs.length - 1;
            }
            if (next === null) {
                return;
            }
            event.preventDefault();
            richSetMode(
                state,
                tabs[next] === visualTab
                    ? 'visual'
                    : (tabs[next] === sourceTab ? 'source' : 'css')
            );
            tabs[next].focus();
        });
        visual.addEventListener('mouseup', function () {
            richCaptureSelection(state);
        });
        visual.addEventListener('pointerdown', function () {
            richClosePalettes(state, '');
        });
        visual.addEventListener('focus', function () {
            richClosePalettes(state, '');
        });
        visual.addEventListener('keyup', function () {
            richCaptureSelection(state);
        });
        visual.addEventListener('input', function () {
            state.inputTouched = true;
            state.visualTouched = true;
            richResolveCaretExitAfterInput(state);
            if (richSyncVisual(state)) {
                richCaptureSelection(state);
                richStatus(state, '', false);
            }
        });
        source.addEventListener('compositionstart', function () {
            state.sourceHistory.composition = richCodeSnapshot(source);
        });
        source.addEventListener('compositionend', function () {
            richCodeHistoryCommitComposition(source, state.sourceHistory);
        });
        source.addEventListener('beforeinput', function (event) {
            var direction = richCodeHistoryBeforeInput(
                source,
                state.sourceHistory,
                event
            );
            if (direction === null) {
                return;
            }
            state.inputTouched = true;
            state.sourceTouched = richCodeTouched(
                source,
                state.sourceBaseline
            );
            richSourceHideSuggestions(state);
            richSourceSyncChrome(state, false);
            richUpdateLimitFeedback(state);
            richRefreshAdvancedValidationStatus(state, 'html');
        });
        source.addEventListener('input', function (event) {
            richCodeHistoryCommitInput(source, state.sourceHistory, event);
            state.inputTouched = true;
            state.sourceTouched = richCodeTouched(
                source,
                state.sourceBaseline
            );
            richUpdateLimitFeedback(state);
            richSourceSyncChrome(state, true);
            richRefreshAdvancedValidationStatus(state, 'html');
        });
        source.addEventListener('scroll', function () {
            state.sourceGutter.scrollTop = source.scrollTop;
            state.source.scrollLeft = 0;
            richSyncHighlight(state.source, state.sourceHighlight, 'html');
        });
        source.addEventListener('click', function () {
            richSourceSyncChrome(state, true);
        });
        source.addEventListener('select', function () {
            richSourceSyncChrome(state, true);
        });
        source.addEventListener('keyup', function (event) {
            if (
                ['Tab', 'Enter', 'ArrowDown', 'ArrowUp', 'Escape'].includes(
                    event.key
                )
                || (
                    (event.ctrlKey || event.metaKey)
                    && event.key === ' '
                )
            ) {
                richSourceUpdatePosition(state);
                return;
            }
            richSourceSyncChrome(state, true);
        });
        source.addEventListener('keydown', function (event) {
            if (event.isComposing || event.keyCode === 229) {
                return;
            }
            var sourceHistoryDirection = richCodeHistoryDirection(event);
            if (sourceHistoryDirection !== null) {
                event.preventDefault();
                if (richCodeHistoryStep(
                    source,
                    state.sourceHistory,
                    sourceHistoryDirection
                )) {
                    state.inputTouched = true;
                    state.sourceTouched = richCodeTouched(
                        source,
                        state.sourceBaseline
                    );
                    richSourceHideSuggestions(state);
                    richSourceSyncChrome(state, false);
                    richUpdateLimitFeedback(state);
                    richRefreshAdvancedValidationStatus(state, 'html');
                }
                return;
            }
            var suggestionsOpen = !state.sourceSuggestions.hidden;
            var suggestionAction = richSuggestionKeyboardAction(
                event.key,
                suggestionsOpen,
                event.shiftKey,
                event.ctrlKey,
                event.metaKey,
                event.altKey
            );
            if (['next', 'previous'].includes(suggestionAction)) {
                event.preventDefault();
                richSourceSetSuggestionIndex(
                    state,
                    state.sourceSuggestionIndex
                        + (suggestionAction === 'next' ? 1 : -1)
                );
                return;
            }
            if (event.key === 'Escape') {
                if (state.sourceTabExitArmed && !suggestionsOpen) {
                    state.sourceTabExitArmed = false;
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                if (suggestionsOpen) {
                    richSourceHideSuggestions(state);
                } else {
                    state.sourceTabExitArmed = true;
                    richStatus(
                        state,
                        'Tab liberado. Púlsalo ahora para salir del editor.',
                        false
                    );
                }
                return;
            }
            if (event.key === 'Tab') {
                if (state.sourceTabExitArmed) {
                    state.sourceTabExitArmed = false;
                    richSourceHideSuggestions(state);
                    return;
                }
                if (suggestionAction === 'accept') {
                    event.preventDefault();
                    richSourceApplySuggestion(
                        state,
                        state.sourceSuggestionIndex
                    );
                    return;
                }
                event.preventDefault();
                richSourceApplyEdit(state, richSourceIndentEdit(
                    source.value,
                    source.selectionStart || 0,
                    source.selectionEnd || 0,
                    event.shiftKey
                ));
                return;
            }
            state.sourceTabExitArmed = false;
            if (
                event.key === 'Backspace'
                && !richCodeHasCommandModifier(event)
            ) {
                var pairDelete = richSourcePairDeleteEdit(
                    source.value,
                    source.selectionStart || 0,
                    source.selectionEnd || 0
                );
                if (pairDelete !== null) {
                    event.preventDefault();
                    richSourceApplyEdit(state, pairDelete);
                    return;
                }
            }
            if (
                event.key === 'Enter'
                && (event.ctrlKey || event.metaKey)
                && !event.altKey
            ) {
                var pairExit = richSourceExitPairEdit(
                    source.value,
                    source.selectionStart || 0,
                    source.selectionEnd || 0
                );
                if (pairExit !== null) {
                    event.preventDefault();
                    richSourceApplyEdit(state, pairExit);
                    return;
                }
            }
            if (suggestionAction === 'accept') {
                event.preventDefault();
                richSourceApplySuggestion(
                    state,
                    state.sourceSuggestionIndex
                );
                return;
            }
            if (event.key === 'Enter') {
                event.preventDefault();
                richSourceApplyEdit(state, richSourceEnterEdit(
                    source.value,
                    source.selectionStart || 0,
                    source.selectionEnd || 0,
                    state.textFlowMode,
                    state.allowBreak,
                    state.allowedHeadingLevels,
                    state.advancedSourceEnabled
                ));
                return;
            }
            if (
                ['{', '}', '[', ']', '(', ')', '"', "'"].includes(event.key)
                && !richCodeHasCommandModifier(event)
            ) {
                var pairEdit = richSourcePairEdit(
                    source.value,
                    source.selectionStart || 0,
                    source.selectionEnd || 0,
                    event.key
                );
                if (pairEdit !== null) {
                    event.preventDefault();
                    richSourceApplyEdit(state, pairEdit);
                    return;
                }
            }
            if (
                (event.ctrlKey || event.metaKey)
                && !event.altKey
                && event.key === ' '
            ) {
                event.preventDefault();
                richSourceRefreshSuggestions(state, true);
                return;
            }
            if (
                event.key === '>'
                && !richCodeHasCommandModifier(event)
            ) {
                var autoClose = richSourceAutoCloseEdit(
                    source.value,
                    source.selectionStart || 0,
                    source.selectionEnd || 0,
                    state.textFlowMode,
                    state.allowBreak,
                    state.allowedHeadingLevels,
                    state.advancedSourceEnabled
                );
                if (autoClose !== null) {
                    event.preventDefault();
                    richSourceApplyEdit(state, autoClose);
                }
            }
        });
        source.addEventListener('blur', function () {
            state.sourceTabExitArmed = false;
            richSourceHideSuggestions(state);
        });
        sourceSuggestionList.addEventListener('mousedown', function (event) {
            if (event.target.closest('[data-blog-rich-source-suggestion]')) {
                event.preventDefault();
            }
        });
        sourceSuggestionList.addEventListener('click', function (event) {
            var option = event.target.closest(
                '[data-blog-rich-source-suggestion]'
            );
            if (!(option instanceof HTMLButtonElement)) {
                return;
            }
            richSourceApplySuggestion(
                state,
                Number.parseInt(
                    option.dataset.blogRichSourceSuggestion || '',
                    10
                )
            );
            source.focus();
        });
        cssSource.addEventListener('compositionstart', function () {
            state.cssHistory.composition = richCodeSnapshot(cssSource);
        });
        cssSource.addEventListener('compositionend', function () {
            richCodeHistoryCommitComposition(cssSource, state.cssHistory);
        });
        cssSource.addEventListener('beforeinput', function (event) {
            var direction = richCodeHistoryBeforeInput(
                cssSource,
                state.cssHistory,
                event
            );
            if (direction === null) {
                return;
            }
            state.inputTouched = true;
            state.cssTouched = richCodeTouched(
                cssSource,
                state.cssBaseline
            );
            richCssSourceSyncChrome(state);
            richUpdateLimitFeedback(state);
            richRefreshAdvancedValidationStatus(state, 'css');
        });
        cssSource.addEventListener('input', function (event) {
            richCodeHistoryCommitInput(cssSource, state.cssHistory, event);
            state.inputTouched = true;
            state.cssTouched = richCodeTouched(
                cssSource,
                state.cssBaseline
            );
            richCssSourceSyncChrome(state, true);
            richUpdateLimitFeedback(state);
            richRefreshAdvancedValidationStatus(state, 'css');
        });
        cssSource.addEventListener('scroll', function () {
            state.cssSource.scrollLeft = 0;
            richCssSourceSyncChrome(state);
        });
        ['click', 'select'].forEach(function (eventName) {
            cssSource.addEventListener(eventName, function () {
                richCssSourceSyncChrome(state, true);
            });
        });
        cssSource.addEventListener('keyup', function (event) {
            if (
                ['Tab', 'Enter', 'ArrowDown', 'ArrowUp', 'Escape'].includes(
                    event.key
                )
                || (
                    (event.ctrlKey || event.metaKey)
                    && event.key === ' '
                )
            ) {
                richCssSourceSyncChrome(state, false);
                return;
            }
            richCssSourceSyncChrome(state, true);
        });
        cssSource.addEventListener('keydown', function (event) {
            if (event.isComposing || event.keyCode === 229) {
                return;
            }
            var cssHistoryDirection = richCodeHistoryDirection(event);
            if (cssHistoryDirection !== null) {
                event.preventDefault();
                if (richCodeHistoryStep(
                    cssSource,
                    state.cssHistory,
                    cssHistoryDirection
                )) {
                    state.inputTouched = true;
                    state.cssTouched = richCodeTouched(
                        cssSource,
                        state.cssBaseline
                    );
                    richCssHideSuggestions(state);
                    richCssSourceSyncChrome(state);
                    richUpdateLimitFeedback(state);
                    richRefreshAdvancedValidationStatus(state, 'css');
                }
                return;
            }
            var cssSuggestionsOpen = !state.cssSuggestions.hidden;
            var cssSuggestionAction = richSuggestionKeyboardAction(
                event.key,
                cssSuggestionsOpen,
                event.shiftKey,
                event.ctrlKey,
                event.metaKey,
                event.altKey
            );
            if (['next', 'previous'].includes(cssSuggestionAction)) {
                event.preventDefault();
                richCssSetSuggestionIndex(
                    state,
                    state.cssSuggestionIndex
                        + (cssSuggestionAction === 'next' ? 1 : -1)
                );
                return;
            }
            if (event.key === 'Escape') {
                if (cssSuggestionsOpen) {
                    event.preventDefault();
                    event.stopPropagation();
                    richCssHideSuggestions(state);
                    richCssSourceSyncChrome(state, false);
                    return;
                }
                if (state.cssTabExitArmed) {
                    state.cssTabExitArmed = false;
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                state.cssTabExitArmed = true;
                richStatus(
                    state,
                    'Tab liberado. P\u00falsalo ahora para salir del editor.',
                    false
                );
                return;
            }
            if (event.key === 'Tab') {
                if (state.cssTabExitArmed) {
                    state.cssTabExitArmed = false;
                    richCssHideSuggestions(state);
                    return;
                }
                if (cssSuggestionAction === 'accept') {
                    event.preventDefault();
                    richCssApplySuggestion(
                        state,
                        state.cssSuggestionIndex
                    );
                    return;
                }
                event.preventDefault();
                richCssSourceApplyEdit(state, richSourceIndentEdit(
                    cssSource.value,
                    cssSource.selectionStart || 0,
                    cssSource.selectionEnd || 0,
                    event.shiftKey
                ));
                return;
            }
            state.cssTabExitArmed = false;
            if (
                event.key === 'Backspace'
                && !richCodeHasCommandModifier(event)
            ) {
                var cssPairDelete = richSourcePairDeleteEdit(
                    cssSource.value,
                    cssSource.selectionStart || 0,
                    cssSource.selectionEnd || 0
                );
                if (cssPairDelete !== null) {
                    event.preventDefault();
                    richCssSourceApplyEdit(state, cssPairDelete);
                    return;
                }
            }
            if (cssSuggestionAction === 'accept') {
                event.preventDefault();
                richCssApplySuggestion(
                    state,
                    state.cssSuggestionIndex
                );
                return;
            }
            if (event.key === 'Enter') {
                event.preventDefault();
                richCssSourceApplyEdit(state, richCodeEnterEdit(
                    cssSource.value,
                    cssSource.selectionStart || 0,
                    cssSource.selectionEnd || 0
                ));
                return;
            }
            if (
                (event.ctrlKey || event.metaKey)
                && !event.altKey
                && event.key === ' '
            ) {
                event.preventDefault();
                richCssRefreshSuggestions(state, true);
                richCssSourceSyncChrome(state, false);
                return;
            }
            if (
                ['{', '}', '[', ']', '(', ')', '"', "'"].includes(event.key)
                && !richCodeHasCommandModifier(event)
            ) {
                var cssPairEdit = richSourcePairEdit(
                    cssSource.value,
                    cssSource.selectionStart || 0,
                    cssSource.selectionEnd || 0,
                    event.key
                );
                if (cssPairEdit !== null) {
                    event.preventDefault();
                    richCssSourceApplyEdit(state, cssPairEdit);
                }
            }
        });
        cssSource.addEventListener('blur', function () {
            state.cssTabExitArmed = false;
            richCssHideSuggestions(state);
        });
        cssSuggestionList.addEventListener('mousedown', function (event) {
            if (event.target.closest('[data-blog-rich-css-suggestion]')) {
                event.preventDefault();
            }
        });
        cssSuggestionList.addEventListener('click', function (event) {
            var option = event.target.closest(
                '[data-blog-rich-css-suggestion]'
            );
            if (!(option instanceof HTMLButtonElement)) {
                return;
            }
            richCssApplySuggestion(
                state,
                Number.parseInt(
                    option.dataset.blogRichCssSuggestion || '',
                    10
                )
            );
            cssSource.focus();
        });
        listTextarea.addEventListener('input', function () {
            state.inputTouched = true;
            richCommitListDraft(state);
            richUpdateDirty(state);
            richUpdateLimitFeedback(state);
        });
        visual.addEventListener('beforeinput', function (event) {
            if (event.inputType === 'insertFromDrop') {
                event.preventDefault();
                return;
            }
            if (
                event.inputType === 'insertText'
                && !event.isComposing
                && richReplaceRootBreakWithParagraph(
                    state,
                    event.data || '',
                    window.getSelection()
                )
            ) {
                event.preventDefault();
                state.inputTouched = true;
                state.visualTouched = true;
                if (richSyncVisual(state)) {
                    richCaptureSelection(state);
                    richStatus(state, '', false);
                }
                return;
            }
            if (
                !['insertParagraph', 'insertLineBreak'].includes(event.inputType)
            ) {
                return;
            }
            event.preventDefault();
            if (
                event.inputType === 'insertParagraph'
                && state.advancedMode
                && state.advancedVisualStructureLocked
                && !richAdvancedVisualCanSplitSelection(state)
            ) {
                richStatus(
                    state,
                    'Edita esta lista estructurada desde HTML para conservar sus atributos.',
                    true
                );
                return;
            }
            state.visualTouched = true;
            if (state.textFlowMode && event.inputType === 'insertParagraph') {
                richInsertFlowParagraph(state);
            } else {
                richInsertPlainText(state, state.allowBreak ? '\n' : ' ');
            }
            richSyncVisual(state);
            richCaptureSelection(state);
        });
        visual.addEventListener('drop', function (event) {
            event.preventDefault();
            var text = event.dataTransfer
                ? event.dataTransfer.getData('text/plain')
                : '';
            if (text !== '') {
                state.visualTouched = true;
                if (state.textFlowMode) {
                    richInsertFlowText(state, text);
                } else {
                    richInsertPlainText(state, text);
                }
                richSyncVisual(state);
                richCaptureSelection(state);
            }
        });
        visual.addEventListener('paste', function (event) {
            event.preventDefault();
            var text = event.clipboardData
                ? event.clipboardData.getData('text/plain')
                : '';
            state.visualTouched = true;
            if (state.textFlowMode) {
                richReplaceRootBreakWithParagraph(
                    state,
                    '',
                    window.getSelection()
                );
                richInsertFlowText(state, text);
            } else {
                richInsertPlainText(state, text);
            }
            richSyncVisual(state);
            richCaptureSelection(state);
        });
        visual.addEventListener('keydown', function (event) {
            if (
                event.key === 'Enter'
                && !event.altKey
                && !event.isComposing
                && event.keyCode !== 229
            ) {
                event.preventDefault();
                if (state.context.readOnly) {
                    return;
                }
                var lineBreak = event.ctrlKey
                    || event.metaKey
                    || event.shiftKey;
                if (
                    !lineBreak
                    && state.advancedMode
                    && state.advancedVisualStructureLocked
                    && !richAdvancedVisualCanSplitSelection(state)
                ) {
                    richStatus(
                        state,
                        'Edita esta estructura desde HTML para conservar sus atributos; Ctrl+Enter añade un salto dentro del bloque.',
                        true
                    );
                    return;
                }
                state.inputTouched = true;
                state.visualTouched = true;
                var inserted = state.textFlowMode
                    ? (
                        lineBreak
                            ? richInsertFlowLineBreak(state)
                            : richInsertFlowParagraph(state)
                    )
                    : (function () {
                        richInsertPlainText(
                            state,
                            state.allowBreak ? '\n' : ' '
                        );
                        return true;
                    }());
                if (inserted) {
                    richSyncVisual(state);
                    richCaptureSelection(state);
                    richStatus(state, '', false);
                }
                return;
            }
            if (!(event.ctrlKey || event.metaKey)) {
                return;
            }
            var action = {
                b: 'strong',
                i: 'em',
                u: 'underline',
                k: 'link'
            }[event.key.toLowerCase()];
            if (!action) {
                return;
            }
            event.preventDefault();
            richHandleAction(state, action);
        });
        document.addEventListener('selectionchange', function () {
            if (state.active && state.mode === 'visual') {
                richCaptureSelection(state);
            }
        });
        var syncGuttersOnResize = function () {
            if (!state.active) {
                return;
            }
            if (!state.sourcePanel.hidden) {
                richScheduleSourceGutter(state, 'source');
            }
            if (!state.cssPanel.hidden) {
                richScheduleSourceGutter(state, 'css');
            }
        };
        if (typeof window.ResizeObserver === 'function') {
            state.sourceResizeObserver = new window.ResizeObserver(
                syncGuttersOnResize
            );
            state.sourceResizeObserver.observe(sourceStage);
            state.sourceResizeObserver.observe(cssStage);
        } else if (typeof window.addEventListener === 'function') {
            window.addEventListener('resize', syncGuttersOnResize);
        }

        return state;
    }

    function richClone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function richInlinePlainText(content) {
        return richInlineTextValue(content).trim();
    }

    function richInlineTextValue(content) {
        return content.map(function (node) {
            return node.type === 'break' ? ' ' : (node.text || '');
        }).join('');
    }

    function richEditablePlainText(state) {
        if (state.listMode) {
            return listItemsAsLines(state.listDraft);
        }
        if (state.mode === 'css') {
            return state.cssSource.value;
        }
        if (state.mode === 'source') {
            try {
                if (
                    state.advancedMode
                    || state.wasAdvanced
                    || !richSourceUsesStandardParagraph(
                        state,
                        state.source.value
                    )
                ) {
                    return richAdvancedPlainText(
                        state.source.value,
                        richStatePolicy(state)
                    );
                }
                if (state.textFlowMode) {
                    return inlinePlainText(
                        richParseTextFlowHtml(state.source.value)
                    );
                }
                if (state.headingMode) {
                    return richInlineTextValue(richParseHeadingHtml(
                        state.source.value,
                        state.allowedHeadingLevels
                    ).content);
                }
                return richInlineTextValue(
                    richParseHtml(state.source.value, state.allowBreak)
                );
            } catch (error) {
                return state.source.value;
            }
        }
        if (state.advancedMode) {
            try {
                return richAdvancedPlainText(
                    state.advancedHtmlDraft,
                    richStatePolicy(state)
                );
            } catch (error) {
                return state.advancedHtmlDraft;
            }
        }
        if (state.textFlowMode) {
            return inlinePlainText(state.flowDraft);
        }
        return richInlineTextValue(state.draft);
    }

    function richUpdateLimitFeedback(state) {
        var value = richEditablePlainText(state);
        var field = state.mode === 'css'
            ? 'css'
            : (
                state.advancedMode
                || (
                    state.mode === 'source'
                    && (
                    state.advancedMode
                    || state.wasAdvanced
                    || !richSourceUsesStandardParagraph(
                        state,
                        state.source.value
                    )
                    )
                ) ? (state.sourceOnlyMode ? 'embed_html' : 'html')
                    : (state.textFlowMode ? 'text_content' : 'content')
            );
        if (['html', 'embed_html'].includes(field)) {
            value = state.mode === 'source'
                ? state.source.value
                : state.advancedHtmlDraft;
        }
        var limit = state.context.technicalLimits.block[field].bytes;
        var byteCount = bytes(value);
        var invalid = byteCount > limit;
        var feedback = characters(value)
            + ' caracteres \u00b7 ' + byteCount + ' de ' + limit
            + ' bytes (' + field.toUpperCase() + ').';
        if (invalid) {
            feedback +=
                ' Supera el l\u00edmite t\u00e9cnico; el contenido se conserva en este editor.';
        }
        richSetTextContent(state.richFeedback, feedback);
        richSetDatasetValue(
            state.richFeedback,
            'state',
            invalid ? 'error' : 'ok'
        );
        [state.visual, state.source, state.cssSource].forEach(function (control) {
            richSetAttributeValue(
                control,
                'aria-invalid',
                invalid ? 'true' : 'false'
            );
        });
        return !invalid;
    }

    function richStructuredFingerprint(state) {
        if (state.sourceOnlyMode) {
            return JSON.stringify({
                html: state.advancedHtmlDraft,
                css: state.cssDraft
            });
        }
        return JSON.stringify(
            state.advancedMode ? {
                html: state.advancedHtmlDraft,
                css: state.cssDraft,
                text_align: state.textAlign
            } : (state.listMode ? {
                ordered: state.listOrdered,
                items: state.listDraft,
                text_align: state.textAlign
            } : (state.textFlowMode ? {
                content: state.flowDraft,
                text_align: state.textAlign
            } : (state.headingMode ? {
                level: state.headingLevel,
                content: state.draft,
                text_align: state.textAlign
            } : {
                content: state.draft,
                text_align: state.textAlign
            })))
        );
    }

    function richPristineFingerprint(state) {
        if (state.advancedMode) {
            return richStructuredFingerprint(state);
        }
        if (!state.textFlowMode) {
            return richStructuredFingerprint(state);
        }
        return JSON.stringify({
            content: state.flowDraft.map(function (flowNode) {
                if (flowNode.type === 'break') {
                    return { type: 'break' };
                }
                if (flowNode.type !== 'list') {
                    var normalized = {
                        type: flowNode.type,
                        content: richMergeContent(
                            richClone(flowNode.content || [])
                        )
                    };
                    if (flowNode.type === 'heading') {
                        normalized.level = flowNode.level;
                    }
                    return normalized;
                }
                return {
                    type: 'list',
                    ordered: flowNode.ordered === true,
                    items: (flowNode.items || []).map(function (item) {
                        return {
                            content: richMergeContent(
                                richClone(item.content || [])
                            )
                        };
                    })
                };
            }),
            text_align: state.textAlign
        });
    }

    function richUpdateDirty(state) {
        state.inputTouched = richStructuredFingerprint(state)
            !== state.baseline;
    }

    function richLegacyListEditMode(state) {
        if (!state.legacyListMode) {
            return null;
        }
        if (richPristineFingerprint(state) === state.pristineBaseline) {
            return 'noop';
        }
        return richFlowCanStayLegacyList(state.flowDraft)
            ? 'list'
            : 'paragraph';
    }

    function richCommitListDraft(state) {
        if (!state.listMode) {
            return;
        }
        state.listDraft = listItemsFromLines(
            state.context,
            state.listDraft,
            state.listTextarea.value
        );
    }

    function richRefreshListPanel(state) {
        state.listPanel.hidden = !state.listMode;
        if (!state.listMode) {
            return;
        }
        state.listTextarea.disabled = state.context.readOnly;
        if (document.activeElement !== state.listTextarea) {
            state.listTextarea.value = listItemsAsLines(state.listDraft);
        }
    }

    function richCommitCurrentInput(state) {
        try {
            if (state.listMode) {
                richCommitListDraft(state);
                richUpdateDirty(state);
                return true;
            }
            if (state.mode === 'css') {
                richCommitAdvancedEditors(state);
                richUpdateDirty(state);
                return true;
            }
            if (state.mode === 'source') {
                richParseEditorHtml(state, state.source.value);
                richUpdateDirty(state);
                return true;
            }
            if (state.advancedMode) {
                if (state.advancedVisualEditable) {
                    return richSyncVisual(state);
                }
                richUpdateDirty(state);
                return true;
            }
            return richSyncVisual(state);
        } catch (error) {
            richReportAdvancedValidationFailure(state, error, state.mode);
            return false;
        }
    }

    function richBlockTypePickerOptions(state) {
        return Object.keys(state.blockType.options).map(function (value) {
            return state.blockType.options[value];
        }).filter(function (option) {
            return option instanceof HTMLButtonElement && !option.disabled;
        });
    }

    function richCloseBlockTypePicker(state, returnFocus) {
        if (!state || !state.blockType) {
            return false;
        }
        var picker = state.blockType;
        var wasOpen = !picker.menu.hidden;
        richSetDomProperty(picker.menu, 'hidden', true);
        richSetAttributeValue(picker.trigger, 'aria-expanded', 'false');
        Object.keys(picker.options).forEach(function (value) {
            richSetDomProperty(picker.options[value], 'tabIndex', -1);
        });
        if (
            returnFocus
            && wasOpen
            && !picker.trigger.disabled
            && typeof picker.trigger.focus === 'function'
        ) {
            picker.trigger.focus();
        }
        return wasOpen;
    }

    function richOpenBlockTypePicker(state, focusTarget) {
        var picker = state.blockType;
        if (!picker || picker.trigger.disabled || picker.root.hidden) {
            return false;
        }
        richClosePalettes(state, '');
        picker.menu.hidden = false;
        picker.trigger.setAttribute('aria-expanded', 'true');
        var options = richBlockTypePickerOptions(state);
        if (options.length === 0) {
            richCloseBlockTypePicker(state, false);
            return false;
        }
        var target = focusTarget === 'first'
            ? options[0]
            : (focusTarget === 'last'
                ? options[options.length - 1]
                : options.find(function (option) {
                    return option.getAttribute('aria-selected') === 'true';
                }) || options[0]);
        target.tabIndex = 0;
        target.focus();
        return true;
    }

    function richToggleBlockTypePicker(state) {
        if (state.blockType.menu.hidden) {
            richOpenBlockTypePicker(state, 'selected');
            return;
        }
        richCloseBlockTypePicker(state, true);
    }

    function richBlockTypePickerOwnsTarget(state, target) {
        return Boolean(
            state
            && state.blockType
            && target
            && typeof state.blockType.root.contains === 'function'
            && state.blockType.root.contains(target)
        );
    }

    function richCloseBlockTypePickerOnPointerDown(state, target) {
        if (richBlockTypePickerOwnsTarget(state, target)) {
            return false;
        }
        var toolbarButton = target instanceof Element
            ? target.closest('.blogEditor__richToolbar button')
            : null;
        var returnFocus = !state.blockType.menu.hidden
            && (
                !richPointerTargetCanReceiveFocus(target)
                || toolbarButton instanceof HTMLButtonElement
            );
        return richCloseBlockTypePicker(state, returnFocus);
    }

    function richCloseBlockTypePickerOnFocusLeave(state, nextTarget) {
        if (richBlockTypePickerOwnsTarget(state, nextTarget)) {
            return false;
        }
        return richCloseBlockTypePicker(state, false);
    }

    function richHandleBlockTypePickerKeydown(state, event) {
        var picker = state.blockType;
        var trigger = event.target.closest(
            '.blogEditor__richBlockTypeTrigger'
        );
        if (trigger instanceof HTMLButtonElement) {
            if (['ArrowDown', 'Home'].includes(event.key)) {
                event.preventDefault();
                richOpenBlockTypePicker(state, 'first');
            } else if (['ArrowUp', 'End'].includes(event.key)) {
                event.preventDefault();
                richOpenBlockTypePicker(state, 'last');
            } else if (event.key === 'Escape' && !picker.menu.hidden) {
                event.preventDefault();
                richCloseBlockTypePicker(state, true);
            }
            return;
        }
        var option = event.target.closest(
            '.blogEditor__richBlockTypeOption'
        );
        if (!(option instanceof HTMLButtonElement)) {
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            richCloseBlockTypePicker(state, true);
            return;
        }
        if (['Enter', ' ', 'Spacebar'].includes(event.key)) {
            event.preventDefault();
            option.click();
            return;
        }
        var options = richBlockTypePickerOptions(state);
        var current = options.indexOf(option);
        var next = null;
        if (event.key === 'ArrowDown') {
            next = (current + 1) % options.length;
        } else if (event.key === 'ArrowUp') {
            next = (current - 1 + options.length) % options.length;
        } else if (event.key === 'Home') {
            next = 0;
        } else if (event.key === 'End') {
            next = options.length - 1;
        }
        if (next !== null && options[next]) {
            event.preventDefault();
            option.tabIndex = -1;
            options[next].tabIndex = 0;
            options[next].focus();
        }
    }

    function richClosePalettes(state, exceptGroup) {
        Object.keys(state.palettes).forEach(function (group) {
            if (group === exceptGroup) {
                return;
            }
            var palette = state.palettes[group];
            richSetDomProperty(palette.menu, 'hidden', true);
            richSetAttributeValue(
                palette.trigger,
                'aria-expanded',
                'false'
            );
            if (state.rgbaControls[group]) {
                richSetDomProperty(
                    state.rgbaControls[group].root,
                    'hidden',
                    true
                );
            }
        });
    }

    function richPaletteOwnsTarget(palette, target) {
        return Boolean(
            palette
            && palette.root
            && target
            && typeof palette.root.contains === 'function'
            && palette.root.contains(target)
        );
    }

    function richOpenPalette(state) {
        var groups = Object.keys(state.palettes);
        for (var index = 0; index < groups.length; index += 1) {
            var palette = state.palettes[groups[index]];
            if (palette && !palette.menu.hidden) {
                return palette;
            }
        }
        return null;
    }

    function richClosePalettesOnFocusLeave(state, nextTarget) {
        var openPalette = richOpenPalette(state);
        if (richPaletteOwnsTarget(openPalette, nextTarget)) {
            return false;
        }
        richClosePalettes(state, '');
        return true;
    }

    function richPointerTargetCanReceiveFocus(target) {
        if (!target || typeof target.closest !== 'function') {
            return false;
        }
        var candidate = target.closest(
            'a[href], button:not([disabled]), input:not([disabled]), '
                + 'select:not([disabled]), textarea:not([disabled]), '
                + '[contenteditable="true"], [tabindex]:not([tabindex="-1"])'
        );
        return Boolean(candidate);
    }

    function richClosePalettesOnPointerDown(state, target) {
        var openPalette = richOpenPalette(state);
        if (richPaletteOwnsTarget(openPalette, target)) {
            return false;
        }
        var returnFocus = openPalette
            && !richPointerTargetCanReceiveFocus(target)
            ? openPalette.trigger
            : null;
        richClosePalettes(state, '');
        if (
            returnFocus
            && !returnFocus.disabled
            && typeof returnFocus.focus === 'function'
        ) {
            returnFocus.focus();
        }
        return true;
    }

    function richTogglePalette(state, trigger) {
        if (!richRequireSelection(state)) {
            return;
        }
        richCloseBlockTypePicker(state, false);
        var group = trigger.dataset.blogRichPalette || '';
        var palette = state.palettes[group];
        if (!palette) {
            return;
        }
        var open = palette.menu.hidden;
        richClosePalettes(state, open ? group : '');
        palette.menu.hidden = !open;
        palette.trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            var selected = palette.menu.querySelector(
                '.blogEditor__richPaletteOption[aria-pressed="true"]'
            ) || palette.menu.querySelector('.blogEditor__richPaletteOption');
            if (selected instanceof HTMLElement) {
                selected.focus();
            }
        } else {
            palette.trigger.focus();
        }
    }

    function richHandlePaletteKeydown(state, event) {
        var option = event.target.closest('.blogEditor__richPaletteOption');
        var custom = event.target.closest('.blogEditor__richCustomColor');
        var trigger = event.target.closest('.blogEditor__richPaletteTrigger');
        if (trigger && event.key === 'ArrowDown') {
            event.preventDefault();
            richTogglePalette(state, trigger);
            return;
        }
        var menu = event.target.closest('.blogEditor__richPaletteMenu');
        if (menu && event.key === 'Escape') {
            event.preventDefault();
            var menuGroup = menu.parentElement
                ? menu.parentElement.dataset.blogRichPalette || ''
                : '';
            if (state.rgbaControls[menuGroup]) {
                state.rgbaControls[menuGroup].root.hidden = true;
            }
            richClosePalettes(state, '');
            if (state.palettes[menuGroup]) {
                state.palettes[menuGroup].trigger.focus();
            }
            return;
        }
        var choice = option instanceof HTMLButtonElement ? option : custom;
        if (!(choice instanceof HTMLButtonElement)) {
            return;
        }
        var palette = state.palettes[choice.dataset.blogRichPalette || ''];
        if (!palette) {
            return;
        }
        var options = Array.from(palette.menu.querySelectorAll(
            '.blogEditor__richPaletteOption:not([disabled]), '
                + '.blogEditor__richCustomColor:not([disabled])'
        ));
        var current = options.indexOf(choice);
        var next = null;
        if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
            next = (current + 1) % options.length;
        } else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
            next = (current - 1 + options.length) % options.length;
        } else if (event.key === 'Home') {
            next = 0;
        } else if (event.key === 'End') {
            next = options.length - 1;
        }
        if (next !== null) {
            event.preventDefault();
            options[next].focus();
        }
    }

    function richAdvancedVisualSource(state) {
        if (
            !state.advancedMode
            || !state.advancedVisualEditable
            || !state.visualTouched
        ) {
            return state.advancedHtmlDraft;
        }
        return state.advancedVisualStructureLocked
            ? richSerializeAdvancedVisualFlowHtml(
                state.advancedHtmlDraft,
                state.flowDraft
            )
            : richSerializeTextFlowHtml(state.flowDraft);
    }

    function richAdvancedFlowFingerprint(state) {
        return JSON.stringify(Array.isArray(state.flowDraft)
            ? state.flowDraft
            : []);
    }

    function richResetAdvancedVisualBaseline(state) {
        state.advancedVisualBaseline = richAdvancedFlowFingerprint(state);
        state.advancedVisualRawBaseline = state.advancedMode
            ? state.advancedHtmlDraft
            : '';
        state.visualTouched = false;
    }

    function richReconcileAdvancedVisualTouch(state) {
        if (!state.advancedMode || !state.advancedVisualEditable) {
            return;
        }
        state.visualTouched = richAdvancedFlowFingerprint(state)
            !== state.advancedVisualBaseline;
        if (!state.visualTouched) {
            state.advancedHtmlDraft = state.advancedVisualRawBaseline;
        }
    }

    function richCommitAdvancedVisualFlow(state) {
        if (
            !state.advancedMode
            || !state.advancedVisualEditable
            || !state.visualTouched
        ) {
            return;
        }
        state.advancedHtmlDraft = richAdvancedVisualSource(state);
    }

    function richSyncVisual(state) {
        try {
            if (state.textFlowMode) {
                state.flowDraft = state.advancedMode
                    && state.advancedVisualEditable
                    ? richParseAdvancedVisualRoot(state)
                    : richParseTextFlowRoot(state.visual, false);
                richReconcileAdvancedVisualTouch(state);
                richCommitAdvancedVisualFlow(state);
                if (
                    state.advancedMode
                    && state.advancedVisualEditable
                    && state.advancedVisualStructureLocked
                ) {
                    richAdvancedVisualProjectAttributes(state);
                }
                richSetDatasetValue(
                    state.visual,
                    'empty',
                    inlinePlainText(state.flowDraft).trim() === ''
                        ? 'true'
                        : 'false'
                );
            } else {
                state.draft = richParseInlineRoot(
                    state.visual,
                    state.allowBreak,
                    false,
                    true
                );
                richSetDatasetValue(
                    state.visual,
                    'empty',
                    richInlinePlainText(state.draft) === '' ? 'true' : 'false'
                );
            }
            richUpdateDirty(state);
            richUpdateLimitFeedback(state);
            state.documentRevision += 1;
            return true;
        } catch (error) {
            richStatus(
                state,
                'Revisa el contenido: contiene una estructura no permitida.',
                true
            );
            return false;
        }
    }

    function richRenderDraft(state, restoreSelection, options) {
        options = options || {};
        richCancelToolbarRefresh(state);
        state.documentRevision += 1;
        richUpdateDirty(state);
        if (state.advancedMode && !state.advancedVisualEditable) {
            richRenderAdvancedDraft(state);
            richRefreshToolbar(state);
            return;
        }
        state.visual.contentEditable = state.context.readOnly ? 'false' : 'true';
        if (state.advancedMode) {
            state.visual.dataset.advanced = 'editable';
        } else {
            delete state.visual.dataset.advanced;
        }
        state.toolbar.hidden = false;
        state.visual.replaceChildren();
        if (state.textFlowMode) {
            appendTextFlowPreview(state.visual, state.flowDraft);
            richAdvancedVisualProjectAttributes(state);
            richSetDatasetValue(
                state.visual,
                'empty',
                inlinePlainText(state.flowDraft).trim() === ''
                    ? 'true'
                    : 'false'
            );
        } else {
            appendInlinePreview(state.visual, state.draft);
            richSetDatasetValue(
                state.visual,
                'empty',
                richInlinePlainText(state.draft) === '' ? 'true' : 'false'
            );
        }
        richSetDatasetValue(state.visual, 'textAlign', state.textAlign);
        if (options.refreshAdvancedStyle !== false) {
            richRefreshAdvancedVisualStyle(state);
        }
        richRefreshListPanel(state);
        richUpdateLimitFeedback(state);
        if (restoreSelection) {
            state.visual.focus();
            richRestoreSelection(state.visual, restoreSelection);
            state.selection = restoreSelection;
            state.selectionRevision = state.documentRevision;
        }
        richRefreshToolbar(state);
    }

    function richCancelToolbarRefresh(state) {
        if (!state) {
            return;
        }
        if (state.toolbarRefreshFrame) {
            window.cancelAnimationFrame(state.toolbarRefreshFrame);
            state.toolbarRefreshFrame = 0;
        }
        state.toolbarRefreshEpoch += 1;
    }

    function richScheduleToolbarRefresh(state) {
        if (
            !state
            || !state.active
            || state.mode !== 'visual'
            || state.toolbarRefreshFrame
        ) {
            return;
        }
        var epoch = state.toolbarRefreshEpoch;
        state.toolbarRefreshFrame = window.requestAnimationFrame(function () {
            state.toolbarRefreshFrame = 0;
            if (
                epoch !== state.toolbarRefreshEpoch
                || !state.active
                || !state.dialog.open
                || state.mode !== 'visual'
            ) {
                return;
            }
            richRefreshToolbar(
                state,
                state.toolbarSelectedFlowIndexes.slice()
            );
        });
    }

    function richCaptureSelection(state) {
        richDiscardCaretExitOutsideSelection(state);
        var flowSelection = state.textFlowMode
            ? richFlowSelection(state)
            : null;
        var flowSelections = flowSelection
            ? [flowSelection]
            : (
                state.textFlowMode
                    ? richFlowMultiListSelection(state)
                    : []
            );
        var offsets = flowSelection || richSelectionOffsets(state.visual);
        if (offsets) {
            state.selection = {
                start: offsets.start,
                end: offsets.end
            };
            state.flowSelection = flowSelection;
            state.flowSelections = flowSelections;
            state.selectionRevision = state.documentRevision;
            if (state.textFlowMode) {
                var selectedFlowIndexes = richFlowSelectedIndexes(state);
                if (
                    selectedFlowIndexes.length === 0
                    && flowSelection
                    && Number.isInteger(flowSelection.flowIndex)
                ) {
                    selectedFlowIndexes = [flowSelection.flowIndex];
                }
                state.toolbarSelectedFlowIndexes = selectedFlowIndexes.slice();
            } else {
                state.toolbarSelectedFlowIndexes = [];
            }
        }
        richScheduleToolbarRefresh(state);
    }

    function richFlowMetadataControlValue(flowNode, key) {
        if (!flowNode) {
            return '';
        }
        if (key === 'preset') {
            return flowNode.preset || 'default';
        }
        if (key === 'tone') {
            return flowNode.tone || 'neutral';
        }
        return typeof flowNode[key] === 'string' ? flowNode[key] : '';
    }

    function richRefreshFlowMetadataPanel(state, selectedIndexes) {
        var metadata = state.flowMetadata;
        if (!metadata) {
            return;
        }
        var indexes = Array.isArray(selectedIndexes) ? selectedIndexes : [];
        var index = indexes.length === 1 ? indexes[0] : -1;
        var flowNode = index >= 0 ? state.flowDraft[index] : null;
        var type = flowNode && ['quote', 'callout'].includes(
            flowNode.type
        ) ? flowNode.type : '';
        if (
            state.mode !== 'visual'
            || !state.textFlowMode
            || state.advancedMode
            || type === ''
        ) {
            richSetDomProperty(metadata.panel, 'hidden', true);
            metadata.index = -1;
            return;
        }

        metadata.index = index;
        richSetDomProperty(metadata.panel, 'hidden', false);
        richSetDatasetValue(
            metadata.panel,
            'blogRichFlowMetadataType',
            type
        );
        richSetTextContent(metadata.context, {
            quote: 'Cita seleccionada',
            callout: 'Destacado seleccionado'
        }[type]);
        Object.keys(metadata.groups).forEach(function (groupType) {
            richSetDomProperty(
                metadata.groups[groupType],
                'hidden',
                groupType !== type
            );
        });
        if (type === 'quote') {
            if (document.activeElement !== metadata.author) {
                richSetDomProperty(
                    metadata.author,
                    'value',
                    richFlowMetadataControlValue(flowNode, 'author')
                );
            }
            if (document.activeElement !== metadata.source) {
                richSetDomProperty(
                    metadata.source,
                    'value',
                    richFlowMetadataControlValue(flowNode, 'source')
                );
            }
            richSetDomProperty(
                metadata.quotePreset,
                'value',
                richFlowMetadataControlValue(flowNode, 'preset')
            );
        } else {
            richSetDomProperty(
                metadata.tone,
                'value',
                richFlowMetadataControlValue(flowNode, 'tone')
            );
        }
    }

    function richSyncFlowMetadataElement(state, index) {
        var flowNode = state.flowDraft[index];
        var flowElement = state.visual.children[index];
        if (!flowNode || !(flowElement instanceof HTMLElement)) {
            return false;
        }
        if (
            flowElement.getAttribute('data-content-callout') === 'true'
            && flowElement.getAttribute('role') === 'note'
        ) {
            flowElement.removeAttribute('role');
        }
        [
            'data-content-heading-preset',
            'data-content-quote-author',
            'data-content-quote-source',
            'data-content-quote-preset',
            'data-content-callout',
            'data-content-callout-tone',
            'data-content-list-marker'
        ].forEach(function (attribute) {
            flowElement.removeAttribute(attribute);
        });
        flowElement.style.removeProperty('list-style-type');
        richApplyTextFlowMetadata(flowElement, flowNode);
        if (
            flowNode.type === 'list'
            && Object.prototype.hasOwnProperty.call(flowNode, 'marker')
        ) {
            flowElement.style.listStyleType = flowNode.marker;
        }
        return true;
    }

    function richCommitFlowMetadataControl(state, control) {
        var metadata = state.flowMetadata;
        var key = control.dataset.blogRichFlowMetadata || '';
        var flowNode = metadata && metadata.index >= 0
            ? state.flowDraft[metadata.index]
            : null;
        if (!flowNode) {
            return false;
        }
        var before = JSON.stringify(flowNode);
        if (!richSetFlowMetadataValue(flowNode, key, control.value)) {
            control.value = richFlowMetadataControlValue(flowNode, key);
            control.removeAttribute('aria-invalid');
            richStatus(
                state,
                key === 'author'
                    ? 'El autor admite hasta 255 bytes y debe ocupar una sola línea.'
                    : (key === 'source'
                        ? 'La fuente admite hasta 500 bytes y debe ocupar una sola línea.'
                        : 'El ajuste elegido no está permitido.'),
                true
            );
            return false;
        }
        control.removeAttribute('aria-invalid');
        if (JSON.stringify(flowNode) === before) {
            return true;
        }
        richSyncFlowMetadataElement(state, metadata.index);
        state.inputTouched = true;
        state.visualTouched = true;
        richUpdateDirty(state);
        richUpdateLimitFeedback(state);
        richStatus(state, 'Ajuste del elemento actualizado.', false);
        return true;
    }

    function richHasSelection(state) {
        return state.mode === 'visual'
            && state.selectionRevision === state.documentRevision
            && state.selection
            && state.selection.end > state.selection.start;
    }

    function richRefreshToolbar(state, selectedFlowIndexesSnapshot) {
        var enabled = richHasSelection(state) && !state.context.readOnly;
        if (!enabled) {
            richClosePalettes(state, '');
        }
        state.toolbar.querySelectorAll(
            '[data-blog-rich-requires-selection]'
        ).forEach(function (control) {
            richSetDomProperty(control, 'disabled', !enabled);
        });
        var selected = enabled
            ? (
                state.textFlowMode
                    ? richFlowRangeSelectedNodes(
                        state.flowDraft,
                        state.flowSelections
                    )
                    : richSelectedNodes(
                        state.draft,
                        state.selection.start,
                        state.selection.end
                    )
            ) : [];
        ['strong', 'em', 'underline'].forEach(function (mark) {
            var button = state.toolbar.querySelector(
                '[data-blog-rich-action="' + mark + '"]'
            );
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }
            richSetAttributeValue(
                button,
                'aria-pressed',
                selected.length > 0
                    && selected.every(function (node) {
                        return node.marks.includes(mark);
                    })
                    ? 'true'
                    : 'false'
            );
        });
        ['size'].forEach(function (groupName) {
            var select = state.toolbar.querySelector(
                '[data-blog-rich-format="' + groupName + '"]'
            );
            if (!(select instanceof HTMLSelectElement)) {
                return;
            }
            var values = selected.map(function (node) {
                return node.marks.find(function (mark) {
                    return richMarkInGroup(groupName, mark);
                }) || '';
            });
            richSetDomProperty(select, 'value', values.length === 0
                ? ''
                : (
                    values.every(function (value) {
                        return value === values[0];
                    })
                        ? values[0]
                        : '__mixed'
                ));
        });
        ['color', 'background'].forEach(function (groupName) {
            var palette = state.palettes[groupName];
            if (!palette) {
                return;
            }
            var values = selected.map(function (node) {
                return node.marks.find(function (mark) {
                    return richMarkInGroup(groupName, mark);
                }) || '';
            });
            var current = values.length === 0
                ? ''
                : (
                    values.every(function (value) {
                        return value === values[0];
                    })
                        ? values[0]
                        : '__mixed'
                );
            var definition = palette.options.find(function (option) {
                return option.value === current;
            });
            var dynamic = canonicalDynamicRichMark(current);
            richSetColorSwatch(
                palette.triggerSwatch,
                definition
                    ? definition.value
                    : (dynamic || (current === '__mixed' ? 'mixed' : ''))
            );
            richSetTextContent(palette.triggerValue, definition
                ? definition.label
                : (dynamic ? 'RGBA personalizado' : 'Varios colores'));
            if (dynamic && state.rgbaControls[groupName]) {
                var rgba = rgbaControls(
                    dynamic.slice(dynamic.indexOf(':') + 1)
                );
                if (
                    document.activeElement
                        !== state.rgbaControls[groupName].color
                ) {
                    richSetDomProperty(
                        state.rgbaControls[groupName].color,
                        'value',
                        rgba.color
                    );
                }
                if (
                    document.activeElement
                        !== state.rgbaControls[groupName].alpha
                ) {
                    richSetDomProperty(
                        state.rgbaControls[groupName].alpha,
                        'value',
                        String(rgba.alpha)
                    );
                }
            }
            palette.menu.querySelectorAll('.blogEditor__richPaletteOption').forEach(
                function (option) {
                    richSetAttributeValue(
                        option,
                        'aria-pressed',
                        option.dataset.blogRichPaletteValue === current
                            ? 'true'
                            : 'false'
                    );
                }
            );
        });
        var structureLocked = state.advancedMode
            && state.advancedVisualStructureLocked;
        var usesCapturedFlowIndexes = Array.isArray(
            selectedFlowIndexesSnapshot
        );
        var selectedFlowIndexes = state.textFlowMode
            ? (usesCapturedFlowIndexes
                ? selectedFlowIndexesSnapshot.slice()
                : richFlowSelectedIndexes(state))
            : [];
        if (!usesCapturedFlowIndexes) {
            if (
                selectedFlowIndexes.length === 0
                && state.flowSelection
                && Number.isInteger(state.flowSelection.flowIndex)
            ) {
                selectedFlowIndexes = [state.flowSelection.flowIndex];
            }
            state.toolbarSelectedFlowIndexes = selectedFlowIndexes.slice();
        }
        var selectedFlowTypes = selectedFlowIndexes.map(function (index) {
            var flowNode = state.flowDraft[index];
            if (!flowNode) {
                return '';
            }
            return flowNode.type === 'heading'
                ? 'h' + flowNode.level
                : flowNode.type;
        });
        var leadingFlowIndex = state.flowDraft.findIndex(function (flowNode) {
            return flowNode && flowNode.type !== 'break';
        });
        var protectsLeadingHeading = state.requiresLeadingHeading
            && leadingFlowIndex >= 0
            && selectedFlowIndexes.includes(leadingFlowIndex);
        var blockTypeDisabled = !state.textFlowMode
            || state.context.readOnly
            || selectedFlowTypes.length === 0
            || selectedFlowTypes.some(function (type) {
                return type === 'list';
            });
        var selectedBlockType = selectedFlowTypes.length > 0
            && selectedFlowTypes.every(function (type) {
                return type === selectedFlowTypes[0];
            })
            && ['paragraph', 'h2', 'h3', 'h4', 'h5', 'h6'].includes(
                selectedFlowTypes[0]
            ) ? selectedFlowTypes[0] : '__mixed';
        richSetDomProperty(
            state.blockType.root,
            'hidden',
            !state.textFlowMode
        );
        richSetDomProperty(
            state.blockType.trigger,
            'disabled',
            blockTypeDisabled
        );
        Object.keys(state.blockType.options).forEach(function (value) {
            var option = state.blockType.options[value];
            richSetDomProperty(
                option,
                'disabled',
                value === 'paragraph' && protectsLeadingHeading
            );
            richSetAttributeValue(
                option,
                'aria-selected',
                value === selectedBlockType ? 'true' : 'false'
            );
            if (state.blockType.menu.hidden) {
                richSetDomProperty(option, 'tabIndex', -1);
            }
        });
        var selectedBlockDefinition = state.blockType.definitions.find(
            function (definition) {
                return definition.value === selectedBlockType;
            }
        );
        richSetTextContent(
            state.blockType.triggerValue,
            selectedBlockDefinition ? selectedBlockDefinition.shortLabel : '—'
        );
        richSetAttributeValue(
            state.blockType.trigger,
            'aria-label',
            'Tipo de bloque: ' + (selectedBlockDefinition
                ? selectedBlockDefinition.label
                : 'varios bloques')
        );
        if (state.blockType.root.hidden || blockTypeDisabled) {
            richCloseBlockTypePicker(state, false);
        }
        Object.keys(state.flowBlockButtons).forEach(function (blockTypeName) {
            var blockButton = state.flowBlockButtons[blockTypeName];
            richSetDomProperty(
                blockButton,
                'hidden',
                !state.textFlowMode
            );
            richSetDomProperty(
                blockButton,
                'disabled',
                !state.textFlowMode
                    || state.context.readOnly
                    || protectsLeadingHeading
                    || selectedFlowTypes.length === 0
                    || selectedFlowTypes.some(function (type) {
                        return type === 'list';
                    })
            );
            richSetAttributeValue(
                blockButton,
                'aria-pressed',
                selectedFlowTypes.length > 0
                    && selectedFlowTypes.every(function (type) {
                        return type === blockTypeName;
                    }) ? 'true' : 'false'
            );
        });
        var selectedFlowNodes = selectedFlowIndexes.map(function (index) {
            return state.flowDraft[index] || null;
        }).filter(Boolean);
        var listSelectionAllowed = selectedFlowNodes.length > 0
            && selectedFlowNodes.every(function (flowNode) {
                return ['paragraph', 'list'].includes(flowNode.type);
            });
        Object.keys(state.flowListStyles).forEach(function (listType) {
            var listStyle = state.flowListStyles[listType];
            var ordered = listType === 'ordered';
            var active = selectedFlowNodes.length > 0
                && selectedFlowNodes.every(function (flowNode) {
                    return flowNode.type === 'list'
                        && flowNode.ordered === ordered;
                });
            var markerValues = active ? selectedFlowNodes.map(function (flowNode) {
                return richFlowListMarkerValue(flowNode);
            }) : [];
            richSetDomProperty(
                listStyle.label,
                'hidden',
                !state.textFlowMode || structureLocked
            );
            richSetDomProperty(
                listStyle.select,
                'disabled',
                !state.textFlowMode
                    || state.context.readOnly
                    || structureLocked
                    || protectsLeadingHeading
                    || !listSelectionAllowed
            );
            richSetDomProperty(
                listStyle.select,
                'value',
                active
                    && markerValues.every(function (markerValue) {
                        return markerValue === markerValues[0];
                    }) ? markerValues[0] : '__mixed'
            );
            richSetDatasetValue(
                listStyle.label,
                'active',
                active ? 'true' : 'false'
            );
        });
        richRefreshFlowMetadataPanel(state, selectedFlowIndexes);
        Object.keys(state.alignmentButtons).forEach(function (alignment) {
            var button = state.alignmentButtons[alignment];
            richSetDomProperty(
                button,
                'disabled',
                state.context.readOnly
            );
            richSetAttributeValue(
                button,
                'aria-pressed',
                state.textAlign === alignment ? 'true' : 'false'
            );
        });
        richSetDatasetValue(state.visual, 'textAlign', state.textAlign);
    }

    function richRequireSelection(state) {
        if (
            state.active
            && state.dialog.open
            && !state.context.readOnly
            && richHasSelection(state)
        ) {
            return true;
        }
        richStatus(
            state,
            'Selecciona el texto al que quieres aplicar el formato.',
            true
        );
        return false;
    }

    function richApplySimpleMark(state, mark) {
        if (!richRequireSelection(state) || !richSyncVisual(state)) {
            return;
        }
        var selection = state.selection;
        if (state.textFlowMode) {
            if (!richFlowTransform(
                state,
                richFlowMarkTransform(mark, null),
                true
            )) {
                richStatus(
                    state,
                    'Aplica el formato dentro de un bloque o de una misma lista.',
                    true
                );
                return;
            }
            richStatus(state, 'Formato aplicado a la selección.', false);
            return;
        }
        state.draft = richApplyMark(
            state.draft,
            selection.start,
            selection.end,
            mark,
            null
        );
        richRenderDraft(state, selection);
        richStatus(state, 'Formato aplicado a la selecci\u00f3n.', false);
    }

    function richApplyGroup(state, groupName, mark) {
        var group = RICH_MARK_GROUPS[groupName];
        if (
            !Array.isArray(group)
            || (mark !== '' && !richMarkInGroup(groupName, mark))
            || !richRequireSelection(state)
            || !richSyncVisual(state)
        ) {
            return;
        }
        var selection = state.selection;
        if (state.textFlowMode) {
            if (!richFlowTransform(
                state,
                richFlowMarkTransform(mark, groupName),
                true
            )) {
                richStatus(
                    state,
                    'Aplica el formato dentro de un bloque o de una misma lista.',
                    true
                );
                return;
            }
            richStatus(state, 'Formato aplicado a la selección.', false);
            return;
        }
        state.draft = richApplyMark(
            state.draft,
            selection.start,
            selection.end,
            mark,
            groupName
        );
        richRenderDraft(state, selection);
        richStatus(state, 'Formato aplicado a la selecci\u00f3n.', false);
    }

    function richOpenLinkPanel(state) {
        if (!richRequireSelection(state) || !richSyncVisual(state)) {
            return;
        }
        state.selectionRevision = state.documentRevision;
        var selectionContent = state.textFlowMode
            ? richFlowSelectedContent(state)
            : state.draft;
        var selected = richSelectedNodes(
            selectionContent,
            state.selection.start,
            state.selection.end
        );
        var current = selected.find(function (node) {
            return node.type === 'link';
        });
        state.linkHref.value = current ? current.href : '/';
        state.linkTitle.value = current && current.title ? current.title : '';
        state.linkTarget.value = current ? current.target : 'same';
        state.linkPanel.hidden = false;
        state.linkHref.focus();
    }

    function richCommitLink(state) {
        if (!richRequireSelection(state)) {
            return;
        }
        var href = state.linkHref.value.trim();
        var title = state.linkTitle.value.trim();
        if (
            !safeUrl(href)
            || !optionalSingleLine(title === '' ? null : title, 500)
            || !['same', 'new'].includes(state.linkTarget.value)
        ) {
            richStatus(
                state,
                'Revisa la URL, el title y el destino del enlace.',
                true
            );
            return;
        }
        var selection = state.selection;
        if (state.textFlowMode) {
            if (!richFlowTransform(state, function (content, start, end) {
                return richApplyLink(content, start, end, {
                    href: href,
                    title: title === '' ? null : title,
                    target: state.linkTarget.value
                });
            })) {
                richStatus(
                    state,
                    'El enlace debe quedar dentro de un mismo párrafo o elemento de lista.',
                    true
                );
                return;
            }
            state.linkPanel.hidden = true;
            richStatus(state, 'Enlace aplicado a la selección.', false);
            return;
        }
        state.draft = richApplyLink(
            state.draft,
            selection.start,
            selection.end,
            {
                href: href,
                title: title === '' ? null : title,
                target: state.linkTarget.value
            }
        );
        state.linkPanel.hidden = true;
        richRenderDraft(state, selection);
        richStatus(state, 'Enlace aplicado a la selecci\u00f3n.', false);
    }

    function richSetMode(state, mode) {
        if (
            mode === state.mode
            || !['visual', 'source', 'css'].includes(mode)
            || (mode === 'css' && state.cssTab.hidden)
        ) {
            return;
        }
        if (state.mode === 'visual') {
            richDiscardEmptyCaretExit(state);
        }
        state.linkPanel.hidden = true;
        try {
            if (state.mode === 'source') {
                richParseEditorHtml(state, state.source.value);
            } else if (state.mode === 'css') {
                richCommitAdvancedEditors(state);
            } else if (
                (!state.advancedMode || state.advancedVisualEditable)
                && !richSyncVisual(state)
            ) {
                return;
            }
        } catch (error) {
            richReportAdvancedValidationFailure(state, error, state.mode);
            return;
        }
        richSourceHideSuggestions(state);
        richCssHideSuggestions(state);
        if (mode === 'source') {
            state.source.value = richSerializeEditorHtml(state);
            state.sourceBaseline = state.source.value;
            state.sourceTouched = false;
            richResetCodeHistory(state.sourceHistory, state.source);
            state.source.scrollLeft = 0;
            richSourceSyncChrome(state, false);
        } else if (mode === 'css') {
            state.source.value = richSerializeEditorHtml(state);
            state.cssSource.value = state.cssDraft;
            state.sourceBaseline = state.source.value;
            state.cssBaseline = state.cssSource.value;
            state.sourceTouched = false;
            state.cssTouched = false;
            richResetCodeHistory(state.sourceHistory, state.source);
            richResetCodeHistory(state.cssHistory, state.cssSource);
            state.cssSource.scrollLeft = 0;
            richCssSourceSyncChrome(state);
        } else {
            richSourceHideSuggestions(state);
            richCssHideSuggestions(state);
            richRenderDraft(state, null);
        }
        richCancelToolbarRefresh(state);
        state.selection = null;
        state.flowSelection = null;
        state.flowSelections = [];
        state.toolbarSelectedFlowIndexes = [];
        state.mode = mode;
        richUpdateLimitFeedback(state);
        state.visualPanel.hidden = mode !== 'visual';
        state.sourcePanel.hidden = mode !== 'source';
        state.cssPanel.hidden = mode !== 'css';
        if (mode === 'source' || mode === 'css') {
            richScheduleSourceGutter(state, mode);
        }
        state.visualTab.setAttribute(
            'aria-selected',
            mode === 'visual' ? 'true' : 'false'
        );
        state.visualTab.tabIndex = mode === 'visual' ? 0 : -1;
        state.sourceTab.setAttribute(
            'aria-selected',
            mode === 'source' ? 'true' : 'false'
        );
        state.sourceTab.tabIndex = mode === 'source' ? 0 : -1;
        state.cssTab.setAttribute(
            'aria-selected',
            mode === 'css' ? 'true' : 'false'
        );
        state.cssTab.tabIndex = mode === 'css' ? 0 : -1;
        richRefreshToolbar(state);
        var activeControl = (
            mode === 'visual'
                ? state.visual
                : (mode === 'source' ? state.source : state.cssSource)
        );
        activeControl.focus();
        if (mode === 'visual') {
            richPlaceEmptyFlowCaret(state);
        }
        richStatus(state, '', false);
    }

    function richModalDirty(state) {
        if (!state || !state.active) {
            return false;
        }
        if (state.mode === 'source') {
            return state.source.value !== richSerializeEditorHtml(state)
                || richStructuredFingerprint(state) !== state.baseline;
        }
        if (state.mode === 'css') {
            return state.cssSource.value !== state.cssDraft
                || richStructuredFingerprint(state) !== state.baseline;
        }
        return state.inputTouched
            || richStructuredFingerprint(state) !== state.baseline;
    }

    function richHandleListAction(state, action, trigger) {
        return false;
    }

    function richHandleAction(state, action, trigger) {
        if (richHandleListAction(state, action, trigger)) {
            return;
        }
        if (action === 'toggle-size') {
            var expanded = state.dialog.dataset.expanded !== 'true';
            state.dialog.style.removeProperty('height');
            state.dialog.dataset.expanded = expanded ? 'true' : 'false';
            state.expand.setAttribute('aria-pressed', expanded ? 'true' : 'false');
            state.expand.setAttribute(
                'aria-label',
                expanded
                    ? 'Restaurar el alto del editor'
                    : 'Ampliar el editor hasta el alto disponible'
            );
            state.expand.textContent = expanded ? 'Restaurar' : 'Ampliar';
            richStatus(
                state,
                expanded
                    ? 'Editor ampliado al alto disponible.'
                    : 'Editor restaurado a su alto inicial.',
                false
            );
            return;
        }
        if (action === 'toggle-block-type') {
            richToggleBlockTypePicker(state);
            return;
        }
        if (action === 'block-type-value') {
            var requestedBlockType = trigger.dataset.blogRichBlockTypeValue
                || '';
            richCloseBlockTypePicker(state, false);
            if (!richSetFlowBlockType(state, requestedBlockType)) {
                state.blockType.trigger.focus();
            }
            return;
        }
        if (action === 'toggle-palette') {
            richTogglePalette(state, trigger);
            return;
        }
        if (action === 'palette-value') {
            var paletteGroup = trigger.dataset.blogRichPalette || '';
            var paletteValue = trigger.dataset.blogRichPaletteValue || '';
            var valuePalette = state.palettes[paletteGroup];
            richClosePalettes(state, '');
            richApplyGroup(state, paletteGroup, paletteValue);
            if (valuePalette) {
                valuePalette.trigger.focus();
            }
            return;
        }
        if (action === 'toggle-rgba') {
            var customGroup = trigger.dataset.blogRichPalette || '';
            var customControl = state.rgbaControls[customGroup];
            if (customControl) {
                customControl.root.hidden = !customControl.root.hidden;
                if (!customControl.root.hidden) {
                    customControl.color.focus();
                }
            }
            return;
        }
        if (action === 'apply-rgba') {
            var rgbaGroup = trigger.dataset.blogRichPalette || '';
            var rgbaControl = state.rgbaControls[rgbaGroup];
            var rgbaValue = rgbaControl
                ? rgbaFromControls(
                    rgbaControl.color.value,
                    rgbaControl.alpha.value
                )
                : null;
            if (
                rgbaValue === null
                || !['color', 'background'].includes(rgbaGroup)
            ) {
                richStatus(state, 'El color RGBA no es v\u00e1lido.', true);
                return;
            }
            richApplyGroup(
                state,
                rgbaGroup,
                (rgbaGroup === 'color'
                    ? 'text-rgba:'
                    : 'background-rgba:') + rgbaValue
            );
            rgbaControl.root.hidden = true;
            richClosePalettes(state, '');
            if (state.palettes[rgbaGroup]) {
                state.palettes[rgbaGroup].trigger.focus();
            }
            return;
        }
        if (action.startsWith('text-align-')) {
            var textAlign = action.slice('text-align-'.length);
            if (
                PRESENTATION_TEXT_ALIGNS.includes(textAlign)
                && !state.context.readOnly
            ) {
                state.textAlign = textAlign;
                richUpdateDirty(state);
                richRefreshToolbar(state);
                richStatus(state, 'Alineaci\u00f3n del texto actualizada.', false);
            }
            return;
        }
        if (action === 'block-quote' || action === 'block-callout') {
            richToggleFlowBlockType(
                state,
                action === 'block-quote' ? 'quote' : 'callout'
            );
            return;
        }
        if (['strong', 'em', 'underline'].includes(action)) {
            richApplySimpleMark(state, action);
            return;
        }
        if (action === 'clear') {
            if (!richRequireSelection(state) || !richSyncVisual(state)) {
                return;
            }
            var clearSelection = state.selection;
            if (state.textFlowMode) {
                if (!richFlowTransform(
                    state,
                    function (content, start, end) {
                        return richClearMarks(content, start, end);
                    },
                    true
                )) {
                    richStatus(
                        state,
                        'Limpia el formato dentro de un bloque o de una misma lista.',
                        true
                    );
                    return;
                }
                richStatus(state, 'Formato eliminado de la selección.', false);
                return;
            }
            state.draft = richClearMarks(
                state.draft,
                clearSelection.start,
                clearSelection.end
            );
            richRenderDraft(state, clearSelection);
            richStatus(state, 'Formato eliminado de la selecci\u00f3n.', false);
            return;
        }
        if (action === 'link') {
            richOpenLinkPanel(state);
            return;
        }
        if (action === 'unlink') {
            if (!richRequireSelection(state) || !richSyncVisual(state)) {
                return;
            }
            var unlinkSelection = state.selection;
            if (state.textFlowMode) {
                if (!richFlowTransform(state, function (content, start, end) {
                    return richRemoveLink(content, start, end);
                })) {
                    richStatus(
                        state,
                        'Quita el enlace dentro de un mismo párrafo o elemento de lista.',
                        true
                    );
                    return;
                }
                richStatus(state, 'Enlace eliminado de la selección.', false);
                return;
            }
            state.draft = richRemoveLink(
                state.draft,
                unlinkSelection.start,
                unlinkSelection.end
            );
            richRenderDraft(state, unlinkSelection);
            richStatus(state, 'Enlace eliminado de la selecci\u00f3n.', false);
            return;
        }
        if (action === 'apply-link') {
            richCommitLink(state);
            return;
        }
        if (action === 'cancel-link') {
            state.linkPanel.hidden = true;
            var selectionRoot = state.textFlowMode && state.flowSelection
                ? state.flowSelection.element
                : state.visual;
            selectionRoot.focus();
            richRestoreSelection(selectionRoot, state.selection);
            return;
        }
        if (action === 'mode-source') {
            richSetMode(state, 'source');
            return;
        }
        if (action === 'mode-css') {
            richSetMode(state, 'css');
            return;
        }
        if (action === 'mode-visual') {
            richSetMode(state, 'visual');
            return;
        }
        if (action === 'apply') {
            richApplyModal(state);
            return;
        }
        if (action === 'cancel') {
            richCloseModal(state);
        }
    }

    function richCloseModal(state) {
        richSourceHideSuggestions(state);
        richCssHideSuggestions(state);
        richCancelToolbarRefresh(state);
        state.active = false;
        if (state.dialog.open) {
            state.dialog.close();
        }
    }

    function richDraftKeepsLeadingHeading(state) {
        if (!state.requiresLeadingHeading) {
            return true;
        }
        if (state.advancedMode) {
            return v2SectionHeadingModule({
                type: 'paragraph',
                html: state.advancedHtmlDraft
            });
        }
        var firstMeaningful = state.flowDraft.find(function (flowNode) {
            return flowNode && flowNode.type !== 'break';
        });
        return Boolean(
            firstMeaningful
            && firstMeaningful.type === 'heading'
            && HEADING_LEVELS.includes(firstMeaningful.level)
        );
    }

    function richApplyTextFlowDraft(location, flowDraft) {
        if (richAdvancedParagraphBlock(location.node)) {
            var standardParagraph = {
                id: location.node.id,
                type: 'paragraph',
                content: richClone(flowDraft),
                presentation: richClone(location.node.presentation)
            };
            location.siblings[location.index] = standardParagraph;
            location.node = standardParagraph;
            return;
        }
        location.node.content = richClone(flowDraft);
    }

    function richApplyModal(state) {
        if (state.mode === 'visual') {
            richDiscardEmptyCaretExit(state);
        }
        if (!richCommitCurrentInput(state)) {
            return;
        }
        if (!richUpdateLimitFeedback(state)) {
            richStatus(
                state,
                'Reduce el contenido hasta el l\u00edmite t\u00e9cnico antes de aplicarlo.',
                true
            );
            return;
        }
        if (state.advancedMode && (
            bytes(state.advancedHtmlDraft)
                > state.context.technicalLimits.block[
                    state.sourceOnlyMode ? 'embed_html' : 'html'
                ].bytes
            || bytes(state.cssDraft)
                > state.context.technicalLimits.block.css.bytes
        )) {
            richStatus(
                state,
                'Reduce el HTML o el CSS hasta sus límites técnicos antes de aplicarlo.',
                true
            );
            return;
        }
        if (!richDraftKeepsLeadingHeading(state)) {
            richStatus(
                state,
                'El Texto inicial de la sección debe comenzar con un encabezado H2-H6.',
                true
            );
            return;
        }
        if (!state.block) {
            richStatus(state, 'El bloque necesita contenido v\u00e1lido.', true);
            return;
        }

        var nodeId = state.block.id;
        var candidate = richClone(state.context.documentValue);
        var location = v2Location(candidate, nodeId);
        if (!location || !RICH_MODAL_BLOCK_TYPES.includes(location.node.type)) {
            richStatus(state, 'No se pudo localizar el bloque editado.', true);
            return;
        }
        if (state.sourceOnlyMode) {
            location.node.html = state.advancedHtmlDraft;
            location.node.css = state.cssDraft;
            if (!validV2DraftDocument(candidate)) {
                richStatus(
                    state,
                    'Revisa el HTML y el CSS antes de aplicar los cambios.',
                    true
                );
                return;
            }
            state.context.documentValue = candidate;
            state.returnFocus = null;
            state.dialog.close();
            renderV2(state.context);
            announce(state.context, 'Contenido HTML actualizado.', false);
            return;
        }
        var legacyListEditMode = richLegacyListEditMode(state);
        if (legacyListEditMode === 'noop') {
            state.dialog.close();
            announce(
                state.context,
                'Sin cambios: la lista conserva su tipo e identificadores.',
                false
            );
            return;
        }
        if (
            state.block.type === 'paragraph'
            && richStructuredFingerprint(state) === state.baseline
            && !state.sourceTouched
            && !state.cssTouched
        ) {
            state.dialog.close();
            announce(
                state.context,
                'Sin cambios: el módulo conserva su contenido original.',
                false
            );
            return;
        }
        var convertedLegacyList = false;
        location.node.presentation.text_align = state.textAlign;
        if (state.legacyListMode) {
            if (legacyListEditMode === 'list') {
                var projectedList = state.flowDraft[0];
                location.node.ordered = projectedList.ordered === true;
                location.node.marker = location.node.ordered
                ? (
                    ['decimal', 'lower-alpha', 'upper-alpha'].includes(
                        location.node.marker
                    ) ? location.node.marker : 'decimal'
                )
                : (
                    ['disc', 'circle', 'square'].includes(location.node.marker)
                        ? location.node.marker : 'disc'
                );
                location.node.items = richLegacyListItems(
                    state.context,
                    location.node.items,
                    projectedList.items
                );
            } else {
                convertedLegacyList = true;
                location.siblings[location.index] = {
                    id: location.node.id,
                    type: 'paragraph',
                    content: richClone(state.flowDraft),
                    presentation: richParagraphPresentationFromList(
                        location.node.presentation
                    )
                };
            }
        } else if (state.advancedMode) {
            location.siblings[location.index] = {
                id: location.node.id,
                type: 'paragraph',
                html: state.advancedHtmlDraft,
                css: state.cssDraft,
                presentation: richClone(location.node.presentation)
            };
        } else if (state.textFlowMode) {
            richApplyTextFlowDraft(location, state.flowDraft);
        } else {
            location.node.content = richClone(state.draft);
            if (state.headingMode) {
                location.node.level = state.headingLevel;
            }
        }
        normalizeUnifiedTextModules(candidate);
        if (!validV2DraftDocument(candidate)) {
            richStatus(
                state,
                'Revisa el contenido antes de aplicar los cambios.',
                true
            );
            return;
        }
        state.context.documentValue = candidate;
        state.returnFocus = null;
        state.dialog.close();
        renderV2(state.context);
        var nextTrigger = state.context.blockList.querySelector(
            '[data-blog-v2-action="edit"][data-blog-v2-node="' + nodeId + '"]'
        );
        if (nextTrigger instanceof HTMLElement) {
            nextTrigger.focus();
        }
        announce(
            state.context,
            convertedLegacyList
                ? 'Lista convertida en Texto para conservar el flujo enriquecido. Las revisiones anteriores permanecen intactas.'
                : 'Contenido del bloque actualizado.',
            false
        );
    }

    function richBlockTitle(block) {
        if (block.type === 'heading') {
            return 'Editar encabezado H' + block.level;
        }
        if (block.type === 'callout') {
            return 'Editar texto destacado';
        }
        if (block.type === 'quote') {
            return 'Editar cita';
        }
        if (block.type === 'list') {
            return 'Editar lista';
        }
        if (block.type === 'paragraph') {
            return 'Editar texto';
        }
        if (block.type === 'embed') {
            return 'Editar HTML';
        }
        return 'Editar m\u00f3dulo de texto';
    }

    function richBlockPlaceholder(block) {
        if (block.type === 'heading') {
            return 'Escribe el t\u00edtulo';
        }
        if (block.type === 'paragraph') {
            return 'Escribe el texto';
        }
        return '';
    }

    function richAllowedHeadingLevels(context, block) {
        if (!block || block.type !== 'heading') {
            return [];
        }
        if (context.documentValue.version !== VERSION) {
            var blockIndex = context.documentValue.blocks.findIndex(
                function (candidate) { return candidate.id === block.id; }
            );
            return headingPolicyForContext(context).allowed_levels.filter(function (level) {
                return blockIndex >= 0
                    && headingLevelAllowed(context, blockIndex, level);
            });
        }
        return headingPolicyForContext(context).allowed_levels.filter(function (level) {
            var candidate = richClone(context.documentValue);
            var location = v2Location(candidate, block.id);
            if (!location || location.node.type !== 'heading') {
                return false;
            }
            location.node.level = level;
            return validV2DraftDocument(candidate);
        });
    }

    function richAdvancedParagraphBlock(block) {
        return Boolean(
            block
            && block.type === 'paragraph'
            && typeof block.html === 'string'
            && typeof block.css === 'string'
            && !Object.prototype.hasOwnProperty.call(block, 'content')
        );
    }

    function richTextBlockEmpty(block) {
        return richAdvancedParagraphBlock(block)
            ? block.html.trim() === ''
            : inlinePlainText(block.content).trim() === '';
    }

    function richAdvancedDraftFromBlock(block) {
        var advanced = richAdvancedParagraphBlock(block);
        return {
            advanced: advanced,
            html: advanced ? block.html : '',
            css: advanced ? block.css : ''
        };
    }

    function richOpenModal(context, block, trigger) {
        if (
            context.readOnly
            || !block
            || !RICH_MODAL_BLOCK_TYPES.includes(block.type)
        ) {
            return false;
        }
        var state = richBuildModal(context);
        richCancelToolbarRefresh(state);
        state.active = false;
        state.toolbarSelectedFlowIndexes = [];
        richClearAdvancedVisualStyle(state);
        var sourceOnly = block.type === 'embed';
        var advancedDraft = sourceOnly ? {
            advanced: true,
            html: block.html,
            css: block.css || ''
        } : richAdvancedDraftFromBlock(block);
        state.block = block;
        state.sourceOnlyMode = sourceOnly;
        var blockLocation = v2Location(context.documentValue, block.id);
        state.requiresLeadingHeading = block.type === 'paragraph'
            && v2ProtectedHeading(blockLocation);
        state.wasAdvanced = advancedDraft.advanced;
        state.advancedMode = state.wasAdvanced;
        state.advancedSourceEnabled = block.type === 'paragraph' || sourceOnly;
        state.advancedHtmlDraft = advancedDraft.html;
        state.cssDraft = advancedDraft.css;
        state.sourceTouched = false;
        state.cssTouched = false;
        state.visualTouched = false;
        state.allowBreak = block.type !== 'heading';
        state.listMode = false;
        state.legacyListMode = block.type === 'list';
        state.textFlowMode = ['paragraph', 'list'].includes(block.type);
        state.headingMode = block.type === 'heading';
        state.headingLevel = state.headingMode ? block.level : null;
        state.allowedHeadingLevels = state.headingMode
            ? richAllowedHeadingLevels(context, block)
            : (
                state.textFlowMode
                    ? headingPolicyForContext(context).allowed_levels.slice()
                    : []
            );
        state.listDraft = [];
        state.listOrdered = false;
        state.advancedVisualEditable = !sourceOnly && state.wasAdvanced
            && richSourceSupportsAdvancedVisualFlow(
                state,
                state.advancedHtmlDraft
            );
        state.advancedVisualStructureLocked = state.advancedVisualEditable
            && richAdvancedVisualStructureLocked(
                state.advancedHtmlDraft
            );
        state.flowDraft = state.textFlowMode
            ? (
                state.wasAdvanced
                    ? (
                        state.advancedVisualEditable
                            ? richParseAdvancedVisualFlowHtml(
                                state.advancedHtmlDraft
                            )
                            : []
                    )
                    : (state.legacyListMode
                        ? richLegacyListFlow(block)
                        : richNormalizeTextFlow(block.content))
            )
            : [];
        richResetAdvancedVisualBaseline(state);
        state.flowSelection = null;
        state.flowSelections = [];
        state.flowMetadata.index = -1;
        state.flowMetadata.panel.hidden = true;
        state.textAlign = block.presentation.text_align || 'start';
        state.sourceHelp.textContent = sourceOnly
            ? 'HTML seguro. Admite las etiquetas permitidas e iframes HTTPS de proveedores autorizados; rechaza scripts, eventos y atributos inseguros.'
            : (block.type === 'paragraph'
            ? 'HTML permitido: div, p, h2-h6, ul, ol, li, blockquote, aside, span, strong, em, u, a, br, small, mark, sup, sub, code e iframe seguro. Admite class, id, title, lang, dir, role, ARIA seguros (label, hidden, labelledby y describedby), data-content-* y atributos seguros de enlace o iframe.'
            : (state.headingMode
            ? 'HTML permitido: un \u00fanico encabezado estructural ('
                + state.allowedHeadingLevels.map(function (level) {
                    return 'h' + level;
                }).join(', ')
                + ') con strong, em, u, a y span en su interior.'
            : (state.textFlowMode
                ? 'HTML permitido: p, ul, ol y li; dentro puedes usar strong, em, u, a, br y span con tama\u00f1o, color o fondo LiquidStack.'
                : (
                state.allowBreak
                    ? 'HTML permitido: strong, em, u, a, br y span con tama\u00f1o, color o fondo LiquidStack.'
                    : 'HTML permitido: strong, em, u, a y span con tama\u00f1o, color o fondo LiquidStack.'
                ))));
        state.visual.setAttribute(
            'aria-multiline',
            (state.allowBreak || state.textFlowMode) ? 'true' : 'false'
        );
        state.visual.setAttribute(
            'aria-label',
            'Contenido del bloque'
        );
        state.draft = state.textFlowMode
            ? []
            : richClone(Array.isArray(block.content) ? block.content : []);
        state.source.value = state.wasAdvanced
            ? state.advancedHtmlDraft
            : richSerializeEditorHtml(state);
        state.cssSource.value = state.cssDraft;
        state.sourceBaseline = state.source.value;
        state.cssBaseline = state.cssSource.value;
        richResetCodeHistory(state.sourceHistory, state.source);
        richResetCodeHistory(state.cssHistory, state.cssSource);
        state.listTextarea.value = '';
        state.baseline = richStructuredFingerprint(state);
        state.pristineBaseline = richPristineFingerprint(state);
        state.baselineHtml = richSerializeEditorHtml(state);
        state.inputTouched = false;
        state.mode = sourceOnly ? 'source' : 'visual';
        state.selection = null;
        state.documentRevision += 1;
        state.selectionRevision = -1;
        state.toolbarSelectedFlowIndexes = [];
        state.pendingFlowListIndexes = null;
        state.pendingFlowListRevision = -1;
        state.returnFocus = trigger instanceof HTMLElement ? trigger : null;
        state.active = true;
        var placeholder = richBlockPlaceholder(block);
        state.dialog.dataset.blockType = block.type;
        state.visual.dataset.placeholder = placeholder;
        state.source.placeholder = placeholder;
        state.dialog.dataset.expanded = 'false';
        state.dialog.style.removeProperty('height');
        state.expand.setAttribute('aria-pressed', 'false');
        state.expand.setAttribute(
            'aria-label',
            'Ampliar el editor hasta el alto disponible'
        );
        state.expand.textContent = 'Ampliar';
        state.title.textContent = richBlockTitle(block);
        state.modeTabs.hidden = false;
        state.visualTab.hidden = sourceOnly;
        state.visualPanel.hidden = sourceOnly;
        state.sourcePanel.hidden = !sourceOnly;
        state.cssPanel.hidden = true;
        state.cssTab.hidden = !['paragraph', 'embed'].includes(block.type);
        state.toolbar.hidden = state.advancedMode;
        state.visual.hidden = false;
        state.visualTab.setAttribute('aria-selected', sourceOnly ? 'false' : 'true');
        state.visualTab.tabIndex = sourceOnly ? -1 : 0;
        state.sourceTab.setAttribute('aria-selected', sourceOnly ? 'true' : 'false');
        state.sourceTab.tabIndex = sourceOnly ? 0 : -1;
        state.cssTab.setAttribute('aria-selected', 'false');
        state.cssTab.tabIndex = -1;
        state.linkPanel.hidden = true;
        richClosePalettes(state, '');
        Object.keys(state.rgbaControls).forEach(function (group) {
            state.rgbaControls[group].root.hidden = true;
        });
        state.source.scrollTop = 0;
        state.source.scrollLeft = 0;
        state.cssSource.scrollTop = 0;
        state.cssSource.scrollLeft = 0;
        state.sourceTabExitArmed = false;
        state.cssTabExitArmed = false;
        richSourceHideSuggestions(state);
        richCssHideSuggestions(state);
        richSourceSyncChrome(state, false);
        richCssSourceSyncChrome(state);
        richStatus(state, '', false);
        if (!sourceOnly) {
            richRenderDraft(state, null);
        }
        if (state.legacyListMode) {
            richStatus(
                state,
                'Lista existente abierta como Texto enriquecido. Si mantienes una \u00fanica lista, conservar\u00e1 su identidad e identificadores.',
                false
            );
        }
        if (typeof state.dialog.showModal === 'function') {
            state.dialog.showModal();
        } else {
            state.dialog.setAttribute('open', '');
        }
        var openToolbarEpoch = state.toolbarRefreshEpoch;
        window.requestAnimationFrame(function () {
            if (
                openToolbarEpoch !== state.toolbarRefreshEpoch
                || !state.active
                || !state.dialog.open
            ) {
                return;
            }
            (sourceOnly ? state.source : state.visual).focus();
            if (!sourceOnly) {
                richPlaceEmptyFlowCaret(state);
            } else {
                richScheduleSourceGutter(state, 'source');
            }
            richRefreshToolbar(state);
        });
        return true;
    }

    function mediaOption(context, publicId) {
        return context.media.find(function (option) {
            return option.publicId === publicId;
        }) || null;
    }

    function blockPreview(context, block) {
        var preview;
        if (block.type === 'paragraph') {
            if (richAdvancedParagraphBlock(block)) {
                return richAdvancedPreviewFrame(
                    context,
                    block.id,
                    block.html,
                    block.css,
                    'blogEditor__advancedPreview blogEditor__advancedPreview--canvas'
                );
            }
            if (isTextFlowContent(block.content)) {
                preview = element('div', 'blogEditor__previewText');
                block.content.forEach(function (flowNode) {
                    if (flowNode.type === 'break') {
                        preview.append(document.createElement('br'));
                        return;
                    }
                    if (flowNode.type !== 'list') {
                        var tag = flowNode.type === 'heading'
                            ? 'h' + flowNode.level
                            : (
                                flowNode.type === 'quote'
                                    ? 'blockquote'
                                    : (
                                        flowNode.type === 'callout'
                                            ? 'aside'
                                            : 'p'
                                    )
                            );
                        var className = {
                            paragraph: 'blogEditor__previewParagraph',
                            heading: 'blogEditor__previewHeading',
                            quote: 'blogEditor__previewQuote blogEditor__previewQuote--'
                                + (flowNode.preset || 'minimal'),
                            callout: 'blogEditor__previewCallout blogEditor__previewCallout--'
                                + (flowNode.tone || 'neutral')
                        }[flowNode.type];
                        var textBlock = element(tag, className);
                        if (flowNode.type === 'heading') {
                            textBlock.dataset.semanticTag = tag;
                        }
                        richApplyTextFlowMetadata(textBlock, flowNode);
                        appendInlinePreview(textBlock, flowNode.content);
                        if (
                            flowNode.type === 'quote'
                            && (flowNode.author || flowNode.source)
                        ) {
                            var flowQuoteFooter = element(
                                'footer',
                                'blogEditor__previewQuoteMeta'
                            );
                            if (flowNode.author) {
                                flowQuoteFooter.append(element(
                                    'cite',
                                    '',
                                    flowNode.author
                                ));
                            }
                            if (flowNode.source) {
                                flowQuoteFooter.append(element(
                                    'span',
                                    'blogEditor__previewQuoteSource',
                                    flowNode.source
                                ));
                            }
                            textBlock.append(flowQuoteFooter);
                        }
                        preview.append(textBlock);
                        return;
                    }
                    var list = element(
                        flowNode.ordered ? 'ol' : 'ul',
                        'blogEditor__previewList'
                    );
                    richApplyTextFlowMetadata(list, flowNode);
                    if (Object.prototype.hasOwnProperty.call(
                        flowNode,
                        'marker'
                    )) {
                        list.style.listStyleType = flowNode.marker;
                    }
                    flowNode.items.forEach(function (itemValue) {
                        var listItem = document.createElement('li');
                        if (Object.prototype.hasOwnProperty.call(
                            itemValue,
                            'id'
                        )) {
                            listItem.dataset.contentListItemId = itemValue.id;
                        }
                        appendInlinePreview(listItem, itemValue.content);
                        list.append(listItem);
                    });
                    preview.append(list);
                });
                return preview;
            }
            preview = element('p', 'blogEditor__previewParagraph');
            appendInlinePreview(preview, block.content);
            return preview;
        }
        if (block.type === 'quote') {
            preview = element(
                'blockquote',
                'blogEditor__previewQuote blogEditor__previewQuote--'
                    + block.preset
            );
            var quoteText = element('p', 'blogEditor__previewQuoteText');
            appendInlinePreview(quoteText, block.content);
            preview.append(quoteText);
            if (block.author || block.source) {
                var quoteFooter = element('footer', 'blogEditor__previewQuoteMeta');
                if (block.author) {
                    quoteFooter.append(element('cite', '', block.author));
                }
                if (block.source) {
                    quoteFooter.append(element(
                        'span',
                        'blogEditor__previewQuoteSource',
                        block.source
                    ));
                }
                preview.append(quoteFooter);
            }
            return preview;
        }
        if (block.type === 'heading') {
            preview = element(
                'h' + block.level,
                'blogEditor__previewHeading'
            );
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
                var picture = element(
                    'picture',
                    'blogEditor__previewImageMedia'
                );
                var image = document.createElement('img');
                image.src = media.thumbnailUrl;
                image.alt = block.decorative ? '' : block.alt;
                image.loading = block.display === 'cover' ? 'eager' : 'lazy';
                picture.append(image);
                preview.append(picture);
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
        if (block.type === 'embed') {
            preview = element('figure', 'blogEditor__previewEmbed');
            var embedPlaceholder = element(
                'div',
                'blogEditor__embedPlaceholder'
            );
            embedPlaceholder.append(
                element('strong', '', 'HTML'),
                element(
                    'code',
                    '',
                    block.html.replace(/\s+/gu, ' ').trim().slice(0, 180)
                )
            );
            preview.append(embedPlaceholder);
            if (block.caption) {
                preview.append(element('figcaption', '', block.caption));
            }
            return preview;
        }
        if (block.type === 'separator') {
            preview = document.createElement('hr');
            preview.className = 'blogEditor__previewSeparator';
            preview.dataset.lineStyle = block.line_style;
            preview.dataset.thickness = block.thickness;
            preview.dataset.color = block.color;
            if (canonicalRgba(block.color) !== null) {
                preview.style.setProperty(
                    '--blog-editor-separator-color',
                    block.color
                );
            }
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
                    confirmEditorAction(context, {
                        title: 'Eliminar contenido',
                        message: '¿Eliminar este ' + label
                            + ' y su contenido?',
                        confirmLabel: 'Eliminar',
                        danger: true
                    }, document.activeElement).then(function (confirmed) {
                        if (!confirmed) {
                            return;
                        }
                        var range = semanticRange(
                            context.documentValue.blocks,
                            blockIndex
                        );
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
                            focusAfterRender(
                                context,
                                { kind: 'block', id: adjacent.id }
                            );
                        }
                        announce(context, 'Contenido eliminado.', false);
                    });
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
            block.type === 'image' && block.display === 'cover'
                ? 'HERO'
                : (block.type === 'heading'
                    ? 'H' + block.level + ' · ' + semanticBlockLabel(block)
                    : BLOCK_LABELS[block.type])
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

    function v2Walk(documentValue, visitor) {
        function walkChildren(children, metadata) {
            children.forEach(function (node, index) {
                var location = {
                    node: node,
                    siblings: children,
                    index: index,
                    owner: metadata.owner,
                    ownerType: metadata.ownerType,
                    column: metadata.column || null,
                    divDepth: metadata.divDepth || 0,
                    article: metadata.article || null,
                    topLevel: Boolean(metadata.topLevel)
                };
                visitor(location);
                if (node.type === 'section') {
                    walkChildren(node.children, {
                        owner: node,
                        ownerType: 'section',
                        divDepth: 0,
                        article: null
                    });
                    return;
                }
                if (!['article', 'div'].includes(node.type)) {
                    return;
                }
                var depth = node.type === 'div'
                    ? (metadata.ownerType === 'div'
                        ? metadata.divDepth + 1
                        : 1)
                    : metadata.divDepth;
                var article = node.type === 'article'
                    ? node
                    : metadata.article;
                node.layout.columns.forEach(function (column) {
                    walkChildren(column.children, {
                        owner: node,
                        ownerType: node.type,
                        column: column,
                        divDepth: depth,
                        article: article
                    });
                });
            });
        }
        walkChildren(documentValue.blocks, {
            owner: documentValue,
            ownerType: 'root',
            divDepth: 0,
            article: null,
            topLevel: true
        });
    }

    function v2Location(documentValue, nodeId) {
        var found = null;
        v2Walk(documentValue, function (location) {
            if (found === null && location.node.id === nodeId) {
                found = location;
            }
        });
        return found;
    }

    function v2DocumentNodeCount(documentValue) {
        var count = 0;
        v2Walk(documentValue, function () {
            count += 1;
        });
        return count;
    }

    function v2SubtreeNodeCount(node) {
        var count = 0;
        function visit(value) {
            if (!value || typeof value !== 'object') {
                return;
            }
            count += 1;
            if (value.type === 'section') {
                value.children.forEach(visit);
                return;
            }
            if (['article', 'div'].includes(value.type)) {
                value.layout.columns.forEach(function (column) {
                    column.children.forEach(visit);
                });
            }
        }
        visit(node);
        return count;
    }

    function v2ColumnTarget(documentValue, ownerId, columnId) {
        if (ownerId === 'root') {
            return {
                owner: documentValue,
                ownerType: 'root',
                children: documentValue.blocks,
                divDepth: 0,
                article: null
            };
        }
        var location = v2Location(documentValue, ownerId);
        if (!location) {
            return null;
        }
        if (location.node.type === 'section') {
            return {
                owner: location.node,
                ownerType: 'section',
                children: location.node.children,
                divDepth: 0,
                article: null
            };
        }
        if (!['article', 'div'].includes(location.node.type)) {
            return null;
        }
        var column = location.node.layout.columns.find(function (candidate) {
            return candidate.id === columnId;
        });
        if (!column) {
            return null;
        }
        var depth = location.node.type === 'div'
            ? (location.ownerType === 'div' ? location.divDepth + 1 : 1)
            : location.divDepth;
        return {
            owner: location.node,
            ownerType: location.node.type,
            column: column,
            children: column.children,
            divDepth: depth,
            article: location.node.type === 'article'
                ? location.node
                : location.article
        };
    }

    function v2ProtectedHeading(location) {
        return Boolean(
            location
            && location.ownerType === 'section'
            && location.index === 0
            && v2SectionHeadingModule(location.node)
        );
    }

    function v2CanMove(location, direction) {
        var minimum = location
            && location.topLevel
            && location.siblings[0]
            && location.siblings[0].type === 'image'
            ? 1
            : 0;
        return Boolean(
            location
            && !(location.topLevel && location.node.type !== 'section')
            && !v2ProtectedHeading(location)
            && location.index + direction >= minimum
            && location.index + direction < location.siblings.length
            && !(
                location.ownerType === 'section'
                && location.index + direction === 0
            )
        );
    }

    function v2CanDrag(location) {
        return Boolean(
            location
            && !(location.topLevel && location.node.type !== 'section')
            && !v2ProtectedHeading(location)
        );
    }

    function v2NodeContainsId(node, nodeId) {
        var found = false;
        function visit(candidate) {
            if (!candidate || typeof candidate !== 'object' || found) {
                return;
            }
            if (candidate.id === nodeId) {
                found = true;
                return;
            }
            if (candidate.type === 'section') {
                candidate.children.forEach(visit);
                return;
            }
            if (['article', 'div'].includes(candidate.type)) {
                candidate.layout.columns.forEach(function (column) {
                    column.children.forEach(visit);
                });
            }
        }
        visit(node);
        return found;
    }

    function v2SubtreeDivDepth(node) {
        var maximum = 0;
        function visit(candidate, depth) {
            if (!candidate || typeof candidate !== 'object') {
                return;
            }
            var nextDepth = depth;
            if (candidate.type === 'div') {
                nextDepth += 1;
                maximum = Math.max(maximum, nextDepth);
            }
            if (candidate.type === 'section') {
                candidate.children.forEach(function (child) {
                    visit(child, nextDepth);
                });
                return;
            }
            if (['article', 'div'].includes(candidate.type)) {
                candidate.layout.columns.forEach(function (column) {
                    column.children.forEach(function (child) {
                        visit(child, nextDepth);
                    });
                });
            }
        }
        visit(node, 0);
        return maximum;
    }

    function v2DropPlan(context, nodeId, ownerId, columnId, index) {
        var source = v2Location(context.documentValue, nodeId);
        var target = v2ColumnTarget(
            context.documentValue,
            ownerId,
            columnId
        );
        if (
            !v2CanDrag(source)
            || !target
            || !Number.isInteger(index)
            || index < 0
            || index > target.children.length
        ) {
            return null;
        }

        var node = source.node;
        var minimum = target.ownerType === 'section'
            ? 1
            : (
                target.ownerType === 'root'
                && target.children[0]
                && target.children[0].type === 'image'
                    ? 1
                    : 0
            );
        if (index < minimum) {
            return null;
        }
        if (
            (target.ownerType === 'root' && node.type !== 'section')
            || (target.ownerType !== 'root' && node.type === 'section')
            || (node.type === 'article' && target.ownerType !== 'section')
            || (
                target.ownerType !== 'root'
                && node.type === 'image'
                && node.display === 'cover'
            )
        ) {
            return null;
        }
        if (
            target.ownerType !== 'root'
            && target.owner.id
            && v2NodeContainsId(node, target.owner.id)
        ) {
            return null;
        }
        if (
            node.type === 'div'
            && target.divDepth + v2SubtreeDivDepth(node) > MAX_DIV_DEPTH
        ) {
            return null;
        }

        var adjustedIndex = index;
        if (source.siblings === target.children && source.index < index) {
            adjustedIndex -= 1;
        }
        if (
            source.siblings === target.children
            && adjustedIndex === source.index
        ) {
            return null;
        }

        return {
            source: source,
            target: target,
            index: adjustedIndex
        };
    }

    function v2MoveToTarget(context, nodeId, ownerId, columnId, index) {
        var plan = v2DropPlan(
            context,
            nodeId,
            ownerId,
            columnId,
            index
        );
        if (!plan) {
            return false;
        }
        var node = plan.source.siblings.splice(plan.source.index, 1)[0];
        plan.target.children.splice(plan.index, 0, node);
        context.selectedNodeId = node.id;
        return true;
    }

    function v2CanDuplicate(context, location) {
        if (
            !location
            || (location.topLevel && location.node.type !== 'section')
            || v2ProtectedHeading(location)
        ) {
            return false;
        }
        return v2DocumentNodeCount(context.documentValue)
            + v2SubtreeNodeCount(location.node) <= MAX_BLOCKS;
    }

    function v2CanDelete(location) {
        return Boolean(
            location
            && !v2ProtectedHeading(location)
            && location.index >= 0
            && !(location.topLevel && location.node.type !== 'section')
        );
    }

    function v2CloneWithIds(context, node) {
        var clone = JSON.parse(JSON.stringify(node));
        var reserved = allStructuralIds(context.documentValue);
        function renew(value) {
            if (!value || typeof value !== 'object') {
                return;
            }
            if (Object.prototype.hasOwnProperty.call(value, 'id')) {
                value.id = nextUuid(reserved);
            }
            Object.keys(value).forEach(function (key) {
                var child = value[key];
                if (Array.isArray(child)) {
                    child.forEach(renew);
                } else if (child && typeof child === 'object') {
                    renew(child);
                }
            });
        }
        renew(clone);
        return clone;
    }

    function v2ColumnPresetDefinitions() {
        return [
            { value: '1', label: '1 columna' },
            { value: '2-50-50', label: '2 columnas, 50/50' },
            { value: '2-40-60', label: '2 columnas, 40/60' },
            { value: '2-60-40', label: '2 columnas, 60/40' },
            { value: '2-30-70', label: '2 columnas, 30/70' },
            { value: '2-70-30', label: '2 columnas, 70/30' },
            { value: '3', label: '3 columnas iguales' },
            { value: '4', label: '4 columnas iguales' },
            { value: '5', label: '5 columnas iguales' }
        ];
    }

    function v2ColumnProportions(preset) {
        return {
            '1': ['100%'],
            '2-50-50': ['50%', '50%'],
            '2-40-60': ['40%', '60%'],
            '2-60-40': ['60%', '40%'],
            '2-30-70': ['30%', '70%'],
            '2-70-30': ['70%', '30%'],
            '3': ['1/3', '1/3', '1/3'],
            '4': ['1/4', '1/4', '1/4', '1/4'],
            '5': ['1/5', '1/5', '1/5', '1/5', '1/5']
        }[preset] || [];
    }

    function v2ColumnLabel(container, columnIndex) {
        var proportion = v2ColumnProportions(container.layout.preset)[
            columnIndex
        ] || '';
        return 'Columna ' + (columnIndex + 1)
            + (proportion === '' ? '' : ' \u00b7 ' + proportion);
    }

    function v2PresetLabel(preset) {
        var definition = v2ColumnPresetDefinitions().find(function (option) {
            return option.value === preset;
        });
        return definition ? definition.label : preset;
    }

    function v2ColumnProportionWeight(proportion) {
        if (proportion.endsWith('%')) {
            return Number.parseFloat(proportion) || 1;
        }
        var fraction = proportion.split('/').map(Number);
        if (
            fraction.length === 2
            && Number.isFinite(fraction[0])
            && Number.isFinite(fraction[1])
            && fraction[1] > 0
        ) {
            return fraction[0] / fraction[1];
        }
        return 1;
    }

    function v2ColumnPresetModels(selectedPreset) {
        return v2ColumnPresetDefinitions().map(function (definition) {
            var proportions = v2ColumnProportions(definition.value);
            return {
                value: definition.value,
                label: definition.label,
                proportions: proportions,
                weights: proportions.map(v2ColumnProportionWeight),
                selected: definition.value === selectedPreset
            };
        });
    }

    function v2ColumnPresetDiagram(model, modifier) {
        var diagram = element(
            'span',
            'blogEditor__layoutDiagram'
                + (modifier ? ' blogEditor__layoutDiagram--' + modifier : '')
        );
        diagram.setAttribute('aria-hidden', 'true');
        model.weights.forEach(function (weight) {
            var segment = element(
                'span',
                'blogEditor__layoutDiagramSegment'
            );
            segment.style.setProperty(
                '--blog-layout-column-weight',
                String(weight)
            );
            diagram.append(segment);
        });
        return diagram;
    }

    function v2ColumnPresetControl(context, container, surface) {
        var canvas = surface === 'canvas';
        var wrapper = element(
            'div',
            (canvas
                ? 'blogEditor__builderLayoutControl'
                : 'blogEditor__field')
                + ' blogEditor__layoutPicker'
                + ' blogEditor__layoutPicker--' + surface
        );
        var details = element('details', 'blogEditor__layoutPickerDetails');
        details.dataset.blogV2LayoutPicker = 'true';
        details.dataset.blogV2Node = container.id;
        var current = v2ColumnPresetModels(container.layout.preset).find(
            function (model) {
                return model.selected;
            }
        ) || v2ColumnPresetModels('1')[0];
        var summary = element('summary', 'blogEditor__layoutPickerSummary');
        summary.dataset.blogV2LayoutSummary = 'true';
        summary.dataset.blogV2Node = container.id;
        summary.setAttribute(
            'aria-label',
            'Distribuci\u00f3n de columnas de '
                + CONTAINER_LABELS[container.type].toLowerCase()
                + ': ' + current.label
        );
        summary.append(
            element('span', 'blogEditor__layoutPickerTitle', 'Distribuci\u00f3n'),
            v2ColumnPresetDiagram(current, 'current')
        );

        var fieldset = element('fieldset', 'blogEditor__layoutPickerPanel');
        fieldset.disabled = context.readOnly;
        fieldset.append(element(
            'legend',
            'webadmin-srOnly',
            'Elegir distribuci\u00f3n de columnas'
        ));
        var groupName = controlId(
            context,
            'v2-' + surface + '-preset-' + container.id
        );
        v2ColumnPresetModels(container.layout.preset).forEach(function (
            model,
            index
        ) {
            var choice = element('label', 'blogEditor__layoutChoice');
            var radio = document.createElement('input');
            radio.className = 'blogEditor__layoutChoiceInput';
            radio.type = 'radio';
            radio.id = groupName + '-' + index;
            radio.name = groupName;
            radio.value = model.value;
            radio.checked = model.selected;
            radio.disabled = context.readOnly;
            radio.dataset.blogV2Config = 'preset';
            radio.dataset.blogV2Node = container.id;
            radio.setAttribute('aria-label', model.label);
            radio.addEventListener('click', function () {
                if (model.selected) {
                    details.open = false;
                    summary.focus();
                }
            });
            choice.htmlFor = radio.id;
            choice.dataset.selected = model.selected ? 'true' : 'false';
            choice.append(
                radio,
                v2ColumnPresetDiagram(model, 'choice'),
                element('span', 'webadmin-srOnly', model.label),
                element('span', 'blogEditor__layoutChoiceCheck', '\u2713')
            );
            fieldset.append(choice);
        });
        details.append(summary, fieldset);
        wrapper.append(details);
        return wrapper;
    }

    function v2ApplyPresetSelection(context, nodeId, preset) {
        var location = v2Location(context.documentValue, nodeId || '');
        if (
            !location
            || !['article', 'div'].includes(location.node.type)
        ) {
            return null;
        }
        var previousPreset = location.node.layout.preset;
        if (!v2SetPreset(context, location.node, preset)) {
            return null;
        }
        return {
            location: location,
            previousPreset: previousPreset,
            preset: preset
        };
    }

    function v2ApplyPresetControl(context, control) {
        return v2ApplyPresetSelection(
            context,
            control.dataset.blogV2Node,
            control.value
        );
    }

    function v2SetPreset(context, container, preset) {
        var count = COLUMN_PRESETS[preset];
        if (!count || !['article', 'div'].includes(container.type)) {
            return false;
        }
        var columns = container.layout.columns;
        var reserved = allStructuralIds(context.documentValue);
        while (columns.length < count) {
            columns.push(freshColumn(context, reserved));
        }
        if (columns.length > count) {
            var survivors = columns.slice(0, count);
            var orderedChildren = [];
            columns.forEach(function (column) {
                Array.prototype.push.apply(
                    orderedChildren,
                    column.children
                );
            });
            survivors[0].children = orderedChildren;
            survivors.slice(1).forEach(function (column) {
                column.children = [];
            });
            container.layout.columns = survivors;
        }
        container.layout.preset = preset;
        return true;
    }

    function v2HeadingLevelForTarget(context, target) {
        var ownerType = ['section', 'article', 'div'].includes(
            target.ownerType
        ) ? target.ownerType : 'div';
        var policy = headingPolicyForContext(context);
        return policy.defaults[ownerType] || policy.allowed_levels[0];
    }

    function v2ApplyPlacementDefaults(node, target) {
        if (
            !node
            || node.type === 'section'
            || !node.presentation
            || !target
        ) {
            return node;
        }
        var directSectionChild = target.ownerType === 'section';
        var fullWidthDiv = node.type === 'div';
        node.presentation.width = directSectionChild && !fullWidthDiv
            ? '60'
            : 'full';
        node.presentation.align = directSectionChild ? 'center' : 'start';
        return node;
    }

    function v2Add(context, trigger) {
        var ownerId = trigger.dataset.blogV2Owner || '';
        var columnId = trigger.dataset.blogV2Column || '';
        var index = Number(trigger.dataset.blogV2Index);
        var type = trigger.dataset.blogV2AddType || '';
        var target = v2ColumnTarget(
            context.documentValue,
            ownerId,
            columnId
        );
        if (
            !target
            || !Number.isInteger(index)
            || index < 0
            || index > target.children.length
            || v2DocumentNodeCount(context.documentValue)
                + (type === 'section' ? 2 : 1) > MAX_BLOCKS
        ) {
            return false;
        }

        var node;
        if (type === 'section' && target.ownerType === 'root') {
            node = makeV2Section(context);
            context.selectedNodeId = node.children[0].id;
            context.inspectorMode = 'edit';
        } else if (
            type === 'article'
            && target.ownerType === 'section'
        ) {
            node = makeV2Container(context, 'article');
            context.selectedNodeId = node.id;
            context.inspectorMode = 'config';
        } else if (
            type === 'div'
            && ['section', 'article', 'div'].includes(target.ownerType)
            && target.divDepth < MAX_DIV_DEPTH
        ) {
            node = makeV2Container(context, 'div');
            context.selectedNodeId = node.id;
            context.inspectorMode = 'config';
        } else if (
            INSERTABLE_BLOCK_TYPES.includes(type)
            && target.ownerType !== 'root'
        ) {
            if (type === 'image' && context.media.length === 0) {
                return false;
            }
            node = makeBlock(context, type);
            if (type === 'heading') {
                applyHeadingDefault(
                    context,
                    node,
                    v2HeadingLevelForTarget(context, target)
                );
            }
            context.selectedNodeId = node.id;
            context.inspectorMode = 'edit';
        } else {
            return false;
        }
        v2ApplyPlacementDefaults(node, target);
        target.children.splice(index, 0, node);
        return true;
    }

    function v2MutateAction(context, action, nodeId) {
        var location = v2Location(context.documentValue, nodeId);
        if (!location) {
            return false;
        }
        if (action === 'move-up' || action === 'move-down') {
            var direction = action === 'move-up' ? -1 : 1;
            if (!v2CanMove(location, direction)) {
                return false;
            }
            move(location.siblings, location.index, location.index + direction);
            return true;
        }
        if (action === 'duplicate') {
            if (!v2CanDuplicate(context, location)) {
                return false;
            }
            var duplicate = v2CloneWithIds(context, location.node);
            location.siblings.splice(location.index + 1, 0, duplicate);
            context.selectedNodeId = duplicate.id;
            return true;
        }
        if (action === 'delete') {
            if (!v2CanDelete(location)) {
                return false;
            }
            location.siblings.splice(location.index, 1);
            context.selectedNodeId = null;
            return true;
        }
        return false;
    }

    function v2ActionButton(label, action, nodeId, disabled) {
        var button = element('button', '', label);
        button.type = 'button';
        button.dataset.blogV2Action = action;
        if (nodeId) {
            button.dataset.blogV2Node = nodeId;
        }
        button.disabled = Boolean(disabled);
        return button;
    }

    function v2EditIcon() {
        var namespace = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(namespace, 'svg');
        svg.classList.add('blogEditor__builderActionIcon');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '1.8');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');

        var pencil = document.createElementNS(namespace, 'path');
        pencil.setAttribute(
            'd',
            'M4 20h4L19.5 8.5a2.12 2.12 0 0 0-3-3L5 17v3Z'
        );
        var pencilDetail = document.createElementNS(namespace, 'path');
        pencilDetail.setAttribute('d', 'm14.5 5.5 4 4');
        svg.append(pencil, pencilDetail);
        return svg;
    }

    function v2TrashIcon() {
        var namespace = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(namespace, 'svg');
        svg.classList.add('blogEditor__builderActionIcon');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '1.8');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');

        var lid = document.createElementNS(namespace, 'path');
        lid.setAttribute('d', 'M3 6h18M9 6V4h6v2');
        var bin = document.createElementNS(namespace, 'path');
        bin.setAttribute('d', 'm6 6 1 14h10l1-14M10 10v6M14 10v6');
        svg.append(lid, bin);
        return svg;
    }

    function v2MoveIcon() {
        var namespace = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(namespace, 'svg');
        svg.classList.add('blogEditor__builderActionIcon');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        svg.setAttribute('fill', 'currentColor');
        [7, 12, 17].forEach(function (y) {
            [8, 16].forEach(function (x) {
                var circle = document.createElementNS(namespace, 'circle');
                circle.setAttribute('cx', String(x));
                circle.setAttribute('cy', String(y));
                circle.setAttribute('r', '1.35');
                svg.append(circle);
            });
        });
        return svg;
    }

    function v2InspectorActionIcon(direction) {
        var namespace = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(namespace, 'svg');
        svg.classList.add('blogEditor__inspectorActionIcon');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        var path = document.createElementNS(namespace, 'path');
        path.setAttribute(
            'd',
            direction === 'up'
                ? 'm6 11 6-6 6 6M12 5v14'
                : 'm6 13 6 6 6-6M12 5v14'
        );
        svg.append(path);
        return svg;
    }

    function v2InspectorIconButton(label, action, nodeId, disabled, icon) {
        var button = v2ActionButton('', action, nodeId, disabled);
        button.className = 'blogEditor__inspectorAction blogEditor__inspectorAction--icon';
        button.setAttribute('aria-label', label);
        tooltip(button, label);
        button.append(v2InspectorActionIcon(icon));
        return button;
    }

    function v2IconActionButton(label, action, nodeId, disabled, icon) {
        var button = v2ActionButton('', action, nodeId, disabled);
        button.className = 'blogEditor__builderIconButton';
        button.setAttribute('aria-label', label);
        tooltip(button, label);
        if (icon === 'delete') {
            button.classList.add('blogEditor__builderIconButton--delete');
            button.append(v2TrashIcon());
        } else if (icon === 'move') {
            button.classList.add('blogEditor__builderDragHandle');
            button.append(v2MoveIcon());
        } else {
            button.append(v2EditIcon());
        }
        return button;
    }

    function v2NodeActions(context, node) {
        var actions = element('div', 'blogEditor__builderActions');
        actions.setAttribute('role', 'group');
        actions.setAttribute(
            'aria-label',
            'Acciones de ' + (
                BLOCK_LABELS[node.type] || CONTAINER_LABELS[node.type]
            )
        );
        var location = v2Location(context.documentValue, node.id);
        var dragLabel = context.keyboardDragNodeId === node.id
            ? 'Cancelar movimiento'
            : 'Mover: arrastra o pulsa para elegir destino';
        var dragHandle = v2IconActionButton(
            dragLabel,
            'drag-handle',
            node.id,
            context.readOnly || !v2CanDrag(location),
            'move'
        );
        dragHandle.draggable = !dragHandle.disabled;
        dragHandle.setAttribute(
            'aria-pressed',
            context.keyboardDragNodeId === node.id ? 'true' : 'false'
        );
        actions.append(
            dragHandle,
            v2IconActionButton(
            BLOCK_TYPES.includes(node.type) && node.type !== 'separator'
                ? 'Editar'
                : 'Configurar',
            'edit',
            node.id,
            context.readOnly
            )
        );
        if (v2CanDelete(location)) {
            actions.append(v2IconActionButton(
                'Eliminar',
                'delete',
                node.id,
                context.readOnly,
                'delete'
            ));
        }
        return actions;
    }

    function v2InsertOptions(target) {
        if (target.ownerType === 'root') {
            return [{
                type: 'section',
                label: 'Secci\u00f3n',
                group: 'structure'
            }];
        }
        var options = [];
        INSERTABLE_BLOCK_TYPES.filter(function (type) {
            return type !== 'heading' || target.ownerType !== 'section';
        }).forEach(function (type) {
            options.push({
                type: type,
                label: BLOCK_LABELS[type],
                group: 'content'
            });
        });
        if (target.ownerType === 'section') {
            options.push({
                type: 'article',
                label: 'Art\u00edculo',
                group: 'structure'
            });
        }
        if (target.divDepth < MAX_DIV_DEPTH) {
            options.push({
                type: 'div',
                label: 'Contenedor',
                group: 'structure'
            });
        }
        return options;
    }

    function v2TargetColumnNumber(target) {
        if (
            !target
            || !target.column
            || !target.owner
            || !target.owner.layout
            || !Array.isArray(target.owner.layout.columns)
        ) {
            return null;
        }
        var index = target.owner.layout.columns.indexOf(target.column);
        return index < 0 ? null : index + 1;
    }

    function v2TargetContextLabel(target) {
        var columnNumber = v2TargetColumnNumber(target);
        if (columnNumber !== null) {
            return 'la columna ' + columnNumber + ' de '
                + (CONTAINER_LABELS[target.ownerType] || 'contenedor')
                    .toLowerCase();
        }
        if (target && target.ownerType === 'section') {
            return 'la secci\u00f3n';
        }
        if (target && target.ownerType === 'root') {
            return 'el documento';
        }
        return 'esta posici\u00f3n';
    }

    function v2SetInsertExpanded(toggle, menu, expanded) {
        var isExpanded = Boolean(expanded);
        var targetLabel = toggle.dataset.blogInsertContext
            || 'esta posici\u00f3n';
        menu.hidden = !isExpanded;
        toggle.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
        toggle.setAttribute(
            'aria-label',
            isExpanded
                ? 'Cerrar opciones de inserci\u00f3n en ' + targetLabel
                : 'A\u00f1adir contenido en ' + targetLabel
        );
        tooltip(
            toggle,
            isExpanded ? 'Cerrar opciones' : 'A\u00f1adir contenido'
        );
    }

    function v2InsertPoint(context, target, index) {
        var insertion = element('div', 'blogEditor__insert');
        var ownerId = target.owner.id || 'root';
        var columnId = target.column ? target.column.id : '';
        var targetLabel = v2TargetContextLabel(target);
        insertion.dataset.blogV2DropTarget = 'true';
        insertion.dataset.blogV2Owner = ownerId;
        insertion.dataset.blogV2Column = columnId;
        insertion.dataset.blogV2Index = String(index);
        if (context.keyboardDragNodeId) {
            var keyboardPlan = v2DropPlan(
                context,
                context.keyboardDragNodeId,
                ownerId,
                columnId,
                index
            );
            var keyboardDrop = v2ActionButton(
                '',
                'keyboard-drop',
                context.keyboardDragNodeId,
                !keyboardPlan
            );
            keyboardDrop.className = 'blogEditor__insertToggle '
                + 'blogEditor__insertToggle--drop';
            keyboardDrop.dataset.blogV2Owner = ownerId;
            keyboardDrop.dataset.blogV2Column = columnId;
            keyboardDrop.dataset.blogV2Index = String(index);
            keyboardDrop.setAttribute(
                'aria-label',
                keyboardPlan
                    ? 'Mover el elemento a ' + targetLabel
                    : 'No se puede mover el elemento a ' + targetLabel
            );
            tooltip(
                keyboardDrop,
                keyboardPlan ? 'Mover aquí' : 'Posición no válida'
            );
            insertion.dataset.blogV2KeyboardDrop = keyboardPlan
                ? 'available'
                : 'blocked';
            insertion.append(keyboardDrop);
            return insertion;
        }
        var nodeCount = v2DocumentNodeCount(context.documentValue);
        var toggle = v2ActionButton(
            '',
            'toggle-insert',
            '',
            context.readOnly
                || nodeCount + (target.ownerType === 'root' ? 2 : 1)
                    > MAX_BLOCKS
        );
        toggle.className = 'blogEditor__insertToggle';
        toggle.dataset.blogInsertContext = targetLabel;
        toggle.setAttribute('aria-haspopup', 'true');
        var menu = element('div', 'blogEditor__insertMenu');
        var menuId = controlId(context, 'v2-insert-menu');
        menu.id = menuId;
        menu.hidden = true;
        menu.setAttribute(
            'aria-label',
            'Elementos disponibles para ' + targetLabel
        );
        toggle.setAttribute('aria-controls', menuId);
        v2SetInsertExpanded(toggle, menu, false);
        var options = v2InsertOptions(target);
        ['content', 'structure'].forEach(function (groupName) {
            var definitions = options.filter(function (option) {
                return option.group === groupName;
            });
            if (definitions.length === 0) {
                return;
            }
            var group = element('div', 'blogEditor__insertGroup');
            var groupTitle = element(
                'p',
                'blogEditor__insertGroupTitle',
                groupName === 'content' ? 'Contenido' : 'Estructura'
            );
            groupTitle.id = controlId(context, 'v2-insert-group');
            var optionList = element('div', 'blogEditor__insertOptions');
            optionList.setAttribute('role', 'group');
            optionList.setAttribute('aria-labelledby', groupTitle.id);
            definitions.forEach(function (option) {
                var button = v2ActionButton(
                    option.label,
                    'add',
                    '',
                    context.readOnly
                        || (option.type === 'image' && context.media.length === 0)
                        || nodeCount
                            + (option.type === 'section' ? 2 : 1) > MAX_BLOCKS
                );
                button.dataset.blogV2Owner = ownerId;
                button.dataset.blogV2Column = columnId;
                button.dataset.blogV2Index = String(index);
                button.dataset.blogV2AddType = option.type;
                optionList.append(button);
            });
            group.append(groupTitle, optionList);
            menu.append(group);
        });
        insertion.append(toggle, menu);
        return insertion;
    }

    function v2ApplyImagePresentation(module, presentation) {
        if (!(module instanceof HTMLElement)) {
            return;
        }
        module.dataset.imageRadius = PRESENTATION_IMAGE_RADII.includes(
            presentation.radius
        ) ? presentation.radius : 'default';
        var properties = [
            ['height_dvh', 'blogImageHeightDvh'],
            ['object_fit', 'blogImageObjectFit'],
            ['object_position_y', 'blogImageObjectPositionY'],
            ['radius_percent', 'blogImageRadiusPercent'],
            ['overlay_mode', 'blogImageOverlayMode'],
            ['overlay_opacity', 'blogImageOverlayOpacity']
        ];
        properties.forEach(function (definition) {
            var value = presentation[definition[0]];
            if (value === undefined) {
                delete module.dataset[definition[1]];
            } else {
                module.dataset[definition[1]] = String(value);
            }
        });
        [
            '--blog-editor-image-height',
            '--blog-editor-image-radius',
            '--blog-editor-image-overlay-color',
            '--blog-editor-image-overlay-opacity'
        ].forEach(function (property) {
            module.style.removeProperty(property);
        });
        if (presentation.height_dvh !== undefined) {
            module.style.setProperty(
                '--blog-editor-image-height',
                presentation.height_dvh + 'dvh'
            );
        }
        if (presentation.radius_percent !== undefined) {
            module.style.setProperty(
                '--blog-editor-image-radius',
                presentation.radius_percent + '%'
            );
        }
        if (presentation.overlay_color === undefined) {
            delete module.dataset.blogImageOverlayColor;
        } else {
            var overlayRgba = canonicalRgba(presentation.overlay_color);
            module.dataset.blogImageOverlayColor = overlayRgba === null
                ? presentation.overlay_color
                : 'rgba';
            if (overlayRgba !== null) {
                module.style.setProperty(
                    '--blog-editor-image-overlay-color',
                    overlayRgba
                );
            }
        }
        if (presentation.overlay_opacity !== undefined) {
            module.style.setProperty(
                '--blog-editor-image-overlay-opacity',
                String(presentation.overlay_opacity / 100)
            );
        }
    }

    function v2UpdateImagePresentationPreview(context, node) {
        var module = context.blockList.querySelector(
            '.blogEditor__builderModule--image[data-blog-v2-node="'
                + node.id + '"]'
        );
        v2ApplyImagePresentation(module, node.presentation);
    }

    function v2RenderModule(context, block) {
        var item = element(
            'div',
            'blogEditor__builderModule blogEditor__builderModule--'
                + block.type
        );
        item.dataset.blogV2Node = block.id;
        item.dataset.blogV2Selectable = 'true';
        item.dataset.blogBlockTitle = block.id;
        item.tabIndex = 0;
        item.setAttribute('role', 'group');
        item.setAttribute(
            'aria-label',
            'Configurar ' + (
                block.type === 'image' && block.display === 'cover'
                    ? 'HERO'
                    : BLOCK_LABELS[block.type]
            )
        );
        item.dataset.selected = context.selectedNodeId === block.id
            ? 'true'
            : 'false';
        item.dataset.width = block.presentation.width;
        item.dataset.align = block.presentation.align;
        item.dataset.textAlign = block.presentation.text_align || 'start';
        item.dataset.size = canonicalPresentationSize(
            block.presentation.size || block.presentation.font_size
        ) || 'm';
        item.dataset.spacingBefore = block.presentation.spacing_before || 'none';
        item.dataset.spacingAfter = block.presentation.spacing_after || 'none';
        if (['paragraph', 'heading', 'list'].includes(block.type)) {
            item.dataset.fontWeight = block.presentation.font_weight || 'default';
            var textColor = block.presentation.text_color || 'default';
            var textRgba = canonicalRgba(textColor);
            item.dataset.textColor = textRgba === null ? textColor : 'rgba';
            if (textRgba !== null) {
                item.style.setProperty(
                    '--blog-editor-module-text-color',
                    textRgba
                );
            }
        }
        var previewHeading = block.type === 'heading'
            ? block
            : v2LeadingTextHeading(block);
        if (previewHeading !== null) {
            item.dataset.headingPreset = previewHeading.preset || 'default';
            var headingPreset = context.headingPresetCatalog.find(
                function (preset) {
                    return preset.token === item.dataset.headingPreset;
                }
            );
            if (headingPreset) {
                item.classList.add(headingPreset.preview_class);
            }
        } else if (block.type === 'list') {
            item.dataset.listMarker = block.marker
                || (block.ordered ? 'decimal' : 'disc');
        } else if (block.type === 'quote') {
            item.dataset.quotePreset = block.preset;
        } else if (block.type === 'cta') {
            item.dataset.buttonPreset = block.variant;
            item.dataset.textAlign = 'start';
            item.dataset.buttonAlign = block.presentation.text_align || 'start';
        } else if (block.type === 'image') {
            item.dataset.imageRadius = block.presentation.radius || 'default';
            v2ApplyImagePresentation(item, block.presentation);
        } else if (block.type === 'separator') {
            item.dataset.separatorStyle = block.line_style;
            item.dataset.separatorThickness = block.thickness;
            item.dataset.separatorColor = canonicalRgba(block.color) === null
                ? block.color
                : 'rgba';
        }
        var label = element(
            'span',
            'blogEditor__builderLabel',
            block.type === 'image' && block.display === 'cover'
                ? 'HERO'
                : (block.type === 'heading'
                    ? 'H' + block.level + ' \u00b7 T\u00edtulo'
                    : BLOCK_LABELS[block.type])
        );
        var content = element('div', 'blogEditor__builderContent');
        var placeholderText = block.type === 'heading'
            ? 'Escribe el t\u00edtulo H' + block.level
            : (block.type === 'paragraph' ? 'Escribe el texto' : '');
        var emptyTextBlock = placeholderText !== ''
            && richTextBlockEmpty(block);
        if (emptyTextBlock) {
            var placeholder = element(
                'div',
                'blogEditor__builderTextPlaceholder'
                    + (block.type === 'heading'
                        ? ' blogEditor__builderHeadingPlaceholder'
                        : ''),
                placeholderText
            );
            if (block.type === 'heading') {
                placeholder.setAttribute('role', 'heading');
                placeholder.setAttribute('aria-level', String(block.level));
            }
            content.append(placeholder);
        } else {
            content.append(blockPreview(context, block));
        }
        var moduleActions = v2NodeActions(context, block);
        item.append(label);
        if (moduleActions) {
            item.append(moduleActions);
        }
        item.append(content);
        return item;
    }

    function v2RenderChildren(context, target) {
        var fragment = document.createDocumentFragment();
        target.children.forEach(function (child, index) {
            fragment.append(v2RenderNode(context, child, target));
            fragment.append(v2InsertPoint(context, target, index + 1));
        });
        if (target.children.length === 0) {
            fragment.append(v2InsertPoint(context, target, 0));
        }
        return fragment;
    }

    function v2RenderLayoutControl(context, container) {
        return v2ColumnPresetControl(context, container, 'canvas');
    }

    function v2RenderContainer(context, container, parentTarget) {
        var wrapper = element(
            'div',
            'blogEditor__builderContainer blogEditor__builderContainer--'
                + container.type
        );
        wrapper.dataset.blogV2Node = container.id;
        wrapper.dataset.blogV2Selectable = 'true';
        wrapper.tabIndex = 0;
        wrapper.setAttribute('role', 'group');
        wrapper.setAttribute(
            'aria-label',
            'Configurar ' + CONTAINER_LABELS[container.type]
        );
        wrapper.dataset.selected = context.selectedNodeId === container.id
            ? 'true'
            : 'false';
        var containerPresentation = container.presentation || {};
        if (['article', 'div'].includes(container.type)) {
            wrapper.dataset.width = PRESENTATION_WIDTHS.includes(
                containerPresentation.width
            ) ? containerPresentation.width : 'full';
            wrapper.dataset.align = PRESENTATION_ALIGNS.includes(
                containerPresentation.align
            ) ? containerPresentation.align : 'start';
        }
        var background = container.presentation
            ? container.presentation.background
            : null;
        if (PRESENTATION_BACKGROUNDS.includes(background)) {
            wrapper.dataset.background = background;
        } else {
            var rgba = canonicalRgba(background);
            if (rgba !== null) {
                wrapper.dataset.background = 'rgba';
                wrapper.style.setProperty(
                    '--blog-editor-container-background',
                    rgba
                );
            } else {
                wrapper.dataset.background = 'none';
            }
        }
        var padding = containerPresentation.padding;
        wrapper.dataset.padding = CONTAINER_PADDINGS.includes(padding)
            ? padding
            : 'default';
        var containerTextColor = containerPresentation.text_color;
        var textRgba = canonicalRgba(containerTextColor);
        var palette = richAdvancedPreviewPalette(context.form);
        if (PRESENTATION_BACKGROUNDS.includes(containerTextColor)) {
            wrapper.dataset.containerTextColor = containerTextColor;
            wrapper.style.setProperty(
                '--blog-editor-container-text-color',
                palette[containerTextColor]
            );
        } else if (textRgba !== null) {
            wrapper.dataset.containerTextColor = 'rgba';
            wrapper.style.setProperty(
                '--blog-editor-container-text-color',
                textRgba
            );
        } else if (background !== null && background !== undefined) {
            wrapper.dataset.containerTextColor = 'auto';
            wrapper.style.setProperty(
                '--blog-editor-container-text-color',
                automaticContainerTextColor(background, palette)
            );
        }
        var label = element(
            'span',
            'blogEditor__builderLabel',
            CONTAINER_LABELS[container.type]
        );
        wrapper.append(label, v2NodeActions(context, container));
        if (container.type === 'section') {
            wrapper.append(v2RenderChildren(context, {
                owner: container,
                ownerType: 'section',
                children: container.children,
                column: null,
                divDepth: 0,
                article: null
            }));
            return wrapper;
        }

        var location = v2Location(context.documentValue, container.id);
        var depth = container.type === 'div'
            ? (
                location && location.ownerType === 'div'
                    ? location.divDepth + 1
                    : 1
            )
            : (location ? location.divDepth : 0);
        var article = container.type === 'article'
            ? container
            : (location ? location.article : null);
        var grid = element('div', 'blogEditor__builderColumns');
        grid.dataset.preset = container.layout.preset;
        grid.dataset.columnCount = String(container.layout.columns.length);
        grid.setAttribute('role', 'group');
        grid.setAttribute(
            'aria-label',
            'Distribuci\u00f3n: ' + v2PresetLabel(container.layout.preset)
        );
        container.layout.columns.forEach(function (column, columnIndex) {
            var columnElement = element('div', 'blogEditor__builderColumn');
            var columnLabel = v2ColumnLabel(container, columnIndex);
            columnElement.dataset.column = String(columnIndex + 1);
            columnElement.dataset.contentCount = String(column.children.length);
            columnElement.setAttribute('role', 'group');
            columnElement.setAttribute(
                'aria-label',
                columnLabel + (column.children.length === 0
                    ? ', vac\u00eda'
                    : ', ' + column.children.length + ' elementos')
            );
            var guideLabel = element(
                'span',
                'blogEditor__builderColumnLabel',
                columnLabel
            );
            guideLabel.setAttribute('aria-hidden', 'true');
            columnElement.append(guideLabel, v2RenderChildren(context, {
                owner: container,
                ownerType: container.type,
                children: column.children,
                column: column,
                divDepth: depth,
                article: article
            }));
            grid.append(columnElement);
        });
        wrapper.append(v2RenderLayoutControl(context, container), grid);
        return wrapper;
    }

    function v2RenderNode(context, node, parentTarget) {
        return BLOCK_TYPES.includes(node.type)
            ? v2RenderModule(context, node)
            : v2RenderContainer(context, node, parentTarget);
    }

    function currentHeaderSelection(context) {
        if (
            context.documentValue.version === VERSION
            && validHeaderSelection(
                context.documentValue.header,
                context.documentValue.template
            )
        ) {
            return context.documentValue.header;
        }
        return context.headerSelection;
    }

    function renderPostHeaderContent(context, preset) {
        var definitions = {
            moduleH1Type01: {
                title: 'moduleH1Type01-header',
                eyebrow: 'destacado',
                intro: 'destacado'
            },
            moduleH1Type03: {
                title: 'moduleH1Type03-title',
                eyebrow: 'moduleH1Type03-eyebrow',
                intro: 'moduleH1Type03-text'
            },
            moduleH1Type04: {
                title: 'moduleH1Type04-title',
                eyebrow: 'moduleH1Type04-eyebrow',
                intro: 'moduleH1Type04-text'
            }
        };
        var selection = currentHeaderSelection(context);
        var moduleKey = selection && H1_MODULE_KEYS.includes(
            selection.h1_module
        ) ? selection.h1_module : 'moduleH1Type04';
        var definition = definitions[moduleKey] || definitions.moduleH1Type04;
        var wrapperClass = preset === 'basic'
            ? 'blogEditor__postHeaderContent'
            : preset + '-content blogEditor__postHeaderContent';
        var wrapper = element('div', wrapperClass);
        var module = element(
            'div',
            moduleKey + ' blogEditor__postHeading '
                + 'blogEditor__postHeading--' + moduleKey
        );
        module.setAttribute('data-blog-h1-module', moduleKey);
        var h1 = element(
            'h1',
            definition.title + ' blogEditor__postTitle',
            context.h1Input && context.h1Input.value.trim() !== ''
                ? context.h1Input.value
                : 'T\u00edtulo del art\u00edculo'
        );
        module.append(h1);
        var excerpt = context.excerptInput
            ? context.excerptInput.value.trim()
            : '';
        if (excerpt !== '') {
            module.append(element(
                'p',
                definition.intro + ' blogEditor__postExcerpt '
                    + 'blogEditor__postExcerpt--' + moduleKey,
                excerpt
            ));
        }
        wrapper.append(module);
        return wrapper;
    }

    function refreshV2Canvas(context) {
        context.blockList.replaceChildren();
        var post = element('div', 'blogEditor__postPreview blogEditor__builder');
        post.setAttribute('role', 'document');
        var selection = currentHeaderSelection(context);
        var preset = selection && selection.hero
            ? selection.hero
            : templateHeroPreset(context.documentValue.template);
        var header = element(
            'header',
            'blogEditor__postHeader blogEditor__postHeader--' + preset
                + (preset === 'basic' ? '' : ' ' + preset)
        );
        header.dataset.semanticTag = 'header';

        var offset = 0;
        if (
            templateHasCover(context.documentValue.template)
            && context.documentValue.blocks[0]
            && context.documentValue.blocks[0].type === 'image'
        ) {
            header.append(v2RenderModule(context, context.documentValue.blocks[0]));
            offset = 1;
        }
        header.append(renderPostHeaderContent(context, preset));
        post.append(header);

        var main = element('div', 'blogEditor__postMain blogEditor__builderMain');
        main.dataset.semanticTag = 'main';
        var rootTarget = {
            owner: context.documentValue,
            ownerType: 'root',
            children: context.documentValue.blocks,
            column: null,
            divDepth: 0,
            article: null
        };
        main.append(v2InsertPoint(context, rootTarget, offset));
        for (var index = offset; index < context.documentValue.blocks.length; index += 1) {
            main.append(v2RenderContainer(
                context,
                context.documentValue.blocks[index],
                rootTarget
            ));
            main.append(v2InsertPoint(context, rootTarget, index + 1));
        }
        post.append(main);
        context.blockList.append(post);
    }

    function refreshVisualCanvas(context) {
        if (context.documentValue.version === VERSION) {
            refreshV2Canvas(context);
            return;
        }
        var blocks = context.documentValue.blocks;
        context.blockList.replaceChildren();
        var post = element('div', 'blogEditor__postPreview');
        post.setAttribute('role', 'document');
        var selection = currentHeaderSelection(context);
        var preset = selection && selection.hero
            ? selection.hero
            : templateHeroPreset(context.documentValue.template);
        var header = element(
            'header',
            'blogEditor__postHeader blogEditor__postHeader--' + preset
                + (preset === 'basic' ? '' : ' ' + preset)
        );
        header.dataset.semanticTag = 'header';

        var firstContentIndex = 0;
        if (
            templateHasCover(context.documentValue.template)
            && blocks[0]
            && blocks[0].type === 'image'
        ) {
            header.append(visualBlock(context, blocks[0], 0));
            firstContentIndex = 1;
        }
        header.append(renderPostHeaderContent(context, preset));
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

    function v2ConfigSelect(context, labelText, value, options, nodeId, key) {
        var wrapper = element('div', 'blogEditor__field');
        var label = document.createElement('label');
        var select = document.createElement('select');
        select.id = controlId(context, 'v2-' + key);
        select.dataset.blogV2Config = key;
        select.dataset.blogV2Node = nodeId;
        select.disabled = context.readOnly;
        options.forEach(function (definition) {
            var option = document.createElement('option');
            option.value = definition.value;
            option.textContent = definition.label;
            option.selected = definition.value === value;
            option.disabled = definition.disabled === true;
            select.append(option);
        });
        label.htmlFor = select.id;
        label.textContent = labelText;
        wrapper.append(label, select);
        return wrapper;
    }

    function v2ConfigInput(
        context,
        labelText,
        value,
        nodeId,
        key,
        options
    ) {
        var wrapper = element('div', 'blogEditor__field');
        var label = document.createElement('label');
        var settings = options || {};
        var control = document.createElement(
            settings.multiline ? 'textarea' : 'input'
        );
        control.id = controlId(context, 'v2-' + key);
        control.dataset.blogV2Config = key;
        control.dataset.blogV2Node = nodeId;
        control.value = value || '';
        control.disabled = context.readOnly;
        if (settings.maxLength) {
            control.maxLength = settings.maxLength;
        }
        if (settings.multiline && settings.rows) {
            control.rows = settings.rows;
        }
        label.htmlFor = control.id;
        label.textContent = labelText;
        wrapper.append(label, control);
        return wrapper;
    }

    function v2ImageRangeControl(
        context,
        labelText,
        current,
        nodeId,
        key,
        policy,
        automaticLabel,
        suffix
    ) {
        var wrapper = element(
            'div',
            'blogEditor__field blogEditor__imageRangeField'
        );
        var label = document.createElement('label');
        var range = document.createElement('input');
        var allowsAutomatic = typeof automaticLabel === 'string';
        var automatic = allowsAutomatic && !Number.isInteger(current);
        var fallback = key === 'image-radius-percent'
            ? policy.min
            : Math.min(
                policy.max,
                Math.max(
                    policy.min,
                    Math.round(50 / policy.step) * policy.step
                )
            );
        range.id = controlId(context, 'v2-' + key);
        range.type = 'range';
        range.min = String(policy.min);
        range.max = String(policy.max);
        range.step = String(policy.step);
        range.value = String(automatic ? fallback : current);
        range.dataset.blogV2Config = key;
        range.dataset.blogV2Node = nodeId;
        range.dataset.blogImageRangeSuffix = suffix;
        range.disabled = context.readOnly || automatic;
        label.htmlFor = range.id;
        label.textContent = labelText;
        var output = element(
            'output',
            'blogEditor__imageRangeOutput',
            automatic ? automaticLabel : range.value + suffix
        );
        output.setAttribute('for', range.id);
        output.dataset.blogImageRangeOutput = key;
        var autoLabel = element(
            'label',
            'blogEditor__imageAutomaticToggle'
        );
        var auto = document.createElement('input');
        auto.type = 'checkbox';
        auto.checked = automatic;
        auto.dataset.blogV2Config = key + '-auto';
        auto.dataset.blogV2Node = nodeId;
        auto.disabled = context.readOnly;
        autoLabel.append(auto, document.createTextNode(automaticLabel));
        wrapper.append(label, output, range);
        if (allowsAutomatic) {
            wrapper.append(autoLabel);
        }
        return wrapper;
    }

    function v2SvgIcon(name, value) {
        if (name === 'position') {
            var position = ['start', 'center', 'end'].includes(value)
                ? value
                : 'center';
            var file = {
                start: 'left',
                center: 'center',
                end: 'right'
            }[position];
            var image = document.createElement('img');
            image.className = 'blogEditor__choiceIcon';
            image.src = '/assets/modules/blog/icons/position-' + file + '.svg';
            image.alt = '';
            image.setAttribute('aria-hidden', 'true');
            image.setAttribute('draggable', 'false');
            return image;
        }
        var namespace = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(namespace, 'svg');
        svg.classList.add('blogEditor__choiceIcon');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '1.8');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');

        function path(data) {
            var node = document.createElementNS(namespace, 'path');
            node.setAttribute('d', data);
            svg.append(node);
        }
        if (name === 'text-align') {
            var lengths = value === 'justify' ? [18, 18, 18, 18] : [18, 13, 17, 11];
            lengths.forEach(function (length, index) {
                var start = value === 'center'
                    ? (24 - length) / 2
                    : (value === 'end' ? 21 - length : 3);
                path('M' + start + ' ' + (6 + index * 4)
                    + 'h' + length);
            });
        } else if (name === 'object-position-y') {
            var positionY = {
                top: 7,
                center: 12,
                bottom: 17
            }[value] || 12;
            var frame = document.createElementNS(namespace, 'rect');
            frame.setAttribute('x', '4');
            frame.setAttribute('y', '4');
            frame.setAttribute('width', '16');
            frame.setAttribute('height', '16');
            frame.setAttribute('rx', '1.5');
            svg.append(frame);
            path('M7 ' + positionY + 'h10');
            path('M12 ' + (positionY - 2) + 'v4');
        } else if (name === 'radius') {
            var radius = {
                default: 3,
                none: 0,
                small: 1.5,
                medium: 3,
                large: 6
            }[value];
            var radiusRect = document.createElementNS(namespace, 'rect');
            radiusRect.setAttribute('x', '4');
            radiusRect.setAttribute('y', '5');
            radiusRect.setAttribute('width', '16');
            radiusRect.setAttribute('height', '14');
            radiusRect.setAttribute('rx', String(radius === undefined ? 3 : radius));
            svg.append(radiusRect);
        } else if (name === 'list-type') {
            if (value === 'ordered') {
                path('M4 6h1M4 11h1M4 16h1M9 6h11M9 11h11M9 16h11');
            } else {
                var circles = [6, 11, 16];
                circles.forEach(function (y) {
                    var circle = document.createElementNS(namespace, 'circle');
                    circle.setAttribute('cx', '5');
                    circle.setAttribute('cy', String(y));
                    circle.setAttribute('r', '1');
                    svg.append(circle);
                });
                path('M9 6h11M9 11h11M9 16h11');
            }
        } else if (name === 'marker') {
            if (['disc', 'circle', 'square'].includes(value)) {
                var marker = value === 'square'
                    ? document.createElementNS(namespace, 'rect')
                    : document.createElementNS(namespace, 'circle');
                if (value === 'square') {
                    marker.setAttribute('x', '8');
                    marker.setAttribute('y', '8');
                    marker.setAttribute('width', '8');
                    marker.setAttribute('height', '8');
                } else {
                    marker.setAttribute('cx', '12');
                    marker.setAttribute('cy', '12');
                    marker.setAttribute('r', '4');
                    if (value === 'disc') {
                        marker.setAttribute('fill', 'currentColor');
                    }
                }
                svg.append(marker);
            } else {
                var markerText = document.createElementNS(namespace, 'text');
                markerText.setAttribute('x', '12');
                markerText.setAttribute('y', '16');
                markerText.setAttribute('text-anchor', 'middle');
                markerText.setAttribute('fill', 'currentColor');
                markerText.setAttribute('stroke', 'none');
                markerText.setAttribute('font-size', '11');
                markerText.setAttribute('font-weight', '700');
                markerText.textContent = value === 'decimal'
                    ? '1.'
                    : (value === 'lower-alpha' ? 'a.' : 'A.');
                svg.append(markerText);
            }
        }
        return svg;
    }

    function v2ConfigButtonGroup(
        context,
        labelText,
        value,
        options,
        nodeId,
        key,
        iconMode
    ) {
        var wrapper = element(
            'div',
            'blogEditor__field blogEditor__choiceField'
        );
        var label = element('span', 'blogEditor__choiceLabel', labelText);
        label.id = controlId(context, 'v2-' + key + '-choices');
        var group = element('div', 'blogEditor__choiceGroup');
        group.setAttribute('role', 'group');
        group.setAttribute('aria-labelledby', label.id);
        group.dataset.blogV2ChoiceGroup = key;

        options.forEach(function (definition) {
            var button = element(
                'button',
                'blogEditor__choiceButton',
                [
                    'position', 'text-align', 'object-position-y',
                    'list-type', 'marker', 'radius'
                ]
                    .includes(iconMode)
                    ? ''
                    : definition.label
            );
            button.type = 'button';
            button.dataset.blogV2Action = 'set-config';
            button.dataset.blogV2Config = key;
            button.dataset.blogV2Value = definition.value;
            button.dataset.blogV2Node = nodeId;
            button.disabled = context.readOnly || definition.disabled === true;
            button.setAttribute(
                'aria-label',
                labelText + ': ' + definition.label
            );
            button.setAttribute(
                'aria-pressed',
                definition.value === value ? 'true' : 'false'
            );
            if (
                [
                    'position', 'text-align', 'object-position-y',
                    'list-type', 'marker', 'radius'
                ].includes(iconMode)
            ) {
                tooltip(button, definition.label);
                button.append(v2SvgIcon(iconMode, definition.value));
            }
            if (iconMode === 'color') {
                button.classList.add('blogEditor__choiceButton--swatch');
                button.dataset.color = definition.value;
                var swatch = element('span', 'blogEditor__colorSwatch');
                swatch.dataset.color = definition.value;
                swatch.setAttribute('aria-hidden', 'true');
                button.replaceChildren(swatch);
            }
            group.append(button);
        });
        wrapper.append(label, group);
        return wrapper;
    }

    function v2HeadingFields(context, block, location) {
        var fragment = document.createDocumentFragment();
        fragment.append(field(
            'Nivel del encabezado',
            selectControl(
                context,
                'heading-level',
                String(block.level),
                headingPolicyForContext(context).allowed_levels.map(function (level) {
                    return { value: String(level), label: 'H' + level };
                }),
                function (value) {
                    block.level = Number(value);
                }
            )
        ));
        fragment.append(renderInlineEditor(
            context,
            block.content,
            false,
            v2ProtectedHeading(location)
                ? 'T\u00edtulo de la secci\u00f3n'
                : 'Texto del encabezado',
            block.id
        ));
        return fragment;
    }

    function v2InspectorSection(titleText, content, modifier) {
        var section = element(
            'section',
            'blogEditor__inspectorSection blogEditor__inspectorSection--'
                + modifier
        );
        var title = element(
            'h4',
            'blogEditor__inspectorSectionTitle',
            titleText
        );
        var body = element('div', 'blogEditor__inspectorSectionBody');
        if (Array.isArray(content)) {
            content.forEach(function (child) {
                if (child) {
                    body.append(child);
                }
            });
        } else if (content) {
            body.append(content);
        }
        section.append(title, body);
        return section;
    }

    function v2ColorControl(
        context,
        labelText,
        current,
        nodeId,
        configKey,
        resetValue,
        resetLabel
    ) {
        var definitions = [];
        if (resetValue) {
            definitions.push({
                value: resetValue,
                label: resetLabel || 'Sin color'
            });
        }
        [0, 1, 2, 3, 4, 5].forEach(function (index) {
            definitions.push({
                value: 'color0' + index,
                label: 'Color 0' + index
            });
        });
        var wrapper = v2ConfigButtonGroup(
            context,
            labelText,
            current,
            definitions,
            nodeId,
            configKey,
            'color'
        );
        wrapper.classList.add('blogEditor__colorSelector');
        var custom = element(
            'button',
            'blogEditor__customColorToggle',
            'Color personalizado'
        );
        custom.type = 'button';
        custom.dataset.blogV2Action = 'toggle-custom-color';
        custom.dataset.blogV2Node = nodeId;
        custom.dataset.blogV2Config = configKey;
        custom.setAttribute('aria-expanded', 'false');
        custom.disabled = context.readOnly;

        var rgba = rgbaControls(current);
        var panel = element('div', 'blogEditor__customColorPanel');
        panel.hidden = true;
        panel.dataset.blogColorPanel = configKey;
        var colorLabel = document.createElement('label');
        colorLabel.textContent = 'Color';
        var color = document.createElement('input');
        color.type = 'color';
        color.value = rgba.color;
        color.dataset.blogColorValue = configKey;
        color.disabled = context.readOnly;
        colorLabel.append(color);
        var alphaLabel = document.createElement('label');
        alphaLabel.textContent = 'Opacidad';
        var alpha = document.createElement('input');
        alpha.type = 'number';
        alpha.min = '0';
        alpha.max = '1';
        alpha.step = '0.05';
        alpha.value = String(rgba.alpha);
        alpha.dataset.blogColorAlpha = configKey;
        alpha.disabled = context.readOnly;
        alphaLabel.append(alpha);
        var apply = element('button', '', 'Aplicar color');
        apply.type = 'button';
        apply.dataset.blogV2Action = 'apply-custom-color';
        apply.dataset.blogV2Node = nodeId;
        apply.dataset.blogV2Config = configKey;
        apply.disabled = context.readOnly;
        panel.append(colorLabel, alphaLabel, apply);
        wrapper.append(custom, panel);
        return wrapper;
    }

    function v2TypographyOptions(context, node) {
        var presentation = node.presentation;
        var options = [
            v2ConfigButtonGroup(
                context,
                'Grosor',
                presentation.font_weight || 'default',
                [
                    { value: 'default', label: 'Auto' },
                    { value: 'regular', label: '400' },
                    { value: 'medium', label: '500' },
                    { value: 'semibold', label: '600' },
                    { value: 'bold', label: '700' }
                ],
                node.id,
                'font-weight',
                'font-weight'
            ),
            v2ColorControl(
                context,
                'Color',
                presentation.text_color || 'default',
                node.id,
                'text-color',
                'default',
                'Heredado'
            )
        ];
        return options;
    }

    function v2ModuleSizeOptions(context, node) {
        return v2ConfigButtonGroup(
            context,
            'Tama\u00f1o del m\u00f3dulo',
            canonicalPresentationSize(
                node.presentation.size || node.presentation.font_size
            ) || 'm',
            [
                { value: 's', label: 'S' },
                { value: 'm', label: 'M' },
                { value: 'l', label: 'L' },
                { value: 'xl', label: 'XL' }
            ],
            node.id,
            'size',
            'size'
        );
    }

    function v2ModuleSpacingOptions(context, node) {
        var presentation = node.presentation || {};
        var choices = [
            { value: 'none', label: '0' },
            { value: 's', label: 'S' },
            { value: 'm', label: 'M' },
            { value: 'l', label: 'L' },
            { value: 'xl', label: 'XL' }
        ];
        return [
            v2ConfigButtonGroup(
                context,
                'Espacio anterior',
                presentation.spacing_before || 'none',
                choices,
                node.id,
                'spacing-before',
                'spacing'
            ),
            v2ConfigButtonGroup(
                context,
                'Espacio posterior',
                presentation.spacing_after || 'none',
                choices,
                node.id,
                'spacing-after',
                'spacing'
            )
        ];
    }

    function v2ContainerBackgroundOptions(context, node) {
        var presentation = node.presentation || {};
        return [
            v2ColorControl(
                context,
                'Fondo del contenedor',
                presentation.background || 'none',
                node.id,
                'container-background',
                'none',
                'Sin fondo'
            ),
            v2ColorControl(
                context,
                'Color del texto',
                presentation.text_color || 'auto',
                node.id,
                'container-text-color',
                'auto',
                'Automático'
            ),
            v2ConfigButtonGroup(
                context,
                'Relleno',
                presentation.padding || 'default',
                [
                    { value: 'none', label: '0' },
                    { value: 's', label: 'S' },
                    { value: 'm', label: 'M' },
                    { value: 'l', label: 'L' }
                ],
                node.id,
                'container-padding',
                'padding'
            )
        ];
    }

    function v2TextAlignmentOptions(context, node) {
        return v2ConfigButtonGroup(
            context,
            'Alineaci\u00f3n del texto',
            node.presentation.text_align || 'start',
            [
                { value: 'start', label: 'Izquierda' },
                { value: 'center', label: 'Centro' },
                { value: 'end', label: 'Derecha' },
                { value: 'justify', label: 'Justificado' }
            ],
            node.id,
            'text-align',
            'text-align'
        );
    }

    function v2ButtonAlignmentOptions(context, node) {
        return v2ConfigButtonGroup(
            context,
            'Alineaci\u00f3n del bot\u00f3n',
            node.presentation.text_align || 'start',
            [
                { value: 'start', label: 'Izquierda' },
                { value: 'center', label: 'Centro' },
                { value: 'end', label: 'Derecha' }
            ],
            node.id,
            'text-align',
            'text-align'
        );
    }

    function v2HeadingConfig(context, node, location) {
        var content = [];
        content.push(v2ConfigSelect(
            context,
            'Nivel sem\u00e1ntico',
            String(node.level),
            headingPolicyForContext(context).allowed_levels.map(function (level) {
                return { value: String(level), label: 'H' + level };
            }),
            node.id,
            'heading-level'
        ));
        if (v2ProtectedHeading(location)) {
            content.push(element(
                'p',
                'blogEditor__inspectorHint',
                'Este t\u00edtulo identifica la secci\u00f3n; puedes elegir H2-H6.'
            ));
        }
        content.push(v2ConfigButtonGroup(
            context,
            'Estilo visual',
            node.preset || 'default',
            context.headingPresetCatalog.map(function (preset) {
                return {
                    value: preset.token,
                    label: preset.label
                };
            }),
            node.id,
            'heading-preset',
            'preset'
        ));
        var apply = v2ActionButton(
            'Aplicar este estilo a todos los H' + node.level,
            'apply-heading-level',
            node.id,
            context.readOnly
        );
        apply.className = 'blogEditor__applyPreset';
        content.push(apply);
        Array.prototype.push.apply(content, v2TypographyOptions(context, node));
        content.push(v2TextAlignmentOptions(context, node));
        return content;
    }

    function v2UnifiedTextHeadingConfig(context, node, location, heading) {
        var content = [v2ConfigSelect(
            context,
            'Nivel semántico del encabezado inicial',
            String(heading.level),
            headingPolicyForContext(context).allowed_levels.map(
                function (level) {
                    return { value: String(level), label: 'H' + level };
                }
            ),
            node.id,
            'heading-level'
        )];
        if (v2ProtectedHeading(location)) {
            content.push(element(
                'p',
                'blogEditor__inspectorHint',
                'Este encabezado identifica la sección; puedes elegir H2-H6.'
            ));
        }
        content.push(v2ConfigButtonGroup(
            context,
            'Estilo visual del encabezado inicial',
            heading.preset || 'default',
            context.headingPresetCatalog.map(function (preset) {
                return { value: preset.token, label: preset.label };
            }),
            node.id,
            'heading-preset',
            'preset'
        ));
        var apply = v2ActionButton(
            'Aplicar este estilo a todos los H' + heading.level,
            'apply-heading-level',
            node.id,
            context.readOnly
        );
        apply.className = 'blogEditor__applyPreset';
        content.push(apply);
        return content;
    }

    function v2ListConfig(context, node) {
        var ordered = node.ordered === true;
        var currentMarker = node.marker || (ordered ? 'decimal' : 'disc');
        var markers = ordered
            ? [
                { value: 'decimal', label: '1, 2, 3' },
                { value: 'lower-alpha', label: 'a, b, c' },
                { value: 'upper-alpha', label: 'A, B, C' }
            ]
            : [
                { value: 'disc', label: 'Punto' },
                { value: 'circle', label: 'C\u00edrculo' },
                { value: 'square', label: 'Cuadrado' }
            ];
        return [
            v2ConfigButtonGroup(
                context,
                'Tipo de lista',
                ordered ? 'ordered' : 'unordered',
                [
                    { value: 'unordered', label: 'Vi\u00f1etas' },
                    { value: 'ordered', label: 'Numerada' }
                ],
                node.id,
                'list-type',
                'list-type'
            ),
            v2ConfigButtonGroup(
                context,
                'Marcador',
                currentMarker,
                markers,
                node.id,
                'list-marker',
                'marker'
            ),
            v2ColorControl(
                context,
                'Color',
                node.presentation.text_color || 'default',
                node.id,
                'text-color',
                'default',
                'Heredado'
            ),
            v2TextAlignmentOptions(context, node)
        ];
    }

    function v2ImagePresentationOptions(context, node) {
        var policy = IMAGE_PRESENTATION_POLICY;
        if (policy === null) {
            return [];
        }
        var presentation = node.presentation;
        var overlayActive = [
            'overlay_mode', 'overlay_color', 'overlay_opacity'
        ].every(function (key) {
            return Object.prototype.hasOwnProperty.call(presentation, key);
        });
        var overlayToggle = element(
            'label',
            'blogEditor__imageOverlayToggle'
        );
        var overlayCheckbox = document.createElement('input');
        overlayCheckbox.type = 'checkbox';
        overlayCheckbox.checked = overlayActive;
        overlayCheckbox.disabled = context.readOnly;
        overlayCheckbox.dataset.blogV2Config = 'image-overlay-enabled';
        overlayCheckbox.dataset.blogV2Node = node.id;
        overlayToggle.append(
            overlayCheckbox,
            document.createTextNode('Superposici\u00f3n de color')
        );
        var overlayMode = v2ConfigSelect(
            context,
            'Modo de mezcla',
            overlayActive ? presentation.overlay_mode : policy.overlay.modes[0],
            policy.overlay.modes.map(function (value) {
                return {
                    value: value,
                    label: {
                        normal: 'Normal', multiply: 'Multiplicar',
                        screen: 'Trama', overlay: 'Superponer'
                    }[value]
                };
            }),
            node.id,
            'image-overlay-mode'
        );
        var overlayColor = v2ColorControl(
            context,
            'Color corporativo o libre',
            overlayActive
                ? presentation.overlay_color
                : policy.overlay.colors[0],
            node.id,
            'image-overlay-color'
        );
        var overlayOpacity = v2ImageRangeControl(
            context,
            'Opacidad de la superposici\u00f3n',
            overlayActive ? presentation.overlay_opacity : 40,
            node.id,
            'image-overlay-opacity',
            policy.overlay.opacity,
            null,
            '%'
        );
        [overlayMode, overlayColor, overlayOpacity].forEach(function (control) {
            control.classList.add('blogEditor__imageOverlayOption');
            control.dataset.overlayActive = overlayActive ? 'true' : 'false';
            control.querySelectorAll('input, select, button').forEach(
                function (input) {
                    input.disabled = context.readOnly || !overlayActive;
                }
            );
        });
        return [
            v2ImageRangeControl(
                context, 'Altura de la imagen', presentation.height_dvh,
                node.id, 'image-height-dvh', policy.height_dvh,
                'Autom\u00e1tica', 'dvh'
            ),
            v2ConfigButtonGroup(
                context,
                'Ajuste de la imagen',
                presentation.object_fit || policy.object_fit.default,
                policy.object_fit.values.map(function (value) {
                    return {
                        value: value,
                        label: value === 'cover' ? 'Cubrir' : 'Contener'
                    };
                }),
                node.id,
                'image-object-fit'
            ),
            v2ConfigButtonGroup(
                context,
                'Posici\u00f3n vertical',
                presentation.object_position_y
                    || policy.object_position_y.default,
                policy.object_position_y.values.map(function (value) {
                    return {
                        value: value,
                        label: {
                            top: 'Arriba', center: 'Centro', bottom: 'Abajo'
                        }[value]
                    };
                }),
                node.id,
                'image-object-position-y',
                'object-position-y'
            ),
            v2ImageRangeControl(
                context, 'Radio de las esquinas',
                presentation.radius_percent, node.id,
                'image-radius-percent', policy.radius_percent,
                Object.prototype.hasOwnProperty.call(presentation, 'radius')
                    ? 'Estilo heredado' : 'Autom\u00e1tico',
                '%'
            ),
            overlayToggle,
            overlayMode,
            overlayColor,
            overlayOpacity
        ];
    }

    function v2ModuleContentOptions(context, node, location) {
        if (node.type === 'heading') {
            return v2HeadingConfig(context, node, location);
        }
        if (node.type === 'paragraph') {
            var paragraph = [];
            var leadingHeading = v2LeadingTextHeading(node);
            if (leadingHeading !== null) {
                Array.prototype.push.apply(
                    paragraph,
                    v2UnifiedTextHeadingConfig(
                        context,
                        node,
                        location,
                        leadingHeading
                    )
                );
            }
            Array.prototype.push.apply(
                paragraph,
                v2TypographyOptions(context, node)
            );
            paragraph.push(v2TextAlignmentOptions(context, node));
            return paragraph;
        }
        if (node.type === 'list') {
            return v2ListConfig(context, node);
        }
        if (node.type === 'callout') {
            return [
                v2ConfigButtonGroup(
                    context,
                    'Estilo del destacado',
                    node.tone,
                    [
                        { value: 'neutral', label: 'Neutral' },
                        { value: 'info', label: 'Informativo' },
                        { value: 'warning', label: 'Aviso' }
                    ],
                    node.id,
                    'callout-tone',
                    'preset'
                ),
                v2TextAlignmentOptions(context, node)
            ];
        }
        if (node.type === 'quote') {
            return [
                v2ConfigButtonGroup(
                    context,
                    'Estilo de la cita',
                    node.preset,
                    [
                        { value: 'default', label: 'Base' },
                        { value: 'accent', label: 'Acento' },
                        { value: 'minimal', label: 'M\u00ednimo' }
                    ],
                    node.id,
                    'quote-preset',
                    'preset'
                ),
                v2ConfigInput(
                    context,
                    'Autor opcional',
                    node.author || '',
                    node.id,
                    'quote-author',
                    { maxLength: 255 }
                ),
                v2ConfigInput(
                    context,
                    'Fuente opcional',
                    node.source || '',
                    node.id,
                    'quote-source',
                    { maxLength: 500 }
                ),
                v2TextAlignmentOptions(context, node)
            ];
        }
        if (node.type === 'cta') {
            return [
                v2ConfigButtonGroup(
                    context,
                    'Estilo del bot\u00f3n',
                    node.variant,
                    [
                        { value: 'primary', label: 'S\u00f3lido' },
                        { value: 'secondary', label: 'Contorno' },
                        { value: 'type03', label: 'Animado' },
                        { value: 'type04', label: 'Cl\u00e1sico' }
                    ],
                    node.id,
                    'button-preset',
                    'preset'
                ),
                v2ButtonAlignmentOptions(context, node)
            ];
        }
        if (node.type === 'image') {
            return v2ImagePresentationOptions(context, node);
        }
        if (node.type === 'separator') {
            return [
                v2ConfigButtonGroup(
                    context,
                    'Tipo de línea',
                    node.line_style,
                    [
                        { value: 'solid', label: 'Continua' },
                        { value: 'dashed', label: 'Discontinua' },
                        { value: 'dotted', label: 'Puntos' },
                        { value: 'double', label: 'Doble' }
                    ],
                    node.id,
                    'separator-style',
                    'separator-style'
                ),
                v2ConfigButtonGroup(
                    context,
                    'Grosor',
                    node.thickness,
                    [
                        { value: 'thin', label: 'Fino' },
                        { value: 'medium', label: 'Medio' },
                        { value: 'thick', label: 'Grueso' }
                    ],
                    node.id,
                    'separator-thickness',
                    'separator-thickness'
                ),
                v2ColorControl(
                    context,
                    'Color de la línea',
                    node.color,
                    node.id,
                    'separator-color',
                    'color01',
                    'Color base'
                )
            ];
        }
        return [v2TextAlignmentOptions(context, node)];
    }

    function v2CommonActions(context, location) {
        var actions = element('div', 'blogEditor__builderInspectorActions');
        actions.setAttribute('role', 'group');
        actions.setAttribute('aria-label', 'Acciones del bloque');
        var structuralLocked = v2ProtectedHeading(location)
            || (location.topLevel && location.node.type !== 'section');
        actions.append(
            v2InspectorIconButton(
                'Subir bloque',
                'move-up',
                location.node.id,
                context.readOnly || !v2CanMove(location, -1),
                'up'
            ),
            v2InspectorIconButton(
                'Bajar bloque',
                'move-down',
                location.node.id,
                context.readOnly || !v2CanMove(location, 1),
                'down'
            ),
            v2ActionButton(
                'Duplicar',
                'duplicate',
                location.node.id,
                context.readOnly || !v2CanDuplicate(context, location)
            ),
            v2ActionButton(
                'Eliminar',
                'delete',
                location.node.id,
                context.readOnly || structuralLocked
            )
        );
        return actions;
    }

    function renderV2Inspector(context) {
        if (!context.blockInspector) {
            return;
        }
        context.blockInspector.replaceChildren();
        var location = v2Location(
            context.documentValue,
            context.selectedNodeId || ''
        );
        if (!location) {
            context.blockInspector.append(element(
                'p',
                'blogEditor__inspectorEmpty',
                'Selecciona una caja del lienzo para configurarla.'
            ));
            return;
        }

        var node = location.node;
        var moduleNode = BLOCK_TYPES.includes(node.type);
        var title = element(
            'h3',
            'blogEditor__inspectorTitle',
            moduleNode
                ? BLOCK_LABELS[node.type]
                : CONTAINER_LABELS[node.type]
        );
        context.blockInspector.append(title);
        if (context.inspectorMode === 'edit' && moduleNode) {
            var fieldset = document.createElement('fieldset');
            fieldset.disabled = context.readOnly;
            if (node.type === 'heading') {
                fieldset.append(v2HeadingFields(context, node, location));
            } else {
                fieldset.append(renderBlockFields(
                    context,
                    node,
                    location.topLevel ? location.index : -1
                ));
            }
            context.blockInspector.append(v2InspectorSection(
                'Contenido de ' + BLOCK_LABELS[node.type],
                fieldset,
                'content'
            ));
            return;
        }

        var blockOptions = [];
        var contentOptions = [];
        if (moduleNode && !location.topLevel) {
            blockOptions.push(
                v2ModuleSizeOptions(context, node),
                v2ConfigButtonGroup(
                    context,
                    'Anchura',
                    node.presentation.width,
                    [
                        { value: 'full', label: '100%' },
                        { value: '80', label: '80%' },
                        { value: '60', label: '60%' },
                        { value: '40', label: '40%' }
                    ],
                    node.id,
                    'width',
                    'width'
                ),
                v2ConfigButtonGroup(
                    context,
                    'Posici\u00f3n del bloque',
                    node.presentation.align,
                    [
                        { value: 'start', label: 'Izquierda' },
                        { value: 'center', label: 'Centro' },
                        { value: 'end', label: 'Derecha' }
                    ],
                    node.id,
                    'align',
                    'position'
                )
            );
            Array.prototype.push.apply(
                blockOptions,
                v2ModuleSpacingOptions(context, node)
            );
            contentOptions = v2ModuleContentOptions(
                context,
                node,
                location
            );
        } else if (moduleNode) {
            blockOptions.push(element(
                'p',
                'blogEditor__inspectorHint',
                'La imagen de portada ocupa siempre todo el hero.'
            ));
        } else if (['article', 'div'].includes(node.type)) {
            var containerPresentation = node.presentation || {};
            blockOptions.push(
                v2ColumnPresetControl(context, node, 'inspector'),
                v2ConfigButtonGroup(
                    context,
                    'Anchura',
                    PRESENTATION_WIDTHS.includes(containerPresentation.width)
                        ? containerPresentation.width
                        : 'full',
                    [
                        { value: 'full', label: '100%' },
                        { value: '80', label: '80%' },
                        { value: '60', label: '60%' },
                        { value: '40', label: '40%' }
                    ],
                    node.id,
                    'width',
                    'width'
                ),
                v2ConfigButtonGroup(
                    context,
                    'Posici\u00f3n del bloque',
                    PRESENTATION_ALIGNS.includes(containerPresentation.align)
                        ? containerPresentation.align
                        : 'start',
                    [
                        { value: 'start', label: 'Izquierda' },
                        { value: 'center', label: 'Centro' },
                        { value: 'end', label: 'Derecha' }
                    ],
                    node.id,
                    'align',
                    'position'
                )
            );
        }
        if (!moduleNode) {
            Array.prototype.push.apply(
                blockOptions,
                v2ContainerBackgroundOptions(context, node)
            );
        }
        blockOptions.push(v2CommonActions(context, location));
        context.blockInspector.append(v2InspectorSection(
            'Opciones del bloque',
            blockOptions,
            'block'
        ));
        if (contentOptions.length > 0) {
            context.blockInspector.append(v2InspectorSection(
                'Opciones del contenido',
                contentOptions,
                'content'
            ));
        }
    }

    function renderV2(context) {
        normalizeUnifiedTextModules(context.documentValue);
        context.controlNumber = 0;
        if (
            context.selectedNodeId
            && !v2Location(context.documentValue, context.selectedNodeId)
        ) {
            context.selectedNodeId = null;
        }
        refreshV2Canvas(context);
        renderHeaderControls(context);
        renderV2Inspector(context);
        sync(context);
    }

    function renderInspector(context) {
        if (context.documentValue.version === VERSION) {
            renderV2Inspector(context);
            return;
        }
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
        if (context.documentValue.version === VERSION) {
            renderV2(context);
            return;
        }
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

    function appendMediaOption(select, media) {
        if (!(select instanceof HTMLSelectElement)) {
            return;
        }
        var existing = Array.from(select.options).find(function (option) {
            return option.value === media.publicId;
        });
        var option = existing || document.createElement('option');
        option.value = media.publicId;
        option.textContent = media.label;
        option.dataset.thumbnailUrl = media.thumbnailUrl;
        if (!existing) {
            select.append(option);
        }
    }

    function openMediaDialog(context, publicId, apply) {
        if (!context.mediaDialog || typeof apply !== 'function') {
            return;
        }
        context.mediaApply = apply;
        if (context.mediaDialogSelect) {
            var requested = context.media.some(function (media) {
                return media.publicId === publicId;
            }) ? publicId : (context.media[0] || {}).publicId;
            context.mediaDialogSelect.value = requested || '';
        }
        if (typeof context.mediaDialog.showModal === 'function') {
            context.mediaDialog.showModal();
        } else {
            context.mediaDialog.setAttribute('open', '');
        }
    }

    function initMediaDialog(context) {
        var dialog = document.querySelector(
            '[data-webadmin-media-picker][data-webadmin-media-picker-owner="'
                + context.form.id + '"]'
        );
        if (!(dialog instanceof HTMLDialogElement)) {
            return;
        }
        var select = dialog.querySelector(
            '[data-webadmin-media-picker-select]'
        );
        context.mediaDialog = dialog;
        context.mediaDialogSelect = select instanceof HTMLSelectElement
            ? select : null;

        dialog.addEventListener(
            'liquidstack:webadmin-media-picker:selected',
            function (event) {
                var detail = event && event.detail;
                var selected = context.mediaDialogSelect
                    ? readMedia(context.mediaDialogSelect).find(
                        function (media) {
                            return media.publicId
                                === context.mediaDialogSelect.value;
                        }
                    ) : null;
                if (!detail || typeof detail !== 'object' || !selected
                    || detail.public_id !== selected.publicId
                    || detail.label !== selected.label
                    || detail.thumbnail_url !== selected.thumbnailUrl
                    || typeof context.mediaApply !== 'function') {
                    event.preventDefault();
                    return;
                }
                var existing = context.media.findIndex(function (media) {
                    return media.publicId === selected.publicId;
                });
                if (existing < 0) {
                    context.media.unshift(selected);
                } else {
                    context.media[existing] = selected;
                }
                appendMediaOption(context.mediaCatalog, selected);
                context.form.querySelectorAll(
                    '[data-blog-add-block="image"]'
                ).forEach(function (button) {
                    button.disabled = context.readOnly;
                });
                context.mediaApply(selected);
            }
        );
        dialog.addEventListener('close', function () {
            context.mediaApply = null;
        });
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
            var body = editorFormBody(context.form);
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
            if (!(event.target instanceof Element)) {
                return;
            }
            var action = event.target.closest(
                '[data-blog-add-block], [data-blog-action], '
                + '[data-blog-v2-action], '
                + '[data-blog-inline-action], [data-blog-add-inline]'
            );
            if (!action) {
                return;
            }
            if (
                action.hasAttribute('data-blog-v2-action')
                && ![
                    'add', 'move-up', 'move-down', 'duplicate', 'delete'
                ].includes(action.dataset.blogV2Action || '')
            ) {
                return;
            }
            schedule();
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

    var EDITOR_FORM_FIELDS = [
        'csrf',
        'post',
        'locale',
        'lock_version',
        'document_json',
        'h1',
        'slug',
        'seo_title',
        'meta_description',
        'excerpt',
        'robots_index',
        'robots_follow'
    ];

    function editorFormBody(form) {
        var source = formBody(form);
        var body = new URLSearchParams();
        EDITOR_FORM_FIELDS.forEach(function (key) {
            source.getAll(key).forEach(function (value) {
                body.append(key, value);
            });
        });
        return body;
    }

    function formFingerprint(form) {
        return editorFormBody(form).toString();
    }

    function editorialFormFingerprint(form) {
        var body = editorFormBody(form);
        ['csrf', 'lock_version', 'category_workspace_version'].forEach(
            function (key) { body.delete(key); }
        );
        return body.toString();
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

    function validCategoryPayload(value) {
        return plainObject(value)
            && exactKeys(value, [
                'category_public_id', 'locale', 'name', 'slug',
                'lock_version', 'updated_at'
            ])
            && UUID_V4.test(value.category_public_id)
            && typeof value.locale === 'string'
            && /^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/.test(value.locale)
            && typeof value.name === 'string'
            && value.name.trim() !== ''
            && typeof value.slug === 'string'
            && /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(value.slug)
            && Number.isInteger(value.lock_version)
            && value.lock_version > 0
            && typeof value.updated_at === 'string';
    }

    function categoryRequest(state, url, options, slot) {
        var abortable = typeof slot === 'string' && slot !== '';
        var previous = abortable ? state[slot] : null;
        if (previous && typeof previous.abort === 'function') {
            previous.abort();
        }
        var controller = abortable
            && typeof window.AbortController === 'function'
            ? new window.AbortController()
            : null;
        if (abortable) {
            state[slot] = controller;
        }
        var request = Object.assign({
            credentials: 'same-origin',
            redirect: 'error'
        }, options || {});
        request.headers = Object.assign({
            'Accept': 'application/json',
            'X-LiquidStack-Category-Manager': 'async'
        }, request.headers || {});
        if (controller) {
            request.signal = controller.signal;
        }
        return window.fetch(url, request).then(function (response) {
            var contentType = response.headers.get('Content-Type') || '';
            if (!contentType.toLowerCase().startsWith('application/json')) {
                var invalid = new Error('category-invalid-response');
                invalid.status = response.status;
                throw invalid;
            }
            return response.json().then(function (payload) {
                if (!plainObject(payload)) {
                    throw new Error('category-invalid-response');
                }
                if (!response.ok || payload.ok !== true) {
                    var failure = new Error(
                        typeof payload.error === 'string'
                            ? payload.error
                            : 'category-request-failed'
                    );
                    failure.status = response.status;
                    throw failure;
                }
                return payload;
            });
        }).finally(function () {
            if (abortable && state[slot] === controller) {
                state[slot] = null;
            }
        });
    }

    function abortCategoryRequest(state, slot) {
        var controller = state[slot];
        if (controller && typeof controller.abort === 'function') {
            controller.abort();
        }
        state[slot] = null;
    }

    function categoryErrorMessage(error, action) {
        if (error && error.name === 'AbortError') {
            return '';
        }
        if (error && error.message === 'category_in_use') {
            return 'No se puede borrar: la categoría está asignada a contenido.';
        }
        if (error && error.status === 409) {
            return 'La categoría cambió en otra sesión. Actualiza el gestor y vuelve a intentarlo.';
        }
        if (error && error.status === 403) {
            return 'La sesión o el permiso ya no son válidos. El borrador del artículo se conserva.';
        }
        return 'No se pudo ' + action
            + '. El borrador del artículo se conserva.';
    }

    function categorySelectedIds(form) {
        return new Set(Array.from(form.querySelectorAll(
            'input[name="categories[]"]:checked'
        )).map(function (input) {
            return input.value;
        }));
    }

    function categorySelectionFingerprint(form) {
        return Array.from(categorySelectedIds(form)).sort().join(',');
    }

    function categoryMarkDirty(state) {
        if (!state || typeof state.cleanFingerprint !== 'string') {
            return;
        }
        state.dirty = categorySelectionFingerprint(state.assignmentForm)
            !== state.cleanFingerprint;
        if (state.dirty && !state.assignmentPending) {
            state.assignmentStatus.textContent =
                'Hay cambios de categorías pendientes de guardar.';
            state.assignmentStatus.dataset.state = 'pending';
        }
    }

    function renderCategoryChoices(state, categories, newlySelected) {
        var previousFingerprint = typeof state.cleanFingerprint === 'string'
            ? categorySelectionFingerprint(state.assignmentForm)
            : null;
        var selected = categorySelectedIds(state.assignmentForm);
        if (typeof newlySelected === 'string') {
            selected.add(newlySelected);
        }
        var choices = state.assignmentForm.querySelector(
            '.blogEditor__categoryChoices'
        );
        if (!(choices instanceof HTMLElement)) {
            return;
        }
        choices.replaceChildren();
        categories.forEach(function (category, index) {
            var id = 'blog-editor-category-live-' + state.context.instance
                + '-' + index;
            var label = document.createElement('label');
            label.htmlFor = id;
            var input = document.createElement('input');
            input.id = id;
            input.type = 'checkbox';
            input.name = 'categories[]';
            input.value = category.category_public_id;
            input.checked = selected.has(category.category_public_id);
            label.append(input, document.createTextNode(' ' + category.name));
            choices.append(label);
        });
        if (categories.length === 0) {
            var empty = element(
                'p',
                '',
                'No hay categorías disponibles en este idioma.'
            );
            empty.dataset.blogCategoryEmpty = 'true';
            choices.append(empty);
        }
        if (
            previousFingerprint !== null
            && categorySelectionFingerprint(state.assignmentForm)
                !== previousFingerprint
        ) {
            categoryMarkDirty(state);
        }
    }

    function categoryFormBody(form) {
        var body = formBody(form);
        if ((body.get('slug') || '').trim() === '') {
            body.delete('slug');
        }
        return body;
    }

    function categoryCsrf(state) {
        var csrf = state.assignmentForm.elements.namedItem('csrf');
        return csrf instanceof HTMLInputElement ? csrf.value : '';
    }

    function renderCategoryManager(state) {
        state.managerList.replaceChildren();
        if (state.catalog.length === 0) {
            state.managerList.append(element(
                'p',
                'blogEditor__categoryManagerEmpty',
                'No hay categorías en este idioma.'
            ));
            return;
        }
        state.catalog.forEach(function (category) {
            var row = element('article', 'blogEditor__categoryManagerRow');
            var title = element('h4', '', category.name);
            var form = document.createElement('form');
            form.method = 'post';
            form.action = state.endpoint + '/save';
            form.dataset.blogCategoryManagerEdit = category.category_public_id;
            [
                ['csrf', categoryCsrf(state)],
                ['category', category.category_public_id],
                ['locale', state.locale],
                ['lock_version', String(category.lock_version)]
            ].forEach(function (field) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = field[0];
                input.value = field[1];
                form.append(input);
            });
            var nameLabel = element('label', '', 'Nombre');
            var name = document.createElement('input');
            name.name = 'name';
            name.required = true;
            name.value = category.name;
            nameLabel.append(name);
            var slugLabel = element('label', '', 'Slug');
            var slug = document.createElement('input');
            slug.name = 'slug';
            slug.required = true;
            slug.pattern = '[a-z0-9]+(?:-[a-z0-9]+)*';
            slug.value = category.slug;
            slugLabel.append(slug);
            var save = element('button', '', 'Guardar cambios');
            save.type = 'submit';
            form.append(nameLabel, slugLabel, save);
            var remove = element('button', '', 'Borrar');
            remove.type = 'button';
            remove.dataset.blogCategoryManagerDelete = category.category_public_id;
            remove.dataset.blogCategoryLockVersion = String(
                category.lock_version
            );
            row.append(title, form, remove);
            state.managerList.append(row);
        });
    }

    function loadCategoryCatalog(state, focusAfter) {
        state.managerStatus.textContent = 'Cargando categorías…';
        state.managerStatus.dataset.state = 'pending';
        return categoryRequest(
            state,
            state.endpoint + '?locale=' + encodeURIComponent(state.locale),
            { method: 'GET' },
            'catalogController'
        ).then(function (payload) {
            if (
                payload.locale !== state.locale
                || !Array.isArray(payload.categories)
                || !payload.categories.every(validCategoryPayload)
            ) {
                throw new Error('category-invalid-response');
            }
            state.catalog = payload.categories;
            renderCategoryManager(state);
            renderCategoryChoices(state, state.catalog, null);
            state.managerStatus.textContent = state.catalog.length
                + (state.catalog.length === 1
                    ? ' categoría disponible.'
                    : ' categorías disponibles.');
            state.managerStatus.dataset.state = 'ok';
            if (focusAfter) {
                var first = state.managerList.querySelector('input, button');
                if (first instanceof HTMLElement) {
                    first.focus();
                }
            }
            return true;
        }).catch(function (error) {
            var message = categoryErrorMessage(
                error,
                'cargar las categorías'
            );
            if (message !== '') {
                state.managerStatus.textContent = message;
                state.managerStatus.dataset.state = 'error';
            }
            return false;
        });
    }

    function categoryMutation(state, form, action, submitter) {
        abortCategoryRequest(state, 'catalogController');
        var body = categoryFormBody(form);
        form.setAttribute('aria-busy', 'true');
        if (submitter instanceof HTMLButtonElement) {
            submitter.disabled = true;
        }
        state.managerStatus.textContent = action + '…';
        state.managerStatus.dataset.state = 'pending';
        return categoryRequest(state, form.action, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: body.toString()
        }, 'mutationController').then(function (payload) {
            if (!validCategoryPayload(payload.category)) {
                throw new Error('category-invalid-response');
            }
            var found = state.catalog.findIndex(function (category) {
                return category.category_public_id
                    === payload.category.category_public_id;
            });
            if (found >= 0) {
                state.catalog[found] = payload.category;
            } else {
                state.catalog.push(payload.category);
                state.catalog.sort(function (left, right) {
                    return left.name.localeCompare(right.name, state.locale);
                });
            }
            renderCategoryManager(state);
            renderCategoryChoices(
                state,
                state.catalog,
                found < 0 ? payload.category.category_public_id : null
            );
            state.managerStatus.textContent = found < 0
                ? 'Categoría creada y seleccionada. Guarda la asignación para confirmar el borrador privado.'
                : 'Categoría actualizada.';
            state.managerStatus.dataset.state = 'ok';
            return payload.category;
        }).catch(function (error) {
            var message = categoryErrorMessage(error, action.toLowerCase());
            if (message !== '') {
                state.managerStatus.textContent = message;
                state.managerStatus.dataset.state = 'error';
            }
            throw error;
        }).finally(function () {
            form.removeAttribute('aria-busy');
            if (submitter instanceof HTMLButtonElement) {
                submitter.disabled = false;
            }
        });
    }

    function closeCategoryManager(state) {
        abortCategoryRequest(state, 'catalogController');
        if (state.dialog.open && typeof state.dialog.close === 'function') {
            state.dialog.close();
        } else {
            state.dialog.removeAttribute('open');
        }
        if (state.openTrigger instanceof HTMLElement) {
            state.openTrigger.focus();
        }
    }

    function initCategoryCreation(state, quickForm, quickStatus) {
        quickForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var submit = quickForm.querySelector('button[type="submit"]');
            if (quickForm.getAttribute('aria-busy') === 'true') {
                return;
            }
            abortCategoryRequest(state, 'catalogController');
            quickForm.setAttribute('aria-busy', 'true');
            if (submit instanceof HTMLButtonElement) {
                submit.disabled = true;
            }
            quickStatus.textContent = 'Creando categoría…';
            quickStatus.dataset.state = 'pending';
            categoryRequest(state, quickForm.action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: categoryFormBody(quickForm).toString()
            }, 'quickController').then(function (payload) {
                if (!validCategoryPayload(payload.category)) {
                    throw new Error('category-invalid-response');
                }
                var existing = state.catalog.findIndex(function (category) {
                    return category.category_public_id
                        === payload.category.category_public_id;
                });
                if (existing >= 0) {
                    state.catalog[existing] = payload.category;
                } else {
                    state.catalog.push(payload.category);
                }
                state.catalog.sort(function (left, right) {
                    return left.name.localeCompare(right.name, state.locale);
                });
                renderCategoryChoices(
                    state,
                    state.catalog,
                    payload.category.category_public_id
                );
                renderCategoryManager(state);
                quickForm.reset();
                quickStatus.textContent = 'Categoría creada y seleccionada. Guarda las categorías para confirmar el borrador privado.';
                quickStatus.dataset.state = 'ok';
            }).catch(function (error) {
                var message = categoryErrorMessage(error, 'crear la categoría');
                if (message !== '') {
                    quickStatus.textContent = message;
                    quickStatus.dataset.state = 'error';
                }
            }).finally(function () {
                quickForm.removeAttribute('aria-busy');
                if (submit instanceof HTMLButtonElement) {
                    submit.disabled = false;
                }
            });
        });
    }

    function initCategoryManagerDialog(state, createForm, open, close) {
        open.addEventListener('click', function () {
            state.openTrigger = open;
            if (typeof state.dialog.showModal === 'function') {
                state.dialog.showModal();
            } else {
                state.dialog.setAttribute('open', '');
            }
            loadCategoryCatalog(state, true);
        });
        close.addEventListener('click', function () {
            closeCategoryManager(state);
        });
        state.dialog.addEventListener('cancel', function (event) {
            event.preventDefault();
            closeCategoryManager(state);
        });
        state.dialog.addEventListener('click', function (event) {
            if (event.target === state.dialog) {
                closeCategoryManager(state);
            }
        });
        createForm.addEventListener('submit', function (event) {
            event.preventDefault();
            categoryMutation(
                state,
                createForm,
                'Creando categoría',
                event.submitter
            ).then(function () {
                createForm.reset();
            }).catch(function () {});
        });
        state.managerList.addEventListener('submit', function (event) {
            var editForm = event.target.closest(
                '[data-blog-category-manager-edit]'
            );
            if (!(editForm instanceof HTMLFormElement)) {
                return;
            }
            event.preventDefault();
            categoryMutation(
                state,
                editForm,
                'Guardando categoría',
                event.submitter
            ).catch(function () {});
        });
        state.managerList.addEventListener('click', function (event) {
            var trigger = event.target.closest(
                '[data-blog-category-manager-delete]'
            );
            if (!(trigger instanceof HTMLButtonElement)) {
                return;
            }
            var categoryId = trigger.dataset.blogCategoryManagerDelete || '';
            var lockVersion = trigger.dataset.blogCategoryLockVersion || '';
            if (!UUID_V4.test(categoryId) || !/^[1-9][0-9]*$/.test(lockVersion)) {
                return;
            }
            confirmEditorAction(state.context, {
                title: 'Borrar categoría',
                message: 'La traducción se borrará solo si la categoría no está asignada a contenido.',
                confirmLabel: 'Borrar',
                danger: true
            }, trigger).then(function (confirmed) {
                if (!confirmed) {
                    return;
                }
                var body = new URLSearchParams({
                    csrf: categoryCsrf(state),
                    category: categoryId,
                    locale: state.locale,
                    lock_version: lockVersion
                });
                state.managerStatus.textContent = 'Borrando categoría…';
                state.managerStatus.dataset.state = 'pending';
                return categoryRequest(state, state.endpoint + '/delete', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                    },
                    body: body.toString()
                }, 'mutationController').then(function (payload) {
                    if (
                        payload.category_public_id !== categoryId
                        || payload.locale !== state.locale
                        || typeof payload.aggregate_deleted !== 'boolean'
                    ) {
                        throw new Error('category-invalid-response');
                    }
                    state.catalog = state.catalog.filter(function (category) {
                        return category.category_public_id !== categoryId;
                    });
                    renderCategoryManager(state);
                    renderCategoryChoices(state, state.catalog, null);
                    state.managerStatus.textContent = 'Categoría borrada.';
                    state.managerStatus.dataset.state = 'ok';
                }).catch(function (error) {
                    var message = categoryErrorMessage(
                        error,
                        'borrar la categoría'
                    );
                    if (message !== '') {
                        state.managerStatus.textContent = message;
                        state.managerStatus.dataset.state = 'error';
                    }
                });
            });
        });
    }

    function registerEditorialFacet(context, facet) {
        if (!Array.isArray(context.editorialFacets)) {
            context.editorialFacets = [];
        }
        context.editorialFacets.push(facet);
    }

    function editorialFacetsHaveChanges(context) {
        return Array.isArray(context.editorialFacets)
            && context.editorialFacets.some(function (facet) {
                return facet.dirty() || facet.pending();
            });
    }

    function editorialFacetsCommit(context) {
        if (!Array.isArray(context.editorialFacets)) {
            return true;
        }
        return context.editorialFacets.every(function (facet) {
            return typeof facet.commit !== 'function' || facet.commit();
        });
    }

    function editorialFacetsInSeries(context, method) {
        if (!Array.isArray(context.editorialFacets)) {
            return Promise.resolve(true);
        }
        return context.editorialFacets.reduce(function (promise, facet) {
            return promise.then(function (ready) {
                if (!ready || typeof facet[method] !== 'function') {
                    return ready;
                }
                return Promise.resolve(facet[method]()).then(Boolean);
            });
        }, Promise.resolve(true));
    }

    function waitEditorialFacets(context) {
        return editorialFacetsInSeries(context, 'wait');
    }

    function saveEditorialFacets(context) {
        if (!editorialFacetsCommit(context)) {
            return Promise.resolve(false);
        }
        return editorialFacetsInSeries(context, 'save');
    }

    function discardEditorialFacets(context) {
        return editorialFacetsInSeries(context, 'discard');
    }

    function validCategoryAssignmentPayload(payload) {
        return exactKeys(payload, [
            'ok',
            'lock_version',
            'category_workspace_version'
        ])
            && payload.ok === true
            && Number.isInteger(payload.lock_version)
            && payload.lock_version > 0
            && Number.isInteger(payload.category_workspace_version)
            && payload.category_workspace_version >= 0;
    }

    function saveCategoryAssignment(state) {
        if (state.assignmentPending) {
            return state.assignmentPromise.then(function (saved) {
                return saved ? saveCategoryAssignment(state) : false;
            });
        }
        var submittedFingerprint = categorySelectionFingerprint(
            state.assignmentForm
        );
        if (submittedFingerprint === state.cleanFingerprint) {
            state.dirty = false;
            return Promise.resolve(true);
        }
        var workspace = state.assignmentForm.elements.namedItem(
            'category_workspace_version'
        );
        if (!(workspace instanceof HTMLInputElement)) {
            return Promise.resolve(false);
        }

        state.assignmentPending = true;
        state.assignmentForm.setAttribute('aria-busy', 'true');
        if (state.assignmentSubmit instanceof HTMLButtonElement) {
            state.assignmentSubmit.disabled = true;
        }
        state.assignmentStatus.textContent =
            'Guardando categorías en el borrador…';
        state.assignmentStatus.dataset.state = 'pending';
        sync(state.context);

        var requestPromise = categoryRequest(
            state,
            state.assignmentForm.action,
            {
                method: 'POST',
                headers: {
                    'Content-Type':
                        'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: formBody(state.assignmentForm).toString()
            },
            null
        ).then(function (payload) {
            if (
                !validCategoryAssignmentPayload(payload)
                || !v2SyncEditorialLockVersion(
                    state.context,
                    payload.lock_version
                )
                || !v2SyncCategoryWorkspaceVersion(
                    state.context,
                    payload.category_workspace_version
                )
            ) {
                throw new Error('category-save-invalid-response');
            }
            state.cleanFingerprint = submittedFingerprint;
            categoryMarkDirty(state);
            state.assignmentStatus.textContent = state.dirty
                ? 'Se guardó la selección enviada. Guardando cambios posteriores…'
                : 'Categorías guardadas en el borrador privado. Se aplicarán al publicar.';
            state.assignmentStatus.dataset.state = state.dirty
                ? 'pending'
                : 'ok';
            return true;
        }).catch(function (error) {
            state.dirty = true;
            var message = categoryErrorMessage(
                error,
                'guardar las categorías'
            );
            if (message !== '') {
                state.assignmentStatus.textContent = message
                    + ' La selección sigue disponible para reintentar.';
                state.assignmentStatus.dataset.state = 'error';
            }
            return false;
        }).finally(function () {
            state.assignmentPending = false;
            state.assignmentForm.removeAttribute('aria-busy');
            if (state.assignmentSubmit instanceof HTMLButtonElement) {
                state.assignmentSubmit.disabled = false;
            }
        });

        state.assignmentPromise = requestPromise;
        return requestPromise.then(function (saved) {
            if (state.assignmentPromise === requestPromise) {
                state.assignmentPromise = null;
            }
            if (!saved || state.discarding) {
                return saved;
            }
            return categorySelectionFingerprint(state.assignmentForm)
                === state.cleanFingerprint
                ? true
                : saveCategoryAssignment(state);
        });
    }

    function discardCategoryAssignment(state) {
        state.discarding = true;
        var pending = state.assignmentPending
            ? state.assignmentPromise
            : Promise.resolve(true);
        return Promise.resolve(pending).then(function (saved) {
            if (!saved) {
                state.discarding = false;
            }
            return Boolean(saved);
        });
    }

    function initCategoryWorkspace(context) {
        if (!context.inspectorRoot || typeof window.fetch !== 'function') {
            return;
        }
        var tools = context.inspectorRoot.querySelector(
            '[data-blog-category-tools]'
        );
        var form = context.inspectorRoot.querySelector(
            '[data-blog-category-assignment-form]'
        );
        if (!(tools instanceof HTMLElement) || !(form instanceof HTMLFormElement)) {
            return;
        }
        var status = form.querySelector(
            '[data-blog-category-assignment-status]'
        );
        var submit = form.querySelector('button[type="submit"]');
        var quickForm = tools.querySelector('[data-blog-category-quick-form]');
        var quickStatus = tools.querySelector(
            '[data-blog-category-quick-status]'
        );
        var dialog = tools.querySelector('[data-blog-category-manager]');
        var managerList = tools.querySelector(
            '[data-blog-category-manager-list]'
        );
        var managerStatus = tools.querySelector(
            '[data-blog-category-manager-status]'
        );
        var managerCreate = tools.querySelector(
            '[data-blog-category-manager-create]'
        );
        var open = tools.querySelector('[data-blog-category-manager-open]');
        var close = tools.querySelector('[data-blog-category-manager-close]');
        var csrf = form.elements.namedItem('csrf');
        if (
            !(status instanceof HTMLElement)
            || !(quickForm instanceof HTMLFormElement)
            || !(quickStatus instanceof HTMLElement)
            || !(dialog instanceof HTMLElement)
            || dialog.tagName !== 'DIALOG'
            || !(managerList instanceof HTMLElement)
            || !(managerStatus instanceof HTMLElement)
            || !(managerCreate instanceof HTMLFormElement)
            || !(open instanceof HTMLButtonElement)
            || !(close instanceof HTMLButtonElement)
            || !(csrf instanceof HTMLInputElement)
        ) {
            return;
        }
        var state = {
            context: context,
            assignmentForm: form,
            endpoint: tools.dataset.blogCategoryEndpoint || '',
            locale: tools.dataset.blogCategoryLocale || '',
            dialog: dialog,
            managerList: managerList,
            managerStatus: managerStatus,
            catalog: [],
            catalogController: null,
            mutationController: null,
            quickController: null,
            openTrigger: null,
            assignmentStatus: status,
            assignmentSubmit: submit,
            assignmentPending: false,
            assignmentPromise: null,
            cleanFingerprint: categorySelectionFingerprint(form),
            dirty: false,
            discarding: false
        };
        if (!safeRootRelativeUrl(state.endpoint) || state.locale !== context.locale) {
            return;
        }
        context.categoryManager = state;

        form.addEventListener('change', function (event) {
            if (
                event.target instanceof HTMLInputElement
                && event.target.name === 'categories[]'
            ) {
                categoryMarkDirty(state);
            }
        });
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            saveCategoryAssignment(state);
        });
        registerEditorialFacet(context, {
            dirty: function () {
                categoryMarkDirty(state);
                return state.dirty;
            },
            pending: function () { return state.assignmentPending; },
            wait: function () {
                return state.assignmentPending
                    ? saveCategoryAssignment(state)
                    : Promise.resolve(true);
            },
            save: function () { return saveCategoryAssignment(state); },
            discard: function () {
                return discardCategoryAssignment(state);
            }
        });

        initCategoryCreation(state, quickForm, quickStatus);
        initCategoryManagerDialog(state, managerCreate, open, close);
        loadCategoryCatalog(state, false);
    }

    function initCategoryAssignment(context) {
        initCategoryWorkspace(context);
    }

    function normalizedTagName(value) {
        if (typeof value !== 'string') {
            return null;
        }
        if (value.includes(',') || UNSAFE_TAG_TEXT.test(value)) {
            return null;
        }
        var normalized;
        try {
            normalized = value.normalize('NFC').trim().replace(/\s+/gu, ' ');
        } catch (error) {
            return null;
        }
        return !UNSAFE_TAG_TEXT.test(normalized)
            && normalized !== ''
            && characters(normalized) <= MAX_TAG_NAME_CHARACTERS
            && bytes(normalized) <= MAX_TAG_NAME_BYTES
            ? normalized
            : null;
    }

    function tagNameKey(name) {
        var normalized = normalizedTagName(name);
        return normalized === null ? name : normalized;
    }

    function tagListFingerprint(tags) {
        return JSON.stringify(tags.map(function (tag) { return tag.name; }));
    }

    function tagCsv(tags) {
        return tags.map(function (tag) { return tag.name; }).join(', ');
    }

    function validTagAssignmentPayload(payload) {
        if (
            !exactKeys(payload, [
                'ok',
                'lock_version',
                'tag_workspace_version',
                'tags'
            ])
            || payload.ok !== true
            || !Number.isInteger(payload.lock_version)
            || payload.lock_version < 1
            || !Number.isInteger(payload.tag_workspace_version)
            || payload.tag_workspace_version < 0
            || !Array.isArray(payload.tags)
            || payload.tags.length > MAX_TAGS_PER_VARIANT
        ) {
            return false;
        }
        var previousSlug = null;
        var valid = payload.tags.every(function (tag) {
            if (
                !exactKeys(tag, ['name', 'slug'])
                || normalizedTagName(tag.name) !== tag.name
                || typeof tag.slug !== 'string'
                || tag.slug.length > 190
                || !TAG_SLUG.test(tag.slug)
                || (previousSlug !== null && tag.slug <= previousSlug)
            ) {
                return false;
            }
            previousSlug = tag.slug;
            return true;
        });
        return valid && bytes(tagCsv(payload.tags)) <= MAX_TAG_CSV_BYTES;
    }

    function tagAssignmentErrorMessage(error) {
        if (error && error.status === 409) {
            return 'Las etiquetas cambiaron en otra sesión. La selección local sigue disponible para revisarla.';
        }
        if (error && error.status === 422) {
            return 'Revisa las etiquetas: alguna no cumple el formato o los límites permitidos.';
        }
        if (error && error.status === 403) {
            return 'La sesión o el permiso ya no son válidos. Las etiquetas locales se conservan.';
        }
        return 'No se pudieron guardar las etiquetas. La selección local se conserva para reintentar.';
    }

    function renderTagAssignment(state) {
        state.list.replaceChildren();
        if (state.tags.length === 0) {
            var empty = element(
                'li',
                { 'data-blog-tag-empty': '' },
                'No hay etiquetas asignadas.'
            );
            state.list.append(empty);
            return;
        }
        state.tags.forEach(function (tag, index) {
            var item = document.createElement('li');
            item.dataset.blogTag = '';
            if (tag.slug !== '') {
                item.dataset.blogTagSlug = tag.slug;
            }
            var name = document.createElement('span');
            name.dir = 'auto';
            name.textContent = tag.name;
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.dataset.blogTagRemove = String(index);
            remove.setAttribute(
                'aria-label',
                'Quitar etiqueta ' + tag.name
            );
            remove.textContent = '×';
            item.append(name, remove);
            state.list.append(item);
        });
    }

    function syncTagAssignmentDraft(state) {
        var csv = tagCsv(state.tags);
        state.canonicalInput.value = csv;
        state.dirty = tagListFingerprint(state.tags)
            !== state.cleanFingerprint;
        renderTagAssignment(state);
        return bytes(csv) <= MAX_TAG_CSV_BYTES;
    }

    function refreshTagDirty(state) {
        state.dirty = state.composer.value.trim() !== ''
            || tagListFingerprint(state.tags) !== state.cleanFingerprint;
    }

    function markTagInputChanged(state) {
        state.revision += 1;
        refreshTagDirty(state);
        if (
            state.composer.value.trim() !== ''
            && !state.assignmentPending
        ) {
            state.status.textContent = 'Pulsa Intro o escribe una coma para confirmar la etiqueta.';
            state.status.dataset.state = 'pending';
        }
    }

    function commitTagInput(state) {
        var raw = state.composer.value;
        if (raw.trim() === '') {
            state.composer.value = '';
            state.dirty = tagListFingerprint(state.tags)
                !== state.cleanFingerprint;
            return true;
        }
        var candidates = raw.split(',').filter(function (candidate) {
            return candidate.trim() !== '';
        }).map(function (candidate) {
            return normalizedTagName(candidate);
        });
        if (candidates.some(function (candidate) { return candidate === null; })) {
            state.status.textContent = 'Cada etiqueta debe tener entre 1 y 64 caracteres, sin comas ni caracteres de control.';
            state.status.dataset.state = 'error';
            state.composer.setAttribute('aria-invalid', 'true');
            return false;
        }

        var next = state.tags.slice();
        var keys = new Set(next.map(function (tag) {
            return tagNameKey(tag.name);
        }));
        candidates.forEach(function (candidate) {
            var key = tagNameKey(candidate);
            if (!keys.has(key)) {
                keys.add(key);
                next.push({ name: candidate, slug: '' });
            }
        });
        var csv = tagCsv(next);
        if (
            next.length > MAX_TAGS_PER_VARIANT
            || bytes(csv) > MAX_TAG_CSV_BYTES
        ) {
            state.status.textContent = 'Puedes asignar hasta 30 etiquetas y 4096 bytes en total.';
            state.status.dataset.state = 'error';
            state.composer.setAttribute('aria-invalid', 'true');
            return false;
        }

        state.tags = next;
        state.composer.value = '';
        state.composer.removeAttribute('aria-invalid');
        state.revision += 1;
        syncTagAssignmentDraft(state);
        state.status.textContent = state.dirty
            ? 'Hay etiquetas pendientes de guardar.'
            : 'Las etiquetas ya estaban asignadas.';
        state.status.dataset.state = state.dirty ? 'pending' : 'ok';
        return true;
    }

    function scheduleTagAssignment(state) {
        if (state.saveTimer !== null) {
            window.clearTimeout(state.saveTimer);
        }
        state.saveTimer = window.setTimeout(function () {
            state.saveTimer = null;
            saveTagAssignment(state, false);
        }, 650);
    }

    function tagAssignmentRequest(state) {
        return window.fetch(state.action, {
            method: 'POST',
            credentials: 'same-origin',
            redirect: 'error',
            headers: {
                'Accept': 'application/json',
                'Content-Type':
                    'application/x-www-form-urlencoded;charset=UTF-8',
                'X-LiquidStack-Tag-Editor': 'async'
            },
            body: formBody(state.form).toString()
        }).then(function (response) {
            var contentType = response.headers.get('Content-Type') || '';
            if (!contentType.toLowerCase().startsWith('application/json')) {
                var invalid = new Error('tag-invalid-response');
                invalid.status = response.status;
                throw invalid;
            }
            return response.json().then(function (payload) {
                if (!plainObject(payload)) {
                    throw new Error('tag-invalid-response');
                }
                if (!response.ok || payload.ok !== true) {
                    var failure = new Error(
                        typeof payload.error === 'string'
                            ? payload.error
                            : 'tag-request-failed'
                    );
                    failure.status = response.status;
                    throw failure;
                }
                return payload;
            });
        });
    }

    function saveTagAssignment(state, commitPendingInput) {
        if (state.saveTimer !== null) {
            window.clearTimeout(state.saveTimer);
            state.saveTimer = null;
        }
        if (
            commitPendingInput
            && (state.composing || !commitTagInput(state))
        ) {
            return Promise.resolve(false);
        }
        if (state.assignmentPending) {
            return state.assignmentPromise.then(function (saved) {
                return saved
                    ? saveTagAssignment(state, commitPendingInput)
                    : false;
            });
        }
        var submittedFingerprint = tagListFingerprint(state.tags);
        if (submittedFingerprint === state.cleanFingerprint) {
            refreshTagDirty(state);
            return Promise.resolve(!state.dirty && !state.composing);
        }
        var submittedRevision = state.revision;
        var submittedTags = state.tags.map(function (tag) {
            return { name: tag.name, slug: tag.slug };
        });
        state.canonicalInput.value = tagCsv(submittedTags);
        state.assignmentPending = true;
        state.form.setAttribute('aria-busy', 'true');
        state.submit.disabled = true;
        state.status.textContent = 'Guardando etiquetas…';
        state.status.dataset.state = 'pending';
        sync(state.context);

        var requestPromise = tagAssignmentRequest(state).then(
            function (payload) {
                if (
                    !validTagAssignmentPayload(payload)
                    || !v2SyncEditorialLockVersion(
                        state.context,
                        payload.lock_version
                    )
                    || !v2SyncTagWorkspaceVersion(
                        state.context,
                        payload.tag_workspace_version
                    )
                ) {
                    throw new Error('tag-invalid-response');
                }
                var responseTags = payload.tags.map(function (tag) {
                    return { name: tag.name, slug: tag.slug };
                });
                state.cleanFingerprint = tagListFingerprint(responseTags);
                var stable = submittedRevision === state.revision
                    && tagListFingerprint(state.tags)
                        === submittedFingerprint;
                if (stable || state.discarding) {
                    state.tags = responseTags;
                    state.revision += 1;
                    syncTagAssignmentDraft(state);
                } else {
                    state.dirty = true;
                }
                state.status.textContent = state.dirty
                    ? 'Se guardaron las etiquetas enviadas. Guardando cambios posteriores…'
                    : 'Etiquetas guardadas en el borrador privado.';
                state.status.dataset.state = state.dirty ? 'pending' : 'ok';
                return true;
            }
        ).catch(function (error) {
            state.dirty = true;
            state.status.textContent = tagAssignmentErrorMessage(error);
            state.status.dataset.state = 'error';
            return false;
        }).finally(function () {
            state.assignmentPending = false;
            state.form.removeAttribute('aria-busy');
            state.submit.disabled = false;
        });
        state.assignmentPromise = requestPromise;

        return requestPromise.then(function (saved) {
            if (state.assignmentPromise === requestPromise) {
                state.assignmentPromise = null;
            }
            if (!saved || state.discarding) {
                return saved;
            }
            var confirmedDirty = tagListFingerprint(state.tags)
                !== state.cleanFingerprint;
            refreshTagDirty(state);
            if (!confirmedDirty && !state.dirty && !state.composing) {
                return true;
            }
            if (!commitPendingInput && !confirmedDirty) {
                return false;
            }
            return saveTagAssignment(state, commitPendingInput);
        });
    }

    function discardTagAssignment(state) {
        state.discarding = true;
        var pending = state.assignmentPending
            ? state.assignmentPromise
            : Promise.resolve(true);
        return Promise.resolve(pending).then(function (saved) {
            if (!saved) {
                state.discarding = false;
            }
            return Boolean(saved);
        });
    }

    function readAssignedTags(list) {
        var tags = [];
        var valid = Array.from(list.querySelectorAll('[data-blog-tag]'))
            .every(function (item) {
                var nameNode = item.querySelector('span');
                var name = nameNode ? nameNode.textContent : '';
                var slug = item.dataset.blogTagSlug || '';
                if (
                    normalizedTagName(name) !== name
                    || !TAG_SLUG.test(slug)
                    || slug.length > 190
                ) {
                    return false;
                }
                tags.push({ name: name, slug: slug });
                return true;
            });
        return valid && tags.length <= MAX_TAGS_PER_VARIANT ? tags : null;
    }

    function initTagAssignment(context) {
        if (!context.inspectorRoot || typeof window.fetch !== 'function') {
            return;
        }
        var form = context.inspectorRoot.querySelector(
            '[data-blog-tag-assignment-form]'
        );
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        var canonicalInput = form.querySelector('[data-blog-tag-csv]');
        var list = form.querySelector('[data-blog-tag-list]');
        var status = form.querySelector('[data-blog-tag-assignment-status]');
        var submit = form.querySelector('button[type="submit"]');
        var locale = form.elements.namedItem('locale');
        var workspace = form.elements.namedItem('tag_workspace_version');
        var tags = list instanceof HTMLElement ? readAssignedTags(list) : null;
        var action = form.getAttribute('action') || '';
        if (
            !(canonicalInput instanceof HTMLInputElement)
            || !(list instanceof HTMLElement)
            || !(status instanceof HTMLElement)
            || !(submit instanceof HTMLButtonElement)
            || !(locale instanceof HTMLInputElement)
            || !(workspace instanceof HTMLInputElement)
            || tags === null
            || !safeRootRelativeUrl(action)
            || locale.value !== context.locale
        ) {
            return;
        }

        var inputId = canonicalInput.id;
        var composer = document.createElement('input');
        composer.id = inputId;
        composer.type = 'text';
        composer.dir = 'auto';
        composer.autocomplete = 'off';
        // The composer also accepts a complete comma-separated paste. Each
        // individual name is validated when committed, while this boundary
        // must preserve the full request-sized value without truncation.
        composer.maxLength = MAX_TAG_CSV_BYTES;
        composer.dataset.blogTagComposer = '';
        composer.setAttribute(
            'aria-describedby',
            canonicalInput.getAttribute('aria-describedby') || ''
        );
        canonicalInput.removeAttribute('id');
        canonicalInput.type = 'hidden';
        canonicalInput.removeAttribute('aria-describedby');
        canonicalInput.insertAdjacentElement('afterend', composer);

        var state = {
            context: context,
            form: form,
            action: action,
            locale: locale.value,
            canonicalInput: canonicalInput,
            composer: composer,
            list: list,
            status: status,
            submit: submit,
            tags: tags,
            cleanFingerprint: tagListFingerprint(tags),
            dirty: false,
            composing: false,
            revision: 0,
            assignmentPending: false,
            assignmentPromise: null,
            saveTimer: null,
            discarding: false
        };
        syncTagAssignmentDraft(state);

        composer.addEventListener('compositionstart', function () {
            state.composing = true;
        });
        composer.addEventListener('compositionend', function () {
            state.composing = false;
            markTagInputChanged(state);
            scheduleTagAssignment(state);
        });
        composer.addEventListener('input', function (event) {
            markTagInputChanged(state);
            if (state.composing) {
                return;
            }
            if (event.inputType === 'insertFromPaste') {
                if (commitTagInput(state)) {
                    scheduleTagAssignment(state);
                }
                return;
            }
            scheduleTagAssignment(state);
        });
        composer.addEventListener('keydown', function (event) {
            if (
                state.composing
                || event.isComposing
                || !['Enter', ','].includes(event.key)
            ) {
                return;
            }
            event.preventDefault();
            if (commitTagInput(state)) {
                scheduleTagAssignment(state);
            }
        });
        composer.addEventListener('blur', function () {
            if (
                !state.composing
                && composer.value.trim() !== ''
                && commitTagInput(state)
            ) {
                scheduleTagAssignment(state);
            }
        });
        list.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-blog-tag-remove]');
            if (!(trigger instanceof HTMLButtonElement)) {
                return;
            }
            var index = Number(trigger.dataset.blogTagRemove);
            if (!Number.isInteger(index) || !state.tags[index]) {
                return;
            }
            var removed = state.tags[index].name;
            state.tags.splice(index, 1);
            state.revision += 1;
            syncTagAssignmentDraft(state);
            state.status.textContent = 'Etiqueta ' + removed
                + ' quitada. Guardando cambios…';
            state.status.dataset.state = 'pending';
            composer.focus();
            scheduleTagAssignment(state);
        });
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            saveTagAssignment(state, true);
        });
        registerEditorialFacet(context, {
            commit: function () {
                if (state.saveTimer !== null) {
                    window.clearTimeout(state.saveTimer);
                    state.saveTimer = null;
                }
                return !state.composing && commitTagInput(state);
            },
            dirty: function () {
                refreshTagDirty(state);
                return state.dirty;
            },
            pending: function () { return state.assignmentPending; },
            wait: function () {
                return state.assignmentPending
                    ? saveTagAssignment(state, true)
                    : Promise.resolve(true);
            },
            save: function () { return saveTagAssignment(state, true); },
            discard: function () {
                if (state.saveTimer !== null) {
                    window.clearTimeout(state.saveTimer);
                    state.saveTimer = null;
                }
                return discardTagAssignment(state);
            }
        });
    }

    function v2OpenSelectedInspector(context, preferredFocusSelector) {
        activateInspectorTab(context, 'block');
        openInspector(context);
        if (
            typeof preferredFocusSelector === 'string'
            && preferredFocusSelector !== ''
            && context.blockInspector
        ) {
            window.requestAnimationFrame(function () {
                var preferredTarget = context.blockInspector.querySelector(
                    preferredFocusSelector
                );
                if (preferredTarget instanceof HTMLElement) {
                    preferredTarget.focus();
                    return;
                }
                focusSelectedInspector(context);
            });
            return;
        }
        focusSelectedInspector(context);
    }

    function v2OpenSelectedEditor(context, trigger) {
        var location = v2Location(
            context.documentValue,
            context.selectedNodeId || ''
        );
        if (
            location
            && RICH_MODAL_BLOCK_TYPES.includes(location.node.type)
            && richOpenModal(context, location.node, trigger)
        ) {
            return;
        }
        v2OpenSelectedInspector(context);
    }

    function v2SelectCanvasNode(context, nodeElement) {
        if (
            context.readOnly
            || !(nodeElement instanceof HTMLElement)
            || !context.blockList.contains(nodeElement)
        ) {
            return false;
        }
        var nodeId = nodeElement.dataset.blogV2Node || '';
        if (!v2Location(context.documentValue, nodeId)) {
            return false;
        }
        context.selectedNodeId = nodeId;
        context.inspectorMode = 'config';
        renderV2(context);
        v2OpenSelectedInspector(context);
        return true;
    }

    function v2HandleCanvasSelection(context, event) {
        if (!(event.target instanceof Element)) {
            return false;
        }
        if (event.target.closest(
            '[data-blog-v2-action], [data-blog-v2-config], '
                + '.blogEditor__builderLayoutControl, .blogEditor__insert'
        )) {
            return false;
        }
        var nodeElement = event.target.closest(
            '[data-blog-v2-selectable="true"]'
        );
        if (!(nodeElement instanceof HTMLElement)) {
            return false;
        }
        event.preventDefault();
        return v2SelectCanvasNode(context, nodeElement);
    }

    function v2HandleCanvasSelectionKeydown(context, event) {
        if (v2HandleLayoutPickerKeydown(event)) {
            return;
        }
        if (event.key === 'Escape' && context.keyboardDragNodeId) {
            event.preventDefault();
            var cancelledNodeId = context.keyboardDragNodeId;
            context.keyboardDragNodeId = null;
            renderV2(context);
            v2FocusDragHandle(context, cancelledNodeId);
            announce(context, 'Movimiento cancelado.', false);
            return;
        }
        if (
            event.key === 'Escape'
            && event.target instanceof HTMLElement
        ) {
            var menu = event.target.closest('.blogEditor__insertMenu');
            var insertion = menu ? menu.closest('.blogEditor__insert') : null;
            var toggle = insertion ? insertion.querySelector(
                '[data-blog-v2-action="toggle-insert"]'
            ) : null;
            if (menu instanceof HTMLElement && toggle instanceof HTMLButtonElement) {
                event.preventDefault();
                v2SetInsertExpanded(toggle, menu, false);
                toggle.focus();
                return;
            }
        }
        if (
            !['Enter', ' '].includes(event.key)
            || !(event.target instanceof HTMLElement)
            || event.target.dataset.blogV2Selectable !== 'true'
        ) {
            return;
        }
        event.preventDefault();
        v2SelectCanvasNode(context, event.target);
    }

    function v2HandleLayoutPickerKeydown(event) {
        if (
            event.key !== 'Escape'
            || !(event.target instanceof HTMLElement)
        ) {
            return false;
        }
        var details = event.target.closest('[data-blog-v2-layout-picker]');
        if (!(details instanceof HTMLElement) || !details.open) {
            return false;
        }
        var summary = details.querySelector(
            '[data-blog-v2-layout-summary]'
        );
        details.open = false;
        event.preventDefault();
        if (summary instanceof HTMLElement) {
            summary.focus();
        }
        return true;
    }

    function v2FocusLayoutPresetSummary(context, nodeId, canvasControl) {
        window.requestAnimationFrame(function () {
            var root = canvasControl
                ? context.blockList
                : context.inspectorRoot;
            if (!(root instanceof HTMLElement)) {
                return;
            }
            var replacement = root.querySelector(
                '[data-blog-v2-layout-summary]'
                    + '[data-blog-v2-node="' + nodeId + '"]'
            );
            if (replacement instanceof HTMLElement) {
                replacement.focus();
            }
        });
    }

    function v2FocusDragHandle(context, nodeId) {
        window.requestAnimationFrame(function () {
            var handle = context.blockList.querySelector(
                '[data-blog-v2-action="drag-handle"]'
                    + '[data-blog-v2-node="' + nodeId + '"]'
            );
            if (handle instanceof HTMLButtonElement) {
                handle.focus();
            }
        });
    }

    function v2DispatchDocumentChange(context) {
        if (!(context.documentInput instanceof HTMLInputElement)) {
            return;
        }
        context.documentInput.dispatchEvent(new window.Event(
            'input',
            { bubbles: true }
        ));
    }

    function v2CompleteDrop(
        context,
        nodeId,
        ownerId,
        columnId,
        index
    ) {
        var plan = v2DropPlan(
            context,
            nodeId,
            ownerId,
            columnId,
            index
        );
        if (!plan) {
            return false;
        }
        var movedLabel = BLOCK_LABELS[plan.source.node.type]
            || CONTAINER_LABELS[plan.source.node.type]
            || 'Elemento';
        var sourceLabel = v2TargetContextLabel(plan.source);
        var targetLabel = v2TargetContextLabel(plan.target);
        if (!v2MoveToTarget(
            context,
            nodeId,
            ownerId,
            columnId,
            index
        )) {
            return false;
        }
        context.keyboardDragNodeId = null;
        context.inspectorMode = 'config';
        renderV2(context);
        v2DispatchDocumentChange(context);
        v2FocusDragHandle(context, nodeId);
        announce(
            context,
            movedLabel + ' movido de ' + sourceLabel
                + ', posici\u00f3n ' + (plan.source.index + 1)
                + ', a ' + targetLabel + ', posici\u00f3n '
                + (plan.index + 1) + '.',
            false
        );
        return true;
    }

    function v2ToggleKeyboardDrag(context, nodeId) {
        var location = v2Location(context.documentValue, nodeId);
        if (!v2CanDrag(location)) {
            return false;
        }
        if (context.keyboardDragNodeId === nodeId) {
            context.keyboardDragNodeId = null;
            renderV2(context);
            v2FocusDragHandle(context, nodeId);
            announce(context, 'Movimiento cancelado.', false);
            return true;
        }
        context.keyboardDragNodeId = nodeId;
        context.selectedNodeId = nodeId;
        renderV2(context);
        v2FocusDragHandle(context, nodeId);
        var nodeLabel = BLOCK_LABELS[location.node.type]
            || CONTAINER_LABELS[location.node.type]
            || 'Elemento';
        announce(
            context,
            'Modo mover activado para ' + nodeLabel + ' en '
                + v2TargetContextLabel(location)
                + '. Recorre las posiciones disponibles y pulsa Mover aqu\u00ed.',
            false
        );
        return true;
    }

    function v2DropTargetData(target) {
        if (!(target instanceof HTMLElement)) {
            return null;
        }
        var index = Number(target.dataset.blogV2Index);
        if (!Number.isInteger(index)) {
            return null;
        }
        return {
            ownerId: target.dataset.blogV2Owner || '',
            columnId: target.dataset.blogV2Column || '',
            index: index
        };
    }

    function v2ClearPointerDrag(context) {
        context.blockList.querySelectorAll(
            '[data-blog-v2-drop-state], [data-blog-v2-dragging]'
        ).forEach(function (node) {
            node.removeAttribute('data-blog-v2-drop-state');
            node.removeAttribute('data-blog-v2-dragging');
        });
        context.pointerDragNodeId = null;
        context.pointerDropTarget = null;
    }

    function v2BindDragDrop(context) {
        var previous = context.blockList.liquidStackBlogDragController;
        if (previous && typeof previous.abort === 'function') {
            previous.abort();
        }
        var controller = new window.AbortController();
        var options = { signal: controller.signal };
        context.blockList.liquidStackBlogDragController = controller;

        context.blockList.addEventListener('dragstart', function (event) {
            if (
                context.readOnly
                || !(event.target instanceof Element)
            ) {
                event.preventDefault();
                return;
            }
            var handle = event.target.closest(
                '[data-blog-v2-action="drag-handle"]'
            );
            var nodeId = handle instanceof HTMLButtonElement
                ? handle.dataset.blogV2Node || ''
                : '';
            var location = v2Location(context.documentValue, nodeId);
            if (
                !(handle instanceof HTMLButtonElement)
                || handle.disabled
                || !v2CanDrag(location)
                || !event.dataTransfer
            ) {
                event.preventDefault();
                return;
            }
            context.pointerDragNodeId = nodeId;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData(
                'application/x-liquidstack-blog-node',
                nodeId
            );
            var source = handle.closest('[data-blog-v2-selectable="true"]');
            if (source instanceof HTMLElement) {
                source.dataset.blogV2Dragging = 'true';
            }
            announce(
                context,
                'Arrastra '
                    + (BLOCK_LABELS[location.node.type]
                        || CONTAINER_LABELS[location.node.type]
                        || 'el elemento')
                    + ' desde ' + v2TargetContextLabel(location)
                    + ' hasta una posici\u00f3n v\u00e1lida.',
                false
            );
        }, options);

        context.blockList.addEventListener('dragover', function (event) {
            if (
                !context.pointerDragNodeId
                || !(event.target instanceof Element)
            ) {
                return;
            }
            var target = event.target.closest('[data-blog-v2-drop-target]');
            var data = v2DropTargetData(target);
            var valid = data && v2DropPlan(
                context,
                context.pointerDragNodeId,
                data.ownerId,
                data.columnId,
                data.index
            );
            if (!valid) {
                return;
            }
            event.preventDefault();
            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'move';
            }
            if (
                context.pointerDropTarget
                && context.pointerDropTarget !== target
            ) {
                context.pointerDropTarget.removeAttribute(
                    'data-blog-v2-drop-state'
                );
            }
            context.pointerDropTarget = target;
            target.dataset.blogV2DropState = 'active';
        }, options);

        context.blockList.addEventListener('dragleave', function (event) {
            var target = event.target instanceof Element
                ? event.target.closest('[data-blog-v2-drop-target]')
                : null;
            if (
                !(target instanceof HTMLElement)
                || target.contains(event.relatedTarget)
            ) {
                return;
            }
            target.removeAttribute('data-blog-v2-drop-state');
            if (context.pointerDropTarget === target) {
                context.pointerDropTarget = null;
            }
        }, options);

        context.blockList.addEventListener('drop', function (event) {
            var nodeId = context.pointerDragNodeId;
            var target = event.target instanceof Element
                ? event.target.closest('[data-blog-v2-drop-target]')
                : null;
            var data = v2DropTargetData(target);
            if (
                !nodeId
                || !data
                || !v2DropPlan(
                    context,
                    nodeId,
                    data.ownerId,
                    data.columnId,
                    data.index
                )
            ) {
                return;
            }
            event.preventDefault();
            v2ClearPointerDrag(context);
            context.pointerDragJustEnded = true;
            v2CompleteDrop(
                context,
                nodeId,
                data.ownerId,
                data.columnId,
                data.index
            );
            window.setTimeout(function () {
                context.pointerDragJustEnded = false;
            }, 0);
        }, options);

        context.blockList.addEventListener('dragend', function () {
            v2ClearPointerDrag(context);
            context.pointerDragJustEnded = true;
            window.setTimeout(function () {
                context.pointerDragJustEnded = false;
            }, 0);
        }, options);
    }

    function v2EnsureTypographyPresentation(node) {
        if (!['paragraph', 'heading', 'list'].includes(node.type)) {
            return;
        }
        if (
            ['paragraph', 'heading'].includes(node.type)
            && !PRESENTATION_FONT_WEIGHTS.includes(node.presentation.font_weight)
        ) {
            node.presentation.font_weight = 'default';
        }
        if (!validPresentationTextColor(node.presentation.text_color)) {
            node.presentation.text_color = 'default';
        }
    }

    function v2SetModuleConfig(node, key, value) {
        if (key === 'size' && PRESENTATION_SIZES.includes(value)) {
            node.presentation.size = value;
            delete node.presentation.font_size;
            return true;
        }
        if (
            key === 'width'
            && PRESENTATION_WIDTHS.includes(value)
        ) {
            node.presentation.width = value;
            return true;
        }
        if (
            key === 'align'
            && PRESENTATION_ALIGNS.includes(value)
        ) {
            node.presentation.align = value;
            return true;
        }
        if (
            key === 'text-align'
            && PRESENTATION_TEXT_ALIGNS.includes(value)
            && (node.type !== 'cta' || value !== 'justify')
        ) {
            node.presentation.text_align = value;
            return true;
        }
        if (
            ['spacing-before', 'spacing-after'].includes(key)
            && PRESENTATION_SPACINGS.includes(value)
        ) {
            node.presentation[
                key === 'spacing-before' ? 'spacing_before' : 'spacing_after'
            ] = value;
            return true;
        }
        if (['paragraph', 'heading', 'list'].includes(node.type)) {
            v2EnsureTypographyPresentation(node);
            if (
                ['paragraph', 'heading'].includes(node.type)
                &&
                key === 'font-weight'
                && PRESENTATION_FONT_WEIGHTS.includes(value)
            ) {
                node.presentation.font_weight = value;
                return true;
            }
            if (
                key === 'text-color'
                && validPresentationTextColor(value)
            ) {
                node.presentation.text_color = value;
                return true;
            }
        }
        if (
            node.type === 'image'
            && key === 'image-radius'
            && PRESENTATION_IMAGE_RADII.includes(value)
        ) {
            node.presentation.radius = value;
            delete node.presentation.radius_percent;
            return true;
        }
        if (
            node.type === 'image'
            && key === 'image-object-fit'
            && IMAGE_PRESENTATION_POLICY !== null
            && IMAGE_PRESENTATION_POLICY.object_fit.values.includes(value)
        ) {
            if (value === IMAGE_PRESENTATION_POLICY.object_fit.default) {
                delete node.presentation.object_fit;
            } else {
                node.presentation.object_fit = value;
            }
            return true;
        }
        if (
            node.type === 'image'
            && key === 'image-object-position-y'
            && IMAGE_PRESENTATION_POLICY !== null
            && IMAGE_PRESENTATION_POLICY.object_position_y.values.includes(value)
        ) {
            if (
                value
                    === IMAGE_PRESENTATION_POLICY.object_position_y.default
            ) {
                delete node.presentation.object_position_y;
            } else {
                node.presentation.object_position_y = value;
            }
            return true;
        }
        if (
            node.type === 'image'
            && key === 'image-overlay-mode'
            && IMAGE_PRESENTATION_POLICY !== null
            && IMAGE_PRESENTATION_POLICY.overlay.modes.includes(value)
            && Object.prototype.hasOwnProperty.call(
                node.presentation,
                'overlay_mode'
            )
        ) {
            node.presentation.overlay_mode = value;
            return true;
        }
        if (
            node.type === 'image'
            && key === 'image-overlay-color'
            && IMAGE_PRESENTATION_POLICY !== null
            && Object.prototype.hasOwnProperty.call(
                node.presentation,
                'overlay_color'
            )
            && (
                IMAGE_PRESENTATION_POLICY.overlay.colors.includes(value)
                || canonicalRgba(value) === value
            )
        ) {
            node.presentation.overlay_color = value;
            return true;
        }
        if (node.type === 'separator') {
            if (
                key === 'separator-style'
                && SEPARATOR_LINE_STYLES.includes(value)
            ) {
                node.line_style = value;
                return true;
            }
            if (
                key === 'separator-thickness'
                && SEPARATOR_THICKNESSES.includes(value)
            ) {
                node.thickness = value;
                return true;
            }
            if (
                key === 'separator-color'
                && validPresentationTextColor(value)
                && value !== 'default'
            ) {
                node.color = value;
                return true;
            }
        }
        var configuredHeading = node.type === 'heading'
            ? node
            : v2LeadingTextHeading(node);
        if (
            configuredHeading !== null
            && key === 'heading-preset'
            && HEADING_PRESETS.includes(value)
        ) {
            configuredHeading.preset = value;
            return true;
        }
        if (
            node.type === 'list'
            && key === 'list-type'
            && ['ordered', 'unordered'].includes(value)
        ) {
            node.ordered = value === 'ordered';
            node.marker = node.ordered ? 'decimal' : 'disc';
            return true;
        }
        if (
            node.type === 'list'
            && key === 'list-marker'
            && LIST_MARKERS.includes(value)
            && (node.ordered
                ? ['decimal', 'lower-alpha', 'upper-alpha'].includes(value)
                : ['disc', 'circle', 'square'].includes(value))
        ) {
            node.marker = value;
            return true;
        }
        if (
            node.type === 'callout'
            && key === 'callout-tone'
            && CALLOUT_TONES.includes(value)
        ) {
            node.tone = value;
            return true;
        }
        if (
            node.type === 'quote'
            && key === 'quote-preset'
            && QUOTE_PRESETS.includes(value)
        ) {
            node.preset = value;
            return true;
        }
        if (
            node.type === 'cta'
            && key === 'button-preset'
            && CTA_PRESETS.includes(value)
        ) {
            node.variant = value;
            return true;
        }
        return false;
    }

    function v2SetContainerBackground(node, value) {
        if (!['section', 'article', 'div'].includes(node.type)) {
            return false;
        }
        if (value === 'none') {
            if (node.presentation) {
                delete node.presentation.background;
                if (Object.keys(node.presentation).length === 0) {
                    delete node.presentation;
                }
            }
            return true;
        }
        var canonical = PRESENTATION_BACKGROUNDS.includes(value)
            ? value
            : canonicalRgba(value);
        if (canonical === null) {
            return false;
        }
        node.presentation = node.presentation || {};
        node.presentation.background = canonical;
        return true;
    }

    function v2SetContainerTextColor(node, value) {
        if (!['section', 'article', 'div'].includes(node.type)) {
            return false;
        }
        if (value === 'auto') {
            if (node.presentation) {
                delete node.presentation.text_color;
                if (Object.keys(node.presentation).length === 0) {
                    delete node.presentation;
                }
            }
            return true;
        }
        var canonical = PRESENTATION_BACKGROUNDS.includes(value)
            ? value
            : canonicalRgba(value);
        if (canonical === null) {
            return false;
        }
        node.presentation = node.presentation || {};
        node.presentation.text_color = canonical;
        return true;
    }

    function v2SetContainerPadding(node, value) {
        if (
            !['section', 'article', 'div'].includes(node.type)
            || !CONTAINER_PADDINGS.includes(value)
        ) {
            return false;
        }
        node.presentation = node.presentation || {};
        node.presentation.padding = value;
        return true;
    }

    function v2SetContainerConfig(node, key, value) {
        if (!['article', 'div'].includes(node.type)) {
            return false;
        }
        node.presentation = node.presentation || {};
        if (key === 'width' && PRESENTATION_WIDTHS.includes(value)) {
            node.presentation.width = value;
            if (!PRESENTATION_ALIGNS.includes(node.presentation.align)) {
                node.presentation.align = value === 'full' ? 'start' : 'center';
            }
            return true;
        }
        if (key === 'align' && PRESENTATION_ALIGNS.includes(value)) {
            node.presentation.align = value;
            if (!PRESENTATION_WIDTHS.includes(node.presentation.width)) {
                node.presentation.width = 'full';
            }
            return true;
        }
        return false;
    }

    function v2ApplyHeadingLevelStyle(context, source) {
        var sourceHeading = source.type === 'heading'
            ? source
            : v2LeadingTextHeading(source);
        if (sourceHeading === null) {
            return false;
        }
        v2EnsureTypographyPresentation(source);
        var changed = false;
        v2Walk(context.documentValue, function (candidate) {
            var targetHeadings = candidate.node.type === 'heading'
                ? [candidate.node]
                : v2TextHeadings(candidate.node);
            var matchingHeadings = targetHeadings.filter(function (heading) {
                return heading !== sourceHeading
                    && heading.level === sourceHeading.level;
            });
            if (matchingHeadings.length === 0) {
                return;
            }
            v2EnsureTypographyPresentation(candidate.node);
            matchingHeadings.forEach(function (heading) {
                heading.preset = sourceHeading.preset || 'default';
            });
            candidate.node.presentation.size = canonicalPresentationSize(
                source.presentation.size || source.presentation.font_size
            ) || 'm';
            delete candidate.node.presentation.font_size;
            candidate.node.presentation.font_weight = source.presentation.font_weight;
            candidate.node.presentation.text_color = source.presentation.text_color;
            candidate.node.presentation.text_align = source.presentation.text_align;
            changed = true;
        });
        return changed;
    }

    function v2ImageRangePolicy(key) {
        if (IMAGE_PRESENTATION_POLICY === null) {
            return null;
        }
        return {
            'image-height-dvh': IMAGE_PRESENTATION_POLICY.height_dvh,
            'image-radius-percent': IMAGE_PRESENTATION_POLICY.radius_percent,
            'image-overlay-opacity': IMAGE_PRESENTATION_POLICY.overlay.opacity
        }[key] || null;
    }

    function v2ValidImageRangeValue(value, policy) {
        return Number.isInteger(value)
            && value >= policy.min
            && value <= policy.max
            && (value - policy.min) % policy.step === 0;
    }

    function v2ImageControlOutput(control, textValue) {
        var field = control.closest('.blogEditor__imageRangeField');
        var output = field ? field.querySelector(
            '[data-blog-image-range-output]'
        ) : null;
        if (output && output.nodeName === 'OUTPUT') {
            output.value = textValue;
            output.textContent = textValue;
        }
    }

    function v2HandleImagePresentationInput(context, control, location) {
        if (
            location.node.type !== 'image'
            || IMAGE_PRESENTATION_POLICY === null
        ) {
            return false;
        }
        var key = control.dataset.blogV2Config || '';
        var presentation = location.node.presentation;
        if (control instanceof HTMLInputElement && control.type === 'range') {
            var rangePolicy = v2ImageRangePolicy(key);
            var numericValue = Number(control.value);
            if (
                rangePolicy === null
                || !v2ValidImageRangeValue(numericValue, rangePolicy)
                || (
                    key === 'image-overlay-opacity'
                    && !Object.prototype.hasOwnProperty.call(
                        presentation,
                        'overlay_opacity'
                    )
                )
            ) {
                return true;
            }
            if (key === 'image-height-dvh') {
                presentation.height_dvh = numericValue;
            } else if (key === 'image-radius-percent') {
                presentation.radius_percent = numericValue;
                delete presentation.radius;
            } else {
                presentation.overlay_opacity = numericValue;
            }
            v2ImageControlOutput(
                control,
                numericValue + (control.dataset.blogImageRangeSuffix || '')
            );
            v2UpdateImagePresentationPreview(context, location.node);
            sync(context);
            return true;
        }
        if (
            control instanceof HTMLInputElement
            && control.type === 'checkbox'
            && ['image-height-dvh-auto', 'image-radius-percent-auto']
                .includes(key)
        ) {
            var rangeField = control.closest('.blogEditor__imageRangeField');
            var range = rangeField
                ? rangeField.querySelector('input[type="range"]')
                : null;
            var rangeKey = key.replace(/-auto$/u, '');
            var policy = v2ImageRangePolicy(rangeKey);
            if (!(range instanceof HTMLInputElement) || policy === null) {
                return true;
            }
            range.disabled = context.readOnly || control.checked;
            if (control.checked) {
                delete presentation[
                    rangeKey === 'image-height-dvh'
                        ? 'height_dvh'
                        : 'radius_percent'
                ];
                v2ImageControlOutput(
                    range,
                    rangeKey === 'image-radius-percent'
                        && Object.prototype.hasOwnProperty.call(
                            presentation,
                            'radius'
                        )
                        ? 'Estilo heredado'
                        : 'Autom\u00e1tica'
                );
            } else {
                var current = Number(range.value);
                if (!v2ValidImageRangeValue(current, policy)) {
                    return true;
                }
                if (rangeKey === 'image-height-dvh') {
                    presentation.height_dvh = current;
                } else {
                    presentation.radius_percent = current;
                    delete presentation.radius;
                }
                v2ImageControlOutput(
                    range,
                    current + (range.dataset.blogImageRangeSuffix || '')
                );
            }
            v2UpdateImagePresentationPreview(context, location.node);
            sync(context);
            return true;
        }
        if (
            control instanceof HTMLInputElement
            && control.type === 'checkbox'
            && key === 'image-overlay-enabled'
        ) {
            var inspector = control.closest('.blogEditor__inspectorSectionBody');
            if (control.checked) {
                var opacityPolicy = IMAGE_PRESENTATION_POLICY.overlay.opacity;
                var preferredOpacity = Math.min(
                    opacityPolicy.max,
                    Math.max(opacityPolicy.min, 40)
                );
                var defaultOpacity = opacityPolicy.min
                    + Math.round(
                        (preferredOpacity - opacityPolicy.min)
                            / opacityPolicy.step
                    ) * opacityPolicy.step;
                var rememberedOpacity = Number(
                    control.dataset.blogImageOverlayOpacity
                );
                presentation.overlay_mode =
                    IMAGE_PRESENTATION_POLICY.overlay.modes.includes(
                        control.dataset.blogImageOverlayMode
                    )
                        ? control.dataset.blogImageOverlayMode
                        : IMAGE_PRESENTATION_POLICY.overlay.modes[0];
                presentation.overlay_color = (
                    IMAGE_PRESENTATION_POLICY.overlay.colors.includes(
                        control.dataset.blogImageOverlayColor
                    )
                    || canonicalRgba(
                        control.dataset.blogImageOverlayColor || ''
                    ) === control.dataset.blogImageOverlayColor
                )
                    ? control.dataset.blogImageOverlayColor
                    : IMAGE_PRESENTATION_POLICY.overlay.colors[0];
                presentation.overlay_opacity = v2ValidImageRangeValue(
                    rememberedOpacity,
                    opacityPolicy
                ) ? rememberedOpacity : defaultOpacity;
            } else {
                if (
                    Object.prototype.hasOwnProperty.call(
                        presentation,
                        'overlay_mode'
                    )
                    && Object.prototype.hasOwnProperty.call(
                        presentation,
                        'overlay_color'
                    )
                    && Object.prototype.hasOwnProperty.call(
                        presentation,
                        'overlay_opacity'
                    )
                ) {
                    control.dataset.blogImageOverlayMode
                        = presentation.overlay_mode;
                    control.dataset.blogImageOverlayColor
                        = presentation.overlay_color;
                    control.dataset.blogImageOverlayOpacity
                        = String(presentation.overlay_opacity);
                }
                delete presentation.overlay_mode;
                delete presentation.overlay_color;
                delete presentation.overlay_opacity;
            }
            if (inspector) {
                var modeControl = inspector.querySelector(
                    'select[data-blog-v2-config="image-overlay-mode"]'
                );
                if (modeControl instanceof HTMLSelectElement) {
                    modeControl.value = presentation.overlay_mode
                        || IMAGE_PRESENTATION_POLICY.overlay.modes[0];
                }
                inspector.querySelectorAll(
                    'button[data-blog-v2-config="image-overlay-color"]'
                ).forEach(function (button) {
                    button.setAttribute(
                        'aria-pressed',
                        button.dataset.blogV2Value
                            === presentation.overlay_color
                            ? 'true' : 'false'
                    );
                });
                var opacityControl = inspector.querySelector(
                    'input[type="range"]'
                        + '[data-blog-v2-config="image-overlay-opacity"]'
                );
                if (opacityControl instanceof HTMLInputElement) {
                    opacityControl.value = String(
                        presentation.overlay_opacity
                            || IMAGE_PRESENTATION_POLICY.overlay.opacity.min
                    );
                    v2ImageControlOutput(
                        opacityControl,
                        opacityControl.value + '%'
                    );
                }
                inspector.querySelectorAll('.blogEditor__imageOverlayOption')
                    .forEach(function (option) {
                        option.dataset.overlayActive = control.checked
                            ? 'true' : 'false';
                        option.querySelectorAll('input, select, button')
                            .forEach(function (input) {
                                input.disabled = context.readOnly
                                    || !control.checked;
                            });
                    });
            }
            v2UpdateImagePresentationPreview(context, location.node);
            sync(context);
            return true;
        }
        if (
            control instanceof HTMLSelectElement
            && key === 'image-overlay-mode'
        ) {
            if (v2SetModuleConfig(location.node, key, control.value)) {
                v2UpdateImagePresentationPreview(context, location.node);
                sync(context);
            }
            return true;
        }
        return false;
    }

    function v2HandleClick(context, event) {
        var trigger = event.target.closest('[data-blog-v2-action]');
        if (!(trigger instanceof HTMLButtonElement)) {
            return;
        }
        var action = trigger.dataset.blogV2Action || '';
        if (action === 'toggle-insert') {
            var insertion = trigger.closest('.blogEditor__insert');
            var menu = insertion
                ? insertion.querySelector('.blogEditor__insertMenu')
                : null;
            if (!(menu instanceof HTMLElement)) {
                return;
            }
            context.blockList.querySelectorAll('.blogEditor__insertMenu')
                .forEach(function (candidate) {
                    if (candidate !== menu) {
                        candidate.hidden = true;
                        var candidateToggle = candidate.parentElement
                            ? candidate.parentElement.querySelector(
                                '[data-blog-v2-action="toggle-insert"]'
                            )
                            : null;
                        if (candidateToggle) {
                            v2SetInsertExpanded(
                                candidateToggle,
                                candidate,
                                false
                            );
                        }
                    }
                });
            v2SetInsertExpanded(trigger, menu, menu.hidden);
            if (!menu.hidden) {
                var firstOption = menu.querySelector('button:not(:disabled)');
                if (firstOption instanceof HTMLButtonElement) {
                    firstOption.focus();
                }
            }
            return;
        }
        if (context.readOnly) {
            return;
        }
        if (action === 'drag-handle') {
            if (context.pointerDragJustEnded) {
                return;
            }
            v2ToggleKeyboardDrag(
                context,
                trigger.dataset.blogV2Node || ''
            );
            return;
        }
        if (action === 'keyboard-drop') {
            var keyboardNodeId = context.keyboardDragNodeId || '';
            var keyboardTarget = v2DropTargetData(trigger);
            if (
                keyboardNodeId
                && keyboardTarget
                && v2CompleteDrop(
                    context,
                    keyboardNodeId,
                    keyboardTarget.ownerId,
                    keyboardTarget.columnId,
                    keyboardTarget.index
                )
            ) {
                return;
            }
            announce(context, 'Esa posición no admite el elemento.', true);
            return;
        }
        if (action === 'toggle-custom-color') {
            var colorWrapper = trigger.closest('.blogEditor__colorSelector');
            var colorPanel = colorWrapper
                ? colorWrapper.querySelector('.blogEditor__customColorPanel')
                : null;
            if (!(colorPanel instanceof HTMLElement)) {
                return;
            }
            colorPanel.hidden = !colorPanel.hidden;
            trigger.setAttribute(
                'aria-expanded',
                colorPanel.hidden ? 'false' : 'true'
            );
            if (!colorPanel.hidden) {
                var firstColor = colorPanel.querySelector('input');
                if (firstColor instanceof HTMLInputElement) {
                    firstColor.focus();
                }
            }
            return;
        }
        if (action === 'apply-custom-color') {
            var customNodeId = trigger.dataset.blogV2Node || '';
            var customKey = trigger.dataset.blogV2Config || '';
            var customLocation = v2Location(
                context.documentValue,
                customNodeId
            );
            var customPanel = trigger.closest('.blogEditor__customColorPanel');
            var customColor = customPanel ? customPanel.querySelector(
                '[data-blog-color-value]'
            ) : null;
            var customAlpha = customPanel ? customPanel.querySelector(
                '[data-blog-color-alpha]'
            ) : null;
            var customValue = customColor instanceof HTMLInputElement
                && customAlpha instanceof HTMLInputElement
                ? rgbaFromControls(customColor.value, customAlpha.value)
                : null;
            var colorUpdated = customLocation && customValue !== null && (
                customKey === 'text-color'
                    ? v2SetModuleConfig(
                        customLocation.node,
                        customKey,
                        customValue
                    )
                    : (
                        customKey === 'separator-color'
                            ? v2SetModuleConfig(
                                customLocation.node,
                                customKey,
                                customValue
                            )
                            : (
                                customKey === 'image-overlay-color'
                                    ? v2SetModuleConfig(
                                        customLocation.node,
                                        customKey,
                                        customValue
                                    )
                                    : (
                                        customKey === 'container-background'
                                        && v2SetContainerBackground(
                                            customLocation.node,
                                            customValue
                                        )
                                    ) || (
                                        customKey === 'container-text-color'
                                        && v2SetContainerTextColor(
                                            customLocation.node,
                                            customValue
                                        )
                                    )
                                )
                            )
                    )
            ;
            if (!colorUpdated) {
                return;
            }
            context.selectedNodeId = customNodeId;
            context.inspectorMode = 'config';
            renderV2(context);
            v2OpenSelectedInspector(context);
            announce(context, 'Color personalizado aplicado.', false);
            return;
        }
        if (action === 'set-config') {
            var configNodeId = trigger.dataset.blogV2Node || '';
            var configKey = trigger.dataset.blogV2Config || '';
            var configValue = trigger.dataset.blogV2Value || '';
            var configLocation = v2Location(
                context.documentValue,
                configNodeId
            );
            var configUpdated = configLocation
                && (
                    BLOCK_TYPES.includes(configLocation.node.type)
                        ? v2SetModuleConfig(
                            configLocation.node,
                            configKey,
                            configValue
                        )
                        : (
                            (
                                ['width', 'align'].includes(configKey)
                                && v2SetContainerConfig(
                                    configLocation.node,
                                    configKey,
                                    configValue
                                )
                            )
                            || (
                                configKey === 'container-background'
                                && v2SetContainerBackground(
                                    configLocation.node,
                                    configValue
                                )
                            )
                            || (
                                configKey === 'container-text-color'
                                && v2SetContainerTextColor(
                                    configLocation.node,
                                    configValue
                                )
                            )
                            || (
                                configKey === 'container-padding'
                                && v2SetContainerPadding(
                                    configLocation.node,
                                    configValue
                                )
                            )
                        )
                );
            if (!configUpdated) {
                return;
            }
            var headerPositionControl = configKey
                    === 'image-object-position-y'
                && context.headerControls instanceof HTMLElement
                && context.headerControls.contains(trigger);
            if (headerPositionControl) {
                var headerPositionPreview = context.headerControls.querySelector(
                    '.blogEditor__headerMediaPreview img'
                );
                if (headerPositionPreview instanceof HTMLImageElement) {
                    headerPositionPreview.dataset.blogImageObjectPositionY
                        = configValue;
                }
                var headerPositionGroup = trigger.closest(
                    '[data-blog-v2-choice-group="image-object-position-y"]'
                );
                if (headerPositionGroup instanceof HTMLElement) {
                    headerPositionGroup.querySelectorAll(
                        '[data-blog-v2-action="set-config"]'
                    ).forEach(function (button) {
                        button.setAttribute(
                            'aria-pressed',
                            button === trigger ? 'true' : 'false'
                        );
                    });
                }
                v2UpdateImagePresentationPreview(
                    context,
                    configLocation.node
                );
                sync(context);
                announce(
                    context,
                    'Posición vertical del hero actualizada.',
                    false
                );
                return;
            }
            context.selectedNodeId = configNodeId;
            context.inspectorMode = 'config';
            renderV2(context);
            v2OpenSelectedInspector(
                context,
                '[data-blog-v2-action="set-config"]'
                    + '[data-blog-v2-node="' + configNodeId + '"]'
                    + '[data-blog-v2-config="' + configKey + '"]'
                    + '[data-blog-v2-value="' + configValue + '"]'
            );
            announce(context, 'Presentaci\u00f3n del elemento actualizada.', false);
            return;
        }
        if (action === 'apply-heading-level') {
            var headingLocation = v2Location(
                context.documentValue,
                trigger.dataset.blogV2Node || ''
            );
            var sourceHeading = headingLocation
                ? (headingLocation.node.type === 'heading'
                    ? headingLocation.node
                    : v2LeadingTextHeading(headingLocation.node))
                : null;
            if (!headingLocation || sourceHeading === null) {
                return;
            }
            v2ApplyHeadingLevelStyle(context, headingLocation.node);
            renderV2(context);
            announce(
                context,
                'Estilo aplicado a los t\u00edtulos H'
                    + sourceHeading.level + ' del art\u00edculo.',
                false
            );
            return;
        }
        if (action === 'add') {
            if (v2Add(context, trigger)) {
                var addedLocation = v2Location(
                    context.documentValue,
                    context.selectedNodeId || ''
                );
                context.inspectorMode = addedLocation
                    && (
                        RICH_TEXT_BLOCK_TYPES.includes(addedLocation.node.type)
                        || addedLocation.node.type === 'embed'
                        || addedLocation.node.type === 'separator'
                    )
                    ? 'config'
                    : 'edit';
                renderV2(context);
                var addedEditTrigger = context.blockList.querySelector(
                    '[data-blog-v2-action="edit"][data-blog-v2-node="'
                        + context.selectedNodeId + '"]'
                );
                v2OpenSelectedEditor(context, addedEditTrigger);
                announce(context, 'Elemento a\u00f1adido.', false);
            }
            return;
        }
        var nodeId = trigger.dataset.blogV2Node || '';
        if (action === 'edit') {
            context.selectedNodeId = nodeId;
            var editLocation = v2Location(context.documentValue, nodeId);
            context.inspectorMode = editLocation
                && (
                    RICH_TEXT_BLOCK_TYPES.includes(editLocation.node.type)
                    || editLocation.node.type === 'embed'
                    || editLocation.node.type === 'separator'
                )
                ? 'config'
                : 'edit';
            renderV2(context);
            var nextEditTrigger = context.blockList.querySelector(
                '[data-blog-v2-action="edit"][data-blog-v2-node="'
                    + nodeId + '"]'
            );
            v2OpenSelectedEditor(context, nextEditTrigger);
            return;
        }
        if (action === 'delete') {
            var deleteLocation = v2Location(context.documentValue, nodeId);
            if (!v2CanDelete(deleteLocation)) {
                return;
            }
            var deleteLabel = BLOCK_LABELS[deleteLocation.node.type]
                || CONTAINER_LABELS[deleteLocation.node.type]
                || 'elemento';
            confirmEditorAction(context, {
                title: 'Eliminar ' + deleteLabel.toLowerCase(),
                message: '\u00bfEliminar este ' + deleteLabel.toLowerCase()
                    + ' y todo su contenido?',
                confirmLabel: 'Eliminar',
                danger: true
            }, trigger).then(function (confirmed) {
                if (!confirmed) {
                    return;
                }
                if (v2MutateAction(context, 'delete', nodeId)) {
                    renderV2(context);
                    announce(context, 'Contenido eliminado.', false);
                }
            });
            return;
        }
        if (v2MutateAction(context, action, nodeId)) {
            renderV2(context);
            announce(context, 'Estructura actualizada.', false);
        }
    }

    function v2HandleChange(context, event) {
        var control = event.target.closest('[data-blog-v2-config]');
        if (
            !(
                control instanceof HTMLSelectElement
                || control instanceof HTMLInputElement
                || control instanceof HTMLTextAreaElement
            )
            || context.readOnly
        ) {
            return;
        }
        var canvasControl = context.blockList.contains(control);
        var location = v2Location(
            context.documentValue,
            control.dataset.blogV2Node || ''
        );
        if (!location) {
            return;
        }
        var key = control.dataset.blogV2Config;
        if (v2HandleImagePresentationInput(context, control, location)) {
            return;
        }
        var previousPreset = key === 'preset'
            && ['article', 'div'].includes(location.node.type)
            ? location.node.layout.preset
            : null;
        if (
            ['text-color-rgb', 'text-color-alpha'].includes(key)
            && ['paragraph', 'heading'].includes(location.node.type)
        ) {
            var textRgbaField = control.closest('.blogEditor__rgbaField');
            var textRgbaColor = textRgbaField ? textRgbaField.querySelector(
                '[data-blog-v2-config="text-color-rgb"]'
            ) : null;
            var textRgbaAlpha = textRgbaField ? textRgbaField.querySelector(
                '[data-blog-v2-config="text-color-alpha"]'
            ) : null;
            var textRgbaValue = textRgbaColor instanceof HTMLInputElement
                && textRgbaAlpha instanceof HTMLInputElement
                ? rgbaFromControls(textRgbaColor.value, textRgbaAlpha.value)
                : null;
            if (
                textRgbaValue === null
                || !v2SetModuleConfig(
                    location.node,
                    'text-color',
                    textRgbaValue
                )
            ) {
                return;
            }
        } else if (
            ['container-background-rgb', 'container-background-alpha']
                .includes(key)
            && ['section', 'article', 'div'].includes(location.node.type)
        ) {
            var rgbaField = control.closest('.blogEditor__rgbaField');
            var rgbaColor = rgbaField ? rgbaField.querySelector(
                '[data-blog-v2-config="container-background-rgb"]'
            ) : null;
            var rgbaAlpha = rgbaField ? rgbaField.querySelector(
                '[data-blog-v2-config="container-background-alpha"]'
            ) : null;
            var rgbaValue = rgbaColor instanceof HTMLInputElement
                && rgbaAlpha instanceof HTMLInputElement
                ? rgbaFromControls(rgbaColor.value, rgbaAlpha.value)
                : null;
            if (rgbaValue === null) {
                return;
            }
            v2SetContainerBackground(location.node, rgbaValue);
        } else if (
            key === 'width'
            && PRESENTATION_WIDTHS.includes(control.value)
        ) {
            if (BLOCK_TYPES.includes(location.node.type)) {
                location.node.presentation.width = control.value;
            } else if (!v2SetContainerConfig(
                location.node,
                key,
                control.value
            )) {
                return;
            }
        } else if (
            key === 'align'
            && PRESENTATION_ALIGNS.includes(control.value)
        ) {
            if (BLOCK_TYPES.includes(location.node.type)) {
                location.node.presentation.align = control.value;
            } else if (!v2SetContainerConfig(
                location.node,
                key,
                control.value
            )) {
                return;
            }
        } else if (
            key === 'text-align'
            && BLOCK_TYPES.includes(location.node.type)
            && PRESENTATION_TEXT_ALIGNS.includes(control.value)
            && (
                location.node.type !== 'cta'
                || control.value !== 'justify'
            )
        ) {
            location.node.presentation.text_align = control.value;
        } else if (
            key === 'heading-level'
            && (
                location.node.type === 'heading'
                || v2LeadingTextHeading(location.node) !== null
            )
            && headingPolicyForContext(context).allowed_levels.includes(
                Number(control.value)
            )
        ) {
            var configuredHeading = location.node.type === 'heading'
                ? location.node
                : v2LeadingTextHeading(location.node);
            configuredHeading.level = Number(control.value);
        } else if (
            key === 'callout-tone'
            && location.node.type === 'callout'
            && CALLOUT_TONES.includes(control.value)
        ) {
            location.node.tone = control.value;
        } else if (
            key === 'quote-author'
            && location.node.type === 'quote'
            && bytes(control.value) <= 255
        ) {
            location.node.author = nullable(control.value);
        } else if (
            key === 'quote-source'
            && location.node.type === 'quote'
            && bytes(control.value) <= 500
        ) {
            location.node.source = nullable(control.value);
        } else if (key === 'preset') {
            var presetSelection = v2ApplyPresetControl(context, control);
            if (!presetSelection) {
                return;
            }
            location = presetSelection.location;
            previousPreset = presetSelection.previousPreset;
        }
        if (canvasControl) {
            context.selectedNodeId = location.node.id;
            context.inspectorMode = 'config';
        }
        renderV2(context);
        if (key === 'preset') {
            var previousCount = COLUMN_PRESETS[previousPreset] || 0;
            var nextCount = COLUMN_PRESETS[control.value] || 0;
            announce(
                context,
                'Distribuci\u00f3n actualizada: '
                    + v2PresetLabel(control.value) + '.'
                    + (previousCount > nextCount
                        ? ' Todo el contenido se ha mantenido en orden en la columna 1, sin perder elementos.'
                        : ''),
                false
            );
            v2FocusLayoutPresetSummary(
                context,
                location.node.id,
                canvasControl
            );
            return;
        }
        announce(context, 'Configuraci\u00f3n actualizada.', false);
    }

    function v2HandleInput(context, event) {
        var control = event.target.closest('[data-blog-v2-config]');
        if (
            !(
                control instanceof HTMLInputElement
                || control instanceof HTMLTextAreaElement
            )
            || context.readOnly
        ) {
            return;
        }
        var location = v2Location(
            context.documentValue,
            control.dataset.blogV2Node || ''
        );
        if (!location) {
            return;
        }
        if (v2HandleImagePresentationInput(context, control, location)) {
            return;
        }
        if (
            location.node.type !== 'quote'
            || !['quote-author', 'quote-source'].includes(
                control.dataset.blogV2Config || ''
            )
        ) {
            return;
        }
        var value = control.value.trim();
        var maximum = control.dataset.blogV2Config === 'quote-author'
            ? 255
            : 500;
        if (bytes(value) > maximum) {
            return;
        }
        if (control.dataset.blogV2Config === 'quote-author') {
            location.node.author = value === '' ? null : value;
        } else {
            location.node.source = value === '' ? null : value;
        }
        refreshV2Canvas(context);
        sync(context);
    }

    function v2BindInspectorTabs(context) {
        if (!context.inspectorRoot) {
            return;
        }
        var tabs = Array.from(context.inspectorRoot.querySelectorAll(
            '[data-blog-inspector-tab]'
        ));
        tabs.forEach(function (button, tabIndex) {
            button.addEventListener('click', function () {
                activateInspectorTab(
                    context,
                    button.dataset.blogInspectorTab || 'entry'
                );
            });
            button.addEventListener('keydown', function (event) {
                var next = null;
                if (event.key === 'ArrowRight') {
                    next = (tabIndex + 1) % tabs.length;
                } else if (event.key === 'ArrowLeft') {
                    next = (tabIndex - 1 + tabs.length) % tabs.length;
                } else if (event.key === 'Home') {
                    next = 0;
                } else if (event.key === 'End') {
                    next = tabs.length - 1;
                }
                if (next === null) {
                    return;
                }
                event.preventDefault();
                activateInspectorTab(
                    context,
                    tabs[next].dataset.blogInspectorTab || 'entry'
                );
                tabs[next].focus();
            });
        });
        activateInspectorTab(context, 'entry');
    }

    function v2CoverBlock(context) {
        var cover = context.documentValue.blocks[0];
        return cover
            && cover.type === 'image'
            && cover.display === 'cover'
                ? cover
                : null;
    }

    function headerCatalogItem(catalog, key) {
        return catalog.find(function (item) {
            return item.key === key;
        }) || null;
    }

    function v2PersistHeaderSelection(context, hero, h1Module) {
        var selection = {
            hero: hero,
            h1_module: h1Module
        };
        if (!validHeaderSelection(selection, context.documentValue.template)) {
            return false;
        }
        context.headerSelection = selection;
        context.documentValue.header = Object.assign({}, selection);
        return true;
    }

    function v2HeaderChoice(context, definition, kind, selected, disabled) {
        var button = element('button', 'blogEditor__headerChoice');
        button.type = 'button';
        button.dataset.blogHeaderChoice = kind;
        button.dataset.blogHeaderValue = definition.key;
        button.setAttribute('aria-pressed', selected ? 'true' : 'false');
        button.disabled = Boolean(disabled);
        var preview = element(
            'span',
            'blogEditor__headerChoicePreview blogEditor__headerChoicePreview--'
                + definition.key
        );
        preview.setAttribute('aria-hidden', 'true');
        button.append(
            preview,
            element('strong', '', definition.label),
            element('small', '', definition.resource)
        );
        button.addEventListener('click', function () {
            if (context.readOnly) {
                return;
            }
            if (kind === 'hero') {
                var requestedTemplate = templateForHero(definition.key);
                var cover = v2CoverBlock(context);
                if (!cover) {
                    if (context.media.length === 0) {
                        announce(
                            context,
                            'No hay imágenes disponibles para el hero.',
                            true
                        );
                        return;
                    }
                    cover = makeBlock(context, 'image');
                    cover.display = 'cover';
                    cover.presentation = {
                        width: 'full',
                        align: 'center',
                        text_align: 'start',
                        size: 'm',
                        radius: 'default'
                    };
                    context.documentValue.blocks.unshift(cover);
                }
                context.documentValue.template = requestedTemplate;
                context.templateSelect.value = requestedTemplate;
                v2PersistHeaderSelection(
                    context,
                    definition.key,
                    currentHeaderSelection(context).h1_module
                );
            } else {
                var current = currentHeaderSelection(context);
                if (!v2PersistHeaderSelection(
                    context,
                    current.hero,
                    definition.key
                )) {
                    return;
                }
            }
            renderV2(context);
            window.requestAnimationFrame(function () {
                var next = context.headerControls.querySelector(
                    '[data-blog-header-choice="' + kind + '"]'
                        + '[data-blog-header-value="' + definition.key + '"]'
                );
                if (next instanceof HTMLButtonElement) {
                    next.focus();
                }
            });
            announce(context, 'Cabecera del artículo actualizada.', false);
        });
        return button;
    }

    function renderHeaderControls(context) {
        if (!(context.headerControls instanceof HTMLElement)) {
            return;
        }
        var selection = currentHeaderSelection(context);
        var cover = v2CoverBlock(context);
        var selectedMedia = cover
            ? mediaOption(context, cover.media_asset_public_id)
            : null;
        var fragment = document.createDocumentFragment();
        var heading = element('h3', '', 'Cabecera del artículo');
        var live = element('p', 'blogEditor__headerStatus');
        live.dataset.blogHeaderStatus = 'true';
        live.setAttribute('role', 'status');
        live.setAttribute('aria-live', 'polite');
        var hero = selection.hero
            ? headerCatalogItem(context.heroCatalog, selection.hero)
            : null;
        var h1Module = headerCatalogItem(
            context.h1ModuleCatalog,
            selection.h1_module
        );
        live.textContent = (hero ? hero.label : 'Sin hero (compatible)')
            + ' · '
            + (h1Module ? h1Module.label : selection.h1_module)
            + ' · '
            + (selectedMedia ? selectedMedia.label : 'Sin imagen destacada');

        var heroGroup = element('fieldset', 'blogEditor__headerGroup');
        heroGroup.disabled = context.readOnly;
        heroGroup.append(element('legend', '', 'Diseño del hero'));
        var heroChoices = element('div', 'blogEditor__headerChoices');
        context.heroCatalog.forEach(function (definition) {
            heroChoices.append(v2HeaderChoice(
                context,
                definition,
                'hero',
                selection.hero === definition.key,
                !cover && context.media.length === 0
            ));
        });
        heroGroup.append(heroChoices);

        var moduleGroup = element('fieldset', 'blogEditor__headerGroup');
        moduleGroup.disabled = context.readOnly;
        moduleGroup.append(element('legend', '', 'Composición del H1'));
        var moduleChoices = element('div', 'blogEditor__headerChoices');
        context.h1ModuleCatalog.forEach(function (definition) {
            moduleChoices.append(v2HeaderChoice(
                context,
                definition,
                'h1-module',
                selection.h1_module === definition.key,
                false
            ));
        });
        moduleGroup.append(moduleChoices);

        var mediaGroup = element('div', 'blogEditor__headerMedia');
        mediaGroup.append(element('h4', '', 'Imagen destacada activa'));
        var mediaPreview = element('figure', 'blogEditor__headerMediaPreview');
        if (selectedMedia && selectedMedia.thumbnailUrl) {
            var previewImage = document.createElement('img');
            previewImage.src = selectedMedia.thumbnailUrl;
            previewImage.alt = '';
            previewImage.loading = 'lazy';
            previewImage.dataset.blogImageObjectPositionY = cover
                && cover.presentation
                && cover.presentation.object_position_y
                ? cover.presentation.object_position_y
                : 'center';
            mediaPreview.append(previewImage);
        } else {
            mediaPreview.append(element(
                'div',
                'blogEditor__headerMediaPlaceholder',
                selectedMedia ? selectedMedia.label : 'Sin imagen disponible'
            ));
        }
        mediaPreview.append(element(
            'figcaption',
            '',
            selectedMedia ? selectedMedia.label : 'Sin imagen destacada'
        ));
        mediaGroup.append(mediaPreview);
        if (context.mediaDialog && !context.readOnly) {
            var chooseHeaderMedia = element(
                'button',
                'blogEditor__mediaChooseButton',
                'Elegir o subir imagen'
            );
            chooseHeaderMedia.type = 'button';
            chooseHeaderMedia.addEventListener('click', function () {
                openMediaDialog(
                    context,
                    cover ? cover.media_asset_public_id : '',
                    function (media) {
                        var activeCover = v2CoverBlock(context);
                        if (!activeCover) {
                            renderV2(context);
                            announce(
                                context,
                                'Imagen subida a la biblioteca. Ya puedes elegir un hero.',
                                false
                            );
                            return;
                        }
                        activeCover.media_asset_public_id = media.publicId;
                        renderV2(context);
                        announce(
                            context,
                            'Imagen destacada actualizada.',
                            false
                        );
                    }
                );
            });
            mediaGroup.append(chooseHeaderMedia);
        }
        if (cover) {
            mediaGroup.append(v2ConfigButtonGroup(
                context,
                'Posici\u00f3n vertical de la imagen',
                cover.presentation.object_position_y || 'center',
                [
                    { value: 'top', label: 'Arriba' },
                    { value: 'center', label: 'Centro' },
                    { value: 'bottom', label: 'Abajo' }
                ],
                cover.id,
                'image-object-position-y',
                'object-position-y'
            ));
        }

        fragment.append(heading, live, heroGroup, moduleGroup, mediaGroup);
        context.headerControls.replaceChildren(fragment);
        context.headerSettings.dataset.enhanced = 'true';
    }

    function v2BindTemplate(context) {
        context.templateSelect.addEventListener('change', function () {
            if (context.readOnly) {
                context.templateSelect.value = context.documentValue.template;
                return;
            }
            var requested = context.templateSelect.value;
            if (requested === context.documentValue.template) {
                return;
            }
            if (!TEMPLATES.includes(requested)) {
                context.templateSelect.value = context.documentValue.template;
                return;
            }
            var previous = context.documentValue.template;
            var requestedHasCover = templateHasCover(requested);
            var previousHasCover = templateHasCover(previous);
            if (requestedHasCover && !previousHasCover) {
                if (v2DocumentNodeCount(context.documentValue) + 1 > MAX_BLOCKS) {
                    context.templateSelect.value = context.documentValue.template;
                    announce(
                        context,
                        'El documento alcanz\u00f3 el m\u00e1ximo de elementos.',
                        true
                    );
                    return;
                }
                if (context.media.length === 0) {
                    context.templateSelect.value = context.documentValue.template;
                    announce(context, 'No hay im\u00e1genes disponibles para la portada.', true);
                    return;
                }
                var cover = makeBlock(context, 'image');
                cover.display = 'cover';
                cover.presentation = {
                    width: 'full',
                    align: 'center',
                    text_align: 'start'
                };
                context.documentValue.blocks.unshift(cover);
                context.documentValue.template = requested;
                context.selectedNodeId = cover.id;
                context.inspectorMode = 'edit';
            } else if (!requestedHasCover && previousHasCover) {
                var firstSection = context.documentValue.blocks.find(
                    function (block) { return block.type === 'section'; }
                );
                if (!firstSection) {
                    context.templateSelect.value = context.documentValue.template;
                    announce(
                        context,
                        'A\u00f1ade una secci\u00f3n antes de retirar la portada.',
                        true
                    );
                    return;
                }
                var formerCover = context.documentValue.blocks.shift();
                formerCover.display = 'content';
                formerCover.presentation = {
                    width: 'full',
                    align: 'center',
                    text_align: 'start'
                };
                firstSection.children.splice(1, 0, formerCover);
                context.documentValue.template = requested;
                context.selectedNodeId = formerCover.id;
                context.inspectorMode = 'edit';
            } else {
                context.documentValue.template = requested;
            }
            var requestedHero = requested === TEMPLATE_BASIC
                ? null
                : templateHeroPreset(requested);
            if (!v2PersistHeaderSelection(
                context,
                requestedHero,
                context.headerSelection.h1_module
            )) {
                context.templateSelect.value = previous;
                context.documentValue.template = previous;
                announce(
                    context,
                    'No se pudo aplicar la cabecera seleccionada.',
                    true
                );
                return;
            }
            renderV2(context);
            v2OpenSelectedInspector(context);
        });
    }

    function v2DocumentCanBeSaved(context) {
        sync(context);
        if (
            !context.layoutEditorReady
            || context.readOnly
            || !validV2DraftDocument(context.documentValue)
        ) {
            return false;
        }
        var issue = firstEntryTechnicalIssue(context)
            || firstDocumentTechnicalIssue(context);
        if (issue) {
            v2ApplyValidationIssue(context, issue);
            return false;
        }
        return true;
    }

    function v2SyncEditorialLockVersion(context, lockVersion) {
        if (!Number.isInteger(lockVersion) || lockVersion < 1) {
            return false;
        }
        var sourcePost = context.form.elements.namedItem('post');
        var sourceLocale = context.form.elements.namedItem('locale');
        if (
            !(sourcePost instanceof HTMLInputElement)
            || !(sourceLocale instanceof HTMLInputElement)
        ) {
            return false;
        }
        var synchronized = false;
        document.querySelectorAll('form').forEach(function (form) {
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            var post = form.elements.namedItem('post');
            var locale = form.elements.namedItem('locale');
            var lock = form.elements.namedItem('lock_version');
            if (
                !(post instanceof HTMLInputElement)
                || !(locale instanceof HTMLInputElement)
                || !(lock instanceof HTMLInputElement)
                || post.value !== sourcePost.value
                || locale.value !== sourceLocale.value
            ) {
                return;
            }
            lock.value = String(lockVersion);
            synchronized = true;
        });
        return synchronized;
    }

    function v2SyncCategoryWorkspaceVersion(context, workspaceVersion) {
        if (!Number.isInteger(workspaceVersion) || workspaceVersion < 0) {
            return false;
        }
        var sourcePost = context.form.elements.namedItem('post');
        if (!(sourcePost instanceof HTMLInputElement)) {
            return false;
        }
        var synchronized = false;
        document.querySelectorAll('form').forEach(function (form) {
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            var post = form.elements.namedItem('post');
            var version = form.elements.namedItem(
                'category_workspace_version'
            );
            if (
                !(post instanceof HTMLInputElement)
                || !(version instanceof HTMLInputElement)
                || post.value !== sourcePost.value
            ) {
                return;
            }
            version.value = String(workspaceVersion);
            synchronized = true;
        });
        return synchronized;
    }

    function v2SyncTagWorkspaceVersion(context, workspaceVersion) {
        if (!Number.isInteger(workspaceVersion) || workspaceVersion < 0) {
            return false;
        }
        var sourcePost = context.form.elements.namedItem('post');
        var sourceLocale = context.form.elements.namedItem('locale');
        if (
            !(sourcePost instanceof HTMLInputElement)
            || !(sourceLocale instanceof HTMLInputElement)
        ) {
            return false;
        }
        var synchronized = false;
        document.querySelectorAll('form').forEach(function (form) {
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            var post = form.elements.namedItem('post');
            var locale = form.elements.namedItem('locale');
            var version = form.elements.namedItem('tag_workspace_version');
            if (
                !(post instanceof HTMLInputElement)
                || !(locale instanceof HTMLInputElement)
                || !(version instanceof HTMLInputElement)
                || post.value !== sourcePost.value
                || locale.value !== sourceLocale.value
            ) {
                return;
            }
            version.value = String(workspaceVersion);
            synchronized = true;
        });
        return synchronized;
    }

    function v2SyncCsrfToken(csrfToken) {
        if (
            typeof csrfToken !== 'string'
            || !/^[A-Za-z0-9_-]{43}$/u.test(csrfToken)
        ) {
            return false;
        }
        var synchronized = false;
        document.querySelectorAll('form').forEach(function (form) {
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            var csrf = form.elements.namedItem('csrf');
            if (!(csrf instanceof HTMLInputElement)) {
                return;
            }
            csrf.value = csrfToken;
            synchronized = true;
        });
        return synchronized;
    }

    function v2SaveError(code, payload) {
        var error = new Error(code);
        error.saveCode = code;
        error.payload = payload || null;
        return error;
    }

    function v2SaveErrorCode(error) {
        return error && typeof error.saveCode === 'string'
            ? error.saveCode
            : 'save-unavailable';
    }

    function v2DocumentSha256(documentValue) {
        if (
            !window.crypto
            || !window.crypto.subtle
            || typeof window.crypto.subtle.digest !== 'function'
            || typeof TextEncoder !== 'function'
        ) {
            return Promise.reject(v2SaveError('save-invalid-response'));
        }
        var encoded = new TextEncoder().encode(JSON.stringify(documentValue));
        return window.crypto.subtle.digest('SHA-256', encoded).then(
            function (digest) {
                return Array.from(new Uint8Array(digest)).map(
                    function (value) {
                        return value.toString(16).padStart(2, '0');
                    }
                ).join('');
            }
        ).catch(function () {
            throw v2SaveError('save-invalid-response');
        });
    }

    function v2ReadSaveResponse(response) {
        if (response.redirected) {
            try {
                var redirected = new URL(response.url, window.location.href);
                if (
                    redirected.origin === window.location.origin
                    && /\/login$/u.test(redirected.pathname)
                ) {
                    throw v2SaveError('session_expired');
                }
            } catch (error) {
                if (v2SaveErrorCode(error) === 'session_expired') {
                    throw error;
                }
            }
        }
        var contentType = response.headers.get('Content-Type') || '';
        if (!contentType.startsWith('application/json')) {
            throw v2SaveError('save-unavailable');
        }
        return response.json().catch(function () {
            throw v2SaveError('save-invalid-response');
        }).then(function (payload) {
            if (response.ok) {
                return payload;
            }
            var allowed = [
                'session_expired',
                'csrf_stale',
                'forbidden',
                'lock_conflict',
                'invalid_draft',
                'conflict',
                'not_found',
                'unavailable'
            ];
            var code = payload && allowed.includes(payload.error)
                ? payload.error
                : 'save-unavailable';
            throw v2SaveError(code, payload);
        });
    }

    function v2SaveFailureMessage(error) {
        return {
            session_expired:
                'La sesi\u00f3n ha caducado. Tus cambios siguen en el editor. Inicia sesi\u00f3n en otra pesta\u00f1a y vuelve a guardar.',
            csrf_stale:
                'La sesi\u00f3n cambi\u00f3 y no se pudo renovar. Tus cambios siguen en el editor.',
            forbidden:
                'No tienes permiso para guardar este art\u00edculo. Tus cambios siguen en el editor.',
            lock_conflict:
                'El art\u00edculo cambi\u00f3 en otra pesta\u00f1a. Tus cambios siguen aqu\u00ed; revisa la versi\u00f3n guardada antes de continuar.',
            invalid_draft:
                'El borrador contiene un dato t\u00e9cnico no v\u00e1lido. Tus cambios siguen en el editor.',
            conflict:
                'El borrador entra en conflicto con el estado guardado. Tus cambios siguen en el editor.',
            not_found:
                'El art\u00edculo ya no est\u00e1 disponible. Tus cambios siguen en el editor.',
            unavailable:
                'El guardado no est\u00e1 disponible temporalmente. Tus cambios siguen en el editor.'
        }[v2SaveErrorCode(error)]
            || 'No se pudo guardar ahora. Tus cambios siguen en el editor.';
    }

    function v2PublishFailureMessage(error) {
        return {
            session_expired:
                'La sesi\u00f3n ha caducado. El borrador sigue en el editor. Inicia sesi\u00f3n en otra pesta\u00f1a y vuelve a publicar.',
            csrf_stale:
                'La sesi\u00f3n cambi\u00f3 y no se pudo renovar. El borrador sigue en el editor.',
            forbidden:
                'No tienes permiso para publicar este art\u00edculo. El borrador se conserva.',
            lock_conflict:
                'El art\u00edculo cambi\u00f3 en otra pesta\u00f1a. El borrador se conserva; revisa la versi\u00f3n guardada antes de publicar.',
            invalid_draft:
                'El borrador guardado a\u00fan no re\u00fane los datos necesarios para publicarse. Puedes seguir edit\u00e1ndolo.',
            conflict:
                'El borrador entra en conflicto con el estado publicado. Se conserva sin publicar.',
            not_found:
                'El art\u00edculo ya no est\u00e1 disponible. El borrador sigue en el editor.',
            unavailable:
                'La publicaci\u00f3n no est\u00e1 disponible temporalmente. El borrador se conserva.'
        }[v2SaveErrorCode(error)]
            || 'No se pudo publicar ahora. El borrador se conserva.';
    }

    function v2SetPublicationStatus(context, status) {
        var labels = {
            draft: 'Borrador',
            published: 'Publicado'
        };
        var label = labels[status];
        if (
            typeof label !== 'string'
            || !Array.isArray(context.publicationStatuses)
        ) {
            return;
        }
        context.publicationStatuses.forEach(function (target) {
            if (!(target instanceof HTMLElement)) {
                return;
            }
            target.dataset.blogEditorPublicationStatus = status;
            target.textContent = label;
            if (target.hasAttribute('aria-label')) {
                target.setAttribute(
                    'aria-label',
                    'Estado del art\u00edculo: ' + label
                );
            }
        });
    }

    function v2SaveDraft(context) {
        if (!v2DocumentCanBeSaved(context)) {
            announce(
                context,
                'Revisa la estructura o los datos técnicos antes de guardar.',
                true
            );
            return Promise.resolve(false);
        }
        if (typeof window.fetch !== 'function') {
            announce(
                context,
                'El guardado as\u00edncrono no est\u00e1 disponible.',
                true
            );
            return Promise.resolve(false);
        }
        if (context.savePending) {
            return context.savePromise || Promise.resolve(false);
        }

        context.savePending = true;
        context.form.setAttribute('aria-busy', 'true');
        var submitters = Array.from(context.form.elements).filter(
            function (control) {
                return control instanceof HTMLButtonElement
                    && control.type === 'submit';
            }
        );
        submitters.forEach(function (button) { button.disabled = true; });
        announce(context, 'Guardando cambios\u2026', false);
        var submittedFingerprint = editorialFormFingerprint(context.form);
        var submittedBody = editorFormBody(context.form);

        function request(attempt) {
            return window.fetch(context.form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-LiquidStack-Editor': 'async'
                },
                body: submittedBody.toString(),
                redirect: 'follow'
            }).then(v2ReadSaveResponse).catch(function (error) {
                if (
                    attempt === 0
                    && v2SaveErrorCode(error) === 'csrf_stale'
                    && error.payload
                    && v2SyncCsrfToken(error.payload.csrf)
                ) {
                    submittedBody.set('csrf', error.payload.csrf);
                    return request(1);
                }
                throw error;
            });
        }

        context.savePromise = request(0).then(function (payload) {
            var savedDocument = payload && payload.document
                ? richClone(payload.document)
                : null;
            normalizeUnifiedTextModules(savedDocument);
            if (
                !payload
                || payload.ok !== true
                || !Number.isInteger(payload.lock_version)
                || payload.lock_version < 1
                || typeof payload.document_sha256 !== 'string'
                || !/^[0-9a-f]{64}$/u.test(payload.document_sha256)
                || !validV2DraftDocument(savedDocument)
            ) {
                throw v2SaveError('save-invalid-response');
            }
            return v2DocumentSha256(savedDocument).then(function (sha256) {
                if (sha256 !== payload.document_sha256) {
                    throw v2SaveError('save-invalid-response');
                }
                sync(context);
                var unchangedSinceSubmit = editorialFormFingerprint(
                    context.form
                ) === submittedFingerprint;
                if (unchangedSinceSubmit) {
                    context.documentValue = savedDocument;
                    renderV2(context);
                }
                if (!v2SyncEditorialLockVersion(
                    context,
                    payload.lock_version
                )) {
                    throw v2SaveError('save-invalid-response');
                }
                sync(context);
                context.initialFingerprint = unchangedSinceSubmit
                    ? editorialFormFingerprint(context.form)
                    : submittedFingerprint;
                context.allowNavigation = false;
                context.saveHasPendingChanges = !unchangedSinceSubmit;
                announce(context, unchangedSinceSubmit
                    ? 'Cambios guardados.'
                    : 'Se guard\u00f3 la versi\u00f3n enviada. Hay cambios posteriores pendientes de guardar.',
                !unchangedSinceSubmit);
                return unchangedSinceSubmit;
            });
        }).catch(function (error) {
            context.saveHasPendingChanges = true;
            v2ApplyErrorIssue(context, error);
            var failureMessage = v2SaveFailureMessage(error);
            announce(context, failureMessage, true);
            showEditorServerNotice(
                context,
                error,
                failureMessage,
                submitters[0] || context.form
            );
            return false;
        }).finally(function () {
            context.savePending = false;
            context.savePromise = null;
            context.form.removeAttribute('aria-busy');
            submitters.forEach(function (button) {
                button.disabled = context.readOnly;
            });
        });
        return context.savePromise;
    }

    function v2PublishSaved(context, publishForm) {
        if (!(publishForm instanceof HTMLFormElement)) {
            return Promise.resolve(false);
        }
        if (typeof window.fetch !== 'function') {
            announce(
                context,
                'La publicaci\u00f3n as\u00edncrona no est\u00e1 disponible. El borrador se conserva.',
                true
            );
            return Promise.resolve(false);
        }
        if (context.publishPending) {
            return context.publishPromise || Promise.resolve(false);
        }

        context.publishPending = true;
        publishForm.setAttribute('aria-busy', 'true');
        var submitters = Array.from(publishForm.elements).filter(
            function (control) {
                return control instanceof HTMLButtonElement
                    && control.type === 'submit';
            }
        ).map(function (button) {
            var state = { button: button, disabled: button.disabled };
            button.disabled = true;
            return state;
        });
        announce(context, 'Preparando publicaci\u00f3n\u2026', false);

        context.publishPromise = v2PrepareSavedPreview(context).then(
            function (ready) {
                if (!ready) {
                    return false;
                }
                var submittedFingerprint = editorialFormFingerprint(
                    context.form
                );
                var submittedBody = formBody(publishForm);

                function request(attempt) {
                    return window.fetch(publishForm.action, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                            'X-LiquidStack-Editor': 'async'
                        },
                        body: submittedBody.toString(),
                        redirect: 'follow'
                    }).then(v2ReadSaveResponse).catch(function (error) {
                        if (
                            attempt === 0
                            && v2SaveErrorCode(error) === 'csrf_stale'
                            && error.payload
                            && v2SyncCsrfToken(error.payload.csrf)
                        ) {
                            submittedBody.set('csrf', error.payload.csrf);
                            return request(1);
                        }
                        throw error;
                    });
                }

                announce(context, 'Publicando art\u00edculo\u2026', false);
                return request(0).then(function (payload) {
                    if (
                        !payload
                        || payload.ok !== true
                        || payload.status !== 'published'
                        || !Number.isInteger(payload.lock_version)
                        || payload.lock_version < 1
                        || !Number.isInteger(payload.category_workspace_version)
                        || payload.category_workspace_version < 0
                        || !Number.isInteger(payload.tag_workspace_version)
                        || payload.tag_workspace_version < 0
                        || !v2SyncEditorialLockVersion(
                            context,
                            payload.lock_version
                        )
                        || !v2SyncCategoryWorkspaceVersion(
                            context,
                            payload.category_workspace_version
                        )
                        || !v2SyncTagWorkspaceVersion(
                            context,
                            payload.tag_workspace_version
                        )
                    ) {
                        throw v2SaveError('save-invalid-response');
                    }
                    v2SetPublicationStatus(context, payload.status);
                    sync(context);
                    var unchangedSinceSubmit = editorialFormFingerprint(
                        context.form
                    ) === submittedFingerprint;
                    context.initialFingerprint = submittedFingerprint;
                    context.saveHasPendingChanges = !unchangedSinceSubmit;
                    announce(
                        context,
                        unchangedSinceSubmit
                            ? 'Art\u00edculo publicado.'
                            : 'Art\u00edculo publicado. Hay cambios posteriores pendientes de guardar.',
                        !unchangedSinceSubmit
                    );
                    return true;
                });
            }
        ).catch(function (error) {
            v2ApplyErrorIssue(context, error);
            var failureMessage = v2PublishFailureMessage(error);
            announce(context, failureMessage, true);
            showEditorServerNotice(
                context,
                error,
                failureMessage,
                submitters[0] ? submitters[0].button : publishForm
            );
            return false;
        }).finally(function () {
            context.publishPending = false;
            context.publishPromise = null;
            publishForm.removeAttribute('aria-busy');
            submitters.forEach(function (state) {
                state.button.disabled = state.disabled;
            });
        });
        return context.publishPromise;
    }

    function v2BindPublish(context) {
        var publishForm = v2PublishForm(context);
        if (!(publishForm instanceof HTMLFormElement)) {
            return;
        }
        publishForm.addEventListener('submit', function (event) {
            if (typeof window.fetch !== 'function') {
                return;
            }
            event.preventDefault();
            v2PublishSaved(context, publishForm);
        });
    }

    function v2BindSave(context) {
        context.form.addEventListener('submit', function (event) {
            if (typeof window.fetch !== 'function') {
                context.allowNavigation = true;
                return;
            }
            event.preventDefault();
            context.allowNavigation = false;
            try {
                v2PrepareSavedPreview(context, true);
            } catch (error) {
                context.savePending = false;
                context.savePromise = null;
                context.saveHasPendingChanges = true;
                context.form.removeAttribute('aria-busy');
                Array.from(context.form.elements).forEach(function (control) {
                    if (
                        control instanceof HTMLButtonElement
                        && control.type === 'submit'
                    ) {
                        control.disabled = context.readOnly;
                    }
                });
                announce(
                    context,
                    'No se pudo preparar el guardado. Tus cambios siguen en el editor.',
                    true
                );
            }
        });
    }

    function v2PreviewLink(context) {
        var root = context.form.closest('.blogEditor');
        if (!(root instanceof HTMLElement)) {
            return null;
        }
        var explicit = root.querySelector('a[data-blog-editor-preview]');
        var candidates = explicit instanceof HTMLAnchorElement
            ? [explicit]
            : Array.from(root.querySelectorAll('.blogEditor__navigation a'));
        return candidates
            .find(function (link) {
                try {
                    var destination = new URL(
                        link.href,
                        window.location.href
                    );
                    return destination.origin === window.location.origin
                        && destination.pathname.endsWith('/editor/preview');
                } catch (error) {
                    return false;
                }
            }) || null;
    }

    function v2PublishForm(context) {
        var explicit = document.querySelector(
            '[data-blog-editor-publish-form]'
        );
        var candidate = explicit instanceof HTMLFormElement
            ? explicit
            : (
                context.inspectorRoot
                    ? context.inspectorRoot.querySelector(
                        '.blogEditor__publication form'
                    )
                    : null
            );
        if (!(candidate instanceof HTMLFormElement)) {
            return null;
        }
        var submit = candidate.querySelector('button[type="submit"]');
        if (
            !(submit instanceof HTMLButtonElement)
            || submit.disabled
            || !/^Publicar\b/u.test(submit.textContent.trim())
        ) {
            return null;
        }
        return candidate;
    }

    function v2DocumentNeedsSave(context) {
        sync(context);
        return editorialFormFingerprint(context.form)
            !== context.initialFingerprint;
    }

    function v2PreviewNeedsSave(context) {
        return v2DocumentNeedsSave(context)
            || editorialFacetsHaveChanges(context);
    }

    function v2HasUnsavedChanges(context) {
        return v2PreviewNeedsSave(context)
            || richModalDirty(context.richEditor);
    }

    function v2PrepareSavedPreview(context, forceDocumentSave) {
        if (richModalDirty(context.richEditor)) {
            announce(
                context,
                'Aplica o descarta primero los cambios del editor de texto.',
                true
            );
            return Promise.resolve(false);
        }
        if (context.readOnly) {
            return Promise.resolve(true);
        }
        if (!editorialFacetsCommit(context)) {
            return Promise.resolve(false);
        }
        if (!editorialFacetsHaveChanges(context)) {
            return forceDocumentSave || v2DocumentNeedsSave(context)
                ? v2SaveDraft(context)
                : Promise.resolve(true);
        }
        return waitEditorialFacets(context).then(function (ready) {
            if (!ready) {
                return false;
            }
            return forceDocumentSave || v2DocumentNeedsSave(context)
                ? v2SaveDraft(context)
                : true;
        }).then(function (ready) {
            return ready ? saveEditorialFacets(context) : false;
        });
    }

    function v2LoadPreview(state) {
        if (state.loaded && state.frame.contentWindow) {
            try {
                state.frame.contentWindow.location.reload();
                return;
            } catch (error) {
                state.loaded = false;
            }
        }
        state.frame.src = state.url;
    }

    function v2SetPreviewStatus(state, message, status) {
        state.status.textContent = message;
        state.status.setAttribute(
            'aria-live',
            status === 'error' ? 'assertive' : 'polite'
        );
        if (status) {
            state.status.dataset.state = status;
        } else {
            delete state.status.dataset.state;
        }
    }

    function v2SetPreviewPending(context, state, pending) {
        state.pending = pending;
        context.previewPending = pending;
        state.save.disabled = pending || context.readOnly;
        if (state.publish) {
            state.publish.disabled = pending;
        }
        state.deviceButtons.forEach(function (button) {
            button.disabled = pending || !state.loaded;
        });
        if (pending) {
            state.trigger.setAttribute('aria-busy', 'true');
            state.trigger.setAttribute('aria-disabled', 'true');
        } else {
            state.trigger.removeAttribute('aria-busy');
            state.trigger.removeAttribute('aria-disabled');
        }
    }

    function v2ResetPreviewFrame(state) {
        state.loaded = false;
        state.frame.removeAttribute('src');
        state.frame.removeAttribute('srcdoc');
        state.deviceButtons.forEach(function (button) {
            button.disabled = true;
        });
    }

    function v2PreviewFailure(context, state, message) {
        v2SetPreviewPending(context, state, false);
        state.save.textContent = 'Reintentar guardado';
        v2SetPreviewStatus(state, message, 'error');
    }

    function v2PreviewDidLoad(context, state) {
        var source = state.frame.getAttribute('src');
        if (source === null) {
            return false;
        }
        if (source !== state.url) {
            v2ResetPreviewFrame(state);
            v2PreviewFailure(
                context,
                state,
                'No se pudo validar la vista previa SSR. Reintenta el guardado o vuelve al editor.'
            );
            return false;
        }

        try {
            var expectedUrl = new URL(state.url, window.location.href);
            if (expectedUrl.origin !== window.location.origin) {
                throw new Error('preview-origin-mismatch');
            }
            var contentWindow = state.frame.contentWindow;
            var contentDocument = state.frame.contentDocument;
            if (!contentWindow || !contentDocument) {
                throw new Error('preview-document-unavailable');
            }
            var actualUrl = new URL(
                contentWindow.location.href,
                window.location.href
            );
            if (
                actualUrl.origin !== expectedUrl.origin
                || actualUrl.pathname !== expectedUrl.pathname
                || actualUrl.search !== expectedUrl.search
                || actualUrl.hash !== expectedUrl.hash
                || /\/login\/?$/u.test(actualUrl.pathname)
            ) {
                throw new Error('preview-location-mismatch');
            }
            var root = contentDocument.documentElement;
            if (
                !root
                || String(root.tagName).toLowerCase() !== 'html'
                || root.getAttribute('data-blog-preview-ready') !== 'true'
            ) {
                throw new Error('preview-marker-missing');
            }
        } catch (error) {
            v2ResetPreviewFrame(state);
            v2PreviewFailure(
                context,
                state,
                'No se pudo validar la vista previa SSR. Reintenta el guardado o vuelve al editor.'
            );
            return false;
        }
        state.loaded = true;
        state.save.textContent = 'Guardar borrador';
        v2SetPreviewPending(context, state, false);
        v2SetPreviewStatus(state, 'Vista previa SSR actualizada.', 'ok');
        return true;
    }

    function v2RefreshPreview(context, state, forceSave) {
        if (state.pending) {
            return state.pendingPromise || Promise.resolve(false);
        }
        if (richModalDirty(context.richEditor)) {
            v2ResetPreviewFrame(state);
            announce(
                context,
                'Aplica o descarta primero los cambios del editor de texto.',
                true
            );
            v2PreviewFailure(
                context,
                state,
                'No se pudo guardar: aplica o descarta primero los cambios del editor de texto.'
            );
            return Promise.resolve(false);
        }

        var needsSave;
        try {
            needsSave = forceSave || v2PreviewNeedsSave(context);
        } catch (error) {
            v2ResetPreviewFrame(state);
            v2PreviewFailure(
                context,
                state,
                'No se pudo preparar el borrador. Vuelve al editor y reinténtalo.'
            );
            return Promise.resolve(false);
        }

        if (!needsSave) {
            v2SetPreviewPending(context, state, true);
            v2SetPreviewStatus(
                state,
                'Cargando vista previa SSR…',
                'pending'
            );
            v2LoadPreview(state);
            return Promise.resolve(true);
        }

        v2ResetPreviewFrame(state);
        v2SetPreviewPending(context, state, true);
        state.save.textContent = 'Guardando…';
        v2SetPreviewStatus(state, 'Guardando borrador…', 'pending');
        state.pendingPromise = Promise.resolve().then(function () {
            return v2PrepareSavedPreview(context, forceSave);
        }).then(function (saved) {
            if (!saved) {
                v2PreviewFailure(
                    context,
                    state,
                    'No se pudo completar el guardado. Tus cambios siguen en el editor; reinténtalo.'
                );
                return false;
            }
            state.save.textContent = 'Guardar borrador';
            v2SetPreviewStatus(
                state,
                'Borrador guardado. Cargando vista previa SSR…',
                'pending'
            );
            v2LoadPreview(state);
            return true;
        }).catch(function () {
            v2PreviewFailure(
                context,
                state,
                'No se pudo guardar el borrador. Tus cambios siguen en el editor; reinténtalo.'
            );
            return false;
        }).finally(function () {
            state.pendingPromise = null;
        });
        return state.pendingPromise;
    }

    function v2SetPreviewDevice(state, device) {
        if (!['desktop', 'tablet', 'mobile'].includes(device)) {
            return;
        }
        state.stage.dataset.device = device;
        state.deviceButtons.forEach(function (button) {
            button.setAttribute(
                'aria-pressed',
                button.dataset.blogPreviewDevice === device ? 'true' : 'false'
            );
        });
        v2SetPreviewStatus(state, {
            desktop: 'Vista de escritorio.',
            tablet: 'Vista de tablet.',
            mobile: 'Vista m\u00f3vil.'
        }[device], 'ok');
    }

    function v2ClosePreview(state) {
        if (state.dialog.open && typeof state.dialog.close === 'function') {
            state.dialog.close();
        } else {
            state.dialog.removeAttribute('open');
        }
    }

    function v2CreatePreview(context, trigger) {
        var dialog = document.createElement('dialog');
        dialog.className = 'blogEditor__immersivePreview';
        dialog.setAttribute('aria-labelledby', controlId(context, 'preview-title'));

        var title = element(
            'h2',
            'blogEditor__immersivePreviewTitle',
            'Vista previa del art\u00edculo'
        );
        title.id = dialog.getAttribute('aria-labelledby');
        var stage = element('div', 'blogEditor__immersivePreviewStage');
        stage.dataset.device = 'desktop';
        var viewport = element('div', 'blogEditor__immersivePreviewViewport');
        var frame = document.createElement('iframe');
        frame.className = 'blogEditor__immersivePreviewFrame';
        frame.title = 'Vista previa SSR del art\u00edculo';
        viewport.append(frame);
        stage.append(viewport);

        var toolbar = element('div', 'blogEditor__immersivePreviewToolbar');
        var deviceGroup = element(
            'div',
            'blogEditor__immersivePreviewDevices'
        );
        deviceGroup.setAttribute('role', 'group');
        deviceGroup.setAttribute('aria-label', 'Tama\u00f1o de la vista previa');
        var deviceButtons = ['desktop', 'tablet', 'mobile'].map(
            function (device) {
                var label = {
                    desktop: 'Desktop',
                    tablet: 'Tablet',
                    mobile: 'M\u00f3vil'
                }[device];
                var button = element('button', '', label);
                button.type = 'button';
                button.dataset.blogPreviewDevice = device;
                button.setAttribute(
                    'aria-pressed',
                    device === 'desktop' ? 'true' : 'false'
                );
                deviceGroup.append(button);
                return button;
            }
        );
        var status = element('p', 'blogEditor__immersivePreviewStatus');
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.textContent = 'Preparando vista previa…';

        var actions = element('div', 'blogEditor__immersivePreviewActions');
        var save = element('button', '', 'Guardar borrador');
        save.type = 'button';
        save.disabled = context.readOnly;
        var publishForm = v2PublishForm(context);
        var publish = null;
        if (publishForm) {
            publish = element('button', '', 'Publicar');
            publish.type = 'button';
            actions.append(publish);
        }
        var close = element('button', '', 'Volver al editor');
        close.type = 'button';
        close.dataset.blogPreviewClose = 'true';
        actions.prepend(save);
        actions.append(close);
        toolbar.append(title, deviceGroup, status, actions);
        dialog.append(stage, toolbar);

        var host = context.form.closest('.webadmin')
            || document.querySelector('.webadmin')
            || document.body;
        host.append(dialog);
        var state = {
            context: context,
            dialog: dialog,
            stage: stage,
            frame: frame,
            loaded: false,
            url: trigger.href,
            trigger: trigger,
            status: status,
            save: save,
            publish: publish,
            close: close,
            deviceButtons: deviceButtons,
            publishForm: publishForm,
            pending: false,
            pendingPromise: null
        };
        frame.addEventListener('load', function () {
            v2PreviewDidLoad(context, state);
        });
        frame.addEventListener('error', function () {
            if (!state.pending) {
                return;
            }
            v2ResetPreviewFrame(state);
            v2PreviewFailure(
                context,
                state,
                'No se pudo cargar la vista previa. Reintenta el guardado o vuelve al editor.'
            );
        });
        deviceButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                v2SetPreviewDevice(
                    state,
                    button.dataset.blogPreviewDevice || 'desktop'
                );
            });
        });
        save.addEventListener('click', function () {
            v2RefreshPreview(context, state, true);
        });
        if (publish && publishForm) {
            publish.addEventListener('click', function () {
                if (state.pending) {
                    return;
                }
                v2ResetPreviewFrame(state);
                v2SetPreviewPending(context, state, true);
                v2SetPreviewStatus(
                    state,
                    'Preparando publicaci\u00f3n\u2026',
                    'pending'
                );
                v2PublishSaved(context, publishForm).then(
                    function (published) {
                        if (published) {
                            v2SetPreviewStatus(
                                state,
                                'Art\u00edculo publicado. Cargando vista previa SSR\u2026',
                                'pending'
                            );
                            v2LoadPreview(state);
                            return;
                        }
                        v2PreviewFailure(
                            context,
                            state,
                            'No se pudo publicar. El borrador se conserva.'
                        );
                    }
                );
            });
        }
        close.addEventListener('click', function () {
            v2ClosePreview(state);
        });
        dialog.addEventListener('cancel', function (event) {
            event.preventDefault();
            v2ClosePreview(state);
        });
        dialog.addEventListener('close', function () {
            state.trigger.focus();
        });
        return state;
    }

    function v2ShowPreview(state) {
        if (state.dialog.open) {
            return;
        }
        if (typeof state.dialog.showModal === 'function') {
            state.dialog.showModal();
        } else {
            state.dialog.setAttribute('open', '');
        }
    }

    function v2BindImmersivePreview(context) {
        var trigger = v2PreviewLink(context);
        if (!(trigger instanceof HTMLAnchorElement)) {
            return;
        }
        trigger.textContent = 'Vista previa';
        trigger.addEventListener('click', function (event) {
            if (
                event.button !== 0
                || event.ctrlKey
                || event.metaKey
                || event.shiftKey
                || event.altKey
            ) {
                return;
            }
            event.preventDefault();
            var state = context.immersivePreview
                || v2CreatePreview(context, trigger);
            context.immersivePreview = state;
            v2ShowPreview(state);
            v2RefreshPreview(context, state, false);
            window.requestAnimationFrame(function () {
                if (state.loaded) {
                    var active = state.deviceButtons.find(function (button) {
                        return button.getAttribute('aria-pressed') === 'true';
                    });
                    (active || state.deviceButtons[0]).focus();
                    return;
                }
                state.close.focus();
            });
        });
    }

    function v2InternalNavigationLink(event) {
        if (
            event.defaultPrevented
            || event.button !== 0
            || event.ctrlKey
            || event.metaKey
            || event.shiftKey
            || event.altKey
            || !(event.target instanceof Element)
        ) {
            return null;
        }
        var link = event.target.closest('a[href]');
        if (
            !(link instanceof HTMLAnchorElement)
            || link.hasAttribute('download')
            || link.hasAttribute('target')
            || link.hasAttribute('data-blog-editor-preview')
            || (link.getAttribute('rel') || '').split(/\s+/u)
                .includes('external')
        ) {
            return null;
        }

        try {
            var destination = new URL(link.href, window.location.href);
            var current = new URL(window.location.href);
            if (
                destination.origin !== current.origin
                || destination.pathname === current.pathname
                    && destination.search === current.search
            ) {
                return null;
            }
            return destination;
        } catch (error) {
            return null;
        }
    }

    function v2BindInternalNavigation(context) {
        var shell = context.form.closest('[data-webadmin-shell]');
        if (!(shell instanceof HTMLElement)) {
            return;
        }
        shell.addEventListener('click', function (event) {
            var destination = v2InternalNavigationLink(event);
            if (
                destination === null
                || context.readOnly
            ) {
                return;
            }
            var dirty = true;
            try {
                dirty = v2HasUnsavedChanges(context);
            } catch (error) {
                event.preventDefault();
                announce(
                    context,
                    'No se pudo comprobar el borrador. Sigues en el editor.',
                    true
                );
                return;
            }
            if (!dirty) {
                return;
            }

            event.preventDefault();
            if (context.navigationPending) {
                return;
            }
            var trigger = event.target.closest('a[href]');
            context.navigationPending = true;
            Promise.resolve().then(function () {
                return requestEditorNavigation(
                    context,
                    trigger,
                    typeof window.fetch === 'function'
                );
            }).then(function (choice) {
                if (choice === 'stay') {
                    context.navigationPending = false;
                    return;
                }
                if (choice === 'discard') {
                    return discardEditorialFacets(context).then(function (ready) {
                        if (!ready) {
                            context.allowNavigation = false;
                            context.navigationPending = false;
                            announce(
                                context,
                                'No se complet\u00f3 el guardado pendiente de categor\u00edas o etiquetas. Sigues en el editor para reintentarlo.',
                                true
                            );
                            return;
                        }
                        context.allowNavigation = true;
                        window.location.assign(destination.href);
                    });
                }
                if (
                    choice !== 'save'
                    || typeof window.fetch !== 'function'
                ) {
                    context.navigationPending = false;
                    return;
                }
                return v2PrepareSavedPreview(context).then(function (ready) {
                    if (!ready) {
                        context.navigationPending = false;
                        return;
                    }
                    context.allowNavigation = true;
                    window.location.assign(destination.href);
                });
            }).catch(function () {
                context.allowNavigation = false;
                context.navigationPending = false;
                announce(
                    context,
                    'No se pudo abrir el destino. El borrador sigue en el editor.',
                    true
                );
            });
        });
        bindEditorLogout(
            context,
            shell,
            function () { return v2HasUnsavedChanges(context); },
            function () { return v2PrepareSavedPreview(context); }
        );
    }

    function bindLegacyInternalNavigation(context) {
        var shell = context.form.closest('[data-webadmin-shell]');
        if (!(shell instanceof HTMLElement)) {
            return;
        }
        shell.addEventListener('click', function (event) {
            var destination = v2InternalNavigationLink(event);
            if (
                destination === null
                || context.readOnly
            ) {
                return;
            }
            var dirty = true;
            try {
                dirty = formFingerprint(context.form)
                    !== context.initialFingerprint
                    || editorialFacetsHaveChanges(context);
            } catch (error) {
                event.preventDefault();
                announce(
                    context,
                    'No se pudo comprobar el borrador. Sigues en el editor.',
                    true
                );
                return;
            }
            if (!dirty) {
                return;
            }

            event.preventDefault();
            if (context.navigationPending) {
                return;
            }
            var trigger = event.target.closest('a[href]');
            context.navigationPending = true;
            Promise.resolve().then(function () {
                return requestEditorNavigation(
                    context,
                    trigger,
                    false
                );
            }).then(function (choice) {
                if (choice !== 'discard') {
                    context.navigationPending = false;
                    return;
                }
                return discardEditorialFacets(context).then(function (ready) {
                    if (!ready) {
                        context.allowNavigation = false;
                        context.navigationPending = false;
                        announce(
                            context,
                            'No se complet\u00f3 el guardado pendiente de categor\u00edas o etiquetas. Sigues en el editor para reintentarlo.',
                            true
                        );
                        return;
                    }
                    context.allowNavigation = true;
                    window.location.assign(destination.href);
                });
            }).catch(function () {
                context.allowNavigation = false;
                context.navigationPending = false;
                announce(
                    context,
                    'No se pudo abrir el destino. El borrador sigue en el editor.',
                    true
                );
            });
        });
        bindEditorLogout(
            context,
            shell,
            function () {
                return formFingerprint(context.form)
                    !== context.initialFingerprint
                    || editorialFacetsHaveChanges(context);
            },
            null
        );
    }

    function initV2Editor(context, slugInput, entryIdentity, publicUrl) {
        context.selectedNodeId = null;
        context.inspectorMode = 'edit';
        context.keyboardDragNodeId = null;
        context.pointerDragNodeId = null;
        context.pointerDropTarget = null;
        context.pointerDragJustEnded = false;
        var saveButton = document.querySelector(
            '[data-blog-editor-save][form="' + context.form.id + '"]'
        ) || context.form.querySelector(
            '.blogEditor__save button[type="submit"]'
        );
        if (saveButton instanceof HTMLButtonElement) {
            saveButton.textContent = 'Guardar borrador';
        }
        var toolbar = context.form.querySelector('.blogEditor__blockToolbar');
        if (toolbar instanceof HTMLElement) {
            toolbar.hidden = true;
        }
        context.blockList.addEventListener('click', function (event) {
            if (v2HandleCanvasSelection(context, event)) {
                return;
            }
            v2HandleClick(context, event);
        });
        context.blockList.addEventListener('keydown', function (event) {
            v2HandleCanvasSelectionKeydown(context, event);
        });
        context.blockList.addEventListener('change', function (event) {
            v2HandleChange(context, event);
        });
        v2BindDragDrop(context);
        if (context.inspectorRoot) {
            context.inspectorRoot.addEventListener('click', function (event) {
                v2HandleClick(context, event);
            });
            context.inspectorRoot.addEventListener('change', function (event) {
                v2HandleChange(context, event);
            });
            context.inspectorRoot.addEventListener('input', function (event) {
                v2HandleInput(context, event);
            });
            context.inspectorRoot.addEventListener('keydown', function (event) {
                v2HandleLayoutPickerKeydown(event);
            });
        }
        v2BindInspectorTabs(context);
        v2BindTemplate(context);
        if (context.h1Input) {
            context.h1Input.addEventListener('input', function () {
                refreshV2Canvas(context);
            });
        }
        if (context.excerptInput) {
            context.excerptInput.addEventListener('input', function () {
                refreshV2Canvas(context);
            });
        }
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
                ? 'Se completar\u00e1 al guardar un slug.'
                : base + '/' + (slugInput.value || '\u2026');
        }
        if (slugInput instanceof HTMLInputElement) {
            slugInput.addEventListener('input', updatePublicUrl);
            updatePublicUrl();
        }
        initCategoryAssignment(context);
        initTagAssignment(context);
        v2BindSave(context);
        v2BindPublish(context);
        v2BindImmersivePreview(context);
        v2BindInternalNavigation(context);
        initSeoAnalysis(context);
        renderV2(context);
        context.initialFingerprint = editorialFormFingerprint(context.form);
        context.form.dataset.blogEditorEnhanced = 'true';
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
        var headerSettings = inspectorRoot
            ? inspectorRoot.querySelector('[data-blog-header-settings]')
            : document.querySelector('[data-blog-header-settings]');
        var headerControls = headerSettings
            ? headerSettings.querySelector('[data-blog-header-controls]')
            : null;
        var mediaCatalog = form.querySelector('[data-blog-media-catalog]');
        var status = document.querySelector(
            '[data-blog-editor-status][data-blog-editor-form="'
                + form.id + '"]'
        ) || form.querySelector('[data-blog-editor-status]');
        var publicationStatuses = Array.from(document.querySelectorAll(
            '[data-blog-editor-publication-status][data-blog-editor-form="'
                + form.id + '"]'
        )).filter(function (target) {
            return target instanceof HTMLElement;
        });
        var blockInspector = inspectorRoot
            ? inspectorRoot.querySelector('[data-blog-block-inspector]')
            : form.querySelector('[data-blog-block-inspector]');
        var h1Input = document.getElementById('blog-editor-h1');
        var excerptInput = document.getElementById('blog-editor-excerpt');
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

        var heroCatalog = readHeaderCatalog(
            form,
            'data-blog-hero-catalog',
            DEFAULT_HERO_CATALOG
        );
        var h1ModuleCatalog = readHeaderCatalog(
            form,
            'data-blog-h1-module-catalog',
            DEFAULT_H1_MODULE_CATALOG
        );
        HERO_KEYS = heroCatalog.map(function (item) { return item.key; });
        H1_MODULE_KEYS = h1ModuleCatalog.map(function (item) {
            return item.key;
        });
        var headingPresetCatalog = readHeadingPresetCatalog(form);
        HEADING_PRESETS = headingPresetCatalog.map(function (preset) {
            return preset.token;
        });
        var headingDefaults = readHeadingDefaults(
            form,
            headingPresetCatalog
        );
        var headingPolicy = readHeadingPolicy(form);
        HEADING_LEVELS = headingPolicy.allowed_levels.slice();
        var technicalLimits = readTechnicalLimits(form);
        if (technicalLimits === null) {
            disableSubmission(
                form,
                status,
                'No se pudo cargar el catálogo de límites técnicos.'
            );
            return;
        }
        MAX_JSON_BYTES = technicalLimits.document.document_json.bytes;
        MAX_INLINE_TEXT_BYTES = technicalLimits.block.content.bytes;
        MAX_EMBED_HTML_BYTES = technicalLimits.block.embed_html.bytes;
        MAX_CUSTOM_TEXT_HTML_BYTES = technicalLimits.block.html.bytes;
        MAX_CUSTOM_CSS_BYTES = technicalLimits.block.css.bytes;
        CUSTOM_TEXT_POLICY = technicalLimits.custom_text_policy;
        EMBED_POLICY = technicalLimits.embed_policy;
        IMAGE_PRESENTATION_POLICY = technicalLimits.image_presentation_policy;

        var documentValue;
        try {
            documentValue = JSON.parse(documentInput.value);
        } catch (error) {
            disableSubmission(form, status, 'El documento guardado no se puede editar.');
            return;
        }
        if (!validEditorDocument(documentValue)) {
            disableSubmission(form, status, 'El documento guardado no supera la validación.');
            return;
        }
        var headerSelection = readHeaderSelection(
            form,
            documentValue.template
        );
        if (headerSelection === null) {
            disableSubmission(
                form,
                status,
                'No se pudo cargar la configuración de la cabecera.'
            );
            return;
        }
        if (
            documentValue.version === VERSION
            && documentValue.header
            && JSON.stringify(documentValue.header)
                !== JSON.stringify(headerSelection)
        ) {
            disableSubmission(
                form,
                status,
                'La cabecera guardada no coincide con su configuración.'
            );
            return;
        }

        var layoutEditorReady = form.dataset.blogLayoutEditorReady === 'true';
        var advancedPreviewSecurity = richAdvancedPreviewSecurity(form);
        if (documentValue.version === VERSION && !layoutEditorReady) {
            disableSubmission(
                form,
                status,
                'El editor visual requiere completar la migración pendiente.'
            );
            return;
        }
        if (layoutEditorReady && advancedPreviewSecurity === null) {
            disableSubmission(
                form,
                status,
                'No se pudo preparar la vista previa segura del texto.'
            );
            return;
        }

        if (layoutEditorReady && documentValue.version === LEGACY_VERSION) {
            var convertedDocument = legacyDocumentToV2(documentValue);
            if (convertedDocument !== null) {
                documentValue = convertedDocument;
                documentInput.value = JSON.stringify(documentValue);
            }
        }
        var unifiedTextNormalized = documentValue.version === VERSION
            && normalizeUnifiedTextModules(documentValue);
        var presentationsNormalized = documentValue.version === VERSION
            && normalizeV2Presentations(documentValue);
        if (unifiedTextNormalized || presentationsNormalized) {
            documentInput.value = JSON.stringify(documentValue);
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
            publicationStatuses: publicationStatuses,
            mediaCatalog: mediaCatalog instanceof HTMLSelectElement
                ? mediaCatalog : null,
            media: readMedia(mediaCatalog),
            mediaDialog: null,
            mediaDialogSelect: null,
            mediaApply: null,
            mediaUploadPending: false,
            mediaUploadAbort: null,
            documentValue: documentValue,
            heroCatalog: heroCatalog,
            h1ModuleCatalog: h1ModuleCatalog,
            headerSelection: Object.assign({}, headerSelection),
            headerSettings: headerSettings instanceof HTMLElement
                ? headerSettings
                : null,
            headerControls: headerControls instanceof HTMLElement
                ? headerControls
                : null,
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
            excerptInput: excerptInput instanceof HTMLTextAreaElement
                ? excerptInput
                : null,
            locale: localeInput.value,
            initialFingerprint: '',
            allowNavigation: false,
            navigationPending: false,
            savePending: false,
            savePromise: null,
            saveHasPendingChanges: false,
            previewPending: false,
            advancedPreviewSecurity: advancedPreviewSecurity,
            publishPending: false,
            publishPromise: null,
            invalidFocusPending: false,
            editorialFacets: [],
            layoutEditorReady: layoutEditorReady,
            headingPresetCatalog: headingPresetCatalog,
            headingDefaults: headingDefaults,
            headingPolicy: headingPolicy,
            technicalLimits: technicalLimits,
            entryLimitControls: []
        };

        bindEntryLimitFeedback(context);
        initMediaDialog(context);

        if (
            context.layoutEditorReady
            && context.documentValue.version === VERSION
        ) {
            initV2Editor(context, slugInput, entryIdentity, publicUrl);
            return;
        }

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
        if (context.excerptInput) {
            context.excerptInput.addEventListener('input', function () {
                refreshVisualCanvas(context);
            });
        }
        if (slugInput instanceof HTMLInputElement) {
            slugInput.addEventListener('input', updatePublicUrl);
            updatePublicUrl();
        }

        form.querySelectorAll('[data-blog-add-block]').forEach(function (button) {
            var type = button.getAttribute('data-blog-add-block') || '';
            if (
                !LEGACY_BLOCK_TYPES.includes(type)
                || ['list', 'link'].includes(type)
            ) {
                button.disabled = true;
                if (['list', 'link'].includes(type)) {
                    button.hidden = true;
                }
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
                        if (!validEditorDocument(candidate)) {
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
            if (!TEMPLATES.includes(requested)) {
                templateSelect.value = context.documentValue.template;
                return;
            }
            var previous = context.documentValue.template;
            if (requested === previous) {
                return;
            }
            if (!templateHasCover(requested) && templateHasCover(previous)) {
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
                templateHasCover(requested)
                && !templateHasCover(previous)
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
            if (typeof window.fetch !== 'function') {
                context.allowNavigation = true;
                return;
            }
            event.preventDefault();
            context.allowNavigation = false;
            var withinSize = false;
            try {
                normalizeContracts(context, true);
                withinSize = sync(context);
            } catch (error) {
                announce(
                    context,
                    'No se pudo preparar el guardado. Tus cambios siguen en el editor.',
                    true
                );
                return;
            }
            if (
                context.readOnly
                || !withinSize
                || !validDocument(context.documentValue)
            ) {
                announce(
                    context,
                    'El documento contiene campos incompletos o no válidos.',
                    true
                );
                return;
            }
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
                body: editorFormBody(form).toString(),
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
        initCategoryAssignment(context);
        initTagAssignment(context);
        bindLegacyInternalNavigation(context);
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
