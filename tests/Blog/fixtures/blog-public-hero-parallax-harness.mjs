import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(process.argv[2], 'utf8');

function bus() {
  const listeners = new Map();
  return {
    addEventListener(type, handler, options = {}) {
      const current = listeners.get(type) || [];
      current.push(handler);
      listeners.set(type, current);
      options.signal?.addEventListener('abort', () => {
        listeners.set(
          type,
          (listeners.get(type) || []).filter((entry) => entry !== handler),
        );
      }, { once: true });
    },
    dispatch(type) {
      (listeners.get(type) || []).slice().forEach((handler) => handler({}));
    },
    count(type) {
      return (listeners.get(type) || []).length;
    },
  };
}

const windowBus = bus();
const documentBus = bus();
const removed = [];
const style = {
  transform: '',
  willChange: '',
  removeProperty(name) {
    removed.push(name);
    if (name === 'transform') this.transform = '';
    if (name === 'will-change') this.willChange = '';
  },
};
const media = { style };
let heroTop = 700;
const hero = {
  querySelector(selector) {
    return selector === '.hero00-media' ? media : null;
  },
  getBoundingClientRect() {
    return { top: heroTop, height: 800 };
  },
};
let frameId = 0;
const frames = new Map();
const windowRef = {
  innerHeight: 900,
  location: { protocol: 'https:' },
  addEventListener: windowBus.addEventListener,
  requestAnimationFrame(callback) {
    frameId += 1;
    frames.set(frameId, callback);
    return frameId;
  },
  cancelAnimationFrame(id) {
    frames.delete(id);
  },
  matchMedia() {
    return { matches: false };
  },
};
const documentRef = {
  visibilityState: 'visible',
  addEventListener: documentBus.addEventListener,
  get cookie() { return ''; },
  set cookie(value) {},
  getElementById() { return null; },
  querySelector() { return null; },
  querySelectorAll(selector) {
    return selector === '.hero00' ? [hero] : [];
  },
  createElement() {
    throw new Error('No debe crear elementos en este escenario.');
  },
};
const sandbox = {
  window: windowRef,
  document: documentRef,
  AbortController,
  Map,
  Array,
  Math,
  Number,
  String,
  Boolean,
  encodeURIComponent,
  decodeURIComponent,
};

vm.runInNewContext(source, sandbox, { filename: process.argv[2] });

function flushFrame() {
  const next = frames.entries().next().value;
  if (!next) return;
  frames.delete(next[0]);
  next[1]();
}

flushFrame();
const initialTransform = style.transform;
heroTop = -250;
windowBus.dispatch('scroll');
flushFrame();
const scrolledTransform = style.transform;
windowRef.LiquidStackBlogPublic.destroy();

process.stdout.write(JSON.stringify({
  initialized: initialTransform.includes('translate3d')
    && initialTransform.includes('scale(1.2)'),
  moved: scrolledTransform !== initialTransform,
  cleaned: style.transform === ''
    && style.willChange === ''
    && removed.includes('transform')
    && removed.includes('will-change'),
  scrollListenersAfterDestroy: windowBus.count('scroll'),
  runtimeRemoved: windowRef.LiquidStackBlogPublic === undefined,
}));
