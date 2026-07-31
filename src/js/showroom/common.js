import '../../scss/showroom/common.scss';
import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';
import gsapParallax from '../resources/_gsapParallaxScroll.js';

let resizeHandler = null;
let resizeDelay = null;

export default function initShowroomCommon() {
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

  ['.art07-parallax', '.art16-parallax'].forEach((container) => {
    gsapParallax({
      container,
      bg: '.bg',
      moveDesktop: 30,
      moveMobile: 20,
      sizeMode: 'cover',
    });
  });

  gsapParallax({
    container: '.art07-matrix',
    sizeMode: 'containHeight',
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
