import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';

const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

const parseFloatSafe = (value, fallback) => {
  const parsed = Number.parseFloat(value);
  return Number.isFinite(parsed) ? parsed : fallback;
};

function initHScroll(section) {
  const track = section.querySelector('[data-hscroll-track]');
  const viewport = section.querySelector('[data-hscroll-viewport]');
  if (!track || !viewport) {
    return;
  }

  let tween = null;

  const build = () => {
    if (tween) {
      tween.scrollTrigger?.kill();
      tween.kill();
      tween = null;
    }

    const viewportWidth = viewport.clientWidth;
    const trackWidth = track.scrollWidth;
    const distance = Math.max(0, trackWidth - viewportWidth);

    gsap.set(track, { x: 0 });

    if (distance <= 1) {
      return;
    }

    const speed = clamp(parseFloatSafe(section.dataset.hscrollSpeed, 1.1), 0.6, 3);
    const lastItem = track.querySelector('[data-hscroll-item]:last-child');
    let endX = -distance;

    if (lastItem) {
      const lastCenter = lastItem.offsetLeft + lastItem.offsetWidth / 2;
      const targetX = -(lastCenter - viewportWidth / 2);
      if (targetX < endX) {
        endX = targetX;
      }
    }
    const travel = Math.abs(endX);

    tween = gsap.to(track, {
      x: endX,
      ease: 'none',
      scrollTrigger: {
        trigger: section,
        start: 'top top',
        end: () => `+=${travel * speed}`,
        pin: true,
        scrub: true,
        invalidateOnRefresh: true,
      },
    });
  };

  build();

  const onResize = () => {
    build();
    ScrollTrigger.refresh();
  };

  let resizeCall = null;
  window.addEventListener('resize', () => {
    if (resizeCall) {
      resizeCall.kill();
    }
    resizeCall = gsap.delayedCall(0.2, onResize);
  });
}

export default function initSectionHScroll01() {
  gsap.registerPlugin(ScrollTrigger);
  const sections = Array.from(document.querySelectorAll('[data-hscroll-section]'));
  if (sections.length === 0) {
    return;
  }

  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  sections.forEach((section) => {
    if (section.dataset.hscrollInit === 'true') {
      return;
    }
    section.dataset.hscrollInit = 'true';

    if (!prefersReducedMotion) {
      initHScroll(section);
    }
  });
}
