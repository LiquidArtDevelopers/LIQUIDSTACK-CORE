import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

const cleanupKey = Symbol.for('liquidstack.navMegamenu01.cleanup');

export default function initNavMegamenu01() {
  const nav = document.querySelector('nav');
  if (!nav) return;

  if (typeof nav[cleanupKey] === 'function') {
    nav[cleanupKey]();
  }

  const toggleLabel = nav.querySelector('#toggleLabel[for]');
  const toggle = toggleLabel?.control;

  const closeOnNavigation = (event) => {
    const target = event.target instanceof Element
      ? event.target
      : event.target?.parentElement;
    const link = target?.closest('.megamenu a[href]');

    if (!link || !nav.contains(link)) return;
    if (toggle instanceof HTMLInputElement && toggle.type === 'checkbox') {
      toggle.checked = false;
    }
  };

  nav.addEventListener('click', closeOnNavigation);

  // Activa clase cuando no estamos en el tope de la página.
  const scrollTrigger = ScrollTrigger.create({
    start: 'top -1',
    end: 999999,
    onEnter: () => nav.classList.add('is-scrolled'),
    onLeaveBack: () => nav.classList.remove('is-scrolled'),
  });

  const cleanup = () => {
    nav.removeEventListener('click', closeOnNavigation);
    scrollTrigger.kill();
    if (nav[cleanupKey] === cleanup) {
      delete nav[cleanupKey];
    }
  };

  nav[cleanupKey] = cleanup;

  return cleanup;
}
