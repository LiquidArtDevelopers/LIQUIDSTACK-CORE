import fs from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const rootPath = path.resolve(process.argv[2] ?? '.');
const sourcePath = path.join(
  rootPath,
  'modules/blog/resources/project/src/js/modules/blog/blogCollectionLoader.js',
);
const source = await fs.readFile(sourcePath, 'utf8');
const moduleUrl = `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;

class FakeElement {
  constructor({ id = '', dataset = {}, children = [] } = {}) {
    this.id = id;
    this.dataset = { ...dataset };
    this.children = children;
    this.hidden = false;
    this.textContent = '';
    this.attributes = new Map();
    this.listeners = new Map();
    this.isConnected = true;
    this.ownerDocument = null;
    this.selectors = new Map();
    for (const child of children) {
      child.parentElement = this;
    }
  }

  matches(selector) {
    return selector === '[data-blog-collection]'
      && typeof this.dataset.blogCollection === 'string';
  }

  querySelector(selector) {
    if (this.selectors.has(selector)) {
      return this.selectors.get(selector);
    }
    if (selector === '[data-blog-card-key]') {
      return this.children.find((child) => child.dataset.blogCardKey) ?? null;
    }

    return null;
  }

  querySelectorAll(selector) {
    if (selector === '[data-blog-card-key]') {
      return this.children.filter((child) => child.dataset.blogCardKey);
    }

    return [];
  }

  addEventListener(type, listener) {
    const listeners = this.listeners.get(type) ?? [];
    listeners.push(listener);
    this.listeners.set(type, listeners);
  }

  dispatch(type, event = {}) {
    for (const listener of this.listeners.get(type) ?? []) {
      listener({
        button: 0,
        altKey: false,
        ctrlKey: false,
        metaKey: false,
        shiftKey: false,
        preventDefault() { this.defaultPrevented = true; },
        defaultPrevented: false,
        ...event,
      });
    }
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
    return clone;
  }
}

class FakeDocument {
  constructor(view, roots = []) {
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

  addEventListener() {}

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

const collection = (id, keys, nextHref = '') => {
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
  const root = new FakeElement({
    id,
    dataset: {
      blogCollection: 'moduleBlogGrid02',
      blogLoadMode: 'manual',
      blogPartial: 'blog-results',
    },
  });
  root.selectors.set('[data-blog-collection-items]', items);
  root.selectors.set('[data-blog-collection-next]', next);
  root.selectors.set('[data-blog-collection-status]', status);

  return { root, items, next, status };
};

const first = collection('blog-grid', ['a'], '/es/noticias?page=2');
const second = collection('blog-grid', ['a', 'b'], '/es/noticias?page=3');
const third = collection('blog-grid', ['c']);
const responseDocuments = [];
const fetchResponses = [];
const view = {
  URL,
  location: {
    href: 'https://example.test/es/noticias',
    origin: 'https://example.test',
  },
  AbortController,
  CustomEvent: FakeCustomEvent,
  DOMParser: class {
    parseFromString() {
      return responseDocuments.shift();
    }
  },
  async fetch() {
    return fetchResponses.shift();
  },
};
const liveDocument = new FakeDocument(view, [first.root]);
new FakeDocument(view, [second.root]);
new FakeDocument(view, [third.root]);

const response = (document, url) => ({
  ok: true,
  url,
  async text() {
    responseDocuments.push(document);
    return '<!doctype html>';
  },
});
fetchResponses.push(
  response(second.root.ownerDocument, 'https://example.test/es/noticias?page=2'),
  response(third.root.ownerDocument, 'https://example.test/es/noticias?page=3'),
);

const module = await import(moduleUrl);
const cleanup = module.initBlogCollectionLoader(liveDocument);
const tick = async () => {
  await new Promise((resolve) => setTimeout(resolve, 0));
  await new Promise((resolve) => setTimeout(resolve, 0));
};

first.next.dispatch('click');
await tick();
const afterFirst = {
  keys: first.items.children.map((item) => item.dataset.blogCardKey),
  next: first.next.getAttribute('href'),
  busy: first.root.getAttribute('aria-busy'),
  status: first.status.dataset.state,
  events: liveDocument.events.map((event) => event.type),
};

liveDocument.activeElement = first.next;
first.next.dispatch('click');
await tick();
const afterSecond = {
  keys: first.items.children.map((item) => item.dataset.blogCardKey),
  hidden: first.next.hidden,
  disabled: first.next.getAttribute('aria-disabled'),
  label: first.next.textContent,
  status: first.status.dataset.state,
  message: first.status.textContent,
};
first.next.dispatch('blur');
const hiddenAfterBlur = first.next.hidden;

cleanup();

const timeoutLive = collection(
  'blog-grid-timeout',
  ['timeout-a'],
  '/es/noticias?page=2',
);
const timeoutFinal = collection('blog-grid-timeout', ['timeout-b']);
const timeoutDocument = new FakeDocument(view, [timeoutLive.root]);
new FakeDocument(view, [timeoutFinal.root]);
const pendingTimers = new Map();
let timerSequence = 0;
let hangRequest = true;
view.setTimeout = (callback) => {
  timerSequence += 1;
  pendingTimers.set(timerSequence, callback);
  return timerSequence;
};
view.clearTimeout = (timer) => pendingTimers.delete(timer);
view.fetch = async () => {
  if (hangRequest) {
    return new Promise(() => {});
  }

  return response(
    timeoutFinal.root.ownerDocument,
    'https://example.test/es/noticias?page=2',
  );
};

const timeoutCleanup = module.initBlogCollectionLoader(timeoutDocument);
timeoutLive.next.dispatch('click');
await Promise.resolve();
const timeoutCallback = [...pendingTimers.values()][0];
timeoutCallback();
await tick();
const afterTimeout = {
  busy: timeoutLive.root.getAttribute('aria-busy'),
  disabled: timeoutLive.next.getAttribute('aria-disabled'),
  label: timeoutLive.next.textContent,
  status: timeoutLive.status.dataset.state,
  message: timeoutLive.status.textContent,
};

hangRequest = false;
timeoutLive.next.dispatch('click');
await tick();
const afterRetry = {
  keys: timeoutLive.items.children.map((item) => item.dataset.blogCardKey),
  hidden: timeoutLive.next.hidden,
  status: timeoutLive.status.dataset.state,
  message: timeoutLive.status.textContent,
};
timeoutCleanup();

process.stdout.write(JSON.stringify({
  afterFirst,
  afterSecond,
  hiddenAfterBlur,
  afterTimeout,
  afterRetry,
}));
