import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

export default function initNavMegamenu01() {
  const nav = document.querySelector('nav');
  if (!nav) return;

  // Activa clase cuando no estamos en el tope de la página
  ScrollTrigger.create({
    start: 'top -1',
    end: 999999,
    onEnter: () => nav.classList.add('is-scrolled'),
    onLeaveBack: () => nav.classList.remove('is-scrolled'),
  });
}

