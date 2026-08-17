import gsap from 'gsap';
import { Draggable, InertiaPlugin } from 'gsap/all';

const ROOT_SELECTOR = '[data-blog-slider]';
const CARD_SELECTOR = '.sectionBlogSlider01-item';
const RESULTS_UPDATED_EVENT = 'liquidstack:blog-results-updated';
const ITEMS_APPENDED_EVENT = 'liquidstack:blog-collection-appended';
const ROOT_OWNER = Symbol.for(
  'liquidstack.blog.sectionBlogSlider01.owner',
);

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

const clamp = (value, minimum, maximum) => Math.min(
  maximum,
  Math.max(minimum, value),
);

const modulo = (value, divisor) => (
  divisor > 0 ? ((value % divisor) + divisor) % divisor : 0
);

const finiteNumber = (value, fallback, minimum, maximum) => {
  const parsed = Number.parseFloat(value);

  return Number.isFinite(parsed)
    ? clamp(parsed, minimum, maximum)
    : fallback;
};

const installSlider = (root) => {
  const viewport = root.querySelector?.('[data-blog-slider-viewport]');
  const track = root.querySelector?.('[data-blog-slider-track]')
    ?? root.querySelector?.('.sectionBlogSlider01-track');
  const previous = root.querySelector?.('[data-blog-slider-previous]');
  const next = root.querySelector?.('[data-blog-slider-next]');
  const controls = root.querySelector?.('[data-blog-slider-controls]')
    ?? previous?.parentElement
    ?? next?.parentElement;
  const autoplayToggle = root.querySelector?.(
    '[data-blog-slider-autoplay-toggle]',
  );
  if (!viewport || !track || !controls || !previous || !next) {
    return {
      cleanup: () => {},
      refresh: () => {},
    };
  }

  const view = getView(root);
  const documentRef = root.ownerDocument ?? globalThis.document;
  const AbortControllerConstructor = view.AbortController
    ?? globalThis.AbortController;
  const listenerController = new AbortControllerConstructor();
  const listenerOptions = { signal: listenerController.signal };
  const reducedMotionQuery = view.matchMedia?.(
    '(prefers-reduced-motion: reduce)',
  ) ?? null;
  const proxy = documentRef.createElement('div');
  const autoplayConfigured = root.dataset.blogSliderAutoplay !== 'false';
  const autoplayDelay = finiteNumber(
    root.dataset.blogSliderAutoplayDelay,
    6,
    2,
    60,
  );
  const transitionDuration = finiteNumber(
    root.dataset.blogSliderDuration,
    1,
    0.1,
    5,
  );

  let cards = [];
  let clones = [];
  let renderOffsets = [];
  let setters = [];
  let draggable = null;
  let motionTween = null;
  let autoplayCall = null;
  let resizeObserver = null;
  let intersectionObserver = null;
  let refreshFrame = null;
  let controlsFrame = null;
  let enhanced = false;
  let loopEnabled = false;
  let loopCopiesEnabled = false;
  let disposed = false;
  let step = 0;
  let loopWidth = 0;
  let maximumTravel = 0;
  let inlinePadding = 0;
  let currentIndex = 0;
  let userPaused = false;
  let pointerInside = false;
  let focusInside = false;
  let dragging = false;
  let pressProxyPosition = 0;
  let dragDistance = 0;
  let rootVisible = true;
  let motionIsAutoplay = false;
  let suppressClickUntil = 0;
  let lastViewportWidth = 0;
  let legacyReducedMotionListener = null;

  const now = () => view.performance?.now?.() ?? Date.now();
  const reducedMotion = () => reducedMotionQuery?.matches === true;
  const isRtl = () => view.getComputedStyle?.(viewport).direction === 'rtl';
  const cardElements = () => {
    const candidates = Array.from(track.children ?? []).filter(
      (element) => !element.hasAttribute?.('data-blog-slider01-clone'),
    );
    const matched = candidates.filter(
      (element) => element.matches?.(CARD_SELECTOR),
    );

    return matched.length > 0 ? matched : candidates;
  };
  const proxyX = () => Number(gsap.getProperty(proxy, 'x')) || 0;
  const canNavigate = () => (
    cards.length > 0
    && (loopEnabled || cards.length > 1)
  );

  const killMotion = () => {
    motionTween?.kill();
    motionTween = null;
    motionIsAutoplay = false;
  };

  const killAutoplay = () => {
    autoplayCall?.kill();
    autoplayCall = null;
  };

  const updateAutoplayToggle = () => {
    if (!autoplayToggle) {
      return;
    }
    const available = autoplayConfigured
      && enhanced
      && canNavigate()
      && !reducedMotion();
    autoplayToggle.hidden = !available;
    autoplayToggle.setAttribute('aria-pressed', userPaused ? 'true' : 'false');
    const label = userPaused
      ? autoplayToggle.dataset.resumeLabel
      : autoplayToggle.dataset.pauseLabel;
    if (label) {
      autoplayToggle.setAttribute('aria-label', label);
      autoplayToggle.setAttribute('title', label);
    }
  };

  const canAutoplay = () => (
    autoplayConfigured
    && enhanced
    && canNavigate()
    && !reducedMotion()
    && !userPaused
    && !pointerInside
    && !focusInside
    && !dragging
    && rootVisible
    && documentRef?.hidden !== true
    && (loopEnabled || proxyX() > (-maximumTravel + 1))
  );

  const render = () => {
    if (!enhanced || step <= 0) {
      return;
    }
    const position = proxyX();
    if (loopEnabled && !loopCopiesEnabled) {
      const wrapPosition = gsap.utils.wrap(-step, loopWidth - step);
      setters.forEach((setX, index) => {
        setX(inlinePadding + wrapPosition((index * step) + position));
      });
      return;
    }
    setters.forEach((setX, index) => {
      setX(inlinePadding + renderOffsets[index] + position);
    });
  };

  const snappedX = (value) => {
    if (step <= 0) {
      return 0;
    }
    const snapped = Math.round(value / step) * step;

    if (loopCopiesEnabled) {
      return clamp(snapped, -loopWidth, loopWidth);
    }

    return loopEnabled ? snapped : clamp(snapped, -maximumTravel, 0);
  };

  const syncCurrentIndex = () => {
    if (cards.length === 0 || step <= 0) {
      currentIndex = 0;
      return;
    }
    const rawIndex = Math.round(-proxyX() / step);
    currentIndex = loopEnabled
      ? modulo(rawIndex, cards.length)
      : clamp(rawIndex, 0, cards.length - 1);
  };

  const normalizeLoopProxy = () => {
    if (!loopEnabled || cards.length === 0 || step <= 0) {
      return;
    }
    syncCurrentIndex();
    gsap.set(proxy, { x: -(currentIndex * step) });
    render();
  };

  const nearestNativeIndex = () => {
    const viewportRect = viewport.getBoundingClientRect();
    let closestIndex = 0;
    let closestDistance = Number.POSITIVE_INFINITY;
    cards.forEach((card, index) => {
      const rect = card.getBoundingClientRect();
      const distance = Math.abs(isRtl()
        ? rect.right - viewportRect.right
        : rect.left - viewportRect.left);
      if (distance < closestDistance) {
        closestDistance = distance;
        closestIndex = index;
      }
    });

    return closestIndex;
  };

  const updateControls = () => {
    const hasSeveralCards = cards.length > 1;
    if (enhanced) {
      const navigationAvailable = canNavigate();
      controls.hidden = !navigationAvailable;
      previous.disabled = !navigationAvailable
        || (!loopEnabled && proxyX() >= -1);
      next.disabled = !navigationAvailable
        || (!loopEnabled && proxyX() <= (-maximumTravel + 1));
      updateAutoplayToggle();
      return;
    }

    const hasOverflow = hasSeveralCards
      && viewport.scrollWidth > viewport.clientWidth + 2;
    controls.hidden = !hasOverflow;
    if (!hasOverflow) {
      previous.disabled = true;
      next.disabled = true;
      updateAutoplayToggle();
      return;
    }
    const viewportRect = viewport.getBoundingClientRect();
    const firstRect = cards[0]?.getBoundingClientRect?.();
    const lastRect = cards.at(-1)?.getBoundingClientRect?.();
    previous.disabled = isRtl()
      ? firstRect?.right <= viewportRect.right + 2
      : firstRect?.left >= viewportRect.left - 2;
    next.disabled = isRtl()
      ? lastRect?.left >= viewportRect.left - 2
      : lastRect?.right <= viewportRect.right + 2;
    updateAutoplayToggle();
  };

  const scheduleControlsUpdate = () => {
    if (disposed) {
      return;
    }
    const observedWidth = viewport.clientWidth;
    if (
      enhanced
      && lastViewportWidth > 0
      && Math.abs(observedWidth - lastViewportWidth) > 0.5
    ) {
      scheduleRebuild();
      return;
    }
    if (controlsFrame !== null) {
      return;
    }
    controlsFrame = view.requestAnimationFrame?.(() => {
      controlsFrame = null;
      updateControls();
    }) ?? view.setTimeout(() => {
      controlsFrame = null;
      updateControls();
    }, 0);
  };

  const scheduleAutoplay = (restart = false) => {
    if (restart) {
      killAutoplay();
    }
    updateAutoplayToggle();
    if (!canAutoplay() || autoplayCall) {
      return;
    }
    autoplayCall = gsap.delayedCall(autoplayDelay, () => {
      autoplayCall = null;
      moveBy(1, false);
    });
  };

  const finishMotion = () => {
    motionTween = null;
    motionIsAutoplay = false;
    syncCurrentIndex();
    normalizeLoopProxy();
    updateControls();
    scheduleAutoplay(true);
  };

  const animateProxy = (target, userInitiated, autoplayMotion = false) => {
    killMotion();
    killAutoplay();
    motionIsAutoplay = autoplayMotion && !userInitiated;
    const destination = snappedX(target);
    if (reducedMotion()) {
      gsap.set(proxy, { x: destination });
      render();
      finishMotion();
      return;
    }
    motionTween = gsap.to(proxy, {
      x: destination,
      duration: transitionDuration,
      ease: 'power2.out',
      overwrite: true,
      onUpdate: render,
      onComplete: finishMotion,
      onInterrupt: () => {
        motionTween = null;
        motionIsAutoplay = false;
      },
    });
  };

  const moveNative = (offset) => {
    if (cards.length === 0) {
      return;
    }
    const targetIndex = clamp(
      nearestNativeIndex() + offset,
      0,
      cards.length - 1,
    );
    const targetRect = cards[targetIndex].getBoundingClientRect();
    const viewportRect = viewport.getBoundingClientRect();
    const left = isRtl()
      ? targetRect.right - viewportRect.right
      : targetRect.left - viewportRect.left;
    viewport.scrollBy({
      left,
      behavior: reducedMotion() ? 'auto' : 'smooth',
    });
  };

  function moveBy(offset, userInitiated = true) {
    if (!enhanced) {
      moveNative(offset);
      return;
    }
    if (!canNavigate() || step <= 0) {
      return;
    }
    animateProxy(
      proxyX() - (offset * step),
      userInitiated,
      !userInitiated,
    );
  }

  const moveToIndex = (index, userInitiated = true) => {
    if (cards.length === 0) {
      return;
    }
    const safeIndex = clamp(index, 0, cards.length - 1);
    currentIndex = safeIndex;
    if (enhanced) {
      animateProxy(-(safeIndex * step), userInitiated);
      return;
    }
    const target = cards[safeIndex];
    if (typeof viewport.scrollTo === 'function') {
      viewport.scrollTo({
        left: Math.max(0, target.offsetLeft),
        behavior: reducedMotion() ? 'auto' : 'smooth',
      });
    } else {
      viewport.scrollLeft = Math.max(0, target.offsetLeft);
    }
  };

  const stopAutoplayMotion = () => {
    killAutoplay();
    if (!enhanced || !motionTween || !motionIsAutoplay) {
      return;
    }
    motionTween.kill();
    motionTween = null;
    motionIsAutoplay = false;
    gsap.set(proxy, { x: snappedX(proxyX()) });
    render();
    syncCurrentIndex();
    normalizeLoopProxy();
    updateControls();
  };

  const clearEnhancedLayout = () => {
    killMotion();
    killAutoplay();
    draggable?.kill?.();
    draggable = null;
    root.classList.remove(
      'sectionBlogSlider01--enhanced',
      'sectionBlogSlider01--dragging',
    );
    enhanced = false;
    loopEnabled = false;
    loopCopiesEnabled = false;
    dragging = false;
    step = 0;
    loopWidth = 0;
    maximumTravel = 0;
    inlinePadding = 0;
    setters = [];
    renderOffsets = [];
    track.style.height = '';
    const renderedCards = [...cards, ...clones];
    if (renderedCards.length > 0) {
      gsap.set(renderedCards, {
        clearProps: 'height,transform,willChange',
      });
    }
    clones.forEach((clone) => clone.remove?.());
    clones = [];
    gsap.set(proxy, { x: 0 });
  };

  const cloneForLoop = (card) => {
    const clone = card.cloneNode?.(true);
    if (!clone) {
      return null;
    }
    clone.setAttribute('data-blog-slider01-clone', '');
    clone.setAttribute('aria-hidden', 'true');
    clone.setAttribute('inert', '');
    clone.setAttribute('role', 'presentation');
    clone.style.pointerEvents = 'none';
    const cloneTree = [
      clone,
      ...(clone.querySelectorAll?.('*') ?? []),
    ];
    for (const element of cloneTree) {
      element.removeAttribute?.('id');
      element.removeAttribute?.('data-blog-card-key');
    }
    for (const element of cloneTree) {
      for (const attribute of [
        'aria-activedescendant',
        'aria-controls',
        'aria-describedby',
        'aria-details',
        'aria-errormessage',
        'aria-flowto',
        'aria-labelledby',
        'aria-owns',
        'for',
        'form',
        'headers',
        'list',
      ]) {
        element.removeAttribute?.(attribute);
      }
    }
    clone.querySelectorAll?.(
      'a[href], button, input, select, textarea, iframe, [tabindex]',
    ).forEach((element) => element.setAttribute('tabindex', '-1'));

    return clone;
  };

  const installLoopCopies = (cyclesPerSide) => {
    const before = [];
    const after = [];
    for (let cycle = 0; cycle < cyclesPerSide; cycle += 1) {
      before.push(...cards.map(cloneForLoop));
      after.push(...cards.map(cloneForLoop));
    }
    if (before.some((clone) => clone === null)
      || after.some((clone) => clone === null)) {
      [...before, ...after].forEach((clone) => clone?.remove?.());
      return false;
    }
    const beforeFragment = documentRef.createDocumentFragment?.();
    const afterFragment = documentRef.createDocumentFragment?.();
    if (!beforeFragment || !afterFragment) {
      return false;
    }
    before.forEach((clone) => beforeFragment.appendChild(clone));
    after.forEach((clone) => afterFragment.appendChild(clone));
    track.insertBefore(beforeFragment, track.firstChild);
    track.appendChild(afterFragment);
    clones = [...before, ...after];
    renderOffsets = Array.from(
      { length: before.length + cards.length + after.length },
      (_, index) => (index - before.length) * step,
    );

    return true;
  };

  const finishDrag = () => {
    root.classList.remove('sectionBlogSlider01--dragging');
    dragging = false;
    if (dragDistance > 8) {
      suppressClickUntil = now() + 350;
    }
    gsap.set(proxy, { x: snappedX(proxyX()) });
    render();
    syncCurrentIndex();
    normalizeLoopProxy();
    updateControls();
    scheduleAutoplay(true);
  };

  const installDraggable = () => {
    const options = {
      trigger: viewport,
      type: 'x',
      inertia: true,
      allowNativeTouchScrolling: true,
      minimumMovement: 4,
      snap: { x: snappedX },
      onPress() {
        killMotion();
        killAutoplay();
        pressProxyPosition = proxyX();
        dragDistance = 0;
        this.update?.();
      },
      onDragStart() {
        dragging = true;
        root.classList.add('sectionBlogSlider01--dragging');
      },
      onDrag() {
        dragging = true;
        dragDistance = Math.max(
          dragDistance,
          Math.abs(proxyX() - pressProxyPosition),
        );
        render();
      },
      onThrowUpdate: render,
      onRelease() {
        dragDistance = Math.max(
          dragDistance,
          Math.abs(proxyX() - pressProxyPosition),
        );
        if (!this.isThrowing) {
          finishDrag();
        }
      },
      onThrowComplete: finishDrag,
    };
    if (loopCopiesEnabled) {
      options.bounds = { minX: -loopWidth, maxX: loopWidth };
      options.edgeResistance = 0.85;
    } else if (!loopEnabled) {
      options.bounds = { minX: -maximumTravel, maxX: 0 };
      options.edgeResistance = 0.85;
    }
    [draggable] = Draggable.create(proxy, options);
  };

  const rebuild = () => {
    refreshFrame = null;
    if (disposed) {
      return;
    }

    if (enhanced) {
      syncCurrentIndex();
    } else if (cards.length > 0) {
      currentIndex = nearestNativeIndex();
    }
    const activeKey = cards[currentIndex]?.dataset?.blogCardKey ?? '';
    clearEnhancedLayout();
    cards = cardElements();
    if (activeKey !== '') {
      const preservedIndex = cards.findIndex(
        (card) => card.dataset.blogCardKey === activeKey,
      );
      if (preservedIndex >= 0) {
        currentIndex = preservedIndex;
      }
    }
    currentIndex = clamp(currentIndex, 0, Math.max(0, cards.length - 1));
    lastViewportWidth = viewport.clientWidth;

    if (cards.length === 0) {
      viewport.removeAttribute('tabindex');
      controls.hidden = true;
      updateControls();
      return;
    }
    const hasOverflow = cards.length > 1
      && viewport.scrollWidth > viewport.clientWidth + 2;
    const canEnhance = !reducedMotion()
      && !isRtl()
      && cards.length > 0;
    if (!canEnhance) {
      if (hasOverflow) {
        if (!viewport.hasAttribute('tabindex')) {
          viewport.setAttribute('tabindex', '0');
        }
      } else {
        viewport.removeAttribute('tabindex');
      }
      viewport.scrollLeft = Math.max(0, cards[currentIndex]?.offsetLeft ?? 0);
      updateControls();
      return;
    }
    if (!viewport.hasAttribute('tabindex')) {
      viewport.setAttribute('tabindex', '0');
    }

    const trackStyle = view.getComputedStyle?.(track);
    const gap = finiteNumber(
      trackStyle?.columnGap === 'normal'
        ? trackStyle?.gap
        : trackStyle?.columnGap,
      0,
      0,
      500,
    );
    inlinePadding = finiteNumber(trackStyle?.paddingInlineStart, 0, 0, 1000);
    const cardWidth = cards[0]?.offsetWidth ?? 0;
    if (cardWidth <= 0) {
      updateControls();
      return;
    }
    step = cardWidth + gap;
    loopWidth = step * cards.length;
    const naturalWidth = (cardWidth * cards.length)
      + (gap * Math.max(0, cards.length - 1))
      + (2 * inlinePadding);
    maximumTravel = Math.max(0, naturalWidth - viewport.clientWidth);
    loopEnabled = cards.length > 0;
    loopCopiesEnabled = loopEnabled
      && loopWidth <= viewport.clientWidth + step;
    const tallestCard = Math.max(...cards.map((card) => card.offsetHeight), 1);

    root.classList.add('sectionBlogSlider01--enhanced');
    enhanced = true;
    viewport.scrollLeft = 0;
    track.style.height = `${tallestCard}px`;
    const loopCopyCycles = loopCopiesEnabled
      ? Math.max(1, Math.ceil(
        (viewport.clientWidth + gap) / Math.max(loopWidth, 1),
      ))
      : 0;
    if (loopCopiesEnabled && !installLoopCopies(loopCopyCycles)) {
      loopEnabled = false;
      loopCopiesEnabled = false;
    }
    const renderCards = loopCopiesEnabled
      ? Array.from(track.children ?? []).filter(
        (element) => element.matches?.(CARD_SELECTOR),
      )
      : cards;
    if (!loopCopiesEnabled) {
      renderOffsets = cards.map((_, index) => index * step);
    }
    gsap.set(renderCards, { height: tallestCard, willChange: 'transform' });
    setters = renderCards.map((card) => gsap.quickSetter(card, 'x', 'px'));
    gsap.set(proxy, {
      x: loopEnabled
        ? -(currentIndex * step)
        : -Math.min(currentIndex * step, maximumTravel),
    });
    render();

    try {
      installDraggable();
    } catch {
      clearEnhancedLayout();
    }
    updateControls();
    scheduleAutoplay(true);
  };

  function scheduleRebuild() {
    if (refreshFrame !== null || disposed) {
      return;
    }
    refreshFrame = view.requestAnimationFrame?.(rebuild)
      ?? view.setTimeout(rebuild, 0);
  }

  previous.addEventListener('click', () => moveBy(-1), listenerOptions);
  next.addEventListener('click', () => moveBy(1), listenerOptions);
  autoplayToggle?.addEventListener('click', () => {
    userPaused = !userPaused;
    if (userPaused) {
      stopAutoplayMotion();
    }
    scheduleAutoplay(true);
  }, listenerOptions);
  viewport.addEventListener('scroll', () => {
    if (!enhanced) {
      scheduleControlsUpdate();
    }
  }, { passive: true, signal: listenerController.signal });
  viewport.addEventListener('keydown', (event) => {
    if (event.altKey || event.ctrlKey || event.metaKey) {
      return;
    }
    if (event.key === 'ArrowLeft') {
      event.preventDefault();
      moveBy(-1);
    } else if (event.key === 'ArrowRight') {
      event.preventDefault();
      moveBy(1);
    } else if (event.key === 'Home') {
      event.preventDefault();
      moveToIndex(0);
    } else if (event.key === 'End') {
      event.preventDefault();
      moveToIndex(cards.length - 1);
    }
  }, listenerOptions);
  viewport.addEventListener('click', (event) => {
    if (now() < suppressClickUntil) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }, { capture: true, signal: listenerController.signal });
  track.addEventListener('load', (event) => {
    if (event.target?.closest?.('[data-blog-slider01-clone]')) {
      return;
    }
    scheduleRebuild();
  }, {
    capture: true,
    signal: listenerController.signal,
  });
  root.addEventListener('pointerenter', () => {
    pointerInside = true;
    stopAutoplayMotion();
  }, listenerOptions);
  root.addEventListener('pointerleave', () => {
    pointerInside = false;
    scheduleAutoplay(true);
  }, listenerOptions);
  root.addEventListener('focusin', (event) => {
    focusInside = true;
    stopAutoplayMotion();
    const focusedCard = event.target?.closest?.(CARD_SELECTOR);
    const focusedIndex = cards.indexOf(focusedCard);
    if (focusedIndex >= 0) {
      moveToIndex(focusedIndex, true);
    }
  }, listenerOptions);
  root.addEventListener('focusout', (event) => {
    if (root.contains(event.relatedTarget)) {
      return;
    }
    focusInside = false;
    scheduleAutoplay(true);
  }, listenerOptions);
  documentRef?.addEventListener?.('visibilitychange', () => {
    if (documentRef.hidden) {
      stopAutoplayMotion();
    } else {
      scheduleAutoplay(true);
    }
  }, listenerOptions);

  if (typeof reducedMotionQuery?.addEventListener === 'function') {
    reducedMotionQuery.addEventListener('change', scheduleRebuild, listenerOptions);
  } else if (typeof reducedMotionQuery?.addListener === 'function') {
    legacyReducedMotionListener = scheduleRebuild;
    reducedMotionQuery.addListener(legacyReducedMotionListener);
  }

  const ResizeObserverConstructor = view.ResizeObserver
    ?? globalThis.ResizeObserver;
  if (typeof ResizeObserverConstructor === 'function') {
    resizeObserver = new ResizeObserverConstructor(scheduleControlsUpdate);
    resizeObserver.observe(viewport);
  } else {
    view.addEventListener('resize', scheduleRebuild, {
      passive: true,
      signal: listenerController.signal,
    });
  }

  const IntersectionObserverConstructor = view.IntersectionObserver
    ?? globalThis.IntersectionObserver;
  if (typeof IntersectionObserverConstructor === 'function') {
    rootVisible = false;
    intersectionObserver = new IntersectionObserverConstructor((entries) => {
      const entry = entries.find((candidate) => candidate.target === root);
      if (!entry) {
        return;
      }
      rootVisible = entry.isIntersecting && entry.intersectionRatio > 0;
      if (rootVisible) {
        scheduleAutoplay(true);
      } else {
        stopAutoplayMotion();
      }
    }, {
      rootMargin: '20% 0px',
      threshold: 0.01,
    });
    intersectionObserver.observe(root);
  }

  scheduleRebuild();

  return {
    refresh: scheduleRebuild,
    cleanup: () => {
      if (disposed) {
        return;
      }
      disposed = true;
      listenerController.abort();
      resizeObserver?.disconnect();
      intersectionObserver?.disconnect();
      if (legacyReducedMotionListener) {
        reducedMotionQuery?.removeListener?.(legacyReducedMotionListener);
        legacyReducedMotionListener = null;
      }
      if (refreshFrame !== null) {
        if (typeof view.cancelAnimationFrame === 'function') {
          view.cancelAnimationFrame(refreshFrame);
        } else {
          view.clearTimeout(refreshFrame);
        }
        refreshFrame = null;
      }
      if (controlsFrame !== null) {
        if (typeof view.cancelAnimationFrame === 'function') {
          view.cancelAnimationFrame(controlsFrame);
        } else {
          view.clearTimeout(controlsFrame);
        }
        controlsFrame = null;
      }
      const fallbackIndex = currentIndex;
      clearEnhancedLayout();
      controls.hidden = true;
      if (autoplayToggle) {
        autoplayToggle.hidden = true;
      }
      const fallbackCard = cardElements()[fallbackIndex];
      if (fallbackCard) {
        viewport.scrollLeft = Math.max(0, fallbackCard.offsetLeft);
      }
    },
  };
};

export const cleanupSectionBlogSlider01 = () => {
  disposeActiveSliders();
  disposeActiveSliders = () => {};
};

export const initSectionBlogSlider01 = (scope = globalThis.document) => {
  cleanupSectionBlogSlider01();
  gsap.registerPlugin(Draggable, InertiaPlugin);
  const documentRef = scope?.ownerDocument
    ?? (scope?.nodeType === 9 ? scope : globalThis.document);
  const instances = new Map();

  const claimRoot = (root) => {
    root[ROOT_OWNER]?.cleanup?.();
    const installed = installSlider(root);
    let cleaned = false;
    const owner = {
      refresh: installed.refresh,
      cleanup: () => {
        if (cleaned) {
          return;
        }
        cleaned = true;
        installed.cleanup();
        if (root[ROOT_OWNER] === owner) {
          delete root[ROOT_OWNER];
        }
      },
    };
    root[ROOT_OWNER] = owner;

    return owner;
  };

  const scan = (scanScope, refreshExisting = false) => {
    for (const root of queryAllSafely(scanScope, ROOT_SELECTOR)) {
      if (!instances.has(root)) {
        instances.set(root, claimRoot(root));
      } else if (refreshExisting) {
        instances.get(root).refresh();
      }
    }
    for (const [root, instance] of instances) {
      if ('isConnected' in root && !root.isConnected) {
        instance.cleanup();
        instances.delete(root);
      }
    }
  };

  scan(scope);
  const view = getView(documentRef);
  const ListenerController = view.AbortController
    ?? globalThis.AbortController;
  const listenerController = new ListenerController();
  documentRef?.addEventListener?.(RESULTS_UPDATED_EVENT, (event) => {
    scan(event.detail?.target ?? documentRef, true);
  }, { signal: listenerController.signal });
  documentRef?.addEventListener?.(ITEMS_APPENDED_EVENT, (event) => {
    const eventRoot = event.detail?.root;
    if (eventRoot && instances.has(eventRoot)) {
      instances.get(eventRoot).refresh();
      return;
    }
    scan(eventRoot ?? documentRef, true);
  }, { signal: listenerController.signal });

  let cleaned = false;
  disposeActiveSliders = () => {
    if (cleaned) {
      return;
    }
    cleaned = true;
    listenerController.abort();
    for (const instance of instances.values()) {
      instance.cleanup();
    }
    instances.clear();
  };

  return disposeActiveSliders;
};

export default initSectionBlogSlider01;

if (import.meta.hot) {
  import.meta.hot.dispose(cleanupSectionBlogSlider01);
}
