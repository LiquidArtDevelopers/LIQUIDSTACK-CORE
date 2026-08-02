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
     huella histórica de CORE permiten reconocer que sigue intacto. La
     comparación normaliza los finales de línea para que LF/CRLF no convierta
     un fichero intacto en una falsa personalización. También considera
     equivalentes la ausencia de salto final y una o varias líneas vacías al
     final, sin normalizar el contenido interno.
   - Si un controlador, template, SCSS, JS o asset de un recurso contiene una
     personalización desconocida, se conserva el grupo completo del recurso.
     Así no se mezclan piezas de contratos incompatibles.
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
   catálogos de correo ES/EN/EU). El backend, las plantillas de email, el
   runtime legal, el footer y los logos son semillas: se instalan únicamente
   cuando faltan y cualquier variante local existente se conserva.
2. Se copian recursos frontend:
- `resources/js` -> `src/js/resources`.
- `resources/scss` -> `src/scss/resources`.
- `resources/img` -> `public/assets/img`.
- `resources/video` -> `public/assets/video`.

3. Se fusionan dependencias de `package.core.json` en el `package.json` del proyecto consumidor.
4. Se instala el watcher compartido de idiomas en
   `tools/liquidstack/vite/update-languages-plugin.mjs`. Si el proyecto
   conserva el bloque Vite legacy conocido, el instalador lo sustituye por el
   import del módulo sin alterar el resto de `vite.config.js` (puerto, entradas,
   plugins o build). Una configuración personalizada, enlazada o escrita como
   `vite.config.ts`/`.mjs`/`.cjs` se conserva intacta y requiere añadir
   manualmente el import y `createUpdateLanguagesPlugin(env)` a `plugins`.
   CORE nunca copia un `vite.config.js` completo sobre el consumidor.
5. Se sincroniza la guia base para agentes desde `.codex`:
   - `.codex/config.toml` se copia al proyecto solo si no existe. Una configuracion local existente nunca se sobrescribe.
   - Solo se consideran skills que sean subdirectorios directos de `.codex/skills` y contengan `SKILL.md`.
   - Las skills base se escriben siempre en `.codex/skills`, tambien en proyectos nuevos.
   - Cada carpeta de skill procedente de CORE es gestionada por CORE: sus archivos se actualizan y los archivos retirados de esa misma carpeta se eliminan.
   - Las skills locales hermanas, con nombres distintos a las de CORE, se conservan. Un manifiesto oculto `.liquidstack-core-skills.json` permite retirar unicamente carpetas que CORE gestionaba y que ya no existen en el origen.
   - Las skills base son autosuficientes y no dependen de guias `AGENTS_*.md`
     legacy en la raiz. Cada proyecto puede mantener un `AGENTS.md` minimo de
     compatibilidad y encapsular su contexto privado en skills locales con
     nombres propios.
   - Si una version previa de la sincronizacion dejo un manifiesto gestionado en `.agents/skills`, CORE retira solo esas copias antiguas y conserva las skills locales de ese directorio.
   - La sincronizacion rechaza destinos redirigidos mediante symlinks o junctions para no escribir ni borrar fuera del arbol real del proyecto.

La sincronizacion automatica anterior la realiza el plugin en los eventos
`post-install-cmd` y `post-update-cmd`. Los errores de la guia para agentes se
registran sin interrumpir Composer.

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
bootstrap, `migrate --apply` y `media:init` requieren confirmación explícita.
El dispatcher de correo procesa un lote finito ya encolado:

```bash
composer liquidstack:doctor
composer liquidstack:doctor --format=json
composer liquidstack:migrate --plan
composer liquidstack:migrate --dry-run
composer liquidstack:migrate --apply
composer liquidstack:media:init
composer liquidstack:media:init --yes --format=json
composer liquidstack:webadmin:bootstrap
composer liquidstack:webadmin:bootstrap --resend-invites
composer liquidstack:webadmin:mail:dispatch
composer liquidstack:webadmin:mail:dispatch --limit=20 --format=json
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
`--allow-destructive --backup-confirmed`; en JSON, `--apply` siempre requiere
`--yes`. Ninguna salida incluye credenciales, claves, correos, DSN, SQL ni
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
configurar entorno, ejecutar `doctor`, revisar `migrate --plan` y
`migrate --dry-run`, crear y comprobar un backup recuperable de DB y storage y,
tras autorización explícita, aplicar las migraciones; después se inicializa el
storage con `liquidstack:media:init`, se ejecuta el bootstrap, se repiten
`doctor` y el QA HTTP y, cuando corresponda, se despacha el outbox.
El bootstrap solo encola las
dos invitaciones iniciales. `--resend-invites` es una recuperación confirmada
para invitaciones bootstrap ya enviadas o fallidas de forma terminal; no
duplica filas `pending`/`processing` y tampoco envía el correo directamente.

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
crea ni fusiona `.env` o `App/config/modules/*.php`.

Adopción segura en un consumidor nuevo:

```bash
composer require liquidstack/blog
composer update liquidstack/core
composer liquidstack:doctor
composer liquidstack:migrate --plan
composer liquidstack:migrate --dry-run
```

Solo después de revisar el dry-run, disponer de un backup recuperable y
autorizar la mutación se ejecuta `composer liquidstack:migrate --apply`; a
continuación se hace el bootstrap. Cambiar de `shared` a `liquidstack` cuando
ya existen tablas o datos no los copia ni los adopta: exige un plan manual de
backup, traslado y verificación antes de cambiar la configuración.

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
verificador o al runtime; si el esquema no es exacto, se restaura o se prepara
una recuperación explícita.

El entorno operativo de WebAdmin necesita una clave base64url canónica de 32
bytes bajo `LIQUIDSTACK_WEBADMIN_SECURITY_KEY`. Puede generarse una vez con:

```bash
php -r "echo rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), PHP_EOL;"
```

Guárdala solo en el gestor de secretos o `.env` no versionado. La directiva
`zend.exception_ignore_args=On` debe estar activa tanto en el PHP de consola
como en el SAPI que sirve la web; reinicia el proceso correspondiente tras
cambiar `php.ini`.

La entrega de invitaciones y recuperaciones requiere además:

- `LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN`;
- `LIQUIDSTACK_WEBADMIN_SMTP_HOST`;
- `LIQUIDSTACK_WEBADMIN_SMTP_PORT`;
- `LIQUIDSTACK_WEBADMIN_SMTP_ENCRYPTION` (`starttls` o `smtps`);
- `LIQUIDSTACK_WEBADMIN_SMTP_USERNAME`;
- `LIQUIDSTACK_WEBADMIN_SMTP_PASSWORD`;
- `LIQUIDSTACK_WEBADMIN_MAIL_FROM_ADDRESS`;
- `LIQUIDSTACK_WEBADMIN_MAIL_FROM_NAME`.

El origen debe ser un origen HTTPS explícito; nunca se infiere de `Host` o
cabeceras `Forwarded`. El dispatcher está pensado como tarea one-shot de cron o
scheduler. Su contrato de leases, cinco intentos, backoff, entrega al menos una
vez y redacción de tokens se documenta en
[correo y outbox de WebAdmin](docs/webadmin-mail-outbox.md).

Para el laboratorio existe el perfil explícito
`LIQUIDSTACK_WEBADMIN_MAIL_TRANSPORT=local_capture_smtp`. Solo es válido con
`DEV_MODE=1`, `RAIZ` HTTP loopback y un capturador SMTP en `127.0.0.1` o `[::1]`;
usa `RAIZ` para los enlaces y no admite TLS, autenticación ni origen legacy.
Fuera de ese contrato falla antes de PDO y no modifica el SMTP productivo.
CORE no instala el capturador ni imprime enlaces de credencial; Mailpit puede
usarse como servicio externo ligado exclusivamente a loopback siguiendo la
guía de correo.

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
automático. La política de contraseña valida UTF-8 y entre 15 y 1024 bytes. El
contrato completo está en
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

### Liquid Blog: categorías y editor estructurado

El selector `liquidstack/blog` habilita el flujo editorial y activa WebAdmin
como dependencia. Cada artículo conserva un UUID estable y variantes
independientes por idioma con slug, H1, title SEO, description, extracto,
estado y versión de concurrencia. `0003_blog_categories` y
`0004_blog_category_capabilities` añaden categorías localizadas, asignaciones y
capacidades separadas. La UI vive bajo el prefijo WebAdmin efectivo
(`/admin/blog` por defecto), no permite borrado y exige retirar una variante
publicada antes de volver a editarla.

`0005_blog_structured_content` incorpora `/admin/blog/editor`, documento actual,
referencias de medios y revisiones inmutables. El JSON canónico v1 admite ocho
bloques controlados: párrafo, heading H2/H3, lista, callout, enlace, imagen,
YouTube y CTA. El H1 permanece separado y `body_text` se deriva en servidor.
Abrir un artículo legacy solo proyecta su texto en memoria; se adopta al guardar.
Cada restauración crea una revisión nueva y todas las escrituras conservan el
lock optimista.

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
    'sitemap_path' => '/blog-sitemap.xml',
    'public_article_view' => 'App/views/blog-article.php',
    'database' => [
        'connection' => 'liquidstack',
        'table_prefix' => 'ls_blog_',
    ],
];
```

`public_article_view` es opcional y aditivo. Debe apuntar mediante una ruta
relativa a un PHP regular y legible bajo `App/views`, sin traversal ni
symlinks. La vista recibe `$blogArticle` como view model tipado para componer el
shell, head, navegación, footer, tema y CSP del proyecto. Sus alternates SEO
incluyen solo traducciones publicadas; la navegación de idioma separada cae al
índice localizado cuando falta una variante. Si se omite, CORE conserva el HTML
standalone y carga su CSS neutral responsive gestionado.

Los idiomas deben coincidir exactamente con `App/config/langs.php`. Las rutas
estáticas del proyecto conservan prioridad; Blog resuelve las URLs de artículo
DB-backed solo después de que el router existente falle. El path base —por
ejemplo `/noticias`— puede seguir perteneciendo a una vista estática del
proyecto, mientras los descendientes publicados se sirven como
`/noticias/{slug}`. El endpoint exacto de sitemap es la excepción de
infraestructura: se resuelve antes del router multidioma y de la sesión legacy,
una vez descartadas rutas, ficheros y subrutas de showroom project-owned. Así
consulta producción en cada petición, admite hasta 50.000 URLs, no crea
`PHPSESSID` y nunca modifica `public/sitemap.xml` ni requiere un deploy al
publicar.

El ejemplo usa el perfil dedicado y presupone que WebAdmin declara también
`connection => liquidstack`. Si se omite la configuración de DB en ambos
ficheros, los dos conservan el default compatible `shared`.

Blog usa `RAIZ` como origen canónico: HTTPS en producción y HTTP solo cuando
`DEV_MODE=1` y el origen es un loopback exacto. El anterior
`LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN` se conserva como alias compatible, pero
si difiere en producción se mantiene temporalmente para no cambiar URLs durante
el update y `doctor` avisa hasta que se alinee con `RAIZ`. La `RAIZ` loopback
prevalece en desarrollo. Blog no necesita que SMTP esté configurado. Después
de activar el selector se deben revisar y aplicar las migraciones explícitas y
volver a ejecutar el
bootstrap idempotente de WebAdmin para garantizar las capacidades protegidas:

```bash
composer liquidstack:doctor
composer liquidstack:migrate --plan
composer liquidstack:migrate --dry-run
# Crear y verificar aquí un backup recuperable de DB y storage.
composer liquidstack:migrate --apply
composer liquidstack:media:init
composer liquidstack:webadmin:bootstrap
composer liquidstack:doctor
```

Después se realiza el QA HTTP de `/admin`, `/admin/media` y Blog antes de
despachar correo. Composer no ejecuta esos pasos ni toca la DB o el storage
durante un update. El contrato de
rutas, categorías, editor, revisiones, medios, estados y permisos está en
[Liquid Blog](docs/liquid-blog.md).

`blog-admin.css`, `blog-editor.js` y `blog-public.css` viven en
`modules/blog/published/assets` y se sincronizan hacia
`public/assets/modules/blog` mediante el manifiesto del módulo. No forman parte
del bundle general ni son configuración project-owned. `doctor` informa su
estado en `blog.assets`; si falta un destino gestionado o es inválido, incluye
el blocker `assets.missing_or_invalid`.

La frontera HTTP exige HTTPS fuera del laboratorio. `npm run lad` puede usar
HTTP únicamente con `DEV_MODE=1`, una `RAIZ` loopback, coincidencia exacta de
`Host` y puerto y un `REMOTE_ADDR` equivalente a loopback; no confía en `Forwarded` ni
`X-Forwarded-Proto`. Una petición insegura o malformada devuelve `400` antes
de abrir PDO. El script canónico arranca
`php -S localhost:1309 -t public App/tools/php-dev-router.php`, necesario para
que `/blog-sitemap.xml` llegue a CORE y para cargar el front controller desde
`public`, como requieren las rutas relativas legacy. `npm run build` sigue
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
El proyecto consumidor debe conservar esa entrada. El backend genérico se
siembra desde CORE solo si no existe y utiliza:

- `App/app/formContact.php`
- `App/app/_phpmailer.php`
- `App/class/_comprobaciones.php`
- `App/config/languages/_email/{es,en,eu}.json`
- `_formContactAdmin.html` y `_formContactUser.html`

Configuración mínima en el `.env` del consumidor:

```dotenv
MAIL_HOST=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_PORT=465
MAIL_WEB=
MAIL_ADMIN=
EMISOR_NAME=
DOMAIN=
DOMAIN_URL=
```

`MAIL_LAD` y `MAIL_LAD_BIS` son copias ocultas opcionales. Las credenciales,
destinatarios, idiomas habilitados y plantillas personalizadas siguen siendo
responsabilidad de cada proyecto.

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

## Guia de trabajo local (localhost:1309)

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

### Paso 3) Levantar Vite en puerto 1309

```bash
npm run dev -- --host localhost --port 1309
```

Si el script `dev` del laboratorio ya fija host/port, basta con `npm run dev`.

### Paso 4) Refrescar sincronizaciones cuando toque

En el proyecto laboratorio, ejecuta:

```bash
composer update liquidstack/core
```

Esto refresca stubs, recursos, dependencias frontend y guia para agentes. Los
comandos de sincronizacion selectiva solo estaran disponibles en el laboratorio
si se han declarado en el `composer.json` raiz como se muestra en la seccion
"Scripts Composer y paquete raiz".

## Publicacion de cambios del core

CORE incluye un comando interactivo que publica el commit y su etiqueta
anotada en una unica operacion atomica. Las preguntas se realizan mediante la
entrada interactiva nativa de Composer, tambien desde PowerShell en Windows:

Uso rapido desde PowerShell:

```powershell
cd C:\xampp\htdocs\__LIQUIDSTACK\LIQUIDSTACK-CORE

php tools/build-managed-file-history.php
git add .
git commit -m "Descripción del cambio"

composer release
```

No es necesario ejecutar antes `git push`: `composer release` sube
simultaneamente `main` y la etiqueta. El comando:

1. exige estar en `main` con el arbol de trabajo limpio;
2. actualiza las etiquetas y comprueba que `main` no vaya por detras de
   `origin/main`;
3. muestra las siguientes opciones patch, minor y major;
4. permite escribir otra version antes de continuar;
5. ejecuta `composer validate` y `composer test`;
6. muestra commit, remoto y etiqueta y pide confirmacion;
7. crea un tag anotado y ejecuta un `git push --atomic`;
8. elimina el tag local recien creado si el push falla.

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

- [Biblioteca de medios de WebAdmin](docs/mejoras-pendientes/webadmin-media-library.md):
  contrato ya implementado de uploads privados, AVIF responsive y consumo
  Blog, junto a los pendientes reales de ciclo de vida y nuevos formatos.
- [Hoja de ruta de WebAdmin y Liquid Blog](docs/liquid-blog-roadmap.md):
  estado de WebAdmin, Media, categorías y editor estructurado, y siguientes
  cortes de SEO, IA, indexación y futuro maquetador.
- [Promoción de la DB modular entre local y producción](docs/mejoras-pendientes/promocion-db-modulos-local-produccion.md):
  AIWA trabaja actualmente sobre XAMPP local; queda definido el contrato para
  proyectos que usen DB local o producción y el protocolo para cambiar de
  entorno sin modificar código, reutilizar secretos ni mover datos de forma
  implícita.
- [Auditoría de compatibilidad en proyectos consumidores](docs/mejoras-pendientes/auditoria-compatibilidad-proyectos-consumidores.md):
  protocolo obligatorio para probar las actualizaciones de CORE en AIWA,
  ARRO, un starter BASE limpio y el resto de consumidores antes de desplegar
  una versión estructural de forma general.
- [Autocompletado de recursos LiquidStack para VS Code](docs/mejoras-pendientes/autocompletado-vscode-recursos.md):
  propuesta de extensión propia para insertar controladores y completar sus
  opciones y slots públicos a partir de un índice generado por CORE.
