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
| `0006_blog_sitemap_publication_state` | Revisión pública y generación de la caché LKG del sitemap. |
| `0007_blog_post_tombstones` | Papelera recuperable de variantes. |
| `0008_blog_article_delete_capability` | Capacidad delegable de papelera. |
| `0009_blog_analytics` | Sesiones y vistas pseudónimas, propias y consentidas. |
| `0010_blog_analytics_view_capability` | Capacidad delegable para consultar métricas. |

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
    'sitemap_cache' => [
        'enabled' => false,
        'ttl_seconds' => 300,
    ],
    'analytics' => [
        'enabled' => false,
        'retention_days' => 90,
        'session_timeout_seconds' => 1800,
        'collect_in_dev' => false,
    ],
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

Los únicos estados son `draft` y `published`. Publicar exige slug, H1, title,
description, extracto y cuerpo válidos. Retirar conserva contenido, URL y
auditoría y vuelve a `draft`. La papelera de `0007` es recuperable y solo
admite borradores: una variante publicada debe retirarse expresamente antes;
forzar el POST produce conflicto. No existe purga editorial ni borrado permanente. La
papelera conserva documento, referencias, categorías, slug y locale, pero
excluye la variante de listados, cargas editoriales y consultas públicas hasta
su restauración. Enviar o restaurar incrementa `lock_version`.

Duplicar crea siempre un agregado y una variante `draft` independientes, con
lock `1`, slug nulo y H1 `Copia de {H1}` truncado de forma segura en UTF-8. El
title SEO permanece; si existen, se copian el documento actual, sus referencias
y las categorías, se revalidan los medios y se inicia un historial nuevo con
una revisión inicial. No se copia el historial anterior ni se publica la copia.

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
- encabezado H2-H6, sin saltos de jerarquía: cada nivel desde H3 exige su
  padre inmediato activo;
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
| `blog.articles.delete` | Enviar borradores a la papelera y restaurarlos. |
| `blog.categories.view` | Consultar categorías localizadas. |
| `blog.categories.edit` | Crear, traducir, editar y asignar categorías. |
| `blog.analytics.view` | Consultar las métricas propias del Blog. |

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
| `GET`/`HEAD` | `/admin/blog/trash` | Papelera paginada de borradores. |
| `GET`/`HEAD` | `/admin/blog/posts/new` | Alta de artículo o idioma. |
| `POST` | `/admin/blog/posts/create` | Crear variante. |
| `GET`/`HEAD` | `/admin/blog/posts/edit` | Formulario por UUID y locale. |
| `GET`/`HEAD` | `/admin/blog/posts/preview` | Vista previa privada de la versión guardada. |
| `POST` | `/admin/blog/posts/save` | Guardar con versión optimista. |
| `POST` | `/admin/blog/posts/publish` | Publicar una variante completa. |
| `POST` | `/admin/blog/posts/unpublish` | Retirar una variante. |
| `POST` | `/admin/blog/posts/duplicate` | Duplicar una variante como borrador independiente. |
| `POST` | `/admin/blog/posts/trash` | Enviar un borrador a la papelera. |
| `POST` | `/admin/blog/posts/restore` | Restaurar un borrador de la papelera. |
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
| `POST` | `/admin/blog/editor/seo-analysis` | Análisis SEO editorial advisory del payload actual, sin persistencia. |
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

Duplicar revalida `blog.articles.edit`, `blog.categories.edit` y
`webadmin.media.view` dentro de la misma transacción porque conserva las
asignaciones de categoría. Papelera y restauración revalidan
`blog.articles.view` y `blog.articles.delete`. Las tres operaciones exigen el
`lock_version` recibido, bloquean antes de copiar o cambiar visibilidad y
revocan todos sus cambios si falla la auditoría. Duplicar sigue disponible en
el corte anterior a `0007`; la UI y las rutas de papelera permanecen ocultas y
responden `404` hasta que el gate de tombstones esté listo.

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

El editor integra además el primer corte del medidor SEO editorial. El panel
SSR analiza el estado guardado y el runtime progresivo actualiza los avisos con
debounce y cancelación de la petición anterior. Es determinista, no asigna una
puntuación y nunca bloquea guardar o publicar. Su contrato, el inventario
canónico estático opcional y sus límites se detallan en
[Medidor SEO editorial del Blog](blog-seo-editorial.md).
`doctor` inspecciona únicamente los assets runtime module-owned declarados bajo
`public/assets/modules/blog`, `src/js/modules/blog` o
`src/scss/modules/blog`. Un target de directorio se expande desde su fuente a
cada fichero exacto —actualmente los cuatro bundles publicados— antes de
comprobar el proyecto. El estado se expone como `blog.assets` y añade
`assets.missing_or_invalid` a los blockers si falta uno de esos ficheros o su
destino es inválido. Los recursos visuales estándar y hooks de showroom son
selectivos: su ausencia legítima no rebaja por sí sola la disponibilidad del
runtime Blog.

## Shell y edición visual

Las pantallas administrativas de gestión de Blog reutilizan el shell
module-owned de WebAdmin: navegación lateral filtrada por capacidades, un único `main` y un
inspector derecho opcional. El layout ocupa el ancho disponible y no obliga al
contenido editorial a una columna estrecha. El HTML SSR deja navegación,
herramientas y formularios en el flujo; solo después de enlazar `webadmin.js`
los controles pasan a manejar drawers. En ese estado el runtime mantiene
`aria-expanded`, `aria-hidden`, foco e `inert`, admite Escape y restaura el foco
al control de origen. Sin JavaScript no quedan superficies ocultas ni botones
sin función. La preview privada conserva un documento aislado para representar
su `header` y `main`, pero sigue autenticada y responde `no-store` y `noindex`.

La jerarquía visual administrativa es plana. Se construye con espacio,
tipografía, grid, fondos sobrios y separadores funcionales; no usa por defecto
bordes laterales de acento, franjas mediante pseudoelementos, rebordes
decorativos ni cadenas de tarjetas anidadas. Los bordes permanecen reservados
para controles, foco, tablas, separadores y estados seleccionados.

El editor representa en directo la futura composición pública sin introducir
un segundo `main` ni un segundo H1 en el documento de WebAdmin. Su lienzo neutral
expone conceptualmente un `header` con H1 y medio destacado seguido del `main`:
el primer bloque editorial nuevo debe ser H2, cada H2 abre una `section`, cada
H3 abre un `article` y H4-H6 permanecen dentro de ese artículo. No se permiten
saltos de nivel. Mover o retirar un encabezado mueve o retira también todo el
subárbol que depende de él, por lo que el orden visual y el SSR público conservan
la misma semántica.

Al crear un artículo o añadirle una traducción, el formulario exige seleccionar
de manera explícita un locale activo que ese agregado aún no utilice y muestra
la ruta asociada en `public_paths`. Una variante existente conserva su locale
como identidad inmutable. Tanto la URL de edición como la pública usan ese
locale y `{public_path}/{slug}`; nunca se inventa `/es`, `/eu` u otro prefijo por
convención. La traducción asistida mediante IA sigue fuera de este corte: una
acción futura podrá crear otra variante, pero cada idioma mantendrá slug,
metadatos, documento, revisiones y publicación independientes.

El inspector reúne metadatos, bloque activo, SEO, categorías y acceso a medios.
Las categorías asignables pertenecen al mismo locale y su guardado asíncrono
mantiene el formulario POST nativo como fallback. El catálogo Media presenta el
tramo reciente y añade todos los assets referenciados por el documento actual,
aunque sean más antiguos, para que una edición nunca pierda la posibilidad de
resolver o conservar una imagen ya usada. Subir sigue siendo responsabilidad
de `/admin/media` y de `webadmin.media.upload`.

El formulario completo continúa siendo la fuente de verdad. La mejora
progresiva sincroniza el documento canónico y guarda con `fetch`, pero solo
considera éxito una redirección al mismo origen y al editor exacto del mismo
post y locale. Un `409`, `422`, una respuesta de autenticación inesperada o un
fallo de red conservan en pantalla todos los campos y bloques y anuncian un
error genérico sin navegar. Mientras el formulario difiera de su huella inicial,
`beforeunload` protege frente a una salida accidental; tras un éxito confirmado
se actualiza la huella y se permite la navegación.

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
renderiza con la misma semántica validada del preview. La lista plana conserva
como contenido raíz cualquier introducción anterior al primer H2; cada H2 abre
una `section`, cada H3 abre un `article` dentro de la sección activa y H4-H6
permanecen dentro de ese artículo. El contenido posterior a un H2 pertenece a
su sección y el posterior a un H3 a su artículo hasta que otro encabezado cierre
ese ámbito. Si todavía no ha sido
adoptada, conserva el renderer legacy de `body_text`; actualizar CORE no
reescribe el artículo ni cambia por sí solo su salida. No existe fallback de
Matrix o contenido dummy en producción.

El proyecto puede componer el detalle con su shell mediante
`public_article_view`. CORE ejecuta esa vista en un buffer aislado y le entrega
exclusivamente `$blogArticle`, una instancia tipada de
`BlogPublicArticleViewModel`. Expone locale, canonical, alternates y
`x-default`, navegación localizada, title y description SEO, H1, extracto,
y tres proyecciones HTML ya saneadas: `bodyHtml()` conserva el cuerpo histórico
completo, incluida la portada; las vistas nuevas deben componer
`headerMediaHtml()` con `mainHtml()` para situar la portada una sola vez fuera
del contenido principal. También expone la URL de portada opcional, plantilla
y fechas inmutables de publicación/actualización. `alternateUrls()` contiene
solo variantes publicadas
y alimenta `hreflang`; `languageNavigationUrls()` cubre todos los idiomas
activos y cae al índice localizado cuando el artículo aún no tiene traducción
publicada. Ambos contratos permanecen separados para no inventar alternates
SEO.
Los escalares permanecen sin escapar para que el proyecto los codifique según
su contexto; solo `bodyHtml()`, `mainHtml()` y `headerMediaHtml()` se imprimen
como HTML prevalidado. Una excepción,
un fichero que deje de ser regular o una salida vacía fallan de forma genérica,
sin volver silenciosamente al fallback.

La vista project-owned es responsable del documento, head, assets y CSP. CORE
conserva el resto de cabeceras defensivas, pero no impone su CSP cerrada sobre
ese shell. Si no se configura vista, el HTML standalone y sus metadatos siguen
siendo el fallback compatible; enlaza el asset gestionado
`/assets/modules/blog/blog-public.css` y el runtime gestionado
`/assets/modules/blog/blog-public.js`. El CSS fallback mantiene tipografía
explícita hasta H6 y espaciado responsive para las `section` y `article`
proyectadas, sin convertirlas en tarjetas ni añadir decoración estructural.
Su CSP permite exclusivamente estilos y scripts del mismo origen y frames de
`youtube-nocookie.com`.

Si el shell reutiliza la navegación global del proyecto, debe enlazar cada
idioma al valor publicado de `languageNavigationUrls()` y activar
`bindLanguageNavigation(window, document)` desde el helper gestionado
`src/js/resources/_languagePreference.mjs`. Este binding intercepta en captura
el traductor SPA legacy, persiste `cookie_custom_lang` solo con consentimiento
CookieLAD y realiza una navegación normal al `href` localizado. No consulta
`/languages`, por lo que no abre la sesión legacy ni crea `PHPSESSID`; los
clics modificados, `target` y `download` conservan el comportamiento nativo. Su
función de limpieza debe ejecutarse en HMR o al desmontar el entrypoint.

Los bloques YouTube conservan siempre un enlace externo accesible como
contenido SSR y fallback cuando JavaScript no está disponible o no existe
consentimiento social. El runtime module-owned
`/assets/modules/blog/blog-public.js` solo intercepta un clic primario sin
modificadores cuando `cookie_social=true`; valida de nuevo el ID y el segundo de
inicio y crea entonces un iframe de `youtube-nocookie.com`. No solicita
miniaturas ni ningún otro recurso de Google o YouTube antes de ese clic. Un
cambio de consentimiento, el retorno de foco o la restauración de la página
vuelven a leer la cookie y retiran inmediatamente cualquier iframe cuando el
permiso deja de existir. El runtime usa listeners abortables, admite varias
instancias y destruye una instalación anterior antes de reinicializarse.

El fallback standalone limita su CSP a `script-src 'self'` y
`frame-src https://www.youtube-nocookie.com`. Un `public_article_view` sigue
siendo propietario de sus assets y CSP: debe cargar el mismo script gestionado
con el nonce de su shell y permitir exclusivamente ese origen en `frame-src`.
Ampliar la directiva no carga contenido ni sustituye el gate de CookieLAD; el
iframe continúa dependiendo a la vez del consentimiento y de una acción del
usuario.

## Analítica propia y consentida

La analítica Blog es un opt-in doble: `analytics.enabled=true` debe tener las
migraciones `0009` y `0010` verificadas, y la vista pública debe emitir el
marcador explícito solo cuando `BlogPublicArticleViewModel::analyticsEnabled()`
sea verdadero. El fallback standalone lo hace automáticamente. Una vista
`public_article_view` debe trasladar además los límites tipados, por ejemplo en
su elemento `html`:

```php
<?php if ($blogArticle->analyticsEnabled()): ?>
data-blog-analytics-enabled="true"
data-blog-analytics-retention-days="<?= $blogArticle->analyticsRetentionDays() ?>"
data-blog-analytics-session-timeout="<?= $blogArticle->analyticsSessionTimeoutSeconds() ?>"
data-blog-analytics-page-grant="<?= htmlspecialchars(
    (string) $blogArticle->analyticsPageGrant(),
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
) ?>"
<?php endif; ?>
```

`blog-public.js` solo carga el asset separado `blog-analytics.js` cuando existe
ese marcador y CookieLAD declara `cookie_analytics=true`. Sin cualquiera de los
dos no abre endpoints y elimina identificadores antiguos. La revocación durante
una visita elimina las cookies first-party y llama al endpoint exacto de
revocación. Los tres POST viven bajo `/_liquidstack/blog-analytics`, se
despachan antes del bootstrap legacy y no crean `PHPSESSID`. Una vista
project-owned debe permitir `connect-src 'self'`; el fallback ya lo declara.

El `page_grant` es una capacidad HMAC efímera emitida en el render SSR. Ata
origen, ruta canónica, localización y un UUID de vista generado en servidor;
el cliente no elige ninguno de esos datos. Su replay solo puede representar la
misma vista por el índice único y una firma manipulada o expirada se rechaza
antes de abrir PDO. Cuando se emite, la respuesta del artículo usa
`Cache-Control: private, no-store` para que un proxy no comparta la capacidad.
No debe viajar en query strings, logs ni herramientas de diagnóstico.

La identidad es un UUID aleatorio first-party pseudonimizado con HMAC en
servidor. No se leen ni persisten IP, `User-Agent`, referrer, correo o sesión
WebAdmin; la mera presencia de la cookie administrativa excluye esa visita.
El tiempo solo avanza con la página visible y enfocada. Las métricas propias
son: páginas vistas, visitantes pseudónimos únicos, visitantes recurrentes,
tiempo medio activo, sesiones de entrada y rebote. Una entrada se considera
engaged cuando supera 10 segundos activos o la sesión alcanza dos páginas; el
rebote es el complemento porcentual. Son definiciones operativas compatibles
con la lectura habitual de GA4, no una réplica ni una importación de Google.

`retention_days` se aplica con el comando destructivo, one-shot y explícito:

```powershell
composer liquidstack:blog:analytics:purge --yes
```

El comando elimina primero las sesiones vencidas que no contienen actividad
reciente y sus vistas por cascada. Después elimina cualquier vista vencida que
pertenezca a una sesión aún activa. Así, `retention_days` se aplica a cada
registro sin sacrificar las vistas recientes de una sesión larga. No se ejecuta
desde Composer update ni desde una petición pública. Cada proyecto productivo
deberá programarlo mediante su scheduler o cron. CORE entrega el comando y su
salida JSON, pero no instala el cron; esa
adopción operativa queda pendiente hasta configurar el servidor concreto.

Las imágenes estructuradas se entregan como AVIF responsive desde el namespace
fijo `/_liquidstack/blog-media/{uuid}/{width}.avif`. La frontera pública solo
sirve una variante si el asset está referenciado por el documento actual de un
artículo publicado; referencias de borradores o revisiones no bastan. Los bytes
y hashes se verifican contra el storage privado y cualquier ausencia,
corrupción, petición malformada o referencia no publicable responde como `404`
sin revelar la causa. Este namespace se declara como prefijo pre-bootstrap: sus
respuestas válidas y sus 404 uniformes evitan tanto la redirección multidioma
legacy como la creación de sesión, también en `HEAD`.

El renderer mantiene `loading="lazy"` para imágenes de contenido y anchas. La
imagen `cover` de `article-cover-01` es la única excepción: el esquema garantiza
que sea el primer bloque y se emite con `loading="eager"` y
`fetchpriority="high"`, porque el shell puede convertirla en su pieza LCP sin
duplicarla ni introducir otra fuente de verdad.

Las vistas project-owned pueden obtener cards generales, filtros y cards por
categoría desde una única instancia creada por `BlogPublicFeedFactory`. Ese
feed comparte el mismo runtime y la misma conexión PDO durante la petición.
`BlogCategoryPublicFeedFactory` permanece como adaptador compatible para
consumidores anteriores. La proyección de categorías valida `0001+0003` sin
depender de las capacidades administrativas de `0004`, ejecuta consultas
acotadas sin N+1 y devuelve como máximo 100 filtros. La consulta lee 101 para
detectar el desbordamiento y falla cerrada en lugar de truncar silenciosamente
el catálogo. La proyección devuelve exclusivamente arrays
de presentación: locale, slug, nombre y contador para filtros; y locale, slug,
URL, H1, extracto y fechas para cards. PDO, prefijos, IDs numéricos y UUIDs no
cruzan hacia los recursos.

La vista consumidora construye un `BlogPublicCatalogQuery` acotado y lo entrega
a `BlogPublicFeed::cardsForQuery()`. El query valida hasta 480 bytes de entrada,
normaliza los espacios y admite búsquedas no vacías de 2 a 120 caracteres
Unicode; acepta hasta diez slugs de categoría, modo allowlisted `any|all`, un
máximo de 50 filas, offset público de hasta 10.000 y exclusión opcional. El
repositorio aplica todos los filtros en una consulta preparada. Cuando una
vista necesita detectar la página siguiente, solicita expresamente una fila
adicional dentro de ese límite y no la expone como card. SQLite registra una
función determinista de
casefold Unicode una sola vez por conexión; MySQL conserva su conversión
Unicode nativa. El formulario SSR sigue siendo
funcional sin JavaScript y `moduleBlogFilters01` mejora progresivamente el
mismo GET mediante `fetch`, cancelación de peticiones e historial del
navegador. La mejora conserva la paridad del GET nativo, invalida respuestas
obsoletas durante el debounce, usa una única entrada reemplazable para cada
secuencia de búsqueda viva y sincroniza `title`, robots y canonical con el SSR
recibido.

La proyección puede devolver hasta 100 categorías para otros consumidores,
pero `moduleBlogFilters01` muestra como máximo diez controles: ante un enlace
con filtros válidos, sitúa primero las categorías seleccionadas y completa el
resto en el orden del catálogo. Así el formulario SSR nunca ofrece más
selecciones simultáneas de las que acepta el query ni construye URLs extremas.

El índice project-owned puede reducir el cuerpo de esas peticiones cuando
recibe el header exacto `X-LiquidStack-Partial: blog-results`. La respuesta
continúa siendo HTML SSR e incluye el mismo formulario, `#blog-results`,
`title`, robots y canonical; no introduce un endpoint JSON ni una segunda
fuente de verdad. El proyecto debe enviar `Vary: X-LiquidStack-Partial` y
conservar siempre el documento completo para navegación normal, JavaScript
desactivado y cualquier cliente que no solicite la variante parcial.

El mismo feed ofrece consultas tipadas y acotadas de descubrimiento. Los
relacionados parten de una variante publicada y ordenan candidatos del mismo
idioma por número de categorías localizadas compartidas, fecha y UUID estable,
excluyendo el artículo de origen. El archivo consulta un año o mes UTC con
límite y offset defensivos; su proyección de periodos agrega año, mes y recuento
sin exponer IDs. `BlogPublicArticleViewModel::relatedArticles()` transporta las
cards ya proyectadas al shell público. Todas estas lecturas conservan el mismo
PDO y solo incluyen variantes publicadas con slug, extracto y fecha válidos.

### Recursos visuales y showroom selectivos

El manifiesto de Blog declara una allowlist `resources` y publica la familia
visual únicamente cuando el selector `liquidstack/blog` está activo:

- `moduleBlogFilters01`, formulario GET funcional sin JavaScript y mejora
  progresiva abortable para búsqueda, categorías `any|all` e historial;
- `artBlogArticle01`, composición semántica del artículo para las plantillas
  básica y con portada, con cuerpo saneado por CORE e intro/retorno inyectables;
- `moduleBlogArchive01`, navegación por periodos con recuentos y estado actual;
- `sectionBlogGrid01`, rejilla de cards;
- `sectionBlogList01`, listado editorial;
- `sectionBlogFeatured01`, entrada destacada con secundarias;
- `sectionBlogRelated01`, cards relacionadas por categorías compartidas;
- `sectionBlogSlider01`, carrusel horizontal accesible y responsive.

Los recursos reciben arrays de presentación; nunca PDO, prefijos, IDs internos
ni secretos. Su fuente canónica replica la estructura del consumidor bajo
`modules/blog/resources/project/`. Controlador, template, SCSS y JS de cada
recurso comparten un grupo gestionado, mientras helper y hooks de showroom
conservan grupos propios. Así una personalización local bloquea únicamente su
unidad coherente y no toda la familia.

`artBlogArticle01` deduce el rango de su encabezado externo mediante el mismo
contrato relacional del stack y desplaza en consecuencia los H2-H6 saneados
del documento. Sus placeholders estructurales son reservados: solo
`article_data.body_html` puede aportar el cuerpo confiable y el fragmento
opcional `article_data.header_media_html` acepta exclusivamente la proyección
saneada de cabecera. En una vista nueva se alimentan respectivamente con
`mainHtml()` y `headerMediaHtml()`; omitir el segundo mantiene compatible la
composición histórica basada en `bodyHtml()`. Los relacionados
usan tres cards por defecto y centran cualquier última fila incompleta; el
archivo expone el periodo activo con `aria-current="date"`.

El grupo independiente `resource-support` no rebaja ese aislamiento: su helper
es una API de presentación común y estable. Las funciones
`liquidstack_blog_resource_context()`,
`liquidstack_blog_resource_escape()`, `liquidstack_blog_resource_card()` y
`liquidstack_blog_resource_heading()`, sus firmas y las claves ya devueltas por
el contexto solo pueden evolucionar de forma aditiva. Un cambio incompatible
exige un helper versionado o una migración coordinada de toda la familia; no se
publica silenciosamente bajo el mismo contrato.

La familia visual requiere el contrato SCSS estándar del consumidor. Si CORE
no puede leer, completar o verificar `src/scss/_config.scss`, ese ciclo publica
los assets autocontenidos del módulo bajo `public/assets/modules/blog`,
`src/js/modules/blog` y `src/scss/modules/blog`, pero retiene conjuntamente
controladores, templates, SCSS, JS y hooks de showroom estándar. Una
actualización posterior con el contrato reparado instala la familia completa.

El showroom usa cuatro fixtures Matrix localizados y no inserta ni ofrece ese
copy como fallback en la DB pública. Las claves legacy de `templates` se
mantienen aditivamente durante la transición. Un stack sin Blog no recibe los
controladores, templates, estilos, runtimes ni hooks de esta categoría.

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
El documento admite como máximo 50.000 URLs y 50 MiB sin comprimir: la consulta
obtiene hasta 50.001 candidatas para detectar el desbordamiento y el renderer
verifica el tamaño final antes de responder. Cualquiera de los dos excesos
produce un fallo genérico, sin truncar silenciosamente.
El XML declara el namespace XHTML. Cada URL contiene los alternates de todas
las variantes publicadas del mismo post y el mismo `x-default` que su HTML.
Las equivalencias se agrupan por el UUID estable del post, nunca por posición,
título o parecido entre slugs.

Una respuesta válida incluye un `ETag` fuerte derivado de los bytes exactos del
XML y `Cache-Control: public, no-cache, must-revalidate`. `GET` y `HEAD`
atienden `If-None-Match`, incluida la comparación débil permitida para lecturas,
y devuelven `304` sin cuerpo cuando coincide. No se usa `Last-Modified` como
validador global: el máximo `updated_at` puede retroceder al retirar la URL más
reciente.

La caché persistente last-known-good es opcional y está desactivada por
defecto. Cuando el proyecto la activa, `0006_blog_sitemap_publication_state`
mantiene una revisión pública monótona y una generación de storage. Publicar o
retirar escribe un fence durable antes de cambiar la visibilidad y actualiza la
revisión en la misma transacción. El snapshot solo se promueve después de
comprobar de nuevo revisión y generación bajo locks coordinados.

El fallback se admite exclusivamente ante
`database.connection_unavailable`, con snapshot íntegro, vigente y de la misma
identidad. La respuesta lo declara mediante
`X-LiquidStack-Sitemap-Source: stale-cache` y `Warning: 110`; cualquier error
de esquema, configuración, consulta no clasificada, render, overflow o storage
falla cerrado. El contrato y runbook completos están en
[Caché last-known-good del sitemap](blog-sitemap-last-known-good-cache.md).

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
# Solo si sitemap_cache.enabled=true:
composer liquidstack:blog:sitemap-cache:init
# Si analytics.enabled=true, ejecutar periódicamente (cron externo):
composer liquidstack:blog:analytics:purge --yes
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
además `0002_webadmin_media_library` en el scope WebAdmin. La caché LKG exige
el prefijo completo `0001`–`0006`, incluidas las migraciones intercaladas, y su
inicialización explícita. La colección analítica requiere `0001+0009`; su
consulta privada suma `0002+0010` y la capacidad `blog.analytics.view`. Una
migración compuesta
registrada solo retira la postcondición anterior mientras su propio contrato
completo continúe siendo válido.

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

- etiquetas, RSS y comentarios; relacionados y archivo ya forman parte del
  feed público y de la familia base de recursos;
- crop, focal point, vídeo local, audio, reemplazo y garbage collection de
  medios;
- nuevas plantillas editoriales y el maquetador libre de secciones, filas y
  columnas;
- workflow de aprobación, programación y borrado;
- redirecciones automáticas por cambio de slug;
- traducción mediante IA y generación automática de variantes;
- ampliaciones del medidor SEO, Search Console e Indexing API;
- suscripciones y notificaciones al publicar: requieren consentimiento,
  campañas, un outbox por lotes y un scheduler futuro según el
  [contrato pendiente](mejoras-pendientes/blog-notificaciones-suscriptores.md);
- localización independiente de la interfaz WebAdmin;
- edición HTML avanzada, saneada, auditable, restaurable y limitada a una
  capacidad específica;
- ampliación del catálogo de recursos Blog con nuevas composiciones dinámicas
  posteriores a `sectionBlogRelated01` y `moduleBlogArchive01`.

El documento JSON v1, sus revisiones, el agregado, las variantes, permisos,
URLs y estados forman la base estable sobre la que se añadirán esas
capacidades.
