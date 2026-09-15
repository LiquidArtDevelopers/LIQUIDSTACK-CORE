# Topologías de despliegue LiquidStack

Leer esta referencia al crear o auditar un workflow real. Elegir una topología
según el hosting observado; no convertir estos patrones en rutas universales.

## Frontera común

Todo modelo debe separar:

- código y bundles reemplazables;
- configuración y secretos del entorno;
- DB;
- storage runtime privado.

El workflow ordinario es dueño únicamente del primer grupo. Un path ignorado
por Git puede seguir siendo destruido por la herramienta de despliegue.

## Releases versionadas

- Construir o subir una release nueva sin escribir sobre la activa.
- Apuntar las variables de storage directamente a directorios persistentes
  externos a todas las releases.
- Bloquear la activación si el valor efectivo de una raíz runtime resuelve
  dentro de la release nueva, de la activa o de un padre sujeto a cleanup.
- Activar la release solo después del health check. El mecanismo `current` del
  proyecto puede ser un enlace, pero la raíz Media configurada no puede serlo.
- Limpiar únicamente directorios de release identificados y acotados. Nunca
  recorrer el padre compartido ni incluir storage, backups o logs.
- Conservar la release anterior hasta validar la nueva y usarla para rollback
  de código. No presentar ese rollback como reversión de datos.

## Despliegue in-place

- Resolver y verificar el destino absoluto antes de sincronizar.
- Limitar la transferencia a rutas de código conocidas. Preferir que todo
  estado mutable viva fuera del destino.
- Si se descubre storage legacy dentro del proyecto, no confiar en su
  `.gitignore` ni normalizar esa disposición: bloquear el corte productivo y
  preparar su traslado a una raíz externa compatible con CORE.
- No usar borrado espejo hasta que el proyecto declare un inventario o
  artefacto de código contractual. Si no existe, desplegar una release nueva o
  definirlo y revisarlo antes; no improvisar inclusiones/exclusiones en YAML.
- Probar una copia del proceso con un sentinel previamente acordado y un
  inventario runtime; ambos deben permanecer idénticos después del despliegue.
  Crear el sentinel requiere autorización en ese entorno.

## `rsync` y borrado espejo

- Usar `--delete` solo sobre un destino de código explícito, no vacío y ya
  validado. Revisar antes `--dry-run --itemize-changes`.
- Resolver el destino en el host remoto, rechazar componentes redirigidos y
  exigir que exista un marker project-owned con el contenido esperado antes de
  habilitar el borrado. La creación de ese marker es una operación de setup
  autorizada, no una consecuencia implícita del deploy.
- Fallar si cualquier variable de ruta está ausente o vacía. Comparar el path
  canónico con una allowlist exacta y revisar expresamente la semántica del
  slash final de origen y destino.
- Rechazar `--delete-excluded`: convierte exclusiones destinadas a proteger
  runtime en órdenes de borrado.
- Una exclusión es defensa adicional, no sustituto de una raíz persistente
  externa. Aplicarla antes de patrones amplios y en todas las fases que copian,
  extraen o limpian; validar el listado efectivo de filtros en el dry-run.
- No construir el destino concatenando inputs no confiables ni permitir que un
  fallo de variable lo reduzca a `/`, al home o a la raíz del hosting.

## Artefacto y extracción

- Construir en runner o builder aislado, inventariar y firmar o identificar el
  artefacto de forma reproducible.
- Extraer en staging o en una release nueva. No descomprimir recursivamente
  sobre una raíz viva que también contenga estado runtime.
- Excluir `.git`, `.github`, `.env`, dumps, backups, caches con secretos,
  storage y fuentes que el servidor no necesite, según el contrato del
  proyecto.
- Si `vendor` y bundles viajan en el artefacto, producción no debe volver a
  resolverlos de manera independiente.

## Build en servidor, panel o FTP/SFTP

- Verificar si el proveedor vacía el destino, conserva ficheros no presentes o
  rota directorios. “Sync” o “clean deploy” no garantiza preservación.
- Aplicar las mismas fronteras aunque no exista `rsync`: el panel o cliente no
  debe recibir ownership sobre la raíz runtime.
- Si Composer o Node se ejecutan en producción, fijar sus runtimes y evitar que
  un fallo deje la web activa a mitad del build. Siempre que sea posible,
  preparar primero una release o staging.

## Evidencia mínima

Antes de activar el workflow registrar, sin secretos:

- commit/tag y huella del artefacto;
- target de código resuelto;
- raíces runtime protegidas y prueba de que quedan fuera del target;
- dry-run de altas, cambios y borrados;
- resultado de tests, build y health checks;
- prueba de rollback;
- inventario y hash del sentinel o muestra completa de Media antes/después.
