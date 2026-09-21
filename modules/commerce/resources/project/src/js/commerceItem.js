import '../scss/commerceItem.scss';
import './_global.js';
import { initCommerce } from './resources/_commerce.js';
import { bindLanguageNavigation } from './resources/_languagePreference.mjs';

const unbindLanguageNavigation = bindLanguageNavigation(window, document);
const cleanupCommerce = initCommerce(document);

if (import.meta.hot) {
  import.meta.hot.dispose(() => {
    cleanupCommerce();
    unbindLanguageNavigation();
  });
}
