// Módulo para mostrar ocultar contraseña
import "./_showPassword.js";
import rutas from "../../../App/config/rutas.js";

const DEFAULT_LANG = (import.meta.env.LANG_DEFAULT || "es").toLowerCase();
const LANGUAGE_ENDPOINT = "/languages";
const DEFAULT_REDIRECT_SLUG = "";
const AVAILABLE_LANGS = Object.keys(rutas);
const FALLBACK_LANG =
  AVAILABLE_LANGS.includes(DEFAULT_LANG) && DEFAULT_LANG.length === 2
    ? DEFAULT_LANG
    : AVAILABLE_LANGS[0] || DEFAULT_LANG;
const NORMALIZED_PATH = normalizePath(window.location.pathname);
const CURRENT_ROUTE_NAMESPACE = resolveRouteNamespace(NORMALIZED_PATH);
const pathOrigin = window.location.origin;

//funciones asíncronas para comprobar si el campo es correcto (tipo email) y que proceda al envío de un correo para el cambio de pass

// Módulo para validar con backend el formulario XMLHTTP Request

const contenedor_formulario = document.getElementById("contenedor_formulario");
const formulario = document.getElementById("formulario");
const boton_formulario = document.getElementById("boton_formulario");
const enviado = document.getElementById("enviado");
const loaderPrimary = document.getElementById("loaderPrimary");
const inputUsuario = document.getElementById("usuarioId");
const inputPass = document.getElementById("passwordId");
const body = document.querySelector("body");

// Evento submit del form
formulario.addEventListener("submit", function (evento) {
  evento.preventDefault();

  const camposFormulario = new FormData(document.forms.namedItem("formulario"));
  //Aquí es donde añadimos un campo más al objeto tipo formData.
  const xmlhttp = new XMLHttpRequest();
  xmlhttp.onload = function () {
    switch (true) {
      case xmlhttp.status >= 200 && xmlhttp.status < 300:
        // Si hay respuesta correcta (logeo), quitamos form, mostramos enviado y animación del mismo. Después redirigimos.
        loaderPrimary.style.display = "flex";
        contenedor_formulario.style.display = "none";
        enviado.style.display = "inherit";

        //Animación del check en verde
        const animacion1 = enviado.animate(
          [
            { scale: 0.5, opacity: 0, offset: 0 },
            { scale: 1, opacity: 1, offset: 0.4 },
            { scale: 1, opacity: 1, offset: 0.8 },
            { scale: 3, opacity: 0, offset: 1 },
          ],
          {
            duration: 2000,
            iterations: 1,
            easing: "ease-in",
          }
        );

        //callback cuando termine la animación1, activamos animació2 del body y cuando termine esta, redirigimos.
        animacion1.finished.then(() => {
          const animacion2 = body.animate(
            [{ opacity: 1 }, { opacity: 0 }, { opacity: 0 }],
            {
              duration: 2000,
              iterations: 1,
            }
          );

          animacion2.finished.then(() => {
            body.style.opacity = "0";

            const lang = getActiveLang();

            fetchRedirectUrl(lang)
              .then((targetUrl) => {
                window.location.href = targetUrl;
              })
              .catch((err) => {
                console.error("ERROR", err.message || err);
                window.location.href = buildRedirectUrl(
                  pathOrigin,
                  lang,
                  DEFAULT_REDIRECT_SLUG
                );
              });
          });
        });

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
  xmlhttp.open("POST", "/form-login", true);
  xmlhttp.send(camposFormulario);
  limpiar_errores();

  loaderPrimary.style.display = "flex";
  boton_formulario.style.pointerEvents = "none";
  boton_formulario.style.opacity = 0.2;
});

// Listeners para activar o no el botón del form según se escribe en el input
inputUsuario.addEventListener("keypress", outVoid);
inputUsuario.addEventListener("change", onVoid);
inputPass.addEventListener("keypress", outVoid);
inputPass.addEventListener("change", onVoid);

//Función para si viene vacío
function onVoid() {
  if (inputUsuario.value == "" || inputPass.value == "") {
    boton_formulario.style.pointerEvents = "none";
    boton_formulario.style.opacity = 0.2;
  } else {
    boton_formulario.style.pointerEvents = "initial";
    boton_formulario.style.opacity = 1;
  }
}

//Función si los campos cumplen
function outVoid() {
  if (inputUsuario.value !== "" && inputPass.value !== "") {
    boton_formulario.style.pointerEvents = "initial";
    boton_formulario.style.opacity = 1;
  } else {
    boton_formulario.style.pointerEvents = "none";
    boton_formulario.style.opacity = 0.2;
  }
}

//Función limpiar errores del formulario con clase error
function limpiar_errores() {
  const campos = document.getElementsByClassName("error");
  for (const campo of campos) {
    campo.innerHTML = "";
  }
}

function fetchRedirectUrl(lang) {
  return requestLanguageData(CURRENT_ROUTE_NAMESPACE, lang).then((data) => {
    const redirectSlug =
      data && data.redirect ? data.redirect : DEFAULT_REDIRECT_SLUG;
    return buildRedirectUrl(pathOrigin, lang, redirectSlug);
  });
}

function requestLanguageData(routeKey, lang) {
  const params = new URLSearchParams({
    route: routeKey,
    lang,
  });

  return fetch(LANGUAGE_ENDPOINT, {
    method: "POST",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded",
    },
    body: params,
  }).then((response) => {
    if (!response.ok) {
      throw new Error(response.status.toString());
    }
    return response.json();
  });
}

function buildRedirectUrl(origin, lang, slug) {
  if (typeof slug === "string" && /^https?:\/\//i.test(slug)) {
    return slug;
  }
  if (typeof slug === "string" && slug.startsWith("/")) {
    return `${origin}${slug}`;
  }
  const normalizedLang = sanitizeSegment(lang || FALLBACK_LANG);
  const normalizedSlug = sanitizeSegment(slug || DEFAULT_REDIRECT_SLUG);
  const segments = [normalizedLang, normalizedSlug].filter(Boolean);
  return `${origin}/${segments.join("/")}`;
}

function getActiveLang() {
  const cookieLang = getCookieValue("cookie_custom_lang");
  if (isValidLang(cookieLang)) {
    return cookieLang.toLowerCase();
  }

  const htmlElement = document.documentElement || {};
  const htmlLang = (htmlElement.lang || "").trim().toLowerCase();
  if (isValidLang(htmlLang)) {
    return htmlLang;
  }

  const [, pathLang] = window.location.pathname.split("/");
  if (isValidLang(pathLang)) {
    return pathLang.toLowerCase();
  }

  return FALLBACK_LANG;
}

function getCookieValue(name) {
  if (!document.cookie) {
    return null;
  }
  const cookies = document.cookie.split(";");
  for (const cookie of cookies) {
    const [key, ...rest] = cookie.split("=");
    if (key && key.trim() === name) {
      return decodeURIComponent(rest.join("="));
    }
  }
  return null;
}

function isValidLang(value) {
  if (typeof value !== "string" || value.length !== 2) {
    return false;
  }
  return AVAILABLE_LANGS.includes(value.toLowerCase());
}

function sanitizeSegment(value) {
  if (typeof value !== "string") {
    return "";
  }
  return value.replace(/^\/+/, "").replace(/\/+$/, "");
}

function normalizePath(pathname) {
  if (!pathname || pathname === "/") {
    return "/";
  }
  return pathname.endsWith("/") ? pathname.slice(0, -1) : pathname;
}

function resolveRouteNamespace(path) {
  for (const langKey of AVAILABLE_LANGS) {
    const routeGroup = rutas[langKey];
    if (routeGroup && routeGroup[path]) {
      return routeGroup[path];
    }
  }
  return "login";
}
