import traduccionClass from "../resources/js/_traducciones.js";
import initDownloadFiles from '../resources/js/_downloadFiles.js';
import initNavMegamenu01 from '../resources/js/_navMegamenu01.js';

import "../resources/js/_toggle.js";
import "../resources/js/_terminos.js";

import "../resources/js/_gsapScroll.js";
import "../resources/js/_createCursorRippleEffect.js";
import "../resources/js/_createCursorTraking.js";
import initInlineEditor from "../resources/js/_inlineEditor.js";




const d = document;
d.addEventListener("DOMContentLoaded", () => {
  const traduccion = traduccionClass.getInstance();
  traduccion.colorearIdioma();
  initDownloadFiles()
  initNavMegamenu01()
  initInlineEditor();
});
