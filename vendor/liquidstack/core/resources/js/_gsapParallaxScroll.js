
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
  if (!roots.length) return;

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
    bgs.forEach(el => {
      const cRect = el.parentNode.getBoundingClientRect();

      el.style.width  = `${rRect.width}px`;
      el.style.height = `${imgH}px`;

      el.style.backgroundSize =
        sizeMode === "containHeight"
          ? `auto ${imgH}px`
        : sizeMode === "containWidth"
          ? `100% auto`
        : "cover";                         // default

      gsap.set(el, { x:rRect.left-cRect.left, y:rRect.top-cRect.top });
    });

    /* ── ScrollTrigger único ───────────────────────────────────────── */
    ScrollTrigger.create({
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

      bgs.forEach(el=>{
        const cRect = el.parentNode.getBoundingClientRect();

        el.style.width  = `${rRect.width}px`;
        el.style.height = `${imgH}px`;

        el.style.backgroundSize =
          sizeMode === "containHeight"
            ? `auto ${imgH}px`
          : sizeMode === "containWidth"
            ? `100% auto`
          : "cover";

        gsap.set(el,{ x:rRect.left-cRect.left, y:rRect.top-cRect.top });
      });
    };

    ScrollTrigger.addEventListener("refreshInit", updateSizes);
  });
}
