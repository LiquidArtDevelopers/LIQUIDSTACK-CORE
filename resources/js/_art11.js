import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';

/* statsCounter.js — cuenta ascendente con reinicio al volver al viewport
   Requiere GSAP v3 + ScrollTrigger (gsap.registerPlugin(ScrollTrigger)).
   Uso en HTML:
      <span class="stat-number" data-target="998" data-suffix="k+">0</span>
      <span class="stat-label">POSTS</span>
*/

export default function initStatsCounter () {
  const EASING      = 'power1.out'; // curva de aceleración
  const DURATION    = 1.5;          // segundos por animación
  const START_POINT = 'top 90%';    // posición de disparo

  gsap.registerPlugin(ScrollTrigger);

  const counters = document.querySelectorAll('.stat-number');
  if (!counters.length) return;

  counters.forEach(counter => {
    const target = parseFloat(counter.dataset.target);
    const suffix = counter.dataset.suffix || '';

    // ScrollTrigger autónomo por contador
    ScrollTrigger.create({
      trigger: counter.parentElement,
      start  : START_POINT,
      onEnter: () => animateCounter(counter, target, suffix),   // entrando desde arriba
      onEnterBack: () => animateCounter(counter, target, suffix), // volviendo desde abajo
      onLeave: () => resetCounter(counter, suffix),              // sale por abajo
      onLeaveBack: () => resetCounter(counter, suffix)           // sale por arriba
    });
  });

  /* ---------- helpers ---------- */
  function animateCounter (el, endValue, suffix) {
    // si ya hay un tween activo lo matamos para reiniciar limpio
    if (el._tween) el._tween.kill();

    const proxy = { val: 0 };
    el._tween = gsap.to(proxy, {
      val      : endValue,
      duration : DURATION,
      ease     : EASING,
      snap     : { val: 1 },
      onUpdate () {
        el.textContent = formatNumber(proxy.val) + suffix;
      }
    });
  }

  function resetCounter (el, suffix) {
    if (el._tween) el._tween.kill();
    el.textContent = '0' + suffix;
  }

  function formatNumber (value) {
    return Math.floor(value).toLocaleString('es-ES');
  }
}
