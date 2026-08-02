import '../scss/templates.scss';
import './_global.js';
import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';
import {
  installShowroomLanguageLinks,
} from './showroom/catalog-routing.mjs';

gsap.registerPlugin(ScrollTrigger);

const cleanupShowroomLanguageLinks = installShowroomLanguageLinks();
const installShowroomNavPin = () => {
  const catalog = document.querySelector('.showroomCatalog');
  const nav = document.querySelector('.showroomCatalog-nav');

  if (!(catalog instanceof HTMLElement) || !(nav instanceof HTMLElement)) {
    return () => {};
  }

  // ScrollSmoother usa scroll nativo en dispositivos táctiles; ahí el
  // sticky CSS es más fiable y evita duplicar la compensación del pin.
  if (ScrollTrigger.isTouch) {
    return () => {};
  }

  catalog.classList.add('showroomCatalog-hasPin');
  const navOffset = () => Math.round(window.innerHeight * 0.06);
  const pin = ScrollTrigger.create({
    trigger: nav,
    start: () => `top ${navOffset()}`,
    endTrigger: catalog,
    end: () => `bottom ${navOffset() + nav.offsetHeight}`,
    pin: true,
    pinSpacing: false,
    anticipatePin: 1,
    invalidateOnRefresh: true,
  });

  return () => {
    pin.kill();
    catalog.classList.remove('showroomCatalog-hasPin');
  };
};
const cleanupShowroomNavPin = installShowroomNavPin();

const categoryLoaders = {
  heroes: () => import('./showroom/heroes.js'),
  particles: () => import('./showroom/particles.js'),
  'gsap-specials': () => import('./showroom/gsap-specials.js'),
  common: () => import('./showroom/common.js'),
  'cards-grids': () => import('./showroom/cards-grids.js'),
  media: () => import('./showroom/media.js'),
  'forms-interactive': () => import('./showroom/forms-interactive.js'),
  'modules-sections': () => import('./showroom/modules-sections.js'),
  blog: () => import('./showroom/blog.js'),
};

// Extensión reservada para BASE y consumidores. Un fichero local como
// src/js/showroom/local/particles.js puede añadir su init y su SCSS sin
// modificar este entrypoint gestionado por CORE.
const localCategoryLoaders = import.meta.glob('./showroom/local/*.js');

const runModule = async (loader) => {
  if (typeof loader !== 'function') {
    return;
  }

  const loadedModule = await loader();
  if (typeof loadedModule.default === 'function') {
    await loadedModule.default();
  }
};

const initRequestedCategory = async () => {
  const category = document.body?.dataset.showroomCategory ?? 'index';

  try {
    await runModule(categoryLoaders[category]);

    const localLoader =
      localCategoryLoaders[`./showroom/local/${category}.js`];
    await runModule(localLoader);
  } catch (error) {
    console.error(
      `[showroom] No se pudo inicializar la categoría "${category}".`,
      error,
    );
  }
};

let domReadyHandler = null;
if (document.readyState === 'loading') {
  domReadyHandler = () => {
    domReadyHandler = null;
    void initRequestedCategory();
  };
  document.addEventListener('DOMContentLoaded', domReadyHandler, {
    once: true,
  });
} else {
  void initRequestedCategory();
}

if (import.meta.hot) {
  import.meta.hot.dispose(() => {
    cleanupShowroomLanguageLinks();
    cleanupShowroomNavPin();
    if (domReadyHandler) {
      document.removeEventListener('DOMContentLoaded', domReadyHandler);
      domReadyHandler = null;
    }
  });
}
