import '../../scss/showroom/gsap-specials.scss';
import initArtHeroScroll01 from '../resources/_artHeroScroll01.js';
import initArtMarquee01 from '../resources/_artMarquee01.js';
import initArtPricingGlass01 from '../resources/_artPricingGlass01.js';
import initArtScale01 from '../resources/_artScale01.js';
import initArtScatter01 from '../resources/_artScatter01.js';
import initArtWorksSkew01 from '../resources/_artWorksSkew01.js';
import initArtZipper from '../resources/_artZipper.js';
import initSectionDiskSlider01 from '../resources/_sectionDiskSlider01.js';
import initSectionHScroll01 from '../resources/_sectionHScroll01.js';
import initSectionParallax01 from '../resources/_sectionParallax01.js';
import initSectionSkewGallery01 from '../resources/_sectionSkewGallery01.js';

export default function initShowroomGsapSpecials() {
  initArtScatter01();
  initArtMarquee01();
  initArtScale01();
  initSectionParallax01();
  initSectionDiskSlider01();
  initSectionSkewGallery01();
  initArtWorksSkew01();
  initSectionHScroll01();
  initArtHeroScroll01();
  initArtPricingGlass01();
  initArtZipper();
}
