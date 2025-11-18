import gsap from 'gsap';
import {Draggable, InertiaPlugin} from 'gsap/all';

export default function initArtSlider01(){
  
  gsap.registerPlugin(Draggable, InertiaPlugin);

  /* ---------------- CONFIGURACIÓN ---------------- */
  const slideDelay    = 6;   // segundos de inactividad antes del autoplay
  const slideDuration = 2;   // segundos que tarda un slide en animarse
  const wrap          = true; // true = bucle infinito

  /* ---------------- REFERENCIAS DOM -------------- */
  const track       = document.querySelector(".artSlider01-track");
  const slides      = document.querySelectorAll(".artSlider01-card");
  const prevButton  = document.querySelector("#prev");
  const nextButton  = document.querySelector("#next");
  const progressWrap = gsap.utils.wrap(0, 1);


  const numSlides   = slides.length;

  /* -------------- POSICIONAMIENTO INICIAL -------- */
  gsap.set(slides, { xPercent: i => i * 100 });

  const wrapX = gsap.utils.wrap(-100, (numSlides - 1) * 100);

  /* -------------- TIMELINE INFINITO -------------- */
  const animation = gsap.to(slides, {
    xPercent: "+=" + (numSlides * 100),
    duration : 1,
    ease     : "none",
    paused   : true,
    repeat   : -1,
    modifiers: { xPercent: wrapX },
  });

  /* -------------- DRAGGABLE PROXY ---------------- */
  const proxy      = document.createElement("div");
  let   slideAnimation = gsap.to({}, {});   // se reasigna en animateSlides()
  let   slideWidth     = 0;                 // px de una tarjeta
  let   wrapWidth      = 0;                 // loop completo en px

  const draggable = new Draggable(proxy, {
    trigger       : track,
    inertia       : true,
    type          : "x",
    onPress       : updateDraggable,
    onDrag        : updateProgress,
    onThrowUpdate : updateProgress,
    snap          : { x: snapX }
  });

  /* -------------- AUTOPLAY ----------------------- */
  const timer = gsap.delayedCall(slideDelay, autoPlay);

  /* -------------- LISTENERS ---------------------- */
  prevButton.addEventListener("click", () => animateSlides( 1));
  nextButton.addEventListener("click", () => animateSlides(-1));
  window.addEventListener("resize", resize);
  window.addEventListener("load",   resize); // asegura altura tras cargar img

  /* -------------- INIT --------------------------- */
  resize();

  /* ============= FUNCIONES INTERNAS ============== */
  function autoPlay () {
    if (draggable.isPressed || draggable.isDragging || draggable.isThrowing) {
      timer.restart(true);
    } else {
      animateSlides(-1);
    }
  }

  function animateSlides (direction) {
    timer.restart(true);
    slideAnimation.kill();

    const x = snapX(gsap.getProperty(proxy, "x") + direction * slideWidth);

    slideAnimation = gsap.to(proxy, {
      x,
      duration : slideDuration,
      onUpdate : updateProgress
    });
  }

  function updateDraggable () {
    timer.restart(true);
    slideAnimation.kill();
    this.update();
  }

  function updateProgress () {
    const progress = (gsap.getProperty(proxy, "x") / wrapWidth) || 0;
    animation.progress(progressWrap(progress));
  }

  function snapX (value) {
    const snapped = gsap.utils.snap(slideWidth, value);
    return wrap ? snapped : gsap.utils.clamp(-slideWidth * (numSlides - 1), 0, snapped);
  }

  function resize () {
    /* 1. Altura automática del track para que no colapse */
    const slideHeight = slides[0].offsetHeight; // incluye imagen + textos
    track.style.height = `${slideHeight}px`;

    /* 2. Recalcular medidas físicas */
    const norm = (gsap.getProperty(proxy, "x") / wrapWidth) || 0; // progreso 0–1
    slideWidth = slides[0].offsetWidth;
    wrapWidth  = slideWidth * numSlides;

    if (!wrap) {
      draggable.applyBounds({ minX: -slideWidth * (numSlides - 1), maxX: 0 });
      // console.log("dasd")
    }

    gsap.set(proxy, { x: norm * wrapWidth }); // reubica proxy al mismo progreso

    /* 3. Sincronizar timeline */
    animateSlides(0);      // nudge
    slideAnimation.progress(1);
  }

}
