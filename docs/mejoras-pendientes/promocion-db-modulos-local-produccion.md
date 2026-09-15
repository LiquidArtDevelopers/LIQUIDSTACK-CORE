# Promoción de la DB modular entre local y producción

> Estado (2026-09-11): contrato manual documentado y distribuido. Continúa
> pendiente una herramienta automatizada de exportación/importación; no es un
> requisito para ejecutar el procedimiento manual verificado.

El runbook operativo que llega a todos los consumidores mediante Composer vive
en
[`liquidstack-module-operations/references/production-db-media-promotion.md`](../../.codex/skills/liquidstack-module-operations/references/production-db-media-promotion.md).
Los workflows que deban preservar ese estado se crean o auditan con
[`liquidstack-github-actions`](../../.codex/skills/liquidstack-github-actions/SKILL.md).

## Situación actual

El consumidor de referencia es el laboratorio completo de WebAdmin y Liquid
Blog. Durante esta fase su DB modular se ejecuta en MySQL/MariaDB local mediante
XAMPP. La DB de
producción todavía no forma parte del flujo y no debe reutilizar las
credenciales locales.

Otros proyectos podrán seguir uno de estos modelos:

- desarrollar contra una DB local de XAMPP y preparar después una DB vacía de
  producción;
- trabajar desde desarrollo contra una DB remota de producción ya existente,
  únicamente cuando el proyecto y la operación lo justifiquen;
- trasladar a producción contenido que sí se haya creado previamente en local.

CORE debe soportar los tres casos sin que cambiar de host, puerto, nombre de
DB o credenciales obligue a modificar PHP, rutas, controladores o migraciones.

## Invariantes

- El código mantiene `database.connection => liquidstack` en WebAdmin y Blog.
  El entorno concreto se selecciona exclusivamente con `LIQUIDSTACK_DB_*`.
- Cada entorno utiliza su propia DB, usuario y contraseña con privilegios
  mínimos. Una contraseña local nunca se reutiliza en producción.
- `.env`, secretos, backups y datos son project-owned y no viajan mediante
  Composer, Git ni el sincronizador de CORE.
- WebAdmin y Blog apuntan siempre a la misma conexión física dentro de un
  entorno.
- `composer update` nunca crea esquemas, mueve datos ni cambia el destino de la
  conexión.
- `doctor`, `migrate --plan` y `migrate --dry-run` preceden a cualquier
  escritura. `--apply` continúa requiriendo autorización explícita.
- Un cambio de variables no se interpreta como una migración de datos. CORE no
  adopta tablas encontradas ni mezcla registros de dos entornos.
- El sitemap dinámico, el origen público, SMTP y cualquier almacenamiento de
  medios se configuran para el mismo entorno que la DB antes de publicar.
- Un `.gitignore` no constituye una política de persistencia. El storage
  productivo vive fuera del árbol de proyecto/releases y ninguna fase de
  Actions, sync, extracción, cleanup o rollback de código puede alcanzarlo.

## Dos promociones distintas

### Producción nueva y vacía

1. Crear fuera de Composer una DB vacía y un usuario exclusivo.
2. Guardar las credenciales de producción en el gestor de secretos o `.env`
   del servidor, nunca copiando el fichero local completo.
3. Verificar host, versión MySQL/MariaDB, charset, permisos y conexión con
   `doctor`.
4. Ejecutar `migrate --plan` y `migrate --dry-run`.
5. Obtener backup o snapshot recuperable de la DB vacía y autorización.
6. Aplicar las migraciones y completar
   `composer liquidstack:webadmin:onboard --yes`; un bootstrap que solo encola
   no prepara el acceso inicial.
7. Validar `/admin`, Blog, sitemap, correo y rutas públicas antes de abrir el
   servicio.

En este modo los usuarios y artículos de prueba locales no se copian.
Las dos identidades protegidas se suministran desde el entorno project-owned o
gestor de secretos del destino y su onboarding debe cumplir el
[runbook canónico](../webadmin-bootstrap.md). Composer no copia esos valores ni
ejecuta el envío durante install/update.

### Producción con datos procedentes de local u otro servidor

1. Congelar escrituras y registrar versiones de CORE, esquema y motor en
   origen y destino.
2. Crear backups verificables de ambos lados y ensayar la restauración.
3. Comparar el catálogo y el registro `ls_module_migrations`; no importar solo
   las tablas de negocio dejando fuera dicho registro.
4. Trasladar de forma conjunta los namespaces WebAdmin y Blog, preservando
   claves foráneas, charset, timestamps UTC y orden transaccional posible.
5. Inventariar tamaños y SHA-256 y trasladar la raíz Media completa —marker,
   dotfiles, cuarentena y manifiestos incluidos— con staging vacío. El destino
   final debe ser absoluto, privado, persistente y externo al deploy.
6. Cambiar los secretos del entorno sin modificar el código.
7. Ejecutar `doctor` y `migrate --dry-run` sobre destino antes de permitir
   nuevas escrituras.
8. Hacer smoke tests, comparar recuentos DB↔filesystem e inventarios, ejecutar
   un deploy de ensayo que no cambie Media y conservar un rollback conjunto.

Este flujo no debe incluirse en el deploy ordinario. Cualquier automatización
futura será una operación separada, protegida y autorizada, con inventario,
comprobaciones y recuperación probados.

## Impacto en GitHub Actions

- El build puede generar y desplegar código, `vendor` resuelto y bundles Vite;
  no empaqueta `.env`, dumps, backups, DB ni Media.
- `LIQUIDSTACK_WEBADMIN_MEDIA_STORAGE_ROOT` apunta directamente al directorio
  físico persistente fuera del deploy. No se resuelve con `App/media`, un
  symlink dentro de una release o una mera exclusión de Git.
- Los despliegues in-place y cualquier `rsync --delete` deben probarse con
  dry-run y un target de código acotado. `--delete-excluded` es incompatible
  con una exclusión usada como protección.
- Migraciones, onboarding, inicialización y la primera copia DB + Media no se
  ejecutan automáticamente con cada push.
- Tras la copia inicial, producción pasa a ser la fuente de verdad. Nunca se
  vuelve a sincronizar la biblioteca local sobre ella.

## Trabajo de CORE pendiente

- Diseñar un identificador explícito de entorno (`local`, `staging` o
  `production`) que no dependa del nombre de la DB ni revele credenciales.
- Hacer que `doctor` muestre ese identificador y una huella no sensible del
  destino para detectar conexiones accidentales.
- Añadir un gate reforzado para operaciones mutables en producción, separado
  de la confirmación ordinaria de `--apply`.
- Definir el contrato TLS con CA y verificación del servidor para DB remotas no
  confiables.
- Crear una prueba E2E con dos DB aisladas que simule local → producción, tanto
  con destino vacío como con traslado de contenido, y convertir el runbook
  manual en tooling solo cuando pueda conservar sus mismas garantías.
- Definir exportación/importación versionada de DB y medios sin incluir
  secretos, fixtures o usuarios de laboratorio por accidente.
- Rotar las credenciales usadas durante el desarrollo del consumidor de
  referencia antes de
  habilitar producción; la credencial local actual no debe reutilizarse.

## Criterio de salida

El cambio local → producción se considerará preparado cuando pueda realizarse
modificando únicamente secretos del entorno, el sistema detecte con claridad
el destino, el dry-run sea reproducible, exista rollback ensayado y ninguna
acción de Composer pueda escribir o copiar datos implícitamente.
