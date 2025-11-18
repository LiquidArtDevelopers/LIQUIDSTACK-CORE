import gsap from 'gsap';
import "./_createCursorRippleEffect.js";


// Crear el cursor
const cursor = document.createElement("div");
cursor.classList = "cursor";
document.body.prepend(cursor);

// Variables para rastrear la posición del mouse
let mouseX = 0;
let mouseY = 0;
let y = 0;

// Función para actualizar la posición del cursor
const updateCursorPosition = (e) => {
  mouseX = e.clientX;
  mouseY = e.clientY;

  // Usar left y top con compensación para centrar el cursor
  gsap.to(cursor, {
    left: mouseX - cursor.offsetWidth / 2,
    top: mouseY - cursor.offsetHeight / 2,
  });
};

// Actualizar la posición del cursor al mover el mouse
window.addEventListener("mousemove", updateCursorPosition);





// Función para manejar el efecto de escala cuando está en un elemento interactivo
const handleMouseEnter = (event,element) => {
  if (element.classList.contains("small")) {
    cursor.classList.add("grow-small");
  } else {
    cursor.classList.add("grow");
  }
  // animación adicional cuando se está en enlace para hacer un parpadeo
  rippleEffectWithLight({event,mouseX,mouseY})
};

const handleMouseLeave = () => {
  // Eliminar las clases de escala
  cursor.classList.remove("grow", "grow-small");
};

// Agregar eventos a los elementos interactivos
const cursorScale = document.querySelectorAll("a, .boton, #toggleLabel, .icono_cookie, .cookie_close, .legal");
cursorScale.forEach((element) => {
  element.addEventListener("mouseenter", (e) => handleMouseEnter(e,element));
  element.addEventListener("mouseleave", handleMouseLeave);
});



const rippleEffectWithLight = ({mouseX,mouseY}) => {
  // Crear un div para el ripple
  const y = window.scrollY; //corrección para que aparezca bien en el eje Y (creemos que por el smoother al hacer scroll la posición en Y no se cambia)
  const ripple = document.createElement("div");
  ripple.classList.add("rippleCursor");
  ripple.style.position = "absolute";
  ripple.style.width = "100px";
  ripple.style.height = "100px";
  ripple.style.borderRadius = "50%";
  ripple.style.background = "rgba(255, 255, 255, 0.3)";
  ripple.style.pointerEvents = "none";
  ripple.style.zIndex = "99999999999";
  ripple.style.transform = "translate(-50%, -50%) scale(0)";
  ripple.style.left = `${mouseX}px`;
  ripple.style.top = `${mouseY+y}px`;

  document.body.appendChild(ripple);

  // Animar la expansión del ripple
  gsap.to(ripple, {
    scale: 2,
    opacity: 0,
    duration: 0.6,
    ease: "power2.out",
    onComplete: () => {
      ripple.remove(); // Eliminar el ripple después de la animación
    },
  });
};





