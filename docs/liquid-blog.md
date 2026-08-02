# Liquid Blog: contrato operativo

Liquid Blog es un módulo interno de `liquidstack/core`. Se activa mediante el
selector lógico `liquidstack/blog`, que activa también WebAdmin. No es un
paquete físico independiente y nunca ejecuta migraciones durante
`composer install` o `composer update`.

El módulo mantiene el contrato editorial inicial —artículos, variantes por
idioma, publicación, resolución pública y sitemap dinámico— y lo amplía con
categorías localizadas y un editor estructurado. El cuerpo canónico nuevo es
un documento JSON validado; `body_text` se deriva siempre en servidor para
conservar la compatibilidad con las consultas y artículos anteriores.

Las migraciones Blog se aplican de forma aditiva y explícita:

| Migración | Frontera que incorpora |
| --- | --- |
| `0001_blog_posts` | Artículos y variantes localizadas. |
| `0002_blog_capabilities` | Capacidades base de artículos. |
| `0003_blog_categories` | Categorías localizadas y asignaciones; compone y verifica el esquema de `0001`. |
| `0004_blog_category_capabilities` | Capacidades de categorías; compone las semillas de `0002`. |
| `0005_blog_structured_content` | Documento actual, referencias de medios y revisiones inmutables; compone y verifica las postcondiciones de `0001` y `0003`. |

Cada frontera tiene su propio gate de disponibilidad. Una migración nueva
pendiente no autoriza a CORE a completar tablas por intuición ni debe inutilizar
las funciones anteriores cuyo contrato siga verificado.

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
    'public_article_view' => 'App/views/blog-article.php',
    'database' => [
        'connection' => 'liquidstack',
        'table_prefix' => 'ls_blog_',
    ],
];
```

`public_article_view` es opcional. Cuando se declara, debe ser una ruta PHP
relativa a la raíz del proyecto bajo `App/views/`; el fichero y todos sus
directorios deben existir, ser legibles y no contener symlinks. Una ruta
absoluta, un traversal, una ruta fuera de `App/views` o un fichero ausente
bloquean Blog de forma cerrada. Omitir la clave conserva el renderer standalone
compatible y no exige crear una vista en proyectos existentes.

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

Cada categoría es otro agregado estable con UUID público y una traducción
independiente por locale. Nombre y slug pertenecen a la traducción; el slug es
único dentro de su idioma. La edición usa `lock_version` y las asignaciones se
reemplazan de forma transaccional, conservando las relaciones que no cambian.
Una operación admite hasta 100 categorías y nunca expone IDs numéricos de DB.

El contenido estructurado usa el esquema exacto
`liquidstack.blog.document`, versión `1`, con un máximo de 200 bloques y
300.000 bytes de JSON canónico. Las plantillas iniciales son
`article-basic-01` y `article-cover-01`; esta última exige una única imagen de
portada como primer bloque. El H1 sigue siendo un campo independiente de la
variante y no forma parte del cuerpo.

El documento admite ocho tipos de bloque controlados:

- párrafo;
- encabezado H2 o H3, sin saltos de jerarquía;
- lista ordenada o no ordenada;
- destacado (`callout`);
- enlace independiente;
- imagen de WebAdmin Media con ALT, title, caption, estado decorativo y modo de
  presentación por cada uso;
- vídeo YouTube con carga ligera;
- CTA primaria o secundaria.

El texto inline admite texto, saltos y enlaces con marcas `strong` y `em`, pero
no HTML, clases o CSS libres. `body_text` nunca llega desde el navegador: se
proyecta desde el documento validado dentro del mismo guardado.

Cada guardado efectivo crea una revisión inmutable que conserva documento,
metadatos y versión editorial. Restaurar una revisión no reescribe el historial:
crea una revisión nueva y también exige la `lock_version` vigente. Al abrir el
editor para un artículo anterior, CORE proyecta `body_text` a párrafos solo en
memoria; la adopción estructurada ocurre exclusivamente al guardar. Desde ese
momento, el guardado legacy de texto plano queda bloqueado para evitar dos
fuentes de verdad.

## Capacidades

El contrato registra capacidades delegables por frontera:

| Capacidad | Permite |
| --- | --- |
| `blog.articles.view` | Consultar el listado y la vista previa privada guardada. |
| `blog.articles.edit` | Crear y editar borradores o variantes. |
| `blog.articles.publish` | Publicar y retirar variantes. |
| `blog.categories.view` | Consultar categorías localizadas. |
| `blog.categories.edit` | Crear, traducir, editar y asignar categorías. |

Las cuentas protegidas de WebAdmin reciben todas. Un `site_admin` puede
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

Rutas privadas:

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
| `GET`/`HEAD` | `/admin/blog/categories` | Listado localizado de categorías. |
| `GET`/`HEAD` | `/admin/blog/categories/new` | Alta de categoría o traducción. |
| `POST` | `/admin/blog/categories/create` | Crear categoría o traducción. |
| `GET`/`HEAD` | `/admin/blog/categories/edit` | Edición por UUID y locale. |
| `POST` | `/admin/blog/categories/save` | Guardado con versión optimista. |
| `GET`/`HEAD` | `/admin/blog/categories/assign` | Selección para un artículo. |
| `POST` | `/admin/blog/categories/assign` | Sustitución transaccional de asignaciones. |
| `GET`/`HEAD` | `/admin/blog/categories/updated` | Destino PRG sin datos editoriales. |
| `GET`/`HEAD` | `/admin/blog/editor` | Editor estructurado de una variante. |
| `POST` | `/admin/blog/editor/save` | Guardado atómico de metadatos, documento y revisión. |
| `GET`/`HEAD` | `/admin/blog/editor/preview` | Vista previa privada del documento guardado o de la proyección legacy. |
| `GET`/`HEAD` | `/admin/blog/editor/revisions` | Historial o detalle de una revisión. |
| `POST` | `/admin/blog/editor/restore` | Restauración mediante una revisión nueva. |

Los formularios son `application/x-www-form-urlencoded`, tienen campos
exactos, CSRF de la sesión WebAdmin y límites de bytes. El editor envía
`document_json` junto a H1, slug, title SEO, description y extracto; el JSON
admite hasta 300.000 bytes para permanecer dentro del límite global HTTP. La
autorización de la UI es solo presentación: cada escritura vuelve a validar
SID, CSRF, `auth_version`, lifecycle y capacidades dentro de la transacción
antes de bloquear la variante. Toda mutación genera auditoría sin cuerpo,
metadatos, correo, SID, CSRF ni IP.

Abrir, guardar o restaurar desde el editor requiere
`webadmin.media.view` además de la capacidad Blog correspondiente:
`blog.articles.edit` para editar y mutar, y `blog.articles.view` para previews e
historial. La subida de nuevos assets se realiza en `/admin/media` y requiere
también `webadmin.media.upload`; el editor solo selecciona medios ya
disponibles. La revalidación transaccional comprueba que cada UUID exista y
tenga variantes AVIF antes de persistir su referencia.

El listado se pagina en bloques de 50 mediante offsets canónicos y acotados;
consulta una fila adicional para saber si existe página siguiente y nunca
oculta silenciosamente las variantes posteriores.

Las vistas previas cargan la variante persistida por UUID y locale y no
representan cambios todavía sin guardar. La preview estructurada renderiza el
documento actual o, si aún no existe, su proyección legacy en memoria. Funcionan
con borradores incompletos, no necesitan origen público y nunca emiten
canonical, metadatos SEO públicos, slug ni una URL compartible. Sus respuestas
conservan `no-store`, `noindex`, CSP privada y el resto de cabeceras de
WebAdmin. Un GET o HEAD no publica, audita, adopta ni modifica la variante.

El CSS administrativo y el runtime progresivo del editor viven en
`modules/blog/published/assets` y se sincronizan como assets gestionados del
módulo hacia `public/assets/modules/blog`. El editor funciona con HTML SSR y
controles seguros; JavaScript facilita añadir, editar, mover y retirar bloques,
pero el servidor vuelve a validar el documento completo. Estos assets no son
configuración project-owned ni deben duplicarse en el bundle general del stack.
`doctor` inspecciona los `project_files` declarados por `modules/blog`, expone
el estado como `blog.assets` y añade `assets.missing_or_invalid` a los blockers
si falta un asset gestionado o su destino es inválido.

## Resolución pública y prioridad

Las rutas privadas se despachan antes del stack legacy para conservar el
aislamiento de WebAdmin. Las URLs públicas de artículo Blog se evalúan en una
segunda fase solo después de agotar:

1. la ruta estática GET o POST exacta del proyecto;
2. las rutas con query compatibles;
3. las subrutas especiales del showroom.

Por ello una URL estática del proyecto siempre gana frente a un slug Blog. La
publicación también comprueba el catálogo para impedir que el usuario publique
una variante que quedaría oculta. El path base puede ser una vista estática del
proyecto —por ejemplo `/noticias`—; el provider solo reclama descendientes con
slug válido y deja el índice al router existente. El claim previo de ese
prefijo solo lee metadatos y configuración: no construye el provider ni abre
PDO. Si la ruta del índice debe ser cacheable y no usa `$_SESSION`, puede
declarar exactamente `'session' => false`; cualquier otro valor mantiene el
bootstrap legacy antes de renderizar la vista.

Una variante `published` responde HTML en
`{public_path}/{slug}`. Un borrador o slug desconocido continúa hacia el 404
normal del proyecto. Una ruta reconocida cuyo runtime o esquema no esté listo
responde `503` genérico; una URL ajena no abre PDO. `HEAD` conserva status y
cabeceras sin cuerpo ni escrituras. Un `POST` no estático sobre una URL pública
Blog devuelve `405` con `Allow: GET, HEAD`.

Un `GET`/`HEAD` que termina en una respuesta pública Blog no inicia la sesión
PHP legacy ni crea `PHPSESSID`, por lo que tampoco sustituye las cabeceras de
caché explícitas del controlador. Si el handler no encuentra una variante y
devuelve `null`, CORE inicia la sesión antes de entrar en el 404 legacy. Las
rutas no reclamadas, POST y otros métodos conservan el orden de bootstrap
anterior.

Si la variante tiene un documento estructurado actual, la URL pública lo
renderiza con la misma semántica validada del preview. Si todavía no ha sido
adoptada, conserva el renderer legacy de `body_text`; actualizar CORE no
reescribe el artículo ni cambia por sí solo su salida. No existe fallback de
Matrix o contenido dummy en producción.

El proyecto puede componer el detalle con su shell mediante
`public_article_view`. CORE ejecuta esa vista en un buffer aislado y le entrega
exclusivamente `$blogArticle`, una instancia tipada de
`BlogPublicArticleViewModel`. Expone locale, canonical, alternates y
`x-default`, navegación localizada, title y description SEO, H1, extracto,
cuerpo HTML ya saneado, portada opcional, plantilla y fechas inmutables de
publicación/actualización. `alternateUrls()` contiene solo variantes publicadas
y alimenta `hreflang`; `languageNavigationUrls()` cubre todos los idiomas
activos y cae al índice localizado cuando el artículo aún no tiene traducción
publicada. Ambos contratos permanecen separados para no inventar alternates
SEO.
Los escalares permanecen sin escapar para que el proyecto los codifique según
su contexto; solo `bodyHtml()` se imprime como HTML prevalidado. Una excepción,
un fichero que deje de ser regular o una salida vacía fallan de forma genérica,
sin volver silenciosamente al fallback.

La vista project-owned es responsable del documento, head, assets y CSP. CORE
conserva el resto de cabeceras defensivas, pero no impone su CSP cerrada sobre
ese shell. Si no se configura vista, el HTML standalone y sus metadatos siguen
siendo el fallback compatible; enlaza el asset gestionado
`/assets/modules/blog/blog-public.css` y usa una CSP cerrada que solo amplía
`style-src 'self'` para cargarlo.

Las imágenes estructuradas se entregan como AVIF responsive desde el namespace
fijo `/_liquidstack/blog-media/{uuid}/{width}.avif`. La frontera pública solo
sirve una variante si el asset está referenciado por el documento actual de un
artículo publicado; referencias de borradores o revisiones no bastan. Los bytes
y hashes se verifican contra el storage privado y cualquier ausencia,
corrupción, petición malformada o referencia no publicable responde como `404`
sin revelar la causa. Este namespace se declara como prefijo pre-bootstrap: sus
respuestas válidas y sus 404 uniformes evitan tanto la redirección multidioma
legacy como la creación de sesión, también en `HEAD`.

Las vistas project-owned obtienen filtros y cards por categoría mediante
`BlogCategoryPublicFeedFactory`. La frontera valida `0001+0003` sin depender
de las capacidades administrativas de `0004`, ejecuta consultas acotadas sin
N+1 y devuelve exclusivamente arrays
de presentación: locale, slug, nombre y contador para filtros; y locale, slug,
URL, H1, extracto y fechas para cards. PDO, prefijos, IDs numéricos y UUIDs no
cruzan hacia los recursos.

## SEO técnico de artículos públicos

Cada variante publicada renderiza en servidor su `title`, meta description,
`robots=index,follow` y canonical absoluto. También incluye Open Graph de tipo
`article`, Twitter Card y un conjunto completo de `hreflang` formado
exclusivamente por las variantes publicadas del mismo post. El conjunto
incluye la URL actual. `x-default` apunta al idioma principal declarado por el
orden de `App/config/langs.php` cuando esa variante está publicada; si no lo
está, usa la primera variante publicada según el orden de `public_paths`. El
orden de ese array no redefine por accidente el idioma principal mientras su
variante predeterminada sí esté publicada.

Las URLs se construyen siempre desde `RAIZ` y desde el base path configurado
para cada locale. Una variante en borrador o retirada no aparece ni como
canonical alternativa ni como `hreflang`. Las plantillas legacy y
`article-basic-01` usan una Twitter Card `summary` y no inventan una portada.
Solo `article-cover-01`, cuyo contrato exige una imagen `cover` como primer
bloque, publica `og:image`, `twitter:image` y `summary_large_image`; la URL
corresponde a la variante AVIF mayor que el resolver público autoriza para el
documento actual publicado.

## Sitemap dinámico

El endpoint configurado, `/blog-sitemap.xml` por defecto, consulta únicamente
variantes publicadas y construye URLs desde `RAIZ`: HTTPS fuera del laboratorio
y HTTP solo bajo el perfil loopback tipado de desarrollo. Nunca usa `Host`,
`Forwarded` o cabeceras del cliente como origen. No modifica
`public/sitemap.xml`, el repositorio ni el deploy. Publicar o retirar cambia su
respuesta inmediatamente porque la DB de producción es la fuente de verdad.
Como endpoint público exacto de infraestructura, se despacha antes del resolver
multidioma y de la sesión legacy, por lo que una respuesta modular no crea
`PHPSESSID`. Antes de reclamarlo, CORE descarta una ruta GET exacta, un fichero
o symlink público y una subruta showroom pertenecientes al proyecto. Si el
catálogo GET no puede inspeccionarse de forma completa, declina la fase
pre-bootstrap y recupera la resolución normal con prioridad project-owned. Esta
excepción no adelanta las URLs de artículo, aunque una respuesta pública tardía
también permanece sin sesión.
El documento admite como máximo 50.000 URLs: la consulta obtiene hasta 50.001
candidatas para detectar el desbordamiento y responder con un fallo genérico,
sin cargar un conjunto ilimitado ni truncarlo silenciosamente.
El XML declara el namespace XHTML. Cada URL contiene los alternates de todas
las variantes publicadas del mismo post y el mismo `x-default` que su HTML.
Las equivalencias se agrupan por el UUID estable del post, nunca por posición,
título o parecido entre slugs.

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
# Crear y verificar aquí un backup recuperable de DB y storage.
composer liquidstack:migrate --apply
composer liquidstack:webadmin:bootstrap
composer liquidstack:doctor
```

`--plan` permanece offline y `--dry-run` solo lee. `--apply` no forma parte del
flujo por defecto: se ejecuta únicamente después de revisar el plan, crear y
comprobar un backup recuperable de DB y storage, y autorizar expresamente la
mutación.

El bootstrap es idempotente y debe repetirse después de añadir Blog a una
instalación WebAdmin existente: así las cuentas protegidas reciben las nuevas
capacidades sin reactivar, duplicar ni sustituir usuarios. Ninguno de estos
pasos se ejecuta desde `composer update`.

La resolución pública base exige `0001`; la administración de artículos suma
`0002`. La proyección pública de categorías exige `0001+0003` y su
administración `0001+0002+0003+0004`. El documento estructurado y sus
revisiones requieren `0001+0003+0005`; su selección de imágenes necesita
además `0002_webadmin_media_library` en el scope WebAdmin. Una migración
compuesta registrada solo retira la postcondición anterior mientras su propio
contrato completo continúe siendo válido.

Cambiar un proyecto con artículos o identidades existentes desde `shared` a
`liquidstack` no traslada ni adopta datos. Requiere una migración y verificación
manual de ambos namespaces y del registro `ls_module_migrations`, además de un
backup previo. El perfil dedicado inicial solo debe usarse en `localhost` o
una red confiable; el acceso a hosts no confiables queda pendiente de un
contrato TLS con CA y verificación del servidor.

El mismo código sirve en local, staging y producción. El cambio de entorno se
realiza mediante `RAIZ`, `DEV_MODE`, `LIQUIDSTACK_DB_*` y la raíz de storage
declarada fuera de Git; no requiere modificar controladores. Cambiar esos
valores selecciona otro destino, pero nunca copia ni sincroniza sus datos.
Composer se limita a distribuir código y assets gestionados: jamás toca DB,
`.env`, configuración modular ni `storage`.

`blog_ready` exige selector, configuración, idiomas, esquema y capacidades
aplicados, WebAdmin operativo, rutas públicas válidas y sitemap libre. El
diagnóstico no revela prefijos efectivos, slugs, contenido, correos, SQL ni
mensajes PDO.

## Fuera del contrato actual

Quedan expresamente para cortes posteriores:

- etiquetas, buscador reactivo completo, archivos, relacionados, RSS y
  comentarios;
- reproducción Lite YouTube inline gobernada por consentimiento social de
  CookieLAD; el enlace externo accesible continúa siendo el fallback sin
  consentimiento ni JavaScript;
- recursos visuales Blog `module-owned`, sincronizados únicamente cuando el
  selector `liquidstack/blog` esté activo;
- proyección pública compartida que evite conexiones PDO redundantes en índices
  que combinan filtros, categorías y cards;
- crop, focal point, vídeo local, audio, reemplazo y garbage collection de
  medios;
- nuevas plantillas editoriales y el maquetador libre de secciones, filas y
  columnas;
- workflow de aprobación, programación y borrado;
- redirecciones automáticas por cambio de slug;
- traducción mediante IA y generación automática de variantes;
- medidor SEO avanzado, Search Console e Indexing API;
- localización independiente de la interfaz WebAdmin;
- edición HTML avanzada, saneada, auditable, restaurable y limitada a una
  capacidad específica;
- ampliación del catálogo de recursos Blog con sliders, relacionados y otras
  composiciones dinámicas.

El documento JSON v1, sus revisiones, el agregado, las variantes, permisos,
URLs y estados forman la base estable sobre la que se añadirán esas
capacidades.
