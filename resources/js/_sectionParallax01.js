import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';

export default function initSectionParallax01() {
  gsap.registerPlugin(ScrollTrigger);

  const sections = Array.from(document.querySelectorAll('.sectionParallax01'));
  if (sections.length === 0) {
    return;
  }

  const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const initPointerParallax = (section) => {
    const maxShift = parseFloat(section.dataset.parallaxShift || '14');
    if (!Number.isFinite(maxShift) || maxShift <= 0) {
      return;
    }

    let currentX = 0;
    let currentY = 0;
    let targetX = 0;
    let targetY = 0;
    let rafId = null;

    const update = () => {
      const ease = 0.08;
      currentX += (targetX - currentX) * ease;
      currentY += (targetY - currentY) * ease;

      section.style.setProperty('--section-parallax01-bg-x', `${currentX.toFixed(2)}px`);
      section.style.setProperty('--section-parallax01-bg-y', `${currentY.toFixed(2)}px`);

      if (Math.abs(targetX - currentX) < 0.1 && Math.abs(targetY - currentY) < 0.1) {
        currentX = targetX;
        currentY = targetY;
        section.style.setProperty('--section-parallax01-bg-x', `${currentX.toFixed(2)}px`);
        section.style.setProperty('--section-parallax01-bg-y', `${currentY.toFixed(2)}px`);
        rafId = null;
        return;
      }

      rafId = requestAnimationFrame(update);
    };

    const queueUpdate = () => {
      if (rafId === null) {
        rafId = requestAnimationFrame(update);
      }
    };

    const onMove = (event) => {
      if (event.pointerType && event.pointerType !== 'mouse') {
        return;
      }
      const rect = section.getBoundingClientRect();
      if (!rect.width || !rect.height) {
        return;
      }
      const relX = (event.clientX - rect.left) / rect.width;
      const relY = (event.clientY - rect.top) / rect.height;
      const offsetX = (relX - 0.5) * 2;
      const offsetY = (relY - 0.5) * 2;
      targetX = offsetX * maxShift;
      targetY = offsetY * maxShift;
      queueUpdate();
    };

    const onLeave = () => {
      targetX = 0;
      targetY = 0;
      queueUpdate();
    };

    section.addEventListener('pointermove', onMove);
    section.addEventListener('pointerleave', onLeave);
  };

  sections.forEach((section) => {
    if (!prefersReduced) {
      initPointerParallax(section);
    }

    const items = Array.from(section.querySelectorAll('[data-parallax-item]'));
    if (items.length === 0) {
      return;
    }

    if (prefersReduced) {
      gsap.set(items, { clearProps: 'transform,opacity' });
      return;
    }

    const marginRem = parseFloat(section.dataset.stackMarginRem || '2');
    const pinnedItems = items.length > 1 ? items.slice(0, -1) : [];

    const getStartPoint = () => {
      if (section.dataset.stackStart) {
        return section.dataset.stackStart;
      }
      const nav = document.querySelector('nav');
      const navHeight = nav ? nav.getBoundingClientRect().height : 0;
      const remSize = parseFloat(getComputedStyle(document.documentElement).fontSize || '16');
      const offset = navHeight + remSize * marginRem;
      return `top top+=${Math.round(offset)}`;
    };

    items.forEach((item, index) => {
      item.style.zIndex = String(index + 1);

      gsap.set(item, { autoAlpha: 1 });
    });

    if (pinnedItems.length === 0) {
      return;
    }

    pinnedItems.forEach((item, index) => {
      const nextItem = pinnedItems[index + 1] ?? items[items.length - 1];

      gsap.to(item, {
        autoAlpha: 0,
        ease: 'none',
        scrollTrigger: {
          trigger: item,
          start: getStartPoint,
          endTrigger: nextItem,
          end: getStartPoint,
          scrub: true,
          pin: true,
          pinSpacing: false,
          pinType: 'transform',
          anticipatePin: 1,
          invalidateOnRefresh: true,
        },
      });
    });
  });
}
