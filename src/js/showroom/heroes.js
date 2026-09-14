import '../../scss/showroom/heroes.scss';

// IMPORTS POR RECURSO
// Copia únicamente los imports de los héroes presentes en la vista.
import initHero03 from '../resources/_hero03.js';
import initHero04 from '../resources/_hero04.js';
import initHero05 from '../resources/_hero05.js';

// Requerido por el bloque copiable HERO00 · PARALLAX de más abajo.
import gsapParallax from '../resources/_gsapParallaxScroll.js';

// HERO00 · PARALLAX: conserva este cleanup si copias el bloque con HMR.
let parallaxCleanup = null;

export default function initShowroomHeroes() {
  parallaxCleanup?.();

  // INICIALIZACIONES SIN CONFIGURACIÓN
  initHero03();
  initHero04();
  initHero05();

  // =============================================================
  // HERO00 · PARALLAX — BLOQUE COPIABLE
  // =============================================================
  // 1. Copia el import de gsapParallax indicado arriba.
  // 2. Copia esta llamada en el JS que hidrata la vista.
  // 3. Ejecuta la función devuelta al desmontar/HMR.
  //
  // El <picture> de hero00 ya selecciona mobile/tablet/desktop: no necesita
  // un listener de resize ni cambiar fondos desde este entrypoint.
  //
  // moveDesktop / moveMobile: recorrido vertical en porcentaje.
  // sizeMode: 'cover', 'containHeight' o 'containWidth'.
  parallaxCleanup = gsapParallax({
    container: '.hero00',
    bg: '.hero00-media',
    moveDesktop: 20,
    moveMobile: 20,
    sizeMode: 'cover',
  });
  // FIN HERO00 · PARALLAX
}

if (import.meta.hot) {
  import.meta.hot.dispose(() => {
    // HERO00 · PARALLAX — LIMPIEZA HMR (parte del bloque copiable).
    parallaxCleanup?.();
    parallaxCleanup = null;
  });
}
