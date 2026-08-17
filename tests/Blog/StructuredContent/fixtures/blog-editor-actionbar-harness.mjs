import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import vm from 'node:vm';

const asset = process.argv[2];
let source = fs.readFileSync(asset, 'utf8');
const marker = '}());';
const markerIndex = source.lastIndexOf(marker);
if (markerIndex < 0) {
  throw new Error('Unable to expose action bar hooks.');
}

source = source.slice(0, markerIndex)
  + `var actualV2DocumentCanBeSaved = v2DocumentCanBeSaved;
  v2DocumentCanBeSaved = function (context) {
    if (context.throwDuringSaveValidation) {
      throw new Error('forced-save-validation-failure');
    }
    return actualV2DocumentCanBeSaved(context);
  };
  renderV2 = function () {};
  announce = function (context, message, error) {
    globalThis.__blogActionAnnouncements.push({ message, error });
  };
  showEditorServerNotice = function () {};
  v2ApplyErrorIssue = function () {};
  requestEditorNavigation = function (context, trigger, canSave) {
    globalThis.__blogNavigationRequests.push({ trigger, canSave });
    return Promise.resolve(globalThis.__blogNavigationChoice);
  };
  globalThis.__blogActionBarHooks = {
    formBody,
    editorFormBody,
    editorialFormFingerprint,
    inlinePlainText,
    normalizeUnifiedTextModules,
    firstDocumentTechnicalIssue,
    sync,
    validV2DraftDocument,
    v2ReadSaveResponse,
    v2SaveDraft,
    v2BindSave,
    v2PrepareSavedPreview,
    v2LoadPreview,
    v2RefreshPreview,
    v2PreviewDidLoad,
    v2HasUnsavedChanges,
    v2InternalNavigationLink,
    v2BindInternalNavigation
  };\n`
  + source.slice(markerIndex);

class FakeElement {
  constructor(name, value = '') {
    this.name = name;
    this.value = value;
    this.disabled = false;
    this.dataset = {};
    this.attributes = {};
  }

  setAttribute(name, value) {
    this.attributes[name] = String(value);
  }

  removeAttribute(name) {
    delete this.attributes[name];
  }

  getAttribute(name) {
    return Object.hasOwn(this.attributes, name)
      ? this.attributes[name]
      : null;
  }

  hasAttribute(name) {
    return Object.hasOwn(this.attributes, name);
  }

  closest() {
    return null;
  }
}

class FakeInput extends FakeElement {
  constructor(name, value = '', type = 'hidden') {
    super(name, value);
    this.type = type;
    this.checked = false;
  }
}

class FakeButton extends FakeElement {
  constructor() {
    super('', '');
    this.type = 'submit';
    this.form = null;
  }

  click() {
    if (!this.form || this.disabled) {
      return null;
    }
    return this.form.requestSubmit(this);
  }
}

class FakeForm {
  constructor(controls, classNames = []) {
    this.id = 'blog-editor-form';
    this.action = 'http://localhost/admin/blog/editor/save';
    this.method = 'post';
    this.attributes = {};
    this.classNames = new Set(classNames);
    this.classList = {
      contains: (name) => this.classNames.has(name),
    };
    this.controls = controls;
    this.listeners = new Map();
    this.nativeSubmitCount = 0;
    this.elements = controls;
    this.elements.namedItem = (name) => (
      controls.find((control) => control.name === name) || null
    );
    controls.forEach((control) => {
      if (control instanceof FakeButton) {
        control.form = this;
        control.setAttribute('form', this.id);
      }
    });
  }

  setAttribute(name, value) {
    this.attributes[name] = String(value);
  }

  removeAttribute(name) {
    delete this.attributes[name];
  }

  addEventListener(type, listener) {
    this.listeners.set(type, [
      ...(this.listeners.get(type) || []),
      listener,
    ]);
  }

  requestSubmit(submitter) {
    const event = {
      type: 'submit',
      target: this,
      submitter,
      defaultPrevented: false,
      preventDefault() {
        this.defaultPrevented = true;
      },
    };
    (this.listeners.get('submit') || []).forEach((listener) => listener(event));
    if (this.shell) {
      this.shell.dispatch('submit', event);
    }
    if (!event.defaultPrevented) {
      this.nativeSubmitCount += 1;
    }
    return event;
  }

  closest(selector) {
    return selector === '[data-webadmin-shell]' ? this.shell : null;
  }
}

class FakeShell extends FakeElement {
  constructor() {
    super('', '');
    this.listeners = new Map();
  }

  addEventListener(type, listener) {
    this.listeners.set(type, [
      ...(this.listeners.get(type) || []),
      listener,
    ]);
  }

  dispatch(type, event) {
    (this.listeners.get(type) || []).forEach((listener) => listener(event));
  }
}

class FakeAnchor extends FakeElement {
  constructor(href, attributes = {}) {
    super('', '');
    this.href = href;
    this.attributes = { href, ...attributes };
  }

  closest(selector) {
    return selector === 'a[href]' ? this : null;
  }
}

class FakeChild extends FakeElement {
  constructor(anchor) {
    super('', '');
    this.anchor = anchor;
  }

  closest(selector) {
    return selector === 'a[href]' ? this.anchor : null;
  }
}

class FakeFormData {
  constructor(form) {
    this.values = form.controls
      .filter((control) => control.name && !control.disabled)
      .filter((control) => (
        !['checkbox', 'radio'].includes(control.type) || control.checked
      ))
      .map((control) => [control.name, control.value]);
  }

  forEach(callback) {
    this.values.forEach(([name, value]) => callback(value, name));
  }
}

globalThis.Element = FakeElement;
globalThis.HTMLElement = FakeElement;
globalThis.HTMLAnchorElement = FakeAnchor;
globalThis.HTMLInputElement = FakeInput;
globalThis.HTMLTextAreaElement = FakeInput;
globalThis.HTMLButtonElement = FakeButton;
globalThis.HTMLFormElement = FakeForm;
globalThis.FormData = FakeFormData;
globalThis.__blogActionAnnouncements = [];
globalThis.__blogNavigationChoice = 'save';
globalThis.__blogNavigationRequests = [];

let forms = [];
globalThis.document = {
  readyState: 'loading',
  addEventListener() {},
  querySelectorAll(selector) {
    return selector === 'form' ? forms : [];
  },
};
const navigations = [];
globalThis.window = {
  crypto: {
    subtle: {
      digest(_algorithm, input) {
        const digest = createHash('sha256').update(Buffer.from(input)).digest();
        return Promise.resolve(digest.buffer.slice(
          digest.byteOffset,
          digest.byteOffset + digest.byteLength,
        ));
      },
    },
  },
  location: {
    href: 'http://localhost/admin/blog/editor?post=post-a&locale=es',
    origin: 'http://localhost',
    assign(href) {
      navigations.push(href);
    },
  },
  requestAnimationFrame(callback) {
    callback();
  },
};

vm.runInThisContext(source, { filename: asset });
const hooks = globalThis.__blogActionBarHooks;

const uuid = (number) => '82000000-0000-4000-8000-'
  + String(number).padStart(12, '0');
const richText = (text) => [{ type: 'text', text, marks: [] }];
const presentation = () => ({
  width: 'full',
  align: 'start',
  text_align: 'start',
  size: 'm',
  font_weight: 'default',
  text_color: 'default',
});
const documentValue = (text) => ({
  schema: 'liquidstack.blog.document',
  version: 2,
  template: 'article-basic-01',
  blocks: [{
    id: uuid(1),
    type: 'section',
    children: [{
      id: uuid(2),
      type: 'heading',
      level: 2,
      content: richText(text),
      presentation: presentation(),
    }],
  }],
});

assert.equal(hooks.validV2DraftDocument(documentValue('Initial')), true);

const textFlowDocument = documentValue('Text flow section');
textFlowDocument.blocks[0].children.push({
  id: uuid(3),
  type: 'paragraph',
  content: [{
    type: 'paragraph',
    content: richText('Text flow content'),
  }],
  presentation: presentation(),
});
const textFlowContext = contextFor(textFlowDocument);
assert.equal(
  hooks.inlinePlainText(textFlowDocument.blocks[0].children[1].content),
  'Text flow content ',
);
assert.equal(hooks.firstDocumentTechnicalIssue(textFlowContext), null);
assert.equal(hooks.validV2DraftDocument(textFlowDocument), true);

function technicalContext(document) {
  return {
    documentInput: { value: JSON.stringify(document) },
    documentValue: document,
    technicalLimits: {
      document: { document_json: { bytes: 300000 } },
      block: {
        content: { bytes: 20000 },
        text_content: { bytes: 200000 },
      },
    },
  };
}

const splitInline = (left, right) => [
  { type: 'text', text: left.repeat(11000), marks: [] },
  { type: 'text', text: right.repeat(11000), marks: ['strong'] },
];
const semanticLimitsDocument = documentValue('Semantic limits');
semanticLimitsDocument.blocks[0].children[0].content = splitInline('a', 'b');
semanticLimitsDocument.blocks[0].children.push({
  id: uuid(3),
  type: 'paragraph',
  content: [{
    type: 'heading',
    level: 2,
    content: splitInline('c', 'd'),
  }, {
    type: 'break',
  }, {
    type: 'list',
    ordered: false,
    items: [{
      id: uuid(4),
      content: splitInline('e', 'f'),
    }],
  }],
  presentation: presentation(),
});
assert.equal(
  hooks.firstDocumentTechnicalIssue(
    technicalContext(semanticLimitsDocument),
  ),
  null,
);

const oversizedLeafDocument = structuredClone(semanticLimitsDocument);
oversizedLeafDocument.blocks[0].children[0].content[0].text = 'x'.repeat(20001);
assert.deepEqual(
  hooks.firstDocumentTechnicalIssue(
    technicalContext(oversizedLeafDocument),
  ),
  {
    scope: 'block',
    field: 'content',
    block_id: uuid(2),
    code: 'bytes_exceeded',
    limit: 20000,
  },
);

const aggregateDocument = documentValue('Aggregate limits');
aggregateDocument.blocks[0].children.push({
  id: uuid(5),
  type: 'paragraph',
  content: Array.from({ length: 11 }, () => ({
    type: 'paragraph',
    content: richText('z'.repeat(19000)),
  })),
  presentation: presentation(),
});
assert.deepEqual(
  hooks.firstDocumentTechnicalIssue(technicalContext(aggregateDocument)),
  {
    scope: 'block',
    field: 'text_content',
    block_id: uuid(5),
    code: 'bytes_exceeded',
    limit: 200000,
  },
);

function response(payload, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    redirected: false,
    url: 'http://localhost/admin/blog/editor/save',
    headers: {
      get(name) {
        return name.toLowerCase() === 'content-type'
          ? 'application/json; charset=utf-8'
          : null;
      },
    },
    json() {
      return Promise.resolve(payload);
    },
  };
}

function savedPayload(document, lockVersion) {
  const savedDocument = structuredClone(document);
  hooks.normalizeUnifiedTextModules(savedDocument);
  return {
    ok: true,
    lock_version: lockVersion,
    document_sha256: createHash('sha256')
      .update(JSON.stringify(savedDocument))
      .digest('hex'),
    document: savedDocument,
  };
}

function contextFor(document) {
  const saveButton = new FakeButton();
  const layoutRadio = new FakeInput(
    'blog-editor-1-v2-canvas-preset-' + uuid(90),
    '2-50-50',
    'radio',
  );
  layoutRadio.checked = true;
  const controls = [
    new FakeInput('csrf', 'csrf-token'),
    new FakeInput('post', 'post-a'),
    new FakeInput('locale', 'es'),
    new FakeInput('lock_version', '3'),
    new FakeInput('document_json', JSON.stringify(document)),
    layoutRadio,
    saveButton,
  ];
  const form = new FakeForm(controls);
  const shell = new FakeShell();
  form.shell = shell;
  forms = [form];
  const context = {
    form,
    documentInput: form.elements.namedItem('document_json'),
    documentValue: document,
    initialFingerprint: '',
    readOnly: false,
    savePending: false,
    savePromise: null,
    saveHasPendingChanges: false,
    previewPending: false,
    allowNavigation: false,
    navigationPending: false,
    richEditor: null,
    shell,
    saveButton,
    layoutRadio,
    layoutEditorReady: true,
    entryLimitControls: [],
    technicalLimits: {
      document: { document_json: { bytes: 300000 } },
      block: {
        content: { bytes: 20000 },
        text_content: { bytes: 200000 },
        label: { bytes: 255 },
        alt: { bytes: 500 },
        title: { bytes: 500 },
        caption: { bytes: 2000 },
        href: { bytes: 2048 },
        html: { bytes: 50000 },
        css: { bytes: 30000 },
      },
    },
  };
  hooks.sync(context);
  context.initialFingerprint = hooks.editorialFormFingerprint(form);
  return context;
}

const filteredPayload = contextFor(documentValue('Filtered payload'));
assert.equal(
  hooks.formBody(filteredPayload.form).get(filteredPayload.layoutRadio.name),
  '2-50-50',
);
assert.equal(
  hooks.editorFormBody(filteredPayload.form).has(
    filteredPayload.layoutRadio.name,
  ),
  false,
);
const filteredFingerprint = hooks.editorialFormFingerprint(
  filteredPayload.form,
);
filteredPayload.layoutRadio.value = '5';
assert.equal(
  hooks.editorialFormFingerprint(filteredPayload.form),
  filteredFingerprint,
);

function previewStateFor(context) {
  const frame = new FakeElement();
  frame.contentWindow = null;
  frame.contentDocument = null;
  frame._src = '';
  Object.defineProperty(frame, 'src', {
    get() {
      return this._src;
    },
    set(value) {
      this._src = String(value);
      this.setAttribute('src', value);
    },
  });
  const devices = ['desktop', 'tablet', 'mobile'].map((device) => {
    const button = new FakeButton();
    button.type = 'button';
    button.dataset.blogPreviewDevice = device;
    return button;
  });
  const save = new FakeButton();
  save.type = 'button';
  save.textContent = 'Guardar borrador';
  const close = new FakeButton();
  close.type = 'button';
  close.textContent = 'Volver al editor';
  return {
    context,
    dialog: { open: true },
    stage: new FakeElement(),
    frame,
    loaded: false,
    url: 'http://localhost/admin/blog/editor/preview?post=post-a&locale=es',
    trigger: new FakeAnchor(
      'http://localhost/admin/blog/editor/preview?post=post-a&locale=es',
    ),
    status: new FakeElement(),
    save,
    publish: null,
    close,
    deviceButtons: devices,
    publishForm: null,
    pending: false,
    pendingPromise: null,
  };
}

function installPreviewDocument(state, options = {}) {
  const href = options.href || state.url;
  const markerValue = Object.hasOwn(options, 'markerValue')
    ? options.markerValue
    : 'true';
  const root = new FakeElement();
  root.tagName = 'HTML';
  if (markerValue !== null) {
    root.setAttribute('data-blog-preview-ready', markerValue);
  }
  state.frame.contentWindow = { location: { href } };
  state.frame.contentDocument = {
    documentElement: root,
    status: options.status || 200,
  };
}

function installCrossOriginPreview(state) {
  Object.defineProperty(state.frame, 'contentWindow', {
    configurable: true,
    get() {
      throw new Error('cross-origin-window');
    },
  });
  Object.defineProperty(state.frame, 'contentDocument', {
    configurable: true,
    get() {
      throw new Error('cross-origin-document');
    },
  });
}

function prepareLoadedPreviewAttempt(context, state) {
  state.pending = true;
  context.previewPending = true;
  state.frame.src = state.url;
  state.save.disabled = true;
  state.deviceButtons.forEach((button) => {
    button.disabled = true;
  });
}

function assertRejectedPreview(context, state) {
  assert.equal(state.frame.getAttribute('src'), null);
  assert.equal(state.loaded, false);
  assert.equal(state.pending, false);
  assert.equal(context.previewPending, false);
  assert.equal(state.status.dataset.state, 'error');
  assert.doesNotMatch(state.status.textContent, /actualizada/u);
  assert.equal(state.save.textContent, 'Reintentar guardado');
  assert.equal(state.save.disabled, false);
  assert.equal(state.deviceButtons.every((button) => button.disabled), true);
}

let fetchCount = 0;
const cleanSubmit = contextFor(documentValue('Clean submit'));
window.fetch = () => {
  fetchCount += 1;
  return Promise.resolve(response(savedPayload(
    structuredClone(cleanSubmit.documentValue),
    4,
  )));
};
hooks.v2BindSave(cleanSubmit);
assert.equal(cleanSubmit.saveButton.getAttribute('form'), cleanSubmit.form.id);
assert.equal(cleanSubmit.saveButton.form, cleanSubmit.form);
const cleanSubmitEvent = cleanSubmit.saveButton.click();
assert.equal(cleanSubmitEvent.defaultPrevented, true);
assert.equal(cleanSubmit.form.nativeSubmitCount, 0);
assert.equal(await cleanSubmit.savePromise, true);
assert.equal(hooks.v2HasUnsavedChanges(cleanSubmit), false);

const dirtySubmit = contextFor(documentValue('Dirty submit'));
edit(dirtySubmit, 'Dirty submit changed');
window.fetch = () => {
  fetchCount += 1;
  return Promise.resolve(response(savedPayload(
    structuredClone(dirtySubmit.documentValue),
    4,
  )));
};
hooks.v2BindSave(dirtySubmit);
const dirtySubmitEvent = dirtySubmit.saveButton.click();
assert.equal(dirtySubmitEvent.defaultPrevented, true);
assert.equal(dirtySubmit.form.nativeSubmitCount, 0);
assert.equal(await dirtySubmit.savePromise, true);
assert.equal(hooks.v2HasUnsavedChanges(dirtySubmit), false);

const failedSubmit = contextFor(documentValue('Failed submit'));
edit(failedSubmit, 'Failed submit changed');
window.fetch = () => Promise.reject(new Error('offline'));
hooks.v2BindSave(failedSubmit);
const failedSubmitEvent = failedSubmit.saveButton.click();
assert.equal(failedSubmitEvent.defaultPrevented, true);
assert.equal(failedSubmit.form.nativeSubmitCount, 0);
assert.equal(await failedSubmit.savePromise, false);
assert.equal(hooks.v2HasUnsavedChanges(failedSubmit), true);

const throwingSubmit = contextFor(documentValue('Throwing submit'));
edit(throwingSubmit, 'Throwing submit changed');
throwingSubmit.throwDuringSaveValidation = true;
let throwingFetches = 0;
window.fetch = () => {
  throwingFetches += 1;
  throw new Error('fetch must not run after validation throws');
};
hooks.v2BindSave(throwingSubmit);
const announcementsBeforeThrow = __blogActionAnnouncements.length;
const throwingSubmitEvent = throwingSubmit.saveButton.click();
assert.equal(throwingSubmitEvent.defaultPrevented, true);
assert.equal(throwingSubmit.form.nativeSubmitCount, 0);
assert.equal(throwingFetches, 0);
assert.equal(throwingSubmit.saveHasPendingChanges, true);
assert.equal(
  __blogActionAnnouncements.length,
  announcementsBeforeThrow + 1,
);
assert.equal(__blogActionAnnouncements.at(-1).error, true);

const fallbackSubmit = contextFor(documentValue('Fallback submit'));
delete window.fetch;
hooks.v2BindSave(fallbackSubmit);
const fallbackSubmitEvent = fallbackSubmit.saveButton.click();
assert.equal(fallbackSubmitEvent.defaultPrevented, false);
assert.equal(fallbackSubmit.form.nativeSubmitCount, 1);
assert.equal(fallbackSubmit.allowNavigation, true);

fetchCount = 0;
const success = contextFor(documentValue('Initial'));
success.documentValue.blocks[0].children[0].content = richText('Saved');
hooks.sync(success);
let successRequestBody = '';
window.fetch = (_url, options) => {
  fetchCount += 1;
  successRequestBody = options.body;
  return Promise.resolve(response(savedPayload(
    structuredClone(success.documentValue),
    4,
  )));
};

assert.equal(await hooks.v2SaveDraft(success), true);
assert.equal(
  new URLSearchParams(successRequestBody).has(success.layoutRadio.name),
  false,
);
assert.equal(success.saveHasPendingChanges, false);
assert.equal(
  hooks.editorialFormFingerprint(success.form),
  success.initialFingerprint,
);
assert.equal(success.form.elements.namedItem('lock_version').value, '4');
assert.equal(await hooks.v2PrepareSavedPreview(success), true);
assert.equal(fetchCount, 1, 'a clean preview must not save a second time');

const previewState = {
  loaded: false,
  url: 'http://localhost/admin/blog/editor/preview?post=post-a&locale=es',
  frame: { src: '', contentWindow: null },
};
hooks.v2LoadPreview(previewState);
assert.equal(previewState.frame.src, previewState.url);

const pending = contextFor(documentValue('Initial'));
pending.documentValue.blocks[0].children[0].content = richText('Submitted');
hooks.sync(pending);
let resolvePending;
window.fetch = () => new Promise((resolve) => {
  resolvePending = resolve;
});
const pendingSave = hooks.v2SaveDraft(pending);
pending.documentValue.blocks[0].children[0].content = richText('Later');
hooks.sync(pending);
resolvePending(response(savedPayload(documentValue('Submitted'), 4)));
assert.equal(await pendingSave, false);
assert.equal(pending.saveHasPendingChanges, true);
assert.notEqual(
  hooks.editorialFormFingerprint(pending.form),
  pending.initialFingerprint,
);

const failed = contextFor(documentValue('Initial'));
failed.documentValue.blocks[0].children[0].content = richText('Fails');
hooks.sync(failed);
const failedBaseline = failed.initialFingerprint;
window.fetch = () => Promise.reject(new Error('offline'));
assert.equal(await hooks.v2SaveDraft(failed), false);
assert.equal(failed.initialFingerprint, failedBaseline);
assert.equal(failed.saveHasPendingChanges, true);
assert.notEqual(
  hooks.editorialFormFingerprint(failed.form),
  failed.initialFingerprint,
);
assert.equal(await hooks.v2PrepareSavedPreview(failed), false);

const dirtyPreviewContext = contextFor(documentValue('Preview initial'));
edit(dirtyPreviewContext, 'Preview dirty');
const dirtyPreviewState = previewStateFor(dirtyPreviewContext);
let resolveDirtyPreview;
let dirtyPreviewFetches = 0;
window.fetch = () => {
  dirtyPreviewFetches += 1;
  return new Promise((resolve) => {
    resolveDirtyPreview = resolve;
  });
};
const dirtyPreviewPromise = hooks.v2RefreshPreview(
  dirtyPreviewContext,
  dirtyPreviewState,
  false,
);
assert.equal(dirtyPreviewState.dialog.open, true);
assert.equal(dirtyPreviewState.status.textContent, 'Guardando borrador…');
assert.equal(dirtyPreviewState.status.dataset.state, 'pending');
assert.equal(dirtyPreviewState.frame.getAttribute('src'), null);
assert.equal(dirtyPreviewState.save.disabled, true);
assert.equal(dirtyPreviewState.deviceButtons.every((button) => button.disabled), true);
assert.equal(dirtyPreviewState.trigger.getAttribute('aria-busy'), 'true');
assert.equal(
  hooks.v2RefreshPreview(dirtyPreviewContext, dirtyPreviewState, false),
  dirtyPreviewPromise,
  'a second activation must reuse the pending preview operation',
);
await Promise.resolve();
assert.equal(dirtyPreviewFetches, 1);
assert.equal(dirtyPreviewState.frame.getAttribute('src'), null);
resolveDirtyPreview(response(savedPayload(
  structuredClone(dirtyPreviewContext.documentValue),
  4,
)));
assert.equal(await dirtyPreviewPromise, true);
assert.equal(dirtyPreviewState.frame.getAttribute('src'), dirtyPreviewState.url);
assert.equal(dirtyPreviewState.pending, true);
installPreviewDocument(dirtyPreviewState);
assert.equal(
  hooks.v2PreviewDidLoad(dirtyPreviewContext, dirtyPreviewState),
  true,
);
assert.equal(dirtyPreviewState.pending, false);
assert.equal(dirtyPreviewContext.previewPending, false);
assert.equal(dirtyPreviewState.status.dataset.state, 'ok');
assert.equal(dirtyPreviewState.save.disabled, false);

const unavailableResponseError = await hooks.v2ReadSaveResponse(response({
  ok: false,
  error: 'unavailable',
}, 503)).then(
  () => null,
  (error) => error,
);
assert.equal(unavailableResponseError.saveCode, 'unavailable');

const unavailablePreviewContext = contextFor(documentValue('Preview 503'));
const unavailablePreviewState = previewStateFor(unavailablePreviewContext);
prepareLoadedPreviewAttempt(unavailablePreviewContext, unavailablePreviewState);
installPreviewDocument(unavailablePreviewState, {
  markerValue: null,
  status: 503,
});
assert.equal(
  hooks.v2PreviewDidLoad(
    unavailablePreviewContext,
    unavailablePreviewState,
  ),
  false,
);
assertRejectedPreview(unavailablePreviewContext, unavailablePreviewState);

const loginPreviewContext = contextFor(documentValue('Preview login'));
const loginPreviewState = previewStateFor(loginPreviewContext);
prepareLoadedPreviewAttempt(loginPreviewContext, loginPreviewState);
installPreviewDocument(loginPreviewState, {
  href: 'http://localhost/admin/login',
});
assert.equal(
  hooks.v2PreviewDidLoad(loginPreviewContext, loginPreviewState),
  false,
);
assertRejectedPreview(loginPreviewContext, loginPreviewState);

const missingMarkerContext = contextFor(documentValue('Preview no marker'));
const missingMarkerState = previewStateFor(missingMarkerContext);
prepareLoadedPreviewAttempt(missingMarkerContext, missingMarkerState);
installPreviewDocument(missingMarkerState, { markerValue: null });
assert.equal(
  hooks.v2PreviewDidLoad(missingMarkerContext, missingMarkerState),
  false,
);
assertRejectedPreview(missingMarkerContext, missingMarkerState);

const crossOriginPreviewContext = contextFor(documentValue('Preview cross origin'));
const crossOriginPreviewState = previewStateFor(crossOriginPreviewContext);
prepareLoadedPreviewAttempt(crossOriginPreviewContext, crossOriginPreviewState);
installCrossOriginPreview(crossOriginPreviewState);
assert.equal(
  hooks.v2PreviewDidLoad(
    crossOriginPreviewContext,
    crossOriginPreviewState,
  ),
  false,
);
assertRejectedPreview(crossOriginPreviewContext, crossOriginPreviewState);

const failedPreviewContext = contextFor(documentValue('Preview failure'));
edit(failedPreviewContext, 'Preview failure dirty');
const failedPreviewState = previewStateFor(failedPreviewContext);
window.fetch = () => Promise.reject(new Error('offline'));
assert.equal(
  await hooks.v2RefreshPreview(
    failedPreviewContext,
    failedPreviewState,
    false,
  ),
  false,
);
assert.equal(failedPreviewState.dialog.open, true);
assert.equal(failedPreviewState.frame.getAttribute('src'), null);
assert.equal(failedPreviewState.status.dataset.state, 'error');
assert.equal(failedPreviewState.status.getAttribute('aria-live'), 'assertive');
assert.match(failedPreviewState.status.textContent, /No se pudo/);
assert.equal(failedPreviewState.save.textContent, 'Reintentar guardado');
assert.equal(failedPreviewState.save.disabled, false);
assert.equal(failedPreviewState.close.textContent, 'Volver al editor');

function edit(context, text) {
  context.documentValue.blocks[0].children[0].content = richText(text);
  hooks.sync(context);
}

function logoutFormFor(context) {
  const submit = new FakeButton();
  const form = new FakeForm([
    new FakeInput('csrf', 'logout-csrf'),
    submit,
  ], ['webadminShell-logout']);
  form.id = 'webadmin-logout-form';
  form.action = 'http://localhost/admin/logout';
  submit.setAttribute('form', form.id);
  form.shell = context.shell;
  return { form, submit };
}

function eventFor(target, overrides = {}) {
  return {
    target,
    button: 0,
    ctrlKey: false,
    metaKey: false,
    shiftKey: false,
    altKey: false,
    detail: 1,
    defaultPrevented: false,
    preventDefault() {
      this.defaultPrevented = true;
    },
    ...overrides,
  };
}

const revisionUrl = 'http://localhost/admin/blog/editor/revisions'
  + '?post=post-a&locale=es';
const flush = () => new Promise((resolve) => setImmediate(resolve));

const cleanNavigation = contextFor(documentValue('Clean'));
hooks.v2BindInternalNavigation(cleanNavigation);
const cleanEvent = eventFor(new FakeAnchor(revisionUrl));
const fetchesBeforeCleanNavigation = fetchCount;
cleanNavigation.shell.dispatch('click', cleanEvent);
await flush();
assert.equal(cleanEvent.defaultPrevented, false);
assert.equal(fetchCount, fetchesBeforeCleanNavigation);
assert.deepEqual(navigations, []);

const dirtyNavigation = contextFor(documentValue('Initial'));
edit(dirtyNavigation, 'Dirty navigation');
__blogNavigationChoice = 'save';
let navigationFetches = 0;
window.fetch = () => {
  navigationFetches += 1;
  return Promise.resolve(response(savedPayload(
    structuredClone(dirtyNavigation.documentValue),
    4,
  )));
};
hooks.v2BindInternalNavigation(dirtyNavigation);
const dirtyEvent = eventFor(new FakeChild(new FakeAnchor(revisionUrl)));
dirtyNavigation.shell.dispatch('click', dirtyEvent);
assert.equal(dirtyEvent.defaultPrevented, true);
await flush();
assert.equal(navigationFetches, 1);
assert.equal(__blogNavigationRequests.at(-1).canSave, true);
assert.equal(
  __blogNavigationRequests.at(-1).trigger instanceof FakeAnchor,
  true,
);
assert.deepEqual(navigations, [revisionUrl]);
assert.equal(dirtyNavigation.allowNavigation, true);
assert.equal(hooks.v2HasUnsavedChanges(dirtyNavigation), false);

navigations.length = 0;
const pendingNavigation = contextFor(documentValue('Initial'));
edit(pendingNavigation, 'Pending navigation');
let resolveNavigationSave;
window.fetch = () => new Promise((resolve) => {
  resolveNavigationSave = resolve;
});
const activeSave = hooks.v2SaveDraft(pendingNavigation);
hooks.v2BindInternalNavigation(pendingNavigation);
const pendingEvent = eventFor(new FakeAnchor(revisionUrl));
pendingNavigation.shell.dispatch('click', pendingEvent);
assert.equal(pendingEvent.defaultPrevented, true);
assert.equal(pendingNavigation.navigationPending, true);
resolveNavigationSave(response(savedPayload(
  structuredClone(pendingNavigation.documentValue),
  4,
)));
assert.equal(await activeSave, true);
await flush();
assert.deepEqual(navigations, [revisionUrl]);

navigations.length = 0;
const failedNavigation = contextFor(documentValue('Initial'));
edit(failedNavigation, 'Failed navigation');
window.fetch = () => Promise.reject(new Error('offline'));
hooks.v2BindInternalNavigation(failedNavigation);
const failedEvent = eventFor(new FakeAnchor(revisionUrl));
failedNavigation.shell.dispatch('click', failedEvent);
assert.equal(failedEvent.defaultPrevented, true);
await flush();
assert.deepEqual(navigations, []);
assert.equal(failedNavigation.navigationPending, false);
assert.equal(hooks.v2HasUnsavedChanges(failedNavigation), true);

const keyboardNavigation = contextFor(documentValue('Initial'));
edit(keyboardNavigation, 'Keyboard navigation');
window.fetch = () => Promise.resolve(response(savedPayload(
  structuredClone(keyboardNavigation.documentValue),
  4,
)));
hooks.v2BindInternalNavigation(keyboardNavigation);
const keyboardEvent = eventFor(
  new FakeAnchor(revisionUrl),
  { detail: 0 },
);
keyboardNavigation.shell.dispatch('click', keyboardEvent);
assert.equal(keyboardEvent.defaultPrevented, true);
await flush();
assert.deepEqual(navigations, [revisionUrl]);

navigations.length = 0;
const fallbackNavigation = contextFor(documentValue('Initial'));
edit(fallbackNavigation, 'Native fallback');
delete window.fetch;
__blogNavigationChoice = 'stay';
hooks.v2BindInternalNavigation(fallbackNavigation);
const fallbackEvent = eventFor(new FakeAnchor(revisionUrl));
fallbackNavigation.shell.dispatch('click', fallbackEvent);
assert.equal(fallbackEvent.defaultPrevented, true);
await flush();
assert.deepEqual(navigations, []);
assert.equal(__blogNavigationRequests.at(-1).canSave, false);

const discardNavigation = contextFor(documentValue('Initial'));
edit(discardNavigation, 'Discard navigation');
__blogNavigationChoice = 'discard';
hooks.v2BindInternalNavigation(discardNavigation);
const discardEvent = eventFor(new FakeAnchor(revisionUrl));
discardNavigation.shell.dispatch('click', discardEvent);
assert.equal(discardEvent.defaultPrevented, true);
await flush();
assert.deepEqual(navigations, [revisionUrl]);

const cleanLogout = contextFor(documentValue('Clean logout'));
const cleanLogoutForm = logoutFormFor(cleanLogout);
__blogNavigationChoice = 'save';
hooks.v2BindInternalNavigation(cleanLogout);
const cleanLogoutEvent = cleanLogoutForm.submit.click();
assert.equal(cleanLogoutEvent.defaultPrevented, false);
assert.equal(cleanLogoutForm.form.nativeSubmitCount, 1);

const savedLogout = contextFor(documentValue('Saved logout'));
edit(savedLogout, 'Saved logout changed');
const savedLogoutForm = logoutFormFor(savedLogout);
let logoutSaveFetches = 0;
window.fetch = () => {
  logoutSaveFetches += 1;
  return Promise.resolve(response(savedPayload(
    structuredClone(savedLogout.documentValue),
    4,
  )));
};
__blogNavigationChoice = 'save';
hooks.v2BindInternalNavigation(savedLogout);
const savedLogoutEvent = savedLogoutForm.submit.click();
assert.equal(savedLogoutEvent.defaultPrevented, true);
await flush();
assert.equal(logoutSaveFetches, 1);
assert.equal(savedLogoutForm.form.nativeSubmitCount, 1);
assert.equal(hooks.v2HasUnsavedChanges(savedLogout), false);

const failedLogout = contextFor(documentValue('Failed logout'));
edit(failedLogout, 'Failed logout changed');
const failedLogoutForm = logoutFormFor(failedLogout);
window.fetch = () => Promise.reject(new Error('offline'));
__blogNavigationChoice = 'save';
hooks.v2BindInternalNavigation(failedLogout);
const failedLogoutEvent = failedLogoutForm.submit.click();
assert.equal(failedLogoutEvent.defaultPrevented, true);
await flush();
assert.equal(failedLogoutForm.form.nativeSubmitCount, 0);
assert.equal(hooks.v2HasUnsavedChanges(failedLogout), true);
assert.equal(failedLogout.navigationPending, false);

const stayedLogout = contextFor(documentValue('Stayed logout'));
edit(stayedLogout, 'Stayed logout changed');
const stayedLogoutForm = logoutFormFor(stayedLogout);
delete window.fetch;
__blogNavigationChoice = 'stay';
hooks.v2BindInternalNavigation(stayedLogout);
const stayedLogoutEvent = stayedLogoutForm.submit.click();
assert.equal(stayedLogoutEvent.defaultPrevented, true);
await flush();
assert.equal(stayedLogoutForm.form.nativeSubmitCount, 0);
assert.equal(stayedLogout.navigationPending, false);

const discardedLogout = contextFor(documentValue('Discarded logout'));
edit(discardedLogout, 'Discarded logout changed');
const discardedLogoutForm = logoutFormFor(discardedLogout);
delete window.fetch;
__blogNavigationChoice = 'discard';
hooks.v2BindInternalNavigation(discardedLogout);
const discardedLogoutEvent = discardedLogoutForm.submit.click();
assert.equal(discardedLogoutEvent.defaultPrevented, true);
await flush();
assert.equal(discardedLogoutForm.form.nativeSubmitCount, 1);
assert.equal(discardedLogoutForm.form.method, 'post');
assert.equal(
  discardedLogoutForm.form.elements.namedItem('csrf').value,
  'logout-csrf',
);

const unrelatedPost = contextFor(documentValue('Unrelated POST'));
edit(unrelatedPost, 'Unrelated POST changed');
const unrelatedSubmit = new FakeButton();
const unrelatedForm = new FakeForm([
  new FakeInput('csrf', 'unrelated-csrf'),
  unrelatedSubmit,
]);
unrelatedForm.shell = unrelatedPost.shell;
__blogNavigationChoice = 'stay';
hooks.v2BindInternalNavigation(unrelatedPost);
const unrelatedEvent = unrelatedSubmit.click();
assert.equal(unrelatedEvent.defaultPrevented, false);
assert.equal(unrelatedForm.nativeSubmitCount, 1);

__blogNavigationChoice = 'save';
navigations.length = 0;
const excludedNavigation = contextFor(documentValue('Initial'));
edit(excludedNavigation, 'Excluded links');
let excludedFetches = 0;
window.fetch = () => {
  excludedFetches += 1;
  throw new Error('excluded navigation must not fetch');
};
hooks.v2BindInternalNavigation(excludedNavigation);
[
  eventFor(new FakeAnchor('https://example.com/revisions')),
  eventFor(new FakeAnchor(revisionUrl, { download: '' })),
  eventFor(new FakeAnchor(revisionUrl, { target: '_blank' })),
  eventFor(new FakeAnchor(revisionUrl, { rel: 'external' })),
  eventFor(new FakeAnchor(revisionUrl, {
    'data-blog-editor-preview': '',
  })),
  eventFor(new FakeAnchor(revisionUrl), { ctrlKey: true }),
  eventFor(new FakeButton()),
].forEach((event) => {
  excludedNavigation.shell.dispatch('click', event);
  assert.equal(event.defaultPrevented, false);
});
assert.equal(excludedFetches, 0);

process.stdout.write(JSON.stringify({
  textFlowTechnicalProjectionStaysValid: true,
  semanticTechnicalLimitsMatchDomain: true,
  genericFormPayloadRemainsUnfiltered: true,
  editorPayloadIgnoresLayoutControls: true,
  editorFingerprintIgnoresLayoutControls: true,
  savePayloadIgnoresLayoutControls: true,
  externalSaveButtonUsesAssociatedForm: true,
  cleanSubmitStaysInEditor: true,
  dirtySubmitStaysInEditor: true,
  failedSubmitStaysInEditor: true,
  thrownValidationCannotEscapeSubmit: true,
  noFetchSubmitKeepsSsrFallback: true,
  successBaselineClean: true,
  previewUsesSavedSnapshot: true,
  dirtyPreviewOpensBeforeSaveAndWaitsFor200: true,
  validPreviewRequiresMarker: true,
  preview503CannotBecomeReady: true,
  loginRedirectCannotBecomeReady: true,
  missingMarkerCannotBecomeReady: true,
  crossOriginPreviewCannotBecomeReady: true,
  backendUnavailableUsesClientAllowlist: true,
  failedPreviewStaysRecoverableInDialog: true,
  pendingPreviewBlocksDoubleActivation: true,
  inFlightEditRemainsDirty: true,
  failedSaveRemainsDirty: true,
  cleanNavigationUsesNativePath: true,
  dirtyNavigationSavesFirst: true,
  pendingNavigationAwaitsSave: true,
  failedNavigationStaysInEditor: true,
  keyboardNavigationSavesFirst: true,
  noFetchNavigationOffersCustomChoice: true,
  discardNavigationLeavesWithoutSaving: true,
  cleanLogoutKeepsNativePost: true,
  dirtyLogoutCanSaveThenPostOnce: true,
  failedLogoutStaysInEditor: true,
  dirtyLogoutCanStay: true,
  dirtyLogoutCanDiscardThenPostOnce: true,
  unrelatedPostFormsStayNative: true,
  excludedLinksStayNative: true,
}));
