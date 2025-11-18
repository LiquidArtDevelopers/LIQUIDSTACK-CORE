//funciones asíncronas para comprobar si el campo es correcto (tipo email) y que proceda al envío de un correo para el cambio de pass

// Módulo para validar con backend el formulario XMLHTTP Request
const contenedor_formulario = document.getElementById("contenedor_formulario");
const formulario = document.getElementById("formulario");
const boton_formulario = document.getElementById("boton_formulario");
const enviado = document.getElementById("enviado");
const loaderPrimary = document.getElementById("loaderPrimary");
const inputCorreo = document.getElementById("correoId");
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
        var campo_error = document.getElementById("email_error");
        campo_error.innerHTML = "Error en el servidor";
        loaderPrimary.style.display = "none";
        boton_formulario.style.pointerEvents = "inherit";
        boton_formulario.style.opacity = 1;
        break;
    }
  };
  /* Control de error cuando no hay conexión */
  xmlhttp.onerror = function () {
    var campo_error = document.getElementById("email_error");
    campo_error.innerHTML = "Error de conexión";
    loaderPrimary.style.display = "none";
    boton_formulario.style.pointerEvents = "inherit";
    boton_formulario.style.opacity = 1;
  };

  //Ruta POST /form para este formulario, se verifica en el controlador del index
  xmlhttp.open("POST", "/form-remember-password", true);
  xmlhttp.send(camposFormulario);
  limpiar_errores();

  loaderPrimary.style.display = "flex";
  boton_formulario.style.pointerEvents = "none";
  boton_formulario.style.opacity = 0.2;
});

// Listener para activar botón del form según se escribe en el input
inputCorreo.addEventListener("keypress", function (e) {
  if (inputCorreo.value !== "") {
    boton_formulario.style.pointerEvents = "initial";
    boton_formulario.style.opacity = 1;
  } else {
    boton_formulario.style.pointerEvents = "none";
    boton_formulario.style.opacity = 0.2;
  }
});

// Listener para desactivar el botón si el input está vacío
inputCorreo.addEventListener("change", function (e) {
  if (inputCorreo.value == "") {
    boton_formulario.style.pointerEvents = "none";
    boton_formulario.style.opacity = 0.2;
  } else {
    boton_formulario.style.pointerEvents = "initial";
    boton_formulario.style.opacity = 1;
  }
});

//Función limpiar errores del formulario con clase error
function limpiar_errores() {
  const campos = document.getElementsByClassName("error");
  for (const campo of campos) {
    campo.innerHTML = "";
  }
}
