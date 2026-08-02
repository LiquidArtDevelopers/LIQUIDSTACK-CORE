# Biblioteca de medios de WebAdmin

> Estado (2026-08-02): biblioteca y vinculación con el editor estructurado Blog
> implementadas en CORE. El comando operativo de inicialización, la gestión del
> ciclo de vida y otros formatos siguen pendientes. La adopción en consumidores
> exige migración y preparación de storage explícitas; Composer no realiza
> ninguna de las dos.

## Contrato implementado

WebAdmin incorpora una biblioteca privada compartida por los módulos que la
necesiten. Permite subir una imagen JPEG, PNG o WebP, validarla, normalizarla y
generar variantes AVIF responsive. Blog la consume mediante una frontera
cross-scope; la biblioteca sigue perteneciendo a WebAdmin.

## Ownership y actualización segura

- El código vive en `liquidstack/core` y el ownership lógico es `webadmin`.
- Blog la recibe transitivamente al depender de WebAdmin.
- Composer solo distribuye código y la definición de migración. Nunca crea
  tablas, inicializa storage, procesa imágenes ni borra medios.
- La DB y los ficheros generados son datos de cada proyecto y entorno; no se
  versionan ni se trasladan mediante `npm run build`.
- Actualizar CORE con la migración pendiente debe mantener operativo el núcleo
  de `/admin`. Solo `/admin/media` puede responder como no preparado hasta
  aplicar su migración explícita.

La migración implementada es `0002_webadmin_media_library`. Sustituye la
postcondición exacta de `0001_webadmin_identity_and_access` únicamente después
de quedar aplicada y verificar el contrato combinado 0001 + 0002. El gate base
de WebAdmin exige siempre la fundacional 0001, valida checksum y scope de lo ya
aplicado y tolera migraciones conocidas pendientes. El gate específico de
medios exige también 0002.

## Persistencia mínima

`{prefix}media_assets` conserva UUID público, etiqueta interna, MIME y
dimensiones de origen, bytes, SHA-256, autor y fecha. No guarda el nombre
original del fichero.

`{prefix}media_variants` conserva asset, ancho, alto, bytes, SHA-256, clave
relativa opaca, MIME AVIF y fecha. La combinación asset + ancho y cada clave de
storage son únicas.

ALT, title y pie no pertenecen al asset compartido: son datos localizados de
cada uso de la imagen dentro de Blog u otro editor.

## Storage

La raíz es privada y queda fuera de `public`, `vendor`, `.git` y cualquier
directorio reemplazable por deploy. El layout canónico es:

```text
storage/liquidstack/webadmin/media/{shard}/{uuid}/480.avif
storage/liquidstack/webadmin/media/{shard}/{uuid}/900.avif
storage/liquidstack/webadmin/media/{shard}/{uuid}/1800.avif
storage/liquidstack/webadmin/media/{shard}/{uuid}/{master-width}.avif
```

Un comando explícito futuro `composer liquidstack:media:init` validará la raíz,
rechazará symlinks o targets peligrosos y creará un marcador. Producción deberá
declarar el storage persistente de forma explícita; el laboratorio podrá usar
el directorio privado por defecto del proyecto.

DB y storage se respaldan y restauran como una unidad. Cambiar credenciales o
la raíz no mueve datos. Una promoción selectiva local → producción mediante
exportación e importación con hashes continúa pendiente.

## Contrato de imagen

- Entrada real JPEG, PNG o WebP; no se confía en extensión o MIME del browser.
- Máximo 12 MiB, 12.000 píxeles por lado y 40 megapíxeles.
- Rechazo de SVG, GIF, PDF, HEIC, animaciones, multiframe y poliglotas.
- Verificación coincidente con `fileinfo` y el decodificador.
- Orientación normalizada, conversión a sRGB, transparencia conservada y todos
  los metadatos eliminados.
- Master encajado en 2560 × 2560 sin recortar ni ampliar.
- Anchos 480, 900, 1800 y ancho real del master, eliminando duplicados.
- AVIF de calidad fija inicial 74; cada salida se reabre y verifica.

Imagick y soporte AVIF se comprueban mediante `doctor` y una suite opt-in; no
son un requisito duro de Composer, para no romper consumidores que no activen
medios. El helper legacy `imgConvert()` no cumple este contrato y no se
reutiliza.

## Seguridad y escritura atómica

Las capacidades son `webadmin.media.view` y
`webadmin.media.upload`. La subida revalida sesión, CSRF, lifecycle,
`auth_version` y ambas capacidades dentro de la transacción.

El procesado ocurre en staging aleatorio dentro del mismo storage. Solo tras
verificar todas las variantes se renombra al directorio UUID definitivo; a
continuación se insertan asset, variantes y auditoría en una transacción. Un
fallo DB elimina el directorio definitivo. `doctor` detecta directorios
huérfanos derivados de un crash entre rename y commit.

La auditoría registra `webadmin.media.created` y el UUID, nunca nombre de
origen, path, hash, contenido o metadatos privados.

## Superficie HTTP y UI

Rutas bajo el prefijo WebAdmin configurable:

- `GET|HEAD /admin/media`;
- `POST /admin/media/upload`;
- `GET|HEAD /admin/media/updated`;
- `GET|HEAD /admin/media/file?asset={uuid}&width={width}`.

La UI es SSR y accesible: navegación “Biblioteca de
medios”, listado paginado, cards con miniatura privada, etiqueta, dimensiones y
fecha, y formulario de una imagen. Explica formatos, límites, conversión
automática y que ALT/title se asignan al usar el asset. Las respuestas privadas
mantienen `no-store`, `noindex`, `nosniff` y CSP con `img-src 'self'`.

El objeto `Request` ofrece soporte multipart acotado mediante un value
object `UploadedFile`, sin elevar el límite de 1 MiB de formularios normales ni
leer indiscriminadamente `php://input`. El contrato rechaza árboles `$_FILES`
anidados, múltiples o corruptos.

Los assets administrativos canónicos viven en
`modules/webadmin/published/assets` y el manifiesto los sincroniza como
module-managed hacia `public/assets/modules/webadmin`.

## Integración actual con Liquid Blog

El editor `/admin/blog/editor` requiere `webadmin.media.view` además de la
capacidad Blog de lectura o edición correspondiente. Selecciona assets ya
procesados; subir uno nuevo sigue ocurriendo en `/admin/media` y exige también
`webadmin.media.upload`.

Cada bloque de imagen guarda su UUID de asset junto a ALT, title, caption,
estado decorativo y modo de presentación localizados por uso. El guardado
revalida bajo la misma transacción que el asset existe y dispone de variantes.
El documento actual y cada revisión conservan sus propias referencias.

La entrega pública utiliza
`/_liquidstack/blog-media/{uuid}/{width}.avif`. Solo es elegible un asset
referenciado por el documento actual de una variante publicada; un borrador o
una revisión aislada no lo hacen público. La lectura verifica bytes y hash en el
storage privado y responde `404` uniforme ante cualquier fallo.

## Pendientes reales

- Borrado, reemplazo y garbage collection con protección de referencias.
- Crop, focal point, vídeo, audio, SVG, S3/CDN o procesamiento asíncrono.
- Carpetas, etiquetas, buscador, deduplicación y promoción automática entre
  entornos.

Hasta que exista ese comando, el operador debe crear la raíz persistente con
permisos exclusivos del proceso PHP y declarar su ruta absoluta mediante
`LIQUIDSTACK_WEBADMIN_MEDIA_STORAGE_ROOT`. En desarrollo local tipado
(`DEV_MODE=1` y `RAIZ` loopback) puede utilizarse el default privado
`storage/liquidstack/webadmin/media`; producción no admite ese fallback.

## Pruebas de aceptación

- Multipart válido y corrupto sin regresión en formularios normales.
- Migración vacía, pendiente, aplicada, drift, checksum y supersesión en SQLite
  y MySQL opt-in.
- Un consumidor con solo 0001 conserva `/admin`; `/admin/media` exige 0002.
- Storage seguro frente a traversal, symlinks y raíces peligrosas.
- Procesado con fakes y suite Imagick opt-in: MIME, límites, multiframe,
  dimensiones, no-upscale, AVIF reabierto y metadatos eliminados.
- Rollback coordinado de DB, ficheros y auditoría.
- Sesión, CSRF, capacidades, revocación concurrente, cuota y rate limit.
- GET/HEAD sin mutaciones y entrega binaria privada con cabeceras correctas.
- Composer require/update/remove sin tocar DB ni storage.
