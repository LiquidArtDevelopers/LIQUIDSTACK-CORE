# Biblioteca de medios de WebAdmin

## Objetivo del siguiente corte

WebAdmin incorporará una biblioteca privada compartida por los módulos que lo
necesiten. El primer corte permitirá subir una imagen JPEG, PNG o WebP,
validarla, normalizarla y generar variantes AVIF responsive. Blog la consumirá
en un corte posterior; la biblioteca no pertenecerá al dominio Blog.

Este trabajo debe comenzar después de publicar y adoptar el corte de
laboratorio local, correo capturado y preview Blog. No se mezclará con esa
release porque añade esquema y almacenamiento persistente nuevos.

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

La migración propuesta será `0002_webadmin_media_library`. Debe sustituir la
postcondición exacta de `0001_webadmin_identity_and_access` únicamente después
de quedar aplicada y verificar el contrato combinado 0001 + 0002. El gate base
de WebAdmin exigirá siempre la fundacional 0001, validará checksum y scope de
lo ya aplicado y tolerará migraciones conocidas pendientes. El gate específico
de medios exigirá también 0002.

## Persistencia mínima

`{prefix}media_assets` conservará UUID público, etiqueta interna, MIME y
dimensiones de origen, bytes, SHA-256, autor y fecha. No guardará el nombre
original del fichero.

`{prefix}media_variants` conservará asset, ancho, alto, bytes, SHA-256, clave
relativa opaca, MIME AVIF y fecha. La combinación asset + ancho y cada clave de
storage serán únicas.

ALT, title y pie no pertenecen al asset compartido: serán datos localizados de
cada uso de la imagen dentro de Blog u otro editor.

## Storage

La raíz será privada y quedará fuera de `public`, `vendor`, `.git` y cualquier
directorio reemplazable por deploy. El layout canónico será:

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
exportación e importación con hashes queda fuera del primer corte.

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

Imagick y soporte AVIF se comprobarán mediante `doctor` y una suite opt-in; no
se convertirán en requisito duro de Composer para no romper consumidores que
no activen medios. El helper legacy `imgConvert()` no cumple este contrato y no
se reutilizará.

## Seguridad y escritura atómica

Las capacidades iniciales serán `webadmin.media.view` y
`webadmin.media.upload`. La subida revalidará sesión, CSRF, lifecycle,
`auth_version` y ambas capacidades dentro de la transacción.

El procesado ocurrirá en staging aleatorio dentro del mismo storage. Solo tras
verificar todas las variantes se renombrará al directorio UUID definitivo; a
continuación se insertarán asset, variantes y auditoría en una transacción. Un
fallo DB eliminará el directorio definitivo. `doctor` detectará directorios
huérfanos derivados de un crash entre rename y commit.

La auditoría registrará `webadmin.media.created` y el UUID, nunca nombre de
origen, path, hash, contenido o metadatos privados.

## Superficie HTTP y UI del MVP

Rutas bajo el prefijo WebAdmin configurable:

- `GET|HEAD /admin/media`;
- `POST /admin/media/upload`;
- `GET|HEAD /admin/media/updated`;
- `GET|HEAD /admin/media/file?asset={uuid}&width={width}`.

La UI será SSR y accesible, sin dependencia JS: navegación “Biblioteca de
medios”, listado paginado, cards con miniatura privada, etiqueta, dimensiones y
fecha, y formulario de una imagen. Explicará formatos, límites, conversión
automática y que ALT/title se asignan al usar el asset. Las respuestas privadas
mantendrán `no-store`, `noindex`, `nosniff` y CSP con `img-src 'self'`.

El objeto `Request` necesitará soporte multipart acotado mediante un value
object `UploadedFile`, sin elevar el límite de 1 MiB de formularios normales ni
leer indiscriminadamente `php://input`. Este MVP rechazará árboles `$_FILES`
anidados, múltiples o corruptos.

## Fuera del primer corte

- Vinculación de portada o bloques Blog, ALT/title/pie por idioma y entrega
  pública mediante `<picture>`.
- Editor por bloques, borrado, reemplazo y garbage collection.
- Crop, focal point, vídeo, audio, SVG, S3/CDN o procesamiento asíncrono.
- Carpetas, etiquetas, buscador, deduplicación y promoción automática entre
  entornos.

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
