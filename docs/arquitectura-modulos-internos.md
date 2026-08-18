# Módulos internos de LiquidStack

LiquidStack mantiene un único repositorio, paquete físico, versión y release:
`liquidstack/core`. WebAdmin y Blog viven dentro de CORE como módulos internos;
los nombres `liquidstack/webadmin` y `liquidstack/blog` son selectores lógicos,
no repositorios ni descargas independientes.

## Selección desde un proyecto

La selección se obtiene únicamente de las dependencias directas de producción
del `composer.json` raíz:

| `require` directo | Capas activas |
| --- | --- |
| `liquidstack/core` | Core |
| `liquidstack/webadmin` | Core + WebAdmin |
| `liquidstack/blog` | Core + WebAdmin + Blog |

Blog declara internamente su dependencia de WebAdmin. No se inspeccionan
`require-dev`, `replace`, `provide`, `composer.lock` ni
`Composer\InstalledVersions`: CORE reemplaza ambos nombres lógicos y esas
fuentes producirían falsos positivos.

Con una versión compatible de CORE ya instalada y sus plugins habilitados:

```bash
composer require liquidstack/webadmin
composer require liquidstack/blog
```

El plugin transforma únicamente esos nombres exactos en selectores `:*` antes
de que Composer intente buscarlos como paquetes físicos. Si se usa una versión
antigua de CORE, el fallback explícito es:

```bash
composer require liquidstack/webadmin:*
composer require liquidstack/blog:*
```

Con `--no-plugins` el alias puede quedar registrado, pero no se ejecuta la
sincronización post-update. Después debe ejecutarse `composer install` o
`composer update` con el plugin de CORE habilitado; `:*` no sustituye ese hook.

No deben instalarse con `--dev`. Para recibir nuevas versiones del código
físico se actualiza CORE:

```bash
composer update liquidstack/core
```

## Manifiestos y cierre de dependencias

Cada módulo dispone de `modules/<id>/module.json`. El manifiesto versiona:

- selector Composer lógico;
- dependencias entre módulos;
- providers por responsabilidad;
- ficheros que, si fueran necesarios, se publican en el proyecto consumidor.

El catálogo valida IDs, nombres de paquete, dependencias, ciclos y rutas
relativas. Las dependencias se ordenan antes del módulo solicitante, por lo que
Blog siempre registra WebAdmin primero.

Los tipos de provider reservados son rutas, middleware, servicios, navegación,
capacidades, migraciones y sitemap. Un provider solo se consulta si su módulo
está activo.

## Shell administrativo compartido

Las páginas administrativas de gestión no pertenecen a `App/views` ni son
plantillas project-owned. WebAdmin compone un shell module-owned de ancho completo con
navegación lateral generada por los providers y filtrada por capacidades, un
único `main` para el contenido de la ruta y un inspector derecho opcional. Blog,
Media, usuarios, revisiones y categorías aportan contenido a esa misma
estructura sin crear layouts administrativos paralelos. Las vistas previas
privadas pueden conservar un documento aislado para proyectar fielmente su
`header` y `main`; siguen dentro del namespace autenticado, con `no-store` y
`noindex`, y no se convierten en una segunda navegación administrativa.

El HTML SSR conserva navegación, inspector y formularios en el flujo normal.
Los toggles solo se convierten en drawers cuando `webadmin.js` ha enlazado el
shell; desde ese momento el runtime sincroniza `aria-expanded`, `aria-hidden`,
foco e `inert`, permite cerrar con Escape y devuelve el foco al control que
abrió la superficie. Un fallo o ausencia de JavaScript no puede ocultar la
navegación ni dejar controles muertos.

El sistema visual es deliberadamente plano. La jerarquía se expresa con
espaciado, tipografía, grid, fondos y separadores funcionales. Los bordes se
reservan para controles, foco, tablas o selección; no se usan bordes laterales
de acento, franjas, pseudoelementos decorativos ni cadenas de tarjetas anidadas
como decoración por defecto.

## Sincronización y propiedad de datos

El PHP de dominio de los módulos permanece en CORE, bajo `vendor`, y no se
duplica en el proyecto. Solo los ficheros declarados expresamente en
`project_files` pueden publicarse. La superficie ordinaria queda limitada al
namespace propio del módulo en `public/assets/modules`, `src/js/modules` o
`src/scss/modules`.

Un módulo puede declarar además una lista cerrada `resources` para publicar
recursos visuales LiquidStack en sus rutas estándar. Cada identificador se
valida y habilita exclusivamente sus ficheros exactos de controlador, template,
SCSS y JS; no concede acceso a directorios completos ni a recursos no
declarados. El módulo puede sumar su helper de familia y los tres hooks exactos
de showroom. En Blog la fuente canónica vive bajo
`modules/blog/resources/project/`, replicando la estructura final del
consumidor.

El diagnóstico operativo distingue ambas superficies. Solo considera
obligatorios los targets module-owned situados bajo
`public/assets/modules/<id>`, `src/js/modules/<id>` o
`src/scss/modules/<id>`; si el manifiesto publica un directorio, resuelve sus
ficheros fuente exactos y comprueba cada destino final. Un directorio runtime
vacío, enlazado o cuyo source atraviese un enlace se rechaza antes del
diagnóstico del consumidor. Controladores,
templates, recursos SCSS/JS y hooks de showroom permanecen fuera de ese gate:
pueden no haberse instalado por el contrato SCSS o haberse preservado de forma
legítima sin declarar inoperativo el runtime PHP del módulo.

Un manifiesto no puede apuntar por esta vía a rutas, `.env`, configuración,
sitemap, DB, storage, medios, copy ni vistas públicas del cliente. Esa
publicación reutiliza el sincronizador seguro de CORE:

- instala ficheros ausentes;
- actualiza únicamente versiones reconocidas como gestionadas;
- conserva personalizaciones locales;
- no usa mirrors destructivos;
- no ejecuta migraciones durante `composer install` o `composer update`.

Cada entrada publicada forma un grupo de actualización independiente por
defecto. Un manifiesto puede dar el mismo `group` al controlador, template,
SCSS y JS de un recurso para actualizarlos atómicamente, sin congelar por ello
los demás recursos ni todos los assets del módulo. Los cambios de ownership
desde el catálogo base solo reconocen huellas históricas verificadas bajo el
nuevo source ID modular; una copia desconocida o personalizada se conserva.

La atomicidad de `managed_hash` se aplica bajo un lock exclusivo por proyecto;
el estado se recarga después de adquirirlo para que dos procesos no decidan con
la misma proyección obsoleta. Primero todos los orígenes mutables del grupo se
copian y verifican en un staging corto bajo
`.liquidstack/core/sync-transactions`; un journal atómico con estados
`staging`, `prepared` y `committed` mapea cada destino, backup y SHA-256 exacto.
Después los destinos
reconocidos se apartan a backup y se promueven los ficheros preparados.

Un fallo en cualquier escritura elimina solo destinos cuya huella demuestra
que proceden del staging y restaura cada original antes de registrar huellas o
contabilizar altas/actualizaciones. Una edición concurrente desconocida nunca
se borra. Si PHP se interrumpe, el siguiente `apply()` recupera obligatoriamente
los journals `prepared` o finaliza el cleanup de los `committed` antes de
planificar. Los backups viven en el mismo volumen del proyecto para que
`rename` sea seguro en Windows. Si incluso la restauración falla, Composer se
detiene, no escribe estado ni continúa otros grupos y conserva journal y
backups para recuperación. El lock reside en el temporal del sistema y el
staging contiene un `.gitignore` interno que excluye journals y backups; solo
`.liquidstack/core/managed-files.json` se versiona. Las políticas
`install_if_missing`, `merge_json_additive` y los ficheros sin grupo conservan
su flujo independiente.

El helper común de una familia puede tener un grupo granular propio únicamente
si su API pública es estable y aditiva. En Blog, `resource-support` conserva
las cuatro funciones de normalización, escape, card y encabezado, sus firmas y
las claves existentes del contexto. Un cambio incompatible requiere versionar
el helper o migrar coordinadamente todos sus consumidores; no debe forzar a
agrupar y congelar recursos independientes.

Los adaptadores públicos Blog viajan en tres grupos independientes y
allowlisted: `App/app/_moduleBlogPublicIndex.php` en
`public-index-support`, `App/app/_moduleBlogPublicArticle.php` en
`public-article-support` y `App/app/_moduleBlogPublicCollections.php` en
`public-collections-support`. No comparten atomicidad con `resource-support`:
una personalización del helper visual o del loader no puede bloquear la
instalación o actualización del hook que requiere una vista. La funcionalidad
reutilizable permanece autoloaded bajo `src/Core/Blog`; cada adaptador traduce
el contexto del stack a contratos tipados y una vista requiere solo el hook de
su superficie.

Los recursos estándar modulares comparten el gate del contrato SCSS base. Si
`src/scss/_config.scss` no puede verificarse, el instalador omite durante ese
ciclo todos sus controladores, templates, SCSS, JS y hooks de showroom, y
publica solamente los assets autocontenidos bajo los namespaces propios
`public/assets/modules/<id>`, `src/js/modules/<id>` y
`src/scss/modules/<id>`. De este modo no deja una familia visual parcial; tras
reparar el contrato, una actualización posterior publica el conjunto completo.

Desactivar o retirar un selector deja de registrar el módulo, pero nunca borra
tablas, usuarios, artículos, medios, uploads, configuración ni ficheros ya
publicados. Las migraciones serán comandos explícitos con preflight, plan,
confirmación y diagnóstico.

Siguen siendo siempre propiedad del proyecto:

- `.env`;
- `App/config/routes/get.php` y `post.php`;
- `App/config/modules/*.php`;
- `robots.txt` y cualquier sitemap existente;
- copy, vistas publicadas, medios y datos del cliente.

### Perfiles de conexión modular

Todos los módulos activos utilizan una única conexión física. El contrato
admite dos nombres lógicos:

- `shared`, default compatible con las instalaciones existentes, obtiene sus
  parámetros de `BBDD_SERVER`, `BBDD_USER`, `BBDD_PASS` y `BBDD_NAME`;
- `liquidstack`, opt-in explícito para una DB modular por proyecto y entorno,
  exige `LIQUIDSTACK_DB_HOST`, `LIQUIDSTACK_DB_PORT`,
  `LIQUIDSTACK_DB_NAME`, `LIQUIDSTACK_DB_USER`,
  `LIQUIDSTACK_DB_PASSWORD` y `LIQUIDSTACK_DB_CHARSET`.

El perfil dedicado valida por separado host, puerto, nombre, usuario,
contraseña y charset antes de construir internamente el DSN. El puerto debe
estar entre 1 y 65535, la contraseña no puede estar vacía y el charset solo
puede ser `utf8mb4`. No se admiten un DSN completo ni opciones PDO procedentes
del entorno. Los diagnósticos muestran únicamente el nombre lógico y los
nombres de variables, nunca sus valores.

La selección se declara en `database.connection` dentro de los ficheros
project-owned `App/config/modules/webadmin.php` y
`App/config/modules/blog.php`. Si Blog está activo, ambos deben usar el mismo
perfil: las migraciones cross-scope, las capabilities, la autorización y la
auditoría requieren el mismo PDO. Una discrepancia o un perfil dedicado
incompleto falla cerrado antes de abrir PDO y nunca cae de vuelta a `shared`.

El prefijo de tablas Blog admite como máximo 29 bytes. El presupuesto se
deriva del sufijo gestionado más largo,
`category_assignment_workspace_items`, para respetar el límite de 64 bytes de
MySQL/MariaDB. Los proyectos configurados con versiones hasta v1.23.0 que
usaron un prefijo de 30–46 bytes requieren una migración explícita del namespace
antes de actualizar; CORE responde con `config.invalid_table_prefix` y nunca
trunca o renombra tablas automáticamente.

Composer no crea, fusiona ni sobrescribe `.env` o esos ficheros de
configuración. Tampoco copia datos al cambiar de perfil. Un proyecto que ya
tenga tablas o contenido en `shared` debe preparar y verificar manualmente un
backup y una migración de datos antes de seleccionar `liquidstack`; mientras
tanto conserva `shared` sin cambios.

El primer contrato dedicado está limitado a `localhost` o redes confiables.
No debe utilizarse a través de un transporte no confiable hasta que CORE
incorpore un perfil TLS probado con CA y verificación del servidor.

El consumidor de referencia desarrolla actualmente estos módulos contra una DB
local de XAMPP. El mismo código deberá poder apuntar en otros proyectos a local,
staging o
producción cambiando únicamente secretos `LIQUIDSTACK_DB_*`. La promoción a
una DB vacía y el traslado de datos existentes son operaciones diferentes;
ninguna se infiere de un cambio de entorno. El diseño y runbook pendientes se
mantienen en
[`promocion-db-modulos-local-produccion.md`](mejoras-pendientes/promocion-db-modulos-local-produccion.md).

### Diagnóstico y plan de migraciones

El plugin registra comandos únicamente en los proyectos consumidores donde
Composer lo ha cargado:

```bash
composer liquidstack:doctor
composer liquidstack:doctor --format=json
composer liquidstack:migrate --plan
composer liquidstack:migrate --dry-run
composer liquidstack:migrate --apply
composer liquidstack:media:init
composer liquidstack:blog:sitemap-cache:init
composer liquidstack:webadmin:bootstrap
composer liquidstack:webadmin:onboard --yes
composer liquidstack:webadmin:mail:dispatch
```

`doctor` valida el catálogo, el cierre de dependencias, los providers activos,
los ficheros base de configuración y los requisitos de WebAdmin. Si WebAdmin
declara migraciones, abre la conexión modular seleccionada y ejecuta un probe
estrictamente de solo lectura: valida el contrato PDO, compara el registro de
migraciones con el catálogo y verifica las postcondiciones del esquema. La
salida estructurada contiene nombres de variables y códigos estables, nunca
credenciales, correos, claves, DSN, SQL o mensajes internos del driver.

El comando exige exactamente uno de estos modos:

- `--plan` es `catalog-only`: valida y enumera metadatos (`module`, `id`,
  descripción, checksum y carácter destructivo), marca la DB como
  `not_evaluated` y no abre conexión.
- `--dry-run` carga el entorno y la configuración de scopes, conecta al perfil
  seleccionado y compara el catálogo con `ls_module_migrations` en solo
  lectura.
- `--apply` parte de ese preview, enlaza su hash como `expectedPlanHash` y el
  runner vuelve a calcular el plan antes y después de adquirir el lock. Es la
  única vía que puede crear el registro o ejecutar SQL.

`--apply` exige `--yes` o confirmación interactiva. En formato JSON exige
siempre `--yes`. Las migraciones destructivas requieren simultáneamente
`--allow-destructive` y `--backup-confirmed`.

Sin esos dos flags, el CLI deja la destructiva pendiente, aplica el prefijo
seguro de cada módulo y avisa del estado restante. Desde la primera pendiente
destructiva también difiere los IDs posteriores de ese mismo módulo para no
registrarlos fuera de orden; otros módulos pueden seguir avanzando. El motor
conserva el bloqueo por defecto para llamadas que no opten expresamente por
este filtrado seguro.

`--lock-timeout` admite entre 0
y 300 segundos. El runtime utiliza la única conexión modular resuelta, el
scope configurable de WebAdmin y `ls_blog_` para Blog, pero nunca imprime
secretos, sentencias SQL ni mensajes internos de PDO.

Una migración puede declarar como destino el scope de una dependencia directa
o transitiva —Blog usa esta capacidad para registrar sus capabilities en las
tablas WebAdmin—. El motor valida el vínculo en el grafo, resuelve el prefijo
efectivo del destino y registra por separado módulo propietario, módulo de
scope, checksum y hash de scope. No se permiten destinos arbitrarios ni
prefijos codificados en el provider. El orden sigue perteneciendo al módulo
propietario y las postcondiciones verifican el estado en el scope efectivo.

El contrato SQL ejecutable es deliberadamente limitado en este corte:

- SQLite ejecuta todo el lote bajo `BEGIN IMMEDIATE`; el lock restaura el
  `busy_timeout` previo y, si falla el commit, intenta rollback antes de
  devolver el error.
- MySQL nunca puede declararse transaccional, porque su DDL puede provocar
  commits implicitos. Toda definicion debe declararse `retrySafe` y cada
  sentencia MySQL debe pertenecer a la whitelist sintactica: `CREATE TABLE IF
  NOT EXISTS`, `INSERT IGNORE`, `INSERT ... ON DUPLICATE KEY UPDATE` o `DROP
  ... IF EXISTS` para objetos admitidos. No se aceptan sentencias multiples.
- La whitelist no sustituye una prueba semantica: el provider sigue siendo
  responsable de usar claves estables y asignaciones idempotentes en inserts.
- Los IDs son append-only dentro de cada modulo. Una migracion pendiente cuyo
  ID ordena antes que otra ya aplicada bloquea el plan como
  `migration.out_of_order`.
- El registro se valida antes de usarlo. En MySQL debe ser InnoDB y conservar
  tipos, nulabilidad, longitudes, `ascii_bin` y PK; en SQLite se comprueban
  afinidades, PK, `WITHOUT ROWID` y el check positivo de batch.
- Una precondición versionada se evalúa en `--dry-run` y vuelve a evaluarse
  bajo el lock de `--apply`, siempre antes de crear el registro o ejecutar
  DDL/DML. Solo la primera migración de cada módulo puede declararla: describe
  una invariante del inicio del lote y nunca un estado producido por otra
  migración pendiente. WebAdmin 0001 exige una versión MySQL/MariaDB con
  metadatos CHECK fiables y un namespace físico vacío: no puede haber
  tablas, vistas, objetos SQLite ni nombres de constraints que empiecen por
  su prefijo.
- `migration.precondition_failed` indica una colisión o un estado parcial.
  CORE no lo adopta, completa, renombra ni elimina automáticamente. Hay que
  inspeccionar el objeto, conservar una copia recuperable y resolverlo
  manualmente antes de repetir `--dry-run` y `--apply`.
- `retrySafe` limita cada sentencia MySQL a una forma sintácticamente
  idempotente; no promete rollback ni reanudación integral después de que un
  DDL no transaccional haya quedado confirmado. La precondición de una
  migración inicial sí bloqueará restos dentro de su namespace, pero una
  migración posterior sin precondición puede continuar apareciendo como
  pendiente. Tras `migration.postcondition_failed` no se reintenta solo porque
  el plan diga `pending`: se inspeccionan esquema, semillas y datos. Únicamente
  si coinciden exactamente con el contrato y el fallo está en el verificador o
  el runtime se publica su corrección y se repite el lote; en cualquier otro
  estado hace falta restaurar o definir una recuperación explícita.
- Si una versión anterior del verificador falla después de que MySQL/MariaDB
  haya confirmado el DDL de una migración posterior, no se registra esa
  migración a mano ni se fuerza una adopción. Con el verificador corregido, el
  planner admite una reanudación acotada únicamente cuando el superseder
  pendiente es no transaccional y `retrySafe`, su postcondición exacta ya se
  cumple, todos sus contratos supersedidos tienen checksum y scope registrados
  exactos y al menos un verificador antiguo refleja el nuevo superset como
  drift. La entrada sigue siendo `pending`: el `--apply` expresamente
  autorizado reejecuta el SQL idempotente, vuelve a verificar y solo después
  registra la migración. Si una sola prueba no se cumple, el plan permanece
  bloqueado y se restaura el backup o se diseña una recuperación explícita.

### Configuración y readiness de WebAdmin

WebAdmin usa defaults seguros y puede recibir ajustes no secretos desde el
fichero opcional y project-owned `App/config/modules/webadmin.php`. Composer no
crea, fusiona ni sobrescribe ese fichero. El contrato inicial admite solo el
prefijo neutro, el perfil y prefijo de DB y los tiempos/nombre de su sesión:

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

La sesión autenticada dura como máximo 30 días. La actividad válida desliza
el vencimiento por inactividad sin superar ese límite absoluto; escribir en
un formulario sin realizar peticiones al servidor no renueva la sesión.

Credenciales, hosts y correos no se admiten en ese array. `shared` reutiliza
los nombres de entorno legacy; para optar por la conexión dedicada se cambia
solo `connection` a `liquidstack` y se declaran fuera de Git las seis variables
`LIQUIDSTACK_DB_*` descritas antes. Blog debe hacer la misma selección. El
bootstrap explícito usa `LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL` y
`LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL`; sus valores nunca forman parte del
diagnóstico. Tras el bootstrap la base de datos será la fuente de verdad.

Son entradas project-owned que pueden inyectarse transitoriamente desde el
entorno privado del operador. CORE no incorpora direcciones personales en el
paquete, manifests, stubs o documentación y no escribe `.env`. Las dos
direcciones deben ser canónicas y distintas, aunque puedan pertenecer al mismo
operador. No existe una contraseña inicial de entorno: cada identidad la fija
mediante activación.

En una instalación nueva, `liquidstack:webadmin:onboard --yes` es la frontera
operativa posterior a las migraciones. Ejecuta de forma idempotente el
bootstrap y limita la entrega a los dos propietarios protegidos con origen
`bootstrap`; nunca drena invitaciones ordinarias. Solo termina correctamente si
cada propietario está activo o conserva una invitación aceptada por SMTP cuyo
token está entregado, vigente, sin usar y sin revocar. Una entrega válida no se
duplica y una expiración o fallo terminal no se reenvía sin pasar antes por la
recuperación confirmada de invitaciones bootstrap.

El onboarding no es un evento de Composer. `post-install-cmd` y
`post-update-cmd` continúan limitados a sincronizar código, assets, frontend y
guía de agentes: nunca conectan a la DB, ejecutan migraciones, crean cuentas o
contactan SMTP. Esta separación permite usar el mismo contrato en WebAdmin-only
y en Blog, cuya dependencia activa WebAdmin. Las migraciones de Blog asignan
sus capacidades; después se repite el onboarding para verificar las dos
identidades y su acceso sin sustituirlas ni reenviar cuentas ya activas.

El runtime HTTP requiere además `LIQUIDSTACK_WEBADMIN_SECURITY_KEY`, una clave
aleatoria de 32 bytes codificada como 43 caracteres base64url canónicos, y
`zend.exception_ignore_args=On` tanto en CLI como en el SAPI web. PHP debe
soportar además la política productiva fija `argon2id-v1`; no se degrada a
bcrypt según el host. La clave se genera una vez, se guarda fuera del
repositorio y no se rota mediante Composer.

Las contraseñas nuevas exigen ocho caracteres Unicode, minúscula, mayúscula,
número y signo, además de UTF-8 válido y un máximo de 1024 bytes. Esa
validación de creación permanece separada de la verificación de login: las
credenciales existentes se comprueban con entrada UTF-8 no vacía y acotada,
sin reaplicar composición, para que un cambio de política no invalide hashes
Argon2id vigentes.

El informe distingue readiness independientes:

- `runtime_ready` exige selección, configuración, ruta, assets, DB modular,
  esquema aplicado, clave operativa, Argon2id y protección de argumentos en
  trazas;
- `bootstrap_ready` exige la misma base, DB y esquema, además de los dos
  correos iniciales, pero no depende de la clave del runtime HTTP.

El informe presenta además `mail_ready` y `mail_blockers` como eje
independiente. Valida el origen tipado `RAIZ` + `DEV_MODE` y el bloque SMTP
general `MAIL_*` sin mostrar valores; conserva como unidad indivisible el
namespace WebAdmin anterior para instalaciones que aún no lo hayan migrado.
Que el transporte no esté listo bloquea el dispatcher del outbox, pero no
convierte por sí solo en no disponible el login ni el bootstrap que únicamente
encola invitaciones. La recuperación de contraseña usa el mismo contrato SMTP
en una entrega síncrona sin outbox; si el transporte no confirma el mensaje,
la UI informa de un fallo genérico y permite repetir la solicitud.

La readiness de medios es otro eje independiente y no bloquea el runtime base
de WebAdmin. Tras aplicar `0002_webadmin_media_library`, el operador ejecuta
`composer liquidstack:media:init`: su modo normal es una mutación exclusiva de
filesystem, confirmada de forma interactiva o con `--yes` (`--format=json`
también exige `--yes`), que no abre PDO, procesa imágenes ni configura SMTP.
La migración aditiva `0003_webadmin_media_avif_source` habilita únicamente la
entrada nativa AVIF. Mientras esté pendiente, la biblioteca y Blog siguen
operativos y las subidas JPEG, PNG y WebP conservan el contrato anterior.
En desarrollo solo admite el default privado
`storage/liquidstack/webadmin/media` cuando
`DEV_MODE=1` y `RAIZ` es loopback canónica; producción exige
`LIQUIDSTACK_WEBADMIN_MEDIA_STORAGE_ROOT` absoluto, persistente y fuera del
árbol del proyecto/deploy. La raíz queda identificada por
`.liquidstack-webadmin-media`, contiene un `.gitignore` interno y un área de
staging; repetir el comando es idempotente y el modo normal no adopta una raíz
no vacía sin marcador. Symlinks, junctions y destinos peligrosos se rechazan.

La única transición admitida para un storage legacy anterior al marker es
`composer liquidstack:media:init --adopt-existing --backup-confirmed --yes`.
No es el flujo de una instalación nueva: exige WebAdmin y 0002 listos, abre la
DB, adquiere el lock transaccional de cuota y compara de forma bidireccional
todas las variantes registradas con el filesystem. Solo una coincidencia
completa de claves canónicas, bytes, hashes, MIME AVIF, staging vacío y ausencia
de enlaces o entradas extra permite crear scaffold y marker sin reescribir los
medios. Cualquier mismatch falla sin mutar el layout; la bandera de backup
declara una copia recuperable ya comprobada y nunca la crea.

`WebAdminDiagnosticService` recibe un array de entorno ya cargado y la
proyección sin secretos del probe: nunca abre `.env`, conecta por sí mismo ni
escribe. `composer liquidstack:doctor` es la frontera que carga el entorno y
realiza la consulta DB. `composer liquidstack:migrate --plan` llama al mismo
preflight con el probe desactivado, conserva `not_checked` y permanece
completamente offline. `--dry-run` sigue siendo la vista detallada del plan DB.

El dispatcher HTTP usa ese mismo `ProjectEnvironmentLoader` y la misma
precedencia proceso sobre `.env`; no depende de que `variables_order` exponga
las variables de proceso en `$_ENV`. El estado de carga se propaga tipado al
contexto del módulo. Un fichero inválido deja el prefijo reservado en `503`
sin construir PDO, aunque el resto de la web legacy pueda conservar su manejo
de errores propio.

## Enrutado previsto

WebAdmin usará `/admin` como prefijo neutro configurable. Su provider se
resuelve después de cargar `.env`, pero antes de incluir la configuración,
roles, endpoints, sesión o router multidioma legacy. Así `/admin` no hereda
cookies, cabeceras ni efectos laterales de la zona privada anterior.

Antes de reclamar un prefijo, CORE inspecciona estáticamente las claves de ruta
literales de `App/config/routes/get.php` y `post.php`; tokeniza los ficheros sin
incluirlos ni ejecutar código. Una ruta exacta o descendiente se considera
colisión. Una clave calculada, concatenada o añadida mediante un índice hace
que la inspección sea incompleta: se devuelve `route_file.dynamic_key`, no se
registra WebAdmin y la ruta pública conserva prioridad. Si colisiona un prefijo
personalizado, la ruta pública se conserva y
WebAdmin intenta el default seguro; si también colisiona `/admin` o el catálogo
no puede inspeccionarse con seguridad, no registra el prefijo y `doctor`
presenta el bloqueador. `/administrator` no colisiona con `/admin`.

Una configuración inválida o cuyo primer segmento sea un idioma activo nunca
derriba la web pública ni expone el valor rechazado. Si el runtime, la DB o el
esquema no están preparados, el prefijo reservado responde `503` genérico,
`no-store`, `noindex`, sin cuerpo en `HEAD` y sin iniciar `PHPSESSID`. Las rutas
de la zona privada legacy permanecen separadas.

Antes de construir el runtime, la frontera WebAdmin rechaza con `400` las
peticiones malformadas y las que no llegan marcadas como HTTPS por el servidor
local. No confía en cabeceras reenviadas. Un proxy debe trasladar de forma
verificada el esquema y la IP cliente al virtual host; de lo contrario el rate
limit por `REMOTE_ADDR` agruparía a todos sus usuarios. El gate por request
solo valida el registro exacto de migraciones y semillas operativas acotadas;
la auditoría exhaustiva del DDL permanece en `doctor` y
`migrate --dry-run`.

El runtime implementa login, logout, panel mínimo, solicitud genérica de
recuperación, activación y restablecimiento. Separa cookies autenticada,
preautenticada y de acción; la primera navegación con token se vincula y usa
un `303` hacia una URL sin query. La recuperación intenta entregar el correo
de forma síncrona y no lo encola. Las invitaciones se ejecutan fuera de HTTP
mediante un outbox y un comando one-shot con lease, fencing y reintentos
acotados; su scheduler de producción sigue
[pendiente](mejoras-pendientes/webadmin-mail-scheduler-produccion.md). Los
resultados ordinarios de recuperación no revelan el estado de la identidad;
la pantalla distinta solicitada para un fallo SMTP elegible constituye un
compromiso explícito frente a enumeración estricta y nunca incluye detalles
técnicos ni el correo. Los
contratos operativos completos están en
[autenticación](webadmin-authentication.md),
[bootstrap](webadmin-bootstrap.md) y
[correo/outbox](webadmin-mail-outbox.md). La superficie privada de
[gestión de editores](webadmin-editor-management.md) añade listado,
invitación, suspensión/reactivación y capacidades delegables con una segunda
autorización dentro de cada transacción.

Las rutas públicas estáticas del proyecto tienen prioridad sobre los slugs
dinámicos del Blog. El dispatcher público modular solo se consulta después de
que el router legacy haya agotado su ruta exacta. Antes de iniciar la sesión,
un claim estático de prefijo identifica si un `GET`/`HEAD` puede pertenecer a un
módulo; no construye el provider ni su runtime y no abre PDO. Solo en ese
namespace se difiere `session_start()`: una ruta estática ganadora recupera el
bootstrap legacy antes de renderizar, excepto si declara exactamente
`session => false`; una respuesta modular permanece sin `PHPSESSID`, y un miss
abre la sesión antes del 404 existente. Las rutas ajenas, POST y otros métodos
conservan el orden de bootstrap anterior.

El sitemap de Blog es una excepción declarativa exacta y los medios AVIF usan
el prefijo pre-bootstrap fijo `/_liquidstack/blog-media/`. Ambos se resuelven
antes del router multidioma y de la sesión tras comprobar que la ruta o fichero
no pertenece al proyecto. El sitemap se alimenta de la DB de producción;
publicar o retirar un artículo actualiza su respuesta sin modificar el
repositorio ni requerir deploy.

Opcionalmente, Blog puede activar una caché privada last-known-good. Exige el
prefijo Blog completo 0001–0006; la migración 0006 aporta revisión pública
monótona y generación de storage;
`publish`/`unpublish` escriben un fence antes de hacer visible el cambio y el
generador solo promueve snapshots de la revisión bloqueada. La inicialización
es un comando explícito, nunca un efecto de Composer. En producción requiere
storage persistente compartido, locks advisory y promoción atómica confirmados
por el operador. El fallback se limita a conexión DB indisponible y se declara
en cabeceras; cualquier otra deriva falla cerrada. Los contratos completos
están en [Liquid Blog](liquid-blog.md) y en
[la caché LKG del sitemap](blog-sitemap-last-known-good-cache.md).
Las URLs del sitemap y el HTML público comparten el mismo origen tipado,
canonical y conjunto de variantes publicadas. Los alternates `hreflang` y
`x-default` se derivan del agregado multidioma y nunca incluyen borradores;
solo una portada estructurada y publicable puede convertirse en imagen social.

## Estado de implementación

El catálogo, los selectores, el cierre de dependencias, la publicación
selectiva, el provider neutral, el esquema inicial de identidad y capacidades,
el bootstrap explícito, la autenticación/sesión aislada, las acciones de
activación y recuperación, el outbox SMTP, el diagnóstico operativo, el motor
de migraciones, la gestión delegada de editores, la biblioteca de medios y su
inicialización explícita constituyen el corte actual de WebAdmin.

El catálogo Blog 0001 a 0025 está definido sobre esa base: migraciones propias y
cross-scope, capacidades delegables, artículos con variantes localizadas,
categorías post-wide, etiquetas localizadas por variante, documentos
estructurados y revisiones, borrador/publicación,
bloqueo optimista, duplicación completa, papelera recuperable por tombstones,
UI privada, auditoría atómica, consumo de Media, analítica propia opcional,
resolución pública tardía sin sesión legacy, medios por prefijo pre-bootstrap
y sitemap DB-backed pre-bootstrap exacto. Las lecturas editoriales y públicas
excluyen tombstones; no existe purga editorial y una variante eliminada exige
restauración y publicación explícitas.

Las fronteras aditivas `0020`–`0025` separan vocabulario de etiquetas,
asignación live, head y workspace privado localizado, items pendientes y
capacidades WebAdmin. El workspace usa su propio
`tag_workspace_version`: guardar etiquetas no modifica la relación pública y
`Publicar` consume su CAS exacto en la misma transacción que documento, medios,
categorías y cabecera. Una frontera pendiente mantiene operativo el contrato
anterior; una frontera registrada pero incompleta falla cerrada.

La duplicación independiente y el alta de locale no clonan la publicación ni
el historial fuente: una copia estructurada crea su documento actual y revisión
inicial `1`. El duplicado toma categorías del workspace post-wide privado o de
la relación live; también copia las etiquetas efectivas del mismo locale cuando
su frontera está lista. El nuevo locale comparte la asignación de categorías
del agregado y comienza sin etiquetas. Un workspace editorial que no
corresponda a la cabecera pública actual falla
cerrado. `0019_blog_copy_operation_idempotency` persiste una clave ligada al
actor, operación, fuente, locales, lock y payload para que replays idénticos
devuelvan el primer destino y una reutilización incompatible no pueda crear otra
copia.

El editor proyecta el documento sobre un lienzo visual neutral, no sobre un
segundo `main` ni un segundo H1 del documento administrativo. Conceptualmente
presenta un `header` con H1 y medio destacado seguido del `main` público: H2
abre una `section`, H3 un `article` dentro de ella y H4-H6 permanecen en ese
artículo. La jerarquía no admite saltos y las operaciones de orden o borrado
tratan cada encabezado junto a su subárbol semántico.

La identidad localizada también forma parte del agregado. Al crear una
variante solo se ofrecen locales activos todavía no usados y se muestra el path
público configurado; al editar, el locale queda inmutable y la URL procede de
`public_paths` más el slug, nunca de un prefijo convencional inferido. El
inspector del editor puede asignar categorías y etiquetas de ese mismo locale,
además de consultar medios recientes junto a todos los assets ya referenciados
por el documento, de modo que una referencia antigua no desaparece de la UI.

El guardado conserva el POST SSR como fallback y lo mejora con una petición
asíncrona. Solo una redirección canónica al mismo origen, post y locale confirma
el éxito. Conflictos de lock, validación, pérdida de autorización o red dejan
intactos los campos y el documento; mientras existan diferencias frente al
estado inicial, la navegación accidental se advierte. Categorías usan el mismo
principio progresivo y mantienen su formulario nativo como fallback. La edición
de etiquetas conserva un input CSV SSR de 0–30 términos; la mejora crea
pastillas con coma, Intro, pegado o blur y serializa el guardado mediante un
único request en vuelo.
Los cambios que llegan durante esa petición se reencolan, actualizan el CAS y
no se pierden. Consultarlas exige `blog.tags.view`; mutarlas exige conjuntamente
`blog.tags.view` y `blog.tags.edit` tanto en la UI como dentro de la transacción
de `POST /admin/blog/tags/assign`.

El detalle público conserva un renderer standalone compatible, semántico y
seguro, con CSS responsive publicado únicamente al activar Blog. Como punto de
extensión aditivo, el proyecto puede declarar una vista regular bajo
`App/views` y recibir un view model y un contexto de shell tipados, sin PDO,
IDs internos ni secretos. La vista comienza con el único require gestionado
`App/app/_moduleBlogPublicArticle.php` y compone head, navegación, footer, tema,
assets y layout. La seguridad del índice y el artículo se configura de forma
compartida en `App/config/modules/blog-public.php`; el controlador crea el
nonce y aplica CSP y cabeceras defensivas sobre la `Response`. La vista solo
consume ese nonce y no emite cabeceras ni requiere seguridad local. CORE falla
cerrado si la vista emite una salida vacía o lanza una excepción. Omitir la
clave no cambia la salida standalone de consumidores existentes. Las claves
`article-basic-01` y
`article-cover-01` siguen siendo contratos de documento y portada; nuevas
composiciones visuales mediante recursos LiquidStack permanecen aditivas.
El view model conserva `bodyHtml()` como cuerpo histórico completo, incluida la
portada, para no romper shells existentes. Las vistas nuevas deben componer
`headerMediaHtml()` en el `header` y `mainHtml()` dentro del `main`; ambos son
fragmentos saneados y separan el medio destacado del contenido sin duplicarlo.
El hook añade `$articleCategories`, `$articleTags` y
`$articleTaxonomiesHtml`; el shell coloca este último dentro del artículo, antes
de `$articleMain`. El copy integrado usa «Categorías»/«Etiquetas» en español,
«Kategoriak»/«Etiketak» en euskera y «Categories»/«Tags» como fallback; omite
cada grupo vacío y no inventa enlaces a archivos inexistentes.

El bloque YouTube conserva un enlace externo accesible y el asset module-owned
`blog-public.js` lo mejora progresivamente. Solo un clic primario sin
modificadores y con `cookie_social=true` crea un iframe de
`youtube-nocookie.com`; sin JavaScript o consentimiento la navegación externa
permanece intacta, y revocar el permiso desmonta cualquier iframe activo. La
CSP standalone permite exclusivamente el script propio y ese origen de frame;
un shell project-owned recibe el mismo contrato y nonce desde el controlador. La
frontera de sesión pública ya está implementada: el detalle modular no hereda
la sesión legacy y el índice project-owned puede declarar `session => false`
en su ruta estática dentro del prefijo Blog. `BlogPublicFeedFactory` comparte
ya una única conexión y runtime para cards generales, filtros y cards por
categoría; el factory específico de categorías se conserva como adaptador
compatible.

La familia visual Blog usa ownership selectivo. Incluye `artBlogArticle01`,
`moduleBlogArchive01`, `moduleBlogFilters01`, `moduleBlogPagination01`,
`moduleBlogResults01`, `sectionBlogCatalog01`, `sectionBlogGrid01`,
`sectionBlogList01`, `sectionBlogFeatured01`, `sectionBlogRelated01`,
`sectionBlogSlider01` y, desde el corte RESOURCE-001,
`moduleBlogGrid02`, `sectionBlogSlider02` y `sectionBlogStack01`. Grid02 ofrece
composición regular o bento y revelado GSAP incremental; Slider02 encapsula por
instancia Draggable, Inertia, snap, wrap y autoplay configurable con pausa,
fallback scroll-snap, movimiento reducido y cleanup; Stack01 usa ScrollTrigger
solo con 3–8 cards y degrada a lista vertical fuera de sus condiciones de
viewport. Controladores, templates, SCSS y JS se publican únicamente cuando
está activo `liquidstack/blog`; un proyecto core-only o WebAdmin-only no recibe
esos ficheros. Las claves Matrix legacy de los catálogos base se conservan de
forma aditiva durante la transición y no son contenido público de DB.

`sectionBlogCatalog01` posee el H2 y la única sección del catálogo. Los recursos
`moduleBlogGrid02`, Pagination01 y Archive01 tienen siempre raíces neutras;
conservan su lógica sin introducir landmarks y no publican parámetros para
cambiar el tag. El catálogo falla cerrado si un slot intenta introducir otra
`section` o `nav`.

Una vista que solo necesita una colección pública acotada requiere
`App/app/_moduleBlogPublicCollections.php` como único soporte y consume el view
model `ready|empty|unavailable` que entrega `latest($locale, $limit)`. Feeds,
factories, queries y manejo de fallos permanecen fuera de la maquetación.

La búsqueda pública, los filtros de categorías `any|all`, archivo y
relacionados están implementados sobre un mismo `BlogPublicFeed`. El contrato
para recursos usa `BlogPublicResourceQuery`, incluida la allowlist de orden
`newest|oldest|updated`, y `BlogPublicResourceBatch` como proyección inmutable
de `items`, `has_next`, `next_offset` y `next_url`. Las categorías localizadas
de todas las cards se obtienen junto a las etiquetas live en un batch compuesto
y acotado. Las categorías excluyen Dummy y ninguna taxonomía expone IDs. La API
enriquecida añade `tags`, mientras `BlogPublicFeed::cards()` conserva su shape
histórico. La query `q` busca también nombre y slug de etiquetas mediante
`EXISTS`, sin leer workspaces, duplicar cards ni añadir `tag[]`, filtros, rutas,
archivos, URLs canonical o entradas de sitemap. El loader compartido vive en
el namespace module-owned
con origen en
`modules/blog/resources/project/src/js/modules/blog/blogCollectionLoader.js` y
destino `src/js/modules/blog/blogCollectionLoader.js`: mejora el siguiente
enlace SSR con HTML parcial same-origin, deduplica por clave estable, mantiene
estados de carga/error/reintento/fin, aborta generaciones obsoletas y notifica
los nodos anexados a cada recurso. Sin JavaScript sigue operativa la navegación
SSR.

El helper de presentación admite categorías y `media|thumbnail` por card,
valida estrictamente `src`, los candidatos ascendentes de `srcset` y `sizes`, y
aplica el `sizes` propio de cada recurso. El runtime público puede inyectar un
repositorio batch opcional que compone los scopes Blog y WebAdmin con un máximo
de dos `SELECT` constantes por lote. El primero obtiene documentos y referencias
publicables; el segundo consulta variantes solo si la selección anterior produjo
algún medio elegible. La selección respeta portada o primera imagen por
orden del documento `CURRENT` publicado; solo proyecta AVIF válidos, usa como
`src` la mayor variante de hasta 900 px, no entrega IDs ni `sizes` desde backend
y evita N+1. Schema o storage no preparados y datos corruptos fallan cerrados a
la card textual. Los 16 derivados Dummy del showroom tienen fuente gestionada
por Composer en `resources/img/dummy/responsive`, cubren 480, 899/900, 1800 y
2560 px y no sustituyen una proyección real del feed.
RESOURCE-001 se integra en CORE principal dentro de `Unreleased`; la release
versionada permanece condicionada a la matriz de adopción final.

Siguen fuera de este corte el análisis de búsqueda avanzado, la traducción IA, las
plantillas visuales adicionales, los formatos de medios aún no admitidos, la
localización independiente de la interfaz WebAdmin, la edición HTML avanzada
protegida y el editor de páginas. El detalle de estos siguientes cortes está en
[la hoja de ruta de WebAdmin y Liquid Blog](liquid-blog-roadmap.md).
