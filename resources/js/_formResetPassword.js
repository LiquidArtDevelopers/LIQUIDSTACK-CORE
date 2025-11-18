import "./_showPassword.js";

// Módulo para validar el Checker cuando se crea una nueva password.
const passwordId = document.getElementById("passwordId");
passwordId.addEventListener("keyup", validaciones);

// Módulo para validar que la contraseña repetida es igual
const repeatPasswordId = document.getElementById("repeatPasswordId");
repeatPasswordId.addEventListener("keyup", validaciones);

// Módulo para validar con backend el formulario XMLHTTP Request
const contenedor_formulario = document.getElementById("contenedor_formulario");
const formulario = document.getElementById("formulario");
const boton_formulario = document.getElementById("boton_formulario");
const enviado = document.getElementById("enviado");
const loaderPrimary = document.getElementById("loaderPrimary");
const t = new URLSearchParams(location.search).get("t"); //obtenemos el token de la url
// Evento submit del form
formulario.addEventListener("submit", function (evento) {
  evento.preventDefault();

  const camposFormulario = new FormData(document.forms.namedItem("formulario"));
  //Aquí es donde añadimos un campo más al objeto tipo formData.
  const xmlhttp = new XMLHttpRequest();
  xmlhttp.onload = function () {
    switch (true) {
      case xmlhttp.status >= 200 && xmlhttp.status < 300:
        loaderPrimary.style.display = "none";
        contenedor_formulario.style.display = "none";
        enviado.style.display = "inherit";
        break;
      case xmlhttp.status >= 400 && xmlhttp.status < 500:
        var jsonRecibido = xmlhttp.responseText;
        var ArrayJson = JSON.parse(jsonRecibido);
        var mensaje = ArrayJson.mensaje;
        var campo = ArrayJson.campo;
        var campo_error = document.getElementById(campo);
        campo_error.dataset.lang = ArrayJson.data_lang;
        campo_error.innerHTML = mensaje;
        loaderPrimary.style.display = "none";
        boton_formulario.style.pointerEvents = "inherit";
        boton_formulario.style.opacity = 1;
        break;
      case xmlhttp.status >= 500 && xmlhttp.status < 600:
        var campo_error = document.getElementById("new_password_error");
        campo_error.innerHTML = "Server Error";
        loaderPrimary.style.display = "none";
        boton_formulario.style.pointerEvents = "inherit";
        boton_formulario.style.opacity = 1;
        break;
    }
  };
  /* Control de error cuando no hay conexión */
  xmlhttp.onerror = function () {
    var campo_error = document.getElementById("new_password_error");
    campo_error.innerHTML = "Error de conexión";
    loaderPrimary.style.display = "none";
    boton_formulario.style.pointerEvents = "inherit";
    boton_formulario.style.opacity = 1;
  };

  //Ruta POST /form para este formulario, se verifica en el controlador del index
  xmlhttp.open("POST", "/form-reset-password", true);
  camposFormulario.append("t", t); // agregamos el token como query de la petición en el form
  xmlhttp.send(camposFormulario);
  limpiar_errores();

  loaderPrimary.style.display = "flex";
  boton_formulario.style.pointerEvents = "none";
  boton_formulario.style.opacity = 0.2;
});

//Función limpiar errores del formulario con clase error
function limpiar_errores() {
  const campos = document.getElementsByClassName("error");
  for (const campo of campos) {
    campo.innerHTML = "";
  }
}

// Función índice de validaciones
function validaciones() {
  if (validarCheckPass() && validarDoblePass()) {
    activarBoton(true);
  } else {
    activarBoton(false);
  }
}

//Función para validar el check
function validarCheckPass() {
  // Comprobamos el valor en la función de expresiones regulares
  let fallos = checkPassword(passwordId.value);
  let validar = false;
  //Si no hay fallos check OK en el input de nueva pass
  const completePasswordCheck = document.getElementsByClassName(
    "completePasswordCheck"
  );
  for (const e of completePasswordCheck) {
    if (fallos == false) {
      e.src = "/assets/img/system/check-OK.svg";
      validar = true;
    } else {
      e.src = "/assets/img/system/check-ERROR.svg";
    }
  }
  return validar;
}

//Función para validar el doble password
function validarDoblePass() {
  let validar = false;
  //Si no hay fallos check OK en el input de nueva pass
  const doublePasswordCheck = document.getElementsByClassName(
    "doublePasswordCheck"
  );
  for (const e of doublePasswordCheck) {
    if (
      passwordId.value == repeatPasswordId.value &&
      !repeatPasswordId.value == ""
    ) {
      e.src = "/assets/img/system/check-OK.svg";
      validar = true;
    } else {
      e.src = "/assets/img/system/check-ERROR.svg";
    }
  }
  return validar;
}

//Función para comprobar check con expresiones regulares
function checkPassword(v) {
  // Devolveremos fallo true si en alguna se falla
  let fallo = false;

  // Comprobar si tiene mínimo 8 caracteres
  const checkCount = document.getElementsByClassName("checkCount");
  for (const e of checkCount) {
    const img = e.children[0];
    const span = e.children[1];

    const regex = new RegExp(".{8,}");
    if (!regex.test(v)) {
      img.src = "/assets/img/system/check-ERROR.svg";
      span.classList.remove("checkOK");
      fallo = true;
    } else {
      img.src = "/assets/img/system/check-OK.svg";
      span.classList.add("checkOK");
    }
  }

  // Comprobar si tiene letra mayúscula y minúscula
  const checkCapital = document.getElementsByClassName("checkCapital");
  for (const e of checkCapital) {
    const img = e.children[0];
    const span = e.children[1];

    const regex = new RegExp("(?=.*?[A-Z])(?=.*?[a-z])");
    if (!regex.test(v)) {
      img.src = "/assets/img/system/check-ERROR.svg";
      span.classList.remove("checkOK");
      fallo = true;
    } else {
      img.src = "/assets/img/system/check-OK.svg";
      span.classList.add("checkOK");
    }
  }

  // Comprobar si tiene algún carácter especial
  const checkSpecial = document.getElementsByClassName("checkSpecial");
  for (const e of checkSpecial) {
    const img = e.children[0];
    const span = e.children[1];

    const regex = new RegExp("(?=.*[\\W_])");
    if (!regex.test(v)) {
      img.src = "/assets/img/system/check-ERROR.svg";
      span.classList.remove("checkOK");
      fallo = true;
    } else {
      img.src = "/assets/img/system/check-OK.svg";
      span.classList.add("checkOK");
    }
  }

  // Comprobar si tiene algún número
  const checkNum = document.getElementsByClassName("checkNum");
  for (const e of checkNum) {
    const img = e.children[0];
    const span = e.children[1];

    const regex = new RegExp("(?=.*\\d)");
    if (!regex.test(v)) {
      img.src = "/assets/img/system/check-ERROR.svg";
      span.classList.remove("checkOK");
      fallo = true;
    } else {
      img.src = "/assets/img/system/check-OK.svg";
      span.classList.add("checkOK");
    }
  }

  // Sin espacios
  const checkSpace = document.getElementsByClassName("checkSpace");
  for (const e of checkSpace) {
    const img = e.children[0];
    const span = e.children[1];

    // const regex = /^\S+$/
    const regex = new RegExp("^\\S+$");
    if (!regex.test(v)) {
      img.src = "/assets/img/system/check-ERROR.svg";
      span.classList.remove("checkOK");
      fallo = true;
    } else {
      img.src = "/assets/img/system/check-OK.svg";
      span.classList.add("checkOK");
    }
  }

  return fallo;
}

//Gestion del botón de enviar form
function activarBoton(v) {
  if (v) {
    boton_formulario.style.opacity = "1";
    boton_formulario.style.pointerEvents = "initial";
  } else {
    boton_formulario.style.opacity = ".2";
    boton_formulario.style.pointerEvents = "none";
  }
}
