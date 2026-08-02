# Autenticación y acciones de credencial de WebAdmin

WebAdmin dispone de un dominio de autenticación propio bajo su prefijo neutro
configurable (`/admin` por defecto). No reutiliza `PHPSESSID`, la sesión de la
zona privada legacy ni sus tablas. El runtime desplegable usa el perfil
MySQL/MariaDB configurado (`shared` o `liquidstack`); SQLite se conserva para
pruebas e inyección explícita.

`WebAdminHttpController` es la fachada estable consumida por el provider de
rutas. La orquestación interna se mantiene separada en coordinadores de
autenticación, acciones de credencial y gestión de editores; todos comparten
una única frontera de respuestas, cabeceras y cookies. Esta separación no
cambia las rutas ni permite que una pantalla eluda la política común.

Este corte incluye login, panel mínimo, logout, solicitud no enumerable de
recuperación, activación inicial mediante invitación y restablecimiento de
contraseña. La entrega asíncrona se describe en
[Correo y outbox de WebAdmin](webadmin-mail-outbox.md).

Las pantallas de credenciales usan la familia canónica de recursos
`artAuth01`, `moduleFormAuthLogin01`, `moduleFormAuthRecover01` y
`moduleFormAuthPassword01`. El showroom y las vistas LiquidStack pueden
componer esos recursos con sus controladores hidratables; el runtime privado
renderiza las mismas plantillas estructurales desde CORE, sin `data-lang`,
editor inline ni endpoints codificados en el recurso. De este modo el contrato
visual es reutilizable, mientras CSRF, acciones y feedback continúan bajo el
control del backend.

WebAdmin publica exclusivamente
`/assets/modules/webadmin/webadmin.css` y `webadmin.js` mediante el manifiesto
del módulo. El documento no contiene CSS o JavaScript inline y su CSP permite
`style-src 'self'` y `script-src 'self'`, manteniendo bloqueados el resto de
orígenes. El JavaScript solo controla la visibilidad de contraseñas; nunca
intercepta el envío ni replica validación de servidor.

## Preflight operativo

Antes de crear el runtime HTTP, WebAdmin exige:

- el selector `liquidstack/webadmin` activo y una configuración válida;
- la ruta neutral libre, la conexión PDO estricta y las migraciones
  fundacionales requeridas por autenticación aplicadas sin deriva;
- `LIQUIDSTACK_WEBADMIN_SECURITY_KEY` con 32 bytes aleatorios como base64url
  canónico de 43 caracteres;
- `zend.exception_ignore_args=On` en CLI y en el SAPI web, para impedir que
  contraseña, token o CSRF queden retenidos en trazas;
- soporte para la política productiva fija `argon2id-v1`: Argon2id con
  `memory_cost=19456`, `time_cost=2` y `threads=1`.

No existe degradación automática a bcrypt. `composer liquidstack:doctor`
comprueba estos requisitos sin revelar valores. Un fallo deja el prefijo en
`503` genérico y no crea el esquema ni cae en la sesión legacy. El gate de cada
request valida solo el contrato operativo acotado; la auditoría completa del
esquema corresponde a `doctor` y a
`composer liquidstack:migrate --dry-run`.

Las migraciones de funcionalidades posteriores se comprueban mediante gates
independientes. Que una versión nueva de CORE conozca, por ejemplo, la
biblioteca de medios sin que el proyecto haya aplicado aún esa migración no
inutiliza login, logout o gestión de editores: `doctor` lo informa como
ampliación pendiente y solo la ruta de esa funcionalidad permanece no lista.

HTTP y CLI comparten `ProjectEnvironmentLoader`. Las variables inyectadas por
el proceso prevalecen sobre `.env`, incluso si `variables_order` omite `E`. Un
`.env` ilegible o inválido bloquea WebAdmin antes de abrir PDO; no se utiliza
una vista parcial de la configuración.

En producción y en cualquier host no local, las rutas privadas continúan
exigiendo HTTPS afirmado por el servidor. El laboratorio admite una única
excepción acotada para el flujo habitual `npm run lad`: `DEV_MODE=1`,
`RAIZ=http://localhost:1309` —o el loopback canónico equivalente— y una
petición cuyo `Host` y puerto coincidan exactamente y cuyo `REMOTE_ADDR` sea
una representación válida de loopback. Si falta una de esas condiciones, HTTP responde `400` antes de abrir
PDO. `Forwarded` y `X-Forwarded-*` nunca habilitan la excepción.

El servidor integrado debe arrancarse con el router distribuido por CORE para
que también enrute endpoints dinámicos con extensión:

```bash
php -S localhost:1309 -t public App/tools/php-dev-router.php
```

El router devuelve al servidor únicamente ficheros reales dentro de `public`;
el resto pasa por `public/index.php` con `public` como directorio de trabajo,
preservando las rutas relativas del stack legacy. `npm run build` mantiene el
perfil de producción y no utiliza este router.

## Rutas HTTP

Las rutas siguientes se registran bajo el prefijo efectivo. Si el proyecto
configura `path => '/gestion'`, por ejemplo, `/admin` se sustituye por
`/gestion` en toda la tabla.

| Método | Ruta por defecto | Finalidad |
| --- | --- | --- |
| `GET`/`HEAD` | `/admin` y `/admin/` | Panel o redirección al login |
| `GET`/`HEAD` | `/admin/login` | Formulario de acceso |
| `POST` | `/admin/login` | Autenticación con CSRF |
| `POST` | `/admin/logout` | Revocación de sesión con CSRF |
| `GET`/`HEAD` | `/admin/password/forgot` | Formulario de recuperación |
| `POST` | `/admin/password/forgot` | Solicitud genérica no enumerable |
| `GET`/`HEAD` | `/admin/password/forgot/sent` | Confirmación genérica |
| `GET`/`HEAD` | `/admin/activate?token=…` | Vincular una invitación |
| `GET`/`HEAD` | `/admin/activate` | Formulario de contraseña inicial |
| `POST` | `/admin/activate` | Completar la activación |
| `GET`/`HEAD` | `/admin/password/reset?token=…` | Vincular una recuperación |
| `GET`/`HEAD` | `/admin/password/reset` | Formulario de nueva contraseña |
| `POST` | `/admin/password/reset` | Completar el restablecimiento |
| `GET`/`HEAD` | `/admin/action-unavailable` | Resultado genérico de enlace inválido |
| `GET`/`HEAD` | `/admin/login/activated` | Login tras activación |
| `GET`/`HEAD` | `/admin/login/password-reset` | Login tras restablecimiento |

Los `POST` aceptan únicamente
`application/x-www-form-urlencoded`, sin query string y con el conjunto exacto
de campos esperado. Las respuestas privadas usan `no-store`, `noindex`, CSP
restrictiva y `Referrer-Policy: no-referrer`.

`HEAD` es un probe no mutante: no crea ni desliza sesiones, no vincula tokens,
no consume acciones y no escribe rate limits o auditoría. Los formularios,
resultados y acciones devuelven `200` sin cuerpo cuando la frontera puede
atender el probe, aunque el `GET` equivalente pudiera redirigir según token.
La raíz anónima conserva su atajo `303` al login, también sin abrir PDO; con
cookie de sesión llega al probe vacío sin resolverla. Estas diferencias son
deliberadas: los monitores no deben sustituir operaciones o navegaciones reales
por `HEAD`.

## Cookies y separación de propósitos

Las tres cookies son host-only, `Secure`, `HttpOnly`, limitadas al prefijo
WebAdmin y nunca se aceptan de forma intercambiable:

En Chrome, `Secure` se mantiene también sobre el origen especialmente
confiable `http://localhost`; el laboratorio no rebaja flags de cookie. No se
debe sustituir `localhost` por un dominio arbitrario resuelto a `127.0.0.1`.

| Cookie | Propósito | SameSite |
| --- | --- | --- |
| `LS_WEBADMIN_SID` | Sesión autenticada; el nombre puede configurarse | `Strict` |
| `LS_WEBADMIN_PREAUTH` | Login y formulario de recuperación | `Lax` |
| `LS_WEBADMIN_ACTION` | Activación o restablecimiento ya vinculados | `Lax` |

Separar `LS_WEBADMIN_PREAUTH` impide que una navegación externa al formulario
reemplace la cookie autenticada `Strict`. `LS_WEBADMIN_ACTION` tampoco concede
acceso al panel: solo permite resolver una acción y su CSRF dentro de su flujo.
Una rotación de `LIQUIDSTACK_WEBADMIN_SECURITY_KEY` invalida los enlaces CSRF
de las sesiones existentes.

## Activación y recuperación

El correo contiene un token opaco de un solo propósito. La primera navegación
`GET` con `?token=` valida el token, lo vincula a una sesión de acción aislada,
envía `LS_WEBADMIN_ACTION` y responde `303` hacia la misma ruta **sin query**.
El formulario y el `POST` posterior trabajan solo con esa cookie y su CSRF. El
token bruto no se guarda en DB y no vuelve a aparecer en el HTML ni en la URL
limpia.

El edge o servidor web debe redaccionar el parámetro `token` en access logs,
APM y trazas de proxy: la redirección limpia la navegación posterior, pero la
petición inicial puede registrarse antes de que PHP responda. No se debe
inyectar el token en analítica, logs de aplicación ni mensajes de error.

Un enlace ausente, malformado, expirado, revocado, usado, de otro propósito o
para una identidad ya no elegible converge en el mismo resultado público. La
solicitud de recuperación también devuelve siempre la misma confirmación, con
independencia de que el correo exista, sea elegible, esté limitado o ya tenga
trabajo en cola. Los límites predeterminados son tres solicitudes por identidad
y veinte por IP en una hora, con bloqueo de una hora.

La contraseña se valida sobre su secuencia UTF-8 exacta: entre **15 y 1024
bytes**, UTF-8 válido, sin trim, normalización, truncado ni reglas de
composición. La contraseña puede contener emojis u otros caracteres
multibyte, por lo que el límite no equivale necesariamente a 15–1024
caracteres.

Una activación correcta establece la credencial, activa la identidad e invalida
acciones y sesiones incompatibles. Un restablecimiento incrementa
`auth_version` y revoca las sesiones autenticadas previas. Ninguno de los dos
flujos inicia sesión automáticamente: ambos redirigen a un formulario de login
limpio con un aviso genérico. Esto mantiene separada la prueba de posesión del
enlace de la creación de una sesión autenticada.

## Login, autorización y logout

- `openPreAuthenticationSession()` abre o reutiliza una preautenticación
  vigente y mantiene estable su CSRF entre pestañas.
- `authenticate()` valida preautenticación y CSRF, aplica límites por identidad
  e IP y devuelve siempre el fallo público
  `webadmin.authentication.failed`. Solo una credencial válida con
  `webadmin.access` crea sesión autenticada.
- `resolveAuthenticatedSession()` revalida expiración idle/absoluta,
  `auth_version` y el ciclo de vida completo: identidad activa, activación
  válida, ausencia de suspensión y credencial real con `password_set_at` y
  hash vigentes. Cualquier deriva revoca la sesión y falla cerrado.
- `WebAdminAuthorizationService` requiere el token secreto, consulta solo su
  SHA-256 y vuelve a comprobar ese mismo ciclo de vida, usuario, versión y
  capacidad contra DB en cada gate. Un ID público o DTO no autoriza.
- `logout()` solo revoca con sesión y CSRF válidos. Un `POST` bien formado usa
  el mismo `303` público exista o no la sesión.

Las sesiones contienen 32 bytes aleatorios. El CSRF se deriva por propósito
con HMAC-SHA-256 y en DB solo se guardan hashes SHA-256. Email e IP, al ser
identificadores de baja entropía, se pseudonimizan con HMAC para los rate
limits. La auditoría usa códigos estables y nunca persiste correo, contraseña,
token, CSRF, IP o user-agent en claro.

La política inicial de login permite cinco fallos por identidad y veinte por
IP en quince minutos; al llegar al umbral bloquea durante quince minutos. Un
login correcto limpia el contador de identidad, no el agregado de IP.

## TLS y proxies

`Request` solo confía en el estado que afirma el servidor local: `HTTPS`,
`REQUEST_SCHEME=https` o puerto local 443. Ignora `Forwarded`,
`X-Forwarded-Proto` y equivalentes. Si el TLS termina en un proxy, el virtual
host debe trasladar el estado **ya verificado** a una de esas variables; HSTS
pertenece al edge o virtual host.

Los rate limits usan exclusivamente `REMOTE_ADDR`. Detrás de un proxy se debe
configurar una capa como `mod_remoteip` para reescribirla solo cuando la
cabecera procede de proxies autorizados. Copiar directamente una cabecera
reenviada a PHP permitiría falsear la IP; no reescribirla haría que todos los
usuarios compartieran el bucket del proxy.

## Persistencia y concurrencia

SQLite usa `BEGIN IMMEDIATE`; MySQL/MariaDB usa transacciones, locks
`SELECT … FOR UPDATE`, prepares nativos y un reintento acotado para conflictos
transitorios. Toda mutación privilegiada debe revalidar sesión, usuario,
`auth_version` y capacidad dentro de su propia transacción para evitar TOCTOU.

La limpieza acotada elimina sesiones y rate limits vencidos. `audit_log` no se
purga silenciosamente: su retención requiere una política operativa y legal
explícita. Antes de publicar cambios de persistencia sigue siendo obligatorio
validar también la matriz opt-in MySQL/MariaDB sobre una DB aislada.
