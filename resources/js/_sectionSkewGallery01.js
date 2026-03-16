import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';
import ScrollSmoother from 'gsap/ScrollSmoother';

const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

const parseFloatSafe = (value, fallback) => {
  const parsed = Number.parseFloat(value);
  return Number.isFinite(parsed) ? parsed : fallback;
};

function initSkew(section) {
  const mediaButtons = Array.from(section.querySelectorAll('[data-skew-open]'));
  if (mediaButtons.length === 0) {
    return;
  }

  const targets = mediaButtons;
  const maxSkew = clamp(parseFloatSafe(section.dataset.skewMax, 20), 2, 40);
  const ease = clamp(parseFloatSafe(section.dataset.skewEase, 0.12), 0.02, 0.4);
  const returnEase = clamp(parseFloatSafe(section.dataset.skewReturn, 0.22), 0.02, 0.6);
  const factor = clamp(parseFloatSafe(section.dataset.skewFactor, 10), 6, 30);
  const direction = clamp(parseFloatSafe(section.dataset.skewDirection, -1), -1, 1) || -1;
  const inputIdleDelay = 120;

  const setters = targets.map((el) => gsap.quickSetter(el, 'skewY', 'deg'));
  gsap.set(targets, {
    transformOrigin: 'center',
    force3D: true,
    willChange: 'transform',
  });

  let currentSkew = 0;
  let targetSkew = 0;
  const smoother = ScrollSmoother.get && ScrollSmoother.get();
  const getScroll = smoother
    ? () => smoother.scrollTop()
    : ScrollTrigger.getScrollFunc
      ? ScrollTrigger.getScrollFunc(window)
      : () => window.pageYOffset || document.documentElement.scrollTop || 0;

  const isSectionVisible = () => {
    const rect = section.getBoundingClientRect();
    return rect.bottom > 0 && rect.top < window.innerHeight;
  };
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

  const tick = () => {
    const now = performance.now();
    const scrollPos = getScroll();
    const dt = Math.max(16, now - lastTime);
    const velocity = ((scrollPos - lastScroll) / dt) * 1000;
    lastScroll = scrollPos;
    lastTime = now;

    const inputIdle = now - lastInputAt > inputIdleDelay;

    if (isSectionVisible()) {
      if (inputIdle) {
        targetSkew = 0;
      } else {
        const next = clamp((velocity / factor) * direction, -maxSkew, maxSkew);
        targetSkew = next;
      }
    } else {
      targetSkew = 0;
    }

    const easing = targetSkew === 0 ? returnEase : ease;
    currentSkew += (targetSkew - currentSkew) * easing;
    setters.forEach((set) => set(currentSkew));
  };

  gsap.ticker.add(tick);
}

function initLightbox(section) {
  const lightbox = section.querySelector('[data-skew-lightbox]');
  const media = section.querySelector('[data-skew-lightbox-media]');
  const image = section.querySelector('[data-skew-lightbox-img]');
  const cursor = section.querySelector('[data-skew-cursor]');
  const openButtons = Array.from(section.querySelectorAll('[data-skew-open]'));
  const closeTargets = Array.from(section.querySelectorAll('[data-skew-close]'));

  if (!lightbox || !media || !image || !cursor || openButtons.length === 0) {
    return;
  }

  if (!lightbox.dataset.portalized) {
    document.body.appendChild(lightbox);
    lightbox.dataset.portalized = 'true';
  }

  const zoom = clamp(parseFloatSafe(section.dataset.skewZoom, 1.1), 1, 1.3);
  let isOpen = false;
  let bounds = { maxX: 0, maxY: 0 };
  let targetX = 0;
  let targetY = 0;

  const cursorX = gsap.quickTo(cursor, 'x', { duration: 0.18, ease: 'power3.out' });
  const cursorY = gsap.quickTo(cursor, 'y', { duration: 0.18, ease: 'power3.out' });
  const imageX = gsap.quickTo(image, 'x', { duration: 0.35, ease: 'power3.out' });
  const imageY = gsap.quickTo(image, 'y', { duration: 0.35, ease: 'power3.out' });

  const syncBounds = () => {
    if (!isOpen) {
      return;
    }
    const rect = media.getBoundingClientRect();
    const scale = Math.max(1, zoom);
    bounds = {
      maxX: Math.max(0, (rect.width * (scale - 1)) / 2),
      maxY: Math.max(0, (rect.height * (scale - 1)) / 2),
    };
    targetX = clamp(targetX, -bounds.maxX, bounds.maxX);
    targetY = clamp(targetY, -bounds.maxY, bounds.maxY);
    imageX(targetX);
    imageY(targetY);
  };

  const updateTarget = (event) => {
    if (!isOpen) {
      return;
    }
    const rect = media.getBoundingClientRect();
    if (!rect.width || !rect.height) {
      return;
    }
    const relX = (event.clientX - rect.left) / rect.width;
    const relY = (event.clientY - rect.top) / rect.height;
    const offsetX = (relX - 0.5) * 2;
    const offsetY = (relY - 0.5) * 2;
    targetX = -offsetX * bounds.maxX;
    targetY = -offsetY * bounds.maxY;
    imageX(targetX);
    imageY(targetY);
    cursorX(event.clientX);
    cursorY(event.clientY);
  };

  const onPointerMove = (event) => {
    if (event.pointerType && event.pointerType !== 'mouse') {
      return;
    }
    updateTarget(event);
  };

  const openLightbox = (button) => {
    const src = button.dataset.skewSrcFull || button.dataset.skewSrc || '';
    if (!src) {
      return;
    }
    const alt = button.dataset.skewAlt || '';
    const title = button.dataset.skewTitle || '';
    image.src = src;
    image.alt = alt || title;
    image.style.width = '100%';
    image.style.height = '100%';
    image.style.maxWidth = 'none';
    image.style.maxHeight = 'none';
    image.style.objectFit = 'cover';
    image.style.objectPosition = '50% 50%';
    gsap.set(image, { scale: zoom, x: 0, y: 0, transformOrigin: 'center' });
    targetX = 0;
    targetY = 0;
    isOpen = true;
    lightbox.classList.add('is-open');
    lightbox.setAttribute('aria-hidden', 'false');
    document.body.classList.add('is-skew-gallery-open');
    gsap.set(cursor, { scale: 1, opacity: 1 });
    if (image.complete) {
      requestAnimationFrame(syncBounds);
    } else {
      image.addEventListener('load', () => requestAnimationFrame(syncBounds), { once: true });
    }
  };

  const closeLightbox = (withShrink = false) => {
    if (!isOpen) {
      return;
    }
    isOpen = false;
    const finalize = () => {
      lightbox.classList.remove('is-open');
      lightbox.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('is-skew-gallery-open');
      gsap.set(cursor, { scale: 1 });
    };

    if (withShrink) {
      gsap.to(cursor, { scale: 0.6, duration: 0.15, ease: 'power2.out', onComplete: finalize });
      return;
    }
    finalize();
  };

  openButtons.forEach((button) => {
    button.addEventListener('click', () => openLightbox(button));
  });

  closeTargets.forEach((el) => {
    if (el === cursor) {
      return;
    }
    el.addEventListener('click', () => closeLightbox(false));
  });

  if (cursor) {
    cursor.addEventListener('click', (event) => {
      event.stopPropagation();
      closeLightbox(true);
    });
  }

  lightbox.addEventListener('click', (event) => {
    if (!isOpen) {
      return;
    }
    if (event.target === cursor) {
      return;
    }
    closeLightbox(false);
  });

  lightbox.addEventListener('pointermove', onPointerMove);
  lightbox.addEventListener('pointerleave', () => {
    if (!isOpen) {
      return;
    }
    targetX = 0;
    targetY = 0;
    imageX(targetX);
    imageY(targetY);
  });

  window.addEventListener('resize', () => {
    syncBounds();
  });

  window.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeLightbox();
    }
  });
}

export default function initSectionSkewGallery01() {
  gsap.registerPlugin(ScrollTrigger, ScrollSmoother);
  const sections = Array.from(document.querySelectorAll('[data-skew-gallery]'));
  if (sections.length === 0) {
    return;
  }

  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  sections.forEach((section) => {
    if (section.dataset.skewInit === 'true') {
      return;
    }
    section.dataset.skewInit = 'true';

    if (!prefersReducedMotion) {
      initSkew(section);
    }
    initLightbox(section);
  });
}
