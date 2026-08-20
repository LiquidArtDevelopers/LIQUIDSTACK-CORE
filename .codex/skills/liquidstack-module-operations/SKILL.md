---
name: liquidstack-module-operations
description: Activación, diagnóstico, actualización, desarrollo y cierre funcional seguro de los módulos internos WebAdmin y Blog de LiquidStack. Usar cuando Codex deba ejecutar o revisar composer require/remove/update de liquidstack/webadmin o liquidstack/blog, configurar App/config/modules, comprobar /admin, interpretar liquidstack:doctor, preparar migraciones, validar adopción en un stack consumidor, modificar manifiestos/providers modulares o planificar y ejecutar QA exploratoria adversarial de recorridos de usuario sobre herramientas funcionales.
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
        'idle_ttl_seconds' => 2592000,
        'absolute_ttl_seconds' => 2592000,
    ],
];
```

- Mantener la sesión autenticada en 30 días (`2592000` segundos) tanto para
  el vencimiento por inactividad como para el límite absoluto. La actividad
  HTTP válida desliza el primero sin ampliar el segundo; editar localmente un
  formulario sin enviar peticiones no renueva la sesión. Un cambio de estos
  valores solo afecta a sesiones creadas tras volver a iniciar sesión.
- Persistir fechas y eventos en UTC. En la aplicación privada, proponer la
  zona IANA obtenida con `Intl.DateTimeFormat().resolvedOptions().timeZone`,
  permitir que el usuario la confirme o corrija y guardarla en su perfil
  autenticado del servidor. Validarla contra el catálogo IANA y usar UTC
  explícito si no existe una preferencia válida. No asumir `Europe/Madrid`, la
  zona global de PHP, IP o geolocalización. No usar cookies, `localStorage` o
  `sessionStorage` para conservarla; una futura réplica opcional en navegador
  exige consentimiento `cookie_custom` de CookieLad.

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
- En el laboratorio usar `npm run lad`. El supervisor gestionado arranca PHP
  con `App/tools/php-dev-router.php` desde 1309 y Vite desde 5173, avanzando
  independientemente hasta puertos libres; sin el router, rutas dinámicas con
  extensión como `/blog-sitemap.xml` pueden no llegar a CORE. Los overrides
  `LIQUIDSTACK_DEV_APP_PORT` y `LIQUIDSTACK_DEV_VITE_PORT` son exactos. Usar
  los orígenes que muestra la consola, no asumir los puertos iniciales ni
  detener listeners ajenos. `RAIZ` y el origen Vite se inyectan en los
  procesos sin persistir los puertos elegidos; el `swap-env` previo conserva
  su activación habitual del perfil. Al cerrar el supervisor solo deben
  terminar su PHP y su Vite. No alterar por ello el flujo de `npm run build`. El
  router debe cargar el front controller con `public` como directorio de
  trabajo para conservar las rutas relativas legacy.
- Reservar `LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL` y
  `LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL` para el bootstrap explícito. Exigir
  dos direcciones canónicas distintas; pueden pertenecer al mismo operador.
  No mostrar sus valores ni tratarlas como contraseñas.
- Tratar ese par como configuración project-owned que puede inyectarse de
  forma transitoria desde el entorno privado o gestor de secretos del
  operador. Si el mismo operador usa un par aprobado en varios proyectos,
  recuperarlo siempre de esa fuente privada; no inventarlo ni codificar PII en
  CORE, la skill, manifests, stubs, `.env.example`, Git o argumentos CLI.
  Composer nunca escribe `.env`. No crear una contraseña inicial: cada
  identidad establece la suya mediante el enlace de activación.
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
- Sin `--allow-destructive --backup-confirmed`, `migrate --apply` debe dejar
  las destructivas pendientes con aviso y aplicar solo el prefijo no
  destructivo de cada módulo. Desde una destructiva diferida, conservar
  también pendientes los IDs posteriores del mismo módulo para no romper el
  orden append-only; las migraciones seguras de otros módulos pueden avanzar.
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
  esquema manualmente. Tras actualizar CORE, `--dry-run` solo puede habilitar
  la reanudación si la migración es no transaccional y `retrySafe`, su
  postcondición exacta ya se cumple, todos los supersedidos conservan registros
  con checksum/scope exactos y al menos uno de sus verificadores antiguos falla
  por el nuevo superset. La migración continúa como `pending`: un `--apply`
  nuevamente autorizado reejecuta su SQL idempotente, verifica y solo entonces
  escribe el registro. Si cualquiera de esas pruebas falla, restaurar el backup
  o diseñar una recuperación explícita.
- No copiar a informes credenciales, SQL ni mensajes internos de PDO.
- Una petición insegura o malformada debe fallar con `400` antes de abrir PDO.
  Cuando el runtime, la conexión o el esquema no están listos, un `503`
  genérico en `/admin` es el fallo cerrado esperado.

## Operar bootstrap y correo

1. Ejecutar en orden `doctor`, `migrate --dry-run`, `migrate --apply`, y, cuando
   Media esté activo, `liquidstack:media:init`. En toda instalación nueva con
   WebAdmin —directa o por la dependencia de Blog— continuar obligatoriamente
   con `liquidstack:webadmin:onboard --yes`, un segundo `doctor` y QA HTTP. No
   dar la adopción por terminada en `composer require`, `composer update`, las
   migraciones o el bootstrap que solo encola.
2. Tratar el onboarding como una composición explícita e idempotente: crea o
   reconcilia solo `system_superadmin` y `site_admin`, entrega únicamente sus
   invitaciones bootstrap abiertas y exige que cada identidad esté activa o
   tenga una invitación aceptada por SMTP con token entregado, vigente, sin uso
   y sin revocación. No usar el dispatcher global como sustituto de esta
   postcondición ni considerar suficiente un lote vacío.
3. Obtener el par de correos aprobado desde el entorno project-owned o perfil
   privado del operador y comprobar la configuración local del transporte
   antes del onboarding. La autenticación y aceptación SMTP solo pueden
   confirmarse al intentar la entrega, ya después del bootstrap; un fallo deja
   backoff recuperable y el comando debe terminar como incompleto. En
   automatización usar `liquidstack:webadmin:onboard --yes`; añadir
   `--format=json` solo junto a `--yes`. La aceptación SMTP no garantiza la
   entrega final del proveedor o buzón y la activación sigue requiriendo que el
   destinatario abra el enlace y establezca su contraseña.
   Una vez verificado el onboarding, retirar esas variables one-shot del
   entorno del proyecto; la DB pasa a ser la fuente de verdad y una repetición
   no debe necesitarlas ni sustituir las identidades existentes. `doctor`
   puede mantener entonces la advertencia no bloqueante
   `bootstrap_ready=false`, porque mide la capacidad del entorno para iniciar
   otra DB; no confundirla con el 2/2 ya verificado por `onboard`.
4. No ejecutar onboarding desde hooks de `composer install` o
   `composer update`: pueden faltar configuración, migraciones, backup,
   conectividad o autorización para escribir y enviar correo. La automatización
   segura consiste en convertir el comando en el cierre obligatorio posterior
   a esos preflight, no en ocultar el efecto lateral dentro de Composer.
5. Mientras no exista scheduler de producción, ejecutar el dispatcher general
   manualmente cuando haya invitaciones pendientes. El cron futuro será una
   tarea one-shot por proyecto con `--limit` entre 1 y 100; no instalarlo desde
   Composer, convertirlo en daemon ni registrar destinatarios, tokens o
   diagnósticos SMTP. Seguir
   `docs/mejoras-pendientes/webadmin-mail-scheduler-produccion.md`.
6. Asumir entrega al menos una vez: una caída después de que SMTP acepte el
   mensaje y antes del ACK puede causar un duplicado. No alterar manualmente
   locks, hashes ni estados para simular exactly-once.
7. Usar `liquidstack:webadmin:bootstrap --resend-invites` solo con
   confirmación para invitaciones bootstrap ya enviadas o en fallo terminal.
   No sirve para saltar el backoff de filas `pending`/`processing`; revoca los
   enlaces vivos antes de reencolar y requiere otro onboarding posterior. El
   onboarding nunca reenvía implícitamente una invitación expirada, enviada sin
   activar o fallida de forma terminal.
8. Si SMTP falla y queda retry/backoff, corregir únicamente la configuración o
   credencial externa, respetar `available_at` y repetir onboarding cuando el
   trabajo vuelva a ser elegible. No editar intentos, fechas, locks, estados o
   tokens ni interpretar una ejecución sin filas examinadas como acceso listo.
9. Redaccionar el parámetro `token` de `/activate` y `/password/reset` en los
   access logs del edge, servidor web y APM. El `303` limpia la navegación
   posterior, no el log de la primera petición.
10. La recuperación de contraseña es síncrona: crea el token, cierra la
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
  `/admin/media` exige además `0002_webadmin_media_library`.
  `0003_webadmin_media_avif_source` habilita únicamente la admisión de un
  original AVIF: mientras esté pendiente, la biblioteca, Blog y las subidas
  JPEG/PNG/WebP siguen operativos y la UI no ofrece AVIF. Una migración de
  medios pendiente no puede bloquear el panel base.
  `0005_webadmin_media_quarantine` habilita solo la retirada recuperable;
  pendiente no bloquea lectura ni subida y nunca debe mostrar esa acción.
- Aplicar las migraciones de medios solo mediante el flujo explícito `doctor`
  → `migrate --plan` → `migrate --dry-run` → backup verificado → `migrate
  --apply` → `liquidstack:media:init` → `liquidstack:webadmin:onboard --yes`
  → segundo `doctor` y QA HTTP.
  Al habilitar 0003, `--apply` exige además
  `--allow-destructive --backup-confirmed`: está marcada como destructiva
  aunque su reconstrucción SQLite preserve filas. Los eventos automáticos de
  Composer distribuyen el contrato, pero nunca crean tablas, inicializan
  directorios, procesan imágenes o borran datos.
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
  usarlo en instalaciones nuevas o raíces vacías. Exigir WebAdmin/0002 listo y
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
- Admitir una sola entrada multipart plana JPEG, PNG o WebP y, solo con
  `0003_webadmin_media_avif_source` lista, también AVIF. Aplicar un máximo de
  12 MiB, 12.000 px por lado y 40 MP. Ignorar nombre, ruta y MIME del
  navegador; verificar firma, contenedor y decoder, rechazar
  animación/multiframe y generar exclusivamente variantes AVIF verificadas y
  sin metadatos.
- Mantener `webadmin.media.view`, `webadmin.media.upload` y
  `webadmin.media.delete` como gates separados. Toda subida revalida las dos
  primeras dentro de la transacción. Adquirir primero el
  mutex global `state.media.quota_lock=v1`; bajo ese lock decidir rate limits,
  suma de cuota, promoción, filas y auditoría para evitar carreras de ausencia
  y sobrecuota.
- Retirar un medio solo si el registro de proveedores de uso está completo y
  todos confirman cero referencias. `used`, `unknown`, proveedor no preparado
  o error de inspección cierran la operación y ocultan el botón. Revalidar
  sesión, CSRF, view+delete, CAS e idempotencia dentro de la transacción;
  mantener lock DB y lock de storage hasta commit o rollback compensado.
- La retirada 0005 es una cuarentena, no un borrado: rename atómico dentro del
  mismo storage, manifiesto durable, filas originales conservadas y auditoría
  `webadmin.media.quarantined`. Ante fallo DB restaurar el directorio y retirar
  el manifiesto transitorio. No introducir unlink, purga o cron irreversible
  hasta publicar un contrato separado de retención/restauración.
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
- Componer las páginas administrativas de gestión con el shell module-owned
  compartido de WebAdmin: navegación lateral filtrada por capacidades, un único `main` y
  un inspector derecho solo cuando la ruta tenga herramientas contextuales. No
  crear layouts privados paralelos dentro de Blog ni trasladarlos a
  `App/views`. Una preview privada puede conservar su documento aislado para
  representar `header` y `main`, siempre dentro del namespace autenticado,
  limitada al mismo origen y con `no-store` y `noindex`.
- Conservar el shell funcional sin JavaScript. Los toggles de navegación e
  inspector solo actúan como drawers después de enlazar el runtime; entonces
  deben sincronizar `aria-expanded`, `aria-hidden`, foco e `inert`, cerrar con
  Escape y devolver el foco al disparador. Una mejora progresiva fallida no
  puede ocultar navegación, herramientas o formularios SSR.
- Tratar `App/config/modules/blog.php` como configuración project-owned. Puede
  declarar `public_paths` por cada idioma activo, `sitemap_path`, el prefijo de
  tablas, la vista opcional `public_article_view` y el opt-in
  `sitemap_cache.enabled` con `ttl_seconds` entre 30 y 3.600. La caché LKG
  permanece desactivada por defecto. También puede declarar el opt-in
  `analytics.enabled`, `retention_days` entre 30 y 400,
  `session_timeout_seconds` entre 300 y 28.800 y `collect_in_dev`; la analítica
  permanece desactivada por defecto y Composer no debe activarla. La vista debe usar una
  ruta relativa `App/views/...php`, regular, legible, contenida y sin symlinks;
  recibe `$blogArticle` como `BlogPublicArticleViewModel` y
  `$blogArticleShell` como contexto tipado. Debe comenzar con el único require
  gestionado `App/app/_moduleBlogPublicArticle.php`, escapar sus escalares por
  contexto y solo imprimir directamente las proyecciones HTML saneadas.
  `bodyHtml()` conserva por compatibilidad el cuerpo histórico
  completo, incluida la portada; las vistas nuevas deben colocar
  `headerMediaHtml()` en su `header` y `mainHtml()` dentro de su `main`, sin
  duplicar el medio destacado. El hook expone además `$articleCategories`,
  `$articleTags` y `$articleTaxonomiesHtml`; colocar el último dentro del
  artículo, antes de `$articleMain`. Mantener el copy localizado de
  categorías/etiquetas, omitir cada grupo vacío y no inventar enlaces a
  archivos o rutas de etiquetas.
  Si `analyticsEnabled()` es verdadero, una vista project-owned debe emitir
  los atributos `data-blog-analytics-enabled`,
  `data-blog-analytics-retention-days` y
  `data-blog-analytics-session-timeout`, además del valor escapado de
  `data-blog-analytics-page-grant` obtenido mediante
  `analyticsPageGrant()`; sin ese marcador completo no se carga el tracker.
  Debe permitir `connect-src 'self'` en su CSP.
  `alternateUrls()` contiene únicamente variantes publicadas para SEO y
  `languageNavigationUrls()` usa el índice localizado cuando falta traducción.
  El proyecto es dueño del head, assets y layout de ese shell. La política de
  seguridad pública es común al índice y al detalle: se configura opcionalmente
  en `App/config/modules/blog-public.php` y el controlador la aplica a la
  `Response`, junto con su nonce. La vista solo consume ese nonce desde el
  contexto; nunca llama `header()`, genera otro nonce ni requiere un include de
  seguridad local. Omitir `public_article_view` conserva el fallback standalone
  y su CSS gestionado. `shared` permanece como default; si se declara
  `liquidstack`, WebAdmin debe declararlo también. Composer no debe crear,
  fusionar ni sobrescribir estos ficheros project-owned.
- Limitar `database.table_prefix` de Blog a 29 bytes, presupuesto derivado de
  `category_assignment_workspace_items` y del máximo de 64 bytes de
  MySQL/MariaDB. Un proyecto configurado con una versión hasta v1.23.0 que
  adoptó un prefijo de 30–46 bytes necesita una migración explícita del
  namespace antes de actualizar.
  No truncar, renombrar ni adoptar sus tablas automáticamente: esperar
  `config.invalid_table_prefix` hasta completar y verificar ese traslado.
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
- En la creación, ofrecer de forma explícita solo locales activos que el post
  todavía no tenga y mostrar el `public_path` asociado. En una variante
  existente el locale es identidad inmutable y viaja como campo oculto: no
  permitir cambiarlo desde el editor ni inferir prefijos convencionales como
  `/es`. La UI puede mostrarse en otro idioma sin cambiar el locale editorial.
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
  `blog:sitemap-cache:init`; continuar con `webadmin:onboard --yes`, un segundo
  `doctor` y QA HTTP. No confundir
  `--backup-confirmed` con la creación del backup. Las migraciones de Blog
  asignan sus capacidades; después de añadir Blog a un WebAdmin ya
  inicializado, repetir el onboarding verifica de forma idempotente las dos
  identidades y su acceso. Una identidad activa o una invitación válida ya
  entregada no se vuelve a enviar.
- Mantener separados `blog.articles.view`, `blog.articles.edit`,
  `blog.articles.publish`, `blog.articles.delete`, `blog.tags.view`,
  `blog.tags.edit` y `blog.analytics.view`.
  Ocultar botones no sustituye el gate transaccional:
  SID, CSRF, lifecycle, `auth_version` y capability deben revalidarse con el
  mismo PDO y dentro de la transacción Blog.
- Duplicar un artículo conserva sus categorías y, cuando la frontera está
  lista, las etiquetas efectivas de la variante origen. Debe revalidar juntos
  `blog.articles.edit`, `blog.categories.edit`, `webadmin.media.view`,
  `blog.tags.view` y `blog.tags.edit`; no permitir que la clonación eluda las
  capacidades de taxonomía. Tomar categorías primero del workspace post-wide
  privado y solo en su ausencia de la relación live. Tomar etiquetas del
  workspace localizado efectivo o de live; al añadir un locale al mismo post
  no duplicar categorías y comenzar con cero etiquetas.
- Una copia estructurada crea documento actual, referencias y revisión propia
  inicial `1`, pero no clona publicación, historial, cabecera o workspace de la
  fuente. Usar una instantánea privada solo si la fuente está publicada, su
  versión base coincide con la cabecera vigente y la revisión pertenece a esa
  localización. Un workspace stale, incompatible, ajeno o corrupto debe fallar
  cerrado, sin volver silenciosamente al documento público.
- Tratar `0019_blog_copy_operation_idempotency` como frontera obligatoria de
  las copias HTTP. El `operation_id` emitido por servidor se liga al actor,
  operación, fuente, locales, lock y payload: un replay idéntico devuelve el
  destino original y una reutilización distinta falla con conflicto. Reserva,
  resultado, copia y auditoría comparten transacción; no dejar filas incompletas
  ni confiar únicamente en deshabilitar el submit con JavaScript.
- Emitir un `operation_id` distinto por opción elegible y resincronizar el
  hidden real al cambiar la selección y en `pageshow`; BFCache no puede
  reutilizar la intención de otro locale. Si el destino registrado por un
  replay exacto está después en Papelera, responder con conflicto contextual
  `409`, sin crear otra copia ni degradar a un `503` genérico.
- Servir las vistas previas y revisiones solo dentro del prefijo privado de
  WebAdmin y con `blog.articles.view` más `webadmin.media.view` cuando se use el
  editor estructurado. Distinguir `/posts/preview`, que es únicamente una
  lectura privada textual legacy sin medios ni estilos, de
  `/editor/preview`, que es la preview visual SSR completa. Esta última carga
  la última instantánea de trabajo guardada y reutiliza el mismo renderer SSR
  que la salida pública; no representa estado que permanezca únicamente en el
  cliente. Antes de abrir el diálogo
  inmersivo, guardar el formulario si está sucio y verificar que su URL sea
  same-origin. Permitir el `iframe` propio con CSP `frame-ancestors 'self'` y
  `X-Frame-Options: SAMEORIGIN`, manteniendo `no-store`, `noindex`, `nofollow` y
  `noarchive` en cabeceras y HTML. No convertir la preview en URL compartible ni
  emitir canonical, metadatos SEO públicos o sitemap; GET y HEAD nunca mutan,
  adoptan, publican ni auditan. Declarar éxito solo tras validar la URL exacta,
  `data-blog-preview-ready`, al menos una hoja pública y que todas hayan cargado.
  A los `12 s` fallar cerrado, ocultar/vaciar el iframe y deshabilitar los
  dispositivos; cerrar, reabrir o desmontar el runtime cancela timers y
  callbacks de generaciones anteriores.
- Conservar el agregado y cada variante por idioma como unidades estables. Solo
  existen `draft` y `published`. Con el gate de `0014` listo, una variante
  publicada puede editarse en un workspace privado sin retirarla; sí debe
  retirarse antes de enviarla a la papelera. La papelera usa POST, CSRF,
  `blog.articles.delete`, tombstone y `lock_version`; es recuperable y no existe
  borrado permanente de artículos.
- Tratar `0003_blog_categories` y `0004_blog_category_capabilities` como las
  fronteras ya implementadas de categorías localizadas, asignaciones y sus
  capacidades. La proyección pública exige `0001+0003`; la administración
  exige `0001+0002+0003+0004`. Una categoría usa UUID público, slug por locale,
  lock optimista y un máximo de 100 asignaciones por operación.
- Integrar en el inspector la asignación de categorías del locale actual solo
  cuando el actor posea su capacidad. Mantener el POST nativo como fallback;
  la mejora asíncrona conserva la selección ante un fallo. Con `0014`, las
  categorías de una variante publicada se escriben en el workspace post-wide
  separado y nunca sustituyen la relación live al guardarlas. `Publicar` debe
  consumir su `category_workspace_version` exacta; retirar o enviar un locale a
  la papelera preserva tanto el head como el workspace global. La gestión
  completa puede abrirse aparte sin mezclar locales ni convertir ese enlace en
  requisito del guardado editorial.
- Reutilizar `/admin/blog/categories` como listado SSR y catálogo JSON
  localizado de la mejora progresiva. Crear, editar y borrar conservan POST
  form-urlencoded, CSRF y `blog.categories.edit`; el header exacto del editor
  o `X-LiquidStack-Category-Manager: async`, junto a `Accept: application/json`,
  pide una respuesta JSON `no-store`, mientras el fallback termina por PRG.
  El alta rápida puede omitir el slug y lo deriva de forma determinista. Borrar
  exige UUID público, locale y `lock_version`, elimina solo esa traducción y
  falla con conflicto si el agregado aparece en asignaciones live o privadas;
  el agregado desaparece únicamente al borrar su última traducción.
- Tratar `0020_blog_tags`, `0021_blog_localization_tags`,
  `0022_blog_tag_assignment_heads`,
  `0023_blog_tag_assignment_workspaces`,
  `0024_blog_tag_assignment_workspace_items` y
  `0025_blog_tag_capabilities` como una frontera aditiva localizada por
  variante. Separar vocabulario canónico, relación live, head, workspace e
  items pendientes; `0025` compone las capacidades en el scope WebAdmin. Una
  cola pendiente conserva el Blog anterior; si figura aplicada pero su esquema
  o semillas no cumplen, runtime y `doctor` fallan cerrados.
- Mantener de cero a treinta etiquetas por variante. Canonizar nombres en NFC,
  deduplicar por identidad Unicode casefold, generar slugs deterministas y
  rechazar UTF-8 inválido, límites o controles invisibles peligrosos sin
  truncar. Conservar ZWJ para emoji. Guardar siempre en el workspace localizado
  con CAS `tag_workspace_version`; una selección vacía es una intención válida
  de retirar todas las etiquetas en la siguiente publicación.
- Servir la única mutación desde `POST /admin/blog/tags/assign`, form-urlencoded,
  sin query y con SID, CSRF, lifecycle, `auth_version`, lock editorial y CAS
  revalidados dentro de la transacción. Mantener el input CSV sin JavaScript:
  éxito por PRG y errores 409/422/503 en una vista HTML que conserva el texto
  enviado. Solo el header exacto
  `X-LiquidStack-Tag-Editor: async` junto a `Accept: application/json` pide JSON
  `no-store`; no aceptar claves de formulario adicionales.
- Exponer etiquetas en el editor solo con `blog.tags.view`; exigir además
  `blog.tags.edit` para habilitar o ejecutar la mutación. Mostrar solo lectura
  con VIEW, ocultar y bloquear con EDIT sin VIEW y permitir edición únicamente
  con VIEW+EDIT. Con JavaScript, crear pastillas mediante DOM seguro al confirmar
  con coma, Intro, pegado o blur; una pausa no parte el término y la composición
  IME permanece intacta.
  Usar un único request en vuelo: si el usuario modifica durante la petición,
  reencolar el estado más reciente, avanzar el CAS y no perder la cola al salir
  o cerrar sesión. Si ese guardado falla, cancelar la navegación y conservar el
  texto para reintento.
- Tratar `0005_blog_structured_content` como la frontera ya implementada del
  editor `/admin/blog/editor`: documento actual, referencias de medios y
  revisiones inmutables. Exige las postcondiciones combinadas de
  `0001+0003+0005`; las imágenes requieren además
  `0002_webadmin_media_library` en el scope WebAdmin. La 0003 de WebAdmin solo
  condiciona subir originales AVIF, nunca seleccionar medios ya procesados ni
  abrir el listado o el editor Blog.
- Mantener por compatibilidad el contrato exacto del documento
  `liquidstack.blog.document`, versión `1`. Admite ocho bloques controlados:
  párrafo, heading H2-H6, lista, callout, enlace, imagen, YouTube y CTA. Cada
  nivel desde H3 exige que su padre inmediato permanezca activo; H2 abre una
  `section`, H3 un `article` dentro de ella y H4-H6 quedan en ese artículo. No
  admitir H1, HTML, clases o CSS libres en el cuerpo. El H1 vive en los
  metadatos de la variante y `body_text` se deriva siempre en servidor.
- Tratar `0011_blog_layout_editor_v2` como una extensión aditiva: el documento
  V2 modela `section`, `article` y `div` con columnas controladas. Ofrecer un
  único módulo textual nuevo, `Texto` (`paragraph`), cuyo flujo tipado alterna
  párrafos, encabezados H2-H6, listas, citas, destacados y nodos raíz
  `{"type":"break"}`. Un `<br>` hijo directo del módulo representa ese salto
  raíz; dentro de P, H2-H6, `li`, Cita o Destacado sigue siendo un `break`
  inline. Nunca ofrecer Título, Lista, Cita o Destacado como altas
  independientes. Mantener como módulos no textuales Imagen, YouTube, HTML,
  Botón y Separador. `Enlace` solo se admite
  al leer documentos históricos: la proyección y cualquier persistencia nueva
  lo convierten uno a uno en `Botón` primario, conservando UUID, texto, URL,
  título, destino y presentación. Los enlaces inline continúan dentro de
  `Texto`. No
  anidar artículos, limitar `div` a tres niveles y mantener opcional el
  encabezado de artículo o contenedor. El módulo HTML abre el mismo editor de
  fuente con solo las pestañas HTML y CSS; se sanea y canoniza en backend y
  nunca conserva scripts, eventos, estilos inline ni URLs peligrosas. Tanto
  HTML como `Texto` avanzado limitan los `iframe` a proveedores y rutas HTTPS
  permitidos. El SSR los entrega como placeholders sin red y el runtime solo
  crea el iframe tras `cookie_social=true`; revocar el consentimiento lo retira.
- En el Visual de `Texto`, persistir cada Intro sobre una línea vacía como un
  `break` raíz. Escribir en esa línea sustituye solo el nodo activo por un
  párrafo y conserva los demás saltos; `Ctrl+Intro` inserta siempre un `break`
  inline en la unidad actual. No usar fillers serializados ni `<p></p>` para
  representar el hueco. El parser HTML debe distinguir `<br>` raíz e inline, y
  el modo avanzado debe conservar alrededor de los saltos clases, atributos y
  CSS. SSR emite cada salto raíz como
  `<br class="blogDocument__flowBreak">`. En el downgrade V1, plegarlo sobre el
  vecino inline compatible más próximo, sin usar encabezados como destino y sin
  perder posición o multiplicidad; un flujo compuesto solo por saltos continúa
  siendo válido únicamente como borrador. Este salto editorial no sustituye el
  espaciado de presentación de módulos o contenedores.
- Tratar el modo HTML/CSS avanzado de `Texto` como una variante V2 mutuamente
  excluyente de `content`: persiste exactamente `html` y `css`, con límites
  independientes de 200.000 y 30.000 bytes; el documento completo queda
  acotado a 300.000 bytes. Saneamiento, canonicalización y SSR pertenecen al
  backend. Solo admite HTML estructural en allowlist, atributos seguros y
  `data-content-*`; IDs, clases, fragmentos, IDREF y selectores se namespacifican
  con el UUID al renderizar. CSS se parsea de forma fail-closed, solo permite
  nesting y `@media`/`@supports`, queda encapsulado bajo el wrapper del módulo y
  se entrega como `customCss()` para que el shell lo emita en un `<style nonce>`
  coordinado con su CSP. Nunca usar `style` inline ni relajar la CSP global.
- En el flujo avanzado que continúe siendo editable, representar el CSS
  presentacional seguro directamente sobre el único canvas Visual: usar un
  `<style nonce>` con selector exclusivo de la instancia, proyectar y verificar
  de forma exacta clases, IDs, ARIA y `data-*` seguros, y conservar selección y
  atributos al dividir bloques. No añadir una segunda preview ni otro scroll y
  no reconstruir canvas, hoja o iframe en cada pulsación. Si la estructura o el
  CSS pueden ocultar el caret, capturar eventos o alterar el layout, fallar
  cerrado y mantener el fallback aislado/no editable; nunca ampliar la allowlist
  para forzar una falsa edición tipo Word.
- Resolver una separación temática mediante un módulo controlado `Separador`
  que renderice `<hr>`. Tipo de línea, grosor, color, anchura y separación se
  eligen exclusivamente mediante presets y el color seguro del editor. El
  espacio puramente visual es una propiedad de presentación del módulo o
  contenedor; no admitir `div` vacíos ni estilos inline libres para simularlo.
- Separar la presentación general de un módulo de texto de sus marcas inline.
  Anchura, posición, tamaño, grosor, color y alineación afectan al módulo
  completo; el editor enriquecido actúa solo sobre la selección y sus marcas
  prevalecen. Nivel semántico H2-H6 y preset visual son contratos distintos.
- Usar `Contenedor` como nombre de UI sin cambiar `div` en el documento o el
  HTML. Mantener guías discontinuas también en los módulos, sus SVG de posición
  como assets gestionados de Blog y el contenido visualmente centrado en el eje
  vertical. Mostrar tamaños S/M/L/XL y persistir `s/m/l/xl`; aceptar las formas
  legacy `small/default/large/xlarge` únicamente como compatibilidad de lectura.
- Aplicar el mismo contrato responsive en constructor y SSR público. En móvil,
  `full/80/60/40` ocupan 100% y solo los hijos directos de `section` reciben
  `1.5rem` de padding inline; una imagen `full` directa puede ir a sangre. Desde
  `48rem`, proyectar esos anchos a 100/90/80/60, conservar ese padding en los
  módulos `full` que no sean imágenes, retirarlo solo de `80/60/40` y activar
  50/50, 40/60, 60/40, 30/70, 70/30 y rejillas iguales de tres a cinco.
  Desde `64rem`, recuperar 100/80/60/40.
- Permitir en presentación pública `color00`-`color05` o RGBA validado y
  canonizado para color de texto y fondo; nunca interpolar CSS arbitrario. Los
  defaults globales H2-H6 aceptan `default` y tokens de tema, no RGBA. Imagen
  admite altura automática o `height_dvh` entero entre 5 y 100,
  `object_fit=cover|contain`, `object_position_y=top|center|bottom`, radio
  porcentual entero entre 0 y 50 y un overlay opcional completo —modo seguro,
  token corporativo o RGBA y opacidad—; los campos parciales fallan cerrado. El
  radio porcentual es incompatible con el radio legacy
  `default|none|small|medium|large`, que se conserva solo para compatibilidad;
  `default` resuelve a `medium`, salvo en una imagen `full` hija directa de
  `section`, donde resuelve a `none`.
- Renderizar Hero00 con media responsive real `<picture>/<img>`, nunca mediante
  `style="background-image"`. La imagen destacada se elige únicamente con el
  selector multimedia compartido y expone debajo
  `object_position_y=top|center|bottom`; no mantener un selector nominal
  paralelo. Si Blog aplica parallax a Hero00, debe existir un solo propietario
  runtime sobre `.hero00-media`, respetar `prefers-reduced-motion`, desmontar
  listeners y transformaciones al reinicializarse y conservar una portada
  `cover` legible sin JavaScript.
- Tratar `0012_blog_editor_preferences` y
  `0013_blog_settings_manage_capability` como una frontera opcional. La ruta
  `/admin/blog/settings/presentation` persiste defaults completos por H2-H6
  solo para encabezados futuros dentro de `Texto`. Su ausencia debe proyectar
  defaults de código sin bloquear Blog ni reescribir documentos existentes.
  Exigir
  `blog.settings.manage`, mantenerla no delegable y comprobar el gate propio
  antes de exponer la pantalla o el servicio.
- Tratar `0014_blog_private_draft_publication` como una frontera aditiva y
  opcional. `editorial_workspaces` apunta a la revisión privada y conserva la
  versión pública de partida; `publication_heads` apunta a la revisión
  inmutable aprobada y a una versión creciente. Mantener las categorías en una
  frontera post-wide independiente mediante `category_assignment_heads`,
  `category_assignment_workspaces` y `category_assignment_workspace_items`, con
  CAS propio de `category_workspace_version`; no asociarlas al workspace de un
  locale. Guardar categorías nunca toca la asignación live. Cuando `0020`–`0024`
  están listas, mantener además el head y workspace de etiquetas por
  localización, con CAS `tag_workspace_version`; guardarlas tampoco toca live.
  Guardar una variante publicada crea una revisión privada y avanza su lock
  editorial sin tocar metadatos, documento, medios ni cabecera públicos.
  `Publicar` consume atómicamente las versiones global exacta de categorías y
  localizada exacta de etiquetas, promociona la última instantánea, actualiza
  proyecciones y ambas taxonomías, avanza la cabecera, aplica el fencing del
  sitemap, audita y limpia los workspaces consumidos. Al retirar, adoptar
  el snapshot editorial privado como borrador antes de jubilar su workspace y
  cabecera; retirar o enviar un locale a la papelera preserva el head y workspace
  global de categorías y las relaciones live/privadas de etiquetas. Sin el
  gate, conservar el flujo anterior y la variante publicada de solo lectura
  hasta su retirada.
- Resolver la firma pública del artículo en vivo desde la identidad de autor y
  su perfil actual. No congelar apodo o rol dentro de revisiones o snapshots de
  publicación: una modificación posterior del perfil se refleja también en
  los artículos históricos.
- Reservar `Dummy` como categoría interna oculta de los selectores editoriales
  ordinarios. Una variante Dummy fuerza `noindex,nofollow`, deshabilita esos
  controles y queda fuera del sitemap, feeds y recursos públicos salvo una
  consulta de QA explícita. Los fixtures Matrix nunca pueden activar indexación
  por configuración del editor. El catálogo privado debe excluir siempre el
  UUID canónico antes de filtros, orden y paginación, sin opt-out GET, y fallar
  cerrado si la política reservada no está disponible. `0017` crea la identidad
  canónica y `0018` normaliza aditivamente las asignaciones live y workspace que
  aún apunten a un slug legacy exacto `dummy`, sin borrar datos históricos.
- Proyectar el editor sobre un lienzo neutral con `role="document"`, no sobre
  otro `main` ni otro H1 real dentro de WebAdmin. Para documentos nuevos exigir
  que cada `section` comience con un `Texto` cuya primera unidad sea un
  encabezado: proponer H2 por defecto, permitir H2-H6 y prohibir H1. Mover o
  retirar ese encabezado estructural debe operar sobre todo su subárbol
  semántico; la representación visual y el renderer público tienen que
  conservar el mismo orden y jerarquía.
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
- Al cargar V1 o V2 anterior, proyectar también en memoria y uno a uno cada
  Párrafo, Título, Lista, Cita y Destacado al flujo canónico de `Texto`,
  conservando UUID, orden, presentación, contenido, IDs y metadatos específicos.
  Abrir o cancelar nunca escribe. Guardar, restaurar, duplicar o copiar un locale
  persiste la forma unificada y no puede reintroducir módulos textuales
  independientes; las revisiones ya existentes permanecen inmutables.
- Cada guardado efectivo crea una revisión inmutable dentro de la misma
  transacción que documento, referencias, lock y auditoría. En `draft` alimenta
  el estado actual; en `published` con `0014` alimenta solo el workspace privado
  y deja intacta la proyección pública. Normalizar el documento antes de validar
  y persistir. Restaurar exige la `lock_version` actual y crea una revisión nueva
  canónica; nunca actualiza ni elimina la revisión elegida.
- Usar `composer liquidstack:blog:adopt-unified-text` sin flags como dry-run
  global y de solo lectura. Excluir siempre Dummy y papelera. Ejecutar `--apply`
  únicamente con `--yes`, un `--actor` elegible, exactamente un `--post` y un
  `--locale`; volver a comprobar bajo lock actor, post, locale, estado, versión y
  huella. En `draft`, crear current+revisión; en `published`, crear o avanzar solo
  el workspace privado. Nunca publicar, modificar la cabecera pública, reescribir
  revisiones históricas ni registrar el comando como hook automático de Composer.
- Mantener el formulario SSR como fallback y mejorar el guardado sin perder
  estado. Sincronizar primero `document_json`. Cuando se envíe
  `X-LiquidStack-Editor: async`, aceptar como éxito solo un `200` JSON con `ok`,
  `lock_version`, `document_sha256` y documento canónico válido; la publicación
  asíncrona debe confirmar `ok`, `status` y `lock_version`. El POST SSR conserva
  el `303` al editor exacto del mismo post y locale. Ante `403`, `409`, `422`,
  content type o JSON inesperado, login o fallo de red, conservar campos y
  bloques, mostrar un mensaje genérico y no navegar. Interceptar el submit antes
  de sincronizar o validar para que una excepción no active la navegación
  nativa. No registrar `beforeunload`, ni usar `window.confirm` o `alert`: los
  enlaces internos y el logout con cambios pendientes deben usar un diálogo
  LiquidStack accesible para guardar, descartar o seguir editando. El cierre o
  recarga de la pestaña queda sin prompt nativo por decisión de producto.
- Diferenciar `Guardar borrador` de `Publicar`: el primero solo confirma la
  instantánea de trabajo; `POST /admin/blog/editor/publish` promociona la última
  instantánea guardada. El formulario SSR puede rotular `Publicar borrador
  guardado`; la preview inmersiva ofrece `Guardar borrador`, `Publicar`,
  `Volver al editor` y controles desktop/tablet/móvil.
- Exigir `blog.articles.edit` y `webadmin.media.view` para abrir, guardar o
  restaurar el editor; preview e historial exigen `blog.articles.view` y
  `webadmin.media.view`. Publicar el snapshot guardado exige conjuntamente
  `blog.articles.edit`, `blog.articles.publish` y `webadmin.media.view`. La subida
  se realiza en `/admin/media` y requiere además `webadmin.media.upload`.
  Revalidar dentro de la transacción que todos los UUID de imagen existen y
  tienen variantes AVIF.
- El catálogo del editor debe incluir el tramo reciente y, además, todos los
  medios referenciados por el documento actual aunque sean anteriores. Mantener
  esa ampliación como interfaz opcional y aditiva para no romper runtimes Media
  anteriores; nunca eliminar una referencia válida de la UI solo por quedar
  fuera de la ventana reciente.
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
- Para índices project-owned, resolver una sola vez
  `BlogPublicIndex::current()` mediante el soporte gestionado
  `App/app/_moduleBlogPublicIndex.php`. Ese único require previo al `DOCTYPE`
  recibe `BlogPublicIndexInput` y `BlogPublicIndexTextCatalog`, resuelve preview
  opcional, HTTP/SEO, CSP, redirects y HEAD y deja disponible el
  `BlogPublicIndexPage`. La vista se limita a maquetar llamadas explícitas a
  controladores, sin superglobals, PDO, consultas, buffering, cabeceras ni HTML
  de recursos precompuesto; anotar junto al primer sniper Blog la dependencia
  del soporte para que la composición sea copiable.
  Invocar los controladores sin `if` de visibilidad: paginación, archivo y cada
  recurso devuelven `''` cuando sus datos no producen una representación útil.
  Mantener `body`, `main`, `section` y wrappers de composición neutros, sin
  clases para corregir recursos desde la vista; solo se permiten IDs y
  `data-*` funcionales. Cuando resultados, paginación, archivo u otros recursos
  compartan un target reactivo, encapsular ese target en un recurso compositor:
  preparar los controladores hijos en variables e inyectarlos en slots
  opcionales de su template, que posee clase, SCSS y hooks. No escribir ese
  contenedor funcional a mano en la vista. En el índice público,
  `moduleBlogResults01` posee de forma singleton
  `#blog-results[data-blog-results]`; ampliar ID o multiinstancia requiere un
  cambio coordinado de formularios, paginación, historial y URLs. Nav, footer y
  shell global se
  incluyen de forma incondicional; una petición parcial puede transportar el
  mismo documento SSR y dejar que el runtime extraiga su target. Configurar el
  lote y las rutas
  limpias con `public_index.page_size` y `public_index.pagination_paths`,
  declarando esas rutas en el router del proyecto. Reutilizar el mismo feed
  para resultados, filtros y archivo; no combinar
  `BlogCategoryPublicFeedFactory` legacy con la fachada general. Mantener
  paridad GET/HEAD/partial en status, cache, robots y canonical, y degradar un
  fallo de runtime o PDO a la página 503 tipada.
  La CSP y el nonce proceden exclusivamente de la configuración compartida
  `App/config/modules/blog-public.php`. El fichero legacy
  `App/config/modules/blog-public-index.php` queda reservado a una fuente
  `preview` opcional de desarrollo; no declarar allí seguridad nueva ni
  mantenerlo cuando no exista preview.
- En el índice público, renderizar el H1 dentro de un recurso hero cuya raíz
  sea `<header>` y antes de `<main>`; nunca dentro de una `<section>` anónima.
  Componer búsqueda, categorías y `moduleBlogResults01` como hermanos dentro
  de un recurso `sectionBlog*` con H2 cuando representen el mismo contexto.
  Los formularios deben permanecer fuera de `#blog-results`: filtros sustituye
  su interior y paginación sustituye su raíz, por lo que introducirlos en el
  target destruiría sus listeners. Bajo el H2 del compositor,
  `moduleBlogGrid02`, `moduleBlogPagination01` y `moduleBlogArchive01` son
  siempre módulos de raíz
  neutra: conservan clases, hooks, headings útiles y enlaces, pero no crean
  otro landmark `section`/`nav` ni publican modos para cambiar su tag. El
  compositor de resultados exige hijos `div` sin `role`; él y el catálogo
  rechazan slots que reintroduzcan esos landmarks, y las cards escalan como
  `<article>`/H3 respecto al H2 exterior.
  `sectionBlogRelated01` conserva su
  propia raíz `<section>`, H2 y cards `<article>`/H3, y desaparece con 0 items.
- Convertir la query GET pública en un `BlogPublicCatalogQuery` acotado y usar
  `BlogPublicFeed::cardsForQuery()`. Mantener `category_mode` en la allowlist
  `any|all`, un máximo de diez categorías simultáneas y los límites de
  búsqueda,
  paginación y exclusión del value object; no pasar arrays o SQL construidos
  desde el request al repositorio. Hacer que `q` busque también nombre y slug
  de etiquetas live del locale mediante `EXISTS`; no consultar workspaces ni
  duplicar cards. No añadir `tag[]`, selector, archivo, ruta, canonical,
  hreflang o sitemap de etiquetas: siguen siendo señal de la búsqueda textual
  y metadatos informativos.
- Para composiciones visuales dinámicas, construir un
  `BlogPublicResourceQuery` y mantener `order` en la allowlist cerrada
  `newest|oldest|updated`. Usar `BlogPublicResourceFeed::batch()` cuando haya
  continuación: el array API reserva la fila de lookahead y devuelve un
  `BlogPublicResourceBatch` con `items`, `has_next`, `next_offset` y
  `next_url`; al construir el value object directamente, exigir
  `limit > items`. La URL siguiente la calcula el proyecto y debe ser relativa
  a la raíz; el feed no resuelve rutas.
- En vistas que solo necesitan una colección pública acotada, requerir como
  único soporte `App/app/_moduleBlogPublicCollections.php` y consumir
  `$blogPublicCollections->latest($locale, $limit)`. El resultado tipado
  distingue `ready`, `empty` y `unavailable`; el hook no emite headers ni HTML.
  No instanciar `BlogPublicResourceFeed`, factories ni queries dentro de la
  vista; una selección avanzada debe encapsularse en soporte backend propio.
- Resolver categorías y etiquetas live de todas las cards de un lote en un
  batch compuesto, con un máximo de 50 slugs de categoría, 30 etiquetas por
  variante, exclusión obligatoria de Dummy y proyección sin IDs. Añadir `tags`
  solo a las APIs enriquecidas y conservar exactamente el shape histórico de
  `BlogPublicFeed::cards()`. Un gate pendiente devuelve `tags=[]` sin consultar
  tablas ausentes; un registro aplicado con esquema corrupto falla cerrado.
  Enriquecer su media mediante el repositorio batch opcional Blog+WebAdmin:
  para cada lote no vacío debe ejecutar como máximo dos `SELECT` constantes. El
  primero lee el documento `CURRENT` publicado y sus referencias; solo cuando
  de esa fase resulte algún medio elegible, el segundo carga sus variantes.
  Nunca hacer una consulta por localización o card. Seleccionar la portada o,
  si falta, la primera imagen por orden canónico del documento; proyectar
  solo metadatos AVIF válidos. Emitir `thumbnail.src` con la mayor variante de
  hasta 900 px y `srcset` ascendente, sin IDs ni `sizes` desde backend. Ante
  schema o storage no preparados, referencia ajena, corrupción o ausencia de
  candidato pequeño, omitir la miniatura afectada y conservar la card textual.
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
- Para Grid02, Slider02 y Stack01, cargar el runtime compartido module-owned
  `src/js/modules/blog/blogCollectionLoader.js`. Mantener un enlace siguiente
  SSR navegable y mejorar solo `GET` HTML same-origin en modo `manual` o
  `near-end`; validar ID y tipo de la colección devuelta, deduplicar por
  `data-blog-card-key`, rechazar lotes sin progreso, conservar estados
  accesibles de carga/error/reintento/fin, acotar el número de lotes y abortar
  generaciones obsoletas al reemplazar resultados o desmontar. Emitir y
  consumir `liquidstack:blog-collection-appended` para reconciliar únicamente
  las cards nuevas.
- Mantener `blog-admin.css` y `blog-editor.js` bajo
  `modules/blog/published/assets`; el manifiesto los sincroniza como assets
  module-managed en `public/assets/modules/blog`. No integrarlos en el bundle
  general ni convertirlos en ficheros project-owned. Revisar `blog.assets` en
  `doctor`: un destino ausente o inválido debe bloquear con
  `assets.missing_or_invalid`, sin intentar reparar DB o storage.
- Mantener la administración visualmente plana y funcional. No introducir por
  defecto bordes laterales de acento (`border-left` o
  `border-inline-start`), franjas mediante pseudoelementos, rebordes
  decorativos ni cadenas de tarjetas anidadas con borde y radio en cada
  nivel. Expresar jerarquía mediante espacio, tipografía, grid y fondos
  sobrios. Reservar los bordes para controles, foco, tablas, separadores o
  estados seleccionados donde cumplan una función perceptible; comunicar
  estado con texto, icono y color accesible, no con una raya lateral.
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
  `sectionBlogFeatured01`, `sectionBlogRelated01`, `sectionBlogSlider01`,
  `moduleBlogGrid02`, `sectionBlogSlider02` o `sectionBlogStack01`.
- Mantener `moduleBlogGrid02` en variantes `regular|bento`, con fallback
  visible, título que hereda el nivel tipográfico del heading y un CTA
  `cta_label` localizado, accesible y sin sombra por card. En `regular`, las
  filas desktop completas deben conservar tres cards del mismo ancho y solo
  los restos reales de una o dos cards se centran; una regla tablet no puede
  ensanchar el último item al cruzar el breakpoint. `moduleBlogArchive01`
  mantiene raíz neutra, pero posee su ancho autocontenido y centra las filas
  incompletas 1/2/N. Limitar el revelado GSAP a cards nuevas. En
  `sectionBlogSlider01` y
  `sectionBlogSlider02`, no exponer una opción `wrap`: cualquier conjunto con
  uno o más originales debe formar un bucle visual continuo cuando la mejora
  JavaScript sea elegible. Emitir cada card una sola vez en SSR y crear solo en
  runtime los conjuntos de copias necesarios a ambos lados del carril; hacerlos
  `inert`, `aria-hidden` y de presentación, sin IDs, IDREF, claves de card ni
  elementos enfocables. Compartir el propietario de cada raíz entre identidades
  ESM/HMR para impedir desmontajes cruzados, y reconstruir las copias al cambiar
  la geometría sin acumularlas. Conservar Draggable+Inertia, snap, autoplay,
  pausa/reanudación visible, teclado y varias instancias; Slider02 mantiene por
  defecto 6 s de espera y 2 s de transición. Pausar ante interacción, hover,
  foco, documento oculto, salida del viewport o movimiento reducido y limpiar
  copias, Draggable, tweens, timers, listeners y observadores al
  reinicializar/HMR. Cero items permanece vacío; sin GSAP, con RTL o movimiento
  reducido, conservar el fallback SSR accesible. En
  `sectionBlogStack01`, activar ScrollTrigger solo con 3–8 cards, desktop,
  altura suficiente, sin lote pendiente y sin movimiento reducido; nunca
  secuestrar el scroll y conservar siempre la lista vertical SSR.
- Mantener `resource-support` como grupo independiente y su helper como API
  estable y aditiva: no retirar ni cambiar las firmas de
  `liquidstack_blog_resource_context()`,
  `liquidstack_blog_resource_escape()`, `liquidstack_blog_resource_card()` o
  `liquidstack_blog_resource_heading()`, ni las claves existentes del contexto.
  Un cambio incompatible exige helper versionado o migración coordinada de la
  familia; no congelar todos los recursos en un único grupo por conveniencia.
  Normalizar aditivamente `categories` y `media|thumbnail`: limitar categorías,
  sanear sus URLs opcionales y exigir `src`, `alt`, `width` y `height` válidos
  para renderizar una imagen lazy. Validar estrictamente la URL de `src`, cada
  candidato ascendente de `srcset` y la gramática acotada de `sizes`; un
  atributo responsive opcional inválido no debe descartar un `src` pequeño
  válido. Aplicar el `sizes` por recurso en el helper, nunca fijarlo en la
  proyección backend.
- Mantener los hooks gestionados en grupos independientes:
  `App/app/_moduleBlogPublicIndex.php` en `public-index-support`,
  `App/app/_moduleBlogPublicArticle.php` en `public-article-support` y
  `App/app/_moduleBlogPublicCollections.php` en
  `public-collections-support`. No agruparlos con `resource-support`: una
  personalización del helper o loader visual no debe impedir instalar o
  actualizar el adaptador backend que requiere cada vista.
- RESOURCE-001 forma parte de CORE principal desde este corte `Unreleased`.
  Mantener como gate de publicación sus pruebas técnicas y la QA funcional y
  visual tras instalarlo en un consumidor; no confundir integración con release.
- Si el contrato de `src/scss/_config.scss` no está disponible, publicar solo
  los assets autocontenidos de cada módulo bajo sus namespaces
  `public/assets/modules/<id>`, `src/js/modules/<id>` y
  `src/scss/modules/<id>`. Omitir conjuntamente en ese ciclo todos los recursos
  estándar y hooks de showroom para no dejar contratos visuales parciales; la
  siguiente actualización con el config reparado debe instalar el conjunto.
- Mantener los controladores visuales libres de PDO, prefijos e IDs internos.
  Reciben únicamente arrays de presentación. Los fixtures Matrix pertenecen
  al showroom y nunca actúan como fallback de la DB pública. Mantener la fuente
  de sus 16 derivados responsive en CORE bajo
  `resources/img/dummy/responsive`, declararlos como ficheros gestionados por
  Composer y sincronizarlos al consumidor; cubren 480, 899/900, 1800 y 2560 px
  y solo se usan cuando la card no trae ya `thumbnail` o `media` del feed. Al
  trasladar un fichero gestionado desde CORE base al módulo, reconocer solo
  huellas legacy verificadas bajo el nuevo source ID; conservar cualquier copia
  desconocida.
- Tratar `blog-public.js` como el runtime progresivo module-owned de los
  bloques públicos. Un bloque YouTube conserva siempre su enlace externo SSR;
  el script solo puede impedir esa navegación ante un clic primario sin
  modificadores y después de comprobar `cookie_social=true`. El iframe debe
  usar exclusivamente `youtube-nocookie.com`, nacer tras el clic y retirarse
  al revocar consentimiento. No precargar miniaturas de terceros. El fallback
  standalone carga el asset con `script-src 'self'`; cada shell project-owned
  debe cargarlo con su nonce y limitar `frame-src` al mismo origen.
- Mantener `blog-analytics.js` separado y cargarlo desde `blog-public.js`
  únicamente con marcador SSR explícito y `cookie_analytics=true`. No usar
  IP, `User-Agent`, referrer, email, sesión WebAdmin, localStorage ni
  sessionStorage como identidad. Excluir cualquier navegador que presente la
  cookie WebAdmin sin leer ni persistir su valor. La revocación elimina
  `LS_BLOG_AV`/`LS_BLOG_AS`; los POST exactos pre-bootstrap no crean
  `PHPSESSID`. Refrescar la cookie de sesión solo mientras exista actividad
  visible/en foco y conservar el mismo token.
- Validar transporte, origen y esquema JSON completo antes de construir el
  runtime analítico o abrir PDO. Mantener un orden de locks único
  padre→hijo: post→localización en mutaciones editoriales y
  sesión→vista en ingestión analítica.
- El cliente nunca elige ruta, localización o UUID de vista: enviar únicamente
  el `page_grant` HMAC efímero emitido por SSR. Validar firma, origen y
  caducidad antes de PDO, fallar cerrado si una vista project-owned no adopta
  el atributo y servir HTML con grant como `private, no-store`. No incluir el
  grant en URLs, logs, `__debugInfo` ni diagnósticos.
- Aplicar `analytics.retention_days` con el comando one-shot explícito
  `composer liquidstack:blog:analytics:purge --yes`. No purgar durante
  Composer ni peticiones públicas y no instalar cron desde CORE. Documentar y
  verificar el scheduler externo de cada producción antes de activar la
  colección.
- Probar SQLite aislado y, ante cambios de DDL, repositorio, locks o auditoría,
  la integración opt-in MySQL/MariaDB. Cubrir el catálogo exacto `0020`–`0025`
  y create/add-locale/save/publish/unpublish, categorías, etiquetas privadas y
  live, documento canónico, adopción legacy, revisiones,
  restauración, medios, stale writes con dos PDO, rollback conjunto de
  contenido y auditoría, prioridad estática, sitemap y ausencia de mutaciones
  en `HEAD`. Con `0014`, demostrar además separación privada/pública, promoción
  atómica, adopción del snapshot editorial al retirar, CAS post-wide de
  categorías entre locales, preservación de su head/workspace al retirar o
  enviar una localización a papelera y preview sin mutaciones. Para analítica,
  cubrir ausencia de consentimiento, revocación, exclusión WebAdmin,
  idempotencia, foreground time, retención y ausencia de IP/UA crudos.
- Para duplicar y añadir locale, cubrir además documento/revisión inicial,
  categorías privadas frente a live, workspace stale o corrupto, rollback de
  auditoría, replay del mismo `operation_id`, reutilización incompatible y
  carrera real con dos procesos y dos conexiones MySQL/MariaDB. Repetir la
  carrera para detectar intermitencias y demostrar en el teardown que no quedan
  tablas, usuarios ni datos QA del entorno aislado. Verificar que la fuente
  queda idéntica y que cancelar, GET, HEAD o abrir cualquiera de las dos
  lecturas privadas no escribe.
- Para etiquetas, forzar con dos procesos y dos conexiones la carrera de alta
  de una misma identidad Unicode: ambos resultados deben converger en un único
  término canónico. Repetir con dos identidades distintas que colisionen en el
  mismo slug y exigir que ambas sobrevivan con resolución determinista, sin
  deadlock ni error de almacenamiento. Volver a verificar esquema,
  capabilities, CAS y cleanup exacto de tablas y marcadores; nunca ejecutar el
  arnés contra la DB de ningún consumidor o proyecto real.
- Consultar `docs/liquid-blog.md` y `docs/blog-seo-editorial.md` como contratos
  completos antes de ampliar el módulo. No presentar categorías, etiquetas,
  AVIF, el editor V1/V2, el workspace privado, la preview inmersiva ni el
  medidor SEO editorial v1 como pendientes. La integración técnica no sustituye
  la validación de adopción previa a la release; siguen pendientes traducción
  IA, Search Console o Indexing API, vídeo local y el maquetador libre.

## Cerrar herramientas funcionales de los módulos

- Usar además `$test-functional-ui` al crear, cambiar, auditar o cerrar
  cualquier interfaz funcional de WebAdmin o Blog. Seguir por completo sus dos
  capas obligatorias: validación técnica y exploración práctica de todos los
  recorridos de usuario en navegador real.
- Aplicar en esas sesiones las restricciones propias de LiquidStack: instalar
  primero el corte gestionado en un consumidor controlado; comprobar `doctor`,
  gates y permisos; cubrir SSR y ausencia de JavaScript; operar datos, base de
  datos y medios solo mediante puertos oficiales; y terminar con una limpieza
  recuperable y una huella posterior.
- No dar por cerrada una UI únicamente porque estén verdes los unitarios,
  contratos, harnesses o integraciones. Repetir tras Composer los recorridos
  funcionales, visuales, responsive, accesibles y adversos que correspondan.
- Cuando el comportamiento pertenezca a CORE, promover con el arreglo su
  regresión automatizada y el inventario de recorridos; repetir la exploración
  en al menos un stack consumidor.

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

   - Core-only: no reserva `/admin`, no registra diagnósticos opcionales y el
     onboarding falla antes de leer entorno, DB o SMTP.
   - WebAdmin: reclama solo su prefijo, no inicia la sesión legacy y el
     onboarding verifica exactamente las dos identidades protegidas.
   - Blog: activa primero WebAdmin, sus migraciones asignan las capacidades y
     repetir onboarding verifica las identidades y el acceso sin duplicar
     usuarios ni entregas válidas.
   - `composer install`/`update`: no mutan DB, cuentas, outbox o SMTP; el
     onboarding sin confirmación tampoco lo hace.
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
- Antes del primer onboarding, obtener desde la fuente privada del operador las
  dos variables bootstrap distintas y preparar el transporte. No copiar sus
  valores a documentación, `.env.example`, commits, salidas o informes.
- Registrar el entorno real antes de operar. El consumidor de referencia usa
  actualmente una DB modular local de XAMPP; otros consumidores pueden usar
  local, staging o
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
- Después de aplicar las migraciones e inicializar Media cuando corresponda,
  ejecutar `liquidstack:webadmin:onboard --yes`. No cerrar una instalación
  nueva hasta que su salida verifique las dos cuentas protegidas y el usuario
  sepa que aún debe activar los enlaces recibidos para disponer de acceso.
- Si el proyecto ya tiene datos bajo `shared`, no cambiar la selección hasta
  disponer de backup y un traslado manual verificado del esquema, contenido y
  registro de migraciones.
- Verificar selectores activos, salida de `doctor`, rutas públicas, `/admin`, ausencia de `Set-Cookie` legacy en la ruta neutral y suite/build propios del consumidor.
- No publicar una etiqueta ni ejecutar deploy sin autorización expresa.
