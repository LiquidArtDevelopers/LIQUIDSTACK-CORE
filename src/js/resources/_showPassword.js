// Módulos para aquellos formularios que tengan campos de contraseña: registro, acceso o cambio de contraseña

// Módulo de Control en los INPUT tipo password para mostrar o no la contraseña
const showPassword = document.getElementsByClassName("showPassword");
for (const sp of showPassword) {
  sp.addEventListener("click", function () {
    const container = sp.parentNode;
    const controlViewPass = container.getElementsByClassName("controlViewPass");

    for (const cp of controlViewPass) {
      if (cp.type === "password") {
        // console.log("dsad");
        sp.src = "/assets/img/system/showPassword.svg";
        cp.setAttribute("type", "text");
      } else {
        sp.src = "/assets/img/system/hidePassword.svg";
        cp.setAttribute("type", "password");
      }
    }
  });
}