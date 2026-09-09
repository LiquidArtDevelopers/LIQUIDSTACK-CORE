import '../scss/blogArticle.scss';
import './_global.js';
import { bindLanguageNavigation } from './resources/_languagePreference.mjs';

const unbindLanguageNavigation = bindLanguageNavigation(window, document);

if (import.meta.hot) {
  import.meta.hot.dispose(unbindLanguageNavigation);
}
