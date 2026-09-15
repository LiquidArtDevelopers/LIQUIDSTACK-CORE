# Promover DB y Media a producción

Leer esta referencia cuando un consumidor vaya a crear su DB productiva,
trasladar contenido creado en local o cambiar de servidor. Es un procedimiento
manual y project-owned: CORE no dispone todavía de un comando universal de
exportación/importación.

## Elegir el caso correcto

### Producción vacía

Crear una DB vacía y un usuario de privilegios mínimos, configurar secretos del
destino y seguir el flujo normal de `doctor`, plan, dry-run, backup,
migraciones autorizadas, inicialización de Media y onboarding. No copiar
usuarios, posts o medios locales si no deben publicarse.

### Producción con contenido procedente de local

Tratar DB y Media como una sola promoción. Es el caso de un proyecto que ya ha
creado posts e imágenes iniciales en desarrollo. Cambiar `LIQUIDSTACK_DB_*` o
`LIQUIDSTACK_WEBADMIN_MEDIA_STORAGE_ROOT` solo selecciona otro destino; no
transfiere datos.

Si producción ya contiene contenido o ha recibido escrituras, no sobrescribirla
con la copia local. Diseñar una reconciliación específica o detener el corte.

### Recolocación de Media conservando la misma DB

Si el entorno ya usa la DB correcta pero su Media está dentro del árbol que el
deploy reemplaza, no exportar ni reimportar la DB por rutina. Congelar escrituras, crear
un backup conjunto, inventariar la raíz, copiarla completa a un directorio
externo vacío, verificarla y cambiar únicamente
`LIQUIDSTACK_WEBADMIN_MEDIA_STORAGE_ROOT` durante una ventana cerrada. Repetir
`doctor` y el QA antes de retirar la raíz anterior. Esta recolocación sigue
requiriendo rollback coordinado porque la DB referencia esos ficheros.

## Invariantes

- Congelar escrituras durante el inventario y el corte.
- Respaldar y restaurar DB y Media del mismo punto lógico. Restaurar solo una
  parte puede dejar referencias rotas o publicar bytes incorrectos.
- Incluir el registro `ls_module_migrations` junto con todas las tablas
  WebAdmin/Blog y preservar claves, charset y timestamps UTC.
- Copiar la raíz Media completa, incluidos marker, dotfiles, cuarentena y
  manifiestos. `.staging` debe estar vacío antes de capturarla.
- Configurar producción con `LIQUIDSTACK_WEBADMIN_MEDIA_STORAGE_ROOT` apuntando
  directamente a una ruta absoluta, privada y persistente fuera del document
  root, releases y destino reemplazado por el deploy. La ruta canónica bajo
  `project/storage/liquidstack/...` es válida si es hermana de un document root
  separado como `project/www` y el workflow preserva `storage`. No usar un
  symlink o junction.
- No interpretar el `.gitignore` interno como persistencia: GitHub Actions,
  `rsync --delete`, una extracción o una limpieza pueden borrar ficheros
  ignorados.
- No incluir dumps, paquetes de transferencia, inventarios ni secretos en Git,
  Composer, artefactos o logs públicos.
- No ejecutar migraciones, inicializadores, imports o copias sin autorización
  expresa para ese entorno.

## Preparar el corte

1. Registrar commit/tag de la aplicación, versión de CORE, versión del motor,
   origen/destino no sensibles y catálogo de migraciones.
2. Ejecutar en origen y destino, cuando sean accesibles, `doctor`,
   `migrate --plan` y `migrate --dry-run`. Estos comandos diagnostican; no
   sustituyen el backup ni autorizan escrituras.
3. Verificar que el destino productivo de Media está fuera del document root y
   del árbol reemplazado por el deploy, pertenece al usuario PHP correcto y no
   contiene datos inesperados.
4. Auditar el workflow con `liquidstack-github-actions`: ninguna fase de sync,
   extracción, cleanup o rollback debe poder alcanzar ese destino.
5. Crear backups recuperables de origen y destino y ensayar su restauración en
   un entorno aislado.
6. Congelar escrituras en WebAdmin y conservar la web nueva cerrada hasta
   terminar DB y Media.

## Inventariar sin modificar

Usar rutas explícitas y guardar los inventarios en una ubicación privada fuera
del repositorio. En PowerShell, una comprobación segura de la raíz local puede
partir de:

```powershell
$LsMediaRoot = (Resolve-Path -LiteralPath '<media-local>').Path
$LsMediaFiles = Get-ChildItem -LiteralPath $LsMediaRoot -Recurse -Force -File
$LsMediaFiles.Count
$LsMediaFiles | Sort-Object FullName | ForEach-Object {
    [PSCustomObject]@{
        Path = $_.FullName.Substring($LsMediaRoot.Length).TrimStart('\', '/')
        Bytes = $_.Length
        Sha256 = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash
    }
}
Get-ChildItem -LiteralPath (Join-Path $LsMediaRoot '.staging') -Force
```

Además, registrar recuentos de assets, variantes, cuarentenas, posts,
localizaciones y migraciones. No mostrar DSN, correos protegidos, tokens o
credenciales en la salida compartida.

## Transferir una sola vez

1. Exportar la DB con la herramienta nativa del motor y credenciales obtenidas
   desde un fichero privado o secret manager; no poner la contraseña en la
   línea de comandos. Incluir datos, constraints y `ls_module_migrations`.
2. Empaquetar o sincronizar la raíz Media completa con una herramienta que
   preserve dotfiles y estructura. No usar un patrón que omita el marker o
   `.quarantine` y no usar borrado espejo contra una raíz productiva viva.
3. Transferir primero a staging privado del destino, comprobar bytes y SHA-256
   y promoverla a un directorio final normal mediante una operación acotada.
   La aplicación debe permanecer cerrada; no activar el destino mediante un
   symlink de storage.
4. Importar la DB y dejar DB y Media disponibles antes de abrir tráfico o
   escrituras. El orden técnico puede adaptarse al proveedor, pero el corte no
   se considera completo mientras falte una de las dos partes.
5. Corregir ownership y permisos mínimos sin hacer los ficheros públicos.
6. Configurar los secretos productivos en el servidor o GitHub Environment,
   nunca copiando el `.env` local completo.

La copia de una raíz ya inicializada conserva su marker. No ejecutar
`media:init --adopt-existing`: esa opción existe solo para layouts legacy que
nunca tuvieron marker. `media:init` normal corresponde a una instalación nueva
con raíz vacía, no a la importación de una biblioteca inicializada.

Cuando solo se recoloca Media dentro del mismo entorno, omitir los pasos de
exportación/importación de DB, no sus backups, freeze, inventario ni QA.

## Validar antes de abrir

1. Repetir `doctor`, `migrate --plan` y `migrate --dry-run` contra producción.
   Si aparecen migraciones pendientes, aplicar su propio flujo de backup y
   autorización; no resolverlo reimportando a ciegas.
2. Comparar recuentos DB, inventario de ficheros, tamaños y SHA-256. Verificar
   marker válido, staging vacío, ausencia de symlinks/junctions y permisos del
   usuario PHP.
3. Probar login, `/admin/media`, catálogo, imágenes de posts publicadas,
   variantes responsive, Blog y sitemap dinámico.
4. Ejecutar un despliegue de ensayo y demostrar que el inventario Media no
   cambia. Verificar también que el artefacto no contiene la DB ni el storage.
5. Registrar evidencia del corte y conservar temporalmente los backups.

## Rollback y operación posterior

- Definir antes del corte cómo restaurar juntos DB y Media al mismo snapshot.
- Si falla la aplicación pero los datos no cambiaron, revertir solo el código
  puede ser válido; si hubo escrituras o cambios de esquema, decidir el rollback
  como una operación de datos coordinada.
- Tras abrir producción, considerarla fuente de verdad. No volver a sincronizar
  la biblioteca local sobre ella.
- Respaldar DB y Media juntos y mantener el workflow de código sin permisos
  para limpiar, reemplazar o publicar la raíz privada.
- Registrar como mínimo fecha, versión, inventarios, backups verificados,
  operador, resultado de QA y commit del workflow, sin secretos.
