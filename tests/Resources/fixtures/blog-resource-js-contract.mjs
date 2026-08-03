import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const projectRoot = resolve(import.meta.dirname, '..', '..', '..', 'modules', 'blog', 'resources', 'project');

const importSource = async (relativePath) => {
  const source = await readFile(resolve(projectRoot, relativePath), 'utf8');
  return import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
};

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

  dispatch(type, target = this, detail = undefined) {
    let prevented = false;
    const event = {
      type,
      target,
      detail,
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
    this.dispatch(event.type, this, event.detail);
    return true;
  }
}

class FakeElement extends FakeTarget {
  constructor(ownerDocument = null) {
    super();
    this.ownerDocument = ownerDocument;
    this.dataset = {};
    this.attributes = new Map();
    this.innerHTML = '';
    this.hidden = false;
    this.disabled = false;
    this.isConnected = true;
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

const tick = (duration = 0) => new Promise((resolveTick) => {
  setTimeout(resolveTick, duration);
});

const createLocation = (initialHref) => {
  let current = new URL(initialHref);
  const assigned = [];

  return {
    get href() {
      return current.href;
    },
    set href(value) {
      current = new URL(value, current);
    },
    get origin() {
      return current.origin;
    },
    assign(value) {
      assigned.push(value);
      current = new URL(value, current);
    },
    assigned,
  };
};

const testFilters = async () => {
  const documentRef = new FakeTarget();
  documentRef.nodeType = 9;
  const location = createLocation('http://localhost:1309/es/noticias');
  const view = new FakeTarget();
  const historyOperations = [];
  view.location = location;
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
      historyOperations.push({ method: 'push', state, title, href });
      location.href = href;
    },
    replaceState(state, title, href) {
      historyOperations.push({ method: 'replace', state, title, href });
      location.href = href;
    },
  };

  const target = new FakeElement(documentRef);
  target.innerHTML = '<p>initial</p>';
  const currentRobots = new FakeElement(documentRef);
  currentRobots.setAttribute('content', 'index, follow');
  const currentCanonical = new FakeElement(documentRef);
  currentCanonical.setAttribute('href', 'http://localhost:1309/es/noticias');
  const status = new FakeElement(documentRef);
  status.dataset.message = 'Resultados actualizados';
  status.textContent = '';
  const search = { name: 'q', value: '', type: 'search' };
  const category = {
    name: 'category[]', value: 'matrix', type: 'checkbox', checked: true,
  };
  const mode = { name: 'category_mode', value: 'any', type: 'select-one' };
  const form = new FakeElement(documentRef);
  form.id = 'moduleBlogFilters01-00';
  form.action = '/es/noticias';
  form.method = 'get';
  form.dataset.blogResultsTarget = '#blog-results';
  form.formEntries = [
    ['q', '  Neo  '],
    ['category[]', 'matrix'],
    ['category_mode', 'any'],
    ['empty', ''],
  ];
  form.elements = [search, category, mode];
  let formIsValid = true;
  form.checkValidity = () => formIsValid;
  form.getAttribute = (name) => (name === 'method' ? 'get' : null);
  form.querySelector = (selector) => (
    selector === '[data-blog-filter-status]' ? status : null
  );
  form.querySelectorAll = () => [];

  const incomingForm = {
    elements: [
      { name: 'q', value: '', type: 'search' },
      {
        name: 'category[]', value: 'matrix', type: 'checkbox', checked: true,
      },
      { name: 'category_mode', value: 'any', type: 'select-one' },
    ],
  };
  const responseDocuments = new Map();
  const createResponseDocument = (marker, html, title = `${marker} title`) => {
    const incomingTarget = new FakeElement(documentRef);
    incomingTarget.innerHTML = html;
    const incomingRobots = new FakeElement(documentRef);
    incomingRobots.setAttribute('content', `robots-${marker}`);
    const incomingCanonical = new FakeElement(documentRef);
    incomingCanonical.setAttribute(
      'href',
      `http://localhost:1309/es/noticias?response=${marker}`,
    );
    responseDocuments.set(marker, {
      title,
      querySelector: (selector) => ({
        '#blog-results': incomingTarget,
        'meta[name="robots"]': incomingRobots,
        'link[rel="canonical"]': incomingCanonical,
      })[selector] ?? null,
      getElementById: (id) => (
        id === 'moduleBlogFilters01-00' ? incomingForm : null
      ),
    });
  };
  createResponseDocument('default', '<article>updated</article>', 'Updated title');
  createResponseDocument('A', '<article>stale A</article>', 'Stale A title');
  createResponseDocument('B', '<article>fresh B</article>', 'Fresh B title');
  createResponseDocument('C', '<article>fresh C</article>', 'Fresh C title');
  view.DOMParser = class FakeDOMParser {
    parseFromString(marker) {
      return responseDocuments.get(marker) ?? responseDocuments.get('default');
    }
  };
  let fetchCount = 0;
  const fetchedUrls = [];
  let nextResponseMarker = 'default';
  let deferredResponse = null;
  view.fetch = (href) => {
    fetchCount += 1;
    fetchedUrls.push(href);
    const marker = nextResponseMarker;
    nextResponseMarker = 'default';
    const response = {
      ok: true,
      status: 200,
      text: async () => marker,
    };
    if (deferredResponse !== null) {
      const deferred = deferredResponse;
      deferredResponse = null;
      return new Promise((resolveResponse) => {
        deferred.resolve = () => resolveResponse(response);
      });
    }

    return Promise.resolve(response);
  };

  documentRef.defaultView = view;
  documentRef.title = 'Initial title';
  documentRef.querySelectorAll = (selector) => (
    selector === '[data-blog-filter-form]' ? [form] : []
  );
  documentRef.querySelector = (selector) => ({
    '#blog-results': target,
    'meta[name="robots"]': currentRobots,
    'link[rel="canonical"]': currentCanonical,
  })[selector] ?? null;

  globalThis.window = view;
  globalThis.document = documentRef;
  const module = await importSource('src/js/resources/_moduleBlogFilters01.js');
  const cleanup = module.initModuleBlogFilters01(documentRef);

  assert.equal(form.dispatch('submit'), true, 'fetch intercepts a valid GET form');
  await tick();
  await tick();
  assert.equal(fetchCount, 1);
  assert.equal(target.innerHTML, '<article>updated</article>');
  assert.equal(historyOperations.length, 1);
  assert.equal(historyOperations[0].method, 'push');
  assert.match(fetchedUrls[0], /q=\+\+Neo\+\+/);
  assert.match(fetchedUrls[0], /empty=/);
  assert.equal(documentRef.title, 'Updated title');
  assert.equal(currentRobots.getAttribute('content'), 'robots-default');
  assert.equal(
    currentCanonical.getAttribute('href'),
    'http://localhost:1309/es/noticias?response=default',
  );
  assert.equal(status.textContent, 'Resultados actualizados');
  assert.equal(form.attributes.has('aria-busy'), false);

  form.formEntries = [
    ['q', '  Neo  '],
    ['category[]', 'matrix'],
    ['category[]', 'zion'],
    ['category_mode', 'any'],
    ['empty', ''],
  ];
  form.dispatch('change', category);
  await tick();
  await tick();
  assert.equal(fetchCount, 2, 'category changes progressively refresh results');
  assert.equal(
    historyOperations.at(-1).method,
    'push',
    'category changes create a navigable history entry',
  );

  target.innerHTML = '<article>before race</article>';
  search.value = 'A';
  form.formEntries = [['q', 'A']];
  nextResponseMarker = 'A';
  const pendingA = {};
  deferredResponse = pendingA;
  form.dispatch('submit');
  await tick();
  assert.equal(fetchCount, 3, 'request A is active');

  search.value = 'B';
  form.formEntries = [['q', 'B']];
  form.dispatch('input', search);
  pendingA.resolve();
  await tick();
  await tick();
  assert.equal(
    target.innerHTML,
    '<article>before race</article>',
    'an aborted response cannot overwrite the query during debounce',
  );
  assert.notEqual(documentRef.title, 'Stale A title');

  nextResponseMarker = 'B';
  await tick(380);
  await tick();
  assert.equal(fetchCount, 4, 'the search field uses its bounded debounce');
  assert.equal(target.innerHTML, '<article>fresh B</article>');
  assert.equal(historyOperations.at(-1).method, 'push');

  search.value = 'C';
  form.formEntries = [['q', 'C']];
  nextResponseMarker = 'C';
  form.dispatch('input', search);
  await tick(380);
  await tick();
  assert.equal(fetchCount, 5);
  assert.equal(target.innerHTML, '<article>fresh C</article>');
  assert.equal(
    historyOperations.at(-1).method,
    'replace',
    'later pauses replace the live-search history entry',
  );

  formIsValid = false;
  search.value = 'x';
  form.formEntries = [['q', 'x']];
  form.dispatch('input', search);
  await tick(380);
  assert.equal(fetchCount, 5, 'invalid minlength state never fetches');
  assert.equal(form.dispatch('submit'), false, 'invalid submit remains native');
  assert.equal(fetchCount, 5);
  formIsValid = true;

  const historyCountBeforePopState = historyOperations.length;
  location.href = 'http://localhost:1309/es/noticias?category=matrix';
  view.dispatch('popstate');
  await tick();
  await tick();
  assert.equal(fetchCount, 6, 'popstate restores server-rendered results');
  assert.equal(
    historyOperations.length,
    historyCountBeforePopState,
    'popstate does not write a history entry',
  );

  cleanup();
  assert.equal(form.dispatch('submit'), false, 'cleanup removes listeners');
  assert.deepEqual(location.assigned, [], 'successful enhancement never navigates');
};

const createSlider = (documentRef, view, slideCount = 3) => {
  const root = new FakeElement(documentRef);
  const viewport = new FakeElement(documentRef);
  const track = new FakeElement(documentRef);
  const controls = new FakeElement(documentRef);
  const previous = new FakeElement(documentRef);
  const next = new FakeElement(documentRef);
  previous.parentElement = controls;
  next.parentElement = controls;
  viewport.scrollLeft = 0;
  viewport.clientWidth = 200;
  viewport.scrollWidth = slideCount * 200;
  viewport.getBoundingClientRect = () => ({ left: 0, right: 200 });
  viewport.scrollBy = ({ left, behavior }) => {
    viewport.lastBehavior = behavior;
    viewport.scrollLeft = Math.max(
      0,
      Math.min(viewport.scrollWidth - viewport.clientWidth, viewport.scrollLeft + left),
    );
    viewport.dispatch('scroll');
  };
  track.children = Array.from({ length: slideCount }, (_, index) => ({
    getBoundingClientRect: () => ({
      left: (index * 200) - viewport.scrollLeft,
      right: ((index + 1) * 200) - viewport.scrollLeft,
    }),
  }));
  viewport.querySelector = (selector) => (
    selector === '.sectionBlogSlider01-track' ? track : null
  );
  root.querySelector = (selector) => ({
    '[data-blog-slider-viewport]': viewport,
    '.sectionBlogSlider01-track': track,
    '[data-blog-slider-previous]': previous,
    '[data-blog-slider-next]': next,
  })[selector] ?? null;
  root.querySelectorAll = () => [];
  root.matches = (selector) => selector === '[data-blog-slider]';

  return {
    root, viewport, track, controls, previous, next,
  };
};

const testSlider = async () => {
  const documentRef = new FakeTarget();
  documentRef.nodeType = 9;
  const view = new FakeTarget();
  const resizeObservers = [];
  view.AbortController = AbortController;
  view.setTimeout = setTimeout;
  view.clearTimeout = clearTimeout;
  view.requestAnimationFrame = (callback) => setTimeout(callback, 0);
  view.cancelAnimationFrame = clearTimeout;
  view.getComputedStyle = () => ({ direction: 'ltr' });
  view.matchMedia = () => ({ matches: false });
  view.ResizeObserver = class FakeResizeObserver {
    constructor(callback) {
      this.callback = callback;
      this.disconnected = false;
      resizeObservers.push(this);
    }

    observe() {}

    disconnect() {
      this.disconnected = true;
    }
  };
  documentRef.defaultView = view;
  globalThis.window = view;
  globalThis.document = documentRef;

  const first = createSlider(documentRef, view);
  const second = createSlider(documentRef, view, 2);
  documentRef.querySelectorAll = (selector) => (
    selector === '[data-blog-slider]' ? [first.root, second.root] : []
  );

  const module = await importSource('src/js/resources/_sectionBlogSlider01.js');
  const cleanup = module.initSectionBlogSlider01(documentRef);
  await tick();
  assert.equal(resizeObservers.length, 2, 'each slider owns one resize observer');
  assert.equal(first.previous.disabled, true);
  assert.equal(first.next.disabled, false);
  assert.equal(first.controls.hidden, false);

  first.next.dispatch('click');
  await tick();
  assert.equal(first.viewport.scrollLeft, 200);
  assert.equal(first.viewport.lastBehavior, 'smooth');
  assert.equal(first.previous.disabled, false);

  first.next.dispatch('click');
  await tick();
  assert.equal(first.viewport.scrollLeft, 400);
  assert.equal(first.next.disabled, true);

  cleanup();
  assert.equal(resizeObservers.every((observer) => observer.disconnected), true);
  assert.equal(first.controls.hidden, true);
  const previousScroll = first.viewport.scrollLeft;
  first.previous.dispatch('click');
  assert.equal(first.viewport.scrollLeft, previousScroll, 'cleanup removes controls');
};

await testFilters();
await testSlider();
process.stdout.write('Blog progressive resource runtimes: OK\n');
