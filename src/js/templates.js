import '../scss/templates.scss';
import "./_global.js";
import initArtSlider01 from './resources/_artSlider01.js';
import initArtSlider02 from './resources/_artSlider02.js';
import initArtZipper from './resources/_artZipper.js';

// parallax 
import ScrollTrigger from 'gsap/ScrollTrigger';
import gsapParallax from "./resources/_gsapParallaxScroll.js";

import initSectTabs01 from './resources/_sectTabs01.js';
import initStatsCounter from './resources/_art11.js';

import initGlobalForm from './resources/_globalForm.js';
import initArtAccordion01 from "./resources/_artAccordion01.js";

const doc = document
doc.addEventListener('DOMContentLoaded',()=>{

    initStatsCounter()
    initSectTabs01()
    initGlobalForm()
    initArtAccordion01()
    initArtSlider01()
    initArtSlider02()
    initArtZipper()

    // GSAP PARALLAX SCROLL--    
    /* ── función que cambia la imagen según ancho ────────────────── */
    function swapBG(){
        const w = innerWidth;
        document.querySelectorAll(".bg[data-bg-mobile]").forEach(el=>{
            const url =
            w < 800  ? el.dataset.bgMobile  :
            w < 1400 ? el.dataset.bgTablet  :
                        el.dataset.bgDesktop;
            el.style.setProperty("background-image", `url(${url})`, "important");
        });
    }                               

    /* --- debounce con delayedCall ----------------------------------- */
    let dc;
    const swapAndRefresh = () => {
        swapBG();
        ScrollTrigger.refresh();   // recalcula tamaños y offsets
    };

    window.addEventListener("resize", () => {
        dc && dc.kill();
        dc = gsap.delayedCall(0.15, swapAndRefresh);
    });

    /* llamada inicial */
    swapAndRefresh();

    /* ── header parallax ────────────────────────────────────────────── */
    gsapParallax({
      container: ".hero00",
      bg: ".bg",
      moveDesktop: 20,
      moveMobile : 20,
      sizeMode   : "cover"
    });

    /* ── parallax bloques ──────────────────────────────────────────── */
    [".art07-parallax", ".art16-parallax"].forEach(selector => {
      gsapParallax({
        container: selector,
        bg: ".bg",
        moveDesktop: 30,
        moveMobile : 20,
        sizeMode   : "cover"
      });
    });

    /* ── art07 parallax grid ────────────────────────────────────────────── */
    gsapParallax({
      container: ".art07-matrix",
      sizeMode : "containHeight"   // o "containWidth" según tu ajuste final
    });
    // FIN GSAP PARALLAX SCROLL--    

});



