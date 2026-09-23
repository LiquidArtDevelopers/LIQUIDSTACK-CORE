import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const projectRoot = resolve(
  import.meta.dirname,
  '..',
  '..',
  '..',
  'modules',
  'blog',
  'resources',
  'project',
);
const source = await readFile(
  resolve(projectRoot, 'src/js/resources/_moduleBlogFilters01.js'),
  'utf8',
);
const runtime = await import(
  `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`
);

class FakeTarget {
  constructor() {
    this.listeners = new Map();
  }

  addEventListener(type, listener, options = {}) {
    const listeners = this.listeners.get(type) ?? new Set();
    listeners.add(listener);
    this.listeners.set(type, listeners);
    options.signal?.addEventListener('abort', () => listeners.delete(listener), {
      once: true,
    });
  }

  dispatch(type, target = this) {
    let prevented = false;
    const event = {
      type,
      target,
      preventDefault() {
        prevented = true;
      },
    };
    for (const listener of this.listeners.get(type) ?? []) {
      listener(event);
    }
    return prevented;
  }

  dispatchEvent(event) {
    this.dispatch(event.type, this);
    return true;
  }
}

class FakeElement extends FakeTarget {
  constructor(ownerDocument = null) {
    super();
    this.ownerDocument = ownerDocument;
    this.dataset = {};
    this.attributes = new Map();
    this.hidden = false;
  }

  setAttribute(name, value) {
    this.attributes.set(name, String(value));
  }

  removeAttribute(name) {
    this.attributes.delete(name);
  }

  getAttribute(name) {
    return this.attributes.get(name) ?? null;
  }
}

class FakeControl {
  constructor({
    name,
    value = '',
    type = 'hidden',
    checked = false,
    disabled = false,
  }) {
    this.name = name;
    this.value = value;
    this.type = type;
    this.checked = checked;
    this.disabled = disabled;
    this.dataset = {
      minlengthMessage: 'Use at least two characters.',
    };
    this.parentNode = null;
    this.attributes = new Map();
  }

  get validationMessage() {
    return this.type === 'search' && this.value.length === 1
      ? 'Use at least two characters.'
      : '';
  }

  setAttribute(name, value) {
    this.attributes.set(name, String(value));
  }

  removeAttribute(name) {
    this.attributes.delete(name);
  }

  getAttribute(name) {
    return this.attributes.get(name) ?? null;
  }

  cloneNode() {
    return new FakeControl({
      name: this.name,
      value: this.value,
      type: this.type,
      checked: this.checked,
      disabled: this.disabled,
    });
  }

  remove() {
    this.parentNode?.removeControl(this);
  }
}

class FakeForm extends FakeElement {
  constructor(ownerDocument, id, controls = []) {
    super(ownerDocument);
    this.id = id;
    this.action = '/es/noticias';
    this.method = 'get';
    this.dataset.blogResultsTarget = '#catalog-results';
    this.status = {
      dataset: {
        message: 'Results updated',
        errorMessage: 'Could not update results. Try again.',
        state: 'idle',
      },
      textContent: '',
    };
    this.controls = [];
    for (const control of controls) {
      this.appendControl(control);
    }
  }

  get elements() {
    return this.controls;
  }

  get firstChild() {
    return this.controls[0] ?? null;
  }

  getAttribute(name) {
    if (name === 'method') {
      return this.method;
    }
    return super.getAttribute(name);
  }

  checkValidity() {
    return true;
  }

  querySelector(selector) {
    return selector === '[data-blog-filter-status]' ? this.status : null;
  }

  querySelectorAll(selector) {
    if (selector !== 'input[type="hidden"][name]') {
      return [];
    }
    return this.controls.filter((control) => control.type === 'hidden');
  }

  appendControl(control) {
    control.parentNode = this;
    this.controls.push(control);
  }

  insertBefore(control, anchor) {
    control.parentNode?.removeControl(control);
    control.parentNode = this;
    const index = anchor ? this.controls.indexOf(anchor) : -1;
    if (index < 0) {
      this.controls.push(control);
    } else {
      this.controls.splice(index, 0, control);
    }
  }

  removeControl(control) {
    const index = this.controls.indexOf(control);
    if (index >= 0) {
      this.controls.splice(index, 1);
    }
    control.parentNode = null;
  }

  successfulEntries() {
    return this.controls.flatMap((control) => {
      if (!control.name || control.disabled) {
        return [];
      }
      if (
        (control.type === 'checkbox' || control.type === 'radio')
        && !control.checked
      ) {
        return [];
      }
      return [[control.name, control.value]];
    });
  }
}

const control = (name, value, type = 'hidden', checked = false) => (
  new FakeControl({ name, value, type, checked })
);
const visibleControlsNamedForHarness = (form, name) => form.elements.filter(
  (item) => item.name === name && item.type !== 'hidden',
);
const valueOf = (form, name, type = null) => form.elements.find((item) => (
  item.name === name && (type === null || item.type === type)
));
const hiddenValues = (form, name) => form.elements
  .filter((item) => item.type === 'hidden' && item.name === name)
  .map((item) => item.value);
const flush = async () => {
  await new Promise((resolveTick) => setTimeout(resolveTick, 0));
  await new Promise((resolveTick) => setTimeout(resolveTick, 0));
};
const REQUEST_TIMEOUT_MS = 12_000;

const documentRef = new FakeTarget();
documentRef.nodeType = 9;
const view = new FakeTarget();
let currentHref = 'http://localhost:1309/es/noticias';
const nativeFallbacks = [];
view.location = {
  get href() {
    return currentHref;
  },
  set href(value) {
    currentHref = new URL(value, currentHref).href;
  },
  get origin() {
    return new URL(currentHref).origin;
  },
  assign(value) {
    nativeFallbacks.push(value);
  },
};
view.AbortController = AbortController;
view.FormData = class FakeFormData {
  constructor(form) {
    this.entries = form.successfulEntries();
  }

  *[Symbol.iterator]() {
    yield* this.entries;
  }
};
view.CustomEvent = class FakeCustomEvent {
  constructor(type, options) {
    this.type = type;
    this.detail = options.detail;
  }
};
const requestTimeouts = [];
const debounceTimeouts = [];
const QUERY_DEBOUNCE_MS = 350;
view.setTimeout = (callback, delay, ...args) => {
  if (delay === REQUEST_TIMEOUT_MS) {
    const timeout = {
      active: true,
      callback: () => callback(...args),
    };
    requestTimeouts.push(timeout);
    return timeout;
  }
  if (delay === QUERY_DEBOUNCE_MS) {
    const timeout = {
      active: true,
      callback: () => callback(...args),
    };
    debounceTimeouts.push(timeout);
    return timeout;
  }

  return setTimeout(callback, delay, ...args);
};
view.clearTimeout = (timeout) => {
  if (requestTimeouts.includes(timeout) || debounceTimeouts.includes(timeout)) {
    timeout.active = false;
    return;
  }
  clearTimeout(timeout);
};
const fireRequestTimeout = (timeout, allowCleared = false) => {
  assert.ok(timeout, 'a request timeout must have been scheduled');
  if (!allowCleared) {
    assert.equal(timeout.active, true, 'request timeout must still be active');
  }
  timeout.active = false;
  timeout.callback();
};
const fireDebounce = (timeout) => {
  assert.ok(timeout, 'a debounce must have been scheduled');
  assert.equal(timeout.active, true, 'only the latest debounce stays active');
  timeout.active = false;
  timeout.callback();
};
view.history = {
  pushState(state, title, href) {
    void state;
    void title;
    currentHref = new URL(href, currentHref).href;
  },
  replaceState(state, title, href) {
    void state;
    void title;
    currentHref = new URL(href, currentHref).href;
  },
};

const target = new FakeElement(documentRef);
target.innerHTML = '<p>Initial</p>';
const searchForm = new FakeForm(documentRef, 'moduleBlogSearch01-00', [
  control('q', 'Neo', 'search'),
  control('order', 'updated', 'select-one'),
]);
const categoryForm = new FakeForm(documentRef, 'moduleBlogCategoryBar01-00', [
  control('q', 'Neo'),
  control('order', 'updated'),
  control('category[]', 'matrix', 'checkbox'),
  control('category[]', 'zion', 'checkbox'),
  control('category_mode', 'any', 'select-one'),
]);
let currentForms = [searchForm, categoryForm];

const responseFormPair = (href) => {
  const url = new URL(href);
  const query = url.searchParams.get('q') ?? '';
  const order = url.searchParams.get('order') ?? 'newest';
  const categories = url.searchParams.getAll('category[]');
  const mode = url.searchParams.get('category_mode') ?? 'any';
  const searchControls = [
    ...categories.map((slug) => control('category[]', slug)),
    ...(categories.length > 0 ? [control('category_mode', mode)] : []),
    control('q', query, 'search'),
    control('order', order, 'select-one'),
  ];
  const categoryControls = [
    ...(query !== '' ? [control('q', query)] : []),
    control('order', order),
    control('category[]', 'matrix', 'checkbox', categories.includes('matrix')),
    control('category[]', 'zion', 'checkbox', categories.includes('zion')),
    control('category_mode', mode, 'select-one'),
  ];
  return [
    new FakeForm(documentRef, searchForm.id, searchControls),
    new FakeForm(documentRef, categoryForm.id, categoryControls),
  ];
};

const responseDocumentFor = (href) => {
  const forms = responseFormPair(href);
  const incomingTarget = new FakeElement(documentRef);
  const url = new URL(href);
  incomingTarget.innerHTML = `result:${url.searchParams.toString()}`;
  return {
    title: `Catalog ${url.search}`,
    querySelector(selector) {
      return selector === '#catalog-results' ? incomingTarget : null;
    },
    querySelectorAll(selector) {
      return selector === '[data-blog-filter-form]' ? forms : [];
    },
    getElementById(id) {
      return forms.find((form) => form.id === id) ?? null;
    },
  };
};
view.DOMParser = class FakeDOMParser {
  parseFromString(href) {
    return responseDocumentFor(href);
  }
};

const pending = [];
view.fetch = (href, options) => new Promise((resolveRequest, rejectRequest) => {
  pending.push({
    href,
    options,
    requestTimeout: requestTimeouts.at(-1),
    resolveRequest,
    rejectRequest,
  });
});
const resolvePending = async (request) => {
  request.resolveRequest({
    ok: true,
    status: 200,
    text: async () => request.href,
  });
  await flush();
};
const rejectPending = async (request) => {
  request.rejectRequest(new TypeError('Failed to fetch'));
  await flush();
};

documentRef.defaultView = view;
documentRef.title = 'Initial catalog';
documentRef.head = { appendChild() {} };
documentRef.importNode = (node) => node.cloneNode(true);
documentRef.createElement = (tagName) => {
  assert.equal(tagName, 'input');
  return control('', '');
};
documentRef.querySelectorAll = (selector) => (
  selector === '[data-blog-filter-form]' ? currentForms : []
);
documentRef.querySelector = (selector) => (
  selector === '#catalog-results' ? target : null
);
documentRef.getElementById = (id) => (
  currentForms.find((form) => form.id === id) ?? null
);

globalThis.window = view;
globalThis.document = documentRef;

let updateEvents = 0;
documentRef.addEventListener('liquidstack:blog-results-updated', () => {
  updateEvents += 1;
});

const cleanup = runtime.initModuleBlogFilters01(documentRef);
assert.equal(view.listeners.get('popstate')?.size, 1, 'one popstate per target');

const search = valueOf(searchForm, 'q', 'search');
const order = valueOf(searchForm, 'order', 'select-one');
const zion = categoryForm.elements.find((item) => (
  item.name === 'category[]'
  && item.type === 'checkbox'
  && item.value === 'zion'
));
const matrix = categoryForm.elements.find((item) => (
  item.name === 'category[]'
  && item.type === 'checkbox'
  && item.value === 'matrix'
));
const categoryMode = valueOf(categoryForm, 'category_mode', 'select-one');

// Secuencia 1: escribir y categorizar antes del debounce conserva la q nueva.
search.value = 'Trinity';
searchForm.dispatch('input', search);
assert.deepEqual(hiddenValues(categoryForm, 'q'), ['Trinity']);
assert.equal(pending.length, 0);
assert.equal(target.hidden, false, 'debounce keeps the current results visible');
assert.equal(target.getAttribute('aria-hidden'), null);
assert.equal(target.getAttribute('data-blog-results-stale'), 'true');
assert.equal(target.getAttribute('aria-busy'), 'true');
zion.checked = true;
assert.equal(categoryForm.dispatch('change', zion), false);
matrix.checked = true;
assert.equal(categoryForm.dispatch('change', matrix), false);
categoryMode.value = 'all';
assert.equal(categoryForm.dispatch('change', categoryMode), false);
assert.equal(pending.length, 0, 'rapid category changes stay inside debounce');
assert.equal(target.hidden, false);
assert.equal(target.getAttribute('aria-hidden'), null);
assert.equal(target.getAttribute('aria-busy'), 'true');
fireDebounce(debounceTimeouts.at(-1));
const latestCategory = pending.at(-1);
assert.equal(pending.length, 1, 'filters auto-apply in one combined request');
assert.equal(new URL(latestCategory.href).searchParams.get('q'), 'Trinity');
assert.deepEqual(
  new URL(latestCategory.href).searchParams.getAll('category[]'),
  ['matrix', 'zion'],
);
assert.equal(
  new URL(latestCategory.href).searchParams.get('category_mode'),
  'all',
);
assert.equal(searchForm.getAttribute('aria-busy'), 'true');
assert.equal(categoryForm.getAttribute('aria-busy'), 'true');
assert.equal(target.getAttribute('aria-busy'), 'true');
assert.equal(target.hidden, false);
assert.equal(target.getAttribute('aria-hidden'), null);
await resolvePending(latestCategory);
assert.deepEqual(hiddenValues(searchForm, 'category[]'), ['matrix', 'zion']);
assert.deepEqual(hiddenValues(searchForm, 'category_mode'), ['all']);
assert.equal(updateEvents, 1);
assert.equal(searchForm.getAttribute('aria-busy'), null);
assert.equal(categoryForm.getAttribute('aria-busy'), null);
assert.equal(target.getAttribute('aria-busy'), null);
assert.equal(target.getAttribute('data-blog-results-stale'), null);

// Secuencia 2: categorizar y buscar mientras la primera petición sigue viva.
matrix.checked = false;
categoryForm.dispatch('change', matrix);
assert.equal(pending.at(-1), latestCategory);
assert.deepEqual(hiddenValues(searchForm, 'category[]'), ['zion']);
fireDebounce(debounceTimeouts.at(-1));
const pendingCategory = pending.at(-1);
search.value = 'Oracle';
searchForm.dispatch('submit');
const latestSearch = pending.at(-1);
const latestSearchUrl = new URL(latestSearch.href);
const visibleResultDuringRace = target.innerHTML;
assert.equal(pendingCategory.options.signal.aborted, true);
assert.equal(target.hidden, false, 'fetch keeps the current results visible');
assert.equal(target.getAttribute('aria-hidden'), null);
assert.equal(target.getAttribute('data-blog-results-stale'), 'true');
assert.equal(target.getAttribute('aria-busy'), 'true');
assert.equal(target.innerHTML, visibleResultDuringRace);
assert.equal(latestSearchUrl.searchParams.get('q'), 'Oracle');
assert.deepEqual(
  latestSearchUrl.searchParams.getAll('category[]'),
  ['zion'],
);
await resolvePending(latestSearch);
const stableResult = target.innerHTML;
const stableUpdates = updateEvents;
assert.notEqual(stableResult, visibleResultDuringRace);
assert.equal(target.getAttribute('data-blog-results-stale'), null);
assert.equal(target.getAttribute('aria-busy'), null);
assert.equal(target.getAttribute('aria-hidden'), null);
assert.equal(target.hidden, false);
await resolvePending(pendingCategory);
assert.equal(target.innerHTML, stableResult, 'stale response cannot overwrite');
assert.equal(updateEvents, stableUpdates, 'stale response emits no update');

zion.checked = false;
matrix.checked = false;
const beforeClearApply = pending.length;
categoryForm.dispatch('change', zion);
assert.equal(pending.length, beforeClearApply, 'clearing waits for debounce');
fireDebounce(debounceTimeouts.at(-1));
assert.equal(pending.length, beforeClearApply + 1);
await resolvePending(pending.at(-1));
assert.deepEqual(hiddenValues(searchForm, 'category[]'), []);
assert.deepEqual(hiddenValues(searchForm, 'category_mode'), []);

// Un carácter invalida sin colapsar; dos y cero recargan el estado real.
const beforeInvalid = pending.length;
const resultBeforeInvalidQuery = target.innerHTML;
search.value = 'N';
searchForm.dispatch('input', search);
assert.equal(pending.length, beforeInvalid);
assert.equal(target.hidden, false);
assert.equal(target.getAttribute('aria-hidden'), null);
assert.equal(target.getAttribute('aria-busy'), null);
assert.equal(target.innerHTML, resultBeforeInvalidQuery);
assert.equal(target.getAttribute('data-blog-results-stale'), 'true');
assert.equal(search.getAttribute('aria-invalid'), 'true');
assert.match(searchForm.status.textContent, /two characters/i);
assert.deepEqual(hiddenValues(categoryForm, 'q'), ['N']);

search.value = 'Ne';
searchForm.dispatch('input', search);
assert.equal(target.hidden, false);
assert.equal(target.getAttribute('aria-hidden'), null);
assert.equal(target.getAttribute('aria-busy'), 'true');
assert.equal(target.innerHTML, resultBeforeInvalidQuery);
fireDebounce(debounceTimeouts.at(-1));
assert.equal(pending.length, beforeInvalid + 1);
assert.equal(new URL(pending.at(-1).href).searchParams.get('q'), 'Ne');
assert.equal(target.hidden, false);
assert.equal(target.getAttribute('aria-hidden'), null);
assert.equal(target.getAttribute('aria-busy'), 'true');
assert.equal(target.innerHTML, resultBeforeInvalidQuery);
await resolvePending(pending.at(-1));
assert.equal(target.hidden, false);
assert.equal(target.getAttribute('aria-hidden'), null);
assert.equal(target.getAttribute('aria-busy'), null);
assert.equal(target.getAttribute('data-blog-results-stale'), null);
assert.notEqual(target.innerHTML, resultBeforeInvalidQuery);
assert.equal(search.getAttribute('aria-invalid'), null);

search.value = '';
searchForm.dispatch('input', search);
fireDebounce(debounceTimeouts.at(-1));
await resolvePending(pending.at(-1));
assert.deepEqual(hiddenValues(categoryForm, 'q'), []);
assert.equal(target.hidden, false);

// Cambiar order se propaga antes de la siguiente acción aunque no autoenvíe.
order.value = 'oldest';
searchForm.dispatch('change', order);
assert.deepEqual(hiddenValues(categoryForm, 'order'), ['oldest']);
zion.checked = true;
const beforeOrderApply = pending.length;
categoryForm.dispatch('change', zion);
assert.equal(pending.length, beforeOrderApply);
fireDebounce(debounceTimeouts.at(-1));
assert.equal(pending.length, beforeOrderApply + 1);
assert.equal(new URL(pending.at(-1).href).searchParams.get('order'), 'oldest');
await resolvePending(pending.at(-1));

// Back/forward comparten una sola generación: el estado más reciente gana.
currentHref = 'http://localhost:1309/es/noticias?q=Back&order=oldest';
const beforePopState = pending.length;
view.dispatch('popstate');
const staleBack = pending.at(-1);
currentHref = 'http://localhost:1309/es/noticias?q=Forward&order=updated';
view.dispatch('popstate');
const latestForward = pending.at(-1);
assert.equal(pending.length, beforePopState + 2, 'one fetch per history event');
assert.equal(staleBack.options.signal.aborted, true);
await resolvePending(latestForward);
await resolvePending(staleBack);
assert.equal(valueOf(searchForm, 'q', 'search').value, 'Forward');
assert.deepEqual(hiddenValues(categoryForm, 'q'), ['Forward']);
assert.deepEqual(hiddenValues(categoryForm, 'order'), ['updated']);
assert.equal(nativeFallbacks.length, 0);

// Un rechazo de red conserva DOM, URL, estado de formulario y foco; el mismo
// submit permite reintentar y el temporizador de la generacion queda limpio.
const beforeNetworkErrorHtml = target.innerHTML;
const beforeNetworkErrorHref = currentHref;
const beforeNetworkErrorUpdates = updateEvents;
documentRef.activeElement = search;
search.value = 'Offline';
searchForm.dispatch('submit');
const networkError = pending.at(-1);
assert.equal(networkError.requestTimeout.active, true);
assert.equal(searchForm.status.dataset.state, 'loading');
await rejectPending(networkError);
assert.equal(networkError.requestTimeout.active, false);
assert.equal(networkError.options.signal.aborted, false);
assert.equal(target.innerHTML, beforeNetworkErrorHtml);
assert.equal(currentHref, beforeNetworkErrorHref);
assert.equal(search.value, 'Offline');
assert.deepEqual(hiddenValues(categoryForm, 'q'), ['Offline']);
assert.equal(documentRef.activeElement, search);
assert.equal(target.getAttribute('aria-busy'), null);
assert.equal(target.getAttribute('data-blog-results-stale'), null);
assert.equal(searchForm.status.dataset.state, 'error');
assert.equal(
  searchForm.status.textContent,
  'Could not update results. Try again.',
);
assert.equal(updateEvents, beforeNetworkErrorUpdates);
assert.equal(nativeFallbacks.length, 0, 'enhanced errors never navigate away');

searchForm.dispatch('submit');
const networkRetry = pending.at(-1);
assert.equal(searchForm.status.dataset.state, 'loading');
await resolvePending(networkRetry);
assert.equal(networkRetry.requestTimeout.active, false);
assert.equal(searchForm.status.dataset.state, 'success');
assert.equal(searchForm.status.textContent, 'Results updated');
assert.match(target.innerHTML, /q=Offline/);

// Un fetch colgado vence por generacion, mantiene los resultados y no deja que
// ni su respuesta tardia ni su callback obsoleto afecten al siguiente intento.
search.value = 'Hanging';
const beforeTimeoutHtml = target.innerHTML;
const beforeTimeoutHref = currentHref;
const beforeTimeoutUpdates = updateEvents;
searchForm.dispatch('submit');
const hangingRequest = pending.at(-1);
assert.equal(hangingRequest.requestTimeout.active, true);
assert.equal(target.getAttribute('aria-busy'), 'true');
fireRequestTimeout(hangingRequest.requestTimeout);
assert.equal(hangingRequest.options.signal.aborted, true);
assert.equal(target.innerHTML, beforeTimeoutHtml);
assert.equal(currentHref, beforeTimeoutHref);
assert.equal(search.value, 'Hanging');
assert.equal(documentRef.activeElement, search);
assert.equal(target.getAttribute('aria-busy'), null);
assert.equal(target.getAttribute('data-blog-results-stale'), null);
assert.equal(searchForm.status.dataset.state, 'error');
assert.equal(updateEvents, beforeTimeoutUpdates);
assert.equal(nativeFallbacks.length, 0);

search.value = 'Recovered';
searchForm.dispatch('submit');
const timeoutRetry = pending.at(-1);
assert.equal(timeoutRetry.options.signal.aborted, false);
fireRequestTimeout(hangingRequest.requestTimeout, true);
assert.equal(
  timeoutRetry.options.signal.aborted,
  false,
  'an obsolete timeout cannot abort the current generation',
);
assert.equal(target.getAttribute('aria-busy'), 'true');
await resolvePending(timeoutRetry);
const recoveredHtml = target.innerHTML;
const recoveredUpdates = updateEvents;
assert.equal(timeoutRetry.requestTimeout.active, false);
assert.match(recoveredHtml, /q=Recovered/);
assert.equal(searchForm.status.dataset.state, 'success');
await resolvePending(hangingRequest);
assert.equal(target.innerHTML, recoveredHtml, 'late timed-out response is inert');
assert.equal(updateEvents, recoveredUpdates);

// Dispose tambien cancela el timeout, aborta y vuelve inerte su callback.
search.value = 'Dispose';
searchForm.dispatch('submit');
const disposedRequest = pending.at(-1);
const beforeDisposeHtml = target.innerHTML;
cleanup();
assert.equal(view.listeners.get('popstate')?.size, 0);
assert.equal(searchForm.dispatch('submit'), false);
assert.equal(disposedRequest.options.signal.aborted, true);
assert.equal(disposedRequest.requestTimeout.active, false);
assert.equal(target.hidden, false, 'cleanup must not collapse the results');
assert.equal(target.getAttribute('aria-hidden'), null);
assert.equal(target.getAttribute('aria-busy'), null);
assert.equal(target.getAttribute('data-blog-results-stale'), null);
fireRequestTimeout(disposedRequest.requestTimeout, true);
await resolvePending(disposedRequest);
assert.equal(target.innerHTML, beforeDisposeHtml);
assert.equal(nativeFallbacks.length, 0);

const singleSearch = new FakeForm(documentRef, searchForm.id, [
  control('q', 'Solo', 'search'),
  control('order', 'newest', 'select-one'),
]);
currentForms = [singleSearch];
const cleanupSingle = runtime.initModuleBlogFilters01(documentRef);
const beforeSingle = pending.length;
assert.equal(singleSearch.dispatch('submit'), true);
assert.equal(pending.length, beforeSingle + 1);
await resolvePending(pending.at(-1));
assert.match(target.innerHTML, /q=Solo/);
cleanupSingle();

process.stdout.write('Blog filter coordination: OK\n');
