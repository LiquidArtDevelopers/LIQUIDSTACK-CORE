# Liquid Stack Core

`liquidstack/core` es el paquete comun para proyectos Liquid Stack.
Centraliza:

- Nucleo PHP (arranque, routing, helpers).
- Stubs backend reutilizables (`stubs/App`, `stubs/public`).
- Recursos frontend reutilizables (`resources/js`, `resources/scss`,
  `resources/img`, `resources/video`).
- Dependencias frontend minimas del core (`package.core.json`).
- Configuracion y skills base para agentes (`.codex`).

## Como sincroniza en proyectos cliente

Al ejecutar `composer install` o `composer update` en un proyecto que consume este paquete:

1. Se sincronizan los stubs y recursos de forma **aditiva por defecto**:
   - Un fichero nuevo de CORE se instala cuando todavía no existe en el
     consumidor.
   - Un fichero existente solo se actualiza si su estado registrado o una
     huella histórica de CORE permiten reconocer que sigue intacto. Ambas
     evidencias son acumulativas: un estado desfasado no convierte una copia
     histórica exacta de CORE en una personalización local. La comparación
     normaliza los finales de línea para que LF/CRLF no convierta
     un fichero intacto en una falsa personalización. También considera
     equivalentes la ausencia de salto final y una o varias líneas vacías al
     final, sin normalizar el contenido interno.
   - Si un controlador, template, SCSS, JS o asset de un recurso contiene una
     personalización desconocida, se conserva el grupo completo del recurso.
     Así no se mezclan piezas de contratos incompatibles.
   - Las altas, actualizaciones y fusiones JSON se preparan antes de modificar
     el consumidor. Las operaciones independientes usan transacciones de un
     fichero y las escrituras `managed_hash` agrupadas conservan su atomicidad
     conjunta. Un lock exclusivo por proyecto obliga a recargar primero el
     estado más reciente; cada operación mantiene además un journal y backups
     temporales en el mismo volumen. Si falla una
     promoción, restaura el grupo antes de actualizar estadísticas o estado; si
     PHP se interrumpe, la siguiente ejecución recupera el journal antes de
     planificar. Una restauración incierta detiene Composer y conserva la copia
     recuperable. Esto evita dejar, por ejemplo, un controlador nuevo con su
     template o SCSS anterior, también en Windows. Lock, journals y backups no
     entran en Git; solo `managed-files.json` sigue siendo estado versionable.
   - La sincronización nunca borra ficheros del consumidor.
   - Los JSON de idiomas se fusionan recursivamente: CORE añade claves y
     propiedades ausentes, pero no sustituye ningún valor existente, incluidos
     `""`, `null`, `false`, `0` y las colecciones vacías. La inserción conserva
     el formato y los finales de línea del catálogo; no reserializa el fichero
     completo.

   El resultado se registra en `.liquidstack/core/managed-files.json`. Este
   manifiesto pertenece al proyecto consumidor y debe versionarse para que las
   siguientes actualizaciones puedan distinguir con precisión los ficheros
   intactos de sus personalizaciones.

   La familia `moduleFormContact01/02/03` incluye además un backend de contacto
   genérico (`formContact.php`, transporte PHPMailer, comprobaciones y
   catálogos de correo ES/EN/EU). El backend, el runtime JS legal y la pareja
   controlador/template del footer se actualizan por huella cuando siguen
   siendo copias canónicas; el footer se aplica como un único grupo atómico.
   Las comprobaciones, plantillas y catálogos de email, estilos legales y logos
   son semillas que solo se instalan cuando faltan. Cualquier variante local
   no reconocida se conserva en ambos casos.
2. Se copian recursos frontend:
- `resources/js` -> `src/js/resources`.
- `resources/scss` -> `src/scss/resources`.
- `resources/img` -> `public/assets/img`.
- `resources/video` -> `public/assets/video`.

### Convención de variantes visuales

Un sufijo `vN`, como `art05v1`, identifica una variante visual independiente
derivada de un recurso anterior. No expresa la versión SemVer de CORE: cada
variante conserva controlador, template, SCSS y prefijo de idiomas propios,
puede evolucionar sin modificar el contrato del recurso original y no depende
de modificadores alojados en una vista consumidora.

3. Se fusionan dependencias de `package.core.json` en el `package.json` del proyecto consumidor.
4. Se sincronizan el supervisor local `App/tools/liquidstack-dev.mjs` y el
   router `App/tools/php-dev-router.php`. Cuando el script `lad` conserva una
   variante canónica reconocida, CORE lo migra a un único proceso gestionado
   que arranca PHP desde el puerto 1309 y Vite desde el 5173, avanzando hasta
   encontrar puertos libres. Un `lad` personalizado se preserva y requiere
   adoptar manualmente el supervisor. La adopción sustituye de forma
   quirúrgica las dos referencias Vite legacy conocidas de
   `App/includes/_globalHead.php` por `liquidstack_dev_vite_origin()`; un HEAD
   personalizado o ambiguo se conserva y deja la migración de `lad` pendiente
   hasta integrar manualmente el origen dinámico.
   El supervisor deriva además una identidad opaca y estable del directorio
   real del proyecto. WebAdmin la usa para separar sus tres cookies locales de
   las de otros stacks, con independencia de los puertos elegidos cada día.
5. Se instala el watcher compartido de idiomas en
   `tools/liquidstack/vite/update-languages-plugin.mjs`. Si el proyecto
   conserva el bloque Vite legacy conocido, el instalador lo sustituye por el
   import del módulo sin alterar el resto de `vite.config.js` (puerto, entradas,
   plugins o build). Una configuración personalizada, enlazada o escrita como
   `vite.config.ts`/`.mjs`/`.cjs` se conserva intacta y requiere añadir
   manualmente el import y `createUpdateLanguagesPlugin(env)` a `plugins`.
   CORE nunca copia un `vite.config.js` completo sobre el consumidor.
6. Se sincroniza la guia base para agentes desde `.codex`:
   - `.codex/config.toml` se copia al proyecto solo si no existe. Una configuracion local existente nunca se sobrescribe.
   - Solo se consideran skills que sean subdirectorios directos de `.codex/skills` y contengan `SKILL.md`.
   - Las skills base se escriben siempre en `.codex/skills`, tambien en proyectos nuevos.
   - Cada carpeta de skill procedente de CORE es gestionada por CORE: sus archivos se actualizan y los archivos retirados de esa misma carpeta se eliminan.
   - Las skills locales hermanas, con nombres distintos a las de CORE, se conservan. Un manifiesto oculto `.liquidstack-core-skills.json` permite retirar unicamente carpetas que CORE gestionaba y que ya no existen en el origen.
   - Las skills base son autosuficientes y no dependen de guias `AGENTS_*.md`
     legacy en la raiz. Cada proyecto puede mantener un `AGENTS.md` minimo de
     compatibilidad y encapsular su contexto privado en skills locales con
     nombres propios.
   - `liquidstack-github-actions` separa artefactos de código y estado runtime,
     protege Media frente a deploys destructivos y deriva cualquier promoción
     DB + Media al runbook distribuido de `liquidstack-module-operations`.
   - `liquidstack-content-localization` separa la traducción de catálogos
     estáticos del ciclo editorial de variantes Blog y preserva rutas, SEO,
     medios, taxonomías y estado de publicación.
   - Si una version previa de la sincronizacion dejo un manifiesto gestionado en `.agents/skills`, CORE retira solo esas copias antiguas y conserva las skills locales de ese directorio.
   - La sincronizacion rechaza destinos redirigidos mediante symlinks o junctions para no escribir ni borrar fuera del arbol real del proyecto.

La sincronizacion automatica anterior la realiza el plugin en los eventos
`post-install-cmd` y `post-update-cmd`. Los errores de la guia para agentes se
registran sin interrumpir Composer.
`--no-plugins`, `--no-scripts` y un dry-run no entregan estas actualizaciones.
Después de un update normal se debe comprobar la presencia de las skills
distribuidas necesarias y que el manifiesto
`.codex/skills/.liquidstack-core-skills.json` las declare como gestionadas.

### Planificación explícita de ficheros gestionados

El consumidor puede auditar y repetir de forma controlada la misma cola de
ficheros gestionados de CORE, runtime y módulos activos:

```bash
composer liquidstack:sync --plan
composer liquidstack:sync --dry-run
composer liquidstack:sync --dry-run --format=json
composer liquidstack:sync --apply --plan-hash=sha256:... --yes
```

- `--plan` enumera targets relativos, política y grupo sin evaluar acciones ni
  escribir el proyecto. El preflight puede comprobar si el contrato SCSS esta
  disponible para determinar el inventario aplicable.
- `--dry-run` evalua las acciones y entrega un `plan_hash` determinista. No
  crea locks o journals, no escribe targets ni actualiza
  `.liquidstack/core/managed-files.json`. El hash queda ligado al protocolo,
  al proyecto físico, a los destinos efectivos y a los preflights relevantes,
  sin publicar rutas absolutas.
- `--apply` exige tanto `--yes` como el hash revisado. El sincronizador vuelve
  a calcular el plan después de adquirir el lock y rechaza
  `sync.plan_changed` si origen, destino o estado han cambiado.

Antes de comparar el hash, `--apply` recupera cualquier journal interrumpido:
esa recuperación puede restaurar un backup o terminar una limpieza pendiente.
No aplica mutaciones nuevas de la cola con un hash obsoleto; si la recuperación
cambia la instantánea, devuelve `sync.plan_changed` y exige repetir el
`--dry-run`. Solo un journal externo en estado `prepared` necesita que siga
activa la misma configuración de destino para validar su binding y restaurar.
`committed` y `cleanup_pending` son terminales de limpieza: no reconstruyen la
cola ni vuelven a leer o modificar el destino ya confirmado.

El comando no ejecuta retires ni renames de lifecycle sobre destinos del
consumidor. Cubre exclusivamente las políticas
`managed_hash`, `install_if_missing` y `merge_json_additive` que ya usa el
sincronizador. El contrato aditivo de `src/scss/_config.scss`, la integracion
quirurgica de `vite.config.js`, las dependencias de `package.json` y la guia
`.codex` siguen siendo fases separadas de `composer install`/`update`. Si el
preflight devuelve `sync.scss_contract_not_satisfied`, ejecutar primero el
hook normal para reconciliar ese contrato y volver a generar el dry-run; el
comando no lo modifica de forma encubierta. También bloquea antes de escribir
si el estado, el historial, un catálogo JSON o el scaffold transaccional son
inválidos. Cualquier mutación dirigida a un override externo solo se ejecuta
cuando ese destino comparte filesystem con el journal del proyecto; un
destino externo en otro volumen devuelve
`sync.external_target_cross_device_unsupported`.

Son propiedad del proyecto y no se sobrescriben automáticamente las rutas,
el fichero `vite.config.js` completo, `src/scss/_config.scss`,
`src/scss/_global.scss`, los SCSS propios de páginas y las configuraciones
locales. Las vistas, idiomas de páginas y recursos adicionales con nombres
distintos también se conservan.

CORE puede migrar de forma quirúrgica el watcher legacy dentro de
`vite.config.js` únicamente cuando reconoce exactamente su implementación
histórica. Si la configuración no coincide con ese contrato, la preserva y
muestra las instrucciones para integrar el plugin manualmente.

## Scripts Composer y paquete raiz

Los scripts declarados en el `composer.json` de este repositorio solo estan
disponibles cuando `liquidstack/core` es el paquete raiz (por ejemplo, dentro
de un checkout de CORE):

```bash
composer test
composer test:mysql-integration
composer test:module-e2e
composer release
composer liquidstack-core:sync-resources
composer liquidstack-core:sync-frontend-deps
```

Composer no importa los scripts de una dependencia en el `composer.json` del
proyecto consumidor. En los clientes, `composer install` y `composer update`
activan las sincronizaciones automaticamente mediante el plugin. Si un cliente
necesita comandos manuales, debe declararlos expresamente en el
`composer.json` de ese proyecto raiz:

```json
{
  "scripts": {
    "liquidstack-core:sync-resources": "App\\Core\\Composer\\Installer::syncResources",
    "liquidstack-core:sync-frontend-deps": "App\\Core\\Composer\\Installer::syncFrontendDependencies",
    "liquidstack-core:sync-agent-guidance": "App\\Core\\Composer\\Installer::syncAgentGuidance"
  }
}
```

## WebAdmin y Blog como módulos internos

CORE es el único paquete físico. `liquidstack/webadmin` y `liquidstack/blog`
son selectores lógicos declarados por el proyecto consumidor; Blog activa
también WebAdmin como dependencia interna:

```bash
composer require liquidstack/webadmin
composer require liquidstack/blog
```

El atajo sin versión requiere que CORE ya esté instalado y que los plugins de
Composer estén activos. El fallback es añadir `:*`. Para actualizar el código
se sigue usando `composer update liquidstack/core`.

El plugin expone los comandos operativos en los proyectos consumidores.
`doctor`, `migrate --plan` y `migrate --dry-run` son de solo lectura;
bootstrap, onboarding, `migrate --apply` y `media:init` requieren confirmación
explícita.
La recuperación de contraseña intenta entregar su mensaje de forma síncrona y
no usa el outbox. El dispatcher de correo procesa únicamente un lote finito ya
encolado por los flujos de invitación:

```bash
composer liquidstack:doctor
composer liquidstack:doctor --format=json
composer liquidstack:sync --plan
composer liquidstack:sync --dry-run
composer liquidstack:migrate --plan
composer liquidstack:migrate --dry-run
composer liquidstack:migrate --apply
composer liquidstack:media:init
composer liquidstack:media:init --yes --format=json
composer liquidstack:webadmin:bootstrap
composer liquidstack:webadmin:bootstrap --resend-invites
composer liquidstack:webadmin:onboard --yes
composer liquidstack:webadmin:onboard --yes --format=json
composer liquidstack:webadmin:mail:dispatch
composer liquidstack:webadmin:mail:dispatch --limit=20 --format=json
composer liquidstack:blog:analytics:purge --yes
composer liquidstack:blog:analytics:purge --yes --format=json
composer liquidstack:blog:adopt-public-shell
composer liquidstack:blog:adopt-public-shell --apply --yes
```

`doctor` valida el catálogo, la selección, los providers tipados, la
configuración conocida, el entorno de seguridad y, con WebAdmin activo, abre
la conexión modular configurada para comprobar en solo lectura el registro de
migraciones, el esquema y sus postcondiciones. Su salida separa
`runtime_ready`, `bootstrap_ready` y `mail_ready`: el runtime exige además una
clave
operativa válida, `zend.exception_ignore_args=On` y soporte para la política
fija `argon2id-v1`; el bootstrap exige los dos correos iniciales, pero no esa
clave HTTP; el correo exige su origen público y transporte SMTP, pero no
bloquea por sí solo el login. El dispatcher sigue exigiendo módulo, trazas,
ruta, conexión y esquema operativos. `migrate --plan` sigue siendo
completamente offline y solo enumera metadatos. `--dry-run` compara el catálogo
con `ls_module_migrations`, pero no escribe. `--apply` muestra el plan, exige
`--yes` o confirmación interactiva y lo aplica con lock y verificación del
hash. Una migración destructiva requiere además
`--allow-destructive --backup-confirmed`. Sin ambos flags, `--apply` aplica
solo el prefijo no destructivo pendiente de cada módulo y deja las destructivas
con un aviso. Una migración posterior del mismo módulo también queda pendiente para
no saltarse el orden append-only; las migraciones seguras de otros módulos sí
pueden avanzar. En JSON, `--apply` siempre requiere `--yes`. Ninguna salida
incluye credenciales, claves, correos, DSN, SQL ni
mensajes PDO.

`liquidstack:media:init`, en su modo normal, no consulta ni modifica la DB, no
procesa imágenes y no cambia `.env`. Con WebAdmin activo, carga el entorno del
proyecto, valida la raíz privada y la inicializa de forma idempotente con su
marcador de ownership, su `.gitignore` interno y el área de staging. En texto
solicita confirmación si no se pasa `--yes`; la salida JSON exige siempre
`--yes`. Una raíz no vacía que
no tenga el marcador válido no se adopta automáticamente. La única excepción
es el procedimiento de upgrade legacy, expresamente solicitado con
`--adopt-existing --backup-confirmed --yes` y condicionado a una coincidencia
completa entre DB y filesystem.

El orden de una instalación nueva es: activar el selector, actualizar CORE,
configurar entorno y, con Blog activo, preparar las dependencias project-owned
de su shell público. Después se ejecuta el preflight de solo lectura
`liquidstack:blog:adopt-public-shell`, se genera el bundle con `npm run build` y
se comprueba que el manifest de producción contiene `src/js/blogArticle.js`.
Solo entonces se activa `public_article_view` con
`liquidstack:blog:adopt-public-shell --apply --yes` y se ejecuta `doctor`. A
continuación se revisan `migrate --plan` y `migrate --dry-run`, se crea y
comprueba un backup recuperable de DB y storage y,
tras autorización explícita, aplicar las migraciones; después se inicializa el
storage con `liquidstack:media:init` y se ejecuta obligatoriamente
`liquidstack:webadmin:onboard --yes`. Este último paso compone el bootstrap
idempotente con la entrega acotada de las dos invitaciones protegidas y verifica
que cada identidad esté activa o tenga una invitación aceptada por el
transporte y un token entregado. Solo entonces se repiten `doctor` y el QA HTTP.

El onboarding nunca forma parte de `composer install` o `composer update`, no
despacha otras filas del outbox y no reenvía implícitamente invitaciones
expiradas o fallidas de forma terminal. El bootstrap de bajo nivel continúa
limitándose a encolar. `--resend-invites` sigue siendo una recuperación
confirmada para invitaciones bootstrap ya enviadas o fallidas de forma
terminal; no duplica filas `pending`/`processing` y debe ir seguido de un nuevo
onboarding o del dispatcher explícito.

### Base de datos de los módulos

WebAdmin y Blog admiten dos perfiles lógicos de conexión:

- `shared` es el valor predeterminado compatible con proyectos existentes y
  reutiliza `BBDD_SERVER`, `BBDD_USER`, `BBDD_PASS` y `BBDD_NAME`;
- `liquidstack` es un opt-in explícito para una DB modular propia del proyecto
  y del entorno. Usa exclusivamente:

  ```dotenv
  LIQUIDSTACK_DB_HOST=<host>
  LIQUIDSTACK_DB_PORT=3306
  LIQUIDSTACK_DB_NAME=<database>
  LIQUIDSTACK_DB_USER=<user>
  LIQUIDSTACK_DB_PASSWORD="<secret>"
  LIQUIDSTACK_DB_CHARSET=utf8mb4
  ```

Los seis nombres son obligatorios al seleccionar `liquidstack`; la contraseña
no puede estar vacía y el único charset admitido es `utf8mb4`. Las credenciales
permanecen en el entorno o gestor de secretos y nunca en los ficheros PHP. Si
falta o es inválida una variable, CORE falla cerrado: no vuelve silenciosamente
a `shared`.

La selección vive en los ficheros project-owned de ambos módulos:

```php
// App/config/modules/webadmin.php
'database' => [
    'connection' => 'liquidstack',
    'table_prefix' => 'ls_webadmin_',
],

// App/config/modules/blog.php
'database' => [
    'connection' => 'liquidstack',
    'table_prefix' => 'ls_blog_',
],
```

Blog y WebAdmin deben declarar el mismo perfil porque comparten un único PDO,
el registro de migraciones y operaciones cross-scope. Una discrepancia bloquea
el diagnóstico, las migraciones y el runtime antes de escribir. Composer no
crea ni fusiona `.env` o `App/config/modules/*.php` durante `install` o
`update`. La única mutación asistida de esta configuración es el comando
explícito de adopción del shell Blog: limita su cambio a
`public_article_view`, exige `--apply --yes` y falla sin escribir ante una forma
dinámica o un valor incompatible.

Adopción segura en un consumidor nuevo:

```bash
composer require liquidstack/blog
composer update liquidstack/core
composer liquidstack:blog:adopt-public-shell
npm run build
composer liquidstack:blog:adopt-public-shell --apply --yes
composer liquidstack:doctor
composer liquidstack:migrate --plan
composer liquidstack:migrate --dry-run
```

Antes del preflight deben estar preparados los includes y entradas globales,
los catálogos activos y el head con metadata, nonce y política CSP/CookieLad
cuando corresponda. El build debe terminar correctamente y dejar en
`public/.vite/manifest.json` la entrada exacta `src/js/blogArticle.js` antes de
aplicar la configuración; hasta ese momento el renderer standalone continúa
siendo la salida pública segura.

Solo después de revisar el dry-run, disponer de un backup recuperable y
autorizar la mutación se ejecuta `composer liquidstack:migrate --apply`; a
continuación se completa el alta operativa con
`composer liquidstack:webadmin:onboard --yes`. Cambiar de `shared` a
`liquidstack` cuando ya existen tablas o datos no los copia ni los adopta:
exige un plan manual de backup, traslado y verificación antes de cambiar la
configuración.

El perfil dedicado inicial no configura TLS para MySQL/MariaDB. Es apto para
`localhost` o una red confiable; no debe conectarse a un host no confiable
hasta incorporar y validar CA y verificación del servidor. Nunca se deben
introducir DSN u opciones PDO libres en el entorno.

La precondición de la migración inicial se comprueba en `--dry-run` y otra vez
bajo lock antes de escribir. WebAdmin solo parte de un namespace totalmente
vacío y de una versión MySQL/MariaDB compatible. Si detecta una tabla, vista,
constraint o resto parcial devuelve `migration.precondition_failed`: no lo
adopta ni lo borra. `retrySafe` describe la idempotencia de cada sentencia
permitida, no una recuperación integral después de un DDL MySQL parcialmente
confirmado; ese estado requiere inspección, copia recuperable y resolución
manual antes de reintentar.

Un `migration.postcondition_failed` en una ampliación posterior requiere la
misma cautela aunque el siguiente dry-run la siga mostrando como `pending`:
MySQL/MariaDB puede haber confirmado su DDL. Solo se repite después de comparar
el estado real con el contrato y determinar que la corrección pertenece al
verificador o al runtime. En ese caso el planner solo desbloquea un superseder
no transaccional y `retrySafe` cuyo estado completo sea exacto y cuyos
supersedidos conserven registros íntegros; sigue pendiente hasta que un nuevo
`--apply` reejecuta el SQL idempotente, verifica y registra. Si el esquema no es
exacto, se restaura o se prepara una recuperación explícita.

El entorno operativo de WebAdmin necesita una clave base64url canónica de 32
bytes bajo `LIQUIDSTACK_WEBADMIN_SECURITY_KEY`. Puede generarse una vez con:

```bash
php -r "echo rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), PHP_EOL;"
```

Guárdala solo en el gestor de secretos o `.env` no versionado. La directiva
`zend.exception_ignore_args=On` debe estar activa tanto en el PHP de consola
como en el SAPI que sirve la web; reinicia el proceso correspondiente tras
cambiar `php.ini`.

El primer onboarding necesita además dos identidades canónicas distintas:

```dotenv
LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL=
LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL=
```

Sus valores son configuración project-owned y pueden inyectarse de forma
transitoria desde un perfil privado del operador o un gestor de secretos. CORE
no contiene direcciones personales, no las añade a `.env`, manifiestos o stubs
y no acepta contraseñas iniciales: cada destinatario establece la suya mediante
el enlace de activación. Cuando varios proyectos deban usar el mismo par
operativo, se reutiliza desde esa fuente privada, nunca copiándolo al paquete o
al repositorio.

La entrega de invitaciones y recuperaciones usa el bloque SMTP general del
proyecto en el perfil `smtp`:

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

`MAIL_USERNAME` es a la vez la identidad de autenticación y la dirección
`From`; `MAIL_FROM_NAME` es su nombre visible. `MAIL_ENCRYPTION` debe declarar
explícitamente `starttls` o `smtps` en proyectos nuevos. Para conservar stacks
anteriores, si falta esa clave solo se reconocen `MAIL_PORT=465` como `smtps` y
`MAIL_PORT=587` como `starttls`; si `MAIL_FROM_NAME` está ausente o vacío,
puede usarse `EMISOR_NAME` como nombre visible. Cualquier otro caso incompleto
falla cerrado.

No se usan `MAIL_ADMIN`, `MAIL_LAD` o `MAIL_LAD_BIS` como credenciales,
remitentes o copias: pertenecen a formularios. WebAdmin entrega cada mensaje
solo al destinatario validado por el flujo correspondiente —directo en una
recuperación, desde el outbox en una invitación— y no añade CC/BCC.

`RAIZ` es también el origen canónico de los enlaces: HTTPS fuera del laboratorio
y HTTP únicamente con `DEV_MODE=1` y loopback canónico. Nunca se deriva de
`Host` o cabeceras `Forwarded`. `LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN`, el bloque
dedicado anterior `LIQUIDSTACK_WEBADMIN_SMTP_*` y
`LIQUIDSTACK_WEBADMIN_MAIL_FROM_*` se admiten únicamente como compatibilidad.
La presencia no vacía de cualquier clave SMTP/FROM dedicada selecciona y exige
el bloque legacy completo, incluido su origen; nunca se completa ni mezcla por
campos con `MAIL_*`. Un `LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN` aislado que siga
sirviendo como alias de Blog no selecciona por sí solo ese transporte. Las
configuraciones nuevas deben usar el contrato general.

El dispatcher está preparado como tarea one-shot de un cron o scheduler
futuro. CORE no provisiona hoy esa tarea: hasta que cada producción adopte el
[pendiente operativo](docs/mejoras-pendientes/webadmin-mail-scheduler-produccion.md),
las invitaciones se despachan de forma explícita. El contrato de leases, cinco
intentos, backoff, entrega al menos una vez y redacción de tokens se documenta
en [correo y outbox de WebAdmin](docs/webadmin-mail-outbox.md).

Para el laboratorio existe el perfil explícito
`LIQUIDSTACK_WEBADMIN_MAIL_TRANSPORT=local_capture_smtp`. Solo es válido con
`DEV_MODE=1`, `RAIZ` HTTP loopback y un capturador SMTP en `127.0.0.1` o `[::1]`;
usa `RAIZ` para los enlaces y no admite TLS, autenticación ni origen legacy.
Fuera de ese contrato falla antes de PDO y no modifica el SMTP productivo.
CORE no instala el capturador ni imprime enlaces de credencial; Mailpit puede
usarse como servicio externo ligado exclusivamente a loopback siguiendo la
guía de correo. Este perfil conserva sus claves dedicadas de host, puerto y
remitente local e ignora el bloque `MAIL_*` aunque exista para formularios.

Cuando una prueba necesite entrega externa real, el laboratorio puede activar
de forma explícita `LIQUIDSTACK_WEBADMIN_MAIL_TRANSPORT=smtp` con su bloque
general, `DEV_MODE=1` y `RAIZ` loopback. El proveedor recibe el mensaje, pero
el enlace `localhost` solo funciona en la misma máquina de desarrollo; debe
usarse únicamente con cuentas controladas y no sustituye al perfil seguro por
defecto de Mailpit.

HTTP y CLI usan el mismo cargador: las variables inyectadas por el proceso
tienen prioridad sobre `.env`, con independencia de `variables_order`, y las
referencias `${NOMBRE}` se resuelven contra esa vista inmutable. Si el fichero
existe pero es ilegible o no se puede parsear, WebAdmin falla cerrado en vez
de usar una configuración parcial.

Con WebAdmin activo, su prefijo neutral se resuelve antes de cargar
`App/config/config.php`, roles, sesión o router multidioma legacy. El default
es `/admin`; puede configurarse en `App/config/modules/webadmin.php`, que sigue
siendo propiedad del proyecto. CORE analiza las claves literales de
`App/config/routes/get.php` y `post.php` sin ejecutar esos ficheros: una
colisión conserva la ruta existente y queda reflejada como bloqueador en
`doctor`. Si una clave se calcula, concatena o se añade mediante asignación a
un índice, el análisis se considera incompleto y WebAdmin tampoco reclama el
prefijo. Para activarlo, las rutas legacy deben declarar sus claves de forma
literal o disponer de un catálogo estático equivalente.

La ruta WebAdmin falla cerrada con `503` cuando el entorno, la DB o el esquema
no están preparados y nunca inicia la cookie legacy. Cuando el diagnóstico de
runtime está listo, sirve el acceso aislado bajo el prefijo neutral. La skill
base `liquidstack-module-operations` documenta el flujo operativo y se
sincroniza con las demás skills de CORE.

El acceso usa cookies separadas por propósito: la autenticada
`LS_WEBADMIN_SID` (`SameSite=Strict`), la preautenticación
`LS_WEBADMIN_PREAUTH` (`Lax`) y las acciones de credencial
`LS_WEBADMIN_ACTION` (`Lax`). Invitación y recuperación vinculan el token en
el primer `GET` y redirigen con `303` a una URL limpia; no crean login
automático. La política actual exige para las contraseñas nuevas un mínimo de
ocho caracteres
Unicode, con minúscula, mayúscula, número y signo, y conserva el máximo de 1024
bytes. El login no reaplica esas reglas de composición a hashes Argon2id
vigentes creados antes del cambio, por lo que las credenciales existentes no
quedan bloqueadas. Login, recuperación, activación y reset se componen con la
familia `artAuth02`/`moduleFormAuth*02`; el checklist del navegador refleja la
política y mantiene el backend como autoridad, con fallback HTML sin
JavaScript. El contrato completo está en
[autenticación de WebAdmin](docs/webadmin-authentication.md) y la operación
inicial en [bootstrap de WebAdmin](docs/webadmin-bootstrap.md).

Los administradores del sitio pueden gestionar editores desde `/admin/users`:
listado paginado, invitación asíncrona, reenvío, suspensión/reactivación y
asignación del subconjunto de capacidades activas que sean delegables y que el
propio actor posea. Las cuentas protegidas y el propio actor quedan fuera de la
superficie; SID, CSRF, versión, roles y capacidades se revalidan bajo lock en
cada mutación. El contrato de rutas, preservación de permisos, lifecycle,
outbox y auditoría se documenta en
[gestión de editores de WebAdmin](docs/webadmin-editor-management.md).

### Biblioteca de medios WebAdmin

`0002_webadmin_media_library` añade `/admin/media` sin cambiar el gate del
panel base. Acepta una imagen JPEG, PNG o WebP validada por firma y decoder,
genera variantes responsive AVIF sin metadatos y las conserva en storage
privado. `webadmin.media.view` y `webadmin.media.upload` son capacidades
separadas; ALT, title y caption pertenecen a cada uso editorial, no al asset.

Producción debe declarar una ruta absoluta y persistente mediante
`LIQUIDSTACK_WEBADMIN_MEDIA_STORAGE_ROOT`. El único default interno,
`storage/liquidstack/webadmin/media`, requiere `DEV_MODE=1` y `RAIZ` loopback.
El `.gitignore` creado dentro de esa raíz evita el tracking, pero no impide que
Actions, un sync con borrado o la rotación de releases eliminen su contenido.
Por ello el destino productivo debe quedar fuera del árbol reemplazable y el
workflow se revisa con la skill distribuida `liquidstack-github-actions`.
La preparación se autoriza expresamente con
`composer liquidstack:media:init`; para automatización controlada se usa
`composer liquidstack:media:init --yes --format=json`. El comando crea el
marcador `.liquidstack-webadmin-media`, un `.gitignore` interno y el área
privada de staging. Repetirlo sobre esa misma raíz es seguro; no adopta una raíz
no vacía sin marcador en el flujo normal ni acepta symlinks, junctions o
destinos peligrosos.

Una instalación anterior que ya contenga medios pero todavía no tenga marker
se adopta solo mediante el procedimiento excepcional y no interactivo
`composer liquidstack:media:init --adopt-existing --backup-confirmed --yes`.
Requiere WebAdmin y el esquema Media listos, adquiere el lock de cuota y exige
correspondencia bidireccional exacta entre DB y ficheros: claves canónicas,
bytes, SHA-256, MIME AVIF, staging vacío y ausencia de enlaces o entradas
extra. Solo después escribe el scaffold y el marker; cualquier diferencia
falla sin adoptar ni modificar el layout legacy. No se usa para una raíz nueva
o vacía y `--backup-confirmed` confirma un backup ya verificado, no lo crea.

DB y storage se respaldan como una unidad. Los eventos automáticos de
`composer install`/`composer update` distribuyen el código, la migración y los
assets module-managed, pero no ejecutan el comando, no crean tablas o
directorios, no procesan imágenes, no cambian `.env` ni mueven medios. El
contrato completo está en
[biblioteca de medios WebAdmin](docs/mejoras-pendientes/webadmin-media-library.md).

### Liquid Blog: categorías, etiquetas y editor estructurado

El selector `liquidstack/blog` habilita el flujo editorial y activa WebAdmin
como dependencia. Cada artículo conserva un UUID estable y variantes
independientes por idioma con slug, H1, title SEO, description, extracto,
estado y versión de concurrencia. `0003_blog_categories` y
`0004_blog_category_capabilities` añaden categorías localizadas, asignaciones y
capacidades separadas. La UI vive bajo el prefijo WebAdmin efectivo
(`/admin/blog` por defecto), permite duplicar como borrador y ofrece una
papelera recuperable bajo `blog.articles.delete`. No existe borrado permanente
de artículos: una variante publicada debe retirarse explícitamente antes de
enviarla a la papelera.

`0020`–`0025` añaden etiquetas localizadas por variante sin sustituir las
categorías. El editor conserva un input CSV SSR y lo mejora con pastillas
reactivas, guardado privado single-flight y CAS; cada variante admite de cero a
treinta etiquetas y `Publicar` las promociona atómicamente con documento,
medios y categorías. La búsqueda `q` incluye nombre y slug de etiquetas live
sin crear filtros, archivos o URLs nuevas, y el detalle las presenta como
metadatos informativos separados. La frontera es opcional: pendiente conserva
el Blog anterior; aplicada pero corrupta falla cerrada.

La analítica opcional del Blog es first-party, depende del consentimiento y no
persiste IP, User-Agent ni referrer. Cada evento parte de un grant efímero
firmado en el render SSR; el navegador no decide la URL, la localización ni el
identificador de vista que se contabiliza.

Las pantallas administrativas de gestión de WebAdmin y Blog comparten un shell
de ancho completo con navegación lateral filtrada por capacidades, un único `main` y un
inspector derecho opcional para herramientas contextuales. Los controles de
apertura y cierre solo sustituyen el flujo normal cuando el JavaScript ha
enlazado el shell; entonces mantienen `aria-expanded`, foco e `inert`
sincronizados. Sin JavaScript, navegación, contenido y formularios permanecen
utilizables en el flujo del documento. La administración usa una jerarquía
visual plana basada en espacio, tipografía, grid y fondos sobrios: no introduce
franjas, pseudoelementos o bordes laterales de acento como decoración. La vista
previa privada conserva un documento aislado para representar fielmente
`header` y `main`, siempre bajo autenticación, `no-store` y `noindex`.

`0005_blog_structured_content` incorpora `/admin/blog/editor`, documento actual,
referencias de medios y revisiones inmutables. El JSON canónico v1 histórico
admite ocho bloques controlados: párrafo, heading H2-H6, lista, callout, enlace,
imagen, YouTube y CTA. `0011_blog_layout_editor_v2` añade el modelo actual de
Section, Article y Contenedor con una a cinco columnas, anchos y presentación
tipados. Todo el contenido escrito nuevo pertenece a `Texto`, que integra
párrafos, H2-H6, listas, citas, destacados y enlaces inline; Enlace standalone
se proyecta a Botón y HTML mantiene una fuente HTML/CSS saneada. El lienzo
representa el `header` con su H1 y el `main` estructurado sin duplicar landmarks
ni emitir otro H1 en WebAdmin. El H1 permanece separado y `body_text` se deriva
en servidor.

Al crear una variante se elige expresamente uno de los locales activos que el
artículo todavía no utiliza, mostrando la ruta configurada en `public_paths`.
El locale queda estable durante la edición y la URL se compone exclusivamente
con ese path y el slug; el panel nunca inventa un prefijo como `/es`. Categorías,
etiquetas del mismo idioma y medios se gestionan desde el inspector: el catálogo
conserva visibles todos los assets ya referenciados por el documento aunque
hayan quedado fuera del tramo de medios recientes.

El formulario SSR sigue siendo el fallback. La mejora progresiva guarda con
`fetch`, pero solo acepta como éxito la redirección esperada al mismo editor y
origen. Un conflicto `409`, una validación `422`, una pérdida de autorización o
un fallo de red conservan el documento y los campos sin navegar; también se
avisa antes de abandonar cambios pendientes. Abrir un artículo legacy solo
proyecta su texto en memoria; se adopta al guardar. Cada restauración crea una
revisión nueva y todas las escrituras conservan el lock optimista. La traducción
asistida mediante IA permanece diferida: cada locale continúa siendo una
variante editorial independiente.

El editor exige `webadmin.media.view` junto a `blog.articles.view` o
`blog.articles.edit`, según la acción. Los uploads siguen perteneciendo a
`/admin/media` y requieren `webadmin.media.upload`. Las variantes AVIF se sirven
públicamente desde `/_liquidstack/blog-media/{uuid}/{width}.avif` solo si el
asset está referenciado por el documento actual de un artículo publicado. Los
artículos aún no adoptados conservan el renderer legacy de `body_text`.

La configuración opcional sigue siendo propiedad del proyecto en
`App/config/modules/blog.php`:

```php
<?php

return [
    'public_paths' => [
        'es' => '/noticias',
        'eu' => '/eu/albisteak',
        'en' => '/en/news',
    ],
    'public_index' => [
        'page_size' => 12,
        'pagination_paths' => [
            'es' => '/noticias/pagina/{page}',
            'eu' => '/eu/albisteak/orria/{page}',
            'en' => '/en/news/page/{page}',
        ],
    ],
    'sitemap_path' => '/blog-sitemap.xml',
    'sitemap_cache' => [
        'enabled' => false,
        'ttl_seconds' => 300,
    ],
    'public_article_view' => 'App/views/blog-article.php',
    'database' => [
        'connection' => 'liquidstack',
        'table_prefix' => 'ls_blog_',
    ],
];
```

El prefijo Blog admite como máximo 29 bytes: el límite se deriva de todos los
identificadores gestionados y reserva espacio para
`category_assignment_workspace_items` dentro de los 64 bytes de MySQL/MariaDB.
Los proyectos configurados con versiones hasta v1.23.0 que usaron un prefijo
de 30–46 bytes deben migrar explícitamente su namespace antes de actualizar;
CORE falla temprano y nunca trunca o renombra tablas en automático.

`public_index` configura el lote SSR y las rutas limpias de continuación;
esas rutas siguen declarándose en el router project-owned. El backend
reutilizable vive en `src/Core/Blog/PublicIndex` y Composer instala el soporte
de un solo require `App/app/_moduleBlogPublicIndex.php`. La extensión opcional
`App/config/modules/blog-public-index.php` queda reservada a una fuente
`preview` de desarrollo; si no existe preview, el fichero no es necesario. La
seguridad compartida del índice y los artículos se configura, cuando hace falta,
en `App/config/modules/blog-public.php`. La vista solo compone controladores incondicionales
sobre HTML neutro; cada recurso decide su estado vacío y trae su propia
presentación. El encabezado principal se inyecta en un recurso hero con raíz
`<header>` antes de `<main>`. Dentro de este, `sectionBlogCatalog01` aporta el
H2 del contexto y recibe búsqueda, categorías y resultados como slots hermanos;
los formularios quedan fuera de la frontera sustituible para conservar sus
listeners. `moduleBlogResults01` recibe en slots opcionales la colección,
paginación y archivo ya renderizados y posee el target singleton
`#blog-results[data-blog-results]`, evitando un wrapper funcional escrito a
mano en cada vista. Bajo ese catálogo, `moduleBlogGrid02`, Pagination01 y
Archive01 mantienen comportamiento y estilos sobre raíces siempre neutras, de
modo que el H2 exterior no contiene otra `section` ni otro `nav`. Las cards de
la rejilla son `<article>`/H3 y exponen un CTA `cta_label` localizado hacia la
misma URL pública; Results01 solo admite hijos `div` sin `role` y
Catalog01 conserva una segunda validación de la composición. La vista no lee
superglobals, abre PDO, construye consultas
ni emite cabeceras. GET y HEAD comparten estado HTTP/SEO, y la respuesta parcial
puede transportar el mismo documento SSR completo con
`Vary: X-LiquidStack-Partial` para la mejora progresiva.

Al activar Blog, CORE distribuye el scaffold neutral
`App/views/blog-article.php` y sus entradas `src/js/blogArticle.js` y
`src/scss/blogArticle.scss`. Se actualizan como una unidad mientras no hayan
sido personalizados; si cambia cualquiera, Composer preserva las tres piezas.
La vista incorpora el head dinámico, navegación, footer, smoother y carga global
del proyecto. No contiene marca, dominio, rutas ni copy de un cliente concreto.
El grupo de tres piezas no es autónomo: el preflight exige además los includes
y entradas globales project-owned, los catálogos de todos los locales activos y
un `_globalHead.php` compatible con `$pageMeta` y el nonce CSP.

`public_article_view` es opcional por compatibilidad y aditivo. Debe apuntar
mediante una ruta relativa a un PHP regular y legible bajo `App/views`, sin
traversal ni symlinks. La vista recibe `$blogArticle` y `$blogArticleShell` como contratos
tipados y comienza con el único require gestionado
`App/app/_moduleBlogPublicArticle.php`. El hook prepara las variables de shell,
catálogo y assets sin emitir HTML ni cabeceras. La vista compone head,
navegación, footer, tema y layout; la política configurada en
`App/config/modules/blog-public.php`, su CSP y su nonce pertenecen al
controlador y a la `Response`. La vista solo consume el nonce entregado y nunca
llama `header()` ni requiere seguridad local. Sus alternates SEO
incluyen solo traducciones publicadas; la navegación de idioma separada cae al
índice localizado cuando falta una variante. Si se omite, CORE conserva el HTML
standalone y carga su CSS neutral responsive gestionado, pero `doctor` muestra
el aviso `blog.public_shell` y propone
`composer liquidstack:blog:adopt-public-shell`. El comando es dry-run por
defecto y valida las dependencias del scaffold sin escribir. Mantener el
fallback standalone mientras se ejecuta `npm run build` y comprobar que
`public/.vite/manifest.json` contiene `src/js/blogArticle.js`; solo después se
puede usar `--apply --yes` para crear o completar de forma acotada la
configuración project-owned. Ejecutar `doctor` inmediatamente después de esa
activación. Si el shell carga CookieLad, el diagnóstico
exige además que su origen exacto esté autorizado en `script`, `style`, `image`
y `connect` por `App/config/modules/blog-public.php`; este fichero sigue siendo
project-owned y Composer no lo crea ni lo completa.

El hook expone también `$articleCategories`, `$articleTags` y el fragmento
saneado `$articleTaxonomiesHtml`. Una vista project-owned lo coloca dentro del
artículo, antes de `$articleMain`; `artBlogArticle01` realiza la misma
composición cuando se usa el recurso gestionado. Categorías y etiquetas se
omiten por separado cuando están vacías y nunca se convierten en enlaces a una
ruta que el proyecto no haya declarado.

`bodyHtml()` conserva por compatibilidad el cuerpo histórico completo, incluida
la portada. Las vistas nuevas deben colocar `headerHtml()` antes de `<main>` y
`mainHtml()` dentro de este; `headerMediaHtml()` permanece como proyección
compatible para shells anteriores. Los fragmentos están saneados y evitan
duplicar el hero o su medio destacado; los escalares se siguen escapando según
contexto.

Los idiomas deben coincidir exactamente con `App/config/langs.php`. Las rutas
estáticas del proyecto conservan prioridad; Blog resuelve las URLs de artículo
DB-backed solo después de que el router existente falle. El path base —por
ejemplo `/noticias`— puede seguir perteneciendo a una vista estática del
proyecto, mientras los descendientes publicados se sirven como
`/noticias/{slug}`. Un claim de prefijo puramente declarativo permite diferir
la sesión legacy sin construir el provider ni abrir PDO: si gana una ruta
estática, la sesión se inicia antes de renderizarla; solo el valor literal
`'session' => false` permite que una ruta estática situada dentro de ese
namespace permanezca sin sesión. Si el módulo no encuentra contenido, CORE
inicia la sesión antes del 404 legacy.

El endpoint exacto de sitemap y el prefijo fijo
`/_liquidstack/blog-media/` son fronteras pre-bootstrap: se resuelven antes del
router multidioma y de la sesión legacy, una vez descartadas rutas, ficheros y
subrutas de showroom project-owned. Así el sitemap consulta producción en cada
petición, admite hasta 50.000 URLs, no crea `PHPSESSID` y nunca modifica
`public/sitemap.xml` ni requiere un deploy al publicar. Los artículos públicos
resueltos en la fase tardía tampoco crean la sesión PHP ni degradan sus
cabeceras de caché.

La caché persistente last-known-good del sitemap es opt-in y permanece
desactivada en el ejemplo. Si se habilita, requiere el prefijo Blog completo
0001–0006 y el comando explícito
`composer liquidstack:blog:sitemap-cache:init`; en producción
se añade `--shared-storage-confirmed` y una raíz privada, persistente y
compartida en `LIQUIDSTACK_BLOG_SITEMAP_CACHE_ROOT`. Solo una conexión DB
clasificada como indisponible puede servir el snapshot vigente, marcado con
`X-LiquidStack-Sitemap-Source: stale-cache`; los demás fallos responden de forma
cerrada. Composer nunca inicializa esa raíz ni activa la capacidad. Véase el
[runbook LKG](docs/blog-sitemap-last-known-good-cache.md).

El ejemplo usa el perfil dedicado y presupone que WebAdmin declara también
`connection => liquidstack`. Si se omite la configuración de DB en ambos
ficheros, los dos conservan el default compatible `shared`.

Blog usa `RAIZ` como origen canónico: HTTPS en producción y HTTP solo cuando
`DEV_MODE=1` y el origen es un loopback exacto. El anterior
`LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN` se conserva como alias compatible, pero
si difiere en producción se mantiene temporalmente para no cambiar URLs durante
el update y `doctor` avisa hasta que se alinee con `RAIZ`. El alias aislado puede
coexistir durante esa transición con `MAIL_*` y no selecciona por sí solo el
correo legacy. Si existe además cualquier clave WebAdmin SMTP/FROM no vacía,
se exige entonces el bloque anterior completo. Para adoptar el correo canónico
se alinea primero `RAIZ` y se retiran las claves WebAdmin SMTP/FROM legacy. La
`RAIZ` loopback prevalece en desarrollo. Blog no necesita que SMTP esté
configurado.
Después de activar el selector se deben revisar y aplicar las migraciones
explícitas y volver a ejecutar el
bootstrap idempotente de WebAdmin para garantizar las capacidades protegidas:

```bash
composer liquidstack:doctor
composer liquidstack:migrate --plan
composer liquidstack:migrate --dry-run
# Crear y verificar aquí un backup recuperable de DB y storage.
composer liquidstack:migrate --apply
composer liquidstack:media:init
# Solo si sitemap_cache.enabled=true:
composer liquidstack:blog:sitemap-cache:init
composer liquidstack:webadmin:onboard --yes
composer liquidstack:doctor
```

Después se realiza el QA HTTP de `/admin`, `/admin/media` y Blog antes de
rendir la adopción por completada. El onboarding solo entrega invitaciones de
las dos cuentas protegidas; el dispatcher general continúa reservado al outbox
ordinario y a su scheduler. Composer no ejecuta esos pasos ni toca la DB, el
storage o SMTP durante un update. El contrato de
rutas, categorías, editor, revisiones, medios, estados y permisos está en
[Liquid Blog](docs/liquid-blog.md).

`blog-admin.css`, `blog-editor.js`, `blog-public.css` y `blog-public.js` viven en
`modules/blog/published/assets` y se sincronizan hacia
`public/assets/modules/blog` mediante el manifiesto del módulo. No forman parte
del bundle general ni son configuración project-owned. `doctor` expande ese
directorio y comprueba individualmente esos cuatro ficheros runtime en
`blog.assets`; si falta un destino gestionado o es inválido, incluye el blocker
`assets.missing_or_invalid`. La familia de recursos y sus hooks de showroom se
distribuyen selectivamente y no forman parte de este gate operativo.

La familia visual pública también es selectiva. Su fuente canónica vive bajo
`modules/blog/resources/project/` y el manifiesto publica, solo con
`liquidstack/blog`, `artBlogArticle01`, `moduleBlogArchive01`,
`moduleBlogFilters01`, `moduleBlogSearch01`, `moduleBlogCategoryBar01`,
`moduleBlogPagination01`, `moduleBlogResults01`, `sectionBlogCatalog01`,
`sectionBlogGrid01`, `sectionBlogList01`,
`sectionBlogFeatured01`, `sectionBlogRelated01`, `sectionBlogSlider01`,
`moduleBlogGrid02`, `sectionBlogSlider02` y `sectionBlogStack01`, junto a su
helper, el loader compartido y los hooks de showroom. Search01 y CategoryBar01
se combinan sobre el runtime GET/SSR preservando el estado mutuo; Pagination01
aporta enlaces SSR y un runtime progresivo que sustituye la región de
resultados sin recarga, conservando History API y el fallback nativo. List01
centra su columna. Grid02 ofrece rejilla regular o
bento, media `16:9`/`16rem`, título visible y CTA compacto sin sombra;
Slider01 y Slider02 trasladan Draggable, Inertia,
snap, wrap y autoplay
accesible de `artSlider01` a runtimes multiinstancia con movimiento reducido,
fallback scroll-snap y cleanup. Ambos priorizan una `thumbnail` explícita y
Slider02 acota su media a `16:9` con `object-fit: cover`. Stack01 aplica
ScrollTrigger solo a 3–8 cards cuando el viewport es apto y mantiene en
cualquier otro caso un listado vertical funcional. Cada recurso agrupa
controlador, template, SCSS y JS como una unidad gestionada; una copia local
desconocida se preserva y un proyecto core-only o WebAdmin-only no recibe esos
ficheros. Los ejemplos Matrix pertenecen exclusivamente al showroom, incluyen
una muestra SSR paginada de 4 sobre 10 y nunca sustituyen contenido de la DB.
El helper común mantiene una API estable y aditiva en su propio grupo, de modo
que una personalización de un recurso no congela los demás. Si el contrato
SCSS del consumidor no puede verificarse, CORE conserva los assets
autocontenidos del módulo pero aplaza conjuntamente esta familia visual y sus
hooks hasta una actualización posterior con `_config.scss` reparado.

`BlogPublicResourceQuery` permite los órdenes cerrados
`newest|oldest|updated`, mantiene Dummy excluido y proyecta las categorías de
todas las cards mediante una única consulta batch sin IDs ni N+1.
Una vista que solo necesita la colección reciente requiere
`App/app/_moduleBlogPublicCollections.php` como único soporte y usa
`$blogPublicCollections->latest($locale, $limit)`: recibe un view model
`ready|empty|unavailable` sin instanciar feeds, factories ni queries en la
vista. Una selección avanzada se encapsula en soporte backend propio.
`BlogPublicResourceBatch` entrega `items`, `has_next`, `next_offset` y una
`next_url` relativa a la raíz. El runtime module-owned
`src/js/modules/blog/blogCollectionLoader.js` mejora el enlace SSR con carga
HTML incremental manual o próxima al final, valida mismo origen y colección,
deduplica cards y conserva estados de carga, error, reintento y fin. El helper
visual acepta categorías y media explícitas, y el feed puede enriquecer cada
lote con miniaturas mediante un adaptador opcional entre Blog y WebAdmin. El
adaptador ejecuta como máximo dos `SELECT` constantes por lote: el primero lee
documentos y referencias; solo si de ahí resulta algún medio elegible, el
segundo carga sus variantes. Toma la portada o la primera imagen según el orden
del documento `CURRENT` publicado y no expone IDs ni introduce N+1. Solo proyecta
variantes AVIF válidas: `src` es la mayor disponible de hasta 900 px y `srcset`
queda ascendente; `sizes` pertenece al recurso, no al backend. Si el esquema o
el storage no están listos, o una card presenta datos corruptos, esa card sigue
siendo textual. El helper valida estrictamente `src`, `srcset` y `sizes` y aplica
el `sizes` propio de cada composición. Los 16 derivados AVIF de los cuatro
Dummy del showroom tienen su fuente gestionada por Composer en
`resources/img/dummy/responsive` —480, 899/900, 1800 y 2560 px— y se sincronizan
al consumidor. RESOURCE-001 forma parte de CORE versionado desde `v1.22.0`.
La QA funcional-visual en Chrome real está cerrada a 390, 768 y
1280 px, con filtros, paginación, sliders multiinstancia y geometría responsive
sin overflow ni errores de consola.

La frontera HTTP exige HTTPS fuera del laboratorio. `npm run lad` puede usar
HTTP únicamente con `DEV_MODE=1`, una `RAIZ` loopback, coincidencia exacta de
`Host` y puerto y un `REMOTE_ADDR` equivalente a loopback; no confía en `Forwarded` ni
`X-Forwarded-Proto`. Una petición insegura o malformada devuelve `400` antes
de abrir PDO. El supervisor canónico arranca PHP con
`App/tools/php-dev-router.php`, necesario para que `/blog-sitemap.xml` llegue
a CORE y para cargar el front controller desde `public`, como requieren las
rutas relativas legacy. PHP parte de 1309 y Vite de 5173, pero ambos avanzan
si su puerto está ocupado. Los orígenes elegidos se inyectan en los procesos
sin persistir esos puertos en los perfiles `.env*`; el `swap-env` inicial
mantiene su activación habitual de `.env.development`. `npm run build` sigue
aplicando el perfil de producción. Si existe un proxy, el virtual host debe
traducir de forma
verificada el estado TLS y configurar `REMOTE_ADDR` con una capa de proxies
confiables; WebAdmin usa esa dirección para el bucket agregado de rate limit.
Los fallos internos solo registran códigos estables y mantienen la respuesta
pública genérica.

`composer test:module-e2e` crea y retira un consumidor temporal para comprobar
el alta y baja reales de los selectores, el descubrimiento de los comandos y
que `doctor` y `migrate --plan` no mutan el consumidor. No forma parte de la
suite unitaria porque resuelve dependencias con Composer y puede necesitar red
o caché local.

`composer test:mysql-integration` es una prueba opt-in sobre una DB aislada
`liquidstack_core_test_*`. Ejecuta el runner, postcondición, semillas,
idempotencia, bootstrap, outbox/ACK, activación, login, reset, gestión de
editores, una carrera de identidad única y probes concurrentes de orden de
locks InnoDB. Con Blog activo cubre además sus dos scopes, CRUD localizado,
categorías, documentos y revisiones, publicación/retirada, medios, resolución
pública, sitemap, stale writes con dos PDO y rollback atómico de auditoría. No
contacta SMTP y limpia solo los objetos conocidos. Su contrato y variables
`LIQUIDSTACK_TEST_MYSQL_*` se documentan en
[integración MySQL/MariaDB de WebAdmin](docs/webadmin-mysql-integration-test.md).

La selección lee solo `require` del `composer.json` raíz. Retirar un selector
desactiva su registro, pero nunca elimina datos, medios, configuración ni
ficheros del cliente. El contrato completo y su estado de implementación están
en [arquitectura de módulos internos](docs/arquitectura-modulos-internos.md).

Variables de entorno soportadas:

- `STACK_CORE_RESOURCES_TARGET` (alias: `STACK_LIQUID_CORE_RESOURCES_TARGET`).
- `STACK_CORE_RESOURCES_IMG_TARGET` (alias: `STACK_LIQUID_CORE_RESOURCES_IMG_TARGET`).
- `STACK_CORE_RESOURCES_VIDEO_TARGET` (alias:
  `STACK_LIQUID_CORE_RESOURCES_VIDEO_TARGET`).

## Checklist: promover un recurso nuevo a CORE

Usa esta lista cada vez que subas un recurso nuevo al core.

### 1) Frontend del recurso

- Anadir JS en `resources/js`.
- Anadir SCSS en `resources/scss`.
- Si el recurso requiere imagenes:
- Dummies generales en `resources/img/dummy`.
- Iconos de sistema reutilizables en `resources/img/system`.
- Imagenes especificas del recurso en `resources/img/resources/<nombreRecurso>`.
- Logos genéricos de arranque en `resources/img/logos`; Composer solo los
  instalará cuando el proyecto todavía no tenga un fichero homónimo.
- Si requiere vídeo local reutilizable, añadir únicamente dummies y pistas
  genéricas en `resources/video`; los vídeos de cliente se mantienen fuera de
  CORE.

## Nota de estructura de imagenes

Esa estructura se conserva en destino. Ejemplo:

- Origen: `resources/img/resources/aniBackground01/*`
- Destino: `public/assets/img/resources/aniBackground01/*`

### 2) Registro del recurso en plantillas base del core

- Registrar el JS y su inicialización en
  `src/js/showroom/<categoria>.js`.
- Registrar el SCSS en `src/scss/showroom/<categoria>.scss`.
- `src/js/templates.js` y `src/scss/templates.scss` son únicamente el shell
  ligero del catálogo; no deben volver a acumular todos los recursos.

## Importante sobre `src/scss/_config.scss`, `src/scss/_global.scss` y `src/js/_global.js`

Esos archivos **no** se sustituyen por las copias de CORE en los proyectos
cliente. Solo se sincronizan de `src/`:

- `src/js/templates.js`
- `src/scss/templates.scss`
- `src/js/showroom/`
- `src/scss/showroom/`

Por tanto:

- Los archivos `_config.scss` y `_global.scss` del proyecto cliente no se
  pisan.
- CORE comprueba de forma quirúrgica el contrato de colores de
  `_config.scss`: añade únicamente declaraciones ausentes, con `!default`,
  dentro del bloque delimitado
  `liquidstack-core:scss-color-contract`. Nunca reemplaza valores existentes,
  elimina variables extra ni reescribe el resto del fichero.
- Si el config no es un fichero regular, no se puede leer o escribir, o su
  bloque delimitado está dañado, CORE omite la sincronización gestionada de
  ese ciclo antes de tocar los recursos; así no instala SCSS que todavía no
  pueda compilar en el consumidor.
- Los filtros SVG nuevos reutilizan los aliases legacy del proyecto
  (`filterColor02` y `filterColorSepia`) cuando existen, para conservar su
  identidad cromática.
- En este core `_config.scss` se mantiene como referencia para proyectos
  nuevos. Su contrato SCSS v2 contiene 42 variables y está documentado en
  `manifests/scss-config-contract-v2.json`.
- Las familias estándar son `color00` (blancos), `color01` (negros y grises),
  `color02` (corporativo principal), `color03` (corporativo secundario) y
  `color04` (terciario opcional), con variantes y filtros `colorNNSVG`.
- Un recurso distribuido por CORE solo puede consumir las familias
  `color00` a `color03`. `color04` y cualquier variable posterior quedan
  reservadas para temas y modificadores del proyecto.
- Los acentos que antes dependían de `color04` usan una custom property con
  fallback a `color02` para mantener contraste en configs legacy. El config
  v2 activa `color03` para esos acentos; cualquier proyecto puede
  sobrescribirlos sin ampliar su contrato Sass.
- Un valor de tema específico de un recurso debe exponerse como una custom
  property CSS con fallback a una variable del contrato. El consumidor puede
  modificarla desde el contexto que hidrata la vista.
- Los SCSS de páginas son siempre locales y no forman parte de la
  sincronización gestionada.

### 3) Backend/stubs del recurso

- Actualizar idiomas de templates en:
- `stubs/App/config/languages/templates/es.json`
- `stubs/App/config/languages/templates/en.json`
- `stubs/App/config/languages/templates/eu.json`
- `App/tools/update-languages.php <slug>` hidrata de forma aditiva: conserva
  claves, tipos y propiedades existentes, incluidos los vacíos intencionales.
  La retirada de entradas antiguas solo se activa expresamente con
  `--prune-unused` y exige revisar el diff antes de conservar el resultado. El
  comando valida primero todos los catálogos para evitar escrituras parciales y
  siempre informa por fichero si fue `Creado`, `Actualizado` o quedó
  `Sin cambios`.
- Anadir controlador en `stubs/App/controllers/<recurso>.php`.
- Anadir template en `stubs/App/templates/_<recurso>.html`.
- Si el recurso amplía la edición inline, actualizar conjuntamente
  `resources/js/_inlineEditor.js` y `stubs/App/app/updateLanguage.php`, y
  comprobar que este último sigue registrado en
  `Installer::syncProjectAssets()`.
- Registrar la composición en el parcial de su categoría dentro de
  `stubs/App/views/showroom/`. El shell `stubs/App/views/_showroom.php` solo
  se modifica cuando cambia la navegación o nace una categoría.
- Hacer que el encabezado principal del ejemplo contenga el identificador
  exacto del recurso para poder localizarlo con la busqueda del navegador. Si
  el encabezado se inyecta desde otro modulo, usar un indice independiente y
  mencionar los modulos relevantes en el rotulo.
- Conservar el lorem/Matrix de cuerpo e interiores y las imagenes dummy. No
  anadir un encabezado semanticamente falso dentro de un recurso visual que no
  lo tenga por contrato.
- Comprobar también `/templates`: `stubs/App/views/_templates.php` es el alias
  histórico que carga el mismo showroom.

### Recursos compuestos y edición inline

Los recursos `hero06` y `hero07` son escenarios visuales con raíz `<header>`;
no contienen un H1 fijo. Reciben respectivamente `{hero06-content}` y
`{hero07-content}` para poder combinarse con `moduleH1Type03`,
`moduleH1Type04` u otro módulo equivalente. Los módulos H1 tienen raíz `<div>`
y admiten `header_level`, de modo que no añaden landmarks innecesarios y
conservan el escalado relacional de encabezados.

`src/scss/_global.scss` registra esos módulos como referencia de CORE, pero
ese entrypoint está protegido y no se sobrescribe en los consumidores. Cuando
un proyecto use los módulos fuera del bundle `templates`, debe añadir sus
propios `@use` al `_global.scss` local.

La familia de CTA incluye `moduleButtonType02`, con icono de imagen editable y
fallback `arrow-forward-outline.svg`; `moduleButtonType03`, con transición
expansiva e icono decorativo CSS; y `moduleButtonType04`, con interacción
convencional. Los tres conservan el enlace y el copy como objetos `data-lang`.
`moduleButtonType04` preserva enlaces de raíz y admite atributos de enlace
opcionales desde el controlador, por ejemplo `target` y `rel`.

`moduleTable01` es un módulo atómico con tabla semántica, `caption`,
encabezados de columna y primera celda de fila con su `scope` correspondiente.
Admite entre 1 y 26 filas mediante `items` y entre 1 y 8 columnas mediante
`list_items`; cada celda es editable y la envolvente ofrece desplazamiento
horizontal accesible cuando la tabla no cabe en móvil.

`art30` admite `items` entre 0 y 4 y `benefits` entre 0 y 6. Con
`benefits => 0` oculta el banner. En desarrollo, cada ficha y beneficio forma
un grupo editable; un `Ctrl + doble clic` en el fondo del banner abre todos sus
iconos y encabezados.

`art32` conserva el contrato semántico y de contenido de `art02`, pero integra
la variante de cards en caja: relleno, sombra, iconos filtrados y CTA opcional.
Admite `items`, `{header-primary}`, `header_level` y los slots
`{a-button-primary}` a `{z-button-primary}`. Sus columnas se adaptan sin
depender de modificadores SCSS de una vista concreta.

`artVideo01` compone encabezado, contenido, CTA y vídeo en dos columnas, con
`media_position => start|end`. `artVideo02` ofrece la variante vertical: el
article ocupa el 80 % en escritorio y el 90 % en tablet, mientras que el vídeo
se limita al 60 % y pasa al 100 % con relleno en móvil. Ambos parten de H3,
admiten `header_level` o un `{header-primary}` externo y omiten wrappers vacíos.

`moduleVideo01` permite seleccionar YouTube o vídeo local desde el editor
inline. YouTube usa una fachada ligera: no solicita thumbnail ni crea iframe
antes de `cookie_social=true`, y monta el iframe únicamente después de pulsar
reproducir. El modo local admite WebM, MP4, poster y pistas VTT editables,
valida rutas y extensiones y recarga el elemento `<video>` al guardar.

### Formularios de contacto modulares

`moduleFormContact01`, `moduleFormContact02` y `moduleFormContact03` comparten
el mismo HTML accesible y el mismo runtime asíncrono; solo cambia su diseño.
Son módulos atómicos con raíz `div`, sin encabezado documental, ficha de
contacto ni mapa, para poder combinarlos con otros recursos.

Por defecto envían el contrato legacy mediante `POST /form`:

```php
'/form' => 'formContact.php',
```

`App/config/routes/post.php` pertenece al proyecto y CORE no lo sobrescribe.
El proyecto consumidor debe conservar esa entrada. El backend genérico utiliza:

- `App/app/formContact.php`
- `App/app/_phpmailer.php`
- `App/class/_comprobaciones.php`
- `App/config/languages/_email/{es,en,eu}.json`
- `_formContactAdmin.html` y `_formContactUser.html`

`formContact.php` y `_phpmailer.php` se sincronizan juntos por huella: una
versión histórica intacta recibe las correcciones de CORE, mientras cualquier
personalización local preserva ambos ficheros como una unidad. Las
comprobaciones, catálogos y plantillas de correo continúan siendo semillas que
solo se instalan cuando faltan.

Configuración mínima en el `.env` del consumidor:

```dotenv
MAIL_HOST=
MAIL_PORT=
MAIL_ENCRYPTION=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_NAME=
MAIL_ADMIN=
EMISOR_NAME=
DOMAIN=
DOMAIN_URL=
```

`MAIL_USERNAME` autentica contra SMTP y actúa como dirección `From`;
`MAIL_FROM_NAME` es el nombre visible. `MAIL_ENCRYPTION` se declara como
`starttls` o `smtps` en proyectos nuevos. Para stacks anteriores que aún no la
declaren, solo `465` equivale a `smtps` y `587` a `starttls`; si
`MAIL_FROM_NAME` está ausente o vacío, se conserva `EMISOR_NAME` como nombre
visible. `MAIL_ADMIN` es el destinatario del formulario y
`MAIL_LAD`/`MAIL_LAD_BIS` son copias ocultas opcionales: nunca son credenciales
ni remitentes y WebAdmin no las consume. `MAIL_WEB` queda reservada a backends
project-owned antiguos que aún dependan de ella y no debe usarse en
integraciones nuevas. Las credenciales, destinatarios, idiomas habilitados y
plantillas personalizadas siguen siendo responsabilidad de cada proyecto.

Este backend conserva compatibilidad con el flujo existente. No debe
considerarse todavía un sistema antispam endurecido: el reto aritmético se
resuelve en cliente y quedan pendientes para un refactor posterior CSRF,
limitación de frecuencia y validación server-side del consentimiento.

El editor inline sincronizado soporta fondos responsive, una imagen de fondo
única, colecciones de lista con icono opcional, medios de vídeo y grupos
compuestos opt-in. En `moduleList01` y en las listas editoriales nativas de los
recursos, `Ctrl + doble clic` sobre el propio texto abre todas las líneas del
bloque. La inicialización usa captura temprana, es segura frente a recargas HMR
y neutraliza listeners antiguos antes de que abran únicamente el `li`
pulsado. Su endpoint gestionado es `App/app/updateLanguage.php`; las rutas del
proyecto no se pisan, por lo que el consumidor debe conservar esta entrada en
su configuración POST:

```php
'/languages/update' => 'updateLanguage.php',
```

Cuando una imagen use variantes responsive, sus entradas relacionadas deben
seguir `<clave-base>_srcset01`, `<clave-base>_srcset02`, etc. y existir en
todos los idiomas. El editor las presenta junto a `src`, `alt` y `title` y
actualiza el atributo `srcset` sin recargar la página.

Al adoptar esta versión, Composer actualiza `App/app/updateLanguage.php` si
reconoce una copia intacta de CORE. Si el endpoint contiene una personalización
desconocida, conserva el grupo del editor inline para no mezclar contratos.

`src/js/_global.js` tampoco se sobrescribe. El proyecto debe conservar la
activación del editor:

```js
import initInlineEditor from "./resources/_inlineEditor.js";

initInlineEditor();
```

### Showroom canónico y ruta compatible

`_showroom.php` es el shell canónico del catálogo y `_templates.php` se
mantiene como alias para no romper los stacks que todavía acceden a
`/{lang}/templates`. La ruta padre muestra un índice ligero y cada categoría
vive en una subruta:

- `heroes`
- `particles`
- `gsap-specials`
- `common`
- `cards-grids`
- `media`
- `forms-interactive`
- `modules-sections`

Por ejemplo, si el proyecto registra `/es/showroom`, CORE resuelve
automáticamente `/es/showroom/media`. Solo acepta esas ocho categorías,
únicamente bajo una ruta padre ya registrada con `resources => templates` y
vista `_showroom.php` o `_templates.php`. No modifica `get.php` ni
`rutas.js`, y la misma regla sirve para `/es/templates/media`.

Cada parcial PHP vive en `App/views/showroom/_<categoria>.php`. Vite carga
dinámicamente solo el JS y SCSS de la categoría solicitada, por lo que visitar
el índice o un grupo no descarga el catálogo completo. El plugin de idiomas,
sin embargo, hidrata siempre el shell y todos los parciales para conservar un
único catálogo `templates`.

El shell, el menú y las descripciones usan claves
`showroom_catalog_*` del catálogo `templates`; así el cambio de idioma sin
recarga conserva también la categoría activa y recalcula todos los enlaces
del índice. El SSR consume las claves ya hidratadas desde `$GLOBALS` con
fallback por idioma, y `getMatchRouteByLang()` recompone la misma subruta para
que el selector de idioma funcione también sin JavaScript.

Las rutas legacy que mantienen `resources => templates` y
`content => showroom` también son compatibles: la aplicación carga primero el
catálogo `templates` como base y después `showroom` como override. Así conserva
el copy particular existente y completa de forma aditiva las claves nuevas de
controladores sin reescribir el JSON legacy.

El instalador sincroniza las vistas, pero deliberadamente no sobrescribe
`App/config/routes/get.php` ni `App/config/rutas.js`, porque contienen rutas
propias de cada proyecto. Para exponer también `/showroom`, registra en el
consumidor una ruta equivalente a esta para cada idioma:

```php
'/es/showroom' => [
    'resources' => 'templates',
    'content'   => 'templates',
    'view'      => '../App/views/_showroom.php',
],
```

En `App/config/rutas.js`, la ruta homóloga debe apuntar igualmente a
`templates`:

```js
'/es/showroom': 'templates',
```

Los proyectos pueden ampliar el catálogo sin personalizar el grupo gestionado:

- `App/views/showroom/_local.php` para composición PHP local, comprobando
  `$showroomCategory` antes de renderizar.
- `src/js/showroom/local/<categoria>.js` para inicialización local; ese módulo
  puede importar su SCSS propio.

CORE no distribuye ni elimina esos hooks locales.

#### Parámetros project-owned de `navMegamenu01`

El controlador conserva sus enlaces históricos por defecto, pero los proyectos
nuevos pueden componer una navegación pública sin copiar ni editar el stub:

```php
controller('navMegamenu01', 0, [
    'public_link_keys' => [[
        'link' => 'navMegamenu01_00_blog',
        'text' => 'navMegamenu01_00_blogText',
    ]],
    'show_private_access' => false,
    'offices' => [[
        'label' => 'Oficina de ejemplo',
        'tels' => ['+34 900 000 000'],
        'addr' => 'Dirección configurable',
        'map' => 'https://example.com/map',
    ]],
]);
```

`public_link_keys` es opcional y parte de `[]`. Cada elemento referencia dos
claves ya hidratadas del catálogo activo: `link` apunta a un objeto con `href`
y `title`, mientras `text` apunta a otro objeto con `text`. Las entradas
incompletas se omiten.
`show_private_access` vale `true` por defecto para no romper consumidores
existentes y, al establecerlo en `false`, oculta tanto login como enlaces de
sesión. `offices` parte siempre de `[]`; las sedes son datos del proyecto y cada
una declara `label`, `tels`, `addr` y `map`.

### 4) Dependencias NPM del recurso

Si el recurso necesita librerias nuevas (ejemplo `three`):

- Declararlas en `package.core.json`.

Reglas de fusion en proyecto cliente:

- Solo agrega paquetes faltantes.
- No borra paquetes del proyecto.
- No reemplaza versiones ya declaradas por el proyecto.

### 5) Validación antes de publicar

- Regenerar las huellas históricas después de cerrar los cambios gestionados:
  `php tools/build-managed-file-history.php`.
- Ejecutar `php -l` sobre los controladores y vistas añadidos.
- Validar los JSON de `templates` en todos los idiomas base.
- Compilar `src/scss/templates.scss` y comprobar los ocho chunks de
  `src/scss/showroom/`.
- Ejecutar `composer test`.
- Probar `/showroom` y `/templates` en un consumidor enlazado, sin usar como
  fixture un proyecto que tenga cambios locales coincidentes.

## Guia de trabajo local

Este repositorio no trae una app completa para renderizar por si solo.
La forma recomendada es trabajar con un proyecto laboratorio basado en `liquidstack_base` usando este core local enlazado.

### Paso 1) Preparar proyecto laboratorio

En el `composer.json` del proyecto laboratorio, usar repositorio `path` hacia este core local:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../LIQUIDSTACK-CORE",
      "options": { "symlink": true }
    }
  ]
}
```

Luego:

```bash
composer update liquidstack/core
```

Con `symlink: true`, los cambios que hagas en este repo se reflejan en el proyecto laboratorio.

### Paso 2) Instalar frontend del laboratorio

```bash
npm install
```

### Paso 3) Levantar el entorno local

```bash
npm run lad
```

El supervisor gestionado inicia conjuntamente el servidor PHP y Vite. Intenta
`http://localhost:1309` para la aplicación y `http://localhost:5173` para
Vite; si alguno está ocupado, avanza por 1310, 1311… o 5174, 5175… sin detener
el proceso que ya lo utiliza. La consola muestra los dos orígenes efectivos.
El integrador admite tanto los dos `src` dinámicos directos como la variante
del head público que los escapa con `$escapeMeta` y aplica
`$headScriptNonceAttribute` a ambos scripts. Exige exactamente dos usos
válidos; un head parcial o ambiguo se conserva y difiere la migración de
`lad`.

La identidad local de WebAdmin se deriva del directorio real del proyecto y
no del puerto. Por eso varios stacks pueden permanecer autenticados a la vez
en el mismo perfil de Chrome, y un proyecto conserva su namespace aunque en
otro arranque pase de 1309 a 1310 o a cualquier puerto posterior. Esta
identidad es interna, no se configura en `.env` y no cambia los nombres de
cookie usados fuera de `npm run lad`.

Para exigir puertos concretos, se pueden definir
`LIQUIDSTACK_DEV_APP_PORT` y `LIQUIDSTACK_DEV_VITE_PORT` antes de ejecutar el
script. Un override es exacto: un valor inválido o un puerto ocupado provoca
un error, no una búsqueda incremental.

```powershell
$env:LIQUIDSTACK_DEV_APP_PORT = '1315'
$env:LIQUIDSTACK_DEV_VITE_PORT = '5180'
npm run lad
```

Tras activar el perfil de desarrollo con el `swap-env` habitual, el supervisor
inyecta `RAIZ`, `LIQUIDSTACK_DEV_APP_ORIGIN` y
`LIQUIDSTACK_DEV_VITE_ORIGIN`, junto con la identidad opaca del proyecto,
únicamente en los procesos de esa ejecución; no
persiste los puertos seleccionados. Al interrumpir `npm run lad`, cierra su
servidor PHP y su instancia Vite sin finalizar servicios ajenos.

### Paso 4) Refrescar sincronizaciones cuando toque

En el proyecto laboratorio, ejecuta:

```bash
composer update liquidstack/core
```

Esto refresca stubs, recursos, dependencias frontend y guia para agentes.
`liquidstack:sync` se registra directamente mediante el `CommandProvider` de
CORE. Solo los aliases opcionales `liquidstack-core:*` necesitan declararse en
el `composer.json` raiz como se muestra en "Scripts Composer y paquete raiz".

## Publicacion de cambios del core

CORE incluye un comando interactivo que publica el commit y su etiqueta
anotada en una unica operacion atomica. Las preguntas se realizan mediante la
entrada interactiva nativa de Composer, tambien desde PowerShell en Windows:

Antes de publicar, mueve las entradas de `Unreleased` a una sección fechada
`## [X.Y.Z] - AAAA-MM-DD` de `CHANGELOG.md`. El siguiente bloque PowerShell
ejecuta el cierre completo, enseña el lote exacto antes del commit y delega el
tag y el push atómico en el comando canónico. Ajusta únicamente la versión, el
mensaje y, si existe una DB **TEST aislada**, el flag de integración MySQL:

```powershell
$CoreRoot = (git rev-parse --show-toplevel).Trim()
if ($LASTEXITCODE -ne 0 -or $CoreRoot -eq '') {
    throw 'Ejecuta este bloque desde un clon de liquidstack/core.'
}
Set-Location -LiteralPath $CoreRoot

$Version = 'vX.Y.Z'
$CommitMessage = 'tipo(ámbito): descripción'
$RunMySqlIntegration = $false

function Invoke-Checked {
    param([string]$Name, [scriptblock]$Command)
    & $Command
    if ($LASTEXITCODE -ne 0) {
        throw "$Name falló (exit $LASTEXITCODE)."
    }
}

if ((git branch --show-current).Trim() -ne 'main') {
    throw 'La release debe salir de main.'
}
git diff --cached --quiet
if ($LASTEXITCODE -eq 1) {
    throw 'Ya hay cambios staged; revísalos antes de continuar.'
}
if ($LASTEXITCODE -gt 1) {
    throw 'No se pudo comprobar el staging.'
}
if ($Version -notmatch '^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$') {
    throw 'Usa vX.Y.Z sin ceros iniciales.'
}
$Number = $Version.Substring(1)
$Heading = '^## \[' + [regex]::Escape($Number) + '\] - [0-9]{4}-[0-9]{2}-[0-9]{2}$'
if (-not (Select-String -LiteralPath 'CHANGELOG.md' -Pattern $Heading -Quiet)) {
    throw "Documenta antes ## [$Number] - AAAA-MM-DD en CHANGELOG.md."
}

Invoke-Checked 'Historial gestionado' {
    php tools/build-managed-file-history.php
}
Invoke-Checked 'Comprobación del historial' {
    php tools/build-managed-file-history.php --check
}
Invoke-Checked 'Contrato del manifest' {
    php vendor/bin/phpunit --configuration phpunit.xml.dist `
        --do-not-cache-result --filter ManagedFileManifestTest
}
Invoke-Checked 'Validación Composer' {
    composer validate --strict --no-check-publish
}
Invoke-Checked 'Suite CORE' { composer test }
Invoke-Checked 'E2E modular' { composer test:module-e2e }
if ($RunMySqlIntegration) {
    Invoke-Checked 'Integración MySQL/MariaDB' {
        composer test:mysql-integration
    }
}
Invoke-Checked 'Whitespace/diff' { git diff --check }

Invoke-Checked 'Staging' { git add -A }
Invoke-Checked 'Comprobación staged' { git diff --cached --check }
git status --short
git diff --cached --stat
if ((Read-Host 'Revisa el lote. Escribe PUBLICAR para crear el commit') -cne 'PUBLICAR') {
    git restore --staged .
    throw 'Cancelado; no se creó el commit ni el tag.'
}
Invoke-Checked 'Commit' { git commit -m $CommitMessage }
Invoke-Checked 'Release atómica' {
    composer release -- "--version=$Version"
}
```

`composer release` requiere un árbol completamente limpio. Repite por sí mismo
`composer validate` y `composer test`, pero no regenera el historial gestionado
ni ejecuta `test:module-e2e` o `test:mysql-integration`; por eso esas operaciones
aparecen antes del commit en el bloque completo.

No es necesario ejecutar antes `git push`: `composer release` sube
simultaneamente `main` y la etiqueta. El comando:

1. exige estar en `main` con el arbol de trabajo limpio;
2. actualiza las etiquetas y comprueba que `main` no vaya por detras de
   `origin/main`;
3. muestra las siguientes opciones patch, minor y major;
4. permite escribir otra version antes de continuar;
5. exige que `CHANGELOG.md` contenga la sección fechada de esa versión;
6. ejecuta `composer validate` y `composer test`;
7. muestra commit, remoto y etiqueta y pide confirmacion;
8. crea un tag anotado y ejecuta un `git push --atomic`;
9. elimina el tag local recien creado si el push falla.

Una vez completado `composer release`, el commit ya forma parte de
`origin/main` y tiene una etiqueta asociada. Para añadir cambios posteriores,
crea un commit nuevo; no uses `git commit --amend` sobre el commit publicado.
Si la rama local y `origin/main` aparecen como divergidas, no uses
`git pull --ff-only` ni `git push --force`: conserva primero una referencia de
respaldo y reconcilia el historial antes de volver a publicar.

Ejemplo desde la etiqueta historica `v1.4.01`:

```text
Ultima etiqueta: v1.4.01 (interpretada como v1.4.1)
Patch: v1.4.2
Minor: v1.5.0
Major: v2.0.0
```

Las nuevas etiquetas deben usar SemVer estable canonico `vX.Y.Z`, sin ceros iniciales. Para
elegir directamente el incremento minor o simular el proceso:

```bash
composer release -- --bump=minor
composer release -- --version=v1.5.0
composer release -- --version=v1.5.0 --dry-run
```

La primera vez, ejecuta `composer install` para disponer de la suite local.
`vendor`, `composer.lock` y la cache de PHPUnit estan ignorados en este
repositorio.

El webhook de Packagist configurado en GitHub recibe el evento `push`, por lo
que una etiqueta publicada aparece automaticamente como nueva version del
paquete. No hace falta crear una GitHub Release ni guardar un token de
Packagist en el repositorio.

Despues de publicar:

1. En cada proyecto cliente: `composer update liquidstack/core`.
2. Ejecutar instalacion frontend (`npm install`, `pnpm install` o
   `yarn install`) si se anadieron dependencias.

## Contratos y mejoras pendientes

- [Scheduler de correo de WebAdmin en producción](docs/mejoras-pendientes/webadmin-mail-scheduler-produccion.md):
  adopción futura y por proyecto del dispatcher one-shot para invitaciones;
  la recuperación de contraseña ya es síncrona y no depende de esa cola.
- [Notificaciones de Blog a suscriptores](docs/mejoras-pendientes/blog-notificaciones-suscriptores.md):
  dominio expresamente fuera del MVP actual, con consentimiento, campañas,
  outbox separado, lotes, límites, reintentos y cron futuro.
- [Biblioteca de medios de WebAdmin](docs/mejoras-pendientes/webadmin-media-library.md):
  contrato ya implementado de uploads privados, AVIF responsive y consumo
  Blog, junto a los pendientes reales de ciclo de vida y nuevos formatos.
- [Hoja de ruta de WebAdmin y Liquid Blog](docs/liquid-blog-roadmap.md):
  estado de WebAdmin, Media, categorías y editor estructurado, y siguientes
  cortes de SEO, IA, indexación y futuro maquetador.
- [Promoción de la DB modular entre local y producción](docs/mejoras-pendientes/promocion-db-modulos-local-produccion.md):
  el consumidor de referencia trabaja actualmente sobre XAMPP local; queda
  definido el contrato para
  proyectos que usen DB local o producción y el protocolo para cambiar de
  entorno sin modificar código, reutilizar secretos ni mover datos de forma
  implícita.
- [Auditoría de compatibilidad en proyectos consumidores](docs/mejoras-pendientes/auditoria-compatibilidad-proyectos-consumidores.md):
  protocolo obligatorio para probar las actualizaciones de CORE en dos
  consumidores de referencia, un starter BASE limpio y el resto de
  consumidores antes de desplegar
  una versión estructural de forma general.
- [Autocompletado de recursos LiquidStack para VS Code](docs/mejoras-pendientes/autocompletado-vscode-recursos.md):
  propuesta de extensión propia para insertar controladores y completar sus
  opciones y slots públicos a partir de un índice generado por CORE.
