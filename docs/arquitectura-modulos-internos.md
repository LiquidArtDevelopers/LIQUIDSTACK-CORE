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

## Sincronización y propiedad de datos

El PHP de los módulos permanece en CORE, bajo `vendor`, y no se duplica en el
proyecto. Solo los ficheros declarados expresamente en `project_files` pueden
publicarse. Sus destinos quedan limitados al namespace propio del módulo en
`public/assets/modules`, `src/js/modules` o `src/scss/modules`; un manifiesto
no puede apuntar a rutas, configuración, datos ni contenido del cliente. Esa
publicación reutiliza el sincronizador seguro de CORE:

- instala ficheros ausentes;
- actualiza únicamente versiones reconocidas como gestionadas;
- conserva personalizaciones locales;
- no usa mirrors destructivos;
- no ejecuta migraciones durante `composer install` o `composer update`.

Cada entrada publicada forma un grupo de actualización independiente por
defecto. Un manifiesto puede dar el mismo `group` a varias entradas que deban
actualizarse atómicamente, sin congelar por ello todos los assets del módulo.

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

### Diagnóstico y plan de migraciones

El plugin registra comandos únicamente en los proyectos consumidores donde
Composer lo ha cargado:

```bash
composer liquidstack:doctor
composer liquidstack:doctor --format=json
composer liquidstack:migrate --plan
composer liquidstack:migrate --dry-run
composer liquidstack:migrate --apply
composer liquidstack:webadmin:bootstrap
composer liquidstack:webadmin:mail:dispatch
```

`doctor` valida el catálogo, el cierre de dependencias, los providers activos,
los ficheros base de configuración y los requisitos de WebAdmin. Si WebAdmin
declara migraciones, abre la conexión compartida y ejecuta un probe
estrictamente de solo lectura: valida el contrato PDO, compara el registro de
migraciones con el catálogo y verifica las postcondiciones del esquema. La
salida estructurada contiene nombres de variables y códigos estables, nunca
credenciales, correos, claves, DSN, SQL o mensajes internos del driver.

El comando exige exactamente uno de estos modos:

- `--plan` es `catalog-only`: valida y enumera metadatos (`module`, `id`,
  descripción, checksum y carácter destructivo), marca la DB como
  `not_evaluated` y no abre conexión.
- `--dry-run` carga el entorno y la configuración de scopes, conecta a la DB
  compartida y compara el catálogo con `ls_module_migrations` en solo lectura.
- `--apply` parte de ese preview, enlaza su hash como `expectedPlanHash` y el
  runner vuelve a calcular el plan antes y después de adquirir el lock. Es la
  única vía que puede crear el registro o ejecutar SQL.

`--apply` exige `--yes` o confirmación interactiva. En formato JSON exige
siempre `--yes`. Las migraciones destructivas requieren simultáneamente
`--allow-destructive` y `--backup-confirmed`; `--lock-timeout` admite entre 0
y 300 segundos. El runtime utiliza la conexión compartida del stack, el scope
configurable de WebAdmin y `ls_blog_` para Blog, pero nunca imprime secretos,
sentencias SQL ni mensajes internos de PDO.

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
  DDL no transaccional haya quedado confirmado. Precisamente por eso cualquier
  resto dentro del namespace vuelve a bloquear hasta su recuperación manual.

### Configuración y readiness de WebAdmin

WebAdmin usa defaults seguros y puede recibir ajustes no secretos desde el
fichero opcional y project-owned `App/config/modules/webadmin.php`. Composer no
crea, fusiona ni sobrescribe ese fichero. El contrato inicial admite solo el
prefijo neutro, el prefijo de tablas y los tiempos/nombre de su sesión:

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

Credenciales, hosts y correos no se admiten en ese array. La conexión
`shared` reutiliza los nombres de entorno
`BBDD_SERVER`, `BBDD_USER`, `BBDD_PASS` y `BBDD_NAME`. El bootstrap explícito
usa `LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL` y
`LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL`; sus valores nunca forman parte del
diagnóstico. Tras el bootstrap la base de datos será la fuente de verdad.

El runtime HTTP requiere además `LIQUIDSTACK_WEBADMIN_SECURITY_KEY`, una clave
aleatoria de 32 bytes codificada como 43 caracteres base64url canónicos, y
`zend.exception_ignore_args=On` tanto en CLI como en el SAPI web. PHP debe
soportar además la política productiva fija `argon2id-v1`; no se degrada a
bcrypt según el host. La clave se genera una vez, se guarda fuera del
repositorio y no se rota mediante Composer.

El informe distingue readiness independientes:

- `runtime_ready` exige selección, configuración, ruta, assets, DB compartida,
  esquema aplicado, clave operativa, Argon2id y protección de argumentos en
  trazas;
- `bootstrap_ready` exige la misma base, DB y esquema, además de los dos
  correos iniciales, pero no depende de la clave del runtime HTTP.

El informe presenta además `mail_ready` y `mail_blockers` como eje
independiente. Valida el origen público HTTPS y las ocho variables SMTP sin
mostrar valores. Que el transporte no esté listo bloquea el dispatcher del
outbox, pero no convierte por sí solo en no disponible el login ni el
bootstrap que únicamente encola invitaciones.

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

El runtime implementa login, logout, panel mínimo, solicitud no enumerable de
recuperación, activación y restablecimiento. Separa cookies autenticada,
preautenticada y de acción; la primera navegación con token se vincula y usa
un `303` hacia una URL sin query. La entrega se ejecuta fuera de HTTP mediante
un outbox y un comando one-shot con lease, fencing y reintentos acotados. Los
contratos operativos completos están en
[autenticación](webadmin-authentication.md),
[bootstrap](webadmin-bootstrap.md) y
[correo/outbox](webadmin-mail-outbox.md). La superficie privada de
[gestión de editores](webadmin-editor-management.md) añade listado,
invitación, suspensión/reactivación y capacidades delegables con una segunda
autorización dentro de cada transacción.

Las rutas públicas estáticas del proyecto tienen prioridad sobre los slugs
dinámicos del Blog. El dispatcher público modular solo se consulta después de
que el router legacy haya agotado su ruta exacta. El sitemap de Blog es un
endpoint PHP alimentado por la DB de producción; publicar o retirar un artículo
actualiza su respuesta sin modificar el repositorio ni requerir deploy. El
contrato completo del primer corte está en [Liquid Blog](liquid-blog.md).

## Estado de implementación

El catálogo, los selectores, el cierre de dependencias, la publicación
selectiva, el provider neutral, el esquema inicial de identidad y capacidades,
el bootstrap explícito, la autenticación/sesión aislada, las acciones de
activación y recuperación, el outbox SMTP, el diagnóstico operativo, el motor
de migraciones y la gestión delegada de editores constituyen el corte actual de
WebAdmin.

Blog 0001 está implementado sobre esa base: migraciones propias y cross-scope,
capacidades delegables, artículos con variantes localizadas independientes,
borrador/publicación, bloqueo optimista, UI privada, auditoría atómica,
resolución pública tardía y sitemap DB-backed. Siguen fuera de este corte la
biblioteca de medios, el editor enriquecido o por bloques, categorías,
búsqueda, traducción IA, plantillas múltiples y el editor de páginas.
