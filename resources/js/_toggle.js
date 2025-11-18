// Eventos de toggle/megamenu y cierre automático
const contenido = document.getElementsByClassName('contenido');
const toggle = document.querySelector("input[name=toggle]");
const megamenu = document.querySelector('.megamenu');
const navMenuBar = document.querySelector('nav > div');

// Cerrar al hacer click en items de contenido
for (const item of contenido) {
  item.addEventListener('click', () => {
    if (toggle) {
      toggle.checked = false;
    }
  });
}

// Click en la barra del nav: abrir/cerrar, excepto en toggle label e idiomas
if (navMenuBar && toggle) {
  navMenuBar.addEventListener('click', (event) => {
    const targetElement = event.target instanceof Element ? event.target : (event.target && event.target.parentElement);
    if (!targetElement) return;

    // No interferir con el propio toggle (label)
    if (targetElement.closest('#toggleLabel')) return;

    // No abrir/cerrar cuando se hace clic en el área de idiomas
    if (targetElement.closest('.idiomas')) return;

    // Alternar estado: abre si está cerrado, cierra si está abierto
    toggle.checked = !toggle.checked;
  });
}

// Cerrar al clickar fuera cuando está abierto
if (toggle && megamenu) {
  document.addEventListener('click', (event) => {
    if (!toggle.checked) return;

    const targetElement = event.target instanceof Element ? event.target : (event.target && event.target.parentElement);
    if (!targetElement) {
      toggle.checked = false;
      return;
    }

    if (targetElement.closest('#toggleLabel')) return;
    if (targetElement === toggle) return;
    if (navMenuBar && navMenuBar.contains(targetElement)) return;
    if (megamenu.contains(targetElement)) return;

    toggle.checked = false;
  });
}
