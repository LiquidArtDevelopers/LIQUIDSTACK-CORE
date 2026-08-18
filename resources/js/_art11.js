import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';

const COUNTER_STATE_KEY = Symbol.for('liquidstack.art11.counterState');
const COUNTER_SELECTOR = '.art11 .stat-number';
const VALUE_SELECTOR = '[data-art11-counter-value]';
const EASING = 'power1.out';
const DURATION = 1.5;
const START_POINT = 'top 90%';

let cleanupActiveCounters = () => {};

const readTarget = (counter) => {
  const target = Number.parseFloat(counter.dataset.target ?? '');

  return Number.isFinite(target) ? target : 0;
};

const formatNumber = (value) => Math.floor(value).toLocaleString('es-ES');

const renderValue = (valueElement, value) => {
  valueElement.textContent = formatNumber(value);
};

const cleanupCounter = (counter) => {
  const state = counter[COUNTER_STATE_KEY];
  if (!state) return;

  state.tween?.kill();
  state.scrollTrigger?.kill();
  state.targetObserver?.disconnect();
  delete counter[COUNTER_STATE_KEY];
};

const animateCounter = (counter, valueElement) => {
  const state = counter[COUNTER_STATE_KEY];
  if (!state) return;

  state.tween?.kill();

  const proxy = { value: 0 };
  state.tween = gsap.to(proxy, {
    value: readTarget(counter),
    duration: DURATION,
    ease: EASING,
    snap: { value: 1 },
    onUpdate () {
      renderValue(valueElement, proxy.value);
    }
  });
};

const resetCounter = (counter, valueElement) => {
  const state = counter[COUNTER_STATE_KEY];
  state?.tween?.kill();

  if (state) {
    state.tween = null;
  }
  renderValue(valueElement, 0);
};

export default function initStatsCounter () {
  cleanupActiveCounters();
  gsap.registerPlugin(ScrollTrigger);

  const counters = Array.from(document.querySelectorAll(COUNTER_SELECTOR));

  counters.forEach((counter) => {
    cleanupCounter(counter);

    const valueElement = counter.querySelector(VALUE_SELECTOR);
    if (!valueElement) return;

    const state = {
      scrollTrigger: null,
      targetObserver: null,
      tween: null
    };
    counter[COUNTER_STATE_KEY] = state;

    state.scrollTrigger = ScrollTrigger.create({
      trigger: counter.parentElement,
      start: START_POINT,
      onEnter: () => animateCounter(counter, valueElement),
      onEnterBack: () => animateCounter(counter, valueElement),
      onLeave: () => resetCounter(counter, valueElement),
      onLeaveBack: () => resetCounter(counter, valueElement)
    });

    if (typeof MutationObserver === 'function') {
      state.targetObserver = new MutationObserver((mutations) => {
        const targetChanged = mutations.some(
          (mutation) => mutation.attributeName === 'data-target'
        );
        if (!targetChanged) return;

        state.tween?.kill();
        state.tween = null;
        renderValue(valueElement, readTarget(counter));
      });
      state.targetObserver.observe(counter, {
        attributes: true,
        attributeFilter: ['data-target']
      });
    }
  });

  const cleanup = () => {
    counters.forEach(cleanupCounter);
    if (cleanupActiveCounters === cleanup) {
      cleanupActiveCounters = () => {};
    }
  };

  cleanupActiveCounters = cleanup;

  return cleanup;
}

if (import.meta.hot) {
  import.meta.hot.dispose(() => cleanupActiveCounters());
}
