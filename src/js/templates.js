import '../scss/templates.scss';
import "./_global.js";
import initArtSlider01 from './resources/_artSlider01.js';
import initArtSlider02 from './resources/_artSlider02.js';
import initArtZipper from './resources/_artZipper.js';
import initArt18 from './resources/_art18.js'
import initArt19 from './resources/_art19.js'
import initArtPricingGlass01 from './resources/_artPricingGlass01.js'
import initArtScatter01 from './resources/_artScatter01.js'
import initArtMarquee01 from './resources/_artMarquee01.js'
import initArtScale01 from './resources/_artScale01.js'
import initAniBackground01 from './resources/_aniBackground01.js'
import initSectionParallax01 from './resources/_sectionParallax01.js'
import initSectionParticles01 from './resources/_sectionParticles01.js'
import initSectionDiskSlider01 from './resources/_sectionDiskSlider01.js'
import initSectionSkewGallery01 from './resources/_sectionSkewGallery01.js'
import initSectionHScroll01 from './resources/_sectionHScroll01.js'
import initArtWorksSkew01 from './resources/_artWorksSkew01.js'
import initArtHeroScroll01 from './resources/_artHeroScroll01.js'
import initHero03 from './resources/_hero03.js'
import initHero04 from './resources/_hero04.js'
import initHero05 from './resources/_hero05.js'

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
    initArt18()
    initArt19()
    initArtPricingGlass01()
    initArtScatter01()
    initArtMarquee01()
    initArtScale01()
    initAniBackground01()
    initSectionParallax01()
    initSectionParticles01()
    initSectionDiskSlider01()
    initSectionSkewGallery01()
    initSectionHScroll01()
    initArtWorksSkew01()
    initArtHeroScroll01()
    initHero03()
    initHero04()
    initHero05()

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
