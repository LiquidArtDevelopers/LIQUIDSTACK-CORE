import fs from 'node:fs';
import assert from 'node:assert/strict';
import { createHash, webcrypto } from 'node:crypto';
import vm from 'node:vm';

const asset = process.argv[2];
let source = fs.readFileSync(asset, 'utf8');
const marker = '}());';
const markerIndex = source.lastIndexOf(marker);
if (markerIndex < 0) {
  throw new Error('Unable to expose rich text hooks.');
}
source = source.slice(0, markerIndex)
  + `globalThis.__richTextHooks = {
    INSERTABLE_BLOCK_TYPES,
    richSourceDefinitions,
    richParseTextFlowRoot,
    richSerializeTextFlowHtml,
    richParseHeadingRoot,
    richSerializeHeadingHtml,
    richLegacyListFlow,
    richFlowCanStayLegacyList,
    richConvertFlowBlocks,
    richFlowListStyleDraft,
    richConsumePendingFlowListIndexes,
    richStructuredFingerprint,
    richPristineFingerprint,
    richLegacyListEditMode,
    richApplyModal,
    richAllowedHeadingLevels,
    v2SectionHeadingModule,
    validV2Document,
    validV2DraftDocument,
    v2ProtectedHeading,
    v2CanMove,
    v2CanDrag,
    v2CanDuplicate,
    v2CanDelete,
    richSelectionBreaksLeadingHeading,
    richDraftKeepsLeadingHeading,
    normalizeUnifiedTextModules,
    normalizeV2Presentations,
    v2ApplyHeadingLevelStyle,
    v2CloneWithIds,
    richSetFlowMetadataValue,
    v2DocumentSha256
  };\n`
  + source.slice(markerIndex);

globalThis.Node = {
  ELEMENT_NODE: 1,
  TEXT_NODE: 3,
  DOCUMENT_FRAGMENT_NODE: 11,
};
class FakeHTMLElement {
  static [Symbol.hasInstance](value) {
    return Boolean(value && value.nodeType === Node.ELEMENT_NODE);
  }
}
globalThis.Element = FakeHTMLElement;
globalThis.HTMLElement = FakeHTMLElement;
globalThis.document = { readyState: 'loading', addEventListener() {} };
globalThis.window = {};
vm.runInThisContext(source, { filename: asset });

function text(value) {
  return { nodeType: Node.TEXT_NODE, nodeValue: value };
}

function element(tag, attributes = {}, children = []) {
  const values = { ...attributes };
  const attributeList = Object.keys(values).map((name) => ({ name }));
  return {
    nodeType: Node.ELEMENT_NODE,
    nodeName: tag.toUpperCase(),
    attributes: attributeList,
    childNodes: children,
    dataset: {},
    getAttribute(name) {
      return Object.prototype.hasOwnProperty.call(values, name)
        ? values[name]
        : null;
    },
    hasAttribute(name) {
      return Object.prototype.hasOwnProperty.call(values, name);
    },
    setAttribute(name, value) {
      if (!Object.prototype.hasOwnProperty.call(values, name)) {
        attributeList.push({ name });
      }
      values[name] = String(value);
    },
  };
}

function errorCode(callback) {
  try {
    callback();
    return null;
  } catch (error) {
    return error instanceof Error ? error.message : String(error);
  }
}

const hooks = globalThis.__richTextHooks;
const flowRoot = element('body', {}, [
  element('p', {}, [
    element('strong', {}, [text('Wake up')]),
    text(', '),
    element('a', { href: '/neo', title: 'Neo', target: '_blank' }, [
      element('em', {}, [text('Neo')]),
    ]),
  ]),
  element('ol', {}, [
    element('li', {}, [
      element('span', { 'data-ls-text-color': 'color02' }, [
        text('Follow the white rabbit'),
      ]),
    ]),
  ]),
]);
const flow = hooks.richParseTextFlowRoot(flowRoot, true);
const serializedFlow = hooks.richSerializeTextFlowHtml(flow);
const semanticFlowRoot = element('body', {}, [
  element('h3', {}, [text('Encabezado')]),
  element('blockquote', {}, [text('Cita')]),
  element('aside', {
    'data-content-callout': 'true',
    role: 'note',
  }, [text('Destacado')]),
]);
const semanticFlow = hooks.richParseTextFlowRoot(semanticFlowRoot, true);
const semanticFlowHtml = hooks.richSerializeTextFlowHtml(semanticFlow);

const combinedRoot = element('body', {}, [
  element('p', {}, [
    element('a', { href: '/trinity', title: 'Trinity', target: '_blank' }, [
      element('span', {
        'data-ls-size': 'xlarge',
        'data-ls-background-color': 'color03',
        'data-ls-text-rgba': 'rgba(12, 34, 56, 0.5)',
      }, [
        element('strong', {}, [
          element('em', {}, [element('u', {}, [text('Trinity')])]),
        ]),
      ]),
    ]),
  ]),
]);
const combinedFlow = hooks.richParseTextFlowRoot(combinedRoot, true);
const combinedSerialized = hooks.richSerializeTextFlowHtml(combinedFlow);
const combinedRoundTripRoot = element('body', {}, [
  element('p', {}, [
    element('a', { href: '/trinity', title: 'Trinity', target: '_blank' }, [
      element('span', {
        'data-ls-size': 'xlarge',
        'data-ls-background-color': 'color03',
        'data-ls-text-rgba': 'rgba(12, 34, 56, 0.5)',
      }, [
        element('strong', {}, [
          element('em', {}, [element('u', {}, [text('Trinity')])]),
        ]),
      ]),
    ]),
  ]),
]);
const combinedRoundTrip = hooks.richParseTextFlowRoot(
  combinedRoundTripRoot,
  true
);

const paragraphs = [
  { type: 'paragraph', content: flow[0].content },
  {
    type: 'paragraph',
    content: [{ type: 'text', text: 'Second', marks: ['underline'] }],
  },
];
const bullets = hooks.richConvertFlowBlocks(paragraphs, [0, 1], false);
const unwrapped = hooks.richConvertFlowBlocks(bullets, [0], false);
const styledItemId = '50000000-0000-4000-8000-000000000099';
const styledSource = [{
  type: 'list',
  ordered: false,
  marker: 'square',
  items: [{
    id: styledItemId,
    content: [{ type: 'text', text: 'Styled', marks: ['strong'] }],
  }],
}];
const styledSourceBefore = JSON.stringify(styledSource);
const markerRestyled = hooks.richFlowListStyleDraft(
  styledSource, [0], false, 'circle', false
);
const typeRestyled = hooks.richFlowListStyleDraft(
  styledSource, [0], true, 'upper-alpha', false
);
const listRemoved = hooks.richFlowListStyleDraft(
  styledSource, [0], false, 'remove', true
);
const paragraphsNumbered = hooks.richFlowListStyleDraft(
  paragraphs, [0, 1], true, 'lower-alpha', false
);
const invalidListStyle = hooks.richFlowListStyleDraft(
  styledSource, [0], true, 'disc', false
);
const pendingListSelection = {
  flowDraft: [{}, {}, {}],
  pendingFlowListIndexes: [2, 0],
};
assert.deepEqual([
  hooks.richConsumePendingFlowListIndexes(pendingListSelection),
  pendingListSelection.pendingFlowListIndexes,
  hooks.richConsumePendingFlowListIndexes(pendingListSelection),
], [[0, 2], null, []]);

const legacy = {
  id: '50000000-0000-4000-8000-000000000003',
  type: 'list',
  ordered: false,
  marker: 'disc',
  items: [
    {
      id: '50000000-0000-4000-8000-000000000004',
      content: flow[0].content,
    },
  ],
  presentation: {
    width: 'full',
    align: 'start',
    text_align: 'start',
    size: 'm',
    text_color: 'default',
  },
};
const legacyBefore = JSON.stringify(legacy);
const legacyState = {
  legacyListMode: true,
  listMode: false,
  textFlowMode: true,
  headingMode: false,
  flowDraft: hooks.richLegacyListFlow(legacy),
  textAlign: 'start',
};
legacyState.baseline = hooks.richStructuredFingerprint(legacyState);
legacyState.pristineBaseline = hooks.richPristineFingerprint(legacyState);
const legacyNoop = hooks.richLegacyListEditMode(legacyState);
const legacyNoopHashPreserved = legacyBefore === JSON.stringify(legacy);
legacyState.flowDraft[0].items[0].content[0].text = 'Changed';
const legacyListEdit = hooks.richLegacyListEditMode(legacyState);
legacyState.flowDraft.push({
  type: 'paragraph',
  content: [{ type: 'text', text: 'Tail', marks: [] }],
});
const legacyFlowEdit = hooks.richLegacyListEditMode(legacyState);

const segmentedLegacy = {
  id: '50000000-0000-4000-8000-000000000013',
  type: 'list',
  ordered: false,
  marker: 'disc',
  items: [{
    id: '50000000-0000-4000-8000-000000000014',
    content: [
      { type: 'text', text: 'A', marks: [] },
      { type: 'text', text: 'B', marks: [] },
    ],
  }],
  presentation: {
    width: 'full',
    align: 'start',
    text_align: 'start',
    size: 'm',
    text_color: 'default',
  },
};
const segmentedDocument = {
  schema: 'liquidstack.blog.document',
  version: 2,
  template: 'article-basic-01',
  blocks: [{
    id: '50000000-0000-4000-8000-000000000012',
    type: 'section',
    children: [segmentedLegacy],
  }],
};
const segmentedBefore = JSON.stringify(segmentedDocument);
const segmentedVisual = element('div', {}, [
  element('ul', {}, [element('li', {}, [text('A'), text('B')])]),
]);
const segmentedState = {
  context: {
    documentValue: segmentedDocument,
    technicalLimits: {
      block: {
        content: { bytes: 4096 },
        text_content: { bytes: 200000 },
        html: { bytes: 200000 },
        css: { bytes: 30000 },
      },
    },
    announcementVersion: 0,
    status: { textContent: '', dataset: {} },
  },
  block: segmentedLegacy,
  legacyListMode: true,
  listMode: false,
  textFlowMode: true,
  headingMode: false,
  flowDraft: hooks.richLegacyListFlow(segmentedLegacy),
  textAlign: 'start',
  mode: 'visual',
  visual: segmentedVisual,
  source: { setAttribute() {} },
  cssSource: { setAttribute() {} },
  richFeedback: { textContent: '', dataset: {} },
  modalStatus: { textContent: '', dataset: {} },
  dialog: { closed: false, close() { this.closed = true; } },
  inputTouched: false,
};
segmentedState.baseline = hooks.richStructuredFingerprint(segmentedState);
segmentedState.pristineBaseline = hooks.richPristineFingerprint(segmentedState);
hooks.richApplyModal(segmentedState);
const segmentedAfter = JSON.stringify(segmentedDocument);

const headingRoot = element('body', {}, [
  element('h3', {}, [
    element('strong', {}, [text('Structured heading')]),
  ]),
]);
const heading = hooks.richParseHeadingRoot(headingRoot, [3, 4, 5, 6]);
const headingHtml = hooks.richSerializeHeadingHtml(
  heading.level,
  heading.content
);
const headingRootSuggestions = hooks.richSourceDefinitions(
  false,
  false,
  '',
  0,
  [3, 4]
).map((definition) => definition.tag);
const headingInlineSuggestions = hooks.richSourceDefinitions(
  false,
  false,
  '<h3>',
  4,
  [3, 4]
).map((definition) => definition.tag);

const headingPresentation = {
  width: 'full',
  align: 'start',
  text_align: 'start',
  size: 'm',
  font_weight: 'default',
  text_color: 'default',
};
const structuralH2 = {
  id: '60000000-0000-4000-8000-000000000002',
  type: 'heading',
  level: 2,
  content: [{ type: 'text', text: 'Section', marks: [] }],
  presentation: headingPresentation,
};
const structuralH3 = {
  id: '60000000-0000-4000-8000-000000000005',
  type: 'heading',
  level: 3,
  content: [{ type: 'text', text: 'Article', marks: [] }],
  presentation: headingPresentation,
};
const semanticDocument = {
  schema: 'liquidstack.blog.document',
  version: 2,
  template: 'article-basic-01',
  blocks: [{
    id: '60000000-0000-4000-8000-000000000001',
    type: 'section',
    children: [structuralH2, {
      id: '60000000-0000-4000-8000-000000000003',
      type: 'article',
      layout: {
        preset: '1',
        columns: [{
          id: '60000000-0000-4000-8000-000000000004',
          children: [structuralH3],
        }],
      },
    }],
  }],
};
const semanticContext = { documentValue: semanticDocument };
const unifiedHeading = {
  id: '70000000-0000-4000-8000-000000000002',
  type: 'paragraph',
  content: [{
    type: 'heading',
    level: 2,
    content: [{ type: 'text', text: 'Nueva sección', marks: [] }],
  }],
  presentation: {
    width: '60', align: 'center', text_align: 'start', size: 'm',
    spacing_before: 'none', spacing_after: 'none',
    font_weight: 'default', text_color: 'default',
  },
};
const unifiedDocument = {
  schema: 'liquidstack.blog.document',
  version: 2,
  template: 'article-basic-01',
  blocks: [{
    id: '70000000-0000-4000-8000-000000000001',
    type: 'section',
    children: [unifiedHeading],
  }],
};
const unifiedLocation = {
  ownerType: 'section', index: 0, node: unifiedHeading,
  siblings: unifiedDocument.blocks[0].children, topLevel: false,
};
const retaggedUnifiedHeading = JSON.parse(JSON.stringify(unifiedHeading));
retaggedUnifiedHeading.content[0].level = 5;

const inlineContent = (value) => [{
  type: 'text', text: value, marks: [],
}];
const typography = () => ({
  width: 'full', align: 'start', text_align: 'start', size: 'm',
  spacing_before: 'none', spacing_after: 'none',
  font_weight: 'default', text_color: 'default',
});
const genericPresentation = () => ({
  width: 'full', align: 'start', text_align: 'start', size: 'm',
  spacing_before: 'none', spacing_after: 'none',
});
const legacyTextDocument = {
  schema: 'liquidstack.blog.document',
  version: 2,
  template: 'article-basic-01',
  blocks: [{
    id: '80000000-0000-4000-8000-000000000001',
    type: 'section',
    children: [{
      id: '80000000-0000-4000-8000-000000000002',
      type: 'heading', level: 2, content: inlineContent('SecciÃ³n uno'),
      preset: 'accent-line', presentation: typography(),
    }, {
      id: '80000000-0000-4000-8000-000000000003',
      type: 'list', ordered: false, marker: 'square',
      items: [{
        id: '80000000-0000-4000-8000-000000000004',
        content: inlineContent('Elemento'),
      }],
      presentation: {
        ...genericPresentation(), text_color: 'color02',
      },
    }, {
      id: '80000000-0000-4000-8000-000000000005',
      type: 'quote', content: inlineContent('Cita'), author: 'Neo',
      source: 'Matrix', preset: 'accent',
      presentation: genericPresentation(),
    }, {
      id: '80000000-0000-4000-8000-000000000006',
      type: 'callout', content: inlineContent('Aviso'), tone: 'warning',
      presentation: genericPresentation(),
    }, {
      id: '80000000-0000-4000-8000-000000000007',
      type: 'article',
      layout: {
        preset: '1',
        columns: [{
          id: '80000000-0000-4000-8000-000000000008',
          children: [{
            id: '80000000-0000-4000-8000-000000000009',
            type: 'heading', level: 3, content: inlineContent('ArtÃ­culo'),
            preset: 'accent-block', presentation: typography(),
          }],
        }],
      },
    }],
  }, {
    id: '90000000-0000-4000-8000-000000000001',
    type: 'section',
    children: [{
      id: '90000000-0000-4000-8000-000000000002',
      type: 'heading', level: 2, content: inlineContent('SecciÃ³n dos'),
      preset: 'default', presentation: typography(),
    }],
  }],
};
const legacyTextBeforeValid = hooks.validV2Document(legacyTextDocument);
const legacyTextNormalized = hooks.normalizeUnifiedTextModules(
  legacyTextDocument
);
const legacyTextAfterValid = hooks.validV2Document(legacyTextDocument);
const normalizedSource = legacyTextDocument.blocks[0].children[0];
normalizedSource.content[0].preset = 'accent-block';
const internalHeadingStyleApplied = hooks.v2ApplyHeadingLevelStyle(
  { documentValue: legacyTextDocument },
  normalizedSource
);
const styledTarget = legacyTextDocument.blocks[1].children[0];
let generatedUuid = 0;
window.crypto = {
  randomUUID() {
    generatedUuid += 1;
    return 'f0000000-0000-4000-8000-'
      + String(generatedUuid).padStart(12, '0');
  },
};
const clonedSection = hooks.v2CloneWithIds(
  { documentValue: legacyTextDocument },
  legacyTextDocument.blocks[0]
);
const normalizedMetadata = hooks.richParseTextFlowRoot(
  element('body', {}, [
    element('h2', {
      'data-content-heading-preset': 'accent-line',
    }, [text('TÃ­tulo')]),
    element('ul', {
      'data-content-list-marker': 'square',
    }, [element('li', {
      'data-content-list-item-id': 'a0000000-0000-4000-8000-000000000001',
    }, [text('Elemento')])]),
    element('blockquote', {
      'data-content-quote-author': 'Neo',
      'data-content-quote-source': 'Matrix',
      'data-content-quote-preset': 'accent',
    }, [text('Cita')]),
    element('aside', {
      'data-content-callout': 'true',
      role: 'note',
      'data-content-callout-tone': 'warning',
    }, [text('Aviso')]),
  ]),
  true
);
assert.deepEqual(
  [legacyTextBeforeValid, legacyTextNormalized, legacyTextAfterValid],
  [true, true, true]
);
assert.deepEqual(
  [
    ...legacyTextDocument.blocks[0].children.slice(0, 4),
    legacyTextDocument.blocks[0].children[4].layout.columns[0].children[0],
    legacyTextDocument.blocks[1].children[0],
  ].map((node) => node.type),
  Array(6).fill('paragraph')
);
assert.equal(internalHeadingStyleApplied, true);
assert.equal(styledTarget.content[0].preset, 'accent-block');
assert.equal(
  legacyTextDocument.blocks[0].children[1].content[0].items[0].id,
  '80000000-0000-4000-8000-000000000004'
);
assert.notEqual(
  clonedSection.children[1].content[0].items[0].id,
  '80000000-0000-4000-8000-000000000004'
);
assert.match(
  hooks.richSerializeTextFlowHtml(normalizedMetadata),
  /data-content-quote-author="Neo"[^>]+data-content-quote-preset="accent"/u
);

const sameModuleHeading = {
  id: 'b0000000-0000-4000-8000-000000000002',
  type: 'paragraph',
  content: [
    {
      type: 'heading', level: 2, content: inlineContent('Primero'),
      preset: 'accent-line',
    },
    { type: 'paragraph', content: inlineContent('Cuerpo') },
    {
      type: 'heading', level: 2, content: inlineContent('Segundo'),
      preset: 'default',
    },
  ],
  presentation: typography(),
};
const externalHeading = {
  id: 'b0000000-0000-4000-8000-000000000003',
  type: 'paragraph',
  content: [{
    type: 'heading', level: 2, content: inlineContent('Externo'),
    preset: 'default',
  }],
  presentation: typography(),
};
const sameModuleHeadingDocument = {
  schema: 'liquidstack.blog.document',
  version: 2,
  template: 'article-basic-01',
  blocks: [{
    id: 'b0000000-0000-4000-8000-000000000001',
    type: 'section',
    children: [sameModuleHeading, externalHeading],
  }],
};
assert.equal(hooks.v2ApplyHeadingLevelStyle(
  { documentValue: sameModuleHeadingDocument },
  sameModuleHeading
), true);
assert.equal(sameModuleHeading.content[2].preset, 'accent-line');
assert.equal(externalHeading.content[0].preset, 'accent-line');

const metadataQuote = {
  type: 'quote', content: inlineContent('Cita'), author: null,
  source: null, preset: 'default',
};
assert.equal(hooks.richSetFlowMetadataValue(
  metadataQuote, 'author', 'a'.repeat(255)
), true);
assert.equal(hooks.richSetFlowMetadataValue(
  metadataQuote, 'author', 'a'.repeat(256)
), false);
assert.equal(metadataQuote.author.length, 255);
assert.equal(hooks.richSetFlowMetadataValue(
  metadataQuote, 'preset', 'accent'
), true);
assert.equal(hooks.richSetFlowMetadataValue(
  metadataQuote, 'preset', 'unsafe'
), false);
const metadataList = {
  type: 'list', ordered: false,
  items: [{ content: inlineContent('Elemento') }],
};
assert.equal(hooks.richSetFlowMetadataValue(
  metadataList, 'marker', 'square'
), true);
assert.equal(hooks.richSetFlowMetadataValue(
  metadataList, 'marker', 'decimal'
), false);
const metadataCallout = {
  type: 'callout', content: inlineContent('Aviso'), tone: 'neutral',
};
assert.equal(hooks.richSetFlowMetadataValue(
  metadataCallout, 'tone', 'warning'
), true);

const canonicalProjection = {
  schema: 'liquidstack.blog.document',
  version: 2,
  template: 'article-basic-01',
  blocks: [{
    id: 'c0000000-0000-4000-8000-000000000001',
    type: 'section',
    children: [{
      id: 'c0000000-0000-4000-8000-000000000002',
      type: 'heading', level: 2, content: inlineContent('Sección'),
      preset: 'default', presentation: typography(),
    }, {
      id: 'c0000000-0000-4000-8000-000000000003',
      type: 'quote', content: inlineContent('Cita'), author: 'Neo',
      source: 'Matrix', preset: 'accent',
      presentation: { width: 'full', align: 'start', text_align: 'start' },
    }, {
      id: 'c0000000-0000-4000-8000-000000000004',
      type: 'list', ordered: false, marker: 'square',
      items: [{
        id: 'c0000000-0000-4000-8000-000000000005',
        content: inlineContent('Elemento'),
      }],
      presentation: {
        width: '80', align: 'center', text_align: 'start', size: 'm',
        text_color: 'color02', spacing_before: 's', spacing_after: 'm',
      },
    }, {
      id: 'c0000000-0000-4000-8000-000000000006',
      type: 'link', label: 'Enlace legado', href: '/destino', title: null,
      target: 'same', presentation: {
        width: 'full', align: 'start', text_align: 'justify', size: 'm',
      },
    }],
  }],
};
assert.equal(hooks.normalizeUnifiedTextModules(canonicalProjection), true);
assert.equal(hooks.normalizeV2Presentations(canonicalProjection), false);
assert.deepEqual(
  canonicalProjection.blocks[0].children[1].presentation,
  { width: 'full', align: 'start', text_align: 'start' }
);
assert.deepEqual(
  canonicalProjection.blocks[0].children[2].presentation,
  {
    width: '80', align: 'center', text_align: 'start', size: 'm',
    font_weight: 'default', text_color: 'color02',
    spacing_before: 's', spacing_after: 'm',
  }
);
assert.equal(canonicalProjection.blocks[0].children[3].type, 'cta');
assert.equal(
  canonicalProjection.blocks[0].children[3].presentation.text_align,
  'start'
);
assert.equal(hooks.validV2Document(canonicalProjection), true);
const invalidJustifiedCta = structuredClone(canonicalProjection);
invalidJustifiedCta.blocks[0].children[3].presentation.text_align = 'justify';
assert.equal(hooks.validV2Document(invalidJustifiedCta), false);

window.crypto = webcrypto;
const canonicalProjectionJson = JSON.stringify(canonicalProjection);
const canonicalProjectionSha256 = await hooks.v2DocumentSha256(
  canonicalProjection
);
assert.equal(
  canonicalProjectionSha256,
  createHash('sha256').update(canonicalProjectionJson).digest('hex')
);

process.stdout.write(JSON.stringify({
  insertableTypes: hooks.INSERTABLE_BLOCK_TYPES,
  flow,
  serializedFlow,
  semanticFlow,
  semanticFlowHtml,
  combined: {
    serialized: combinedSerialized,
    parsed: combinedFlow,
    roundTrip: combinedRoundTrip,
    stable: JSON.stringify(combinedFlow) === JSON.stringify(combinedRoundTrip),
  },
  bullets,
  unwrapped,
  listStyles: {
    sourcePreserved: styledSourceBefore === JSON.stringify(styledSource),
    markerRestyled,
    typeRestyled,
    listRemoved,
    paragraphsNumbered,
    invalidListStyle,
  },
  legacy: {
    projected: hooks.richLegacyListFlow(legacy),
    canStayList: hooks.richFlowCanStayLegacyList(
      hooks.richLegacyListFlow(legacy)
    ),
    noop: legacyNoop,
    noopHashPreserved: legacyNoopHashPreserved,
    listEdit: legacyListEdit,
    flowEdit: legacyFlowEdit,
    applyNoopHashPreserved: segmentedBefore === segmentedAfter,
    applyNoopClosed: segmentedState.dialog.closed,
    applyNoopAnnouncement: segmentedState.context.status.textContent,
  },
  heading: {
    parsed: heading,
    html: headingHtml,
    rootSuggestions: headingRootSuggestions,
    inlineSuggestions: headingInlineSuggestions,
    forbiddenLevel: errorCode(() => hooks.richParseHeadingRoot(
      headingRoot,
      [2]
    )),
    paragraphWrapper: errorCode(() => hooks.richParseHeadingRoot(
      element('body', {}, [element('p', {}, [text('No')])]),
      [3]
    )),
    headingInsideText: errorCode(() => hooks.richParseTextFlowRoot(
      element('body', {}, [element('h3', {}, [text('No')])]),
      true
    )),
    boldAliasInSource: errorCode(() => hooks.richParseTextFlowRoot(
      element('body', {}, [
        element('p', {}, [element('b', {}, [text('No')])]),
      ]),
      true
    )),
    unsafeLinkInText: errorCode(() => hooks.richParseTextFlowRoot(
      element('body', {}, [
        element('p', {}, [
          element('a', { href: 'javascript:alert(1)' }, [text('No')]),
        ]),
      ]),
      true
    )),
    sectionAllowedLevels: hooks.richAllowedHeadingLevels(
      semanticContext,
      structuralH2
    ),
    articleAllowedLevels: hooks.richAllowedHeadingLevels(
      semanticContext,
      structuralH3
    ),
  },
  protectedUnifiedHeading: {
    startsHeading: hooks.v2SectionHeadingModule(unifiedHeading),
    retaggedStartsHeading: hooks.v2SectionHeadingModule(retaggedUnifiedHeading),
    valid: hooks.validV2Document(unifiedDocument),
    validDraft: hooks.validV2DraftDocument(unifiedDocument),
    protected: hooks.v2ProtectedHeading(unifiedLocation),
    canMove: hooks.v2CanMove(unifiedLocation, 1),
    canDrag: hooks.v2CanDrag(unifiedLocation),
    canDuplicate: hooks.v2CanDuplicate(
      { documentValue: unifiedDocument },
      unifiedLocation
    ),
    canDelete: hooks.v2CanDelete(unifiedLocation),
    visualParagraphBlocked: hooks.richSelectionBreaksLeadingHeading(
      {
        requiresLeadingHeading: true,
        flowDraft: unifiedHeading.content,
      },
      [0],
      'paragraph'
    ),
    visualQuoteBlocked: hooks.richSelectionBreaksLeadingHeading(
      {
        requiresLeadingHeading: true,
        flowDraft: unifiedHeading.content,
      },
      [0],
      'quote'
    ),
    visualHeadingAllowed: !hooks.richSelectionBreaksLeadingHeading(
      {
        requiresLeadingHeading: true,
        flowDraft: unifiedHeading.content,
      },
      [0],
      'heading'
    ),
    laterParagraphAllowed: !hooks.richSelectionBreaksLeadingHeading(
      {
        requiresLeadingHeading: true,
        flowDraft: unifiedHeading.content,
      },
      [1],
      'paragraph'
    ),
    validFlowApply: hooks.richDraftKeepsLeadingHeading({
      requiresLeadingHeading: true,
      advancedMode: false,
      flowDraft: unifiedHeading.content,
    }),
    invalidFlowApply: hooks.richDraftKeepsLeadingHeading({
      requiresLeadingHeading: true,
      advancedMode: false,
      flowDraft: [{ type: 'paragraph', content: [] }],
    }),
  },
  unifiedTextNormalization: {
    beforeValid: legacyTextBeforeValid,
    changed: legacyTextNormalized,
    afterValid: legacyTextAfterValid,
    moduleTypes: [
      ...legacyTextDocument.blocks[0].children.slice(0, 4),
      legacyTextDocument.blocks[0].children[4].layout.columns[0].children[0],
      legacyTextDocument.blocks[1].children[0],
    ].map((node) => node.type),
    headingPreset: normalizedSource.content[0].preset,
    targetHeadingPreset: styledTarget.content[0].preset,
    internalHeadingStyleApplied,
    listItemId: legacyTextDocument.blocks[0].children[1]
      .content[0].items[0].id,
    clonedModuleTypes: clonedSection.children.map((node) => node.type),
    clonedListItemId: clonedSection.children[1].content[0].items[0].id,
    metadata: normalizedMetadata,
    metadataHtml: hooks.richSerializeTextFlowHtml(normalizedMetadata),
  },
  unifiedTextP1: {
    sameModuleHeadingPreset: sameModuleHeading.content[2].preset,
    externalHeadingPreset: externalHeading.content[0].preset,
    quoteAuthorBytes: Buffer.byteLength(metadataQuote.author, 'utf8'),
    quotePreset: metadataQuote.preset,
    listMarker: metadataList.marker,
    calloutTone: metadataCallout.tone,
    sparsePresentation:
      canonicalProjection.blocks[0].children[1].presentation,
    listPresentation:
      canonicalProjection.blocks[0].children[2].presentation,
    legacyLinkType: canonicalProjection.blocks[0].children[3].type,
    legacyLinkAlignment:
      canonicalProjection.blocks[0].children[3].presentation.text_align,
    justifiedCtaValid: hooks.validV2Document(invalidJustifiedCta),
    presentationNormalizerChanged:
      hooks.normalizeV2Presentations(canonicalProjection),
    documentSha256: canonicalProjectionSha256,
  },
}));
