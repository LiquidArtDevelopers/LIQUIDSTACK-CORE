import '../scss/commerce.scss';
import './_global.js';
import { initCommerce } from './resources/_commerce.js';
import {
  initSectionCommerceSlider01,
} from './resources/_sectionCommerceSlider01.js';
import { bindLanguageNavigation } from './resources/_languagePreference.mjs';

const unbindLanguageNavigation = bindLanguageNavigation(window, document);
const cleanupCommerce = initCommerce(document);
const cleanupCommerceSlider01 = initSectionCommerceSlider01(document);

if (import.meta.hot) {
  import.meta.hot.dispose(() => {
    cleanupCommerceSlider01();
    cleanupCommerce();
    unbindLanguageNavigation();
  });
}
