import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(process.argv[2], 'utf8');
const documentListeners = [];
const windowListeners = [];
const timers = new Map();
const created = [];
let timerSequence = 0;
let activeElement = null;

const addListener = (collection, type, handler, options = {}) => {
  const entry = { type, handler };
  collection.push(entry);
  options.signal?.addEventListener('abort', () => {
    const position = collection.indexOf(entry);
    if (position >= 0) collection.splice(position, 1);
  }, { once: true });
};

class ElementFixture {
  constructor(tag = 'div') {
    this.tag = tag;
    this.className = '';
    this.textContent = '';
    this.dataset = {};
    this.children = [];
    this.listeners = [];
    this.attributes = new Map();
    this.hidden = false;
    this.disabled = false;
    this.open = false;
    this.removed = false;
  }

  append(...nodes) { this.children.push(...nodes); }
  setAttribute(name, value) { this.attributes.set(name, String(value)); }
  getAttribute(name) { return this.attributes.get(name) ?? null; }
  addEventListener(type, handler, options) {
    addListener(this.listeners, type, handler, options);
  }
  dispatch(type) {
    this.listeners.filter((entry) => entry.type === type)
      .forEach((entry) => entry.handler({ preventDefault() {} }));
  }
  closest() { return null; }
  focus() {
    if (this.disabled) return;
    activeElement = this;
    this.focused = true;
  }
  remove() { this.removed = true; }
}

class DialogFixture extends ElementFixture {
  constructor() { super('dialog'); }
  showModal() { this.open = true; }
  close() {
    this.open = false;
    this.dispatch('close');
  }
  removeAttribute(name) {
    if (name === 'open') this.open = false;
    this.attributes.delete(name);
  }
}

class FrameFixture extends ElementFixture {
  constructor() {
    super('iframe');
    this._src = '';
    this.contentWindow = { location: { href: 'about:blank' } };
    this.contentDocument = null;
  }
  set src(value) { this._src = value; }
  get src() { return this._src; }
}

class AnchorFixture extends ElementFixture {
  constructor(href, title) {
    super('a');
    this.href = href;
    this.target = '';
    this.dataset.blogPreviewTitle = title;
  }
  closest(selector) {
    return selector === '[data-blog-private-preview]' ? this : null;
  }
}

class InputFixture extends ElementFixture {}
const host = new ElementFixture('host');
const documentRef = {
  body: host,
  createElement(tag) {
    const node = tag === 'dialog'
      ? new DialogFixture()
      : (tag === 'iframe' ? new FrameFixture() : new ElementFixture(tag));
    created.push(node);
    return node;
  },
  querySelector(selector) {
    return selector === '.webadmin' ? host : null;
  },
  querySelectorAll() { return []; },
  addEventListener(type, handler, options) {
    addListener(documentListeners, type, handler, options);
  },
};

const location = {
  href: 'https://example.test/admin/blog',
  origin: 'https://example.test',
};
const windowRef = {
  location,
  addEventListener(type, handler, options) {
    addListener(windowListeners, type, handler, options);
  },
  requestAnimationFrame(handler) { handler(); },
  setTimeout(handler, delay) {
    const id = ++timerSequence;
    timers.set(id, { handler, delay });
    return id;
  },
  clearTimeout(id) { timers.delete(id); },
};

const sandbox = {
  window: windowRef,
  document: documentRef,
  AbortController,
  Element: ElementFixture,
  HTMLAnchorElement: AnchorFixture,
  HTMLDialogElement: DialogFixture,
  HTMLFormElement: ElementFixture,
  HTMLInputElement: InputFixture,
  URL,
  Symbol,
};
vm.runInNewContext(source, sandbox, { filename: process.argv[2] });

const dispatchClick = (target) => {
  const event = {
    target,
    button: 0,
    ctrlKey: false,
    metaKey: false,
    shiftKey: false,
    altKey: false,
    preventDefault() {},
  };
  documentListeners.filter((entry) => entry.type === 'click')
    .forEach((entry) => entry.handler(event));
};
const timerEntry = () => {
  const current = timers.entries().next();
  if (current.done) throw new Error('Expected preview timeout.');
  return { id: current.value[0], ...current.value[1] };
};
const runTimer = (entry) => {
  timers.delete(entry.id);
  entry.handler();
};

const firstUrl = 'https://example.test/admin/blog/editor/preview?post=first&locale=es';
const secondUrl = 'https://example.test/admin/blog/editor/preview?post=second&locale=en';
const thirdUrl = 'https://example.test/admin/blog/editor/preview?post=third&locale=es';
const first = new AnchorFixture(firstUrl, 'Primero');
const second = new AnchorFixture(secondUrl, 'Segundo');
const third = new AnchorFixture(thirdUrl, 'Tercero');

dispatchClick(first);
const dialog = created.find((node) => node instanceof DialogFixture);
const frame = created.find((node) => node instanceof FrameFixture);
const status = created.find(
  (node) => node.className === 'blogEditor__immersivePreviewStatus'
);
const deviceButtons = created.filter(
  (node) => typeof node.dataset.blogPreviewDevice === 'string'
);
const closeButton = created.find(
  (node) => node.dataset.blogPreviewClose === 'true'
);
const initialFocusIsClose = activeElement === closeButton;
const firstTimeout = timerEntry();
const timeoutDelay = firstTimeout.delay;

frame.contentWindow.location.href = firstUrl;
frame.contentDocument = {
  documentElement: { dataset: { blogPreviewReady: 'true' } },
  querySelectorAll() { return [{ sheet: {} }]; },
};
frame.dispatch('load');
const validLoad = {
  timerCount: timers.size,
  state: status.dataset.state,
  text: status.textContent,
  devicesEnabled: deviceButtons.every((button) => !button.disabled),
};

dialog.close();
dispatchClick(first);
const staleTimeout = timerEntry();
dialog.close();
dispatchClick(second);
const currentTimeout = timerEntry();
staleTimeout.handler();
const afterStale = {
  timerCount: timers.size,
  state: status.dataset.state,
  src: frame.src,
  hidden: frame.hidden,
};
frame.contentWindow.location.href = firstUrl;
frame.contentDocument = {
  documentElement: { dataset: { blogPreviewReady: 'true' } },
  querySelectorAll() { return [{ sheet: {} }]; },
};
frame.dispatch('load');
const afterStaleLoad = {
  timerCount: timers.size,
  state: status.dataset.state,
  src: frame.src,
  hidden: frame.hidden,
  devicesDisabled: deviceButtons.every((button) => button.disabled),
};
runTimer(currentTimeout);
const afterCurrentTimeout = {
  timerCount: timers.size,
  state: status.dataset.state,
  src: frame.src,
  hidden: frame.hidden,
  devicesDisabled: deviceButtons.every((button) => button.disabled),
};

dialog.close();
dispatchClick(third);
const disposeTimerCount = timers.size;
windowRef[Symbol.for('liquidstack.blog.admin-list')].dispose();

process.stdout.write(JSON.stringify({
  timeoutDelay,
  initialFocusIsClose,
  validLoad,
  afterStale,
  afterStaleLoad,
  afterCurrentTimeout,
  disposeTimerCount,
  timersAfterDispose: timers.size,
  dialogRemoved: dialog.removed,
}));
