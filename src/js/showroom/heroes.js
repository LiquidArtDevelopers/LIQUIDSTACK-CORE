import '../../scss/showroom/heroes.scss';
import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';
import initHero03 from '../resources/_hero03.js';
import initHero04 from '../resources/_hero04.js';
import initHero05 from '../resources/_hero05.js';
import gsapParallax from '../resources/_gsapParallaxScroll.js';

let resizeHandler = null;
let resizeDelay = null;

export default function initShowroomHeroes() {
  initHero03();
  initHero04();
  initHero05();

  const swapBackgrounds = () => {
    const width = window.innerWidth;
    document.querySelectorAll('.bg[data-bg-mobile]').forEach((element) => {
      const url =
        width < 800
          ? element.dataset.bgMobile
          : width < 1400
            ? element.dataset.bgTablet
            : element.dataset.bgDesktop;

      if (url) {
        element.style.setProperty('background-image', `url(${url})`, 'important');
      }
    });
  };

  const refresh = () => {
    swapBackgrounds();
    ScrollTrigger.refresh();
  };

  resizeHandler = () => {
    resizeDelay?.kill();
    resizeDelay = gsap.delayedCall(0.15, refresh);
  };
  window.addEventListener('resize', resizeHandler);

  refresh();
  gsapParallax({
    container: '.hero00',
    bg: '.bg',
    moveDesktop: 20,
    moveMobile: 20,
    sizeMode: 'cover',
  });
}

if (import.meta.hot) {
  import.meta.hot.dispose(() => {
    resizeDelay?.kill();
    if (resizeHandler) {
      window.removeEventListener('resize', resizeHandler);
    }
    resizeHandler = null;
    resizeDelay = null;
  });
}
