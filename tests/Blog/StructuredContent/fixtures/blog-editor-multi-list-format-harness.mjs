import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const asset = process.argv[2];
let source = fs.readFileSync(asset, 'utf8');
const marker = '}());';
const markerIndex = source.lastIndexOf(marker);
if (markerIndex < 0) {
  throw new Error('Unable to expose multi-list formatting hooks.');
}
source = source.slice(0, markerIndex)
  + `globalThis.__multiListHooks = {
    richTransformFlowSelectionRanges,
    richFlowMarkTransform,
    richClearMarks,
    richFlowRangesShareList
  };\n`
  + source.slice(markerIndex);

globalThis.Node = {
  ELEMENT_NODE: 1,
  TEXT_NODE: 3,
  DOCUMENT_FRAGMENT_NODE: 11,
};
globalThis.document = { readyState: 'loading', addEventListener() {} };
globalThis.window = {};
vm.runInThisContext(source, { filename: asset });

const hooks = globalThis.__multiListHooks;
const firstId = 'a1000000-0000-4000-8000-000000000001';
const secondId = 'a1000000-0000-4000-8000-000000000002';
const draft = [{
  type: 'list',
  ordered: true,
  marker: 'decimal',
  items: [{
    id: firstId,
    content: [
      { type: 'text', text: 'Uno ', marks: ['em', 'text-color02'] },
      {
        type: 'link', text: 'enlace', marks: ['underline'],
        href: '/neo', title: 'Neo', target: 'new',
      },
    ],
  }, {
    id: secondId,
    content: [{
      type: 'text', text: 'Dos', marks: ['background-basic-yellow'],
    }],
  }],
}];
const ranges = [
  { flowIndex: 0, itemIndex: 0, start: 0, end: 10 },
  { flowIndex: 0, itemIndex: 1, start: 0, end: 3 },
];
const originalShape = {
  type: draft[0].type,
  ordered: draft[0].ordered,
  marker: draft[0].marker,
  ids: draft[0].items.map((item) => item.id),
};

function apply(transform) {
  const result = hooks.richTransformFlowSelectionRanges(
    draft,
    ranges,
    transform
  );
  assert.ok(result);
}

for (const mark of ['strong', 'em', 'underline']) {
  apply(hooks.richFlowMarkTransform(mark, null));
}
apply(hooks.richFlowMarkTransform('size-xlarge', 'size'));
apply(hooks.richFlowMarkTransform('text-basic-red', 'color'));
apply(hooks.richFlowMarkTransform('background-basic-blue', 'background'));

const formatted = structuredClone(draft);
apply(hooks.richFlowMarkTransform('strong', null));
const strongRemovedGlobally = draft[0].items.every((item) => (
  item.content.every((node) => !node.marks.includes('strong'))
));
apply((content, start, end) => hooks.richClearMarks(content, start, end));

const finalShape = {
  type: draft[0].type,
  ordered: draft[0].ordered,
  marker: draft[0].marker,
  ids: draft[0].items.map((item) => item.id),
};
const formattedNodes = formatted[0].items.flatMap((item) => item.content);
const linkAfterClear = draft[0].items[0].content.find(
  (node) => node.type === 'link'
);
const unsafeRanges = [
  { flowIndex: 0, itemIndex: 0, start: 0, end: 1 },
  { flowIndex: 1, itemIndex: null, start: 0, end: 1 },
];

assert.deepEqual(finalShape, originalShape);
assert.ok(formattedNodes.every((node) => (
  ['strong', 'em', 'underline', 'size-xlarge', 'text-basic-red',
    'background-basic-blue'].every((mark) => node.marks.includes(mark))
)));
assert.equal(strongRemovedGlobally, true);
assert.deepEqual(linkAfterClear, {
  type: 'link', text: 'enlace', marks: [],
  href: '/neo', title: 'Neo', target: 'new',
});
assert.ok(draft[0].items.flatMap((item) => item.content).every(
  (node) => node.marks.length === 0
));
assert.equal(hooks.richFlowRangesShareList(draft, unsafeRanges), false);

process.stdout.write(JSON.stringify({
  originalShape,
  finalShape,
  formatted,
  strongRemovedGlobally,
  linkAfterClear,
  unsafeRejected: !hooks.richFlowRangesShareList(draft, unsafeRanges),
}));
