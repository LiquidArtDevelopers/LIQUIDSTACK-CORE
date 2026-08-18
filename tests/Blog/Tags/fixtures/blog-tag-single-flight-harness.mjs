import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const asset = process.argv[2];
let source = fs.readFileSync(asset, 'utf8');
const marker = '}());';
const markerIndex = source.lastIndexOf(marker);
if (markerIndex < 0) {
  throw new Error('Unable to expose Blog facet hooks.');
}

source = source.slice(0, markerIndex)
  + `sync = function () {};
  formBody = function () { return new URLSearchParams(); };
  renderTagAssignment = function () {};
  v2SyncEditorialLockVersion = function () { return true; };
  v2SyncCategoryWorkspaceVersion = function () { return true; };
  globalThis.__tagWorkspaceVersions = [];
  v2SyncTagWorkspaceVersion = function (context, version) {
    globalThis.__tagWorkspaceVersions.push(version);
    return Number.isInteger(version) && version >= 0;
  };
  categoryRequest = function (state) {
    return globalThis.__deferCategory(
      categorySelectionFingerprint(state.assignmentForm)
    );
  };
  tagAssignmentRequest = function (state) {
    return globalThis.__deferTag(state.canonicalInput.value);
  };
  globalThis.__blogFacetHooks = {
    categorySelectionFingerprint,
    saveCategoryAssignment,
    saveTagAssignment,
    discardTagAssignment,
    scheduleTagAssignment,
    syncTagAssignmentDraft,
    tagListFingerprint
  };
`
  + source.slice(markerIndex);

class FakeInput {
  constructor(value = '') {
    this.value = value;
    this.checked = false;
    this.type = 'hidden';
    this.attributes = {};
  }

  setAttribute(name, value) {
    this.attributes[name] = String(value);
  }

  removeAttribute(name) {
    delete this.attributes[name];
  }
}

class FakeButton {
  constructor() {
    this.disabled = false;
  }
}

class FakeForm {
  constructor(inputs, workspaceName) {
    this.inputs = inputs;
    this.action = '/admin/blog/facet/assign';
    this.attributes = {};
    this.workspace = new FakeInput('0');
    this.elements = {
      namedItem: (name) => (
        name === workspaceName ? this.workspace : null
      ),
    };
  }

  querySelectorAll(selector) {
    return selector === 'input[name="categories[]"]:checked'
      ? this.inputs.filter((input) => input.checked)
      : [];
  }

  setAttribute(name, value) {
    this.attributes[name] = String(value);
  }

  removeAttribute(name) {
    delete this.attributes[name];
  }
}

globalThis.HTMLInputElement = FakeInput;
globalThis.HTMLButtonElement = FakeButton;
globalThis.HTMLFormElement = FakeForm;
globalThis.HTMLElement = class {};
globalThis.document = {
  readyState: 'loading',
  addEventListener() {},
};
const timers = new Map();
let timerSequence = 0;
globalThis.window = {
  setTimeout(callback) {
    timerSequence += 1;
    timers.set(timerSequence, callback);
    return timerSequence;
  },
  clearTimeout(timer) {
    timers.delete(timer);
  },
};

function runTimer(timer) {
  const callback = timers.get(timer);
  assert.equal(typeof callback, 'function');
  timers.delete(timer);
  callback();
}

function deferredQueue() {
  const requests = [];
  let active = 0;
  let maximumActive = 0;

  return {
    requests,
    defer(snapshot) {
      active += 1;
      maximumActive = Math.max(maximumActive, active);
      return new Promise((resolve) => {
        requests.push({
          snapshot,
          finish(payload) {
            active -= 1;
            resolve(payload);
          },
        });
      });
    },
    maximumActive() {
      return maximumActive;
    },
  };
}

async function waitForRequest(queue, expected) {
  for (let attempt = 0; attempt < 50; attempt += 1) {
    if (queue.requests.length >= expected) {
      return;
    }
    await Promise.resolve();
  }
  assert.fail(`Expected ${expected} queued requests.`);
}

const categoryQueue = deferredQueue();
const tagQueue = deferredQueue();
globalThis.__deferCategory = (snapshot) => categoryQueue.defer(snapshot);
globalThis.__deferTag = (snapshot) => tagQueue.defer(snapshot);
vm.runInThisContext(source, { filename: asset });

const hooks = globalThis.__blogFacetHooks;
const categoryA = new FakeInput(
  '70000000-0000-4000-8000-000000000001',
);
const categoryB = new FakeInput(
  '70000000-0000-4000-8000-000000000002',
);
categoryA.checked = true;
const categoryForm = new FakeForm(
  [categoryA, categoryB],
  'category_workspace_version',
);
const categoryState = {
  context: {},
  assignmentForm: categoryForm,
  assignmentStatus: { textContent: '', dataset: {} },
  assignmentSubmit: new FakeButton(),
  assignmentPending: false,
  assignmentPromise: null,
  cleanFingerprint: '',
  dirty: true,
  discarding: false,
};

const firstCategorySave = hooks.saveCategoryAssignment(categoryState);
assert.equal(categoryQueue.requests.length, 1);
categoryA.checked = false;
categoryB.checked = true;
const concurrentCategorySave = hooks.saveCategoryAssignment(categoryState);
assert.equal(
  categoryQueue.requests.length,
  1,
  'a pending category mutation must not start a parallel request',
);
categoryQueue.requests[0].finish({
  ok: true,
  lock_version: 1,
  category_workspace_version: 1,
});
await waitForRequest(categoryQueue, 2);
assert.equal(
  categoryQueue.requests[1].snapshot,
  categoryB.value,
  'the category state changed in flight must be replayed',
);
categoryQueue.requests[1].finish({
  ok: true,
  lock_version: 1,
  category_workspace_version: 2,
});
assert.deepEqual(
  await Promise.all([firstCategorySave, concurrentCategorySave]),
  [true, true],
);
assert.equal(categoryQueue.maximumActive(), 1);
assert.equal(categoryState.cleanFingerprint, categoryB.value);
assert.equal(categoryState.dirty, false);

const canonicalInput = new FakeInput('Ahorro');
const tagState = {
  context: {},
  form: new FakeForm([], 'tag_workspace_version'),
  canonicalInput,
  composer: new FakeInput(''),
  list: {},
  status: { textContent: '', dataset: {} },
  submit: new FakeButton(),
  tags: [{ name: 'Ahorro', slug: '' }],
  cleanFingerprint: '[]',
  dirty: true,
  composing: false,
  revision: 1,
  assignmentPending: false,
  assignmentPromise: null,
  saveTimer: null,
  discarding: false,
};

const firstTagSave = hooks.saveTagAssignment(tagState);
assert.equal(tagQueue.requests.length, 1);
tagState.tags.push({ name: 'Fiscalidad', slug: '' });
tagState.revision += 1;
hooks.syncTagAssignmentDraft(tagState);
const concurrentTagSave = hooks.saveTagAssignment(tagState);
assert.equal(
  tagQueue.requests.length,
  1,
  'a pending tag mutation must not start a parallel request',
);
tagQueue.requests[0].finish({
  ok: true,
  lock_version: 1,
  tag_workspace_version: 1,
  tags: [{ name: 'Ahorro', slug: 'ahorro' }],
});
await waitForRequest(tagQueue, 2);
assert.equal(
  tagQueue.requests[1].snapshot,
  'Ahorro, Fiscalidad',
  'the tag state changed in flight must be replayed',
);
tagQueue.requests[1].finish({
  ok: true,
  lock_version: 1,
  tag_workspace_version: 2,
  tags: [
    { name: 'Ahorro', slug: 'ahorro' },
    { name: 'Fiscalidad', slug: 'fiscalidad' },
  ],
});
assert.deepEqual(
  await Promise.all([firstTagSave, concurrentTagSave]),
  [true, true],
);
assert.equal(tagQueue.maximumActive(), 1);
assert.deepEqual(tagState.tags, [
  { name: 'Ahorro', slug: 'ahorro' },
  { name: 'Fiscalidad', slug: 'fiscalidad' },
]);
assert.equal(
  tagState.cleanFingerprint,
  hooks.tagListFingerprint(tagState.tags),
);
assert.equal(tagState.dirty, false);

tagState.composer.value = 'PlanificaciÃ³n todavÃ­a incompleta';
tagState.revision += 1;
hooks.scheduleTagAssignment(tagState);
const pauseTimer = tagState.saveTimer;
assert.notEqual(pauseTimer, null);
assert.equal(tagState.tags.length, 2);
runTimer(pauseTimer);
await Promise.resolve();
assert.equal(
  tagState.composer.value,
  'PlanificaciÃ³n todavÃ­a incompleta',
  'a typing pause must preserve the unconfirmed word in the composer',
);
assert.equal(tagState.tags.length, 2);
assert.equal(
  tagQueue.requests.length,
  2,
  'debounce must save confirmed chips only',
);
assert.equal(tagState.dirty, true);

tagState.composer.value = '';
tagState.dirty = false;
tagState.revision += 1;

const canonicalRequestIndex = tagQueue.requests.length;
tagState.composer.value = 'Cafe\u0301';
tagState.revision += 1;
tagState.dirty = true;
const canonicalSave = hooks.saveTagAssignment(tagState, true);
assert.equal(tagQueue.requests.length, canonicalRequestIndex + 1);
assert.equal(
  tagQueue.requests[canonicalRequestIndex].snapshot,
  'Ahorro, Fiscalidad, Café',
  'the composer must project decomposed input as NFC before transport',
);
tagQueue.requests[canonicalRequestIndex].finish({
  ok: true,
  lock_version: 1,
  tag_workspace_version: 3,
  tags: [
    { name: 'Ahorro', slug: 'ahorro' },
    { name: 'Café', slug: 'cafe' },
    { name: 'Fiscalidad', slug: 'fiscalidad' },
  ],
});
assert.equal(await canonicalSave, true);
assert.equal(globalThis.__tagWorkspaceVersions.at(-1), 3);
assert.deepEqual(tagState.tags, [
  { name: 'Ahorro', slug: 'ahorro' },
  { name: 'Café', slug: 'cafe' },
  { name: 'Fiscalidad', slug: 'fiscalidad' },
]);

const foldedRequestIndex = tagQueue.requests.length;
tagState.composer.value = 'Straße, Strasse';
tagState.revision += 1;
tagState.dirty = true;
const foldedSave = hooks.saveTagAssignment(tagState, true);
assert.equal(tagQueue.requests.length, foldedRequestIndex + 1);
assert.equal(
  tagQueue.requests[foldedRequestIndex].snapshot,
  'Ahorro, Café, Fiscalidad, Straße, Strasse',
  'client dedupe must not guess the authoritative server case-fold',
);
tagQueue.requests[foldedRequestIndex].finish({
  ok: true,
  lock_version: 1,
  tag_workspace_version: 4,
  tags: [
    { name: 'Ahorro', slug: 'ahorro' },
    { name: 'Café', slug: 'cafe' },
    { name: 'Fiscalidad', slug: 'fiscalidad' },
    { name: 'Straße', slug: 'strasse' },
  ],
});
assert.equal(await foldedSave, true);
assert.equal(globalThis.__tagWorkspaceVersions.at(-1), 4);
assert.equal(
  tagState.tags.filter((tag) => tag.slug === 'strasse').length,
  1,
  'the canonical server response must replace client-side fold variants',
);

tagState.tags.push({ name: 'Tesoreria', slug: '' });
tagState.revision += 1;
hooks.syncTagAssignmentDraft(tagState);
const tailFirstIndex = tagQueue.requests.length;
let tailResolved = false;
const tailSave = hooks.saveTagAssignment(tagState, true).then((saved) => {
  tailResolved = true;
  return saved;
});
assert.equal(tagQueue.requests.length, tailFirstIndex + 1);
tagState.composer.value = 'Valor';
tagState.revision += 1;
tagState.dirty = true;
tagQueue.requests[tailFirstIndex].finish({
  ok: true,
  lock_version: 1,
  tag_workspace_version: 5,
  tags: [
    { name: 'Ahorro', slug: 'ahorro' },
    { name: 'Café', slug: 'cafe' },
    { name: 'Fiscalidad', slug: 'fiscalidad' },
    { name: 'Straße', slug: 'strasse' },
    { name: 'Tesoreria', slug: 'tesoreria' },
  ],
});
await waitForRequest(tagQueue, tailFirstIndex + 2);
assert.equal(tailResolved, false);
assert.equal(
  tagQueue.requests[tailFirstIndex + 1].snapshot,
  'Ahorro, Café, Fiscalidad, Straße, Tesoreria, Valor',
  'text typed during the last request must be committed in a second request',
);
tagQueue.requests[tailFirstIndex + 1].finish({
  ok: true,
  lock_version: 1,
  tag_workspace_version: 6,
  tags: [
    { name: 'Ahorro', slug: 'ahorro' },
    { name: 'Café', slug: 'cafe' },
    { name: 'Fiscalidad', slug: 'fiscalidad' },
    { name: 'Straße', slug: 'strasse' },
    { name: 'Tesoreria', slug: 'tesoreria' },
    { name: 'Valor', slug: 'valor' },
  ],
});
assert.equal(await tailSave, true);
assert.equal(tagState.composer.value, '');
assert.equal(tagState.dirty, false);

tagState.tags.push({ name: 'Xestion', slug: '' });
tagState.revision += 1;
hooks.syncTagAssignmentDraft(tagState);
const imeFirstIndex = tagQueue.requests.length;
let imeResolved = false;
const imeSave = hooks.saveTagAssignment(tagState, true).then((saved) => {
  imeResolved = true;
  return saved;
});
tagState.composing = true;
tagState.composer.value = 'Zona';
tagState.revision += 1;
tagState.dirty = true;
// Mirrors compositionend while the first request is still in flight.
tagState.composing = false;
tagState.revision += 1;
tagQueue.requests[imeFirstIndex].finish({
  ok: true,
  lock_version: 1,
  tag_workspace_version: 7,
  tags: [
    { name: 'Ahorro', slug: 'ahorro' },
    { name: 'Café', slug: 'cafe' },
    { name: 'Fiscalidad', slug: 'fiscalidad' },
    { name: 'Straße', slug: 'strasse' },
    { name: 'Tesoreria', slug: 'tesoreria' },
    { name: 'Valor', slug: 'valor' },
    { name: 'Xestion', slug: 'xestion' },
  ],
});
await waitForRequest(tagQueue, imeFirstIndex + 2);
assert.equal(imeResolved, false);
assert.equal(
  tagQueue.requests[imeFirstIndex + 1].snapshot,
  'Ahorro, Café, Fiscalidad, Straße, Tesoreria, Valor, Xestion, Zona',
  'an IME tail completed in flight must be sent before orchestration resolves',
);
tagQueue.requests[imeFirstIndex + 1].finish({
  ok: true,
  lock_version: 1,
  tag_workspace_version: 8,
  tags: [
    { name: 'Ahorro', slug: 'ahorro' },
    { name: 'Café', slug: 'cafe' },
    { name: 'Fiscalidad', slug: 'fiscalidad' },
    { name: 'Straße', slug: 'strasse' },
    { name: 'Tesoreria', slug: 'tesoreria' },
    { name: 'Valor', slug: 'valor' },
    { name: 'Xestion', slug: 'xestion' },
    { name: 'Zona', slug: 'zona' },
  ],
});
assert.equal(await imeSave, true);
assert.equal(tagState.composer.value, '');
assert.equal(tagState.dirty, false);
assert.equal(tagQueue.maximumActive(), 1);

tagState.tags.push({ name: 'Ypsilon', slug: '' });
tagState.composer.value = 'Cola conservada';
tagState.revision += 1;
hooks.syncTagAssignmentDraft(tagState);
const failedDiscardIndex = tagQueue.requests.length;
const failedSave = hooks.saveTagAssignment(tagState, false);
const failedDiscard = hooks.discardTagAssignment(tagState);
assert.equal(tagState.discarding, true);
tagQueue.requests[failedDiscardIndex].finish({ ok: false });
assert.deepEqual(await Promise.all([failedSave, failedDiscard]), [false, false]);
assert.equal(tagState.discarding, false);
assert.equal(tagState.composer.value, 'Cola conservada');
assert.equal(tagState.tags.at(-1).name, 'Ypsilon');
assert.equal(tagState.dirty, true);

process.stdout.write('BLOG_TAG_SINGLE_FLIGHT_OK\n');
