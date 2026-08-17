import gsap from 'gsap';

const ROOT_SELECTOR = '[data-blog-grid02]';
const CARD_SELECTOR = '[data-blog-card-key]';
const RESULTS_UPDATED_EVENT = 'liquidstack:blog-results-updated';
const APPENDED_EVENT = 'liquidstack:blog-collection-appended';

let disposeActiveGrids = () => {};

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
  const prefix = 'moduleBlogGrid02--items-';
  for (const className of Array.from(root.classList ?? [])) {
    if (className.startsWith(prefix)) {
      root.classList.remove(className);
    }
  }
  root.classList?.add?.(`${prefix}${count}`);
};

const clearPresentation = (cards) => {
  try {
    gsap.set(cards, { clearProps: 'opacity,visibility,transform' });
  } catch {
    for (const card of cards) {
      card?.style?.removeProperty?.('opacity');
      card?.style?.removeProperty?.('visibility');
      card?.style?.removeProperty?.('transform');
    }
  }
};

const installGrid = (root) => {
  const view = getView(root);
  const motionQuery = view.matchMedia?.('(prefers-reduced-motion: reduce)')
    ?? null;
  const revealed = new WeakSet();
  const animations = new Set();
  let disposed = false;

  const cardsFrom = (candidates) => {
    const source = candidates == null
      ? queryAllSafely(root, CARD_SELECTOR)
      : Array.from(candidates ?? []);

    return source.filter((card) => (
      card
      && typeof card.matches === 'function'
      && card.matches(CARD_SELECTOR)
      && (card === root || root.contains?.(card))
    ));
  };

  const stopAnimations = () => {
    for (const animation of animations) {
      animation?.kill?.();
    }
    animations.clear();
  };

  const reveal = (candidates = null) => {
    if (disposed) {
      return;
    }
    syncItemCountClass(root, cardsFrom(null).length);
    const cards = cardsFrom(candidates).filter((card) => !revealed.has(card));
    if (cards.length === 0) {
      return;
    }
    for (const card of cards) {
      revealed.add(card);
    }

    if (motionQuery?.matches === true || typeof gsap.fromTo !== 'function') {
      clearPresentation(cards);
      return;
    }

    let animation = null;
    const finish = () => {
      if (animation) {
        animations.delete(animation);
      }
      clearPresentation(cards);
    };

    try {
      animation = gsap.fromTo(
        cards,
        { autoAlpha: 0, y: 24 },
        {
          autoAlpha: 1,
          y: 0,
          duration: 0.58,
          ease: 'power2.out',
          stagger: { each: 0.07, from: 'start' },
          overwrite: 'auto',
          onComplete: finish,
          onInterrupt: finish,
        },
      );
      animations.add(animation);
    } catch {
      finish();
    }
  };

  const onMotionChange = () => {
    if (motionQuery?.matches !== true) {
      return;
    }
    stopAnimations();
    clearPresentation(cardsFrom(null));
  };
  if (typeof motionQuery?.addEventListener === 'function') {
    motionQuery.addEventListener('change', onMotionChange);
  } else {
    motionQuery?.addListener?.(onMotionChange);
  }

  reveal();

  return {
    reveal,
    cleanup: () => {
      if (disposed) {
        return;
      }
      disposed = true;
      if (typeof motionQuery?.removeEventListener === 'function') {
        motionQuery.removeEventListener('change', onMotionChange);
      } else {
        motionQuery?.removeListener?.(onMotionChange);
      }
      stopAnimations();
      clearPresentation(cardsFrom(null));
    },
  };
};

export const cleanupModuleBlogGrid02 = () => {
  disposeActiveGrids();
  disposeActiveGrids = () => {};
};

export const initModuleBlogGrid02 = (scope = globalThis.document) => {
  cleanupModuleBlogGrid02();
  const documentRef = scope?.ownerDocument
    ?? (scope?.nodeType === 9 ? scope : globalThis.document);
  const instances = new Map();

  const scan = (scanScope) => {
    for (const root of queryAllSafely(scanScope, ROOT_SELECTOR)) {
      if (!instances.has(root)) {
        instances.set(root, installGrid(root));
      } else {
        instances.get(root)?.reveal?.();
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
    instances.get(root)?.reveal?.(event.detail?.items ?? []);
  };

  scan(scope);
  documentRef?.addEventListener?.(RESULTS_UPDATED_EVENT, onResultsUpdated);
  documentRef?.addEventListener?.(APPENDED_EVENT, onItemsAppended);

  let cleaned = false;
  disposeActiveGrids = () => {
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

  return disposeActiveGrids;
};

export default initModuleBlogGrid02;

if (import.meta.hot) {
  import.meta.hot.dispose(cleanupModuleBlogGrid02);
}
