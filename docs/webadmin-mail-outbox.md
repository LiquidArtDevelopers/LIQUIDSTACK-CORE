# Correo y outbox de WebAdmin

WebAdmin entrega invitaciones y recuperaciones mediante un outbox
transaccional y un comando CLI finito. Ningún request HTTP abre una conexión
SMTP y `composer update` nunca envía correo. El bootstrap y la solicitud de
recuperación solo crean trabajo `pending`; un scheduler ejecuta el dispatcher
de forma separada.

## Configuración SMTP productiva

El perfil `smtp` comparte la cuenta SMTP general del proyecto. Este es el
contrato canónico para configuraciones nuevas:

```dotenv
RAIZ=
DEV_MODE=
MAIL_HOST=
MAIL_PORT=
MAIL_ENCRYPTION=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_NAME=
```

`MAIL_ENCRYPTION` acepta exclusivamente `starttls` o `smtps`. El puerto debe
estar entre 1 y 65535; el timeout SMTP es de 15 segundos. `MAIL_USERNAME` debe
ser un email válido y cumple deliberadamente dos funciones inseparables:
autentica contra SMTP y es la dirección `From`. `MAIL_FROM_NAME` contiene solo
el nombre visible del remitente. En proyectos nuevos, `MAIL_ENCRYPTION` y
`MAIL_FROM_NAME` son declaraciones explícitas. Usuario, contraseña y nombre no
pueden estar vacíos ni contener caracteres de control. No se infieren host,
puerto, credenciales o dirección remitente a partir de defaults u otras
variables.

El origen público canónico procede exclusivamente de `RAIZ` y su perfil
`DEV_MODE`. Fuera del laboratorio debe ser HTTPS: esquema y host, puerto
opcional, sin credenciales, path, query ni fragment. HTTP solo es válido con
`DEV_MODE=1` y una `RAIZ` loopback canónica. No se deriva de `Host`,
`Forwarded`, `X-Forwarded-Host` o `X-Forwarded-Proto`; por tanto, un proxy o
request manipulado no puede cambiar el dominio de un enlace de credencial.

Los secretos pertenecen al gestor de secretos o al `.env` no versionado del
consumidor. No deben colocarse en `App/config/modules/webadmin.php`, el
repositorio, argumentos CLI, unidades de scheduler visibles o logs.

`MAIL_ADMIN`, `MAIL_LAD` y `MAIL_LAD_BIS` son destinatarios propios de los
formularios del proyecto. WebAdmin no los consulta: cada invitación o
recuperación se dirige únicamente al destinatario validado que contiene su
outbox, sin CC ni BCC. Ninguna de esas variables puede actuar como credencial
SMTP, remitente o copia de un enlace de acceso.

### Compatibilidad con el bloque general anterior

Los stacks existentes pueden tener `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`,
`MAIL_PASSWORD` y `EMISOR_NAME`, pero carecer todavía de las dos claves nuevas.
Para que una actualización no los rompa, el cargador aplica únicamente estas
equivalencias acotadas cuando falta la declaración correspondiente:

- `MAIL_PORT=465` equivale a `MAIL_ENCRYPTION=smtps`;
- `MAIL_PORT=587` equivale a `MAIL_ENCRYPTION=starttls`;
- si `MAIL_FROM_NAME` está ausente o vacío, el nombre visible procede de
  `EMISOR_NAME`.

No son defaults para proyectos nuevos. Un puerto distinto sin
`MAIL_ENCRYPTION`, o la ausencia de ambos nombres, falla cerrado. La
compatibilidad no permite obtener host, puerto, contraseña o dirección `From`
de destinatarios ni de otras variables.

### Compatibilidad con el bloque dedicado anterior

El perfil `smtp` admite temporalmente
`LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN`, `LIQUIDSTACK_WEBADMIN_SMTP_HOST`,
`LIQUIDSTACK_WEBADMIN_SMTP_PORT`,
`LIQUIDSTACK_WEBADMIN_SMTP_ENCRYPTION`,
`LIQUIDSTACK_WEBADMIN_SMTP_USERNAME`,
`LIQUIDSTACK_WEBADMIN_SMTP_PASSWORD`,
`LIQUIDSTACK_WEBADMIN_MAIL_FROM_ADDRESS` y
`LIQUIDSTACK_WEBADMIN_MAIL_FROM_NAME` para no romper instalaciones existentes.
No es el contrato recomendado para instalaciones nuevas.

La selección es cerrada y por bloque: si cualquier clave SMTP/FROM legacy está
presente y no vacía, WebAdmin exige el conjunto legacy completo, incluido
`LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN`. Nunca rellena una clave legacy ausente con
`MAIL_*`, ni mezcla host, credenciales o remitente de ambos espacios. El origen
legacy aislado puede conservarse temporalmente como alias de Blog sin cambiar
el transporte de correo. Para adoptar el contrato general se retiran las claves
SMTP/FROM legacy después de verificar que el bloque `MAIL_*` canónico está
completo. Los valores no se muestran en `doctor`, errores ni logs.

## Captura local tipada

El laboratorio puede recorrer la activación y recuperación reales sin usar
credenciales SMTP productivas. El modo es explícito y solo admite un capturador
SMTP sobre la interfaz loopback:

```dotenv
RAIZ=http://localhost:1309
DEV_MODE=1
LIQUIDSTACK_WEBADMIN_MAIL_TRANSPORT=local_capture_smtp
LIQUIDSTACK_WEBADMIN_SMTP_HOST=127.0.0.1
LIQUIDSTACK_WEBADMIN_SMTP_PORT=1025
LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN=
LIQUIDSTACK_WEBADMIN_SMTP_ENCRYPTION=
LIQUIDSTACK_WEBADMIN_SMTP_USERNAME=
LIQUIDSTACK_WEBADMIN_SMTP_PASSWORD=
LIQUIDSTACK_WEBADMIN_MAIL_FROM_ADDRESS=webadmin@example.test
LIQUIDSTACK_WEBADMIN_MAIL_FROM_NAME="Example WebAdmin dev"
```

En este perfil el origen de los enlaces procede exclusivamente de `RAIZ`.
`LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN`,
`LIQUIDSTACK_WEBADMIN_SMTP_ENCRYPTION`,
`LIQUIDSTACK_WEBADMIN_SMTP_USERNAME` y
`LIQUIDSTACK_WEBADMIN_SMTP_PASSWORD` deben quedar ausentes o vacíos. El
cargador exige a la vez
`DEV_MODE=1`, una `RAIZ` HTTP loopback canónica y un host SMTP `127.0.0.1` o
`[::1]`. Cualquier perfil de producción, origen remoto, credencial o petición de
TLS lo invalida antes de abrir PDO o construir el transporte. El SMTP
productivo mantiene obligatorios autenticación y STARTTLS/SMTPS.

El capturador conserva deliberadamente sus claves dedicadas de host, puerto y
remitente local. Un bloque general `MAIL_*` puede coexistir porque lo utilicen
formularios, pero WebAdmin lo ignora por completo en `local_capture_smtp`: no
puede convertir ese perfil en un relay remoto, activar autenticación ni añadir
CC/BCC.

Si una comprobación local necesita entrega externa real, se puede seleccionar
explícitamente `LIQUIDSTACK_WEBADMIN_MAIL_TRANSPORT=smtp` con el bloque general
completo, `DEV_MODE=1` y `RAIZ` loopback. El transporte conserva autenticación
y TLS reales, pero el enlace enviado apunta a `localhost` y solo puede abrirse
en la misma máquina. Este modo se limita a destinatarios controlados; Mailpit
sigue siendo el default para QA sin efectos externos.

Mailpit es el capturador recomendado, pero CORE solo depende del protocolo
SMTP local y no instala ni arranca herramientas del sistema. Para evitar que
la interfaz o los mensajes salgan de la máquina, se puede ejecutar el binario
con almacenamiento temporal y ambos listeners ligados a loopback:

```console
mailpit --smtp 127.0.0.1:1025 --listen 127.0.0.1:8025 --max 50 --max-age 24h --disable-version-check
```

No se configura relay ni forwarding. Antes del dispatch se comprueba
manualmente `http://127.0.0.1:8025`; después, una invocación explícita del
comando entrega al capturador el mensaje que contiene el enlace. No existe un
comando que imprima tokens: el valor bruto conserva su ciclo normal
outbox/mensaje/ACK y nunca aparece en la salida CLI. Al volver a
`DEV_MODE=0`, aunque el resto de claves permanezca en un `.env` local, el
dispatcher queda bloqueado.

Antes de habilitar la tarea:

```console
composer liquidstack:doctor
composer liquidstack:doctor --format=json
```

El informe separa `mail_ready` y `mail_blockers` de `runtime_ready` y
`bootstrap_ready`. Una configuración de correo ausente no debe abrir el
dispatcher, pero tampoco bloquea por sí sola el login o un bootstrap que solo
encola trabajo. `mail_ready` valida el transporte, no sustituye el preflight
completo: el comando exige además selector activo, entorno legible,
`zend.exception_ignore_args=On`, ruta efectiva segura, conexión PDO y esquema
WebAdmin aplicado. Tampoco prueba que el proceso SMTP local o remoto esté
escuchando; esa disponibilidad se verifica antes de consumir intentos del
outbox.

## Comando de despacho

```console
composer liquidstack:webadmin:mail:dispatch
composer liquidstack:webadmin:mail:dispatch --limit=50
composer liquidstack:webadmin:mail:dispatch --limit=20 --format=json
```

`--limit` indica cuántas filas como máximo se examinan en esa ejecución. Su
valor permitido es 1–100 y el default es 20. El comando es deliberadamente
one-shot: procesa un lote y termina; no implementa un daemon ni espera entre
reintentos.

Los códigos de salida son:

| Código | Significado |
| --- | --- |
| `0` | Lote vacío o todas las entregas examinadas quedaron confirmadas |
| `1` | Runtime bloqueado, fallo del comando o hubo retry, fallo permanente o fencing |
| `2` | `--limit` o `--format` inválido |

La salida, también en JSON, contiene solo contadores: examinados, reclamados,
enviados, reintentos, fallos permanentes y resultados cercados. Un código `1`
no autoriza a imprimir destinatarios, tokens, credenciales SMTP, DSN, SQL,
excepciones internas ni respuestas del servidor de correo.

## Programación one-shot

Ejemplo orientativo para un scheduler Unix cada minuto, usando el ejecutable y
la ruta reales del despliegue:

```cron
* * * * * cd /srv/example/current && /usr/local/bin/composer liquidstack:webadmin:mail:dispatch --limit=20 --format=json
```

En Windows se crea una tarea programada equivalente que arranque en la raíz
del proyecto y ejecute un único lote. El entorno de la tarea debe recibir las
variables mediante un almacén seguro; no se incrustan contraseñas en la línea
de comandos.

Conviene impedir solapamientos en el scheduler para mantener una operación
predecible. Aun así, los claims, leases y fencing protegen la consistencia si
coinciden dos workers. El scheduler debe registrar el código de salida y los
contadores seguros, alertar ante fallos repetidos y volver a invocar el comando
en el siguiente intervalo; no debe construir un bucle infinito alrededor de
una misma ejecución.

## Entrega, reintentos y leases

Cada claim se confirma en DB antes de contactar SMTP. En ese momento se crea
un token criptográfico de acción, se guarda solo su hash y el valor bruto vive
únicamente en memoria el tiempo necesario para construir y enviar el mensaje.
La invitación emitida es válida 72 horas; la recuperación de contraseña, 30
minutos.

El worker dispone de un lease de 300 segundos. Su lock secreto y el contador
de intento cercan el ACK y el registro de fallo: un worker atrasado no puede
confirmar ni modificar una fila reclamada de nuevo por otro proceso. Un lease
caducado se recupera como un nuevo intento; al alcanzar el máximo se convierte
en fallo terminal.

El máximo es de cinco intentos. Tras los cuatro primeros fallos se aplica este
backoff sobre `available_at`:

| Intento fallido | Espera mínima |
| --- | --- |
| 1 | 60 segundos |
| 2 | 300 segundos |
| 3 | 900 segundos |
| 4 | 3600 segundos |
| 5 | Fallo permanente; no se reintenta automáticamente |

Un destinatario ya no elegible, un locale no soportado o un mensaje imposible
se terminaliza sin bloquear el resto del lote. Una excepción SMTP usa el mismo
contrato de retry sin almacenar su diagnóstico privado.

## Semántica de entrega: al menos una vez

El outbox ofrece entrega **at-least-once**, no exactly-once. Existe una ventana
inevitable: SMTP puede aceptar el correo y el proceso puede caer antes de
persistir el ACK `sent`. Al caducar el lease, otra ejecución reintentará y el
destinatario podría recibir un duplicado.

Esta posibilidad no se elimina marcando la fila como enviada antes de SMTP,
porque entonces una caída perdería el correo. Los enlaces siguen siendo
seguros: los tokens están limitados por propósito, estado, versión y caducidad,
y su consumo es transaccional y de un solo uso. Operativamente se debe aceptar
el duplicado excepcional y no prometer entrega exactamente una vez.

## Logs y protección del token

- Mantener `zend.exception_ignore_args=On` también en el PHP del scheduler.
- No activar debug SMTP en producción ni encadenar excepciones del transporte
  a logs públicos.
- Registrar únicamente códigos estables y los contadores seguros del comando.
- Redaccionar `token` en access logs del edge, servidor web, CDN, WAF y APM
  para `/activate` y `/password/reset`.
- Evitar que query strings con token entren en analítica, trazas distribuidas,
  cabeceras `Referer`, tickets o alertas.

La primera petición con `?token=` responde `303` hacia una URL limpia y
establece una cookie de acción `HttpOnly`. Esa redirección limita la exposición
posterior, pero no puede borrar el access log que el edge haya escrito al
recibir la petición original; la redacción debe configurarse antes de abrir el
flujo a producción.

## Recuperación operativa

No se manipulan manualmente hashes, locks o estados del outbox. Ante fallos:

1. ejecutar `composer liquidstack:doctor --format=json` y corregir solo el
   bloqueador indicado, sin copiar valores al informe;
2. confirmar conectividad y política del proveedor SMTP fuera de los logs de
   aplicación;
3. dejar que `available_at` y el scheduler gestionen los intentos pendientes;
4. si una invitación bootstrap ya quedó `sent` sin activar o `failed` terminal,
   usar el reenvío explícito documentado en
   [Bootstrap inicial de WebAdmin](webadmin-bootstrap.md), y después despachar
   el nuevo lote.

No se usa `--resend-invites` para saltarse el backoff de filas aún `pending` o
`processing`, ni se reencola por SQL manual una recuperación de contraseña.
