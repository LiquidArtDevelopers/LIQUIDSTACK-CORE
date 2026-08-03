<?php

use PHPMailer\PHPMailer\PHPMailer;

// En las funcionalidades donde no queramos que retorne un error, usar la
// variable $notSendError=true, por ejemplo en procesos en segundo plano.
if (!isset($notSendError)) {
    $notSendError = false;
}
if (!isset($mailAllowOperationalBcc)) {
    $mailAllowOperationalBcc = false;
}

$mail = new PHPMailer(true);

try {
    $mailHost = trim((string) ($_ENV['MAIL_HOST'] ?? ''));
    $mailPort = filter_var(
        $_ENV['MAIL_PORT'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 65535]]
    );
    $configuredEncryption = trim((string) (
        $_ENV['MAIL_ENCRYPTION'] ?? ''
    ));
    $mailEncryption = $configuredEncryption !== ''
        ? strtolower($configuredEncryption)
        : match ($mailPort) {
            465 => 'smtps',
            587 => 'starttls',
            default => '',
        };
    $mailUsername = (string) ($_ENV['MAIL_USERNAME'] ?? '');
    $mailPassword = (string) ($_ENV['MAIL_PASSWORD'] ?? '');
    $configuredFromName = trim((string) ($_ENV['MAIL_FROM_NAME'] ?? ''));
    $mailFromName = $configuredFromName !== ''
        ? $configuredFromName
        : trim((string) ($_ENV['EMISOR_NAME'] ?? ''));

    if (
        $mailHost === ''
        || $mailPort === false
        || !in_array($mailEncryption, ['starttls', 'smtps'], true)
        || trim($mailUsername) !== $mailUsername
        || filter_var($mailUsername, FILTER_VALIDATE_EMAIL) === false
        || $mailPassword === ''
        || $mailFromName === ''
        || preg_match('/[\x00-\x1F\x7F]/', $mailFromName) === 1
    ) {
        throw new RuntimeException('Invalid project mail configuration.');
    }

    $mail->SMTPDebug = 0;
    $mail->isSMTP();
    $mail->Host = $mailHost;
    $mail->SMTPAuth = true;
    $mail->Username = $mailUsername;
    $mail->Password = $mailPassword;
    $mail->SMTPSecure = $mailEncryption === 'smtps'
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->SMTPAutoTLS = true;
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ],
    ];
    $mail->SMTPKeepAlive = false;
    $mail->Timeout = 15;
    $mail->Port = $mailPort;

    // La cuenta autenticada es siempre el remitente. Los destinatarios de
    // formularios (MAIL_ADMIN, MAIL_LAD...) nunca sustituyen el From.
    $mail->setFrom($mailUsername, $mailFromName, false);
    $mail->addAddress($destinatario, $nombreDestinatario); //var

    $mail->isHTML(true);
    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
    $mail->Subject = $asunto; //var
    $mail->Body = $cuerpo; // var
    if (isset($correoCopia)) {
        $mail->addCC($correoCopia);
        unset($correoCopia);
    }
    if ($mailAllowOperationalBcc === true) {
        foreach (['MAIL_LAD', 'MAIL_LAD_BIS'] as $bccEnv) {
            $bcc = trim((string) ($_ENV[$bccEnv] ?? ''));
            if ($bcc !== '') {
                $mail->addBCC($bcc);
            }
        }
    }
    $mail->AltBody = trim(html_entity_decode(
        strip_tags((string) $cuerpo),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    ));

    if ($mail->send() !== true) {
        throw new RuntimeException('Mail delivery failed.');
    }
    if ($notSendError) {
        echo "\n" . formattedDate(date("Y-m-d H:i:s"))
            . ": Correo enviado.\r";
    }
} catch (Throwable) {
    error_log('LiquidStack mail delivery failed.');
    if (!$notSendError) {
        $fallo = true;
        $mensaje = 'La consulta no se ha podido enviar. Inténtalo de nuevo.';
        $campo = "terminos_error";
        devolver_respuesta($mensaje, $fallo, $campo);
    } else {
        echo "\n" . formattedDate(date("Y-m-d H:i:s"))
            . ": Error al enviar el correo.\r";
    }
}
