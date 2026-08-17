import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';

const ROOT_SELECTOR = '[data-blog-stack01]';
const ITEMS_SELECTOR = '[data-blog-collection-items]';
const CARD_SELECTOR = '[data-blog-card-key]';
const NEXT_SELECTOR = '[data-blog-collection-next]';
const RESULTS_UPDATED_EVENT = 'liquidstack:blog-results-updated';
const APPENDED_EVENT = 'liquidstack:blog-collection-appended';
const ENHANCEMENT_QUERY = '(min-height: 40rem) and (prefers-reduced-motion: no-preference)';
const MIN_STACK_ITEMS = 3;
const MAX_STACK_ITEMS = 8;

let disposeActiveStacks = () => {};

if (typeof gsap.registerPlugin === 'function') {
  gsap.registerPlugin(ScrollTrigger);
}

const queryAllSafely = (scope, selector) => {
  if (!scope || typeof scope.querySelectorAll !== 'function') {
    return [];
  }

  try {
    const matches = Array.from(scope.querySelectorAll(selector));
    if (typeof scope.matches === 'function' && scope.matches(selector)) {
      matches.unshift(scope);
    }

    return [...new Set(matches)];
  } catch {
    return [];
  }
};

const getView = (node) => node?.ownerDocument?.defaultView
  ?? globalThis.window
  ?? globalThis;

const syncItemCountClass = (root, count) => {
  const prefix = 'sectionBlogStack01--items-';
  for (const className of Array.from(root.classList ?? [])) {
    if (className.startsWith(prefix)) {
      root.classList.remove(className);
    }
  }
  root.classList?.add?.(`${prefix}${count}`);
};

const installStack = (root) => {
  const list = root.querySelector?.(ITEMS_SELECTOR);
  if (!list) {
    return { refresh: () => {}, cleanup: () => {} };
  }

  const view = getView(root);
  const documentRef = root.ownerDocument ?? globalThis.document;
  const mediaQuery = view.matchMedia?.(ENHANCEMENT_QUERY) ?? null;
  const triggerByCard = new Map();
  let animationFrame = null;
  let disposed = false;

  // ScrollTrigger envuelve temporalmente los elementos fijados. Consultarlos
  // como descendientes conserva el orden y permite anexar nuevas tarjetas sin
  // confundir los pin-spacers con contenido editorial.
  const cards = () => queryAllSafely(list, CARD_SELECTOR);
  const lengthValue = (name, fallback) => {
    const rawValue = String(
      view.getComputedStyle?.(root)?.getPropertyValue?.(name) ?? '',
    ).trim();
    const match = /^(-?(?:\d+|\d*\.\d+))(px|rem)?$/i.exec(rawValue);
    if (!match) {
      return fallback;
    }
    const value = Number.parseFloat(match[1]);
    if (!Number.isFinite(value)) {
      return fallback;
    }
    if ((match[2] ?? '').toLowerCase() !== 'rem') {
      return value;
    }
    const rootFontSize = Number.parseFloat(
      view.getComputedStyle?.(documentRef?.documentElement)?.fontSize ?? '',
    );

    return value * (Number.isFinite(rootFontSize) ? rootFontSize : 16);
  };
  const clearCards = (items) => {
    for (const card of items) {
      card.style?.removeProperty?.('--blog-stack-index');
      card.style?.removeProperty?.('will-change');
    }
  };
  const killRecord = (record) => {
    try {
      record?.trigger?.kill?.(true);
    } catch {
      record?.trigger?.kill?.();
    }
  };
  const disable = () => {
    for (const record of triggerByCard.values()) {
      killRecord(record);
    }
    triggerByCard.clear();
    root.classList?.remove?.('is-stack-enhanced');
    clearCards(cards());
  };
  const fitsViewport = (items, top, step) => {
    const viewportHeight = Number(view.innerHeight) || 800;
    const available = viewportHeight - top - (step * (items.length - 1)) - 32;
    if (available < 240) {
      return false;
    }

    return items.every((card) => {
      const rectHeight = card.getBoundingClientRect?.().height ?? 0;
      const height = Math.max(Number(card.offsetHeight) || 0, rectHeight || 0);

      return height === 0 || height <= available;
    });
  };
  const hasPendingBatch = () => {
    const next = root.querySelector?.(NEXT_SELECTOR);
    const href = next?.getAttribute?.('href') ?? '';

    return Boolean(next && !next.hidden && href);
  };

  const reconcile = () => {
    animationFrame = null;
    if (disposed) {
      return;
    }
    const items = cards();
    syncItemCountClass(root, items.length);
    const top = lengthValue('--blog-stack-top', 64);
    const step = lengthValue('--blog-stack-step', 12);
    const canEnhance = mediaQuery?.matches === true
      && lengthValue('--blog-stack-enabled', 0) >= 1
      && items.length >= MIN_STACK_ITEMS
      && items.length <= MAX_STACK_ITEMS
      && !hasPendingBatch()
      && typeof ScrollTrigger?.create === 'function'
      && fitsViewport(items, top, step);

    if (!canEnhance) {
      disable();
      return;
    }

    root.classList?.add?.('is-stack-enhanced');
    const pinnedCards = new Set(items.slice(0, -1));
    for (const [card, record] of triggerByCard) {
      const index = items.indexOf(card);
      const startOffset = Math.round(top + (step * index));
      if (
        !pinnedCards.has(card)
        || index !== record.index
        || startOffset !== record.startOffset
      ) {
        killRecord(record);
        triggerByCard.delete(card);
      }
    }

    let failed = false;
    for (const [index, card] of items.entries()) {
      card.style?.setProperty?.('--blog-stack-index', String(index));
      if (index === items.length - 1 || triggerByCard.has(card)) {
        continue;
      }
      const startOffset = Math.round(top + (step * index));
      const key = card.dataset?.blogCardKey ?? String(index);
      try {
        const trigger = ScrollTrigger.create({
          id: `sectionBlogStack01:${root.id}:${key}`,
          trigger: card,
          start: () => `top top+=${startOffset}`,
          endTrigger: list,
          end: () => {
            const height = Math.max(
              Number(card.offsetHeight) || 0,
              card.getBoundingClientRect?.().height ?? 0,
            );

            return `bottom top+=${Math.round(startOffset + height)}`;
          },
          pin: true,
          pinSpacing: false,
          anticipatePin: 1,
          invalidateOnRefresh: true,
        });
        triggerByCard.set(card, { trigger, index, startOffset });
      } catch {
        failed = true;
        break;
      }
    }

    if (failed) {
      disable();
      return;
    }

    try {
      ScrollTrigger.refresh?.();
    } catch {
      disable();
    }
  };

  const schedule = () => {
    if (disposed || animationFrame !== null) {
      return;
    }
    animationFrame = view.requestAnimationFrame?.(reconcile)
      ?? view.setTimeout(reconcile, 0);
  };
  const onMediaChange = () => schedule();
  const onResize = () => schedule();
  if (typeof mediaQuery?.addEventListener === 'function') {
    mediaQuery.addEventListener('change', onMediaChange);
  } else {
    mediaQuery?.addListener?.(onMediaChange);
  }
  view.addEventListener?.('resize', onResize, { passive: true });
  schedule();

  return {
    refresh: schedule,
    cleanup: () => {
      if (disposed) {
        return;
      }
      disposed = true;
      if (typeof mediaQuery?.removeEventListener === 'function') {
        mediaQuery.removeEventListener('change', onMediaChange);
      } else {
        mediaQuery?.removeListener?.(onMediaChange);
      }
      view.removeEventListener?.('resize', onResize);
      if (animationFrame !== null) {
        if (typeof view.cancelAnimationFrame === 'function') {
          view.cancelAnimationFrame(animationFrame);
        } else {
          view.clearTimeout?.(animationFrame);
        }
        animationFrame = null;
      }
      disable();
    },
  };
};

export const cleanupSectionBlogStack01 = () => {
  disposeActiveStacks();
  disposeActiveStacks = () => {};
};

export const initSectionBlogStack01 = (scope = globalThis.document) => {
  cleanupSectionBlogStack01();
  const documentRef = scope?.ownerDocument
    ?? (scope?.nodeType === 9 ? scope : globalThis.document);
  const instances = new Map();

  const scan = (scanScope) => {
    for (const root of queryAllSafely(scanScope, ROOT_SELECTOR)) {
      if (!instances.has(root)) {
        instances.set(root, installStack(root));
      } else {
        instances.get(root)?.refresh?.();
      }
    }
    for (const [root, instance] of instances) {
      if ('isConnected' in root && !root.isConnected) {
        instance.cleanup();
        instances.delete(root);
      }
    }
  };

  const onResultsUpdated = (event) => {
    scan(event.detail?.target ?? documentRef);
  };
  const onItemsAppended = (event) => {
    const root = event.detail?.root;
    if (!root?.matches?.(ROOT_SELECTOR)) {
      return;
    }
    if (!instances.has(root)) {
      scan(root);
    }
    instances.get(root)?.refresh?.();
  };

  scan(scope);
  documentRef?.addEventListener?.(RESULTS_UPDATED_EVENT, onResultsUpdated);
  documentRef?.addEventListener?.(APPENDED_EVENT, onItemsAppended);

  let cleaned = false;
  disposeActiveStacks = () => {
    if (cleaned) {
      return;
    }
    cleaned = true;
    documentRef?.removeEventListener?.(RESULTS_UPDATED_EVENT, onResultsUpdated);
    documentRef?.removeEventListener?.(APPENDED_EVENT, onItemsAppended);
    for (const instance of instances.values()) {
      instance.cleanup();
    }
    instances.clear();
  };

  return disposeActiveStacks;
};

export default initSectionBlogStack01;

if (import.meta.hot) {
  import.meta.hot.dispose(cleanupSectionBlogStack01);
}
