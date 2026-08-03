---
name: liquidstack-module-operations
description: Activación, diagnóstico, actualización y desarrollo seguro de los módulos internos WebAdmin y Blog de LiquidStack. Usar cuando Codex deba ejecutar o revisar composer require/remove/update de liquidstack/webadmin o liquidstack/blog, configurar App/config/modules, comprobar /admin, interpretar liquidstack:doctor, preparar o revisar migraciones, validar adopción en un stack consumidor o modificar manifiestos/providers modulares en liquidstack/core.
---

# Operar módulos LiquidStack

## Mantener el modelo correcto

- Tratar `liquidstack/core` como único paquete, repositorio y release físicos.
- Tratar `liquidstack/webadmin` y `liquidstack/blog` como selectores lógicos declarados en el `require` directo del proyecto.
- Recordar que Blog activa WebAdmin por dependencia interna. WebAdmin puede existir sin Blog.
- No confundir WebAdmin con una zona privada legacy del cliente. No compartir sus rutas, tablas, endpoints, modelos, cookie o sesión.
- No editar `vendor/liquidstack/core` ni decidir módulos desde `composer.lock`, `replace`, `provide` o `InstalledVersions`.

## Activar o retirar un módulo

1. Comprobar el estado de Git y leer `composer.json` antes de mutar dependencias.
2. Usar uno de estos comandos desde el proyecto consumidor:

   ```bash
   composer require liquidstack/webadmin
   composer require liquidstack/blog
   ```

   Si el plugin instalado aún no normaliza el selector, usar explícitamente `:*`.
3. Actualizar el código físico con `composer update liquidstack/core`; actualizar CORE por sí solo no activa WebAdmin ni Blog.
4. Para desactivar, usar `composer remove` sobre el selector directo. Nunca borrar automáticamente tablas, usuarios, artículos, medios, configuración o assets conservados.
5. Revisar el resumen del sincronizador: un fichero project-owned o personalizado debe preservarse salvo que exista un contrato de versión gestionada reconocido.

No ejecutar `require`, `remove`, migraciones, commit, push o release si el usuario solo ha pedido una auditoría.

## Configurar WebAdmin

Usar opcionalmente `App/config/modules/webadmin.php`, propiedad del proyecto:

```php
<?php

return [
    'path' => '/admin',
    'database' => [
        'connection' => 'shared',
        'table_prefix' => 'ls_webadmin_',
    ],
    'session' => [
        'cookie_name' => 'LS_WEBADMIN_SID',
        'idle_ttl_seconds' => 1800,
        'absolute_ttl_seconds' => 28800,
    ],
];
```

- Mantener secretos fuera de este fichero. Composer no debe crearlo, fusionarlo ni sobrescribirlo.
- Conservar `shared` como default compatible: reutiliza `BBDD_SERVER`,
  `BBDD_USER`, `BBDD_PASS` y `BBDD_NAME`.
- Usar `liquidstack` solo como opt-in explícito para una DB modular por proyecto
  y entorno. Requiere `LIQUIDSTACK_DB_HOST`, `LIQUIDSTACK_DB_PORT`,
  `LIQUIDSTACK_DB_NAME`, `LIQUIDSTACK_DB_USER`,
  `LIQUIDSTACK_DB_PASSWORD` y `LIQUIDSTACK_DB_CHARSET=utf8mb4`.
- No aceptar DSN ni opciones PDO libres. Una variable dedicada ausente o
  inválida debe fallar cerrada, nunca volver silenciosamente a `shared`.
- Cuando Blog esté activo, exigir que Blog y WebAdmin declaren la misma
  conexión. Comparten un único PDO y operaciones cross-scope; una discrepancia
  bloquea diagnóstico, migraciones y runtime antes de conectar.
- Tratar `.env` y `App/config/modules/*.php` como project-owned. Cambiar el
  perfil con tablas o datos existentes requiere backup, migración y
  verificación manual; Composer no traslada ni adopta datos.
- Limitar por ahora `liquidstack` a `localhost` o redes confiables. No usarlo
  contra un host no confiable hasta disponer de TLS con CA y verificación del
  servidor.
- Guardar `LIQUIDSTACK_WEBADMIN_SECURITY_KEY` únicamente en el entorno o gestor
  de secretos: debe contener 32 bytes aleatorios como base64url canónico de 43
  caracteres. No reutilizar una contraseña ni registrar su valor.
- Exigir `zend.exception_ignore_args=On` en CLI y en el SAPI web antes de
  habilitar autenticación.
- Exigir soporte Argon2id para la política productiva fija `argon2id-v1`; no
  sustituirla automáticamente por bcrypt según el host.
- Exigir en toda creación, activación o restablecimiento un mínimo de ocho
  caracteres Unicode, una minúscula, una mayúscula, un número y un signo,
  UTF-8 válido y un máximo de 1024 bytes. Mantener separada la validación de
  login: una credencial existente usa una entrada UTF-8 no vacía y acotada,
  sin reaplicar composición antes de verificar el hash vigente. No forzar un
  reset ni bloquear usuarios legacy únicamente por este cambio de política.
- Mantener sincronizados el copy, el `minlength`, los seis estados de
  `moduleFormAuthPassword02`, el JavaScript standalone de WebAdmin y
  `PasswordPolicy`. El checklist solo aporta feedback y no puede añadir
  controles con `name`, exponer el token ni sustituir la validación PHP; el
  submit debe nacer habilitado en HTML para conservar el fallback sin JS.
- Servir WebAdmin por HTTPS fuera del laboratorio. La única excepción HTTP
  exige simultáneamente `DEV_MODE=1`, `RAIZ` con origen loopback canónico y
  coincidencia exacta de `Host` y puerto; `REMOTE_ADDR` debe representar el
  mismo peer loopback, incluida su forma IPv6 válida. El request no confía
  en cabeceras `Forwarded`; detrás de un proxy, configurar el servidor para
  afirmar el TLS ya verificado y reescribir `REMOTE_ADDR` solo desde proxies
  autorizados.
- En el servidor integrado usar `php -S localhost:1309 -t public
  App/tools/php-dev-router.php`; sin el router, rutas dinámicas con extensión
  como `/blog-sitemap.xml` pueden no llegar a CORE. No alterar por ello el
  perfil ni el flujo de `npm run build`. El router debe cargar el front
  controller con `public` como directorio de trabajo para conservar las rutas
  relativas legacy.
- Reservar `LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL` y `LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL` para el bootstrap explícito. No mostrar sus valores.
- En el perfil `smtp`, tomar el origen de enlaces exclusivamente del perfil
  tipado `RAIZ` + `DEV_MODE` y configurar la cuenta de correo con el bloque
  general `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USERNAME`,
  `MAIL_PASSWORD` y `MAIL_FROM_NAME`. Fuera del laboratorio, `RAIZ` debe ser
  un origen HTTPS explícito; HTTP solo es válido con `DEV_MODE=1` y un origen
  loopback canónico. `MAIL_USERNAME` autentica contra SMTP y es también la
  dirección `From`; `MAIL_FROM_NAME` es únicamente su nombre visible. En
  proyectos nuevos, exigir `MAIL_ENCRYPTION` (`starttls` o `smtps`) y
  `MAIL_FROM_NAME` de forma explícita. Para adoptar sin ruptura un bloque
  general anterior que no tenga esas claves, admitir solo estas equivalencias
  acotadas: puerto `465` implica `smtps`, puerto `587` implica `starttls` y la
  ausencia o valor vacío de `MAIL_FROM_NAME` permite usar `EMISOR_NAME`.
  Cualquier otro puerto sin cifrado explícito o nombre ausente/inválido debe
  fallar cerrado. No inferir host, puerto, dirección remitente ni credenciales,
  y nunca derivar el origen de `Host` o cabeceras `Forwarded`.
- Tratar `MAIL_ADMIN`, `MAIL_LAD` y `MAIL_LAD_BIS` exclusivamente como
  destinatarios de formularios. WebAdmin obtiene el destinatario de la
  identidad canónica validada —desde el outbox para invitaciones y desde la
  solicitud elegible para recuperación— y no añade CC/BCC; esas variables no
  pueden autenticar SMTP, definir el remitente ni recibir invitaciones o
  enlaces de recuperación por copia.
- Admitir `LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN`, el bloque dedicado
  `LIQUIDSTACK_WEBADMIN_SMTP_*` y `LIQUIDSTACK_WEBADMIN_MAIL_FROM_*` solo como
  compatibilidad del perfil `smtp`. Si cualquier clave SMTP/FROM legacy está
  presente y no vacía, exigir el bloque legacy completo —incluido su origen— y
  usarlo como una unidad; nunca completar sus huecos con `MAIL_*` ni mezclar
  ambos contratos. Un `LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN` aislado, conservado
  como alias de Blog, no selecciona por sí solo el correo legacy. No crear
  configuraciones nuevas con el namespace legacy ni registrar sus valores.
- Para pruebas locales, usar por defecto el perfil tipado
  `LIQUIDSTACK_WEBADMIN_MAIL_TRANSPORT=local_capture_smtp`: exige
  `DEV_MODE=1`, `RAIZ` HTTP loopback y SMTP en `127.0.0.1` o `[::1]`, toma el
  origen del enlace de `RAIZ` y conserva su bloque dedicado de host, puerto y
  remitente local, sin TLS, usuario ni contraseña SMTP. El bloque general
  `MAIL_*` puede existir para formularios, pero WebAdmin no lo consume en este
  perfil ni permite que active un relay remoto. Arrancar el capturador externo
  ligado solo a loopback y sin relay/forwarding. No instalarlo desde CORE ni
  sustituirlo por un comando que revele tokens en consola. Fuera de ese perfil
  el dispatcher debe fallar antes de PDO/transporte y el SMTP productivo
  conserva STARTTLS/SMTPS y autenticación.
- Permitir SMTP real desde desarrollo solo como opt-in explícito con
  `LIQUIDSTACK_WEBADMIN_MAIL_TRANSPORT=smtp`, el bloque general válido,
  `DEV_MODE=1` y `RAIZ` loopback. El mensaje sale al proveedor configurado,
  pero su enlace `localhost` solo sirve en la misma máquina de desarrollo;
  limitar la prueba a cuentas controladas y volver a `local_capture_smtp` para
  QA que no necesite entrega externa.
- Mantener el prefijo fuera de los idiomas activos y de rutas GET/POST existentes. `/admin` es el default neutral.
- Determinar las colisiones con el catálogo estructurado de `doctor` o con el inspector estático de rutas. Una mención textual en la configuración, comentarios, documentación o código de negocio no convierte por sí sola el prefijo en una ruta ocupada; una búsqueda general solo aporta pistas. Las claves de ruta calculadas, concatenadas o añadidas mediante índices son deliberadamente no analizables: bloquean WebAdmin con `route_file.dynamic_key` hasta convertirlas en un catálogo literal o estático.
- No escribir valores en `.env`; como máximo documentar nombres vacíos en `.env.example` cuando la tarea lo autorice.

## Diagnosticar sin mutar

Ejecutar:

```bash
composer liquidstack:doctor
composer liquidstack:doctor --format=json
composer liquidstack:migrate --plan
composer liquidstack:migrate --dry-run
```

- Tratar `doctor` como preflight operativo: catálogo, selectores, providers,
  config, nombres de entorno, assets, conexión modular seleccionada y esquema
  aplicado.
  El probe DB es estrictamente de solo lectura.
- No copiar valores de entorno a logs, respuestas o informes. Los errores de parseo deben ser genéricos.
- Entender `runtime_ready` (incluye clave, Argon2id y protección de trazas) por
  separado de `bootstrap_ready` (incluye los dos correos, pero no la clave
  HTTP).
- Entender `mail_ready` y `mail_blockers` como un eje adicional: una
  configuración SMTP ausente bloquea el dispatcher y hace fallar de forma
  genérica una recuperación elegible, pero no el login ni el bootstrap que
  solo encola trabajo. `mail_ready` valida configuración, no conectividad;
  comprobar que el capturador local escucha antes de probar cualquiera de los
  dos flujos.
- Usar `--plan` para revisar el catálogo sin conexión y `--dry-run` para
  comprobar el estado real de la DB sin escribir.
- Tratar `--plan` como un preflight exclusivamente de metadatos modulares y de
  migraciones. La ausencia de origen público, SMTP, credenciales DB u otros
  requisitos operativos se diagnostica en `doctor` o `--dry-run`, pero no
  invalida un catálogo correcto.
- No ejecutar `--apply` salvo autorización expresa. Antes, revisar el hash y
  los bloqueadores del dry-run. La aplicación exige `--yes` o confirmación
  interactiva; en JSON siempre `--yes`.
- Si hay migraciones destructivas, exigir a la vez
  `--allow-destructive --backup-confirmed`. No interpretar esos flags como la
  creación automática de un backup.
- Si aparece `migration.precondition_failed`, no adoptar, completar ni borrar
  objetos por intuición. Inspeccionar el namespace, conservar una copia
  recuperable y resolver manualmente la colisión o el DDL parcial. `retrySafe`
  solo cubre la forma idempotente de cada sentencia MySQL, no un rollback
  integral de DDL no transaccional.
- Si aparece `migration.postcondition_failed`, no repetir `--apply` solo porque
  el dry-run conserve la migración como `pending`. En MySQL/MariaDB el DDL puede
  haberse confirmado antes del fallo. Comparar de forma read-only el esquema,
  índices, claves, semillas y datos con el contrato. Solo es seguro reintentar
  tras corregir y publicar el verificador/runtime cuando el estado real es
  exacto; si no lo es, restaurar la copia o diseñar una recuperación explícita.
- Si el DDL de una migración posterior quedó confirmado pero sin registro por
  un fallo demostrado del verificador, no insertar el registro ni adoptar el
  esquema manualmente. Restaurar el backup anterior al lote, actualizar CORE
  con la corrección publicada, repetir `--dry-run` y solicitar de nuevo la
  autorización de `--apply`.
- No copiar a informes credenciales, SQL ni mensajes internos de PDO.
- Una petición insegura o malformada debe fallar con `400` antes de abrir PDO.
  Cuando el runtime, la conexión o el esquema no están listos, un `503`
  genérico en `/admin` es el fallo cerrado esperado.

## Operar bootstrap y correo

1. Ejecutar en orden `doctor`, `migrate --dry-run`, `migrate --apply`, y, cuando
   Media esté activo, `liquidstack:media:init`; continuar con
   `liquidstack:webadmin:bootstrap`, un segundo `doctor`/QA HTTP y
   `liquidstack:webadmin:mail:dispatch`. `--apply`, `media:init` y bootstrap
   exigen sus propias confirmaciones; el dispatch es una invocación explícita
   con efecto SMTP y bootstrap únicamente encola invitaciones.
2. Mientras no exista scheduler de producción, ejecutar el dispatcher
   manualmente cuando haya invitaciones pendientes. El cron futuro será una
   tarea one-shot por proyecto con `--limit` entre 1 y 100; no instalarlo desde
   Composer, convertirlo en daemon ni registrar destinatarios, tokens o
   diagnósticos SMTP. Seguir
   `docs/mejoras-pendientes/webadmin-mail-scheduler-produccion.md`.
3. Asumir entrega al menos una vez: una caída después de que SMTP acepte el
   mensaje y antes del ACK puede causar un duplicado. No alterar manualmente
   locks, hashes ni estados para simular exactly-once.
4. Usar `liquidstack:webadmin:bootstrap --resend-invites` solo con
   confirmación para invitaciones bootstrap ya enviadas o en fallo terminal.
   No sirve para saltar el backoff de filas `pending`/`processing`; revoca los
   enlaces vivos antes de reencolar y requiere otro dispatch posterior.
5. Redaccionar el parámetro `token` de `/activate` y `/password/reset` en los
   access logs del edge, servidor web y APM. El `303` limpia la navegación
   posterior, no el log de la primera petición.
6. La recuperación de contraseña es síncrona: crea el token, cierra la
   transacción, intenta exactamente un envío SMTP y solo después marca el
   enlace como entregado. Nunca crea una fila de outbox. Si el transporte no
   confirma el envío, revoca el token y ofrece una pantalla genérica de
   reintento sin correo, host, causa ni diagnóstico técnico. Esa respuesta
   diferenciada y el tiempo SMTP reducen la no enumeración estricta durante
   fallos; no describir el flujo como completamente indistinguible y unificar
   resultados si un proyecto productivo exige esa propiedad.

## Gestionar editores

- Usar exclusivamente la superficie privada `/admin/users` y UUID públicos;
  no añadir IDs internos, roles o capabilities a query strings de resultado.
- Mantener separados los cuatro gates:
  `webadmin.users.view`, `webadmin.users.invite`,
  `webadmin.users.suspend` y
  `webadmin.users.capabilities.manage`. Ocultar una acción en HTML no sustituye
  su revalidación dentro de la transacción.
- No permitir que esta UI cree, reasigne, suspenda o modifique
  `system_superadmin`, `site_admin`, el propio actor ni una identidad con otro
  rol protegido/no delegable. Las cuentas protegidas solo nacen del bootstrap.
- Delegar una capability únicamente cuando el módulo está activo,
  `is_delegable=1` y el actor la posee. Al reemplazar, conservar las
  capabilities inactivas, no delegables o fuera del alcance del actor.
- Tratar `display_name` como opcional y el correo como identidad canónica
  única. Nunca registrar correo, nombre, SID, CSRF, token, hash o contenido del
  formulario en auditoría o excepciones.
- No enviar SMTP desde la petición. Invitar, reenviar o reactivar una cuenta
  nunca activada solo encola outbox; el dispatcher one-shot realiza la entrega.
- Suspender como operación de contención: revocar sesiones y tokens, cerrar
  entregas abiertas e incrementar `auth_version`, incluso si el lifecycle del
  objetivo contiene deriva. Reactivar una credencial de política antigua debe
  permitir después el reset, no dejar la cuenta irrecuperable.
- Mantener el orden de bloqueo `outbox objetivo → users por ID → SID actor →
  action tokens objetivo → target sessions`, y actualizar solo filas que ya se
  hayan bloqueado. La carrera de correo duplicado debe acabar en un único
  editor y un conflicto controlado.
- Probar HEAD sin mutación, CSRF, no enumeración para actores sin permiso,
  preservación de capabilities, self/protegidos, rollback de auditoría,
  lifecycle completo y carrera real sobre MariaDB aislada. El contrato
  detallado vive en `docs/webadmin-editor-management.md`.

## Operar la biblioteca de medios WebAdmin

- Tratar la biblioteca como una funcionalidad de WebAdmin, nunca como datos
  propios de Blog. `/admin` exige la migración fundacional 0001;
  `/admin/media` exige además `0002_webadmin_media_library`. Una 0002 pendiente
  no puede bloquear el panel base.
- Aplicar 0002 solo mediante el flujo explícito `doctor` → `migrate --plan` →
  `migrate --dry-run` → backup verificado → `migrate --apply` →
  `liquidstack:media:init` → bootstrap → segundo `doctor` y QA HTTP. Los eventos
  automáticos de Composer distribuyen el contrato, pero nunca crean tablas,
  inicializan directorios, procesan imágenes o borran datos.
- Ejecutar `composer liquidstack:media:init` con autorización interactiva o
  `--yes`; `--format=json` exige `--yes`. En modo normal es una mutación
  idempotente de filesystem: no abre PDO, no procesa imágenes y no comprueba
  SMTP. No llamarlo automáticamente desde `composer install`/`update`.
- Configurar producción con
  `LIQUIDSTACK_WEBADMIN_MEDIA_STORAGE_ROOT` apuntando a una ruta absoluta,
  persistente y fuera del árbol del proyecto/deploy. El único default interno
  permitido es `storage/liquidstack/webadmin/media` cuando coinciden
  `DEV_MODE=1` y una `RAIZ` loopback canónica.
- No usar `public`, `vendor`, `.git`, la raíz del proyecto, una raíz de disco,
  traversal, symlinks o junctions como storage. DB y storage se respaldan y
  restauran como una unidad; cambiar la variable no mueve ni adopta medios.
- Exigir el marcador versionado `.liquidstack-webadmin-media` como contrato de
  ownership. El inicializador crea además el lock, `.staging/` y un `.gitignore`
  interno; puede reparar auxiliares de una raíz marcada, pero nunca adoptar de
  forma implícita una raíz no vacía sin marcador válido.
- Reservar
  `composer liquidstack:media:init --adopt-existing --backup-confirmed --yes`
  exclusivamente para upgrades con medios legacy anteriores al marker. No
  usarlo en instalaciones nuevas o raíces vacías. Exigir WebAdmin/0002 listos y
  un backup conjunto ya verificado; `--backup-confirmed` no lo crea y no es
  válido sin `--adopt-existing`, mientras que la adopción exige también
  `--yes` incluso en texto.
- Durante la adopción, mantener el lock transaccional `media.quota_lock=v1` y
  verificar la correspondencia bidireccional completa DB↔FS: storage keys
  canónicas, MIME AVIF, bytes/hash, ninguna variante ausente o extra, staging
  vacío y ningún symlink/junction. Crear scaffold y marker solo después de la
  verificación completa; ante mismatch, no tocar filas ni layout legacy.
- Interpretar `result.status=adopted_existing` como éxito de adopción. Distinguir
  los errores de flags `webadmin.media.init.adoption_requires_*`, schema
  `webadmin.media.init.schema_not_ready`, mismatch
  `webadmin.media.storage_adoption_mismatch`, raíz vacía/no aplicable
  `webadmin.media.storage_adoption_not_required` y fallo DB/lock
  `webadmin.media.storage_adoption_database_failed`; no intentar reparar ni
  marcar a mano ante ninguno de ellos.
- Antes de habilitar uploads, comprobar en `doctor` por separado:
  `media.schema`, `file_uploads`, `upload_max_filesize` (12 MiB mínimo),
  `post_max_size` (límite multipart completo), fileinfo, Imagick, round-trip
  AVIF y storage inicializado/escribible. `media_ready=false` no cambia
  `runtime_ready` del WebAdmin base.
- Admitir una sola entrada multipart plana JPEG, PNG o WebP, máximo 12 MiB,
  12.000 px por lado y 40 MP. Ignorar nombre, ruta y MIME del navegador;
  verificar firma, contenedor y decoder, rechazar animación/multiframe y
  generar exclusivamente variantes AVIF verificadas y sin metadatos.
- Mantener `webadmin.media.view` y `webadmin.media.upload` como gates separados.
  Toda subida revalida ambas dentro de la transacción. Adquirir primero el
  mutex global `state.media.quota_lock=v1`; bajo ese lock decidir rate limits,
  suma de cuota, promoción, filas y auditoría para evitar carreras de ausencia
  y sobrecuota.
- Servir ficheros solo por UUID + ancho y tras verificar bytes/hash en storage
  privado. `HEAD` debe conservar autenticación, status y cabeceras de `GET`,
  pero usar el probe streaming de metadata/hash y no materializar el AVIF.
- Mantener `no-store`, `noindex`, `nosniff`, CSP privada y jerarquía semántica
  H1 de página → H2 de sección → H3 de card. No persistir ALT/title/pie en el
  asset compartido: pertenecen al uso localizado que hará Blog u otro editor.
- Consultar `docs/mejoras-pendientes/webadmin-media-library.md` para el
  contrato implementado y sus pendientes reales de ciclo de vida y formatos.

## Operar Liquid Blog

- Activar Blog solo mediante el selector directo `liquidstack/blog`; su cierre
  de dependencias debe activar WebAdmin antes. No registrar rutas, navegación,
  migraciones ni diagnósticos Blog en un proyecto core-only o WebAdmin-only.
- Tratar `App/config/modules/blog.php` como configuración project-owned. Puede
  declarar `public_paths` por cada idioma activo, `sitemap_path`, el prefijo de
  tablas, la vista opcional `public_article_view` y el opt-in
  `sitemap_cache.enabled` con `ttl_seconds` entre 30 y 3.600. La caché LKG
  permanece desactivada por defecto. La vista debe usar una
  ruta relativa `App/views/...php`, regular, legible, contenida y sin symlinks;
  recibe `$blogArticle` como `BlogPublicArticleViewModel`, debe escapar sus
  escalares por contexto y puede imprimir directamente solo `bodyHtml()`.
  `alternateUrls()` contiene únicamente variantes publicadas para SEO y
  `languageNavigationUrls()` usa el índice localizado cuando falta traducción.
  El
  proyecto es dueño del head, assets y CSP de ese shell. Omitirla conserva el
  fallback standalone y su CSS gestionado. `shared` permanece como default; si
  se declara `liquidstack`, WebAdmin debe declararlo también. Composer no debe
  crear, fusionar ni sobrescribir este fichero.
- Cuando el shell público reutilice `.btn_idioma`, activar
  `bindLanguageNavigation(window, document)` desde
  `_languagePreference.mjs` y limpiar el binding en HMR. Los enlaces deben usar
  `languageNavigationUrls()`; el helper preserva navegación modificada y evita
  que el traductor legacy haga POST a `/languages`, sin saltarse el gate de
  CookieLAD para `cookie_custom_lang`.
- Exigir que las claves de `public_paths` coincidan exactamente con
  `App/config/langs.php`, que sus rutas sean absolutas y únicas y que no
  colisionen con rutas o ficheros del proyecto. El path base puede pertenecer a
  un índice estático; las URLs de artículo viven en `{public_path}/{slug}`.
- Usar `RAIZ` como origen canónico del sitemap y de los artículos: HTTPS fuera
  del laboratorio y HTTP solo con el perfil loopback tipado de desarrollo.
  Mantener `LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN` como alias de transición y
  conservarlo temporalmente si difiere en producción para no cambiar URLs
  durante un update; `doctor` debe avisar hasta alinear ambos valores. Ese
  alias no selecciona por sí solo el transporte SMTP legacy y puede coexistir
  durante la transición con `MAIL_*`; si aparece además cualquier clave
  SMTP/FROM legacy, entonces sí se exige el bloque anterior completo. Para
  adoptar por completo el correo canónico, alinear primero `RAIZ` y retirar las
  claves WebAdmin SMTP/FROM legacy. En local debe prevalecer la `RAIZ`
  loopback. No derivar el origen de `Host`, `Forwarded` ni del request. Blog no
  debe depender de que el transporte SMTP esté listo.
- Mantener fuera del MVP las notificaciones de nuevas publicaciones a
  suscriptores. Cuando se implemente, usar campañas y outbox propios de Blog,
  consentimiento auditable, bajas, lotes acotados, límites, reintentos y un
  cron one-shot por proyecto para no saturar SMTP; nunca enviar el lote dentro
  del POST de publicación ni reutilizar el flujo síncrono de recuperación.
  Seguir `docs/mejoras-pendientes/blog-notificaciones-suscriptores.md`.
- Aplicar en orden `doctor`, `migrate --plan`, `migrate --dry-run`, backup
  recuperable de DB y storage, autorización expresa, `migrate --apply`,
  `media:init` y, solo si la caché LKG está activada,
  `blog:sitemap-cache:init`; continuar con `webadmin:bootstrap`, un segundo
  `doctor` y QA HTTP. No confundir
  `--backup-confirmed` con la creación del backup. Repetir el bootstrap es
  obligatorio al añadir Blog a un WebAdmin ya inicializado para completar de
  forma idempotente las capacidades de las cuentas protegidas.
- Mantener separados `blog.articles.view`, `blog.articles.edit` y
  `blog.articles.publish`. Ocultar botones no sustituye el gate transaccional:
  SID, CSRF, lifecycle, `auth_version` y capability deben revalidarse con el
  mismo PDO y dentro de la transacción Blog.
- Servir las vistas previas y revisiones solo dentro del prefijo privado de
  WebAdmin y con `blog.articles.view` más `webadmin.media.view` cuando se use el
  editor estructurado. No convertirlas en URLs compartibles ni emitir
  canonical, metadatos SEO públicos o entradas de sitemap; deben usar
  `no-store`, `noindex` y no realizar mutaciones, adopción ni auditoría
  editorial en GET o HEAD.
- Conservar el agregado y cada variante por idioma como unidades estables. En
  el MVP solo existen `draft` y `published`; una variante publicada se retira
  antes de editarla, cada escritura usa `lock_version` y no existe borrado HTTP.
- Tratar `0003_blog_categories` y `0004_blog_category_capabilities` como las
  fronteras ya implementadas de categorías localizadas, asignaciones y sus
  capacidades. La proyección pública exige `0001+0003`; la administración
  exige `0001+0002+0003+0004`. Una categoría usa UUID público, slug por locale,
  lock optimista y un máximo de 100 asignaciones por operación.
- Tratar `0005_blog_structured_content` como la frontera ya implementada del
  editor `/admin/blog/editor`: documento actual, referencias de medios y
  revisiones inmutables. Exige las postcondiciones combinadas de
  `0001+0003+0005`; las imágenes requieren además
  `0002_webadmin_media_library` en el scope WebAdmin.
- Mantener el contrato exacto del documento
  `liquidstack.blog.document`, versión `1`. Admite ocho bloques controlados:
  párrafo, heading H2/H3, lista, callout, enlace, imagen, YouTube y CTA. No
  admitir H1, HTML, clases o CSS libres en el cuerpo. El H1 vive en los
  metadatos de la variante y `body_text` se deriva siempre en servidor.
- Conservar `article-basic-01` y `article-cover-01` como las plantillas v1. Una
  plantilla nueva exige ampliar registro, validación, renderer, editor,
  persistencia y pruebas como un único contrato; no aceptar claves arbitrarias
  llegadas del formulario.
- Mantener las imágenes de contenido y anchas con `loading="lazy"`. La única
  imagen `cover`, garantizada como primer bloque por el esquema, debe usar
  `loading="eager"` y `fetchpriority="high"` para que un shell project-owned
  pueda tratarla como LCP sin duplicar la portada.
- Al abrir un artículo legacy, proyectar `body_text` a un documento temporal sin
  escribir. Adoptarlo solo cuando el usuario guarda el editor estructurado. A
  partir de esa adopción, bloquear el guardado plano para que no existan dos
  fuentes de verdad.
- Cada guardado efectivo crea una revisión inmutable dentro de la misma
  transacción que variante, documento, referencias, lock y auditoría. Restaurar
  exige la `lock_version` actual y crea una revisión nueva; nunca actualiza ni
  elimina la revisión elegida.
- Exigir `blog.articles.edit` y `webadmin.media.view` para abrir, guardar o
  restaurar el editor; preview e historial exigen `blog.articles.view` y
  `webadmin.media.view`. La subida se realiza en `/admin/media` y requiere
  además `webadmin.media.upload`. Revalidar dentro de la transacción que todos
  los UUID de imagen existen y tienen variantes AVIF.
- Resolver las URLs de artículo Blog únicamente después de que fallen las rutas
  estáticas del proyecto. Un borrador o slug desconocido continúa al 404 legacy;
  un runtime reconocido pero no operativo responde `503`, y un método de
  escritura reconocido responde `405` sin abrir PDO.
- Tratar `publicRoutePrefixes()` únicamente como metadatos baratos: el claim de
  un `GET`/`HEAD` no puede construir el provider, abrir PDO ni crear el runtime.
  Solo un namespace modular reclamado difiere la sesión legacy. Una ruta
  estática ganadora la recupera antes de renderizar salvo que declare
  literalmente `session => false`; un miss modular la inicia antes del 404. Las
  rutas no reclamadas y los métodos de escritura conservan el bootstrap previo.
- Renderizar el documento estructurado actual cuando exista y conservar el
  renderer legacy de `body_text` mientras la variante no se haya adoptado. Una
  actualización de Composer nunca debe reescribir contenido para forzar la
  adopción.
- Servir AVIF público solo por
  `/_liquidstack/blog-media/{uuid}/{width}.avif`, y solo cuando el asset esté
  referenciado por el documento actual de un artículo publicado. Una referencia
  presente únicamente en borrador o revisión no autoriza la entrega. Verificar
  storage, bytes y hash; responder `404` uniforme ante ausencia, corrupción o
  falta de referencia publicable. Declarar este namespace como prefijo
  pre-bootstrap para que tanto medios válidos como rutas malformadas y `HEAD`
  eviten la redirección multidioma y `PHPSESSID`.
- Declarar el sitemap como endpoint público exacto pre-bootstrap para que no lo
  intercepte el resolver multidioma ni cree `PHPSESSID`. Antes de despacharlo,
  preservar una ruta GET exacta, un fichero o symlink público y una subruta de
  showroom project-owned; ante un catálogo GET incompleto, declinar la fase
  temprana y conservar la prioridad normal del router del proyecto.
- Servir el sitemap desde la DB de producción. Nunca reescribir
  `public/sitemap.xml`, `robots.txt`, Git o el deploy al publicar. Consultar como
  máximo 50.001 filas para admitir 50.000 y fallar cerrado ante overflow, sin
  truncar silenciosamente; limitar además el XML final a 50 MiB. Conservar el
  `ETag` fuerte, `Cache-Control: public, no-cache, must-revalidate` y la
  revalidación `If-None-Match` de `GET`/`HEAD`. No inferir un `Last-Modified`
  global mediante `MAX(updated_at)`, porque puede retroceder al retirar URLs.
- Tratar la caché LKG como una frontera opt-in completa, no como un fichero XML
  suelto. Exige el prefijo Blog completo `0001`–`0006`, incluida
  `0006_blog_sitemap_publication_state`, y
  `composer liquidstack:blog:sitemap-cache:init`; en producción exige además
  `--shared-storage-confirmed` y
  `LIQUIDSTACK_BLOG_SITEMAP_CACHE_ROOT` absoluto, privado, persistente, fuera
  del deploy y compartido por todos los nodos. Confirmar operativamente que el
  volumen ofrece `flock` coherente y `rename` atómico; el flag no puede probar
  esas propiedades. No inicializar desde Composer ni rotar una generación
  activa por intuición; respaldar y restaurar DB y storage como una unidad.
  La identidad del snapshot debe incluir también el destino y el prefijo de
  tablas. `doctor` debe contrastar la generación de DB con el marker cuando
  inspecciona la conexión; storage inicializado no equivale por sí solo a
  capacidad preparada. Limpiar restos de `.staging` únicamente bajo lock y con
  nombres, profundidad, cantidad y ficheros estrictamente acotados; una entrada
  inesperada falla cerrada y no se elimina por intuición.
- Mantener la invalidación dentro de `publish`/`unpublish`: fence durable antes
  del cambio visible, revisión pública monótona en la misma transacción y
  promoción solo tras comparar revisión/generación bajo locks. Un rollback
  puede dejar el fence y deshabilitar el stale hasta la siguiente regeneración;
  no retirarlo manualmente. Si ya existe un fence válido de esa generación,
  conservarlo sin reemplazo para no abrir una ventana unlink/rename ante crash.
- Permitir fallback LKG solo para `database.connection_unavailable` y solo con
  snapshot vigente, íntegro, de la misma identidad y sin fence. No degradar a
  stale ante esquema/config inválidos, error de consulta no clasificado,
  overflow, render o storage. Conservar ETag y paridad GET/HEAD/304, declarar
  `X-LiquidStack-Sitemap-Source: stale-cache` y `Warning: 110`, y responder
  cerrado cuando no exista snapshot utilizable. Seguir
  `docs/blog-sitemap-last-known-good-cache.md`.
- Para índices project-owned, crear una sola instancia con
  `BlogPublicFeedFactory` y reutilizarla para cards generales, filtros y cards
  por categoría. `BlogCategoryPublicFeedFactory` es compatibilidad legacy, no
  debe combinarse con el factory general dentro de la misma petición.
- Convertir la query GET pública en un `BlogPublicCatalogQuery` acotado y usar
  `BlogPublicFeed::cardsForQuery()`. Mantener `category_mode` en la allowlist
  `any|all`, un máximo de diez categorías simultáneas y los límites de
  búsqueda,
  paginación y exclusión del value object; no pasar arrays o SQL construidos
  desde el request al repositorio.
- En la mejora progresiva de `moduleBlogFilters01`, conservar la serialización
  del GET nativo, comprobar la validez HTML antes de cada fetch, invalidar una
  respuesta anterior en cuanto cambia la búsqueda y sincronizar resultados,
  `title`, robots y canonical desde el nuevo SSR. Las búsquedas pausadas de una
  misma secuencia usan `pushState` una vez y después `replaceState`; cambios de
  categoría crean entradas nuevas y `popstate` nunca escribe historial.
- Un índice project-owned puede optimizar esa mejora respondiendo al header
  exacto `X-LiquidStack-Partial: blog-results` con un documento mínimo que
  conserve el mismo formulario, `#blog-results`, `title`, robots y canonical.
  Debe enviar `Vary: X-LiquidStack-Partial` y mantener el HTML completo como
  respuesta normal y fallback; la petición parcial no define un segundo
  contrato de datos ni una ruta distinta.
- Mantener `blog-admin.css` y `blog-editor.js` bajo
  `modules/blog/published/assets`; el manifiesto los sincroniza como assets
  module-managed en `public/assets/modules/blog`. No integrarlos en el bundle
  general ni convertirlos en ficheros project-owned. Revisar `blog.assets` en
  `doctor`: un destino ausente o inválido debe bloquear con
  `assets.missing_or_invalid`, sin intentar reparar DB o storage.
- Tratar el medidor SEO editorial v1 como una ayuda no bloqueante, sin score ni
  persistencia propia. Debe clasificar cada comprobación como `Bien`,
  `Revisar` o `Pendiente`; un error del analizador nunca impide abrir, guardar
  o publicar un artículo.
- Mantener el análisis vivo en `POST /admin/blog/editor/seo-analysis`, con
  sesión, CSRF, `blog.articles.edit`, `webadmin.media.view`, respuestas
  `no-store` y CSP `connect-src 'self'`. Conservar el panel SSR como base y
  cancelar peticiones obsoletas en la mejora progresiva.
- Comparar canibalización solo con publicaciones del mismo idioma, excluyendo
  el artículo actual. Acotar la consulta con `MAX + 1`: si el catálogo DB o el
  inventario estático no se ha podido inspeccionar completo, devolver
  `Pendiente` y nunca un falso `Bien`.
- Considerar `App/config/seo/canonical-pages.json` un inventario opcional y
  project-owned. CORE puede leer su esquema documentado, pero no crearlo,
  completarlo ni sobrescribirlo durante Composer; rutas inválidas, exceso de
  entradas o lectura fallida degradan el check a `Pendiente`.
- Mantener la familia visual Blog bajo
  `modules/blog/resources/project/`, replicando las rutas estándar del
  consumidor. Declarar cada identificador en la allowlist `resources` del
  manifiesto y publicar solo sus ficheros exactos de controlador, template,
  SCSS y JS, más el helper y los hooks exactos de showroom. Agrupar cada
  recurso de forma cohesiva con `managed_hash`; no devolverlo al catálogo base
  ni ampliar el permiso a directorios completos. Un stack sin el selector Blog
  no debe recibir `artBlogArticle01`, `moduleBlogArchive01`,
  `moduleBlogFilters01`, `sectionBlogGrid01`, `sectionBlogList01`,
  `sectionBlogFeatured01`, `sectionBlogRelated01` o `sectionBlogSlider01`.
- Mantener `resource-support` como grupo independiente y su helper como API
  estable y aditiva: no retirar ni cambiar las firmas de
  `liquidstack_blog_resource_context()`,
  `liquidstack_blog_resource_escape()`, `liquidstack_blog_resource_card()` o
  `liquidstack_blog_resource_heading()`, ni las claves existentes del contexto.
  Un cambio incompatible exige helper versionado o migración coordinada de la
  familia; no congelar todos los recursos en un único grupo por conveniencia.
- Si el contrato de `src/scss/_config.scss` no está disponible, publicar solo
  los assets autocontenidos de cada módulo bajo sus namespaces
  `public/assets/modules/<id>`, `src/js/modules/<id>` y
  `src/scss/modules/<id>`. Omitir conjuntamente en ese ciclo todos los recursos
  estándar y hooks de showroom para no dejar contratos visuales parciales; la
  siguiente actualización con el config reparado debe instalar el conjunto.
- Mantener los controladores visuales libres de PDO, prefijos e IDs internos.
  Reciben únicamente arrays de presentación. Los fixtures Matrix pertenecen
  al showroom y nunca actúan como fallback de la DB pública. Al trasladar un
  fichero gestionado desde CORE base al módulo, reconocer solo huellas legacy
  verificadas bajo el nuevo source ID; conservar cualquier copia desconocida.
- Tratar `blog-public.js` como el runtime progresivo module-owned de los
  bloques públicos. Un bloque YouTube conserva siempre su enlace externo SSR;
  el script solo puede impedir esa navegación ante un clic primario sin
  modificadores y después de comprobar `cookie_social=true`. El iframe debe
  usar exclusivamente `youtube-nocookie.com`, nacer tras el clic y retirarse
  al revocar consentimiento. No precargar miniaturas de terceros. El fallback
  standalone carga el asset con `script-src 'self'`; cada shell project-owned
  debe cargarlo con su nonce y limitar `frame-src` al mismo origen.
- Probar SQLite aislado y, ante cambios de DDL, repositorio, locks o auditoría,
  la integración opt-in MySQL/MariaDB. Cubrir create/add-locale/save/publish/
  unpublish, categorías, documento canónico, adopción legacy, revisiones,
  restauración, medios, stale writes con dos PDO, rollback conjunto de
  contenido y auditoría, prioridad estática, sitemap y ausencia de mutaciones
  en `HEAD`.
- Consultar `docs/liquid-blog.md` y `docs/blog-seo-editorial.md` como contratos
  completos antes de ampliar el módulo. No presentar categorías, AVIF, el
  editor v1 ni el medidor SEO editorial v1 como pendientes. Los pendientes
  reales incluyen traducción IA, Search Console o Indexing API, vídeo local y
  el maquetador libre de secciones/filas/columnas.

## Modificar la infraestructura en CORE

1. Leer `docs/arquitectura-modulos-internos.md` y el manifiesto `modules/<id>/module.json`.
2. Mantener providers ejecutables solo para módulos activos y validar su interfaz, `moduleId` y construcción sin argumentos cuando corresponda.
3. Resolver rutas operativas antes del bootstrap, sesión y router multidioma legacy. Una ruta no reclamada debe continuar exactamente por el flujo anterior.
4. Hacer que una configuración inválida falle cerrada dentro de su namespace sin derribar rutas públicas ni revelar la causa al visitante.
5. Mantener `project_files` limitado a namespaces de assets del módulo y, si el
   manifiesto declara una allowlist `resources`, a los ficheros estándar y
   hooks exactos habilitados por ella. Rutas, `.env`, configuración, sitemap,
   vistas públicas, medios y datos siguen siendo project-owned.
   Los ficheros `managed_hash` que deban avanzar juntos tienen que compartir
   un `group`: el sincronizador adquiere el lock del proyecto, recarga estado,
   prepara staging+journal y revierte el grupo completo ante un fallo. Un
   journal pendiente debe recuperarse antes de planificar; si la huella de un
   destino es desconocida o el rollback no queda demostrado, detener Composer
   y conservar sus backups, nunca continuar ni escribir estado. No agrupar
   semillas, fusiones JSON o recursos independientes para obtener una
   atomicidad que no necesitan.
6. Registrar toda migración con ID estable, checksum, orden por dependencias y
   carácter destructivo. Su aplicación debe seguir siendo explícita, bloqueada
   y auditable; `doctor` y `--dry-run` nunca escriben.
7. Probar como mínimo estas matrices:

   - Core-only: no reserva `/admin` ni registra diagnósticos opcionales.
   - WebAdmin: reclama solo su prefijo y no inicia la sesión legacy.
   - Blog: activa primero WebAdmin.
   - GET/HEAD modulares: no abren la sesión legacy; un miss sí la recupera antes
     del 404.
   - GET estáticos, showroom, ficheros y POST: conservan prioridad y bootstrap;
     solo una ruta estática dentro del namespace reclamado puede usar
     `session => false`.
   - Config inválida, prefijo localizado o colisión: la web pública permanece operativa.

8. Ejecutar `composer validate --strict --no-check-publish`, `composer test` y `composer test:module-e2e`. El E2E debe usar un consumidor temporal y demostrar que `doctor` y `migrate --plan` no modifican configuración, lock, `.env` ni datos. Los tests SQLite deben demostrar además que `--dry-run` no muta y que solo `--apply` confirmado escribe. Ante cambios de DDL o persistencia, ejecutar también `composer test:mysql-integration` con sus variables TEST contra versiones soportadas reales; debe cubrir el ciclo outbox/acciones, gestión de editores, carrera de identidad única y los órdenes concurrentes de locks, y nunca apuntar a la DB de un proyecto.
9. Actualizar README, arquitectura, changelog y esta skill cuando cambie el contrato operativo.

## Adoptar cambios en un consumidor

- Comparar primero el estado local con la versión canónica y preservar personalizaciones reales.
- Probar el update en un stack controlado antes de recomendarlo a proyectos publicados.
- Para una instalación nueva con DB dedicada, crear primero una DB vacía y un
  usuario acotado, declarar las seis variables `LIQUIDSTACK_DB_*` fuera de Git
  y seleccionar `connection => liquidstack` en los dos configs project-owned.
- Registrar el entorno real antes de operar. AIWA usa actualmente una DB
  modular local de XAMPP; otros consumidores pueden usar local, staging o
  producción, pero el código no cambia entre ellos y los secretos nunca se
  reutilizan.
- Distinguir una producción vacía de una promoción con datos. En el primer
  caso se aplica el catálogo sobre destino vacío; en el segundo se exige un
  plan coordinado para esquemas, `ls_module_migrations`, datos y medios. Un
  cambio de `LIQUIDSTACK_DB_*` nunca mueve ni adopta contenido.
- Antes de apuntar a producción, rotar cualquier credencial usada o compartida
  durante desarrollo y consultar
  `docs/mejoras-pendientes/promocion-db-modulos-local-produccion.md`.
- Ejecutar `doctor`, `migrate --plan` y `migrate --dry-run` antes de pedir
  autorización. No presentar `--apply` como parte automática de la adopción.
- Si el proyecto ya tiene datos bajo `shared`, no cambiar la selección hasta
  disponer de backup y un traslado manual verificado del esquema, contenido y
  registro de migraciones.
- Verificar selectores activos, salida de `doctor`, rutas públicas, `/admin`, ausencia de `Set-Cookie` legacy en la ruta neutral y suite/build propios del consumidor.
- No publicar una etiqueta ni ejecutar deploy sin autorización expresa.
