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
  perfil ni el flujo de `npm run build`.
- Reservar `LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL` y `LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL` para el bootstrap explícito. No mostrar sus valores.
- Configurar el correo solo mediante
  `LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN`, `LIQUIDSTACK_WEBADMIN_SMTP_HOST`,
  `LIQUIDSTACK_WEBADMIN_SMTP_PORT`,
  `LIQUIDSTACK_WEBADMIN_SMTP_ENCRYPTION`,
  `LIQUIDSTACK_WEBADMIN_SMTP_USERNAME`,
  `LIQUIDSTACK_WEBADMIN_SMTP_PASSWORD`,
  `LIQUIDSTACK_WEBADMIN_MAIL_FROM_ADDRESS` y
  `LIQUIDSTACK_WEBADMIN_MAIL_FROM_NAME`. El origen debe ser HTTPS explícito y
  nunca derivarse de `Host` o cabeceras `Forwarded`.
- Para pruebas locales, usar únicamente el perfil tipado
  `LIQUIDSTACK_WEBADMIN_MAIL_TRANSPORT=local_capture_smtp`: exige
  `DEV_MODE=1`, `RAIZ` HTTP loopback y SMTP en `127.0.0.1` o `[::1]`, toma el
  origen del enlace de `RAIZ` y prohíbe origen legacy, TLS, usuario y
  contraseña SMTP. Arrancar el capturador externo ligado solo a loopback y sin
  relay/forwarding. No instalarlo desde CORE ni sustituirlo por un comando que
  revele tokens en consola. Fuera de ese perfil el dispatcher debe fallar
  antes de PDO/transporte y el SMTP productivo conserva STARTTLS/SMTPS y auth.
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
  configuración SMTP ausente bloquea el dispatcher, no el login ni el
  bootstrap que solo encola trabajo. `mail_ready` valida configuración, no
  conectividad; comprobar que el capturador local escucha antes del dispatch
  para no consumir un intento del outbox.
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
- No copiar a informes credenciales, SQL ni mensajes internos de PDO.
- Una petición insegura o malformada debe fallar con `400` antes de abrir PDO.
  Cuando el runtime, la conexión o el esquema no están listos, un `503`
  genérico en `/admin` es el fallo cerrado esperado.

## Operar bootstrap y correo

1. Ejecutar en orden `doctor`, `migrate --dry-run`, `migrate --apply`,
   `liquidstack:webadmin:bootstrap` y
   `liquidstack:webadmin:mail:dispatch`. `--apply` y bootstrap exigen sus
   propias confirmaciones; el dispatch es una invocación explícita con efecto
   SMTP y bootstrap únicamente encola invitaciones.
2. Programar `liquidstack:webadmin:mail:dispatch` como tarea one-shot
   recurrente, con `--limit` entre 1 y 100. No convertirlo en daemon ni
   registrar destinatarios, tokens o diagnósticos SMTP.
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

## Operar Liquid Blog

- Activar Blog solo mediante el selector directo `liquidstack/blog`; su cierre
  de dependencias debe activar WebAdmin antes. No registrar rutas, navegación,
  migraciones ni diagnósticos Blog en un proyecto core-only o WebAdmin-only.
- Tratar `App/config/modules/blog.php` como configuración project-owned. Puede
  declarar `public_paths` por cada idioma activo, `sitemap_path` y el prefijo
  de tablas. `shared` permanece como default; si se declara `liquidstack`,
  WebAdmin debe declararlo también. Composer no debe crear, fusionar ni
  sobrescribir este fichero.
- Exigir que las claves de `public_paths` coincidan exactamente con
  `App/config/langs.php`, que sus rutas sean absolutas y únicas y que no
  colisionen con rutas o ficheros del proyecto. El path base puede pertenecer a
  un índice estático; las URLs de artículo viven en `{public_path}/{slug}`.
- Usar `RAIZ` como origen canónico del sitemap y de los artículos: HTTPS fuera
  del laboratorio y HTTP solo con el perfil loopback tipado de desarrollo.
  Mantener `LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN` como alias de transición y
  conservarlo temporalmente si difiere en producción para no cambiar URLs
  durante un update; `doctor` debe avisar hasta alinear ambos valores. En local
  debe prevalecer la `RAIZ` loopback. No derivar el origen de `Host`,
  `Forwarded` ni del request. Blog no debe depender de que el transporte SMTP
  esté listo.
- Aplicar en orden `doctor`, `migrate --plan`, `migrate --dry-run`,
  `migrate --apply`, `webadmin:bootstrap` y un segundo `doctor`. Repetir el
  bootstrap es obligatorio al añadir Blog a un WebAdmin ya inicializado para
  completar de forma idempotente las capacidades de las cuentas protegidas.
- Mantener separados `blog.articles.view`, `blog.articles.edit` y
  `blog.articles.publish`. Ocultar botones no sustituye el gate transaccional:
  SID, CSRF, lifecycle, `auth_version` y capability deben revalidarse con el
  mismo PDO y dentro de la transacción Blog.
- Servir la vista previa guardada solo dentro del prefijo privado de WebAdmin y
  con `blog.articles.view`. No convertirla en una URL compartible ni emitir
  canonical, metadatos SEO públicos o entradas de sitemap; debe usar
  `no-store`, `noindex` y no realizar mutaciones ni auditoría editorial.
- Conservar el agregado y cada variante por idioma como unidades estables. En
  el MVP solo existen `draft` y `published`; una variante publicada se retira
  antes de editarla, cada escritura usa `lock_version` y no existe borrado HTTP.
- Mantener el cuerpo como texto plano validado. No introducir HTML libre,
  uploads, bloques, categorías, traducción IA o programación dentro del MVP
  0001 por conveniencia local.
- Resolver las URLs de artículo Blog únicamente después de que fallen las rutas
  estáticas del proyecto. Un borrador o slug desconocido continúa al 404 legacy;
  un runtime reconocido pero no operativo responde `503`, y un método de
  escritura reconocido responde `405` sin abrir PDO.
- Declarar el sitemap como endpoint público exacto pre-bootstrap para que no lo
  intercepte el resolver multidioma ni cree `PHPSESSID`. Antes de despacharlo,
  preservar una ruta GET exacta, un fichero o symlink público y una subruta de
  showroom project-owned; ante un catálogo GET incompleto, continuar por legacy.
- Servir el sitemap desde la DB de producción. Nunca reescribir
  `public/sitemap.xml`, `robots.txt`, Git o el deploy al publicar. Consultar como
  máximo 50.001 filas para admitir 50.000 y fallar cerrado ante overflow, sin
  truncar silenciosamente.
- Probar SQLite aislado y, ante cambios de DDL, repositorio, locks o auditoría,
  la integración opt-in MySQL/MariaDB. Cubrir create/add-locale/save/publish/
  unpublish, stale writes con dos PDO, rollback conjunto de contenido y
  auditoría, prioridad estática, sitemap y ausencia de mutaciones en `HEAD`.
- Consultar `docs/liquid-blog.md` como contrato completo antes de ampliar el
  módulo. Categorías, medios, editor enriquecido, IA y recursos de showroom
  requieren cortes versionados posteriores.

## Modificar la infraestructura en CORE

1. Leer `docs/arquitectura-modulos-internos.md` y el manifiesto `modules/<id>/module.json`.
2. Mantener providers ejecutables solo para módulos activos y validar su interfaz, `moduleId` y construcción sin argumentos cuando corresponda.
3. Resolver rutas operativas antes del bootstrap, sesión y router multidioma legacy. Una ruta no reclamada debe continuar exactamente por el flujo anterior.
4. Hacer que una configuración inválida falle cerrada dentro de su namespace sin derribar rutas públicas ni revelar la causa al visitante.
5. Mantener `project_files` limitado a namespaces de assets del módulo. Rutas, `.env`, configuración, sitemap y datos siguen siendo project-owned.
6. Registrar toda migración con ID estable, checksum, orden por dependencias y
   carácter destructivo. Su aplicación debe seguir siendo explícita, bloqueada
   y auditable; `doctor` y `--dry-run` nunca escriben.
7. Probar como mínimo estas matrices:

   - Core-only: no reserva `/admin` ni registra diagnósticos opcionales.
   - WebAdmin: reclama solo su prefijo y no inicia la sesión legacy.
   - Blog: activa primero WebAdmin.
   - GET y POST públicos: conservan el comportamiento legacy con módulos activos.
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
