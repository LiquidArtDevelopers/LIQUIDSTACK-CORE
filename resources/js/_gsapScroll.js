import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';
import ScrollSmoother from 'gsap/ScrollSmoother';

gsap.registerPlugin(ScrollTrigger, ScrollSmoother);

// Impide el salto nativo antes de que ScrollSmoother controle la posición.
// El hash se restaura cuando la página termina de cargar.
const initialHash = window.location.hash;

if (initialHash) {
  window.history.replaceState(
    window.history.state,
    "",
    `${window.location.pathname}${window.location.search}`,
  );
}

// 1. Inicialización de ScrollSmoother
const smoother = ScrollSmoother.create({
  // smooth: 0.8,
  // speed: 0,
  // effects: true
  wrapper: "#smooth-wrapper",
  content: "#smooth-content",
  smooth: 2,
  effects: true,
});

/**
 * Evita que el navegador y ScrollSmoother suavicen el mismo scroll a la vez.
 */
function disableNativeSmoothScroll() {
  const scrollingElements = [
    document.documentElement,
    document.body,
    smoother.wrapper(),
  ];

  scrollingElements.forEach((element) => {
    element.style.setProperty("scroll-behavior", "auto", "important");
  });
}

const reducedMotionQuery = window.matchMedia("(prefers-reduced-motion: reduce)");

/**
 * Busca el elemento indicado por un hash, incluido un identificador codificado.
 * Si el hash no es válido, devuelve null.
 */
function getHashTarget(hash) {
  if (!hash || hash === "#") {
    return null;
  }

  const rawId = hash.slice(1);
  let decodedId = rawId;

  try {
    decodedId = decodeURIComponent(rawId);
  } catch {
    //Si no encontramos hash solo atrapamos la excepción
  }

  return document.getElementById(decodedId) ?? document.getElementById(rawId);
}

/** Devuelve el hash únicamente cuando el enlace pertenece a esta misma página. */
function getSamePageHash(link) {
  const destination = new URL(link.href, window.location.href);
  const isSamePage =
    destination.origin === window.location.origin
    && destination.pathname === window.location.pathname
    && destination.search === window.location.search;

  return isSamePage ? destination.hash : null;
}

/** Indica si el usuario pretende abrir el enlace de otra forma. */
function isModifiedClick(event) {
  return event.button !== 0
    || event.metaKey
    || event.ctrlKey
    || event.shiftKey
    || event.altKey;
}

/** Manejador unificado para los clics en los enlaces/botones internos con '#' */
function handleAnchorClick(event) {
  if (event.defaultPrevented || isModifiedClick(event)) {
    return;
  }

  const link = event.target.closest?.('a[href*="#"]');

  // Las descargas y las nuevas pestañas conservan su comportamiento nativo.
  if (
    !link
    || link.hasAttribute("download")
    || (link.target && link.target !== "_self")
  ) {
    return;
  }

  const hash = getSamePageHash(link);
  const target = getHashTarget(hash);

  if (!target) {
    return;
  }

  event.preventDefault();

  // Actualiza la URL sin provocar un desplazamiento nativo.
  if (window.location.hash !== hash) {
    window.history.pushState(null, "", hash);
  }

  const useSmoothScroll = !reducedMotionQuery.matches;

  if (smoother) {
    disableNativeSmoothScroll();
    smoother.scrollTo(target, useSmoothScroll, "top top");
  }
}

/** Coloca una sola vez la página en el hash recibido al abrir la URL. */
function handleInitialHashLoad() {
  if (!initialHash) {
    return;
  }

  const target = getHashTarget(initialHash);

  // replaceState recupera la URL sin iniciar otro desplazamiento nativo.
  window.history.replaceState(
    window.history.state,
    "",
    `${window.location.pathname}${window.location.search}${initialHash}`,
  );

  if (!target || !smoother) {
    return;
  }

  disableNativeSmoothScroll();
  ScrollTrigger.refresh();

  // Si el navegador ya dejó visible el destino, no repetimos el movimiento.
  if (Math.abs(target.getBoundingClientRect().top) <= 1) {
    return;
  }

  // Deja el suavizado únicamente en manos de ScrollSmoother.
  smoother.scrollTo(target, !reducedMotionQuery.matches, "top top");
}

// Event listeners centralizados
document.addEventListener("click", handleAnchorClick);

// Espera a que el layout tenga sus medidas definitivas.
window.addEventListener("load", handleInitialHashLoad, { once: true });

// Maneja cambios posteriores en el hash mediante la barra de direcciones o historial
window.addEventListener("hashchange", () => {
  const target = getHashTarget(window.location.hash);
  if (target && smoother) {
    disableNativeSmoothScroll();
    const useSmoothScroll = !reducedMotionQuery.matches;
    smoother.scrollTo(target, useSmoothScroll, "top top");
  }
});
