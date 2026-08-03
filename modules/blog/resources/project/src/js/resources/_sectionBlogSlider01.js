const ROOT_SELECTOR = '[data-blog-slider]';
const RESULTS_UPDATED_EVENT = 'liquidstack:blog-results-updated';

let disposeActiveSliders = () => {};

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

const installSlider = (root) => {
  const viewport = root.querySelector?.('[data-blog-slider-viewport]');
  const track = viewport?.querySelector?.('.sectionBlogSlider01-track')
    ?? root.querySelector?.('.sectionBlogSlider01-track');
  const previous = root.querySelector?.('[data-blog-slider-previous]');
  const next = root.querySelector?.('[data-blog-slider-next]');
  const controls = previous?.parentElement ?? next?.parentElement ?? null;
  if (!viewport || !track || !previous || !next) {
    return () => {};
  }

  const view = getView(root);
  const AbortControllerConstructor = view.AbortController
    ?? globalThis.AbortController;
  const listenerController = new AbortControllerConstructor();
  let resizeObserver = null;
  let animationFrame = null;
  let disposed = false;

  const items = () => Array.from(track.children ?? []);
  const direction = () => view.getComputedStyle?.(viewport).direction === 'rtl'
    ? 'rtl'
    : 'ltr';
  const reducedMotion = () => view.matchMedia?.(
    '(prefers-reduced-motion: reduce)',
  ).matches === true;

  const updateControls = () => {
    animationFrame = null;
    const slides = items();
    const viewportRect = viewport.getBoundingClientRect();
    const firstRect = slides[0]?.getBoundingClientRect?.();
    const lastRect = slides.at(-1)?.getBoundingClientRect?.();
    const hasOverflow = viewport.scrollWidth > viewport.clientWidth + 2;
    const isRtl = direction() === 'rtl';
    const canGoBack = hasOverflow && firstRect
      ? (isRtl
        ? firstRect.right > viewportRect.right + 2
        : firstRect.left < viewportRect.left - 2)
      : false;
    const canGoForward = hasOverflow && lastRect
      ? (isRtl
        ? lastRect.left < viewportRect.left - 2
        : lastRect.right > viewportRect.right + 2)
      : false;

    previous.disabled = !canGoBack;
    next.disabled = !canGoForward;
    if (controls) {
      controls.hidden = !hasOverflow;
    }
  };

  const scheduleControlsUpdate = () => {
    if (animationFrame !== null) {
      view.cancelAnimationFrame?.(animationFrame);
    }
    animationFrame = view.requestAnimationFrame?.(updateControls)
      ?? view.setTimeout(updateControls, 0);
  };

  const nearestIndex = () => {
    const viewportRect = viewport.getBoundingClientRect();
    const isRtl = direction() === 'rtl';
    let closestIndex = 0;
    let closestDistance = Number.POSITIVE_INFINITY;

    items().forEach((item, index) => {
      const rect = item.getBoundingClientRect();
      const distance = Math.abs(isRtl
        ? rect.right - viewportRect.right
        : rect.left - viewportRect.left);
      if (distance < closestDistance) {
        closestDistance = distance;
        closestIndex = index;
      }
    });

    return closestIndex;
  };

  const move = (offset) => {
    const slides = items();
    if (slides.length === 0) {
      return;
    }
    const targetIndex = Math.max(
      0,
      Math.min(slides.length - 1, nearestIndex() + offset),
    );
    const targetRect = slides[targetIndex].getBoundingClientRect();
    const viewportRect = viewport.getBoundingClientRect();
    const left = direction() === 'rtl'
      ? targetRect.right - viewportRect.right
      : targetRect.left - viewportRect.left;
    viewport.scrollBy({
      left,
      behavior: reducedMotion() ? 'auto' : 'smooth',
    });
  };

  previous.addEventListener('click', () => move(-1), {
    signal: listenerController.signal,
  });
  next.addEventListener('click', () => move(1), {
    signal: listenerController.signal,
  });
  viewport.addEventListener('scroll', scheduleControlsUpdate, {
    passive: true,
    signal: listenerController.signal,
  });

  const ResizeObserverConstructor = view.ResizeObserver
    ?? globalThis.ResizeObserver;
  if (typeof ResizeObserverConstructor === 'function') {
    resizeObserver = new ResizeObserverConstructor(scheduleControlsUpdate);
    resizeObserver.observe(viewport);
    resizeObserver.observe(track);
  } else {
    view.addEventListener('resize', scheduleControlsUpdate, {
      passive: true,
      signal: listenerController.signal,
    });
  }

  scheduleControlsUpdate();

  return () => {
    if (disposed) {
      return;
    }
    disposed = true;
    listenerController.abort();
    resizeObserver?.disconnect();
    if (animationFrame !== null) {
      if (typeof view.cancelAnimationFrame === 'function') {
        view.cancelAnimationFrame(animationFrame);
      } else {
        view.clearTimeout(animationFrame);
      }
      animationFrame = null;
    }
    previous.disabled = true;
    next.disabled = true;
    if (controls) {
      controls.hidden = true;
    }
  };
};

export const cleanupSectionBlogSlider01 = () => {
  disposeActiveSliders();
  disposeActiveSliders = () => {};
};

export const initSectionBlogSlider01 = (scope = globalThis.document) => {
  cleanupSectionBlogSlider01();
  const documentRef = scope?.ownerDocument
    ?? (scope?.nodeType === 9 ? scope : globalThis.document);
  const instances = new Map();

  const scan = (scanScope) => {
    for (const root of queryAllSafely(scanScope, ROOT_SELECTOR)) {
      if (!instances.has(root)) {
        instances.set(root, installSlider(root));
      }
    }
    for (const [root, cleanup] of instances) {
      if ('isConnected' in root && !root.isConnected) {
        cleanup();
        instances.delete(root);
      }
    }
  };

  scan(scope);
  const listenerController = new (getView(documentRef).AbortController
    ?? globalThis.AbortController)();
  documentRef?.addEventListener?.(RESULTS_UPDATED_EVENT, (event) => {
    scan(event.detail?.target ?? documentRef);
  }, { signal: listenerController.signal });

  let cleaned = false;
  disposeActiveSliders = () => {
    if (cleaned) {
      return;
    }
    cleaned = true;
    listenerController.abort();
    for (const cleanup of instances.values()) {
      cleanup();
    }
    instances.clear();
  };

  return disposeActiveSliders;
};

export default initSectionBlogSlider01;

if (import.meta.hot) {
  import.meta.hot.dispose(cleanupSectionBlogSlider01);
}
