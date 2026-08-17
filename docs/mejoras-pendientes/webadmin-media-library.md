# Biblioteca de medios de WebAdmin

> Estado (2026-08-17, CORE `Unreleased`): biblioteca, vinculación con el editor
> estructurado Blog y retirada recuperable mediante cuarentena implementadas.
> La purga irreversible, restauración operativa y otros formatos siguen
> pendientes. La adopción en consumidores exige migración e inicialización de
> storage explícitas; los eventos automáticos de Composer no realizan ninguna.

## Contrato implementado

WebAdmin incorpora una biblioteca privada compartida por los módulos que la
necesiten. Permite subir una imagen JPEG, PNG, WebP o AVIF, validarla, normalizarla y
generar variantes AVIF responsive. Blog la consume mediante una frontera
cross-scope; la biblioteca sigue perteneciendo a WebAdmin.

## Ownership y actualización segura

- El código vive en `liquidstack/core` y el ownership lógico es `webadmin`.
- Blog la recibe transitivamente al depender de WebAdmin.
- `composer install` y `composer update` solo distribuyen código y la definición
  de migración. Nunca crean tablas, inicializan storage, procesan imágenes ni
  borran medios; la inicialización requiere el comando operativo explícito.
- La DB y los ficheros generados son datos de cada proyecto y entorno; no se
  versionan ni se trasladan mediante `npm run build`.
- Actualizar CORE con la migración pendiente debe mantener operativo el núcleo
  de `/admin`. Solo `/admin/media` puede responder como no preparado hasta
  aplicar su migración explícita.

Las migraciones implementadas son `0002_webadmin_media_library`,
`0003_webadmin_media_avif_source` y la aditiva
`0005_webadmin_media_quarantine` (la 0004 pertenece al perfil WebAdmin). La
0003 amplía el MIME de origen sin
reescribir el checksum publicado de la 0002 y verifica el contrato combinado
0001 + 0002 + 0003. El gate base
de WebAdmin exige siempre la fundacional 0001, valida checksum y scope de lo ya
aplicado y tolera migraciones conocidas pendientes. El gate específico de
medios exige también 0002. Un gate incremental separado exige 0003 solo al
admitir un original AVIF: pendiente no bloquea `/admin/media`, Blog, la
selección de assets ni las entradas JPEG/PNG/WebP. Otro gate incremental
habilita la retirada solo al verificar 0005; mientras esté pendiente, la
biblioteca conserva lectura y subida pero no muestra ni acepta esa mutación.

## Persistencia mínima

`{prefix}media_assets` conserva UUID público, etiqueta interna, MIME y
dimensiones de origen, bytes, SHA-256, autor y fecha. No guarda el nombre
original del fichero.

`{prefix}media_variants` conserva asset, ancho, alto, bytes, SHA-256, clave
relativa opaca, MIME AVIF y fecha. La combinación asset + ancho y cada clave de
storage son únicas.

`{prefix}media_quarantines` conserva una fila por asset retirado: estado,
versión CAS, prefijos original y de cuarentena, manifiesto canónico con su
SHA-256, request id idempotente, actor, fecha y versión de lock. Las filas de
asset y variantes permanecen intactas para que la operación sea recuperable;
las consultas ordinarias las excluyen mientras exista la cuarentena.

ALT, title y pie no pertenecen al asset compartido: son datos localizados de
cada uso de la imagen dentro de Blog u otro editor.

## Storage

La raíz es privada y queda fuera de `public`, `vendor`, `.git` y cualquier
directorio reemplazable por deploy. El layout canónico es:

```text
storage/liquidstack/webadmin/media/.liquidstack-webadmin-media
storage/liquidstack/webadmin/media/.liquidstack-webadmin-media.lock
storage/liquidstack/webadmin/media/.gitignore
storage/liquidstack/webadmin/media/.staging/
storage/liquidstack/webadmin/media/.quarantine/assets/{shard}/{uuid}/{request-uuid}/
storage/liquidstack/webadmin/media/.quarantine/manifests/{request-uuid}.json
storage/liquidstack/webadmin/media/{shard}/{uuid}/480.avif
storage/liquidstack/webadmin/media/{shard}/{uuid}/900.avif
storage/liquidstack/webadmin/media/{shard}/{uuid}/1800.avif
storage/liquidstack/webadmin/media/{shard}/{uuid}/{master-width}.avif
```

La raíz se prepara únicamente con una operación autorizada:

```bash
composer liquidstack:media:init
# Automatización controlada, sin prompt:
composer liquidstack:media:init --yes --format=json
```

El modo texto confirma la mutación si no recibe `--yes`; JSON exige siempre
`--yes`. El comando carga el entorno, comprueba que WebAdmin está activo,
valida la raíz y crea el marcador versionado `.liquidstack-webadmin-media`, el
lock interno, `.staging/` y un `.gitignore` interno que excluye todo el storage.
En este modo normal no abre PDO, no procesa imágenes y no comprueba SMTP. Es
idempotente sobre una raíz ya inicializada y puede reparar sus auxiliares
internos, pero no adopta una raíz no vacía sin el marcador válido.

Producción debe declarar una ruta absoluta y persistente mediante
`LIQUIDSTACK_WEBADMIN_MEDIA_STORAGE_ROOT`, siempre fuera del árbol del
proyecto/deploy. El default `storage/liquidstack/webadmin/media` solo es válido
en el laboratorio cuando `DEV_MODE=1` y `RAIZ` es un loopback canónico. Se
rechazan traversal, raíces de disco, `public`, `vendor`, `.git`, la raíz del
proyecto y cualquier symlink o junction del recorrido.

### Adopción excepcional de storage legacy

Un proyecto actualizado puede tener variantes creadas por una versión anterior
al marker. Esa raíz no se renombra, mueve ni marca con el inicializador normal.
Tras verificar un backup conjunto y recuperable de DB y storage, el operador
puede solicitar expresamente:

```bash
composer liquidstack:media:init --adopt-existing --backup-confirmed --yes
# La misma operación con salida estructurada:
composer liquidstack:media:init --adopt-existing --backup-confirmed --yes --format=json
```

`--adopt-existing` nunca abre una pregunta interactiva: exige `--yes` y
`--backup-confirmed`; usar la confirmación de backup sin adopción también es
inválido. La operación requiere WebAdmin activo, entorno utilizable y el gate
completo de `0002_webadmin_media_library`. Bajo una transacción y el lock
`media.quota_lock=v1`, compara bidireccionalmente las filas de assets/variantes
con la raíz privada. Cada clave debe ser canónica y única; MIME, bytes y
SHA-256 deben coincidir; no puede faltar ni sobrar un fichero, existir enlaces
o quedar contenido en staging.

Solo después de verificar todo el conjunto crea lock, staging, `.gitignore` y
marker. No modifica filas ni reescribe variantes. Ante cualquier diferencia
revierte la transacción y no altera el layout legacy. Una raíz ausente o vacía
usa el inicializador normal, nunca esta vía de adopción.

En JSON, una adopción correcta devuelve `result.status=adopted_existing`. Los
blockers estables distinguen flags incompletas
(`webadmin.media.init.adoption_requires_yes`,
`webadmin.media.init.adoption_requires_backup_confirmation` y
`webadmin.media.init.backup_confirmation_without_adoption`), schema pendiente
(`webadmin.media.init.schema_not_ready`), mismatch DB↔FS
(`webadmin.media.storage_adoption_mismatch`), raíz que debe pasar por el flujo
normal (`webadmin.media.storage_adoption_not_required`) y fallo cerrado de
conexión/transacción/lock (`webadmin.media.storage_adoption_database_failed`).

DB y storage se respaldan y restauran como una unidad. Cambiar credenciales o
la raíz no mueve datos. Una promoción selectiva local → producción mediante
exportación e importación con hashes continúa pendiente.

## Contrato de imagen

- Entrada real JPEG, PNG o WebP y, con 0003 lista, también AVIF; no se confía en
  extensión o MIME del browser.
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

Las capacidades son `webadmin.media.view`, `webadmin.media.upload` y
`webadmin.media.delete`. Subida y retirada revalidan sesión, CSRF, lifecycle,
`auth_version` y sus capacidades dentro de la transacción.

El procesado ocurre en staging aleatorio dentro del mismo storage. Solo tras
verificar todas las variantes se renombra al directorio UUID definitivo; a
continuación se insertan asset, variantes y auditoría en una transacción. Un
fallo DB elimina el directorio definitivo. `doctor` detecta directorios
huérfanos derivados de un crash entre rename y commit.

La auditoría registra `webadmin.media.created` y el UUID, nunca nombre de
origen, path, hash, contenido o metadatos privados.

La retirada solo se ofrece cuando todos los proveedores activos pueden
resolver referencias y el resultado agregado es cero. Estado `used`,
proveedor no preparado o inspección fallida cierran la operación. Bajo el lock
DB de cuota y el lock privado del storage, el directorio UUID se renombra de
forma atómica dentro de la misma raíz y se escribe un manifiesto durable. DB y
auditoría (`webadmin.media.quarantined`) se confirman después; si fallan, el
rename se compensa y el manifiesto transitorio se retira. Esta fase no elimina
bytes ni filas de forma irreversible.

## Superficie HTTP y UI

Rutas bajo el prefijo WebAdmin configurable:

- `GET|HEAD /admin/media`;
- `POST /admin/media/upload`;
- `POST /admin/media/delete`;
- `GET|HEAD /admin/media/updated`;
- `GET|HEAD /admin/media/deleted`;
- `GET|HEAD /admin/media/file?asset={uuid}&width={width}`.

La UI es SSR y accesible: navegación “Biblioteca de
medios”, listado paginado, cards con miniatura privada, etiqueta, dimensiones y
fecha, y formulario de una imagen. Explica formatos, límites, conversión
automática y que ALT/title se asignan al usar el asset. Las respuestas privadas
mantienen `no-store`, `noindex`, `nosniff` y CSP con `img-src 'self'`.
Solo una card con conocimiento completo y estado “Sin usar” muestra la acción
de cuarentena. JavaScript abre un diálogo accesible y reemplaza de forma
reactiva el fragmento SSR del catálogo; sin JavaScript, el mismo formulario
POST usa PRG y una confirmación SSR segura.

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

## Orden de adopción

1. Ejecutar `doctor`, `migrate --plan` y `migrate --dry-run`.
2. Crear y verificar un backup recuperable y coordinado de DB y storage.
3. Aplicar las migraciones con autorización expresa.
4. Ejecutar `composer liquidstack:media:init`.
5. Ejecutar o repetir `composer liquidstack:webadmin:bootstrap`.
6. Repetir `doctor` y completar el QA HTTP de `/admin`, `/admin/media` y, si
   está activo, Blog; después se puede despachar el outbox.

## Pendientes reales

- Operación autorizada de restauración y purga/garbage collection posterior a
  una retención definida, verificando de nuevo referencias, manifiesto y
  auditoría. No existe purga irreversible ni cron automático en esta fase.
- Reemplazo de assets conservando usos y revisiones.
- Crop, focal point, vídeo, audio, SVG, S3/CDN o procesamiento asíncrono.
- Carpetas, etiquetas, buscador, deduplicación y promoción automática entre
  entornos.

## Pruebas de aceptación

- Multipart válido y corrupto sin regresión en formularios normales.
- Migración vacía, pendiente, aplicada, drift, checksum y supersesión en SQLite
  y MySQL opt-in.
- Un consumidor con solo 0001 conserva `/admin`; `/admin/media` exige 0002.
- Inicialización explícita e idempotente, confirmación/`--yes`, contrato JSON,
  marcador, `.gitignore`, staging y rechazo de adopción implícita.
- Adopción legacy con doble confirmación, schema 0002 y lock de cuota;
  coincidencia bidireccional DB↔FS y ausencia total de mutaciones ante mismatch.
- Storage seguro frente a traversal, symlinks, junctions y raíces peligrosas;
  default exclusivamente local y ruta persistente obligatoria en producción.
- Procesado con fakes y suite Imagick opt-in: MIME, límites, multiframe,
  dimensiones, no-upscale, AVIF reabierto y metadatos eliminados.
- Rollback coordinado de DB, ficheros y auditoría.
- Cuarentena bajo locks DB+storage, CAS, idempotencia, manifiesto durable,
  exclusión del catálogo y compensación real de filesystem ante rollback DB.
- Estado `used`/`unknown` sin botón ni mutación; diálogo reactivo y fallback
  SSR con la misma política server-side.
- Sesión, CSRF, capacidades, revocación concurrente, cuota y rate limit.
- GET/HEAD sin mutaciones y entrega binaria privada con cabeceras correctas.
- Composer require/update/remove sin tocar DB ni storage.
