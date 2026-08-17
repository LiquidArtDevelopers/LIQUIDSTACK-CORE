
import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

/**
 * Parallax “ventana única” para cualquier contenedor.
 * @param {Object} opt
 * @param {string|Element|Element[]}  opt.container   selector o nodos del contenedor (trigger)
 * @param {string} [opt.card=".card-parallax"] selector de las “ventanas”
 * @param {string} [opt.bg=".bg"]  selector de la capa fondo dentro de cada card
 * @param {number} [opt.moveDesktop=50]  % a recorrer en desktop
 * @param {number} [opt.moveMobile=30]   % a recorrer en mobile
 */

export default function gsapParallax(opt){
  const {
    container,
    card = ".card-parallax",
    bg   = ".bg",
    moveDesktop = 50,
    moveMobile  = 30,
    sizeMode = "cover"            // "cover" | "containHeight" | "containWidth"
  } = opt;

  const roots = gsap.utils.toArray(container);
  const cleanups = [];
  if (!roots.length) return () => {};

  roots.forEach(root => {
    let cards = root.querySelectorAll(card);
    if (cards.length === 0) cards = [root];

    const bgs    = root.querySelectorAll(bg);
    if (!bgs.length) return;
    const isMob  = window.innerWidth < 768;
    const vh     = window.innerHeight;

    const rRect  = root.getBoundingClientRect();
    const imgH   = cards.length * cards[0].offsetHeight + vh;     // alto capa
    const travel = isMob ? moveMobile : moveDesktop;              // % a mover

    /* ── dimensiona y alinea ───────────────────────────────────────── */
    const sizeMedia = (el, rootRect, imageHeight) => {
      const cRect = el.parentNode.getBoundingClientRect();
      const isReplacedMedia = el.matches('img, video');

      el.style.width  = `${rootRect.width}px`;
      el.style.height = `${imageHeight}px`;

      if (isReplacedMedia) {
        el.style.objectFit = sizeMode === "containHeight"
          || sizeMode === "containWidth"
          ? "contain"
          : "cover";
      } else {
        el.style.backgroundSize =
        sizeMode === "containHeight"
          ? `auto ${imageHeight}px`
        : sizeMode === "containWidth"
          ? `100% auto`
          : "cover";
      }

      gsap.set(el, {
        x: rootRect.left - cRect.left,
        y: rootRect.top - cRect.top
      });
    };

    bgs.forEach(el => sizeMedia(el, rRect, imgH));

    /* ── ScrollTrigger único ───────────────────────────────────────── */
    const trigger = ScrollTrigger.create({
      trigger: root,
      start: "top bottom",
      end:   "bottom top",
      scrub: true,
      onUpdate: self => gsap.set(bgs, { yPercent: -travel * self.progress })
    });

    /* --- recalcula en cada ScrollTrigger.refresh() ------------------- */
    const updateSizes = ()=> {
      const rRect = root.getBoundingClientRect();
      const imgH  = cards.length * cards[0].offsetHeight + window.innerHeight;

      bgs.forEach(el => sizeMedia(el, rRect, imgH));
    };

    ScrollTrigger.addEventListener("refreshInit", updateSizes);
    cleanups.push(() => {
      ScrollTrigger.removeEventListener("refreshInit", updateSizes);
      trigger.kill();
      bgs.forEach(el => {
        gsap.set(el, {
          clearProps: "width,height,backgroundSize,objectFit,x,y,yPercent"
        });
      });
    });
  });

  return () => cleanups.forEach(cleanup => cleanup());
}
