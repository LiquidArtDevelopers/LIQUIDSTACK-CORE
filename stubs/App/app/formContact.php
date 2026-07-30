<?php

require_once __DIR__."/../config/config.php";
require_once __DIR__."/../class/_comprobaciones.php";
$comprobacion = new clase_comprobaciones;

if(isset($_POST)){ 

    $ip = $_SERVER['REMOTE_ADDR'];
    $datetime = date("H:i:s d-m-Y ");

    $nombre = $_POST["nombre"];
    $telefono = $_POST["telefono"];
    $correo = $_POST["correo"];    
    $consulta = $_POST["mensaje"];

    $respuesta = $_POST["respuesta"];
    $solucion = $_POST["solucion"];

    $lang = $_POST["lang"];

    //COMPROBACIÓN DE LANG
    $langs= require(__DIR__.'/../config/langs.php');
    if(!in_array($lang, $langs)){
        $lang=$_ENV['LANG_DEFAULT'];
    }
    //--

    // OBTENEMOS LOS DATOS DEL JSON PARA MANEJAR CORREOS EN SU IDIOMA EN TODOS LOS ASPECTOS
    // Usamos ruta absoluta basada en __DIR__ para evitar problemas de path relativo
    $data = (array) json_decode(file_get_contents(__DIR__ . "/../config/languages/_email/{$lang}.json"));
    $data && extract($data);

    
    //LIQUID CAPTCHA
    //VACÍO
    if(empty($respuesta) || empty($solucion)){
        $fallo = true;
        $mensaje = $fallos->captcha1;
        $campo = "captcha_error";
        devolver_respuesta($mensaje, $fallo, $campo);
    }
    //MAL RESUELTA
    if($respuesta != $solucion){
        $fallo = true;
        $mensaje = $fallos->captcha2;
        $campo = "captcha_error";
        devolver_respuesta($mensaje, $fallo, $campo);
    }

    /* REVISIÓN CAMPO NOMBRE */
    /* comprobar que no venga vacío */
    if(empty($nombre)){
        $fallo = true;
        $mensaje = $fallos->nombre1;
        $campo = "nombre_error";
        devolver_respuesta($mensaje, $fallo, $campo);
    }
    // NÚMERO
    if(is_numeric($nombre)){
        $fallo = true;
        $mensaje = $fallos->nombre2;
        $campo = "nombre_error";
        devolver_respuesta($mensaje, $fallo, $campo);
    }
    // 4-40
    $numCaracteres = strlen($nombre);
    if($numCaracteres < 3 || $numCaracteres > 40){
        $fallo = true;
        $mensaje = $fallos->nombre3;
        $campo = "nombre_error";
        devolver_respuesta($mensaje, $fallo, $campo);
    }
    // CLEAN
    $nombre = $comprobacion->filtrarValor($nombre);


    // TELÉFONO
    // VACÍO
    if(empty($telefono)){
        $fallo = true;
        $mensaje = $fallos->tel1;
        $campo = "telefono_error";
        devolver_respuesta($mensaje, $fallo, $campo);
    }
    // =9
    $telefono = str_replace(" ", "", $telefono);
    /* $telefono = str_replace("+34", "", $telefono); */
    
    $numCaracteres = strlen($telefono);
    if($numCaracteres >= 15){
        $fallo = true;
        $mensaje = $fallos->tel2;
        $campo = "telefono_error";
        devolver_respuesta($mensaje, $fallo, $campo); 
    }
    // CLEAN
    $telefono = $comprobacion->filtrarValor($telefono);
    

    // CORREO
    // VACÍO (no obligatorio)
    if(empty($correo)){
        $fallo = true;
        $mensaje = $fallos->correo1;
        $campo = "correo_error";
        devolver_respuesta($mensaje, $fallo, $campo);
    }

    // NÚMERO
    if(is_numeric($correo)){
        $fallo = true;
        $mensaje = $fallos->correo2;
        $campo = "correo_error";
        devolver_respuesta($mensaje, $fallo, $campo);
    }
    // >100
    $numCaracteres = strlen($correo);
    if($numCaracteres > 100){
        $fallo = true;
        $mensaje = $fallos->correo3;
        $campo = "correo_error";
        devolver_respuesta($mensaje, $fallo, $campo);
    }
    // VALID
    if($comprobacion->validar_email($correo)==0 && !empty($correo)){
        $fallo = true;
        $mensaje = $fallos->correo4;
        $campo = "correo_error";
        devolver_respuesta($mensaje, $fallo, $campo);
    }
    // CLEAN
    $correo = $comprobacion->filtrarValor($correo);
    $correo = $comprobacion->minusculas($correo);


    // CONSULTA
    // VACÍO
    if(empty($consulta)){
        $fallo = true;
        $mensaje = $fallos->consulta1;
        $campo = "mensaje_error";
        devolver_respuesta($mensaje, $fallo, $campo);
    }
    // 10-500
    $numCaracteres = strlen($consulta);
    if($numCaracteres < 10 || $numCaracteres > 500){
        $fallo = true;
        $mensaje = $fallos->consulta2;
        $campo = "mensaje_error";
        devolver_respuesta($mensaje, $fallo, $campo);
    }
    // CLEAN
    $consulta = $comprobacion->filtrarValor($consulta);


    //**** PHPMAILER ****
    //ADMIN
    $correoEmisor= $_ENV['MAIL_WEB'];
    $nombreEmisor =$_ENV['EMISOR_NAME']." (".$consulta_admin->tipo.")";
    $destinatario = $_ENV['MAIL_ADMIN'];
    $nombreDestinatario = $_ENV['EMISOR_NAME'];
    $asunto = $_ENV['EMISOR_NAME']." | ". $consulta_admin->asunto ." ". $nombre;
    
    /*--------------------------------------
    _formContactAdmin
    --------------------------------------*/
    $pageVars = [

        // Encabezados del correo
        '{title}' => $consulta_admin->title,
        '{saludo}' => $consulta_admin->saludo,
        '{contexto}' => $consulta_admin->contexto,
        '{explicacion}' => $consulta_admin->explicacion,
        '{datosEncabezado}' => $consulta_admin->datosEncabezado,
        '{headerNombre}' => $consulta_admin->headerNombre,
        '{headerTelefono}' => $consulta_admin->headerTelefono,
        '{headerCorreo}' => $consulta_admin->headerCorreo,
        '{headerDate}' => $consulta_admin->headerDate,
        '{headerLang}' => $consulta_admin->headerLang,
        '{headerConsulta}' => $consulta_admin->headerConsulta,
        '{despedida}' => $consulta_admin->despedida,
        '{equipo}' => $consulta_admin->equipo,


        // Destinatario
        '{nombreDestinatario}' => $nombreDestinatario,

        // Datos del remitente
        '{nombre}'   => $nombre,
        '{telefono}' => $telefono ?? '—',
        '{correo}'   => $correo   ?? '—',

        // Datos de sistema
        '{datetime}' => date('d/m/Y H:i'),
        '{lang}'     => $lang ?? $_ENV['LANG_DEFAULT'],

        // Mensaje
        '{consulta}' => $consulta  ?? '—',

        // Marca y web
        '{web}' => $_ENV['DOMAIN'],
        '{web-url}' => $_ENV['DOMAIN_URL']
    ];
    /*  Renderizamos la plantilla y la guardamos
        en $cuerpo para PHPMailer → $mail->Body */
    $cuerpo = render(__DIR__.'/../templates/_formContactAdmin.html', $pageVars);

    include __DIR__.'/_phpmailer.php';

    //**** PHPMAILER ****
    //USER
    $correoEmisor= $_ENV['MAIL_WEB'];
    $nombreEmisor =$_ENV['EMISOR_NAME']." (".$consulta_user->tipo.")";
    $destinatario = $correo;
    $nombreDestinatario = $nombre;
    $asunto = $_ENV['EMISOR_NAME'] ." | ". $consulta_user->asunto ." ". $nombre;
    
    /*--------------------------------------
    _formContactAdmin
    --------------------------------------*/
    $pageVars = [

        // Encabezados del correo
        '{title}' => $consulta_user->title,
        '{saludo}' => $consulta_user->saludo,
        '{contexto}' => $consulta_user->contexto,
        '{explicacion}' => $consulta_user->explicacion,
        '{datosEncabezado}' => $consulta_user->datosEncabezado,
        '{headerNombre}' => $consulta_user->headerNombre,
        '{headerTelefono}' => $consulta_user->headerTelefono,
        '{headerCorreo}' => $consulta_user->headerCorreo,
        '{headerDate}' => $consulta_user->headerDate,
        '{headerConsulta}' => $consulta_user->headerConsulta,
        '{despedida}' => $consulta_user->despedida,
        '{equipo}' => $consulta_user->equipo,
        

        // Asunto y destinatario
        '{nombreDestinatario}' => $nombreDestinatario,

        // Datos del remitente
        '{nombre}'   => $nombre,
        '{telefono}' => $telefono ?? '—',
        '{correo}'   => $correo   ?? '—',

        // Datos de sistema
        '{datetime}' => date('d/m/Y H:i'),

        // Mensaje
        '{consulta}' => $consulta  ?? '—',

        // Marca y web
        '{web}' => $_ENV['DOMAIN'],
        '{web-url}' => $_ENV['DOMAIN_URL']
    ];
    /*  Renderizamos la plantilla y la guardamos
        en $cuerpo para PHPMailer → $mail->Body */
    $cuerpo = render(__DIR__.'/../templates/_formContactUser.html', $pageVars);

    include __DIR__.'/_phpmailer.php';

       
    sleep(2);
    $fallo = false;
    $mensaje="El formulario se ha enviado con éxito";
    $campo = "";
    devolver_respuesta($mensaje, $fallo, $campo);


    /* gestión con bbdd */
    
}else{

    // NO ENTRA POR POST
    $fallo = true;
    $mensaje = "No se están recibiendo datos del formulario";
    $campo = "terminos_error";
    devolver_respuesta($mensaje, $fallo, $campo);
}


?>
