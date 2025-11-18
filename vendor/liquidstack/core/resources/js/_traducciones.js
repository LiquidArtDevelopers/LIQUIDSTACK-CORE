// import cookieClass from "./_cookies.js";
// const cookie = cookieClass.getInstance();


//IMPORTAMOS UN OBJETO CON LA RELACIÓN DE PATHNAME CON RUTA
import rutas from "../../../App/config/rutas.js";
const DEFAULT_LANG = import.meta.env.LANG_DEFAULT || "es";
// console.log(rutas)


document.addEventListener('DOMContentLoaded', () => {
    const btn_idiomas = document.getElementsByClassName("btn_idioma");
    for (const btn of btn_idiomas) {
        btn.addEventListener("click", function (event) {

            event.preventDefault(); // Evita la recarga del enlace

            const idioma = btn.id; //
            // const newUrl = btn.getAttribute('href'); // Obtiene la URL del href

            try {
                // Manejar cookies
                if (typeof window.CookieLAD === 'undefined') {
                    console.error('CookieLAD no está definido. Asegúrate de que el script de CookieLAD esté cargado.');
                    return;
                }
                const cookie = window.CookieLAD;
                const okCookie = cookie.comprobarOkCookie("cookie_custom");
                if (okCookie) {
                    cookie.setCookie("cookie_custom_lang", idioma, 90);
                }

                // Manejar traducciones
                if (typeof traduccionClass === 'undefined') {
                    console.error('traduccionClass no está definido. Asegúrate de que el script de traducciones esté cargado.');
                    return;
                }
                const traduccion = traduccionClass.getInstance();
                traduccion.resetearIdioma();
                traduccion.traducirTodo(idioma);

                // // Actualizar la URL sin recargar
                // history.pushState({}, '', newUrl);
            } catch (error) {
                console.error('Error al cambiar idioma:', error);
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
    let ruta = rutas[pathLang][pathActual];
    // console.log(ruta)

    // COGEMOS EL JSON DEL GLOBAL E IDIOMA CORRESPONDIENTE
    this.jsonIdioma = "global"

    // console.log(this.jsonIdioma);

    //RECOGEMOS TODOS LOS ELEMENTOS DEL JSON
    fetch("/languages",{
      body:new URLSearchParams({route:this.jsonIdioma,lang:this.idioma}),
      method:"POST",
      headers:{"application":"application/x-www-form-urlencoded"}
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
            if (objGroupJson[dataLangValue]["href"]) {
              datalang.href = `${pathOrigin}/${langForHref}/${objGroupJson[dataLangValue]["href"]}`;
            } else {
              datalang.href = this.getHomeUrl(pathOrigin, langForHref);
            }
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
      headers:{"application":"application/x-www-form-urlencoded"}
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
            if (objGroupJson[dataLangValue]["href"]) {
              datalang.href = `${pathOrigin}/${langForHref}/${objGroupJson[dataLangValue]["href"]}`;
            } else {
              datalang.href = this.getHomeUrl(pathOrigin, langForHref);
            }
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

  //Traduciomos todo el documento en función de la url y el idioma
  traducirTodo(idioma) {
    this.idioma = idioma;
    const targetLang = this.idioma;
    const appConfig =
      typeof window !== "undefined" &&
      window.__APP_CONFIG__ &&
      typeof window.__APP_CONFIG__ === "object"
        ? window.__APP_CONFIG__
        : null;

    if (document?.documentElement) {
      document.documentElement.setAttribute("lang", targetLang);
    }

    if (appConfig) {
      appConfig.lang = targetLang;
      if (!appConfig.defaultLang && DEFAULT_LANG) {
        appConfig.defaultLang = DEFAULT_LANG;
      }
    }

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

    //BUSCAMOS EL ÍNDICE DE LA RUTA DENTRO DEL IDIOMA DEL PATHNAME, PARA ELLO CONVERTIMOS A ARRAY EL SEGUNDO NIVEL DEL OBJETO QUE HEMOS BUSCADO POR EL IDIOMA DEL PATHNAME, Y NOS QUEDAMOS CON SU ÍNDICE.
    let indiceRuta = Object.keys(rutas[pathLang]).indexOf(
      decodeURI(pathActual)
    );
    // console.log(indiceRuta);

    //BUSCAMOS LA RUTA EQUIVALENTE SEGÚN EL IDIOMA SELECCIONADO
    // console.log(this.idioma)

    let pathNueva = Object.keys(rutas[this.idioma])[indiceRuta];
    // console.log(pathNueva)

    //OBTENEMOS LA RUTA FINAL SEGÚN EL IDIOMA DE LA PATHNAME NUEVA
    let ruta = rutas[this.idioma][pathNueva];
    // console.log(ruta)

    const normalizedRoute = ruta ?? (appConfig?.route ?? null);

    if (appConfig) {
      appConfig.route = normalizedRoute;
    }

    window.dispatchEvent(
      new CustomEvent("app:languagechange", {
        detail: {
          lang: targetLang,
          route: normalizedRoute,
          defaultLang: DEFAULT_LANG,
        },
      })
    );

    //CAMBIAMOS LA RUTA VISIBLE POR LA NUEVA
    history.pushState(null, "", pathNueva);

    this.colorearIdioma();

    //----------
    // COGEMOS EL JSON DEL GLOBAL E IDIOMA CORRESPONDIENTE
    this.jsonIdioma = "global"

    // console.log(this.jsonIdioma);

      //RECOGEMOS TODOS LOS ELEMENTOS DEL JSON
      fetch("/languages",{
      body:new URLSearchParams({route:this.jsonIdioma,lang:this.idioma}),
      method:"POST",
      headers:{"application":"application/x-www-form-urlencoded"}
    })
      .then((response) => {
        if (response.ok) return response.text();
        else throw new Error(response.status);
      })
      .then((data) => {
        //PARSEAMOS EL JSON EN UN OBJETO
        const objGroupJson = JSON.parse(data);

        // RECOGEMOS EN UN ARRAY TODOS LOS ELEMENTOS HTML CON ESE ATRIBUTO Y LOS RECORREMOS
        const datalangs = document.querySelectorAll("[data-lang]");
        for (const datalang of datalangs) {
          //COGENMOS EL VALOR DEL DATALANG DE ESE TAG
          let dataLangValue = datalang.getAttribute("data-lang");

          //SI EXISTE DENTRO DEL OBJETO EL TAG COMO PROPIEDAD, ENTONCES MODIFICAMOS ATRIBUTOS DEL TAG (SI EXISTEN)
          if (objGroupJson[dataLangValue]) {
            /* Object.keys(objGroupJson[dataLangValue]).forEach( key=>{
                        if(key === "text"){
                            datalang.innerHTML = objGroupJson[dataLangValue][key]
                        }else{
                            if(datalang[key]){
                                datalang[key] = objGroupJson[dataLangValue][key]
                            }
                        }
                    } ) */

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
              if (objGroupJson[dataLangValue]["href"]) {
                datalang.href = `${pathOrigin}/${targetLang}/${objGroupJson[dataLangValue]["href"]}`;
              } else {
                datalang.href = this.getHomeUrl(pathOrigin, targetLang);
              }
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
        }
      })
      .catch((err) => {
        console.error("ERROR", err.message)
      });

    // COGEMOS EL JSON DE LA RUTA E IDIOMA CORRESPONDIENTE
    this.jsonIdioma = ruta

      //RECOGEMOS TODOS LOS ELEMENTOS DEL JSON
      fetch("/languages",{
      body:new URLSearchParams({route:this.jsonIdioma,lang:this.idioma}),
      method:"POST",
      headers:{"application":"application/x-www-form-urlencoded"}
    })
      .then((response) => {
        if (response.ok) return response.text();
        else throw new Error(response.status);
      })
      .then((data) => {
        //PARSEAMOS EL JSON EN UN OBJETO
        const objGroupJson = JSON.parse(data);

        // RECOGEMOS EN UN ARRAY TODOS LOS ELEMENTOS HTML CON ESE ATRIBUTO Y LOS RECORREMOS
        const datalangs = document.querySelectorAll("[data-lang]");
        for (const datalang of datalangs) {
          // console.log(datalang)

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
              if (objGroupJson[dataLangValue]["href"]) {
                datalang.href = `${pathOrigin}/${targetLang}/${objGroupJson[dataLangValue]["href"]}`;
              } else {
                datalang.href = this.getHomeUrl(pathOrigin, targetLang);
              }
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
          } else {
            if (objGroupJson.hasOwnProperty("errors")) {
              if (objGroupJson.errors[dataLangValue]) {
                datalang.textContent = objGroupJson.errors[dataLangValue];
              }
            }
          }
        }
      })
      .catch((err) => {
        console.error("ERROR", err.message);
      });
  }
}

