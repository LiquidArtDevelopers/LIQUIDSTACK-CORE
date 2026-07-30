<?php

/* Clase para instanciar en todos los archivos php que queramos mediante un objeto tipo esta clase, pudiendo usar a través de ese objeto todos los métodos (funciones) que tiene esta clase */

class clase_comprobaciones{

    //FILTRAR DATOS SCRIPTS
    //Elimina del valor aquellas palabras peligrosas además de otras opciones de seguridad
    public function filtrarValor($var) {
        $var = addslashes($var); //agregamos las contrabarras donde haya comillas
        //$var = stripslashes($var); //quitar contrabarras
        $var = strtoupper($var); 
        $var = str_replace(array("*", "php", "<", ">", "</>", "script", "drop", "delete", "insert", "select","update", "where", "<?", "?>", "<?="), "", $var);//sustituir palabras o símbolos peligrosos
        $var = htmlspecialchars($var); //evitamos etiquetas html
        $var = trim($var); //quita el espacio en blanco del principio y final de la cadena (si lo hay)
        return $var;
    }

    //Elimina del valor aquellas palabras peligrosas
    public function filtrarValorLight($var) {       
        $var = str_replace(["php", "script", "drop", "delete", "insert", "select","update", "where", "<?="], ["","","","","","","","",""], $var);//sustituir palabras o símbolos peligrosos
        $var = trim($var); //quita el espacio en blanco del principio y final de la cadena (si lo hay)
        return $var;
    }
    
    /* A minúsculas */
    public function minusculas($var){
        $var = strtolower($var);
        return $var;
    }

    /* A mayúsculas */
    public function mayusculas($var){
        $var = strtoupper($var);
        return $var;
    }

    //FUNCIÓN PARA VALIDAR UN CORREO CON EXPRESIONES REGULARES
    public function validar_email($email) {
        $regex = '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/';
        /* echo preg_match($regex, $email) ? "El email es válido":"El email no es válido"; */
        return preg_match($regex, $email);
    }

    // FUNCIÓN PARA VALIDAR UN PASSWORD: FALSE SI NO ES CORRECTO
    public function validar_password($pass){   
        $regex="/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[\W_])[^\s]{8,}$/";
        return preg_match($regex, $pass);
    }

    //Caracteres peligrosos para inyecciones SQL, HTML, JavaScript y PHP:
    public function validar_peligroso($var){ 
        $regex = '/(php|<\/?>|script|drop|delete|insert|select|update|where|\bunion\b|\btruncate\b|\balter\b|\bexec\b|\bshutdown\b|\biframe\b|javascript:|onerror|onload|eval|alert|\{\{|<\?|\?>|<\?=)/i';
        return preg_match($regex, $var)===1;
    }

}

?>
