---
name: liquidstack-github-actions
description: Crear, revisar y endurecer workflows de GitHub Actions para construir y desplegar proyectos consumidores LiquidStack sin mezclar código con secretos, DB ni storage runtime. Usar al diseñar o auditar CI/CD, despliegues in-place o por releases y la persistencia de WebAdmin/Blog; no usar para ejecutar migraciones o administrar contenido.
---

# Preparar GitHub Actions para LiquidStack

## Mantener separadas las responsabilidades

- Tratar el repositorio, `vendor` resuelto y los bundles Vite como código o
  artefactos reemplazables.
- Tratar `.env`, secretos, DB, backups, logs y cualquier fichero generado en
  runtime como estado project-owned. No incluirlos en el artefacto ni
  reconstruirlos desde Git.
- Tratar la biblioteca Media de WebAdmin y, cuando esté activa, la caché LKG
  del sitemap Blog como storage privado persistente. No son assets del build.
- Recordar que `.gitignore` solo evita el tracking de Git. No protege frente a
  `rsync --delete`, `--delete-excluded`, una extracción que sobrescriba el
  destino, `git clean`, la limpieza de releases o un panel que vacíe la raíz.
- Mantener la creación del workflow separada de su activación. Editar YAML no
  autoriza commit, push, alta de secretos, cambios en GitHub, acceso al hosting
  ni ejecución de un despliegue.

## Enrutar el trabajo

- Para crear o auditar un workflow real, leer
  [topologías de despliegue](references/deployment-topologies.md) después de
  identificar la topología concreta.
- Si la tarea incluye crear la DB de producción, trasladar contenido local o
  mover Media, usar también `liquidstack-module-operations` y leer
  `../liquidstack-module-operations/references/production-db-media-promotion.md`.
  Esta skill protege esos datos durante los despliegues posteriores; no los
  migra.
- Un despliegue solo de código no debe convertirse implícitamente en un
  onboarding, una migración o una promoción de datos.

## Preflight de solo lectura

Antes de escribir el workflow:

1. Resolver la raíz Git y leer las instrucciones del repositorio. Revisar el
   estado, la rama y los cambios concurrentes sin limpiar ni restaurar nada.
2. Inspeccionar los workflows existentes, `composer.json`, lockfiles, scripts
   de `package.json`, configuración Vite y directorio público real.
3. Identificar módulos activos y todas las raíces runtime. Para Blog/WebAdmin,
   resolver de forma privada los valores efectivos de
   `LIQUIDSTACK_WEBADMIN_MEDIA_STORAGE_ROOT` y, si procede,
   `LIQUIDSTACK_BLOG_SITEMAP_CACHE_ROOT` para compararlos con el target. Las
   rutas no son credenciales, pero no deben volcarse en logs compartidos; el
   informe puede limitarse a los nombres y al resultado dentro/fuera.
4. Confirmar proveedor, transporte, sistema operativo remoto, usuario de
   ejecución, destino, rama o tag publicable y topología: in-place, releases,
   artefacto, panel o equivalente. No inventar rutas, host ni capacidades.
5. Saber dónde se ejecutan Composer, tests y build, y si producción recibe un
   artefacto ya resuelto o instala dependencias por sí misma.
6. Localizar las operaciones que pueden borrar o reemplazar ficheros y resolver
   sus destinos absolutos. Si un dato ausente cambia la frontera destructiva,
   detener el diseño ejecutable y pedirlo.

## Diseñar el pipeline

### Seguridad y concurrencia

- Conceder los permisos mínimos de GitHub y fijar las actions de terceros a un
  commit SHA revisado cuando el proyecto no tenga otra política explícita.
- Usar GitHub Environments o el gestor de secretos acordado para credenciales.
  No guardar secretos en `vars`, YAML, artefactos, cachés, logs ni `.env`
  versionado.
- Serializar los despliegues del mismo entorno con `concurrency`. En producción
  usar `cancel-in-progress: false` para no interrumpir una promoción remota a
  mitad de camino.
- Proteger producción con las aprobaciones disponibles. No cambiar reglas,
  environments o secrets sin autorización expresa.
- Evitar interpolar inputs, nombres de rama o datos no confiables dentro de
  comandos shell. Verificar host keys y usar credenciales de alcance mínimo
  según el transporte elegido.

### Build reproducible

- Fijar versiones compatibles de PHP y Node, extensiones PHP necesarias y el
  package manager que ya usa el proyecto.
- Ejecutar las validaciones reales del repositorio. Para producción, resolver
  Composer sin dependencias de desarrollo y con autoloader optimizado; no usar
  `composer update` en el servidor.
- Preferir lockfiles versionados. Si el proyecto decide no tener alguno,
  construir una sola vez en CI, conservar el inventario de versiones resueltas
  y desplegar ese mismo artefacto; no resolver dependencias de nuevo en cada
  nodo.
- Ejecutar el build Vite desde una copia limpia y comprobar su manifest y sus
  entries, no solo el código de salida del comando.
- Inventariar el artefacto antes de publicarlo. Debe excluir `.env`, credenciales,
  dumps, backups, storage runtime, contenido Media y temporales.

### Despliegue y rollback

- Preferir una release nueva o un staging remoto verificado y una activación
  atómica cuando el hosting lo permita. Acotar la limpieza exclusivamente a
  releases conocidas.
- En in-place, sincronizar una lista de código explícita. No apuntar una
  operación con borrado a la raíz del hosting, al home del usuario ni a un
  destino vacío, relativo o calculado que no se haya validado.
- Mantener cada raíz runtime fuera del árbol reemplazable. En producción,
  `LIQUIDSTACK_WEBADMIN_MEDIA_STORAGE_ROOT` debe apuntar directamente a una
  ruta absoluta, privada y persistente fuera del document root y del target que
  el deploy reemplaza; no a un symlink o junction dentro de una release. La
  ruta canónica `project/storage/liquidstack/...` solo es válida si queda como
  hermana de un document root separado —por ejemplo `project/www`— y todas las
  fases de deploy y rollback preservan `storage`.
- Aplicar el mismo principio a la caché LKG del sitemap cuando esté activa y a
  cualquier otro estado mutable declarado por el proyecto.
- Hacer health checks HTTP y funcionales antes de retirar la release anterior.
  Un rollback de código no revierte DB ni Media.

## Operaciones que no pertenecen al deploy ordinario

No añadir automáticamente al workflow de código:

- `composer update`;
- `liquidstack:migrate --apply`;
- `liquidstack:media:init` o `--adopt-existing`;
- `liquidstack:webadmin:onboard`;
- exportación, importación o copia de DB y Media;
- limpieza, compactación o borrado de storage runtime.

Si el usuario solicita automatizar alguna de ellas, diseñarla como una operación
separada, protegida y auditable. Mantener los gates de backup y autorización de
`liquidstack-module-operations`; que GitHub pueda ejecutar un comando no
constituye autorización para hacerlo.

## Validar antes de activar

- Validar sintaxis YAML y, si está disponible, ejecutar `actionlint`.
- Reproducir tests y build desde un checkout limpio o fixture aislado.
- Inspeccionar el artefacto y demostrar la ausencia de secretos, dumps y
  storage.
- Ejecutar dry-run del transporte cuando exista y revisar cada alta, cambio y
  borrado previsto.
- Verificar que el destino remoto resuelto es el esperado y que ninguna
  operación puede alcanzar las raíces persistentes.
- Para un consumidor con Media, comparar marker, inventario, tamaños y hashes
  antes y después de un despliegue de ensayo. Crear un sentinel o realizar una
  subida controlada son mutaciones: hacerlas solo en el entorno autorizado y
  con una limpieza acordada. Las comprobaciones HTTP de lectura pueden cubrir
  `/admin/media` y una imagen pública referenciada.
- Simular fallo de build, fallo de health check y rollback sin tocar DB o Media.

No desbloquear un deploy con borrado hasta demostrar conjuntamente que la raíz
Media externa está completa, el marker es válido, staging está vacío, hashes y
recuentos coinciden, el usuario PHP puede operarla, la configuración efectiva
apunta directamente a ella, el usuario de deploy no puede borrarla, el dry-run
solo alcanza código y el rollback ha sido ensayado.

## Entrega

Resumir la topología elegida, el contenido del artefacto, las raíces
persistentes protegidas, los nombres de secretos requeridos, los pasos externos
pendientes, la estrategia de rollback y las verificaciones ejecutadas. No
presentar el despliegue como operativo hasta probarlo en el entorno correcto.
