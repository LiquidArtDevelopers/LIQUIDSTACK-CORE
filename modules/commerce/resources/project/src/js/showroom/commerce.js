import '../../scss/showroom/commerce.scss';
import {
  initCommerce,
} from '../resources/_commerce.js';
import {
  initSectionCommerceSlider01,
} from '../resources/_sectionCommerceSlider01.js';

const cleanupCommerce = initCommerce(document);
const cleanupCommerceSlider01 = initSectionCommerceSlider01(document);

if (import.meta.hot) {
  import.meta.hot.dispose(() => {
    cleanupCommerceSlider01();
    cleanupCommerce();
  });
}

export default function initShowroomCommerce() {}
