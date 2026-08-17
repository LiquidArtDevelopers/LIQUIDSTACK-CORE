import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const rootPath = resolve(process.argv[2] ?? '.');
const resourcePath = resolve(
  rootPath,
  'modules/blog/resources/project/src/js/resources/_sectionBlogSlider01.js',
);

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

  emit(type, overrides = {}) {
    const event = {
      type,
      target: this,
      relatedTarget: null,
      altKey: false,
      ctrlKey: false,
      metaKey: false,
      key: '',
      defaultPrevented: false,
      immediatePropagationStopped: false,
      preventDefault() {
        this.defaultPrevented = true;
      },
      stopImmediatePropagation() {
        this.immediatePropagationStopped = true;
      },
      ...overrides,
    };
    for (const listener of [...(this.listeners.get(type) ?? [])]) {
      listener(event);
      if (event.immediatePropagationStopped) {
        break;
      }
    }

    return event;
  }
}

class FakeClassList {
  constructor(...values) {
    this.values = new Set(values);
  }

  add(...values) {
    values.forEach((value) => this.values.add(value));
  }

  remove(...values) {
    values.forEach((value) => this.values.delete(value));
  }

  contains(value) {
    return this.values.has(value);
  }

  [Symbol.iterator]() {
    return this.values[Symbol.iterator]();
  }
}

class FakeNode extends FakeEventTarget {
  constructor({ kind = 'generic', classes = [], dataset = {}, children = [] } = {}) {
    super();
    this.kind = kind;
    this.classList = new FakeClassList(...classes);
    this.dataset = { ...dataset };
    this.children = [];
    this.attributes = new Map();
    this.selectors = new Map();
    this.style = { height: '', pointerEvents: '' };
    this.hidden = false;
    this.disabled = false;
    this.isConnected = true;
    this.ownerDocument = null;
    this.parentElement = null;
    this.offsetWidth = 0;
    this.offsetHeight = 0;
    this.offsetLeft = 0;
    this.clientWidth = 0;
    this.scrollWidth = 0;
    this.scrollLeft = 0;
    this.lastScrollBehavior = null;
    children.forEach((child) => this.appendChild(child));
  }

  appendChild(child) {
    if (child?.kind === 'fragment') {
      for (const fragmentChild of [...child.children]) {
        this.appendChild(fragmentChild);
      }
      child.children = [];
      return child;
    }
    child.parentElement = this;
    child.ownerDocument = this.ownerDocument;
    this.children.push(child);

    return child;
  }

  insertBefore(child, reference) {
    const index = Math.max(0, this.children.indexOf(reference));
    const incoming = child?.kind === 'fragment'
      ? [...child.children]
      : [child];
    incoming.forEach((candidate) => {
      candidate.parentElement = this;
      candidate.ownerDocument = this.ownerDocument;
    });
    this.children.splice(index, 0, ...incoming);
    if (child?.kind === 'fragment') {
      child.children = [];
    }

    return child;
  }

  remove() {
    if (!this.parentElement) {
      return;
    }
    const index = this.parentElement.children.indexOf(this);
    if (index >= 0) {
      this.parentElement.children.splice(index, 1);
    }
    this.parentElement = null;
    this.isConnected = false;
  }

  get firstChild() {
    return this.children[0] ?? null;
  }

  matches(selector) {
    if (selector === '[data-blog-slider]') {
      return Object.hasOwn(this.dataset, 'blogSlider');
    }
    if (selector === '.sectionBlogSlider01-item') {
      return this.classList.contains('sectionBlogSlider01-item');
    }
    if (selector === '[data-blog-slider01-clone]') {
      return this.hasAttribute('data-blog-slider01-clone');
    }

    return false;
  }

  descendants() {
    return this.children.flatMap((child) => [child, ...child.descendants()]);
  }

  querySelector(selector) {
    return this.selectors.get(selector)
      ?? this.descendants().find((candidate) => candidate.matches(selector))
      ?? null;
  }

  querySelectorAll(selector) {
    const descendants = this.descendants();
    if (selector === '*') {
      return descendants;
    }
    if (selector.includes('a[href]')) {
      return descendants.filter((candidate) => candidate.kind === 'link');
    }

    return descendants.filter((candidate) => candidate.matches(selector));
  }

  setAttribute(name, value) {
    this.attributes.set(name, String(value));
  }

  getAttribute(name) {
    return this.attributes.get(name) ?? null;
  }

  hasAttribute(name) {
    return this.attributes.has(name);
  }

  removeAttribute(name) {
    this.attributes.delete(name);
    if (name === 'data-blog-card-key') {
      delete this.dataset.blogCardKey;
    }
  }

  cloneNode(deep = false) {
    const clone = new FakeNode({
      kind: this.kind,
      classes: [...this.classList],
      dataset: { ...this.dataset },
    });
    clone.attributes = new Map(this.attributes);
    clone.offsetWidth = this.offsetWidth;
    clone.offsetHeight = this.offsetHeight;
    clone.offsetLeft = this.offsetLeft;
    if (deep) {
      this.children.forEach((child) => clone.appendChild(child.cloneNode(true)));
    }

    return clone;
  }

  contains(candidate) {
    return candidate === this
      || this.children.some((child) => child.contains(candidate));
  }

  closest(selector) {
    let candidate = this;
    while (candidate) {
      if (candidate.matches?.(selector)) {
        return candidate;
      }
      candidate = candidate.parentElement;
    }

    return null;
  }

  getBoundingClientRect() {
    const left = this.offsetLeft + (this.__x ?? 0);
    const width = this.offsetWidth || this.clientWidth;

    return { left, right: left + width, width };
  }

  scrollBy({ left, behavior }) {
    this.scrollLeft += left;
    this.lastScrollBehavior = behavior;
  }

  scrollTo({ left, behavior }) {
    this.scrollLeft = left;
    this.lastScrollBehavior = behavior;
  }
}

class FakeDocument extends FakeEventTarget {
  constructor(view, roots) {
    super();
    this.nodeType = 9;
    this.defaultView = view;
    this.hidden = false;
    this.roots = roots;
    roots.forEach((root) => this.adopt(root));
  }

  adopt(node) {
    node.ownerDocument = this;
    node.children.forEach((child) => this.adopt(child));
  }

  querySelectorAll(selector) {
    return this.roots.filter((root) => root.matches(selector));
  }

  createElement(kind) {
    const element = new FakeNode({ kind });
    element.ownerDocument = this;

    return element;
  }

  createDocumentFragment() {
    const fragment = new FakeNode({ kind: 'fragment' });
    fragment.ownerDocument = this;

    return fragment;
  }
}

class FakeResizeObserver {
  constructor(callback) {
    this.callback = callback;
    this.disconnected = false;
  }

  observe() {}

  disconnect() {
    this.disconnected = true;
  }
}

const nextTick = () => new Promise((resolveTick) => setTimeout(resolveTick, 0));

const tweens = [];
const delayedCalls = [];
let deferTweenCompletion = false;
const gsap = {
  registerPlugin() {},
  getProperty(target, property) {
    return property === 'x' ? (target.__x ?? 0) : 0;
  },
  set(targets, properties) {
    const list = Array.isArray(targets) ? targets : [targets];
    list.forEach((target, index) => {
      if (Object.hasOwn(properties, 'x')) {
        target.__x = typeof properties.x === 'function'
          ? properties.x(index)
          : properties.x;
      }
      if (Object.hasOwn(properties, 'height')) {
        target.__height = properties.height;
      }
      if (properties.clearProps) {
        delete target.__x;
        delete target.__height;
      }
    });
  },
  quickSetter(target) {
    return (value) => {
      target.__x = value;
    };
  },
  to(target, properties) {
    let killed = false;
    const complete = () => {
      if (killed) {
        return;
      }
      if (Object.hasOwn(properties, 'x')) {
        target.__x = properties.x;
      }
      properties.onUpdate?.();
      properties.onComplete?.();
    };
    const tween = {
      kill() {
        if (killed) {
          return;
        }
        killed = true;
        properties.onInterrupt?.();
      },
      complete,
      get killed() {
        return killed;
      },
    };
    tweens.push({ target, properties, tween });
    if (!deferTweenCompletion) {
      queueMicrotask(complete);
    }

    return tween;
  },
  delayedCall(delay, callback) {
    const call = {
      delay,
      callback,
      killed: false,
      kill() {
        this.killed = true;
      },
    };
    delayedCalls.push(call);

    return call;
  },
  utils: {
    wrap(minimum, maximum) {
      const width = maximum - minimum;

      return (value) => ((value - minimum) % width + width) % width + minimum;
    },
  },
};

const draggableRecords = [];
const Draggable = {
  create(proxy, options) {
    const instance = {
      proxy,
      options,
      isThrowing: false,
      killed: false,
      update() {},
      kill() {
        this.killed = true;
      },
    };
    draggableRecords.push(instance);

    return [instance];
  },
};

const makeRoot = (amount, cardWidth, viewportWidth) => {
  const cards = Array.from({ length: amount }, (_, index) => {
    const image = new FakeNode({ kind: 'image' });
    const link = new FakeNode({ kind: 'link' });
    link.setAttribute('href', `/blog/card-${amount}-${index}`);
    const heading = new FakeNode({ kind: 'heading', children: [link] });
    heading.setAttribute('id', `heading-${amount}-${index}`);
    const card = new FakeNode({
      kind: 'card',
      classes: ['sectionBlogSlider01-item'],
      dataset: { blogCardKey: `card-${amount}-${index}` },
      children: [image, heading],
    });
    card.setAttribute('aria-labelledby', `heading-${amount}-${index}`);
    card.offsetWidth = cardWidth;
    card.offsetHeight = 240 + index;
    card.offsetLeft = index * (cardWidth + 20);

    return card;
  });
  const track = new FakeNode({ kind: 'track', children: cards });
  const viewport = new FakeNode({ kind: 'viewport' });
  viewport.clientWidth = viewportWidth;
  viewport.offsetWidth = viewportWidth;
  viewport.scrollWidth = amount * (cardWidth + 20);
  const controls = new FakeNode({ kind: 'controls' });
  controls.hidden = true;
  const previous = new FakeNode({ kind: 'button' });
  const next = new FakeNode({ kind: 'button' });
  const autoplay = new FakeNode({
    kind: 'button',
    dataset: { pauseLabel: 'Pause', resumeLabel: 'Resume' },
  });
  autoplay.hidden = true;
  const root = new FakeNode({
    kind: 'root',
    classes: ['sectionBlogSlider01'],
    dataset: {
      blogSlider: '',
      blogSliderAutoplay: 'true',
      blogSliderAutoplayDelay: '6',
      blogSliderDuration: '1',
    },
    children: [viewport, track, controls, previous, next, autoplay],
  });
  root.selectors.set('[data-blog-slider-viewport]', viewport);
  root.selectors.set('[data-blog-slider-track]', track);
  root.selectors.set('[data-blog-slider-controls]', controls);
  root.selectors.set('[data-blog-slider-previous]', previous);
  root.selectors.set('[data-blog-slider-next]', next);
  root.selectors.set('[data-blog-slider-autoplay-toggle]', autoplay);

  return { root, cards, track, viewport, controls, previous, next, autoplay };
};

globalThis.__legacySliderGsap = gsap;
globalThis.__legacySliderDraggable = Draggable;
globalThis.__legacySliderInertia = {};
let source = await readFile(resourcePath, 'utf8');
source = source.replace(
  "import gsap from 'gsap';",
  'const gsap = globalThis.__legacySliderGsap;',
).replace(
  "import { Draggable, InertiaPlugin } from 'gsap/all';",
  'const Draggable = globalThis.__legacySliderDraggable;\n'
    + 'const InertiaPlugin = globalThis.__legacySliderInertia;',
);
const moduleUrl = `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
const module = await import(`${moduleUrl}#initial`);

const motionQuery = new FakeEventTarget();
motionQuery.matches = false;
const view = new FakeEventTarget();
view.AbortController = AbortController;
view.ResizeObserver = FakeResizeObserver;
view.matchMedia = () => motionQuery;
view.requestAnimationFrame = (callback) => setTimeout(callback, 0);
view.cancelAnimationFrame = clearTimeout;
view.setTimeout = setTimeout;
view.clearTimeout = clearTimeout;
view.performance = { now: () => 1000 };
view.getComputedStyle = (node) => {
  if (node.kind === 'viewport') {
    return { direction: 'ltr' };
  }
  if (node.kind === 'track') {
    return {
      columnGap: '20px',
      gap: '20px',
      paddingInlineStart: '0px',
    };
  }

  return {};
};

const first = makeRoot(4, 300, 600);
const second = makeRoot(3, 250, 1000);
const single = makeRoot(1, 300, 600);
const documentRef = new FakeDocument(view, [
  first.root,
  second.root,
  single.root,
]);
const cleanup = module.initSectionBlogSlider01(documentRef);
await nextTick();

assert.equal(draggableRecords.length, 3);
assert.equal(first.root.classList.contains('sectionBlogSlider01--enhanced'), true);
assert.equal(second.root.classList.contains('sectionBlogSlider01--enhanced'), true);
assert.equal(single.root.classList.contains('sectionBlogSlider01--enhanced'), true);
assert.equal(first.controls.hidden, false);
assert.equal(first.autoplay.hidden, false);
assert.equal(single.controls.hidden, false);
assert.equal(single.autoplay.hidden, false);
assert.equal(second.track.children.length, 15);
assert.equal(single.track.children.length, 5);
const loopClones = [...second.track.children, ...single.track.children].filter(
  (candidate) => candidate.hasAttribute('data-blog-slider01-clone'),
);
assert.equal(loopClones.length, 16);
loopClones.forEach((clone) => {
  assert.equal(clone.getAttribute('aria-hidden'), 'true');
  assert.equal(clone.hasAttribute('inert'), true);
  assert.equal(clone.hasAttribute('aria-labelledby'), false);
  assert.equal(Object.hasOwn(clone.dataset, 'blogCardKey'), false);
  clone.descendants().forEach((descendant) => {
    assert.equal(descendant.hasAttribute('id'), false);
    assert.equal(descendant.hasAttribute('aria-labelledby'), false);
    if (descendant.kind === 'link') {
      assert.equal(descendant.getAttribute('tabindex'), '-1');
    }
  });
});
assert.equal(draggableRecords[0].options.inertia, true);
assert.equal(draggableRecords[0].options.snap.x(-349), -320);

first.next.emit('click');
await Promise.resolve();
await Promise.resolve();
assert.equal(draggableRecords[0].proxy.__x, -320);

first.viewport.emit('keydown', { key: 'End' });
await Promise.resolve();
await Promise.resolve();
assert.equal(draggableRecords[0].proxy.__x, -960);
const keyboardEnd = draggableRecords[0].proxy.__x;

const drag = draggableRecords[0];
drag.options.onPress.call(drag);
drag.options.onDragStart.call(drag);
drag.proxy.__x = -910;
drag.options.onDrag.call(drag);
drag.options.onRelease.call(drag);
const suppressedClick = first.viewport.emit('click');
assert.equal(suppressedClick.defaultPrevented, true);
assert.equal(suppressedClick.immediatePropagationStopped, true);

first.autoplay.emit('click');
assert.equal(first.autoplay.getAttribute('aria-pressed'), 'true');
const pauseExposed = first.autoplay.getAttribute('aria-pressed') === 'true';
assert.ok(delayedCalls.some((call) => call.delay === 6));

const appendedCard = new FakeNode({
  kind: 'card',
  classes: ['sectionBlogSlider01-item'],
  dataset: { blogCardKey: 'single-appended' },
});
appendedCard.offsetWidth = 300;
appendedCard.offsetHeight = 240;
appendedCard.offsetLeft = 320;
single.track.appendChild(appendedCard);
documentRef.emit('liquidstack:blog-collection-appended', {
  detail: { root: single.root, items: [appendedCard] },
});
await nextTick();
const singleOriginalsAfterAppend = single.track.children.filter(
  (candidate) => !candidate.hasAttribute('data-blog-slider01-clone'),
);
const singleClonesAfterAppend = single.track.children.filter(
  (candidate) => candidate.hasAttribute('data-blog-slider01-clone'),
);
assert.equal(singleOriginalsAfterAppend.length, 2);
assert.equal(singleClonesAfterAppend.length, 4);
assert.deepEqual(
  singleOriginalsAfterAppend.map((card) => card.dataset.blogCardKey),
  ['card-1-0', 'single-appended'],
);

appendedCard.remove();
const reloadedModule = await import(`${moduleUrl}#hot-reload`);
const cleanupReloaded = reloadedModule.initSectionBlogSlider01(documentRef);
await nextTick();
const singleOriginalsAfterReload = single.track.children.filter(
  (candidate) => !candidate.hasAttribute('data-blog-slider01-clone'),
);
const singleClonesAfterReload = single.track.children.filter(
  (candidate) => candidate.hasAttribute('data-blog-slider01-clone'),
);
assert.equal(
  single.root.classList.contains('sectionBlogSlider01--enhanced'),
  true,
);
assert.equal(single.controls.hidden, false);
assert.equal(singleOriginalsAfterReload.length, 1);
assert.equal(singleClonesAfterReload.length, 4);
assert.equal(draggableRecords.slice(0, 4).every((record) => record.killed), true);
deferTweenCompletion = true;
const hotReloadTweenCount = tweens.length;
const hotReloadDraggableCount = draggableRecords.length;
single.next.emit('click');
assert.equal(tweens.length, hotReloadTweenCount + 1);
assert.equal(tweens.at(-1).properties.x, -320);
const hotReloadTween = tweens.at(-1).tween;
const cloneImage = singleClonesAfterReload[0].descendants().find(
  (descendant) => descendant.kind === 'image',
);
assert.ok(cloneImage);
single.track.emit('load', { target: cloneImage });
await nextTick();
assert.equal(hotReloadTween.killed, false);
assert.equal(draggableRecords.length, hotReloadDraggableCount);
const cloneLoadIgnoredDuringMotion = true;
hotReloadTween.complete();
single.viewport.emit('keydown', { key: 'ArrowLeft' });
assert.equal(tweens.length, hotReloadTweenCount + 2);
assert.equal(tweens.at(-1).properties.x, 320);
assert.equal(tweens.at(-1).tween.killed, false);
tweens.at(-1).tween.complete();
deferTweenCompletion = false;
const hotReloadInteractionWorks = true;

cleanup();
const staleCleanupPreservedOwner = (
  single.root.classList.contains('sectionBlogSlider01--enhanced')
  && singleClonesAfterReload.every((clone) => clone.isConnected)
);
assert.equal(staleCleanupPreservedOwner, true);

cleanupReloaded();
assert.equal(first.root.classList.contains('sectionBlogSlider01--enhanced'), false);
assert.equal(first.controls.hidden, true);
assert.equal(second.track.children.length, 3);
assert.equal(single.track.children.length, 1);

motionQuery.matches = true;
const reduced = makeRoot(4, 300, 600);
const reducedDocument = new FakeDocument(view, [reduced.root]);
const reducedCleanup = module.initSectionBlogSlider01(reducedDocument);
await nextTick();
assert.equal(draggableRecords.length, 7);
assert.equal(reduced.root.classList.contains('sectionBlogSlider01--enhanced'), false);
assert.equal(reduced.controls.hidden, false);
reduced.next.emit('click');
assert.equal(reduced.viewport.lastScrollBehavior, 'auto');
reducedCleanup();

process.stdout.write(JSON.stringify({
  enhancedInstances: 3,
  inertia: drag.options.inertia,
  keyboardEnd,
  dragClickSuppressed: suppressedClick.defaultPrevented,
  pauseExposed,
  reducedMotionFallback: reduced.viewport.lastScrollBehavior === 'auto',
  loopCopies: loopClones.length,
  appendOriginals: singleOriginalsAfterAppend.length,
  singleClonesAfterHotReload: singleClonesAfterReload.length,
  staleCleanupPreservedOwner,
  hotReloadInteractionWorks,
  cloneLoadIgnoredDuringMotion,
  cleaned: drag.killed && first.controls.hidden,
}));
