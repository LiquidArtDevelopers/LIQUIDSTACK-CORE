import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';

const rootPath = path.resolve(process.argv[2] ?? '.');
const sourcePath = path.join(
  rootPath,
  'modules/blog/resources/project/src/js/modules/blog/blogCollectionLoader.js',
);
const source = await fs.readFile(sourcePath, 'utf8');
const moduleUrl = `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
const loaderModule = await import(moduleUrl);

class FakeEventTarget {
  constructor() {
    this.listeners = new Map();
  }

  addEventListener(type, listener, options = {}) {
    const listeners = this.listeners.get(type) ?? new Set();
    listeners.add(listener);
    this.listeners.set(type, listeners);
    options.signal?.addEventListener?.('abort', () => {
      listeners.delete(listener);
    }, { once: true });
  }

  removeEventListener(type, listener) {
    this.listeners.get(type)?.delete(listener);
  }

  dispatch(type, overrides = {}) {
    const event = {
      type,
      button: 0,
      altKey: false,
      ctrlKey: false,
      metaKey: false,
      shiftKey: false,
      defaultPrevented: false,
      preventDefault() {
        this.defaultPrevented = true;
      },
      ...overrides,
    };
    for (const listener of [...(this.listeners.get(type) ?? [])]) {
      listener(event);
    }

    return event;
  }
}

class FakeElement extends FakeEventTarget {
  constructor({ id = '', dataset = {}, children = [] } = {}) {
    super();
    this.id = id;
    this.dataset = { ...dataset };
    this.children = children;
    this.hidden = false;
    this.textContent = '';
    this.attributes = new Map();
    this.isConnected = true;
    this.ownerDocument = null;
    this.selectors = new Map();
    this.rect = { top: 100, bottom: 140 };
    for (const child of children) {
      child.parentElement = this;
    }
  }

  matches(selector) {
    return selector === '[data-blog-collection]'
      && typeof this.dataset.blogCollection === 'string';
  }

  querySelector(selector) {
    return this.selectors.get(selector) ?? null;
  }

  querySelectorAll(selector) {
    if (selector === '[data-blog-card-key]') {
      return this.children.filter((child) => child.dataset.blogCardKey);
    }

    return [];
  }

  setAttribute(name, value) {
    this.attributes.set(name, String(value));
  }

  getAttribute(name) {
    return this.attributes.has(name) ? this.attributes.get(name) : null;
  }

  removeAttribute(name) {
    this.attributes.delete(name);
  }

  appendChild(child) {
    child.parentElement = this;
    child.ownerDocument = this.ownerDocument;
    this.children.push(child);

    return child;
  }

  cloneNode(deep = false) {
    const clone = new FakeElement({
      id: this.id,
      dataset: { ...this.dataset },
      children: deep ? this.children.map((child) => child.cloneNode(true)) : [],
    });
    clone.hidden = this.hidden;
    clone.textContent = this.textContent;
    clone.attributes = new Map(this.attributes);
    clone.rect = { ...this.rect };

    return clone;
  }

  getBoundingClientRect() {
    return { ...this.rect };
  }
}

class FakeDocument extends FakeEventTarget {
  constructor(view, roots = []) {
    super();
    this.nodeType = 9;
    this.defaultView = view;
    this.roots = roots;
    this.events = [];
    this.activeElement = null;
    for (const root of roots) {
      this.adopt(root);
    }
  }

  adopt(node) {
    node.ownerDocument = this;
    for (const child of node.children ?? []) {
      this.adopt(child);
    }
  }

  querySelectorAll(selector) {
    return selector === '[data-blog-collection]' ? this.roots : [];
  }

  getElementById(id) {
    return this.roots.find((root) => root.id === id) ?? null;
  }

  importNode(node, deep) {
    const clone = node.cloneNode(deep);
    this.adopt(clone);

    return clone;
  }

  dispatchEvent(event) {
    this.events.push(event);

    return true;
  }
}

class FakeCustomEvent {
  constructor(type, options = {}) {
    this.type = type;
    this.detail = options.detail;
  }
}

const card = (key) => new FakeElement({ dataset: { blogCardKey: key } });

const collection = ({
  id,
  keys = [],
  nextHref = '',
  mode = 'manual',
  maxBatches = '',
}) => {
  const items = new FakeElement({ children: keys.map(card) });
  const next = new FakeElement();
  next.textContent = 'Cargar más';
  if (nextHref) {
    next.setAttribute('href', nextHref);
  }
  const status = new FakeElement({
    dataset: {
      loadingMessage: 'Cargando',
      errorMessage: 'Error',
      retryMessage: 'Reintentar',
      endMessage: 'Fin',
    },
  });
  const dataset = {
    blogCollection: 'moduleBlogGrid02',
    blogLoadMode: mode,
    blogPartial: 'blog-results',
  };
  if (maxBatches !== '') {
    dataset.blogMaxBatches = String(maxBatches);
  }
  const root = new FakeElement({ id, dataset });
  root.selectors.set('[data-blog-collection-items]', items);
  root.selectors.set('[data-blog-collection-next]', next);
  root.selectors.set('[data-blog-collection-status]', status);

  return { root, items, next, status };
};

const makeEnvironment = (live, fetchImplementation, withObserver = false) => {
  const responseDocuments = [];
  const observers = [];
  class FakeIntersectionObserver {
    constructor(callback) {
      this.callback = callback;
      this.observed = new Set();
      this.disconnected = false;
      observers.push(this);
    }

    observe(target) {
      this.observed.add(target);
    }

    disconnect() {
      this.disconnected = true;
      this.observed.clear();
    }

    fire(target, isIntersecting = true) {
      if (!this.disconnected && this.observed.has(target)) {
        this.callback([{ target, isIntersecting }]);
      }
    }
  }

  const view = {
    URL,
    location: {
      href: 'https://example.test/es/noticias',
      origin: 'https://example.test',
    },
    AbortController,
    CustomEvent: FakeCustomEvent,
    innerHeight: 1000,
    setTimeout,
    clearTimeout,
    DOMParser: class {
      parseFromString() {
        return responseDocuments.shift();
      }
    },
  };
  if (withObserver) {
    view.IntersectionObserver = FakeIntersectionObserver;
  }
  view.fetch = (url, options) => fetchImplementation(url, options);
  const document = new FakeDocument(view, [live.root]);

  const response = (incoming, url) => ({
    ok: true,
    url,
    async text() {
      responseDocuments.push(incoming.root.ownerDocument);

      return '<!doctype html>';
    },
  });

  return { view, document, observers, response };
};

const adoptIncoming = (view, incoming) => {
  new FakeDocument(view, [incoming.root]);

  return incoming;
};

const waitUntil = async (condition, attempts = 40) => {
  for (let attempt = 0; attempt < attempts; attempt += 1) {
    if (condition()) {
      return;
    }
    await new Promise((resolve) => setTimeout(resolve, 0));
  }
  assert.fail('Timed out waiting for loader state.');
};

const nearLive = collection({
  id: 'near-end-grid',
  nextHref: '/es/noticias?page=2',
  mode: 'near-end',
});
const nearResponses = [];
let nearFetches = 0;
const nearEnvironment = makeEnvironment(
  nearLive,
  async () => {
    nearFetches += 1;
    return nearResponses.shift();
  },
  true,
);
const nearSecond = adoptIncoming(nearEnvironment.view, collection({
  id: 'near-end-grid',
  keys: ['near-a'],
  nextHref: '/es/noticias?page=3',
}));
const nearThird = adoptIncoming(nearEnvironment.view, collection({
  id: 'near-end-grid',
  keys: ['near-b'],
}));
nearResponses.push(
  nearEnvironment.response(
    nearSecond,
    'https://example.test/es/noticias?page=2',
  ),
  nearEnvironment.response(
    nearThird,
    'https://example.test/es/noticias?page=3',
  ),
);
const cleanupNear = loaderModule.initBlogCollectionLoader(
  nearEnvironment.document,
);
assert.equal(nearEnvironment.observers.length, 1);
nearEnvironment.observers[0].fire(nearLive.next);
await waitUntil(() => nearLive.status.dataset.state === 'end');
assert.deepEqual(
  nearLive.items.children.map((item) => item.dataset.blogCardKey),
  ['near-a', 'near-b'],
);
assert.equal(nearFetches, 2);
assert.equal(nearEnvironment.document.events.length, 2);
cleanupNear();

const invalidLive = collection({
  id: 'invalid-grid',
  keys: ['invalid-seed'],
  nextHref: '/es/noticias?page=2',
});
let invalidFetches = 0;
let invalidEnvironment;
invalidEnvironment = makeEnvironment(invalidLive, async () => {
  invalidFetches += 1;
  return invalidEnvironment.response(
    invalidIncoming,
    'https://example.test/es/noticias?page=2',
  );
});
const invalidIncoming = adoptIncoming(invalidEnvironment.view, collection({
  id: 'invalid-grid',
  keys: ['must-not-commit'],
  nextHref: 'https://evil.test/es/noticias?page=3',
}));
const cleanupInvalid = loaderModule.initBlogCollectionLoader(
  invalidEnvironment.document,
);
invalidLive.next.dispatch('click');
await waitUntil(() => invalidLive.status.dataset.state === 'error');
assert.deepEqual(
  invalidLive.items.children.map((item) => item.dataset.blogCardKey),
  ['invalid-seed'],
);
assert.equal(invalidLive.next.getAttribute('href'), '/es/noticias?page=2');
assert.equal(invalidEnvironment.document.events.length, 0);
cleanupInvalid();

const cleanupLive = collection({
  id: 'cleanup-grid',
  keys: ['cleanup-seed'],
  nextHref: '/es/noticias?page=2',
});
let resolveCleanupRequest;
let cleanupSignal;
let cleanupEnvironment;
cleanupEnvironment = makeEnvironment(cleanupLive, (_url, options) => {
  cleanupSignal = options.signal;
  return new Promise((resolveRequest) => {
    resolveCleanupRequest = resolveRequest;
  });
});
const cleanupIncoming = adoptIncoming(cleanupEnvironment.view, collection({
  id: 'cleanup-grid',
  keys: ['must-stay-stale'],
}));
const cleanupPending = loaderModule.initBlogCollectionLoader(
  cleanupEnvironment.document,
);
cleanupLive.next.dispatch('click');
await waitUntil(() => typeof resolveCleanupRequest === 'function');
cleanupPending();
assert.equal(cleanupSignal.aborted, true);
resolveCleanupRequest(cleanupEnvironment.response(
  cleanupIncoming,
  'https://example.test/es/noticias?page=2',
));
await new Promise((resolve) => setTimeout(resolve, 0));
await new Promise((resolve) => setTimeout(resolve, 0));
assert.deepEqual(
  cleanupLive.items.children.map((item) => item.dataset.blogCardKey),
  ['cleanup-seed'],
);
assert.equal(cleanupEnvironment.document.events.length, 0);
assert.equal(cleanupLive.root.getAttribute('aria-busy'), null);

const capLive = collection({
  id: 'cap-grid',
  keys: ['cap-seed'],
  nextHref: '/es/noticias?page=2',
  maxBatches: 1,
});
let capFetches = 0;
let capEnvironment;
capEnvironment = makeEnvironment(capLive, async () => {
  capFetches += 1;
  return capEnvironment.response(
    capIncoming,
    'https://example.test/es/noticias?page=2',
  );
});
const capIncoming = adoptIncoming(capEnvironment.view, collection({
  id: 'cap-grid',
  keys: ['cap-next'],
  nextHref: '/es/noticias?page=3',
}));
const cleanupCap = loaderModule.initBlogCollectionLoader(capEnvironment.document);
capLive.next.dispatch('click');
await waitUntil(() => capLive.items.children.length === 2);
const nativeCapClick = capLive.next.dispatch('click');
await new Promise((resolve) => setTimeout(resolve, 0));
assert.equal(nativeCapClick.defaultPrevented, false);
assert.equal(capFetches, 1);
assert.equal(capLive.next.getAttribute('href'), '/es/noticias?page=3');
assert.equal(capLive.next.hidden, false);
cleanupCap();

process.stdout.write(JSON.stringify({
  nearEnd: {
    fetches: nearFetches,
    keys: nearLive.items.children.map((item) => item.dataset.blogCardKey),
    events: nearEnvironment.document.events.length,
  },
  invalidContinuation: {
    fetches: invalidFetches,
    keys: invalidLive.items.children.map((item) => item.dataset.blogCardKey),
    status: invalidLive.status.dataset.state,
  },
  cleanup: {
    aborted: cleanupSignal.aborted,
    keys: cleanupLive.items.children.map((item) => item.dataset.blogCardKey),
    events: cleanupEnvironment.document.events.length,
  },
  cap: {
    fetches: capFetches,
    nativeDefaultPrevented: nativeCapClick.defaultPrevented,
    next: capLive.next.getAttribute('href'),
  },
}));
