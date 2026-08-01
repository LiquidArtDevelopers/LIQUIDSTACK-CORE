# Promoción de la DB modular entre local y producción

> Estado: pendiente de cerrar antes de instalar WebAdmin o Blog en una DB de
> producción con datos reales.

## Situación actual

AIWA es el laboratorio completo de WebAdmin y Liquid Blog. Durante esta fase
su DB modular se ejecuta en MySQL/MariaDB local mediante XAMPP. La DB de
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

## Dos promociones distintas

### Producción nueva y vacía

1. Crear fuera de Composer una DB vacía y un usuario exclusivo.
2. Guardar las credenciales de producción en el gestor de secretos o `.env`
   del servidor, nunca copiando el fichero local completo.
3. Verificar host, versión MySQL/MariaDB, charset, permisos y conexión con
   `doctor`.
4. Ejecutar `migrate --plan` y `migrate --dry-run`.
5. Obtener backup o snapshot recuperable de la DB vacía y autorización.
6. Aplicar las migraciones, ejecutar el bootstrap y volver a pasar `doctor`.
7. Validar `/admin`, Blog, sitemap, correo y rutas públicas antes de abrir el
   servicio.

En este modo los usuarios y artículos de prueba locales no se copian.

### Producción con datos procedentes de local u otro servidor

1. Congelar escrituras y registrar versiones de CORE, esquema y motor en
   origen y destino.
2. Crear backups verificables de ambos lados y ensayar la restauración.
3. Comparar el catálogo y el registro `ls_module_migrations`; no importar solo
   las tablas de negocio dejando fuera dicho registro.
4. Trasladar de forma conjunta los namespaces WebAdmin y Blog, preservando
   claves foráneas, charset, timestamps UTC y orden transaccional posible.
5. Tratar medios y ficheros fuera de la DB como una migración coordinada y
   verificable.
6. Cambiar los secretos del entorno sin modificar el código.
7. Ejecutar `doctor` y `migrate --dry-run` sobre destino antes de permitir
   nuevas escrituras.
8. Hacer smoke tests, comparar recuentos y conservar un plan de rollback.

Este flujo no debe automatizarse hasta disponer de una herramienta específica
con inventario, comprobaciones y recuperación probados.

## Trabajo de CORE pendiente

- Diseñar un identificador explícito de entorno (`local`, `staging` o
  `production`) que no dependa del nombre de la DB ni revele credenciales.
- Hacer que `doctor` muestre ese identificador y una huella no sensible del
  destino para detectar conexiones accidentales.
- Añadir un gate reforzado para operaciones mutables en producción, separado
  de la confirmación ordinaria de `--apply`.
- Definir el contrato TLS con CA y verificación del servidor para DB remotas no
  confiables.
- Crear un runbook y una prueba E2E con dos DB aisladas que simule local →
  producción, tanto con destino vacío como con traslado de contenido.
- Definir exportación/importación versionada de DB y medios sin incluir
  secretos, fixtures o usuarios de laboratorio por accidente.
- Rotar las credenciales usadas durante el desarrollo de AIWA antes de
  habilitar producción; la credencial local actual no debe reutilizarse.

## Criterio de salida

El cambio local → producción se considerará preparado cuando pueda realizarse
modificando únicamente secretos del entorno, el sistema detecte con claridad
el destino, el dry-run sea reproducible, exista rollback ensayado y ninguna
acción de Composer pueda escribir o copiar datos implícitamente.
