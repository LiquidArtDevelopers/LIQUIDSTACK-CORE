import gsap from 'gsap';
import { Draggable, InertiaPlugin } from 'gsap/all';

const ROOT_SELECTOR = '[data-blog-collection="sectionBlogSlider02"]';
const RESULTS_UPDATED_EVENT = 'liquidstack:blog-results-updated';
const ITEMS_APPENDED_EVENT = 'liquidstack:blog-collection-appended';
const ROOT_OWNER = Symbol.for(
  'liquidstack.blog.sectionBlogSlider02.owner',
);
const CARD_SELECTOR = '.sectionBlogSlider02-item';

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
  const viewport = root.querySelector?.('[data-blog-slider02-viewport]');
  const track = root.querySelector?.('[data-blog-slider02-track]');
  const controls = root.querySelector?.('[data-blog-slider02-controls]');
  const previous = root.querySelector?.('[data-blog-slider02-previous]');
  const next = root.querySelector?.('[data-blog-slider02-next]');
  const autoplayToggle = root.querySelector?.(
    '[data-blog-slider02-autoplay-toggle]',
  );
  const status = root.querySelector?.('[data-blog-collection-status]');
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
  const autoplayConfigured = root.dataset.blogSlider02Autoplay !== 'false';
  const autoplayDelay = finiteNumber(
    root.dataset.blogSlider02AutoplayDelay,
    6,
    2,
    60,
  );
  const transitionDuration = finiteNumber(
    root.dataset.blogSlider02Duration,
    2,
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
  let wheelSnapCall = null;
  let resizeObserver = null;
  let intersectionObserver = null;
  let refreshFrame = null;
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
  let pointerFocusCard = null;
  let pointerFocusClearCall = null;
  let legacyReducedMotionListener = null;

  const now = () => view.performance?.now?.() ?? Date.now();
  const reducedMotion = () => reducedMotionQuery?.matches === true;
  const cardElements = () => Array.from(track.children ?? []).filter(
    (element) => element.matches?.(CARD_SELECTOR)
      && !element.hasAttribute?.('data-blog-slider02-clone'),
  );
  const proxyX = () => Number(gsap.getProperty(proxy, 'x')) || 0;
  const canNavigate = () => (
    cards.length > 0
    && (loopEnabled || cards.length > 1)
  );

  const clearPointerFocus = () => {
    if (pointerFocusClearCall !== null) {
      view.clearTimeout(pointerFocusClearCall);
      pointerFocusClearCall = null;
    }
    pointerFocusCard = null;
  };

  const schedulePointerFocusClear = () => {
    if (pointerFocusClearCall !== null) {
      view.clearTimeout(pointerFocusClearCall);
    }
    pointerFocusClearCall = view.setTimeout(() => {
      pointerFocusClearCall = null;
      pointerFocusCard = null;
    }, 0);
  };

  const killMotion = () => {
    motionTween?.kill();
    motionTween = null;
    motionIsAutoplay = false;
    wheelSnapCall?.kill();
    wheelSnapCall = null;
  };

  const killAutoplay = () => {
    autoplayCall?.kill();
    autoplayCall = null;
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
      return clamp(snapped, -loopWidth, step);
    }
    return loopEnabled ? snapped : clamp(snapped, -maximumTravel, 0);
  };

  const normalizeLoopProxy = () => {
    if (!loopEnabled || cards.length === 0 || step <= 0) {
      return;
    }
    const normalizedIndex = modulo(
      Math.round(-proxyX() / step),
      cards.length,
    );
    gsap.set(proxy, { x: -(normalizedIndex * step) });
    render();
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
    const atStart = viewport.scrollLeft <= 2;
    const atEnd = viewport.scrollLeft
      >= viewport.scrollWidth - viewport.clientWidth - 2;
    previous.disabled = !hasOverflow || atStart;
    next.disabled = !hasOverflow || atEnd;
    if (autoplayToggle) {
      autoplayToggle.hidden = true;
    }
  };

  const animateProxy = (targetX, interaction) => {
    if (!enhanced) {
      return;
    }
    killMotion();
    if (interaction) {
      killAutoplay();
    }
    motionTween = gsap.to(proxy, {
      x: targetX,
      duration: reducedMotion() ? 0 : transitionDuration,
      ease: 'power3.out',
      overwrite: true,
      onUpdate: render,
      onComplete: () => {
        motionTween = null;
        motionIsAutoplay = false;
        normalizeLoopProxy();
        syncCurrentIndex();
        updateControls();
        scheduleAutoplay(true);
      },
    });
    motionIsAutoplay = !interaction;
  };

  const nativeMoveBy = (offset) => {
    const availableCards = cardElements();
    if (availableCards.length === 0) {
      return;
    }
    const viewportRect = viewport.getBoundingClientRect();
    let nearest = 0;
    let nearestDistance = Number.POSITIVE_INFINITY;
    availableCards.forEach((card, index) => {
      const distance = Math.abs(
        card.getBoundingClientRect().left - viewportRect.left,
      );
      if (distance < nearestDistance) {
        nearest = index;
        nearestDistance = distance;
      }
    });
    const target = availableCards[clamp(
      nearest + offset,
      0,
      availableCards.length - 1,
    )];
    if (!target) {
      return;
    }
    const left = target.getBoundingClientRect().left - viewportRect.left;
    viewport.scrollBy({
      left,
      behavior: reducedMotion() ? 'auto' : 'smooth',
    });
  };

  function moveBy(offset, interaction = true) {
    if (!enhanced) {
      nativeMoveBy(offset);
      return;
    }
    if (!canNavigate() || step <= 0) {
      return;
    }
    const target = snappedX(proxyX() - (offset * step));
    animateProxy(target, interaction);
  }

  const moveToCard = (card, interaction = true) => {
    const targetIndex = cards.indexOf(card);
    if (targetIndex < 0) {
      return;
    }
    if (!enhanced) {
      const viewportRect = viewport.getBoundingClientRect();
      viewport.scrollBy({
        left: card.getBoundingClientRect().left - viewportRect.left,
        behavior: reducedMotion() ? 'auto' : 'smooth',
      });
      return;
    }
    const baseTarget = -(targetIndex * step);
    if (!loopEnabled) {
      animateProxy(clamp(baseTarget, -maximumTravel, 0), interaction);
      return;
    }
    const position = proxyX();
    const cycle = Math.round((position - baseTarget) / loopWidth);
    let target = baseTarget + (cycle * loopWidth);
    if (loopCopiesEnabled) {
      const candidates = [target - loopWidth, target, target + loopWidth]
        .filter((candidate) => (
          candidate >= -loopWidth && candidate <= loopWidth
        ))
        .sort(
          (first, second) => Math.abs(first - position)
            - Math.abs(second - position),
        );
      [target] = candidates;
    }
    animateProxy(target, interaction);
  };

  const clearEnhancedLayout = () => {
    draggable?.kill();
    draggable = null;
    killMotion();
    killAutoplay();
    root.classList.remove(
      'sectionBlogSlider02--enhanced',
      'sectionBlogSlider02--dragging',
    );
    dragging = false;
    pressProxyPosition = 0;
    dragDistance = 0;
    const renderedCards = [...cards, ...clones];
    if (renderedCards.length > 0) {
      gsap.set(renderedCards, { clearProps: 'transform,height,willChange' });
    }
    clones.forEach((clone) => clone.remove?.());
    clones = [];
    gsap.set(proxy, { x: 0 });
    track.style.removeProperty('height');
    setters = [];
    renderOffsets = [];
    enhanced = false;
    loopEnabled = false;
    loopCopiesEnabled = false;
    step = 0;
    loopWidth = 0;
    maximumTravel = 0;
    inlinePadding = 0;
  };

  const cloneForLoop = (card) => {
    const clone = card.cloneNode?.(true);
    if (!clone) {
      return null;
    }
    clone.setAttribute('data-blog-slider02-clone', '');
    clone.removeAttribute('data-blog-card-key');
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

  const recordDragDistance = () => {
    dragDistance = Math.max(
      dragDistance,
      Math.abs(proxyX() - pressProxyPosition),
    );
  };

  const suppressClickAfterDrag = () => {
    recordDragDistance();
    if (dragDistance > 4) {
      suppressClickUntil = Math.max(suppressClickUntil, now() + 650);
    }
  };

  const installDraggable = () => {
    const options = {
      type: 'x',
      trigger: viewport,
      inertia: true,
      dragClickables: true,
      minimumMovement: 4,
      snap: { x: snappedX },
      onPress() {
        dragging = true;
        pressProxyPosition = proxyX();
        dragDistance = 0;
        killMotion();
        killAutoplay();
        root.classList.add('sectionBlogSlider02--dragging');
        this.update();
      },
      onDrag() {
        recordDragDistance();
        render();
      },
      onThrowUpdate() {
        recordDragDistance();
        render();
      },
      onRelease() {
        suppressClickAfterDrag();
        if (!this.isThrowing) {
          dragging = false;
          root.classList.remove('sectionBlogSlider02--dragging');
          const target = snappedX(proxyX());
          if (Math.abs(target - proxyX()) > 0.5) {
            animateProxy(target, true);
          } else {
            normalizeLoopProxy();
            syncCurrentIndex();
            updateControls();
            scheduleAutoplay(true);
          }
        }
      },
      onThrowComplete() {
        suppressClickAfterDrag();
        dragging = false;
        root.classList.remove('sectionBlogSlider02--dragging');
        normalizeLoopProxy();
        syncCurrentIndex();
        updateControls();
        scheduleAutoplay(true);
      },
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

    if (cards.length === 0) {
      root.dataset.state = 'empty';
      controls.hidden = true;
      if (autoplayToggle) {
        autoplayToggle.hidden = true;
      }
      viewport.removeAttribute('tabindex');
      const emptyMessage = status?.dataset?.emptyMessage ?? '';
      if (status && emptyMessage !== '') {
        status.textContent = emptyMessage;
        status.dataset.state = 'empty';
        status.hidden = false;
      }
      return;
    }
    root.dataset.state = 'ready';
    const nativeOverflow = cards.length > 1
      && viewport.scrollWidth > viewport.clientWidth + 2;
    const isRtl = view.getComputedStyle?.(viewport).direction === 'rtl';
    const canEnhance = !reducedMotion()
      && !isRtl
      && cards.length > 0;
    if (!canEnhance) {
      if (nativeOverflow) {
        if (!viewport.hasAttribute('tabindex')) {
          viewport.setAttribute('tabindex', '0');
        }
      } else {
        viewport.removeAttribute('tabindex');
      }
      const target = cards[currentIndex];
      if (target) {
        viewport.scrollLeft = Math.max(0, target.offsetLeft);
      }
      updateControls();
      return;
    }
    if (!viewport.hasAttribute('tabindex')) {
      viewport.setAttribute('tabindex', '0');
    }

    root.classList.add('sectionBlogSlider02--enhanced');
    enhanced = true;
    viewport.scrollLeft = 0;
    const trackStyle = view.getComputedStyle?.(track);
    const firstCard = cards[0];
    const gap = finiteNumber(
      trackStyle?.columnGap === 'normal'
        ? trackStyle?.gap
        : trackStyle?.columnGap,
      0,
      0,
      500,
    );
    inlinePadding = finiteNumber(trackStyle?.paddingInlineStart, 0, 0, 1000);
    const cardWidth = firstCard.offsetWidth;
    step = cardWidth + gap;
    loopWidth = step * cards.length;
    const naturalWidth = (cardWidth * cards.length)
      + (gap * Math.max(0, cards.length - 1))
      + (2 * inlinePadding);
    maximumTravel = Math.max(0, naturalWidth - viewport.clientWidth);
    loopEnabled = cards.length > 0;
    loopCopiesEnabled = loopEnabled
      && loopWidth <= viewport.clientWidth + step;
    const tallestCard = Math.max(
      ...cards.map((card) => card.offsetHeight),
      1,
    );
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

  const scheduleRebuild = () => {
    if (refreshFrame !== null || disposed) {
      return;
    }
    refreshFrame = view.requestAnimationFrame?.(rebuild)
      ?? view.setTimeout(rebuild, 0);
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
    normalizeLoopProxy();
    render();
    syncCurrentIndex();
    updateControls();
  };

  previous.addEventListener('click', () => moveBy(-1), listenerOptions);
  next.addEventListener('click', () => moveBy(1), listenerOptions);
  autoplayToggle?.addEventListener('click', () => {
    userPaused = !userPaused;
    scheduleAutoplay(true);
  }, listenerOptions);
  viewport.addEventListener('scroll', () => {
    if (!enhanced) {
      updateControls();
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
    } else if (event.key === 'Home' && cards[0]) {
      event.preventDefault();
      moveToCard(cards[0]);
    } else if (event.key === 'End' && cards.at(-1)) {
      event.preventDefault();
      moveToCard(cards.at(-1));
    }
  }, listenerOptions);
  viewport.addEventListener('wheel', (event) => {
    if (!enhanced) {
      return;
    }
    const horizontalIntent = Math.abs(event.deltaX) > Math.abs(event.deltaY);
    if (!horizontalIntent && !event.shiftKey) {
      return;
    }
    const delta = horizontalIntent ? event.deltaX : event.deltaY;
    if (delta === 0) {
      return;
    }
    event.preventDefault();
    killMotion();
    killAutoplay();
    const modeScale = event.deltaMode === 1
      ? 16
      : (event.deltaMode === 2 ? viewport.clientWidth : 1);
    const requestedTarget = proxyX() - (delta * modeScale);
    const target = loopCopiesEnabled
      ? clamp(requestedTarget, -loopWidth, loopWidth)
      : (loopEnabled
        ? requestedTarget
        : clamp(requestedTarget, -maximumTravel, 0));
    gsap.set(proxy, { x: target });
    render();
    wheelSnapCall = gsap.delayedCall(0.14, () => {
      wheelSnapCall = null;
      animateProxy(snappedX(proxyX()), true);
    });
  }, { passive: false, signal: listenerController.signal });
  viewport.addEventListener('click', (event) => {
    if (now() < suppressClickUntil) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  }, { capture: true, signal: listenerController.signal });
  root.addEventListener('pointerenter', () => {
    pointerInside = true;
    stopAutoplayMotion();
  }, listenerOptions);
  root.addEventListener('pointerleave', () => {
    pointerInside = false;
    scheduleAutoplay(true);
  }, listenerOptions);
  root.addEventListener('pointerdown', (event) => {
    clearPointerFocus();
    const pressedCard = event.target?.closest?.(CARD_SELECTOR);
    if (pressedCard && root.contains(pressedCard)) {
      pointerFocusCard = pressedCard;
    }
  }, { capture: true, signal: listenerController.signal });
  documentRef?.addEventListener?.(
    'pointerup',
    schedulePointerFocusClear,
    listenerOptions,
  );
  documentRef?.addEventListener?.(
    'pointercancel',
    clearPointerFocus,
    listenerOptions,
  );
  root.addEventListener('focusin', (event) => {
    focusInside = true;
    stopAutoplayMotion();
    const focusedCard = event.target?.closest?.(CARD_SELECTOR);
    const focusCameFromPointer = focusedCard !== null
      && focusedCard === pointerFocusCard;
    clearPointerFocus();
    if (
      focusedCard
      && root.contains(focusedCard)
      && !focusCameFromPointer
    ) {
      moveToCard(focusedCard, true);
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
      killAutoplay();
      motionTween?.pause();
    } else {
      motionTween?.resume();
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
    resizeObserver = new ResizeObserverConstructor(scheduleRebuild);
    resizeObserver.observe(viewport);
    resizeObserver.observe(root);
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
      clearPointerFocus();
      const fallbackIndex = currentIndex;
      clearEnhancedLayout();
      controls.hidden = true;
      if (autoplayToggle) {
        autoplayToggle.hidden = true;
      }
      const fallbackCards = cardElements();
      const fallbackCard = fallbackCards[fallbackIndex];
      if (fallbackCard) {
        viewport.scrollLeft = Math.max(0, fallbackCard.offsetLeft);
      }
    },
  };
};

export const cleanupSectionBlogSlider02 = () => {
  disposeActiveSliders();
  disposeActiveSliders = () => {};
};

export const initSectionBlogSlider02 = (scope = globalThis.document) => {
  cleanupSectionBlogSlider02();
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

  const scan = (scanScope) => {
    for (const root of queryAllSafely(scanScope, ROOT_SELECTOR)) {
      if (!instances.has(root)) {
        instances.set(root, claimRoot(root));
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
    scan(event.detail?.target ?? documentRef);
  }, { signal: listenerController.signal });
  documentRef?.addEventListener?.(ITEMS_APPENDED_EVENT, (event) => {
    const eventRoot = event.detail?.root;
    if (eventRoot && instances.has(eventRoot)) {
      instances.get(eventRoot).refresh();
      return;
    }
    scan(eventRoot ?? documentRef);
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

export default initSectionBlogSlider02;

if (import.meta.hot) {
  import.meta.hot.dispose(cleanupSectionBlogSlider02);
}
