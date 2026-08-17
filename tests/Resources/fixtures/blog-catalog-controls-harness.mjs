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
      preventDefault: () => {
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
    this.elements = [];
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

const documentRef = new FakeTarget();
documentRef.nodeType = 9;
const view = new FakeTarget();
let currentHref = 'http://localhost:1309/es/noticias';
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
    throw new Error(`Unexpected native fallback: ${value}`);
  },
};
view.AbortController = AbortController;
view.FormData = class FakeFormData {
  constructor(form) {
    this.entries = form.formEntries;
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
view.setTimeout = setTimeout;
view.clearTimeout = clearTimeout;
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

const createForm = (id, entries) => {
  const form = new FakeElement(documentRef);
  form.id = id;
  form.action = '/es/noticias';
  form.method = 'get';
  form.dataset.blogResultsTarget = '#catalog-results';
  form.formEntries = entries;
  form.checkValidity = () => true;
  form.getAttribute = (name) => (name === 'method' ? 'get' : null);
  form.querySelector = () => null;
  form.querySelectorAll = () => [];
  return form;
};

const searchForm = createForm('moduleBlogSearch01-00', [
  ['category[]', 'matrix'],
  ['category_mode', 'all'],
  ['q', 'Neo'],
  ['order', 'updated'],
]);
const categoryForm = createForm('moduleBlogCategoryBar01-00', [
  ['q', 'Neo'],
  ['order', 'updated'],
  ['category[]', 'matrix'],
  ['category[]', 'zion'],
  ['category_mode', 'any'],
]);
const categoryControl = {
  name: 'category[]',
  value: 'zion',
  type: 'checkbox',
  checked: true,
};
categoryForm.elements = [categoryControl];

const incomingTarget = new FakeElement(documentRef);
incomingTarget.innerHTML = '<article>Updated</article>';
const incomingForms = new Map([
  [searchForm.id, { elements: [] }],
  [categoryForm.id, { elements: [categoryControl] }],
]);
const responseDocument = {
  title: 'Updated catalog',
  querySelector: (selector) => (
    selector === '#catalog-results' ? incomingTarget : null
  ),
  getElementById: (id) => incomingForms.get(id) ?? null,
};
view.DOMParser = class FakeDOMParser {
  parseFromString() {
    return responseDocument;
  }
};

const fetchedUrls = [];
view.fetch = async (href) => {
  fetchedUrls.push(href);
  return {
    ok: true,
    status: 200,
    text: async () => '<html></html>',
  };
};

documentRef.defaultView = view;
documentRef.title = 'Initial catalog';
documentRef.head = { appendChild() {} };
documentRef.querySelectorAll = (selector) => (
  selector === '[data-blog-filter-form]'
    ? [searchForm, categoryForm]
    : []
);
documentRef.querySelector = (selector) => (
  selector === '#catalog-results' ? target : null
);

globalThis.window = view;
globalThis.document = documentRef;

const cleanup = runtime.initModuleBlogFilters01(documentRef);
assert.equal(searchForm.dispatch('submit'), true);
await new Promise((resolveTick) => setTimeout(resolveTick, 0));
await new Promise((resolveTick) => setTimeout(resolveTick, 0));

assert.equal(fetchedUrls.length, 1);
const searchUrl = new URL(fetchedUrls[0]);
assert.equal(searchUrl.searchParams.get('q'), 'Neo');
assert.equal(searchUrl.searchParams.get('order'), 'updated');
assert.deepEqual(searchUrl.searchParams.getAll('category[]'), ['matrix']);
assert.equal(searchUrl.searchParams.get('category_mode'), 'all');

assert.equal(categoryForm.dispatch('change', categoryControl), false);
await new Promise((resolveTick) => setTimeout(resolveTick, 0));
await new Promise((resolveTick) => setTimeout(resolveTick, 0));

assert.equal(fetchedUrls.length, 1, 'category changes wait for apply');
assert.equal(categoryForm.dispatch('submit'), true);
await new Promise((resolveTick) => setTimeout(resolveTick, 0));
await new Promise((resolveTick) => setTimeout(resolveTick, 0));

assert.equal(fetchedUrls.length, 2);
const categoryUrl = new URL(fetchedUrls[1]);
assert.equal(categoryUrl.searchParams.get('q'), 'Neo');
assert.equal(categoryUrl.searchParams.get('order'), 'updated');
assert.deepEqual(
  categoryUrl.searchParams.getAll('category[]'),
  ['matrix', 'zion'],
);
assert.equal(categoryUrl.searchParams.get('category_mode'), 'any');
assert.equal(target.innerHTML, '<article>Updated</article>');

cleanup();
assert.equal(searchForm.dispatch('submit'), false);
assert.equal(categoryForm.dispatch('change', categoryControl), false);

process.stdout.write('Blog catalog control variants: OK\n');
