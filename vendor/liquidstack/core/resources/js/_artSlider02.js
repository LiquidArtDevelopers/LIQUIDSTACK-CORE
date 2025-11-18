import gsap from 'gsap';
import { InertiaPlugin, Draggable } from 'gsap/all';

export default function initArtSlider02(){
  gsap.registerPlugin(Draggable, InertiaPlugin);

  /* ---------------- CONFIG ---------------- */
  const slideDelay    = 6;   // segundos de inactividad antes de autoplay
  const slideDuration = 2;   // duración de la animación entre slides (s)
  const wrap          = true; // true = carrusel infinito

  /* --------------- REFERENCIAS DOM -------- */
  const slides        = document.querySelectorAll('.slide');
  const prevButton    = document.querySelector('#prevButton');
  const nextButton    = document.querySelector('#nextButton');
  const progressWrap  = gsap.utils.wrap(0, 1);
  const numSlides     = slides.length;

  /* --------- ESTILOS INICIALES / POSICIÓN ------ */
  gsap.set(slides, {
    backgroundColor: '',          // quitamos color aleatorio
    xPercent       : i => i * 100 // colocamos cada slide absoluto a su sitio
  });

  /* --------------- TIMELINE INFINITO ---------- */
  const wrapX = gsap.utils.wrap(-100, (numSlides - 1) * 100);
  const timer = gsap.delayedCall(slideDelay, autoPlay);

  const animation = gsap.to(slides, {
    xPercent: `+=${numSlides * 100}`,
    duration : 1,
    ease     : 'none',
    paused   : true,
    repeat   : -1,
    modifiers: { xPercent: wrapX }
  });

  /* --------------- DRAGGABLE PROXY ------------ */
  const proxy  = document.createElement('div');
  let   slideAnimation = gsap.to({}, {}); // se reasigna cada vez
  let   slideWidth     = 0;               // px
  let   wrapWidth      = 0;               // px

  const draggable = new Draggable(proxy, {
    trigger       : '.slides-container',
    inertia       : true,
    type          : 'x',
    onPress       : updateDraggable,
    onDrag        : updateProgress,
    onThrowUpdate : updateProgress,
    snap          : { x: snapX },
    onThrowComplete: mostrarTitulos // callback personalizado
  });

  /* --------------- INIT & LISTENERS ---------- */
  resize();
  window.addEventListener('resize', resize);
  prevButton.addEventListener('click', () => animateSlides( 1));
  nextButton.addEventListener('click', () => animateSlides(-1));

  document.addEventListener('scroll', mostrarTitulos); // títulos en scroll

  /* ============= FUNCIONES PRINCIPALES ======== */
  function animateSlides (direction) {
    timer.restart(true);
    slideAnimation.kill();
    const x = snapX(gsap.getProperty(proxy, 'x') + direction * slideWidth);

    slideAnimation = gsap.to(proxy, {
      x,
      duration : slideDuration,
      onUpdate : updateProgress
    });
  }

  function autoPlay () {
    if (draggable.isPressed || draggable.isDragging || draggable.isThrowing) {
      timer.restart(true);
    } else {
      animateSlides(-1);
    }
  }

  function updateDraggable () {
    timer.restart(true);
    slideAnimation.kill();
    this.update();
  }

  function updateProgress () {
    const p = (gsap.getProperty(proxy, 'x') / wrapWidth) || 0;
    animation.progress(progressWrap(p));
    mostrarTitulos();
  }

  function snapX (value) {
    const snapped = gsap.utils.snap(slideWidth, value);
    return wrap ? snapped : gsap.utils.clamp(-slideWidth * (numSlides - 1), 0, snapped);
  }

  function resize () {
    const norm = (gsap.getProperty(proxy, 'x') / wrapWidth) || 0;
    slideWidth = slides[0].offsetWidth;
    wrapWidth  = slideWidth * numSlides;

    if (!wrap) {
      draggable.applyBounds({ minX: -slideWidth * (numSlides - 1), maxX: 0 });
    }

    gsap.set(proxy, { x: norm * wrapWidth });
    animateSlides(0);
    slideAnimation.progress(1);
  }

  /* ============= TITULOS EN VIEWPORT ========= */
  function isInViewport (el) {
    const rect = el.getBoundingClientRect();
    return (
      rect.top    >= 0 &&
      rect.left   >= 0 &&
      rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
      rect.right  <= (window.innerWidth  || document.documentElement.clientWidth)
    );
  }

  function mostrarTitulos () {
    const titulos = document.getElementsByClassName('titulo');
    for (const titulo of titulos) {
      titulo.classList.remove('efecto');
      if (isInViewport(titulo)) {
        titulo.classList.add('efecto');
      }
    }
  }

}


