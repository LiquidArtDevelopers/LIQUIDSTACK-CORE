import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const asset = process.argv[2];
let source = fs.readFileSync(asset, 'utf8');
const marker = '}());';
const markerIndex = source.lastIndexOf(marker);
if (markerIndex < 0) {
  throw new Error('Unable to expose custom text hooks.');
}
source = source.slice(0, markerIndex)
  + `globalThis.__customTextHooks = {
    RICH_ADVANCED_HTML_TAGS,
    RICH_ADVANCED_ROLES,
    richSourceDefinitions,
    richHtmlSourceTokenRanges,
    richHighlightHtmlSource,
    richHighlightCssSource,
    richValidateAdvancedCss,
    richAdvancedDraftFromBlock,
    richAdvancedPreviewPolicy,
    richAdvancedPreviewDocument,
    richRenderAdvancedDraft,
    richCloseModal,
    richApplyModal,
    richCommitAdvancedEditors,
    richReportAdvancedValidationFailure,
    richRefreshAdvancedValidationStatus,
    richStructuredFingerprint,
    validAdvancedHtml,
    validAdvancedCss,
    validV2Module,
    useCustomTextPolicy(value) {
      CUSTOM_TEXT_POLICY = richNormalizeCustomTextPolicy(value);
      return CUSTOM_TEXT_POLICY !== null;
    }
  };\n`
  + source.slice(markerIndex);

function fakeNode(tag = 'div') {
  return {
    tagName: tag.toUpperCase(),
    className: '',
    textContent: '',
    value: '',
    selectionStart: 0,
    scrollTop: 0,
    scrollLeft: 0,
    hidden: false,
    open: false,
    closed: false,
    dataset: {},
    attributes: {},
    children: [],
    setAttribute(name, value) {
      this.attributes[name] = String(value);
    },
    removeAttribute(name) {
      delete this.attributes[name];
    },
    replaceChildren(...children) {
      this.children = children;
    },
    close() {
      this.open = false;
      this.closed = true;
    },
  };
}

globalThis.document = {
  readyState: 'loading',
  activeElement: null,
  addEventListener() {},
  createElement(tag) {
    return fakeNode(tag);
  },
};
globalThis.window = {};
vm.runInThisContext(source, { filename: asset });

const hooks = globalThis.__customTextHooks;
const previewStyleNonce = 'abcdefghijklmnopQRSTUVWX';
const previewSecurity = {
  styleNonce: previewStyleNonce,
  contentSecurityPolicy: hooks.richAdvancedPreviewPolicy(previewStyleNonce),
};
const customTextPolicy = {
  html: {
    tags: [
      'a', 'blockquote', 'br', 'code', 'div', 'em', 'li', 'mark',
      'ol', 'p', 'small', 'span', 'strong', 'sub', 'sup', 'u', 'ul',
    ],
    global_attributes: [
      'class', 'id', 'title', 'lang', 'dir', 'role', 'aria-label',
      'aria-hidden', 'aria-labelledby', 'aria-describedby',
    ],
    data_attribute_prefix: 'data-content-',
    roles: [
      'group', 'list', 'listitem', 'none', 'note', 'presentation', 'region',
    ],
    special_attributes: {
      a: ['href', 'target', 'rel'],
      blockquote: ['cite'],
      ol: ['start', 'reversed', 'type'],
      li: ['value'],
    },
    max_nodes: 1024,
    max_depth: 32,
  },
  css: {
    properties: [
      'color', 'content', 'display', 'font-weight', 'padding',
      'padding-inline', 'position', 'text-decoration',
    ],
    root_properties: [
      'color', 'font-weight', 'padding', 'padding-inline', 'text-decoration',
    ],
    tags: [
      'a', 'blockquote', 'br', 'code', 'div', 'em', 'li', 'mark',
      'ol', 'p', 'small', 'span', 'strong', 'sub', 'sup', 'u', 'ul',
    ],
    at_rules: ['media', 'supports'],
    simple_pseudos: ['focus', 'hover'],
    pseudo_elements: ['after', 'before'],
    attribute_selectors: [
      'aria-hidden', 'dir', 'lang', 'data-content-*',
    ],
    reserved_class_prefixes: [
      'blogdocument', 'blog-', 'liquidstack', 'ls-', 'webadmin',
    ],
    max_css_bytes: 30000,
    max_rendered_css_bytes: 600000,
    max_depth: 6,
    max_rules: 128,
    max_declarations: 256,
    max_selector_bytes: 512,
    max_value_bytes: 2048,
    max_numeric_tokens_per_value: 64,
    max_root_numeric_total: 4096,
  },
};
assert.equal(hooks.useCustomTextPolicy(customTextPolicy), true);
const tags = (definitions) => definitions.map((definition) => definition.tag);
const advancedRoot = tags(hooks.richSourceDefinitions(
  true,
  true,
  '',
  0,
  [],
  true,
));
const advancedInline = tags(hooks.richSourceDefinitions(
  true,
  true,
  '<div><p>',
  8,
  [],
  true,
));
const standardRoot = tags(hooks.richSourceDefinitions(true, true, '', 0));

assert.deepEqual(hooks.RICH_ADVANCED_HTML_TAGS, [
  'div', 'p', 'ul', 'ol', 'li', 'span', 'strong', 'em', 'u', 'a',
  'br', 'small', 'mark', 'sup', 'sub', 'code', 'blockquote',
]);
assert.deepEqual(hooks.RICH_ADVANCED_ROLES, [
  'group', 'list', 'listitem', 'none', 'note', 'presentation', 'region',
]);
assert.deepEqual(standardRoot, ['p', 'ul', 'ol']);
assert.deepEqual(advancedRoot, ['p', 'ul', 'ol', 'div', 'blockquote']);
assert.ok(advancedInline.includes('strong'));
assert.ok(advancedInline.includes('small'));
assert.ok(!advancedInline.some((tag) => /^h[1-6]$/.test(tag)));

const htmlHighlight = hooks.richHighlightHtmlSource(
  '<div class="lead"><!-- note --><strong>Neo</strong></div>',
);
assert.match(htmlHighlight, /blogEditor__syntaxTag/);
assert.match(htmlHighlight, /blogEditor__syntaxAttribute/);
assert.match(htmlHighlight, /blogEditor__syntaxString/);
assert.match(htmlHighlight, /blogEditor__syntaxComment/);
assert.ok(!htmlHighlight.includes('<script>'));
const quotedAttributeHtml = '<div title="1 > 0" data-note=\'a>b\'><span>ok</span></div>';
const quotedAttributeRanges = hooks.richHtmlSourceTokenRanges(
  quotedAttributeHtml,
);
assert.equal(
  quotedAttributeHtml.slice(
    quotedAttributeRanges[0].start,
    quotedAttributeRanges[0].end,
  ),
  '<div title="1 > 0" data-note=\'a>b\'>',
);
assert.equal(quotedAttributeRanges.length, 4);
const quotedAttributeHighlight = hooks.richHighlightHtmlSource(
  quotedAttributeHtml,
);
assert.match(
  quotedAttributeHighlight,
  /syntaxString[^>]*>&quot;1 &gt; 0&quot;<\/span>/,
);
assert.match(
  quotedAttributeHighlight,
  /syntaxString[^>]*>&#039;a&gt;b&#039;<\/span>/,
);
assert.equal(
  (quotedAttributeHighlight.match(/blogEditor__syntaxTag/g) || []).length,
  4,
);

const css = `
color: #272727;
.lead {
  font-weight: 700;
  & strong { text-decoration: underline; }
}
@media (min-width: 60rem) { padding-inline: 2rem; }
`;
assert.equal(hooks.richValidateAdvancedCss(css), css);
assert.equal(hooks.validAdvancedCss(css), true);
assert.match(hooks.richHighlightCssSource(css), /blogEditor__syntaxProperty/);
assert.match(hooks.richHighlightCssSource(css), /blogEditor__syntaxSelector/);
assert.match(hooks.richHighlightCssSource(css), /blogEditor__syntaxAtRule/);
assert.equal(hooks.validAdvancedCss('background:url(https://example.test/x)'), false);
assert.equal(hooks.validAdvancedCss('@import "https://example.test/x";'), false);
assert.equal(hooks.validAdvancedCss('color: r\\65 d;'), false);
assert.equal(hooks.validAdvancedCss('position: fixed;'), false);
assert.equal(hooks.validAdvancedCss('position: relative;'), false);
assert.equal(hooks.validAdvancedCss('.lead { position: relative; }'), true);
const independentClassCss = `.prueba{
    color: red;
}`;
assert.equal(independentClassCss.length, 26);
assert.equal(hooks.validAdvancedCss(independentClassCss), true);
assert.equal(hooks.validAdvancedCss('#prueba { color: red; }'), true);
assert.equal(hooks.validAdvancedCss('p { color: red; }'), true);
assert.equal(hooks.validAdvancedCss('z-index: 999999;'), false);
assert.equal(hooks.validAdvancedCss('display: contents;'), false);
assert.equal(hooks.validAdvancedCss('all: revert;'), false);
assert.equal(hooks.validAdvancedCss(':root { color: red; }'), false);
assert.equal(hooks.validAdvancedCss('html body { color: red; }'), false);
assert.equal(hooks.validAdvancedCss('& + * { color: red; }'), false);
assert.equal(hooks.validAdvancedCss('& ~ * { color: red; }'), false);
assert.equal(hooks.validAdvancedCss('color: image("https://example.test/x");'), false);
assert.equal(hooks.validAdvancedCss('.lead { color: red;'), false);
assert.equal(hooks.validAdvancedCss('padding: calc(100% - 1rem);'), false);
assert.equal(hooks.validAdvancedCss('padding: min(1rem, 2rem);'), false);
assert.equal(hooks.validAdvancedCss('padding: max(1rem, 2rem);'), false);
assert.equal(hooks.validAdvancedCss('padding: clamp(1rem, 2vw, 3rem);'), false);
assert.equal(hooks.validAdvancedCss('padding: repeat(2, 1rem);'), false);
assert.equal(hooks.validAdvancedCss('padding: 99999e999px;'), false);
assert.equal(hooks.validAdvancedCss('padding: 4096px;'), true);
assert.equal(hooks.validAdvancedCss('padding: 4097px;'), false);
assert.equal(hooks.validAdvancedCss('.lead { padding: 100000px; }'), true);
assert.equal(hooks.validAdvancedCss('.lead { padding: 100001px; }'), false);
assert.equal(hooks.validAdvancedCss(
  `padding: ${Array.from({ length: 65 }, () => '1px').join(' ')};`,
), false);
assert.equal(hooks.validAdvancedHtml('<script>alert(1)</script>'), false);

const advancedParagraph = {
  id: '70000000-0000-4000-8000-000000000001',
  type: 'paragraph',
  html: '<div class="lead">Neo</div>',
  css: '.lead { font-weight: 700; }',
  presentation: {
    width: 'full',
    align: 'start',
    text_align: 'start',
    size: 'm',
    font_weight: 'default',
    text_color: 'default',
  },
};
assert.equal(
  hooks.validV2Module(advancedParagraph, new Set(), false, true),
  true,
);
assert.equal(
  hooks.validV2Module({
    ...advancedParagraph,
    content: [{ type: 'text', text: 'duplicated source', marks: [] }],
  }, new Set(), false, true),
  false,
);

const incompleteAdvancedParagraph = {
  ...advancedParagraph,
  html: '',
  css: 'color: #272727;',
};
const openedDraft = hooks.richAdvancedDraftFromBlock(
  incompleteAdvancedParagraph,
);
assert.deepEqual(openedDraft, {
  advanced: true,
  html: '',
  css: 'color: #272727;',
});
assert.equal(
  hooks.validV2Module(
    incompleteAdvancedParagraph,
    new Set(),
    false,
    true,
  ),
  true,
);
assert.equal(
  hooks.validV2Module(
    incompleteAdvancedParagraph,
    new Set(),
    false,
    false,
  ),
  false,
);

const preview = hooks.richAdvancedPreviewDocument(
  incompleteAdvancedParagraph.id,
  openedDraft.html,
  openedDraft.css,
  previewSecurity,
);
assert.match(preview, /<body><div data-ls-blog-custom=/);
assert.match(preview, /color: #272727;/);
assert.match(preview, /></);
assert.match(preview, new RegExp(`<style nonce="${previewStyleNonce}">`));
assert.doesNotMatch(preview, /unsafe-inline/);
assert.match(
  preview,
  new RegExp(`nonce-${previewStyleNonce}`),
);

function incompleteModalState() {
  const sourceControl = fakeNode('textarea');
  sourceControl.value = '';
  const cssControl = fakeNode('textarea');
  cssControl.value = incompleteAdvancedParagraph.css;
  const dialog = fakeNode('dialog');
  dialog.open = true;
  const state = {
    block: incompleteAdvancedParagraph,
    advancedMode: true,
    wasAdvanced: true,
    advancedHtmlDraft: '',
    cssDraft: incompleteAdvancedParagraph.css,
    sourceTouched: false,
    cssTouched: false,
    allowBreak: true,
    listMode: false,
    legacyListMode: false,
    textFlowMode: true,
    headingMode: false,
    flowDraft: [],
    textAlign: 'start',
    mode: 'visual',
    visual: fakeNode('div'),
    toolbar: fakeNode('div'),
    linkPanel: fakeNode('div'),
    advancedPreviewSlot: fakeNode('section'),
    source: sourceControl,
    cssSource: cssControl,
    richFeedback: fakeNode('p'),
    modalStatus: fakeNode('p'),
    dialog,
    sourceSuggestions: fakeNode('div'),
    sourceSuggestionList: fakeNode('div'),
    sourceSuggestionResult: { start: 0, query: '', items: [] },
    sourceSuggestionIndex: -1,
    sourcePosition: fakeNode('p'),
    context: {
      advancedPreviewSecurity: previewSecurity,
      announcementVersion: 0,
      status: fakeNode('p'),
      documentValue: {
        schema: 'liquidstack.blog.document',
        version: 2,
        template: 'article-basic-01',
        blocks: [{
          id: '70000000-0000-4000-8000-000000000002',
          type: 'section',
          children: [incompleteAdvancedParagraph],
        }],
      },
      technicalLimits: {
        block: {
          content: { bytes: 20000 },
          html: { bytes: 50000 },
          css: { bytes: 30000 },
        },
      },
    },
  };
  state.baseline = hooks.richStructuredFingerprint(state);
  state.pristineBaseline = state.baseline;
  return state;
}

const renderState = incompleteModalState();
hooks.richRenderAdvancedDraft(renderState);
assert.equal(renderState.visual.children.length, 1);
assert.equal(renderState.visual.children[0].tagName, 'IFRAME');
assert.match(renderState.visual.children[0].srcdoc, /color: #272727;/);
assert.equal(renderState.visual.dataset.empty, 'true');

const cancelState = incompleteModalState();
hooks.richCloseModal(cancelState);
assert.equal(cancelState.dialog.closed, true);
assert.deepEqual(incompleteAdvancedParagraph, {
  ...advancedParagraph,
  html: '',
  css: 'color: #272727;',
});

const applyState = incompleteModalState();
hooks.richApplyModal(applyState);
assert.equal(applyState.dialog.closed, true);
assert.equal(applyState.modalStatus.dataset.state, undefined);

const commitState = incompleteModalState();
commitState.mode = 'css';
commitState.cssSource.value = independentClassCss;
hooks.richCommitAdvancedEditors(commitState);
assert.equal(commitState.advancedMode, true);
assert.equal(commitState.advancedHtmlDraft, '');
assert.equal(commitState.cssDraft, independentClassCss);

const independentSelectorState = incompleteModalState();
independentSelectorState.wasAdvanced = false;
independentSelectorState.advancedMode = false;
independentSelectorState.advancedHtmlDraft = '';
independentSelectorState.source.value = '<p>Texto sin class prueba</p>';
independentSelectorState.cssSource.value = independentClassCss;
independentSelectorState.sourceTouched = false;
independentSelectorState.cssTouched = true;
hooks.richCommitAdvancedEditors(independentSelectorState);
assert.equal(independentSelectorState.advancedMode, true);
assert.equal(
  independentSelectorState.advancedHtmlDraft,
  '<p>Texto sin class prueba</p>',
);
assert.equal(independentSelectorState.cssDraft, independentClassCss);

const staleCssErrorState = incompleteModalState();
staleCssErrorState.cssSource.value = independentClassCss;
staleCssErrorState.modalStatus.textContent =
  'El CSS contiene sintaxis o valores no permitidos.';
staleCssErrorState.modalStatus.dataset.state = 'error';
staleCssErrorState.modalStatus.dataset.validationSource = 'css';
hooks.richRefreshAdvancedValidationStatus(staleCssErrorState, 'css');
assert.equal(staleCssErrorState.modalStatus.textContent, '');
assert.equal(staleCssErrorState.modalStatus.dataset.state, 'ok');
assert.equal(staleCssErrorState.modalStatus.dataset.validationSource, undefined);

const incompleteCssErrorState = incompleteModalState();
incompleteCssErrorState.cssSource.value = '.prueba{';
incompleteCssErrorState.modalStatus.textContent =
  'El CSS contiene sintaxis o valores no permitidos.';
incompleteCssErrorState.modalStatus.dataset.state = 'error';
incompleteCssErrorState.modalStatus.dataset.validationSource = 'css';
hooks.richRefreshAdvancedValidationStatus(incompleteCssErrorState, 'css');
assert.equal(incompleteCssErrorState.modalStatus.dataset.state, 'error');
assert.equal(incompleteCssErrorState.modalStatus.dataset.validationSource, 'css');

const mislabeledHtmlState = incompleteModalState();
hooks.richReportAdvancedValidationFailure(
  mislabeledHtmlState,
  new Error('rich-advanced-html-not-allowed'),
  'css',
);
assert.match(mislabeledHtmlState.modalStatus.textContent, /^El HTML /);
assert.equal(mislabeledHtmlState.modalStatus.dataset.validationSource, 'html');

process.stdout.write(JSON.stringify({
  advancedRoot,
  advancedInline,
  standardRoot,
  highlighting: true,
  cssNesting: true,
  independentClassCss: true,
  staleCssErrorClears: true,
  unsafeRejected: true,
  advancedShape: true,
  incompleteAdvancedLifecycle: true,
}));
