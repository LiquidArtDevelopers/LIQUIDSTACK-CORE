import fs from 'node:fs';
import vm from 'node:vm';
import { URL } from 'node:url';

const source = fs.readFileSync(process.argv[2], 'utf8');

function eventBus() {
  const listeners = new Map();
  return {
    addEventListener(type, handler, options = {}) {
      const entries = listeners.get(type) || [];
      entries.push(handler);
      listeners.set(type, entries);
      options.signal?.addEventListener('abort', () => {
        const current = listeners.get(type) || [];
        listeners.set(type, current.filter((entry) => entry !== handler));
      }, { once: true });
    },
    dispatch(type, event = {}) {
      (listeners.get(type) || []).slice().forEach((handler) => handler(event));
    },
  };
}

const baseline = {
  allow: 'accelerometer; autoplay; clipboard-write; encrypted-media; fullscreen; gyroscope; picture-in-picture; web-share',
  allowfullscreen: 'allowfullscreen',
  loading: 'lazy',
  referrerpolicy: 'strict-origin-when-cross-origin',
  sandbox: 'allow-scripts allow-same-origin allow-presentation',
  title: 'Contenido multimedia incrustado',
};

function placeholder(src, overrides = {}) {
  const attributes = { ...baseline, src, ...overrides };
  const node = {
    kind: 'placeholder',
    parentNode: null,
    getAttribute(name) {
      if (name !== 'data-blog-consent-iframe') return '';
      return JSON.stringify({ v: 1, attributes });
    },
  };
  return node;
}

function container(child) {
  const node = {
    child,
    replaceChild(replacement, current) {
      if (this.child !== current) throw new Error('Unexpected child.');
      current.parentNode = null;
      replacement.parentNode = this;
      this.child = replacement;
    },
  };
  child.parentNode = node;
  return node;
}

const youtube = placeholder(
  'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?start=2',
  {
    class: 'lsb-frame',
    id: 'lsb-player',
    width: '560',
    height: '315',
  },
);
const vimeo = placeholder('https://player.vimeo.com/video/123');
const spoof = placeholder('https://evil.player.vimeo.com/video/123');
const traversal = placeholder(
  'https://www.youtube.com/embed/%252e%252e/watch',
);
const tampered = placeholder(
  'https://player.vimeo.com/video/456',
  { sandbox: 'allow-scripts allow-top-navigation' },
);
const eventHandler = placeholder(
  'https://player.vimeo.com/video/789',
  { onload: 'alert(1)' },
);
const invalidRole = placeholder(
  'https://www.google.com/maps/embed?pb=matrix',
  { role: 'application' },
);
const placeholders = [
  youtube,
  vimeo,
  spoof,
  traversal,
  tampered,
  eventHandler,
  invalidRole,
];
const containers = placeholders.map(container);
const windowBus = eventBus();
const documentBus = eventBus();
let cookie = '';

const documentRef = {
  visibilityState: 'visible',
  head: { appendChild() {} },
  documentElement: { appendChild() {} },
  addEventListener: documentBus.addEventListener,
  get cookie() { return cookie; },
  set cookie(value) {},
  getElementById() { return null; },
  querySelector() { return null; },
  querySelectorAll(selector) {
    if (selector === '[data-blog-consent-iframe]') {
      return placeholders.filter((entry) => entry.parentNode !== null);
    }
    return [];
  },
  createElement(name) {
    if (name === 'script') {
      return { setAttribute() {}, remove() {} };
    }
    if (name !== 'iframe') throw new Error('Unexpected element.');
    return {
      kind: 'iframe',
      attributes: new Map(),
      parentNode: null,
      setAttribute(key, value) { this.attributes.set(key, value); },
    };
  },
};
const windowRef = {
  addEventListener: windowBus.addEventListener,
  location: { protocol: 'https:' },
};
const sandbox = {
  window: windowRef,
  document: documentRef,
  AbortController,
  URL,
  JSON,
  Map,
  Object,
  Array,
  Number,
  String,
  Boolean,
  encodeURIComponent,
  decodeURIComponent,
};

vm.runInNewContext(source, sandbox, { filename: process.argv[2] });
const beforeConsent = containers.map((entry) => entry.child.kind);

cookie = 'cookie_social=true';
windowBus.dispatch('cookielad:consent-change');
const afterGrant = containers.map((entry) => entry.child.kind);
const youtubeAttributes = Object.fromEntries(containers[0].child.attributes);
const vimeoAttributes = Object.fromEntries(containers[1].child.attributes);

cookie = '';
windowBus.dispatch('cookielad:consent-change');
const afterRevoke = containers.map((entry) => entry.child.kind);
const exactPlaceholdersRestored = containers.every(
  (entry, index) => entry.child === placeholders[index],
);

cookie = 'cookie_social=true';
windowRef.LiquidStackBlogPublic.syncConsent();
const afterRuntimeSync = containers.map((entry) => entry.child.kind);
windowRef.LiquidStackBlogPublic.destroy();
const afterDestroy = containers.map((entry) => entry.child.kind);

process.stdout.write(JSON.stringify({
  beforeConsent,
  afterGrant,
  youtubeAttributes,
  vimeoAttributes,
  afterRevoke,
  exactPlaceholdersRestored,
  afterRuntimeSync,
  afterDestroy,
}));
