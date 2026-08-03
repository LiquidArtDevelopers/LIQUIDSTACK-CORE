# Caché last-known-good del sitemap Blog

## Estado y alcance

La caché persistente LKG del sitemap está implementada como una capacidad
opcional de Blog y permanece desactivada por defecto. Su objetivo es poder
servir el último XML válido durante una indisponibilidad clasificada de la
conexión a la DB, sin permitir que una publicación o retirada concurrente
reactive un snapshot anterior.

La recuperación LKG es deliberadamente conservadora. Solo se usa cuando la
conexión no puede establecerse y el error se clasifica como
`database.connection_unavailable`. Un esquema pendiente o inválido, una
configuración errónea, un error de consulta no clasificado, overflow, fallo de
render o corrupción del storage responden de forma cerrada; nunca habilitan el
fallback.

## Activación explícita

El proyecto opta por la capacidad en su fichero project-owned
`App/config/modules/blog.php`:

```php
'sitemap_cache' => [
    'enabled' => true,
    'ttl_seconds' => 300,
],
```

El TTL admite entre 30 y 3.600 segundos. Activar la clave no crea tablas ni
directorios. Primero se aplica el prefijo Blog completo `0001`–`0006`; la
migración append-only `0006_blog_sitemap_publication_state` incorpora un
singleton con una revisión pública monótona y la generación activa del storage.
Después se inicializa expresamente la raíz privada:

```bash
composer liquidstack:doctor
composer liquidstack:migrate --plan
composer liquidstack:migrate --dry-run
# Crear y verificar aquí un backup recuperable de DB y storage.
composer liquidstack:migrate --apply
composer liquidstack:blog:sitemap-cache:init
composer liquidstack:doctor
```

En formato no interactivo se usa `--yes`. En producción se exige además
`--shared-storage-confirmed`, que confirma una decisión operativa del
despliegue; no constituye una prueba automática del filesystem.

`composer install` y `composer update` se limitan a distribuir el código y la
migración. Nunca activan la caché, ejecutan la migración, crean su storage ni
modifican la configuración del consumidor.

## Storage y despliegue

En desarrollo con `DEV_MODE=1` y `RAIZ` HTTP loopback, la ausencia de una ruta
explícita usa `storage/liquidstack/blog/sitemap-cache`. En cualquier otro
perfil es obligatoria una ruta absoluta declarada mediante:

```dotenv
LIQUIDSTACK_BLOG_SITEMAP_CACHE_ROOT=
```

En producción debe ser privada, persistente, quedar fuera del árbol del
proyecto y ser compartida por todos los nodos que atiendan Blog. El volumen
debe ofrecer `flock` advisory coherente entre nodos y `rename` atómico dentro
del mismo filesystem. CORE rechaza symlinks y junctions detectables, raíces de
disco, `public`, `vendor`, `.git` y ubicaciones dentro del deploy productivo.

La inicialización crea un marker de propiedad y una generación UUID. Esa
generación se activa en la DB dentro de una transacción. Una repetición es
idempotente y también puede completar con seguridad el caso en que el proceso
cayera después de crear el marker pero antes de activar la generación. Una
generación distinta falla cerrada. La raíz activa no se rota ni se sustituye
por intuición: DB y storage se respaldan, restauran y despliegan como una sola
unidad.

Los directorios UUID incompletos que una caída deje en `.staging` se eliminan
solo bajo el lock exclusivo, con nombres, profundidad, cantidad y ficheros
permitidos estrictamente acotados. Una entrada inesperada no se sigue ni se
borra: invalida el storage y obliga a inspeccionarlo.

## Consistencia editorial

Cada `publish` o `unpublish`:

1. bloquea la fila de estado dentro de la misma transacción editorial;
2. adquiere el lock exclusivo del storage y escribe un fence durable
   `.blocked` antes de cambiar la visibilidad;
3. actualiza la variante, la auditoría y la revisión pública monótona en esa
   transacción;
4. libera el lock tras commit o rollback.

Un rollback puede dejar el fence, pero eso solo deshabilita temporalmente el
fallback. La siguiente respuesta fresca válida genera y promueve un snapshot
de la revisión actual y retira el fence. Así se prefiere una indisponibilidad
cerrada a anunciar una URL retirada.

Si otra transición encuentra un fence existente de la misma generación, lo
valida y conserva; nunca lo sustituye mediante unlink/rename. No existe así una
ventana de crash en la que desaparezca una invalidación ya durable.

El generador lee la revisión antes y después de construir el XML. Si cambia,
repite; antes de promover vuelve a bloquear la fila y compara la generación y
revisión exactas. La promoción se realiza bajo el mismo orden de locks que la
publicación. Los snapshots son inmutables, tienen manifest, SHA-256, ETag,
identidad de configuración —incluidos origen, rutas, renderer, destino DB y
prefijo de tablas—, revisión, generación, tamaño máximo de 50 MiB y caducidad
explícita. El instante exacto de expiración ya no es válido. Un fichero
truncado, corrupto, futuro, vencido o de otra identidad se rechaza.

## Contrato HTTP y observabilidad

Las respuestas frescas y LKG conservan el mismo XML, CSP, comportamiento
`GET`/`HEAD`, `Cache-Control: public, no-cache, must-revalidate` y ETag fuerte.
`If-None-Match` puede producir `304` también sobre el snapshot válido. La
fuente queda visible sin datos sensibles mediante:

```text
X-LiquidStack-Sitemap-Source: database
X-LiquidStack-Sitemap-Source: stale-cache
Warning: 110 - "Response is stale"
```

La cabecera `Warning` solo acompaña a `stale-cache`. No se emite un
`Last-Modified` global ni se delega esta decisión en `stale-if-error`, porque
un proxy no conoce los fences editoriales.

`composer liquidstack:doctor` informa la capacidad como desactivada, pendiente
de migración/inicialización, preparada o inválida/bloqueada. Cuando inspecciona
la DB, compara expresamente su generación con el marker; el runtime repite esa
comprobación antes de publicar, retirar o regenerar.

## QA mínimo por consumidor

- probar `GET`, `HEAD`, ETag y `304` sin `PHPSESSID`;
- publicar y retirar, comprobando que el XML fresco cambia y que el fence no
  permite servir la revisión anterior;
- simular una conexión indisponible con snapshot vigente y verificar
  `stale-cache`; sin snapshot utilizable debe responder `503` genérico;
- comprobar expiración, corrupción e identidad distinta;
- en un despliegue multinodo, validar realmente locks compartidos y promoción
  atómica sobre el volumen elegido antes de activar producción.
