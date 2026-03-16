import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';
import ScrollSmoother from 'gsap/ScrollSmoother';

const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

const parseFloatSafe = (value, fallback) => {
  const parsed = Number.parseFloat(value);
  return Number.isFinite(parsed) ? parsed : fallback;
};

function initWorksSkew(section) {
  const items = Array.from(section.querySelectorAll('[data-works-item]'));
  if (items.length === 0) {
    return;
  }

  const mediaEls = items
    .map((item) => item.querySelector('[data-works-media]'))
    .filter((el) => el);
  const textEls = items
    .map((item) => item.querySelector('[data-works-text]'))
    .filter((el) => el);

  const maxSkew = clamp(parseFloatSafe(section.dataset.worksSkewMax, 14), 2, 30);
  const factor = clamp(parseFloatSafe(section.dataset.worksSkewFactor, 12), 6, 30);
  const textFactor = clamp(parseFloatSafe(section.dataset.worksTextFactor, 0.6), 0.2, 1);
  const ease = clamp(parseFloatSafe(section.dataset.worksEase, 0.12), 0.02, 0.4);
  const returnEase = clamp(parseFloatSafe(section.dataset.worksReturn, 0.22), 0.02, 0.6);
  const mediaShift = clamp(parseFloatSafe(section.dataset.worksMediaShift, 40), 0, 160);
  const textShift = clamp(parseFloatSafe(section.dataset.worksTextShift, 220), 0, 500);
  const direction = clamp(parseFloatSafe(section.dataset.worksDirection, -1), -1, 1) || -1;
  const inputIdleDelay = 120;

  const mediaSkew = mediaEls.map((el) => gsap.quickSetter(el, 'skewY', 'deg'));
  const textSkew = textEls.map((el) => gsap.quickSetter(el, 'skewY', 'deg'));
  const mediaY = mediaEls.map((el) => gsap.quickSetter(el, 'y', 'px'));
  const textY = textEls.map((el) => gsap.quickSetter(el, 'y', 'px'));

  gsap.set([...mediaEls, ...textEls], {
    transformOrigin: 'center',
    force3D: true,
    willChange: 'transform',
  });

  let currentSkew = 0;
  let currentTextSkew = 0;
  let targetSkew = 0;
  let parallaxFactor = 0;

  const smoother = ScrollSmoother.get && ScrollSmoother.get();
  const getScroll = smoother
    ? () => smoother.scrollTop()
    : ScrollTrigger.getScrollFunc
      ? ScrollTrigger.getScrollFunc(window)
      : () => window.pageYOffset || document.documentElement.scrollTop || 0;

  let lastScroll = getScroll();
  let lastTime = performance.now();
  let lastInputAt = lastTime;

  const markInput = () => {
    lastInputAt = performance.now();
  };

  const onKey = (event) => {
    const keys = ['ArrowDown', 'ArrowUp', 'PageDown', 'PageUp', 'Home', 'End', 'Space', ' '];
    if (keys.includes(event.key)) {
      markInput();
    }
  };

  const inputTarget = smoother && smoother.wrapper ? smoother.wrapper() : window;
  inputTarget.addEventListener('wheel', markInput, { passive: true });
  inputTarget.addEventListener('touchmove', markInput, { passive: true });
  inputTarget.addEventListener('touchstart', markInput, { passive: true });
  window.addEventListener('keydown', onKey);

  const isSectionVisible = () => {
    const rect = section.getBoundingClientRect();
    return rect.bottom > 0 && rect.top < window.innerHeight;
  };

  const applyParallax = (progress) => {
    parallaxFactor = (progress - 0.5) * 2;
    const mediaOffset = parallaxFactor * mediaShift;
    const textOffset = parallaxFactor * textShift;
    mediaY.forEach((set) => set(mediaOffset));
    textY.forEach((set) => set(textOffset));
  };

  ScrollTrigger.create({
    trigger: section,
    start: 'top bottom',
    end: 'bottom top',
    onUpdate: (self) => {
      applyParallax(self.progress);
    },
    onRefresh: (self) => {
      applyParallax(self.progress);
    },
  });

  const tick = () => {
    const now = performance.now();
    const scrollPos = getScroll();
    const dt = Math.max(16, now - lastTime);
    const velocity = ((scrollPos - lastScroll) / dt) * 1000;
    lastScroll = scrollPos;
    lastTime = now;

    const inputIdle = now - lastInputAt > inputIdleDelay;

    if (isSectionVisible() && !inputIdle) {
      const next = clamp((velocity / factor) * direction, -maxSkew, maxSkew);
      targetSkew = next;
    } else {
      targetSkew = 0;
    }

    const easing = targetSkew === 0 ? returnEase : ease;
    const textEasing = clamp(easing * textFactor + 0.02, 0.02, 0.5);
    currentSkew += (targetSkew - currentSkew) * easing;
    currentTextSkew += (targetSkew * textFactor - currentTextSkew) * textEasing;

    mediaSkew.forEach((set) => set(currentSkew));
    textSkew.forEach((set) => set(currentTextSkew));
  };

  gsap.ticker.add(tick);
}

export default function initArtWorksSkew01() {
  gsap.registerPlugin(ScrollTrigger, ScrollSmoother);
  const sections = Array.from(document.querySelectorAll('[data-works-skew]'));
  if (sections.length === 0) {
    return;
  }

  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  sections.forEach((section) => {
    if (section.dataset.worksInit === 'true') {
      return;
    }
    section.dataset.worksInit = 'true';

    if (!prefersReducedMotion) {
      initWorksSkew(section);
    }
  });
}
