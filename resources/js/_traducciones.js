// import cookieClass from "./_cookies.js";
// const cookie = cookieClass.getInstance();


//IMPORTAMOS UN OBJETO CON LA RELACIÓN DE PATHNAME CON RUTA
import rutas from "../../../App/config/rutas.js";
import {
  createLatestLanguageRequestTracker,
  loadLanguageCatalogs,
  navigateToLanguageHref,
  persistLanguagePreference,
} from "./_languagePreference.mjs";
const DEFAULT_LANG = import.meta.env.LANG_DEFAULT || "es";
const languageRequests = createLatestLanguageRequestTracker();
// console.log(rutas)


document.addEventListener('DOMContentLoaded', () => {
    const btn_idiomas = document.getElementsByClassName("btn_idioma");
    for (const btn of btn_idiomas) {
        btn.addEventListener("click", async function (event) {
            event.preventDefault();

            const idioma = btn.id;
            const fallbackHref = btn.getAttribute("href");
            const requestToken = languageRequests.next();

            // CookieLAD solo gobierna la persistencia opcional. Su ausencia o
            // cualquier fallo interno nunca debe bloquear el cambio de idioma.
            persistLanguagePreference(window.CookieLAD, idioma);

            try {
                if (typeof traduccionClass !== "function") {
                    if (languageRequests.isCurrent(requestToken)) {
                        navigateToLanguageHref(window, fallbackHref);
                    }
                    return;
                }

                const traduccion = traduccionClass.getInstance();
                await traduccion.traducirTodo(idioma, requestToken);
            } catch (error) {
                if (!languageRequests.isCurrent(requestToken)) {
                    return;
                }
                console.error("Error al cambiar idioma:", error);
                navigateToLanguageHref(window, fallbackHref);
            }
        });
    }
});


export default class traduccionClass {
  constructor() {
    this.navIdioma = "";
    this.idioma = "";
    this.okCookie = false;
    this.jsonIdioma = "";
    this.listadoIdiomas;
  }

  static getInstance() {
    return new traduccionClass();
  }

  getHomeUrl(pathOrigin, idioma) {
    const lang = idioma || DEFAULT_LANG;
    return lang === DEFAULT_LANG ? pathOrigin : `${pathOrigin}/${lang}`;
  }

  resolveLocalizedHref(pathOrigin, idioma, rawHref) {
    const href = typeof rawHref === "string" ? rawHref.trim() : "";

    if (href === "") {
      return this.getHomeUrl(pathOrigin, idioma);
    }

    if (
      href.startsWith("/") ||
      href.startsWith("#") ||
      href.startsWith("?") ||
      href.startsWith("//") ||
      /^[a-z][a-z\d+.-]*:/i.test(href)
    ) {
      return href;
    }

    const lang = idioma || DEFAULT_LANG;
    return `${pathOrigin}/${lang}/${href}`;
  }

  resolveRouteContext(pathActual, currentLang, targetLang = currentLang) {
    const currentRoutes = rutas[currentLang] ?? {};
    const targetRoutes = rutas[targetLang] ?? {};
    const decodedPath = decodeURI(pathActual);
    let routeIndex = Object.keys(currentRoutes).indexOf(decodedPath);
    const directTarget =
      routeIndex >= 0 ? Object.keys(targetRoutes)[routeIndex] : null;

    if (directTarget) {
      return {
        path: directTarget,
        route: targetRoutes[directTarget] ?? null,
      };
    }

    const category = document.body?.dataset.showroomCategory ?? "";
    if (
      category === ""
      || category === "index"
      || !decodedPath.endsWith(`/${category}`)
    ) {
      return { path: null, route: null };
    }

    const parentPath = decodedPath.slice(0, -(category.length + 1));
    routeIndex = Object.keys(currentRoutes).indexOf(parentPath);
    const targetParent =
      routeIndex >= 0 ? Object.keys(targetRoutes)[routeIndex] : null;

    if (!targetParent) {
      return { path: null, route: null };
    }

    return {
      path: `${targetParent.replace(/\/$/, "")}/${category}`,
      route: targetRoutes[targetParent] ?? null,
    };
  }

  //Función para quitar los estilos al selector de idiomas
  resetearIdioma() {
    this.listadoIdiomas = document.getElementsByClassName("btn_idioma");
    for (const item of this.listadoIdiomas) {
      //recorremos todos los elementos html con esa clase
      item.classList.remove("idioma_select");
    }
  }

  colorearIdioma() {

    //COGEMOS LA URL ACTUAL
    let pathActual = window.location.pathname;
    // console.log(pathActual)

    //COGEMOS EL IDIOMA DE LA URL ACTUAL
    let arrPathActual = pathActual.split("/");
    // console.log(arrPathActual)
    let pathLang = arrPathActual[1];
    if (pathLang == "" || pathLang.length > 2) {
      pathLang = DEFAULT_LANG;
    }
    const selectLang = document.getElementById(pathLang);
    selectLang.classList.add("idioma_select");
  }

  idiomaNavegador() {
    //cogemos el idioma del navegador por defecto
    this.navIdioma = navigator.language || navigator.userLanguage;
    //console.log(this.navIdioma)
    let idiomaNav;
    if (this.navIdioma == "eu") {
      //console.log("Euskera")
      idiomaNav = "eu";
    } else if (this.navIdioma == "es-ES" || this.navIdioma == "es") {
      //console.log("Castellano")
      idiomaNav = "es";
    } else if (this.navIdioma == "fr-FR" || this.navIdioma == "fr") {
      //console.log("Francés")
      idiomaNav = "fr";
    } else {
      //console.log("desconocido")
      idiomaNav = DEFAULT_LANG;
    }
    return idiomaNav;
  }

  // TODO añadir método para obtener value de un key, debería ser async

  //función para traducir un elemento en concreto.
  traducirUno(datalang) {
    //COGEMOS EL PROTOCOLO Y EL HOSTNAME
    let pathOrigin = window.location.origin;

    //COGEMOS LA URL ACTUAL
    let pathActual = window.location.pathname;
    // console.log(pathActual)

    //COGEMOS EL IDIOMA DE LA URL ACTUAL
    let arrPathActual = pathActual.split("/");
    // console.log(arrPathActual)
    let pathLang = arrPathActual[1];
    if (pathLang == "" || pathLang.length > 2) {
      pathLang = DEFAULT_LANG;
    }
    const langForHref = this.idioma || pathLang;
    // console.log(pathLang)

    //OBTENEMOS LA RUTA FINAL SEGÚN EL IDIOMA DE LA PATHNAME NUEVA
    let ruta = this.resolveRouteContext(
      pathActual,
      pathLang,
      this.idioma || pathLang
    ).route;
    // console.log(ruta)

    // COGEMOS EL JSON DEL GLOBAL E IDIOMA CORRESPONDIENTE
    this.jsonIdioma = "global"

    // console.log(this.jsonIdioma);

    //RECOGEMOS TODOS LOS ELEMENTOS DEL JSON
    fetch("/languages",{
      body:new URLSearchParams({route:this.jsonIdioma,lang:this.idioma}),
      method:"POST",
      headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"}
    })
      .then((response) => {
        if (response.ok) return response.text();
        else throw new Error(response.status);
      })
      .then((data) => {
        // console.log(data)
        //PARSEAMOS EL JSON EN UN OBJETO
        const objGroupJson = JSON.parse(data);

        //COGENMOS EL VALOR DEL DATALANG DE ESE TAG
        let dataLangValue = datalang.getAttribute("data-lang");

        //SI EXISTE DENTRO DEL OBJETO EL TAG COMO PROPIEDAD, ENTONCES MODIFICAMOS ATRIBUTOS DEL TAG (SI EXISTEN)
        if (objGroupJson[dataLangValue]) {
          if (objGroupJson[dataLangValue]["alt"]) {
            datalang.alt = objGroupJson[dataLangValue]["alt"];
          }
          if (objGroupJson[dataLangValue]["title"]) {
            datalang.title = objGroupJson[dataLangValue]["title"];
          }
          if (objGroupJson[dataLangValue]["text"]) {
            datalang.innerHTML = objGroupJson[dataLangValue]["text"];
          }
          if (Object.keys(objGroupJson[dataLangValue]).includes("href")) {
            datalang.href = this.resolveLocalizedHref(
              pathOrigin,
              langForHref,
              objGroupJson[dataLangValue]["href"]
            );
          }
          if (objGroupJson[dataLangValue]["placeholder"]) {
            datalang.placeholder = objGroupJson[dataLangValue]["placeholder"];
          }
          if (objGroupJson[dataLangValue]["value"]) {
            datalang.value = objGroupJson[dataLangValue]["value"];
          }
          if (objGroupJson[dataLangValue]["content"]) {
            datalang.content = objGroupJson[dataLangValue]["content"];
          }
          if (Object.keys(objGroupJson[dataLangValue]).includes("src")) {
            if (objGroupJson[dataLangValue]["src"]) {
              datalang.src = `${pathOrigin}/${objGroupJson[dataLangValue]["src"]}`;
            }
          }
        }
      })
      .catch((err) => {
        console.error("ERROR", err.message);
      });

    // COGEMOS EL JSON DE LA RUTA E IDIOMA CORRESPONDIENTE
    this.jsonIdioma = ruta

    // console.log(this.jsonIdioma);

    //RECOGEMOS TODOS LOS ELEMENTOS DEL JSON
    fetch("/languages",{
      body:new URLSearchParams({route:this.jsonIdioma,lang:this.idioma}),
      method:"POST",
      headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"}
    })
      .then((response) => {
        if (response.ok) return response.text();
        else throw new Error(response.status);
      })
      .then((data) => {
        //PARSEAMOS EL JSON EN UN OBJETO
        const objGroupJson = JSON.parse(data);

        //COGENMOS EL VALOR DEL DATALANG DE ESE TAG
        let dataLangValue = datalang.getAttribute("data-lang");

        //SI EXISTE DENTRO DEL OBJETO EL TAG COMO PROPIEDAD, ENTONCES MODIFICAMOS ATRIBUTOS DEL TAG (SI EXISTEN)
        if (objGroupJson[dataLangValue]) {
          if (objGroupJson[dataLangValue]["alt"]) {
            datalang.alt = objGroupJson[dataLangValue]["alt"];
          }
          if (objGroupJson[dataLangValue]["title"]) {
            datalang.title = objGroupJson[dataLangValue]["title"];
          }
          if (objGroupJson[dataLangValue]["text"]) {
            datalang.innerHTML = objGroupJson[dataLangValue]["text"];
          }
          if (Object.keys(objGroupJson[dataLangValue]).includes("href")) {
            datalang.href = this.resolveLocalizedHref(
              pathOrigin,
              langForHref,
              objGroupJson[dataLangValue]["href"]
            );
          }
          if (objGroupJson[dataLangValue]["placeholder"]) {
            datalang.placeholder = objGroupJson[dataLangValue]["placeholder"];
          }
          if (objGroupJson[dataLangValue]["value"]) {
            datalang.value = objGroupJson[dataLangValue]["value"];
          }
          if (objGroupJson[dataLangValue]["content"]) {
            datalang.content = objGroupJson[dataLangValue]["content"];
          }
          if (Object.keys(objGroupJson[dataLangValue]).includes("src")) {
            if (objGroupJson[dataLangValue]["src"]) {
              datalang.src = `${pathOrigin}/${objGroupJson[dataLangValue]["src"]}`;
            }
          }
        }
      })
      .catch((err) => {
        console.error("ERROR", err.message);
      });
  }

  aplicarCatalogo(objGroupJson, pathOrigin, targetLang) {
    const datalangs = document.querySelectorAll("[data-lang]");
    for (const datalang of datalangs) {
      const dataLangValue = datalang.getAttribute("data-lang");
      const entry = objGroupJson[dataLangValue];

      if (entry) {
        if (entry.alt) datalang.alt = entry.alt;
        if (entry.title) datalang.title = entry.title;
        if (entry.text) datalang.innerHTML = entry.text;
        if (Object.keys(entry).includes("href")) {
          datalang.href = this.resolveLocalizedHref(
            pathOrigin,
            targetLang,
            entry.href
          );
        }
        if (entry.placeholder) datalang.placeholder = entry.placeholder;
        if (entry.value) datalang.value = entry.value;
        if (entry.content) datalang.content = entry.content;
        if (Object.keys(entry).includes("src") && entry.src) {
          datalang.src = `${pathOrigin}/${entry.src}`;
        }
        continue;
      }

      if (
        objGroupJson.errors
        && dataLangValue
        && objGroupJson.errors[dataLangValue]
      ) {
        datalang.textContent = objGroupJson.errors[dataLangValue];
      }
    }
  }

  // Traducimos todo el documento solo tras cargar ambos catálogos. Un
  // fallo HTTP o JSON rechaza la promesa para que el selector navegue al href
  // localizado sin dejar una traducción parcial como estado definitivo.
  async traducirTodo(idioma, requestToken = null) {
    const activeRequestToken = requestToken
      ?? languageRequests.next();
    const targetLang = idioma;
    const appConfig =
      typeof window !== "undefined" &&
      window.__APP_CONFIG__ &&
      typeof window.__APP_CONFIG__ === "object"
        ? window.__APP_CONFIG__
        : null;

    const pathOrigin = window.location.origin;
    const pathActual = window.location.pathname;
    const arrPathActual = pathActual.split("/");
    let pathLang = arrPathActual[1];
    if (pathLang === "" || pathLang.length > 2) {
      pathLang = DEFAULT_LANG;
    }

    const routeContext = this.resolveRouteContext(
      pathActual,
      pathLang,
      targetLang
    );
    const pathNueva = routeContext.path ?? pathActual;
    const ruta = routeContext.route;
    const normalizedRoute = ruta ?? (appConfig?.route ?? null);

    const catalogRoutes = ["global"];
    if (normalizedRoute && normalizedRoute !== "global") {
      catalogRoutes.push(normalizedRoute);
    }
    const catalogs = await loadLanguageCatalogs(
      fetch,
      catalogRoutes,
      targetLang
    );
    if (!languageRequests.isCurrent(activeRequestToken)) {
      return false;
    }

    this.idioma = targetLang;
    if (document?.documentElement) {
      document.documentElement.setAttribute("lang", targetLang);
    }
    if (appConfig) {
      appConfig.lang = targetLang;
      appConfig.route = normalizedRoute;
      if (!appConfig.defaultLang && DEFAULT_LANG) {
        appConfig.defaultLang = DEFAULT_LANG;
      }
    }

    for (const catalog of catalogs) {
      this.aplicarCatalogo(catalog, pathOrigin, targetLang);
    }

    window.dispatchEvent(
      new CustomEvent("app:languagechange", {
        detail: {
          lang: targetLang,
          route: normalizedRoute,
          defaultLang: DEFAULT_LANG,
          path: pathNueva,
        },
      })
    );

    //CAMBIAMOS LA RUTA VISIBLE POR LA NUEVA
    history.pushState(null, "", pathNueva);

    this.resetearIdioma();
    this.colorearIdioma();

    return true;
  }
}
