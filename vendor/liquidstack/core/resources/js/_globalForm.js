const DEFAULT_LANG = import.meta.env.LANG_DEFAULT || "es";

export default function initGlobalForm(){

    const formId = "form01"

    const formulario = document.getElementById(formId);
    const boton_formulario = document.getElementById("form01_button");
    const enviado = document.getElementById("enviado");
    const beztebat = document.getElementById("beztebat");
    const loaderPrimary = document.getElementById("loader01");

    if(!formulario){
        return;
    }

    const num1 = document.getElementById("num1");
    const num2 = document.getElementById("num2");
    const solucion = document.getElementById("solucion");

    if(num1 && num2 && solucion){
        liquidCaptchaContact(num1, num2, solucion);
    }

    // activamos botón formulario
    if(boton_formulario){
        boton_formulario.style.opacity=1
        boton_formulario.style.pointerEvents="initial"
    }


    function liquidCaptchaContact(num1, num2, solucion){


        var n1 = Math.floor((Math.random() * (10 - 1 + 1)) + 1);
        var n2 = Math.floor((Math.random() * (10 - 1 + 1)) + 1);
        var r = n1+n2
        num1.innerHTML = n1
        num2.innerHTML = n2
        solucion.value=r;
    }

    // Evento submit del form
    if(formulario){
        formulario.addEventListener("submit", function(evento){

            evento.preventDefault();

            const terminos_error = document.getElementById("terminos_error");
            const terminos = document.getElementById("terminos");
            if(terminos && terminos.checked==false){
                if(terminos_error){
                    terminos_error.innerHTML="Debes aceptar las condiciones de privacidad para continuar."
                }
                return;
            };
            //OBTENEMOS EL IDIOMA DE LA URL ACTUAL
            //COGEMOS LA URL ACTUAL
            let pathActual = window.location.pathname
            // console.log(pathActual)

            //COGEMOS EL IDIOMA DE LA URL ACTUAL
            //Con este código cogemos el idioma de la URL actual del cliente. Da igual si ha cambiado el idioma por ajax o por refresh de la web, en la URL siempre estará el idioma en curso.
            let arrPathActual = pathActual.split("/")
            // console.log(arrPathActual)
            let pathLang = arrPathActual[1]
            if(pathLang =="" || pathLang.length > 2){
                pathLang = DEFAULT_LANG
            }
            // console.log(pathLang)

            const camposFormulario = new FormData(formulario)
            //Aquí es donde añadimos un campo más al objeto tipo formData.
            camposFormulario.append("lang", pathLang);
            const xmlhttp = new XMLHttpRequest();
            xmlhttp.onload = function(){
                switch (true){
                    case xmlhttp.status >= 200 && xmlhttp.status <300:
                        if(loaderPrimary){
                            loaderPrimary.style.display="none";
                        }
                        if(formulario){
                            formulario.style.display="none";
                        }
                        if(enviado){
                            enviado.style.display="flex";
                        }
                    break;
                    case xmlhttp.status >= 400 && xmlhttp.status < 500:
                        var jsonRecibido = xmlhttp.responseText;
                        var ArrayJson = JSON.parse(jsonRecibido);
                        var mensaje = ArrayJson.mensaje;
                        var campo = ArrayJson.campo;
                        var campo_error = document.getElementById(campo);
                        if(campo_error){
                            campo_error.innerHTML=mensaje;
                        }
                        if(loaderPrimary){
                            loaderPrimary.style.display="none";
                        }
                        if(boton_formulario){
                            boton_formulario.style.pointerEvents="inherit";
                            boton_formulario.style.opacity=1;
                        }
                    break;
                    case xmlhttp.status >= 500 && xmlhttp.status < 600:
                        var campo_error = document.getElementById("nombre_error");
                        if(campo_error){
                            campo_error.innerHTML="Error en el servidor";
                        }
                        if(loaderPrimary){
                            loaderPrimary.style.display="none";
                        }
                        if(boton_formulario){
                            boton_formulario.style.pointerEvents="inherit";
                            boton_formulario.style.opacity=1;
                        }
                    break;
                }
            }
            /* Control de error cuando no hay conexión */
            xmlhttp.onerror=function(){
                var campo_error = document.getElementById("nombre_error");
                if(campo_error){
                    campo_error.innerHTML="Error de conexión";
                }
                if(loaderPrimary){
                    loaderPrimary.style.display="none";
                }
                if(boton_formulario){
                    boton_formulario.style.pointerEvents="inherit";
                    boton_formulario.style.opacity=1;
                }
            }

            //Ruta POST /form para este formulario, se verifica en el controlador del index
            xmlhttp.open('POST','/form',true);
            xmlhttp.send(camposFormulario);
            limpiar_errores()

            if(loaderPrimary){
                loaderPrimary.style.display="flex";
            }
            if(boton_formulario){
                boton_formulario.style.pointerEvents="none";
                boton_formulario.style.opacity=0.2;
            }
        })
    }

    // mostramos el form para una nueva consulta
    if(beztebat){
        beztebat.addEventListener("click", function(){
            if(enviado){
                enviado.style.display="none";
            }
            if(formulario){
                formulario.style.display="inherit";
            }
            //texto_consulta.value=" ";
            if(boton_formulario){
                boton_formulario.style.pointerEvents="inherit";
                boton_formulario.style.opacity=1;
            }
            //grecaptcha.reset();
        })
    }

    function limpiar_errores(){
        const campos = document.getElementsByClassName("error");
        for(const campo of campos){
            campo.innerHTML="";
        }
    }
}

