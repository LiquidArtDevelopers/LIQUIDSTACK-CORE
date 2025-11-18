let toastContain;

/**
 * Muestra un mensaje de notificación.
 * @param {string} message - El mensaje de texto que se mostrará.
 * @param {'success' | 'error' | 'info'} addClass - La clase que indica el tipo de notificación ('success' o 'error').
 * @param {number} FADE_DUR - La duración del efecto de desvanecimiento en milisegundos.
 * @param {number} MIN_DUR - La duración mínima en milisegundos antes de que se oculte el mensaje.
 */
export function Toast(
  message = "",
  addClass = "info",
  FADE_DUR = 700,
  MIN_DUR = 2000
) {
  // Calcula la duración basada en la longitud del mensaje
  const duration = Math.max(MIN_DUR, message.length * 80);

  // Crea el contenedor de toast si no existe
  if (!toastContain) {
    toastContain = document.createElement("div");
    toastContain.classList.add("toastContain");
    document.body.appendChild(toastContain);
  }

  // Crea el elemento de toast
  const EL = document.createElement("div");
  EL.classList.add("toast", addClass);
  EL.innerText = message;
  toastContain.prepend(EL); //Añade al principio

  // Aplica el efecto de apertura y desvanecimiento
  setTimeout(() => EL.classList.add("open"));
  setTimeout(() => EL.classList.remove("open"), duration);
  setTimeout(() => toastContain.removeChild(EL), duration + FADE_DUR);
}
