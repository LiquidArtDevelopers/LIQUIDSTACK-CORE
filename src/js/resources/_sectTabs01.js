import gsap from 'gsap';

/* sectTabs01.js — ajuste animateTabs sin salto ni doble altura */

export default function initSectTabs01 (containerSelector = '.tabs-container') {
  const container = document.querySelector(containerSelector);
  if (!container) return;

  const navItems  = [...container.querySelectorAll('.nav-item')];
  const tabItems  = [...container.querySelectorAll('.tab-container__item')];
  const slash     = container.querySelector('.nav__line .line');
  let   locked    = false;
  let   initialized = false;

  /* -------- INIT -------- */
  setActive(0);
  navItems.forEach((item, idx) => item.addEventListener('click', () => onClick(idx)));

  const refreshSlash = () => moveSlash(true);

  window.addEventListener('resize', refreshSlash, { passive: true });

  if (document.readyState === 'complete') {
    requestAnimationFrame(refreshSlash);
  } else {
    window.addEventListener('load', refreshSlash, { once: true });
  }

  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(refreshSlash).catch(() => refreshSlash());
  }

  /* -------- HELPERS ----- */
  function onClick (idx) {
    if (locked) return;
    locked = true;
    setActive(idx);
  }

  function setActive (idx = 0) {
    navItems.forEach((el, i) => el.classList.toggle('is-active', i === idx));
    if (!initialized) {
      tabItems.forEach((el, i) => el.classList.toggle('is-active', i === idx));
      moveSlash(true);
      initialized = true;
      locked = false;
      return;
    }
    animateTabs(idx);
    moveSlash();
  }

  function moveSlash (immediate = false) {
    const active = container.querySelector('.nav-item.is-active');
    if (!active || !slash) return;

    const slashParent = slash.parentElement;
    if (!slashParent) return;

    const activeRect = active.getBoundingClientRect();
    const parentRect = slashParent.getBoundingClientRect();
    const props = {
      x: activeRect.left - parentRect.left,
      width: activeRect.width
    };
    if (immediate) {
      gsap.set(slash, props);
    } else {
      gsap.to(slash, { duration: 0.3, ...props });
    }
  }

  /* ===== CORE: transita entre tabs sin salto ni doble altura ===== */
  function animateTabs (idx) {
    const current = container.querySelector('.tab-container__item.is-active');
    const next    = tabItems[idx];
    if (!current || !next || current === next) { locked = false; return; }

    const wrapper = container.querySelector('.tab-containers');
    if (!wrapper) { locked = false; return; }

    const startH = current.offsetHeight;

    gsap.set(wrapper, { height: startH, overflow: 'hidden', position: 'relative' });
    gsap.set(current, { position: 'absolute', top: 0, left: 0, right: 0 });
    gsap.set(next, { display: 'flex', autoAlpha: 0, position: 'absolute', top: 0, left: 0, right: 0 });
    next.classList.add('is-active');

    const endH = next.offsetHeight;

    const tl = gsap.timeline({
      onComplete: () => {
        current.classList.remove('is-active');
        gsap.set(current, { clearProps: 'position,top,left,right,opacity,visibility' });
        gsap.set(next, { clearProps: 'display,position,top,left,right,opacity,visibility' });
        gsap.set(wrapper, { clearProps: 'height,overflow,position' });
        locked = false;
      }
    });

    tl.to(wrapper, { height: endH, duration: 0.35, ease: 'power1.inOut' }, 0)
      .to(current, { autoAlpha: 0, duration: 0.25 }, 0)
      .to(next, { autoAlpha: 1, duration: 0.25 }, 0.05);
  }
}

