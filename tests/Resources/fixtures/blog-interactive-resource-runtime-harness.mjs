import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

const rootPath = resolve(process.argv[2] ?? '.');
const resources = resolve(
  rootPath,
  'modules/blog/resources/project/src/js/resources',
);

const importResource = async (file, replacements, identity = 'default') => {
  let source = await readFile(resolve(resources, file), 'utf8');
  for (const [from, to] of replacements) {
    source = source.replace(from, to);
  }

  const moduleUrl = `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;

  return import(`${moduleUrl}#${encodeURIComponent(identity)}`);
};

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

  emit(type, overrides = {}) {
    const event = {
      type,
      target: this,
      relatedTarget: null,
      altKey: false,
      ctrlKey: false,
      metaKey: false,
      shiftKey: false,
      key: '',
      deltaX: 0,
      deltaY: 0,
      deltaMode: 0,
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
  constructor(...classNames) {
    this.values = new Set(classNames);
  }

  add(...classNames) {
    classNames.forEach((className) => this.values.add(className));
  }

  remove(...classNames) {
    classNames.forEach((className) => this.values.delete(className));
  }

  contains(className) {
    return this.values.has(className);
  }

  [Symbol.iterator]() {
    return this.values[Symbol.iterator]();
  }
}

class FakeStyle {
  constructor() {
    this.values = new Map();
    this.height = '';
    this.pointerEvents = '';
  }

  setProperty(name, value) {
    this.values.set(name, String(value));
  }

  removeProperty(name) {
    this.values.delete(name);
    if (name === 'height') {
      this.height = '';
    }
  }
}

class FakeNode extends FakeEventTarget {
  constructor({
    kind = 'generic',
    id = '',
    classes = [],
    dataset = {},
    children = [],
  } = {}) {
    super();
    this.kind = kind;
    this.id = id;
    this.classList = new FakeClassList(...classes);
    this.dataset = { ...dataset };
    this.children = [];
    this.attributes = new Map();
    this.style = new FakeStyle();
    this.hidden = false;
    this.disabled = false;
    this.textContent = '';
    this.ownerDocument = null;
    this.parentElement = null;
    this.isConnected = true;
    this.offsetWidth = 0;
    this.offsetHeight = 0;
    this.offsetLeft = 0;
    this.scrollWidth = 0;
    this.clientWidth = 0;
    this.scrollLeft = 0;
    this.selectors = new Map();
    children.forEach((child) => this.appendChild(child));
  }

  matches(selector) {
    if (selector === '.sectionBlogSlider02-item') {
      return this.classList.contains('sectionBlogSlider02-item');
    }
    if (selector === '[data-blog-card-key]') {
      return typeof this.dataset.blogCardKey === 'string';
    }
    if (selector === '[data-blog-collection="sectionBlogSlider02"]') {
      return this.dataset.blogCollection === 'sectionBlogSlider02';
    }
    if (selector === '[data-blog-stack01]') {
      return Object.hasOwn(this.dataset, 'blogStack01');
    }

    return false;
  }

  hasAttribute(name) {
    return this.attributes.has(name);
  }

  setAttribute(name, value) {
    this.attributes.set(name, String(value));
    if (name === 'id') {
      this.id = String(value);
    }
  }

  getAttribute(name) {
    if (name === 'id' && this.id !== '') {
      return this.id;
    }

    return this.attributes.has(name) ? this.attributes.get(name) : null;
  }

  removeAttribute(name) {
    this.attributes.delete(name);
    if (name === 'id') {
      this.id = '';
    }
    if (name === 'data-blog-card-key') {
      delete this.dataset.blogCardKey;
    }
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

  contains(candidate) {
    if (candidate === this) {
      return true;
    }

    return this.children.some((child) => child.contains(candidate));
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

  descendants() {
    return this.children.flatMap((child) => [child, ...child.descendants()]);
  }

  querySelector(selector) {
    if (this.selectors.has(selector)) {
      return this.selectors.get(selector);
    }

    return this.descendants().find((candidate) => candidate.matches(selector))
      ?? null;
  }

  querySelectorAll(selector) {
    const descendants = this.descendants();
    if (selector === '*') {
      return descendants;
    }
    if (selector === '[id]') {
      return descendants.filter((candidate) => candidate.id !== '');
    }
    if (selector.includes('[aria-labelledby]')) {
      return descendants.filter((candidate) => [
        'aria-labelledby',
        'aria-describedby',
        'aria-controls',
        'for',
      ].some((name) => candidate.hasAttribute(name)));
    }
    if (selector.includes('a[href]')) {
      return descendants.filter((candidate) => candidate.kind === 'link');
    }
    if (selector === '.sectionBlogSlider02-item') {
      return descendants.filter((candidate) => candidate.matches(selector));
    }
    if (selector === '[data-blog-card-key]') {
      return descendants.filter((candidate) => candidate.matches(selector));
    }

    return [];
  }

  cloneNode(deep = false) {
    const clone = new FakeNode({
      kind: this.kind,
      id: this.id,
      classes: [...this.classList],
      dataset: { ...this.dataset },
    });
    clone.attributes = new Map(this.attributes);
    clone.hidden = this.hidden;
    clone.textContent = this.textContent;
    clone.offsetWidth = this.offsetWidth;
    clone.offsetHeight = this.offsetHeight;
    clone.offsetLeft = this.offsetLeft;
    if (deep) {
      this.children.forEach((child) => clone.appendChild(child.cloneNode(true)));
    }

    return clone;
  }

  getBoundingClientRect() {
    return {
      left: this.offsetLeft + (this.__x ?? 0),
      height: this.offsetHeight,
    };
  }

  scrollBy({ left }) {
    this.scrollLeft += left;
  }
}

class FakeDocument extends FakeEventTarget {
  constructor(view, roots) {
    super();
    this.nodeType = 9;
    this.defaultView = view;
    this.roots = roots;
    this.hidden = false;
    this.documentElement = new FakeNode({ kind: 'document-element' });
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
    const node = new FakeNode({ kind });
    node.ownerDocument = this;
    return node;
  }

  createDocumentFragment() {
    const fragment = new FakeNode({ kind: 'fragment' });
    fragment.ownerDocument = this;
    return fragment;
  }
}

const observerRecords = [];

class FakeObserver {
  constructor(callback) {
    this.callback = callback;
    this.targets = new Set();
    this.disconnected = false;
    observerRecords.push(this);
  }

  observe(target) {
    this.targets.add(target);
  }

  disconnect() {
    this.disconnected = true;
    this.targets.clear();
  }
}

const nextTick = () => new Promise((resolveTick) => setTimeout(resolveTick, 0));

const tweenCalls = [];
const delayedCalls = [];
const sliderGsap = {
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
    let paused = false;
    const tween = {
      kill() {
        if (killed) {
          return;
        }
        killed = true;
        properties.onInterrupt?.();
      },
      pause() {
        paused = true;
      },
      resume() {
        paused = false;
      },
    };
    tweenCalls.push({ target, properties, tween });
    queueMicrotask(() => {
      if (killed || paused) {
        return;
      }
      if (Object.hasOwn(properties, 'x')) {
        target.__x = properties.x;
      }
      properties.onUpdate?.();
      properties.onComplete?.();
    });

    return tween;
  },
  delayedCall(_delay, callback) {
    const record = {
      callback,
      killed: false,
      kill() {
        this.killed = true;
      },
    };
    delayedCalls.push(record);

    return record;
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
      killed: false,
      isThrowing: false,
      update() {},
      kill() {
        this.killed = true;
      },
    };
    draggableRecords.push(instance);

    return [instance];
  },
};

const makeSliderCard = (key) => {
  const heading = new FakeNode({ kind: 'heading', id: `${key}-heading` });
  const link = new FakeNode({ kind: 'link' });
  link.setAttribute('href', `/blog/${key}`);
  heading.appendChild(link);
  const cta = new FakeNode({
    kind: 'link',
    classes: ['sectionBlogSlider02-cta'],
  });
  cta.setAttribute('href', `/blog/${key}`);
  const card = new FakeNode({
    kind: 'card',
    classes: ['sectionBlogSlider02-item'],
    dataset: { blogCardKey: key },
    children: [heading, cta],
  });
  card.setAttribute('aria-labelledby', `${key}-heading`);
  card.offsetWidth = 300;
  card.offsetHeight = 200;

  return { card, link, cta };
};

const makeSliderRoot = (id, amount) => {
  const entries = Array.from({ length: amount }, (_, index) => (
    makeSliderCard(`${id}-card-${index}`)
  ));
  entries.forEach(({ card }, index) => {
    card.offsetLeft = index * 320;
  });
  const track = new FakeNode({ kind: 'track', children: entries.map(({ card }) => card) });
  const viewport = new FakeNode({ kind: 'viewport' });
  viewport.clientWidth = 1000;
  viewport.scrollWidth = amount > 1 ? 1300 : 300;
  const controls = new FakeNode({ kind: 'controls' });
  controls.hidden = true;
  const previous = new FakeNode({ kind: 'button' });
  const next = new FakeNode({ kind: 'button' });
  const autoplay = new FakeNode({
    kind: 'button',
    dataset: { pauseLabel: 'Pausar', resumeLabel: 'Reanudar' },
  });
  const status = new FakeNode({
    kind: 'status',
    dataset: { emptyMessage: 'Vacío' },
  });
  const root = new FakeNode({
    kind: 'slider-root',
    id,
    classes: ['sectionBlogSlider02'],
    dataset: {
      blogCollection: 'sectionBlogSlider02',
      blogSlider02Autoplay: 'true',
      blogSlider02AutoplayDelay: '6',
      blogSlider02Duration: '2',
    },
    children: [viewport, track, controls, previous, next, autoplay, status],
  });
  root.selectors.set('[data-blog-slider02-viewport]', viewport);
  root.selectors.set('[data-blog-slider02-track]', track);
  root.selectors.set('[data-blog-slider02-controls]', controls);
  root.selectors.set('[data-blog-slider02-previous]', previous);
  root.selectors.set('[data-blog-slider02-next]', next);
  root.selectors.set('[data-blog-slider02-autoplay-toggle]', autoplay);
  root.selectors.set('[data-blog-collection-status]', status);

  return {
    root,
    track,
    viewport,
    controls,
    previous,
    next,
    autoplay,
    status,
    entries,
  };
};

globalThis.__sliderGsap = sliderGsap;
globalThis.__sliderDraggable = Draggable;
globalThis.__sliderInertiaPlugin = {};
const sliderModuleReplacements = [
  ["import gsap from 'gsap';", 'const gsap = globalThis.__sliderGsap;'],
  [
    "import { Draggable, InertiaPlugin } from 'gsap/all';",
    'const Draggable = globalThis.__sliderDraggable;\n'
      + 'const InertiaPlugin = globalThis.__sliderInertiaPlugin;',
  ],
];
const sliderModule = await importResource(
  '_sectionBlogSlider02.js',
  sliderModuleReplacements,
  'initial',
);

const sliderMotion = new FakeEventTarget();
sliderMotion.matches = false;
const sliderView = new FakeEventTarget();
sliderView.AbortController = AbortController;
sliderView.matchMedia = () => sliderMotion;
sliderView.ResizeObserver = FakeObserver;
sliderView.IntersectionObserver = FakeObserver;
sliderView.requestAnimationFrame = (callback) => setTimeout(callback, 0);
sliderView.cancelAnimationFrame = clearTimeout;
sliderView.setTimeout = setTimeout;
sliderView.clearTimeout = clearTimeout;
sliderView.performance = { now: () => 1000 };
sliderView.getComputedStyle = (node) => {
  if (node.kind === 'viewport') {
    return { direction: 'ltr' };
  }
  if (node.kind === 'track') {
    return {
      columnGap: '20px',
      gap: '20px',
      paddingInlineStart: '10px',
    };
  }

  return {};
};
const sliderMany = makeSliderRoot('slider-many', 4);
const sliderTwo = makeSliderRoot('slider-two', 2);
const sliderThree = makeSliderRoot('slider-three', 3);
const sliderSingle = makeSliderRoot('slider-single', 1);
const sliderEmpty = makeSliderRoot('slider-empty', 0);
const sliderDocument = new FakeDocument(sliderView, [
  sliderMany.root,
  sliderTwo.root,
  sliderThree.root,
  sliderSingle.root,
  sliderEmpty.root,
]);
const cleanupSliders = sliderModule.initSectionBlogSlider02(sliderDocument);
await nextTick();
assert.equal(draggableRecords.length, 4);
assert.equal(sliderMany.controls.hidden, false);
assert.equal(sliderTwo.controls.hidden, false);
assert.equal(sliderThree.controls.hidden, false);
assert.equal(sliderSingle.controls.hidden, false);
assert.equal(sliderSingle.autoplay.hidden, false);
assert.equal(sliderEmpty.status.dataset.state, 'empty');
assert.equal(sliderMany.track.children.length, 12);
assert.equal(sliderTwo.track.children.length, 10);
assert.equal(sliderThree.track.children.length, 15);
assert.equal(sliderSingle.track.children.length, 9);
const sliderClones = sliderMany.track.children.filter((candidate) => (
  candidate.hasAttribute('data-blog-slider02-clone')
));
assert.equal(sliderClones.length, 8);
sliderClones.forEach((clone) => {
  assert.equal(clone.getAttribute('aria-hidden'), 'true');
  assert.equal(clone.hasAttribute('inert'), true);
  assert.equal(clone.hasAttribute('aria-labelledby'), false);
  assert.equal(clone.querySelectorAll('[id]').length, 0);
});
for (const slider of [sliderTwo, sliderThree, sliderSingle]) {
  const originals = slider.track.children.filter((candidate) => (
    !candidate.hasAttribute('data-blog-slider02-clone')
  ));
  const clones = slider.track.children.filter((candidate) => (
    candidate.hasAttribute('data-blog-slider02-clone')
  ));
  assert.equal(originals.length, slider.entries.length);
  assert.ok(clones.length >= slider.entries.length * 2);
  clones.forEach((clone) => {
    assert.equal(Object.hasOwn(clone.dataset, 'blogCardKey'), false);
    assert.equal(clone.getAttribute('aria-hidden'), 'true');
    assert.equal(clone.hasAttribute('inert'), true);
    assert.equal(clone.querySelectorAll('[id]').length, 0);
    clone.querySelectorAll('a[href]').forEach((link) => {
      assert.equal(link.getAttribute('tabindex'), '-1');
    });
  });
  slider.entries.forEach((entry) => {
    assert.equal(entry.link.getAttribute('href'), `/blog/${entry.card.dataset.blogCardKey}`);
    assert.equal(entry.cta.getAttribute('href'), `/blog/${entry.card.dataset.blogCardKey}`);
    assert.equal(entry.link.hasAttribute('tabindex'), false);
    assert.equal(entry.cta.hasAttribute('tabindex'), false);
  });
}

const cleanCtaClick = sliderMany.viewport.emit('click', {
  target: sliderMany.entries[0].cta,
});
assert.equal(cleanCtaClick.defaultPrevented, false);
assert.equal(cleanCtaClick.immediatePropagationStopped, false);

const pointerCtaDraggable = draggableRecords[1];
sliderTwo.root.emit('pointerdown', {
  target: sliderTwo.entries[1].cta,
});
pointerCtaDraggable.options.onPress.call(pointerCtaDraggable);
sliderTwo.root.emit('focusin', {
  target: sliderTwo.entries[1].cta,
});
await Promise.resolve();
await Promise.resolve();
sliderDocument.emit('pointerup', {
  target: sliderTwo.entries[1].cta,
});
pointerCtaDraggable.options.onRelease.call(pointerCtaDraggable);
const pointerCtaClick = sliderTwo.viewport.emit('click', {
  target: sliderTwo.entries[1].cta,
});
assert.equal(Math.abs(pointerCtaDraggable.proxy.__x), 0);
assert.equal(pointerCtaClick.defaultPrevented, false);
assert.equal(pointerCtaClick.immediatePropagationStopped, false);
const pointerCtaClickPreserved = true;

const pointerTitleDraggable = draggableRecords[2];
sliderThree.root.emit('pointerdown', {
  target: sliderThree.entries[2].link,
});
pointerTitleDraggable.options.onPress.call(pointerTitleDraggable);
sliderThree.root.emit('focusin', {
  target: sliderThree.entries[2].link,
});
await Promise.resolve();
await Promise.resolve();
sliderDocument.emit('pointerup', {
  target: sliderThree.entries[2].link,
});
pointerTitleDraggable.options.onRelease.call(pointerTitleDraggable);
const pointerTitleClick = sliderThree.viewport.emit('click', {
  target: sliderThree.entries[2].link,
});
assert.equal(Math.abs(pointerTitleDraggable.proxy.__x), 0);
assert.equal(pointerTitleClick.defaultPrevented, false);
assert.equal(pointerTitleClick.immediatePropagationStopped, false);
const pointerTitleClickPreserved = true;

const singleIntersectionObserver = observerRecords.find((observer) => (
  observer.targets.size === 1 && observer.targets.has(sliderSingle.root)
));
assert.ok(singleIntersectionObserver);
singleIntersectionObserver.callback([{
  target: sliderSingle.root,
  isIntersecting: true,
  intersectionRatio: 1,
}]);
const singleAutoplayCall = [...delayedCalls]
  .reverse()
  .find((record) => !record.killed);
assert.ok(singleAutoplayCall);
singleAutoplayCall.callback();
const singleDraggable = draggableRecords[3];
singleDraggable.proxy.__x = -240;
sliderSingle.root.emit('pointerenter');
assert.equal(Math.abs(singleDraggable.proxy.__x), 0);
const autoplayInterruptNormalized = singleDraggable.proxy.__x === 0;

const focusTarget = sliderMany.entries[3];
sliderMany.root.emit('focusin', { target: focusTarget.link });
sliderMany.root.emit('pointerenter');
await Promise.resolve();
await Promise.resolve();
assert.equal(draggableRecords[0].proxy.__x, -960);
const focusProxy = draggableRecords[0].proxy.__x;

const draggable = draggableRecords[0];
draggable.options.onPress.call(draggable);
draggable.proxy.__x = -950;
draggable.options.onDrag.call(draggable);
draggable.options.onRelease.call(draggable);
const suppressedClick = sliderMany.viewport.emit('click');
assert.equal(suppressedClick.defaultPrevented, true);
assert.equal(suppressedClick.immediatePropagationStopped, true);

const appendedSingle = makeSliderCard('slider-single-appended');
appendedSingle.card.offsetLeft = 320;
sliderSingle.track.appendChild(appendedSingle.card);
sliderDocument.emit('liquidstack:blog-collection-appended', {
  detail: { root: sliderSingle.root, items: [appendedSingle.card] },
});
await nextTick();
const appendedOriginals = sliderSingle.track.querySelectorAll(
  '[data-blog-card-key]',
);
const rebuiltSingleClones = sliderSingle.track.children.filter((candidate) => (
  candidate.hasAttribute('data-blog-slider02-clone')
));
assert.deepEqual(
  appendedOriginals.map((card) => card.dataset.blogCardKey),
  ['slider-single-card-0', 'slider-single-appended'],
);
assert.equal(rebuiltSingleClones.length, 8);

appendedSingle.card.remove();
const reloadedSliderModule = await importResource(
  '_sectionBlogSlider02.js',
  sliderModuleReplacements,
  'hot-reload',
);
const cleanupReloadedSliders = reloadedSliderModule.initSectionBlogSlider02(
  sliderDocument,
);
await nextTick();
const hotReloadSingleClones = sliderSingle.track.children.filter((candidate) => (
  candidate.hasAttribute('data-blog-slider02-clone')
));
assert.equal(
  sliderSingle.root.classList.contains('sectionBlogSlider02--enhanced'),
  true,
);
assert.equal(sliderSingle.controls.hidden, false);
assert.equal(hotReloadSingleClones.length, 8);
assert.equal(sliderSingle.track.querySelectorAll('[data-blog-card-key]').length, 1);
assert.equal(draggableRecords.slice(0, 5).every((record) => record.killed), true);

cleanupSliders();
assert.equal(draggable.killed, true);
const staleCleanupPreservedOwner = (
  sliderSingle.root.classList.contains('sectionBlogSlider02--enhanced')
  && hotReloadSingleClones.every((clone) => clone.isConnected)
);
assert.equal(staleCleanupPreservedOwner, true);

cleanupReloadedSliders();
assert.equal(sliderMany.track.children.length, 4);
assert.equal(sliderTwo.track.children.length, 2);
assert.equal(sliderThree.track.children.length, 3);
assert.equal(sliderSingle.track.children.length, 1);
assert.equal(
  sliderMany.root.classList.contains('sectionBlogSlider02--enhanced'),
  false,
);

const stackCreates = [];
let stackRefreshes = 0;
globalThis.__stackGsap = { registerPlugin() {} };
globalThis.__stackScrollTrigger = {
  create(configuration) {
    const trigger = {
      configuration,
      killed: false,
      kill() {
        this.killed = true;
      },
    };
    stackCreates.push(trigger);

    return trigger;
  },
  refresh() {
    stackRefreshes += 1;
  },
};
const stackModule = await importResource('_sectionBlogStack01.js', [
  ["import gsap from 'gsap';", 'const gsap = globalThis.__stackGsap;'],
  [
    "import ScrollTrigger from 'gsap/ScrollTrigger';",
    'const ScrollTrigger = globalThis.__stackScrollTrigger;',
  ],
]);
const stackMotion = new FakeEventTarget();
stackMotion.matches = true;
const stackView = new FakeEventTarget();
stackView.matchMedia = () => stackMotion;
stackView.innerHeight = 1000;
stackView.requestAnimationFrame = (callback) => setTimeout(callback, 0);
stackView.cancelAnimationFrame = clearTimeout;
stackView.setTimeout = setTimeout;
stackView.clearTimeout = clearTimeout;
stackView.getComputedStyle = (node) => {
  if (node.kind === 'document-element') {
    return { fontSize: '20px', getPropertyValue: () => '' };
  }

  return {
    fontSize: '16px',
    getPropertyValue(name) {
      return {
        '--blog-stack-enabled': '1',
        '--blog-stack-top': '4rem',
        '--blog-stack-step': '0.75rem',
      }[name] ?? '';
    },
  };
};
const stackCards = Array.from({ length: 4 }, (_, index) => {
  const cardNode = new FakeNode({
    kind: 'stack-card',
    classes: ['sectionBlogStack01-item'],
    dataset: { blogCardKey: `stack-${index}` },
  });
  cardNode.offsetHeight = 120;

  return cardNode;
});
const stackList = new FakeNode({ kind: 'stack-list', children: stackCards });
const stackNext = new FakeNode({ kind: 'link' });
stackNext.hidden = false;
const stackRoot = new FakeNode({
  kind: 'stack-root',
  id: 'stack-runtime',
  classes: ['sectionBlogStack01', 'sectionBlogStack01--items-4'],
  dataset: { blogStack01: '' },
  children: [stackList, stackNext],
});
stackRoot.selectors.set('[data-blog-collection-items]', stackList);
stackRoot.selectors.set('[data-blog-collection-next]', stackNext);
const stackDocument = new FakeDocument(stackView, [stackRoot]);
const cleanupStack = stackModule.initSectionBlogStack01(stackDocument);
await nextTick();
assert.equal(stackRoot.classList.contains('is-stack-enhanced'), true);
assert.equal(stackCreates.length, 3);
assert.equal(stackCreates[0].configuration.start(), 'top top+=80');
assert.equal(stackCreates[1].configuration.start(), 'top top+=95');

const appendedStackCard = new FakeNode({
  kind: 'stack-card',
  classes: ['sectionBlogStack01-item'],
  dataset: { blogCardKey: 'stack-4' },
});
appendedStackCard.offsetHeight = 120;
stackList.appendChild(appendedStackCard);
stackDocument.emit('liquidstack:blog-collection-appended', {
  detail: { root: stackRoot, items: [appendedStackCard] },
});
await nextTick();
assert.equal(stackCreates.length, 4);
assert.equal(
  stackRoot.classList.contains('sectionBlogStack01--items-5'),
  true,
);

stackNext.setAttribute('href', '/es/noticias?page=2');
stackDocument.emit('liquidstack:blog-collection-appended', {
  detail: { root: stackRoot, items: [] },
});
await nextTick();
assert.equal(stackRoot.classList.contains('is-stack-enhanced'), false);
assert.equal(stackCreates.every((record) => record.killed), true);
cleanupStack();

process.stdout.write(JSON.stringify({
  slider: {
    instances: 5,
    clones: sliderClones.length,
    singleClonesAfterAppend: rebuiltSingleClones.length,
    singleClonesAfterHotReload: hotReloadSingleClones.length,
    staleCleanupPreservedOwner,
    focusProxy,
    autoplayInterruptNormalized,
    pointerCtaClickPreserved,
    pointerTitleClickPreserved,
    clickSuppressed: suppressedClick.defaultPrevented,
    cleaned: draggable.killed,
  },
  stack: {
    starts: [
      stackCreates[0].configuration.start(),
      stackCreates[1].configuration.start(),
    ],
    creates: stackCreates.length,
    refreshes: stackRefreshes,
    disabledForPendingBatch: !stackRoot.classList.contains('is-stack-enhanced'),
  },
}));
