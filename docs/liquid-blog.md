# Liquid Blog: contrato del MVP 0001

Liquid Blog es un módulo interno de `liquidstack/core`. Se activa mediante el
selector lógico `liquidstack/blog`, que activa también WebAdmin. No es un
paquete físico independiente y nunca ejecuta migraciones durante
`composer install` o `composer update`.

El primer corte entrega un flujo completo y deliberadamente pequeño: crear un
artículo, mantener variantes localizadas independientes, publicar o retirar
cada variante, servirla desde la base de datos y reflejarla inmediatamente en
un sitemap propio. El cuerpo es texto plano UTF-8 dividido en párrafos al
renderizar; no admite HTML libre.

## Propiedad y configuración

El código del módulo vive en CORE. La configuración, los datos, el copy y los
medios pertenecen al proyecto consumidor. El fichero opcional y project-owned
`App/config/modules/blog.php` no se publica, fusiona ni sobrescribe desde
Composer.

La configuración pública relaciona cada idioma activo con su ruta externa
completa. De este modo cada proyecto puede usar `/noticias`, `/news` o cualquier
otra base sin codificarla en CORE:

```php
<?php

return [
    'public_paths' => [
        'es' => '/es/noticias',
        'eu' => '/eu/albisteak',
        'en' => '/en/news',
    ],
    'sitemap_path' => '/blog-sitemap.xml',
    'database' => [
        'connection' => 'liquidstack',
        'table_prefix' => 'ls_blog_',
    ],
];
```

Las rutas deben ser absolutas, únicas, sin query, fragmento, barras duplicadas
ni segmentos relativos. Solo se aceptan idiomas declarados en
`App/config/langs.php`. El ejemplo opta por `liquidstack`; WebAdmin debe
declarar exactamente el mismo perfil en
`App/config/modules/webadmin.php`. Si ambos omiten `database.connection`, el
default compatible sigue siendo `shared`, que reutiliza `BBDD_*`.

La conexión dedicada requiere en el entorno
`LIQUIDSTACK_DB_HOST`, `LIQUIDSTACK_DB_PORT`, `LIQUIDSTACK_DB_NAME`,
`LIQUIDSTACK_DB_USER`, `LIQUIDSTACK_DB_PASSWORD` y
`LIQUIDSTACK_DB_CHARSET=utf8mb4`. No existe fallback entre perfiles: una
configuración divergente o incompleta bloquea Blog antes de abrir PDO. `.env`,
los dos ficheros de módulo y sus prefijos son project-owned; Composer no los
crea, fusiona ni sobrescribe.

El prefijo puede personalizarse, pero no se infiere ni se copia desde
WebAdmin. Las URLs absolutas usan `RAIZ` como origen canónico del proyecto:
debe ser un origen HTTPS sin path, query ni credenciales en producción. El
laboratorio admite `http://localhost:1309` —o el loopback canónico
equivalente— exclusivamente con `DEV_MODE=1`. El alias anterior
`LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN` sigue siendo compatible durante la
transición. Para no cambiar URLs canónicas en una actualización, una
discrepancia real en producción conserva temporalmente el alias y `doctor`
emite un aviso; se deben alinear ambos valores antes de retirar el alias. En
desarrollo, la `RAIZ` loopback prevalece para no convertir el origen de correo
de producción en una URL local del Blog. Ninguno se deriva de `Host`,
`Forwarded` ni del request, y Blog no depende de que SMTP esté listo.

## Modelo editorial

Un artículo es un agregado estable con UUID público. Cada variante de idioma
tiene sus propios:

- locale y slug;
- H1;
- `title` SEO y meta description;
- extracto;
- cuerpo de texto;
- estado y fecha de publicación;
- versión de bloqueo optimista;
- trazabilidad de creación y última edición mediante UUID público WebAdmin.

El slug es único dentro del idioma. Una misma traducción puede cambiar sus
textos sin afectar a las demás y cada variante se publica o retira de forma
independiente. El H1 no se deriva del `title`, y ninguno de los dos se deriva
forzosamente del slug.

Los únicos estados iniciales son `draft` y `published`. Publicar exige slug,
H1, title, description, extracto y cuerpo válidos. Retirar conserva contenido,
URL y auditoría y vuelve a `draft`; no existe borrado HTTP en este corte. La
edición exige la `lock_version` mostrada por el formulario: una versión antigua
produce conflicto y nunca sobrescribe cambios posteriores. Una variante
publicada es inmutable desde el editor: debe retirarse antes de modificar sus
campos y volver a publicarse después.

## Capacidades

El contrato inicial registra tres capacidades delegables:

| Capacidad | Permite |
| --- | --- |
| `blog.articles.view` | Consultar el listado y la vista previa privada guardada. |
| `blog.articles.edit` | Crear y editar borradores o variantes. |
| `blog.articles.publish` | Publicar y retirar variantes. |

Las cuentas protegidas de WebAdmin reciben las tres. Un `site_admin` puede
delegarlas a editores mediante la gestión existente; solo aparecen cuando Blog
está activo y el actor también las posee.

La migración de capacidades pertenece a Blog, pero usa explícitamente el scope
de su dependencia WebAdmin. El motor valida esa relación y resuelve el prefijo
efectivo; nunca se codifica `ls_webadmin_` en SQL. El registro conserva el
módulo propietario, el checksum y el hash del scope realmente utilizado.

## Superficie privada

Blog reclama un prefijo hijo del WebAdmin efectivo, `/admin/blog` con los
defaults. Si WebAdmin no pudo reclamar su prefijo, Blog no puede apropiarse del
hijo. Ambos comparten cookie, sesión, CSRF, política de credencial, perfil de
conexión y el mismo PDO.

Rutas del MVP:

| Método | Ruta por defecto | Finalidad |
| --- | --- | --- |
| `GET`/`HEAD` | `/admin/blog` | Listado de variantes. |
| `GET`/`HEAD` | `/admin/blog/posts/new` | Alta de artículo o idioma. |
| `POST` | `/admin/blog/posts/create` | Crear variante. |
| `GET`/`HEAD` | `/admin/blog/posts/edit` | Formulario por UUID y locale. |
| `GET`/`HEAD` | `/admin/blog/posts/preview` | Vista previa privada de la versión guardada. |
| `POST` | `/admin/blog/posts/save` | Guardar con versión optimista. |
| `POST` | `/admin/blog/posts/publish` | Publicar una variante completa. |
| `POST` | `/admin/blog/posts/unpublish` | Retirar una variante. |
| `GET`/`HEAD` | `/admin/blog/posts/updated` | Destino PRG sin PII. |

Los formularios son `application/x-www-form-urlencoded`, tienen campos
exactos, CSRF de la sesión WebAdmin y límites de bytes. El cuerpo admite hasta
300.000 bytes para que, incluso con percent-encoding completo y el resto de
campos, la petición permanezca dentro del límite global HTTP de 1 MiB. La
autorización de la UI es solo presentación: cada escritura vuelve a validar
SID, CSRF, `auth_version`, lifecycle y capacidad dentro de la transacción antes
de bloquear la variante. Toda mutación genera auditoría sin cuerpo, metadatos,
correo, SID, CSRF ni IP.

El listado se pagina en bloques de 50 mediante offsets canónicos y acotados;
consulta una fila adicional para saber si existe página siguiente y nunca
oculta silenciosamente las variantes posteriores.

La vista previa carga la variante persistida por UUID y locale, exige
`blog.articles.view` y no representa cambios todavía sin guardar. Funciona con
borradores incompletos, no necesita origen público y nunca emite canonical,
metadatos SEO públicos, slug ni una URL compartible. Su respuesta conserva
`no-store`, `noindex`, CSP privada y el resto de cabeceras de WebAdmin. No
publica, audita ni modifica la variante.

## Resolución pública y prioridad

Las rutas privadas se despachan antes del stack legacy para conservar el
aislamiento de WebAdmin. Las rutas públicas Blog se evalúan en una segunda fase
solo después de agotar:

1. la ruta estática GET o POST exacta del proyecto;
2. las rutas con query compatibles;
3. las subrutas especiales del showroom.

Por ello una URL estática del proyecto siempre gana frente a un slug Blog. La
publicación también comprueba el catálogo para impedir que el usuario publique
una variante que quedaría oculta. El path base puede ser una vista estática del
proyecto —por ejemplo `/noticias`—; el provider solo reclama descendientes con
slug válido y deja el índice al router existente.

Una variante `published` responde HTML en
`{public_path}/{slug}`. Un borrador o slug desconocido continúa hacia el 404
normal del proyecto. Una ruta reconocida cuyo runtime o esquema no esté listo
responde `503` genérico; una URL ajena no abre PDO. `HEAD` conserva status y
cabeceras sin cuerpo ni escrituras. Un `POST` no estático sobre una URL pública
Blog devuelve `405` con `Allow: GET, HEAD`.

## Sitemap dinámico

El endpoint configurado, `/blog-sitemap.xml` por defecto, consulta únicamente
variantes publicadas y construye URLs desde `RAIZ`: HTTPS fuera del laboratorio
y HTTP solo bajo el perfil loopback tipado de desarrollo. Nunca usa `Host`,
`Forwarded` o cabeceras del cliente como origen. No modifica
`public/sitemap.xml`, el repositorio ni el deploy. Publicar o retirar cambia su
respuesta inmediatamente porque la DB de producción es la fuente de verdad.
El documento admite como máximo 50.000 URLs: la consulta obtiene hasta 50.001
candidatas para detectar el desbordamiento y responder con un fallo genérico,
sin cargar un conjunto ilimitado ni truncarlo silenciosamente.

Una ruta o fichero project-owned con el mismo path bloquea `sitemap_ready` y se
muestra en `doctor`; no se reemplaza automáticamente. Desactivar el selector
retira rutas, navegación y sitemap, pero conserva tablas y contenido.

Durante `npm run lad`, el servidor PHP debe usar
`App/tools/php-dev-router.php`; sin él, `php -S` intenta resolver una ruta con
extensión como fichero y puede devolver su propio 404 antes de llegar a CORE.

## Diagnóstico y migraciones

El flujo operativo es explícito:

```powershell
composer liquidstack:doctor
composer liquidstack:migrate --plan
composer liquidstack:migrate --dry-run
composer liquidstack:migrate --apply
composer liquidstack:webadmin:bootstrap
composer liquidstack:doctor
```

`--plan` permanece offline y `--dry-run` solo lee. `--apply` no forma parte del
flujo por defecto: se ejecuta únicamente después de revisar el plan, confirmar
un backup recuperable y autorizar expresamente la mutación.

El bootstrap es idempotente y debe repetirse después de añadir Blog a una
instalación WebAdmin existente: así las cuentas protegidas reciben las nuevas
capacidades sin reactivar, duplicar ni sustituir usuarios. Ninguno de estos
pasos se ejecuta desde `composer update`.

Cambiar un proyecto con artículos o identidades existentes desde `shared` a
`liquidstack` no traslada ni adopta datos. Requiere una migración y verificación
manual de ambos namespaces y del registro `ls_module_migrations`, además de un
backup previo. El perfil dedicado inicial solo debe usarse en `localhost` o
una red confiable; el acceso a hosts no confiables queda pendiente de un
contrato TLS con CA y verificación del servidor.

`blog_ready` exige selector, configuración, idiomas, esquema y capacidades
aplicados, WebAdmin operativo, rutas públicas válidas y sitemap libre. El
diagnóstico no revela prefijos efectivos, slugs, contenido, correos, SQL ni
mensajes PDO.

## Fuera del MVP 0001

Quedan expresamente para cortes posteriores:

- categorías, etiquetas, buscador, archivos, relacionados, RSS y comentarios;
- biblioteca de medios, uploads, AVIF y metadatos de imagen;
- editor enriquecido, bloques, revisiones, previews compartibles y plantillas
  múltiples;
- workflow de aprobación, programación y borrado;
- redirecciones automáticas por cambio de slug;
- traducción mediante IA y generación automática de variantes;
- medidor SEO avanzado, Search Console e Indexing API;
- recursos de showroom alimentados por Blog.

Estas exclusiones evitan fijar prematuramente el constructor de contenido. El
agregado, las variantes, permisos, URLs y estados del MVP son la base estable
sobre la que se añadirán esas capacidades.
