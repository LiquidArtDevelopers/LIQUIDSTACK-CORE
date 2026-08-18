# Liquid Blog: contrato operativo

Liquid Blog es un módulo interno de `liquidstack/core`. Se activa mediante el
selector lógico `liquidstack/blog`, que activa también WebAdmin. No es un
paquete físico independiente y nunca ejecuta migraciones durante
`composer install` o `composer update`.

La distribución base mantiene `ext-dom` como sugerencia para no bloquear
instalaciones CORE-only o WebAdmin-only. Activar Blog sí exige esa extensión:
el editor y el renderer estructurado la usan para sanear HTML y proyectar
iframes inertes. `liquidstack:doctor` informa su disponibilidad en
`runtime.dom_extension` y bloquea únicamente Blog con el código estable
`runtime.dom_extension_missing` cuando falta.

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
| `0011_blog_layout_editor_v2` | Contrato aditivo del documento semántico V2 y su persistencia complementaria. |
| `0012_blog_editor_preferences` | Preferencias globales opcionales y versionadas del editor; no crea una fila por defecto. |
| `0013_blog_settings_manage_capability` | Capacidad protegida y no delegable para administrar esos defaults. |
| `0014_blog_private_draft_publication` | Workspaces editoriales privados, CAS global de categorías por post y cabeceras de publicación sobre revisiones inmutables. |
| `0015_blog_robots_preferences` | Preferencias `index`/`follow` por variante y copia inmutable asociada a cada revisión estructurada. |
| `0016_blog_url_history` | Historial persistente de URL pública con estados activo, 404 temporal, 410 y 301 explícito. |
| `0017_blog_dummy_category` | Identidad canónica de la categoría interna Dummy y exclusión privada/pública fail-closed. |
| `0018_blog_dummy_category_normalization` | Normalización aditiva de asignaciones live y workspace ligadas al slug legacy exacto `dummy`. |
| `0019_blog_copy_operation_idempotency` | Registro transaccional de intenciones para hacer idempotentes la duplicación y el alta de locale. |

Cada frontera tiene su propio gate de disponibilidad. Una migración nueva
pendiente no autoriza a CORE a completar tablas por intuición. Cuando una
migración declara explícitamente una frontera normalizada y supersede la
postcondición anterior —como `0019`—, el runtime exige ese prefijo coherente y
falla cerrado hasta aplicarlo; fuera de ese caso, una frontera opcional pendiente
no debe inutilizar funciones anteriores cuyo contrato siga verificado.

`0016` hace backfill únicamente de las variantes publicadas. No adivina si un
borrador antiguo llegó a publicarse y nunca se aplica desde HTTP o Composer.

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
    'public_index' => [
        'page_size' => 12,
        'pagination_paths' => [
            'es' => '/es/noticias/pagina/{page}',
            'eu' => '/eu/albisteak/orria/{page}',
            'en' => '/en/news/page/{page}',
        ],
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
    'preview_asset_adapter' =>
        'App/config/modules/blog-preview-assets.php',
    'database' => [
        'connection' => 'liquidstack',
        'table_prefix' => 'ls_blog_',
    ],
];
```

`public_index.page_size` admite de 1 a 49 cards y reserva internamente una fila
de lookahead. Cada locale público debe tener una plantilla de paginación con
un único segmento `{page}`; el proyecto declara la ruta dinámica equivalente
en `App/config/routes/get.php`. Las rutas limpias sin filtros pueden ser
self-canonical; búsqueda, categorías, orden y archivo siguen siendo estados
`noindex, follow` con canonical al índice base.

`public_article_view` es opcional. Cuando se declara, debe ser una ruta PHP
relativa a la raíz del proyecto bajo `App/views/`; el fichero y todos sus
directorios deben existir, ser legibles y no contener symlinks. Una ruta
absoluta, un traversal, una ruta fuera de `App/views` o un fichero ausente
bloquean Blog de forma cerrada. Omitir la clave conserva el renderer standalone
compatible y no exige crear una vista en proyectos existentes.

La política de seguridad de todos los shells públicos project-owned se declara,
solo cuando el proyecto necesita ampliar los defaults, en
`App/config/modules/blog-public.php`. Puede aportar `security_sources` con
orígenes exactos para `script`, `style`, `image`, `font`, `connect` y `frame`, o
una `security_policy` que implemente el contrato tipado, pero no ambos. El
controlador crea un contexto por respuesta, aplica sus cabeceras a la
`Response` y entrega el mismo nonce al shell. Composer no crea ni sobrescribe
este fichero.

`preview_asset_adapter` también es opcional y solo afecta al documento SSR
privado que se incrusta en el `iframe` del editor. Debe apuntar a un fichero
regular, legible, sin symlinks y contenido por `App/config/modules/`. Ese
fichero devuelve una instancia de
`BlogPreviewAssetAdapterInterface`; no puede imprimir salida. El adaptador
recibe un `BlogPreviewAssetContext` sin secretos —raíz del proyecto y estado
de desarrollo— y devuelve un `BlogPreviewAssetSet` con la lista completa de
CSS, scripts de módulo y scripts diferidos que usa el post público. El proyecto
reutiliza aquí su resolvedor real de Vite: en desarrollo entrega el cliente HMR
y el entry actual y, tras `build`, lee su manifiesto y entrega los nombres
resueltos. CORE no conoce el entry, el puerto de Vite ni nombres con hash.

El conjunto permite declarar de forma tipada los orígenes adicionales de
`connect-src`, imagen, fuente, frame, media y worker. CORE valida URLs y
orígenes, deriva la CSP final y nunca admite script inline, `unsafe-eval`,
wildcards o HTTP remoto. HTTP y WebSocket sin TLS solo son válidos en
desarrollo y contra loopback; la inyección de estilos de Vite se habilita con
el opt-in `allowInlineStyles`. Omitir el adaptador conserva el CSS y el runtime
público standalone del módulo, sin bloquear proyectos existentes.

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
admite borradores: una variante publicada debe retirarse expresamente antes de
enviarla; forzar el POST produce conflicto. No existe purga editorial ni
borrado permanente. La papelera conserva documento, referencias, categorías,
slug y locale, pero excluye la variante de listados, cargas editoriales y
consultas públicas hasta su restauración. Enviar o restaurar incrementa
`lock_version`.

Con `0016`, retirar registra la URL activa como `temporary_not_found`: sale
inmediatamente de feeds y sitemap y responde 404 limpio mientras siga siendo
razonable republicar. La pantalla privada `posts/url` explica la diferencia
entre retirar y borrar y exige una decisión explícita para convertir esa URL
en 410 o en 301. El 301 solo admite como destino otra variante publicada del
mismo idioma; no existen redirecciones automáticas a inicio o categoría, y
una publicación interna asignada a Dummy tampoco puede ser destino público.
Si el destino deja de estar publicado o pasa a Dummy, la resolución falla
cerrada como 404 en lugar de seguir una redirección insegura.
Republicar reactiva la URL canónica. Estas decisiones conservan contenido,
revisiones y papelera, y usan CSRF, capacidad de publicación, CAS y auditoría.

Con `0014` lista, una variante `published` puede mantener un espacio de trabajo
privado sin cambiar su estado ni su salida pública. `editorial_workspaces`
apunta a la revisión privada vigente, conserva la versión de publicación de la
que partió y `publication_heads` señala la revisión inmutable aprobada junto a
una versión de publicación creciente. Las categorías usan un CAS global por
post: `category_assignment_heads` versiona la asignación pública, mientras
`category_assignment_workspaces` y `category_assignment_workspace_items`
conservan la selección privada con su propio `category_workspace_version` y la
versión pública de la que partió. Este workspace es post-wide e independiente
del workspace localizado: una edición concurrente desde otra variante no puede
sobrescribir categorías en silencio. Guardar una variante publicada avanza su
lock editorial y crea una revisión privada, pero no modifica sus metadatos,
documento, medios, categorías ni cabecera públicos.

`Publicar` promociona de forma atómica la instantánea privada ya guardada y
consume la versión exacta del workspace global de categorías: actualiza la
proyección pública compatible, el documento y los medios actuales, las
categorías, la cabecera de publicación y sus versiones; después limpia los
workspaces consumidos. La misma transacción aplica el fencing del
sitemap y la auditoría. Retirar una variante con trabajo editorial privado
adopta primero esa instantánea como el nuevo borrador actual; solo después
retira su workspace localizado y la cabecera pública. Si no existe ese trabajo,
conserva el contenido publicado como borrador. Retirar o enviar una localización
a la papelera no consume ni elimina el head o workspace global de categorías
del post. Si el gate de `0014` no está listo, se mantiene el flujo anterior.

Duplicar crea siempre un agregado y una variante `draft` independientes, con
lock `1`, slug nulo y H1 `Copia de {H1}` truncado de forma segura en UTF-8. El
title SEO permanece. Si existe un workspace editorial, su instantánea solo
puede prevalecer cuando la fuente está publicada, la versión pública de partida
coincide con la cabecera actual y la revisión privada pertenece a esa
localización. Un workspace stale, incompatible, ajeno o corrupto falla cerrado;
no degrada silenciosamente al documento público ni crea una copia parcial.
Antes de copiar una instantánea válida, el proyector la normaliza al flujo
canónico de `Texto` y se revalidan sus medios.

El duplicado independiente toma las categorías del workspace post-wide privado
cuando existe y, si no existe, de la asignación viva. Copiar hacia un locale
ausente conserva el mismo post y, por tanto, comparte esa relación post-wide en
lugar de duplicarla. Cuando la fuente dispone de documento estructurado, ambos
destinos crean atómicamente su documento actual, sus referencias y una revisión
inicial propia con número `1`. No se copia el historial anterior, la publicación,
la cabecera o el workspace de origen; la fuente no se altera y el destino nunca
nace publicado.

Con `0019`, cada formulario de duplicación o idioma incorpora un
`operation_id` UUID emitido por el servidor. La reserva persistente liga esa
intención al actor, tipo de operación, post fuente, locales, lock esperado y
hash del payload. Repetir la misma clave con los mismos datos devuelve el
destino ya creado, también ante replays concurrentes; reutilizarla con otros
datos falla con conflicto y no crea un segundo borrador. Reserva, copia,
resultado y auditoría comparten transacción, de modo que un fallo revoca también
la idempotencia incompleta. La respuesta HTTP de error conserva el shell y
ofrece volver al listado o al artículo fuente sin comunicar un éxito falso.
Cada opción de locale recibe un UUID propio y el runtime sincroniza el campo
enviado al cambiar la selección o al volver mediante BFCache; así una elección
posterior no reutiliza la intención anterior. Si un replay exacto apunta a un
destino enviado después a Papelera, responde `409` contextual: no devuelve un
destino invisible, no degrada a `503` y no crea otra copia.

Cada categoría es otro agregado estable con UUID público y una traducción
independiente por locale. Nombre y slug pertenecen a la traducción; el slug es
único dentro de su idioma. La edición usa `lock_version` y las asignaciones se
reemplazan de forma transaccional, conservando las relaciones que no cambian.
El gestor dispone del mismo contrato localizado como HTML/PRG y como JSON
`no-store` de mejora progresiva: lista como máximo 100 traducciones, crea una
categoría o un locale, edita con CAS y elimina una traducción con CAS. El alta
rápida puede omitir el slug y obtiene uno ASCII determinista desde el nombre.
La respuesta JSON exige `Accept: application/json` y el header exacto
`X-LiquidStack-Category-Manager: async`; el inspector conserva además su
header compatible `X-LiquidStack-Editor: async`.
El borrado falla con conflicto mientras el agregado tenga una asignación live
o en un workspace privado; solo elimina el agregado cuando desaparece su
última traducción. Ninguna respuesta requiere ni expone IDs numéricos.
Con `0014`, los cambios de categoría de una variante publicada quedan en el
workspace global de su post y no alteran la relación pública hasta `Publicar`.
El CAS de asignaciones y de `category_workspace_version` rechaza bases
obsoletas. Retirar o enviar una variante a la papelera conserva tanto la
asignación global pública como cualquier selección privada pendiente. Una
operación admite hasta 100 categorías y nunca expone IDs numéricos de DB.

El contenido estructurado usa el esquema exacto
`liquidstack.blog.document`. La versión canónica actual es `2`, con un máximo
de 200 nodos controlados y 300.000 bytes de JSON canónico. Las plantillas
iniciales son `article-basic-01` y `article-cover-01`; esta última exige una
única imagen de portada como primer bloque. El H1 sigue siendo un campo
independiente de la variante y no forma parte del cuerpo.

Un documento V2 puede persistir además una selección de cabecera opcional y
tipada como `header: {hero, h1_module}`. `hero` admite `hero00`, `hero06` o
`hero07` cuando la plantilla dispone de portada; `h1_module` se elige de forma
independiente entre `moduleH1Type01`, `moduleH1Type03` y `moduleH1Type04`.
Ambas allowlists viven en catálogos de código separados para poder ampliarlas
sin volver a acoplar parejas. Los documentos anteriores que no contienen
`header` conservan exactamente sus bytes y derivan la pareja histórica de su
plantilla durante la lectura; no se reescriben ni cambian de hash. Preview y
salida pública resuelven esta misma selección mediante un único compositor SSR.
La portada conserva además `object_position_y=top|center|bottom`; el inspector
lo presenta con iconos bajo el selector multimedia compartido y no mantiene un
segundo selector nominal de fichero. Hero00 renderiza la media como
`<picture>/<img>` responsive, sin `background-image` inline. Su parallax actúa
una sola vez sobre `.hero00-media`, respeta `prefers-reduced-motion`, limpia su
estado al reinicializarse y deja una portada `cover` válida sin JavaScript.
El locale no se inyecta como copy del hero. La firma se resuelve siempre desde
el perfil WebAdmin vivo asociado a `created_by_user_public_id`: no se congelan
nombre ni rol al publicar. Cambiar el perfil actualiza todos los artículos
históricos en su siguiente SSR, sin reescribir el documento editorial. El
correo y los identificadores internos no llegan a la proyección pública.

`0004_webadmin_profile_preferences` pertenece a WebAdmin aunque Blog no esté
instalado. Añade una preferencia de zona horaria por perfil con CAS y auditoría;
solo acepta identificadores IANA validados en servidor. Los timestamps persisten
en UTC y la firma localiza la fecha en cada SSR con el locale del artículo y la
zona viva del autor. Si aún no existe una zona configurada, se usa UTC y se
muestra explícitamente. Nunca se deduce la zona del servidor, PHP, IP o
geolocalización.

La fecha pública conserva su forma larga localizada. En Gestión Blog y en la
papelera, la columna `Actualizado` usa la misma zona y la misma hora localizada,
pero presenta la fecha de forma compacta y estable como
`11:35 · 07/08/2026`.

WebAdmin expone `GET|HEAD /admin/profile` y `POST /admin/profile`. La escritura
exige sesión, CSRF, `webadmin.profile.manage_self`, CAS y auditoría. El formulario
SSR permite confirmar o corregir manualmente la zona sin JavaScript. Si el
campo está vacío, la mejora progresiva propone el resultado de
`Intl.DateTimeFormat().resolvedOptions().timeZone`; nunca guarda ni envía esa
propuesta automáticamente. No usa cookies, `localStorage` o `sessionStorage`,
y la persona puede corregirla antes de Guardar. La validación IANA del servidor
sigue siendo la autoridad.

V2 representa semántica estructurada y solo ofrece una excepción avanzada,
acotada y saneada, dentro de `Texto`. Tras la portada opcional, el nivel raíz
solo admite `section`; cada sección comienza con un encabezado estructural y
puede contener módulos, `article` o `div`. En las nuevas altas, ese encabezado
es la primera unidad de flujo de un `Texto`: nace como H2, pero la persona puede
elegir expresamente cualquier nivel H2-H6. El lector sigue admitiendo el nodo
`heading` directo usado por documentos anteriores y no lo reescribe durante
Composer, una migración ni la mera apertura del editor.

Los artículos y divisiones usan presets de una a cinco columnas, incluidas las
proporciones controladas 30/70, 40/60 y sus inversas. Sus encabezados opcionales
nacen como H3 y también pueden cambiarse a H2-H6. Un artículo no puede contener
otro artículo. Los `div` pueden anidarse hasta tres niveles, pero no contener
`section` ni `article`.

Para nuevas inserciones, todo el contenido escrito se resuelve con un único
tipo de módulo, `Texto` (`paragraph`). Su flujo interno puede alternar:

- párrafos;
- encabezados H2-H6, nunca H1;
- listas ordenadas o no ordenadas, con anidación controlada;
- citas (`quote`);
- destacados (`callout`).

El primer encabezado de cada `section` continúa siendo estructural y
obligatorio, aunque viva dentro de ese flujo unificado y su nivel siga siendo
editable. Dentro de `article` y `div`, los encabezados son opcionales. Los
defaults H2 de sección y H3 de artículo o división guían el alta sin bloquear
otras elecciones; los saltos se notifican como hallazgos SEO, no se corrigen ni
se bloquean silenciosamente.

Los nodos independientes históricos de Título, Lista, Cita y Destacado siguen
siendo válidos como entrada de compatibilidad y continúan renderizándose. Al
cargar el editor, cada uno se proyecta en memoria y de forma uno a uno al flujo
canónico de `Texto`, conservando UUID, orden, presentación, contenido y sus
metadatos específicos. Abrir o cancelar no escribe; cualquier guardado efectivo
persiste ya la forma unificada. Restaurar crea otra revisión canónica y duplicar
o copiar un locale tampoco reintroduce nodos textuales independientes. Las
revisiones históricas permanecen inmutables y esos tipos ya no aparecen en el
menú de nuevas altas.

El documento conserva además módulos controlados no textuales:

- enlace independiente;
- imagen de WebAdmin Media con ALT, title, caption, estado decorativo y modo de
  presentación por cada uso;
- vídeo YouTube con carga ligera;
- CTA primaria o secundaria.

`Texto` tiene dos variantes mutuamente excluyentes. La habitual persiste
`content` tipado. La avanzada persiste exactamente `html` y `css`, sin conservar
una segunda fuente `content`; ambos campos forman parte del JSON canónico y de
sus revisiones. El modo avanzado solo existe en V2. Un borrador puede conservar
`html` vacío mientras se trabaja, pero publicar exige contenido significativo.

Un `Texto` habitual admite hasta 200.000 bytes sumando todo su flujo, no 20.000
bytes para el módulo completo. La fuente HTML de un `Texto` avanzado admite
también hasta 200.000 bytes; su CSS mantiene un límite independiente de 30.000
bytes. El documento canónico completo —estructura, módulos y presentación—
continúa acotado a 300.000 bytes. El límite interno de cada fragmento inline
protege el parser, pero el editor divide el contenido sin cambiar su lectura:
un artículo de varios miles de palabras puede permanecer en un solo `Texto`
si el conjunto respeta esos topes técnicos.

La fuente HTML estándar anterior se conserva como compatibilidad para el
`content` tipado habitual y para las listas históricas. No es HTML libre ni se
ha ampliado su allowlist: sigue haciendo round-trip del flujo rico controlado.
La variante avanzada la supersede solo dentro de un `Texto` V2 que adopta
`html`/`css`; nunca mezcla ambas fuentes, convierte documentos existentes por
Composer ni relaja el contrato estándar. Su allowlist propia es la que se
detalla más abajo y todo CSS queda saneado y encapsulado por módulo.

Cada módulo V2 declara una presentación acotada: ancho `full`, `80`, `60` o
`40`; posición horizontal `start`, `center` o `end`; y alineación del texto
`start`, `center`, `end` o `justify`. Esta presentación se proyecta a clases
conocidas y nunca acepta CSS arbitrario, salvo el CSS saneado y encapsulado de
la variante avanzada de `Texto`. La escala visible de tamaño es S, M, L
y XL; sus valores canónicos son `s`, `m`, `l` y `xl`. Los documentos anteriores
que conservan `small`, `default`, `large` o `xlarge` se normalizan a esa escala
sin perder compatibilidad.

Los defaults globales de encabezado constituyen una frontera opcional separada.
Cuando `0012` no está aplicada o no existe todavía la fila `global`, el editor
usa exclusivamente los valores de código y todas sus rutas anteriores siguen
operativas. Una fila persistida define para H2-H6 un `preset`, `font_size`,
`font_weight`, `text_color` y `text_align` mediante allowlists cerradas, JSON
canónico, hash y bloqueo optimista. Su administración exige además `0013` y
`blog.settings.manage`; esta capacidad no es delegable y solo se siembra en
`site_admin` y `system_superadmin` protegidos. El color global de encabezados
admite `default` y los tokens `color00`-`color05`; deliberadamente no acepta
RGBA libre.

El contrato responsive ya es común al constructor y al SSR público. La base
móvil proyecta `full`, `80`, `60` y `40` al 100%. La `section` ocupa todo el
ancho y no aporta padding lateral; solo sus hijos directos reciben `1.5rem` de
padding inline, por lo que los niveles anidados no acumulan relleno. Una imagen
`full` hija directa de la sección elimina ese padding y puede ir a sangre. Desde
`48rem`, el módulo `full` que no sea imagen conserva el padding; solo se retira
en `80/60/40`, y los anchos pasan a 100%, 90%, 80% y 60%, respectivamente. En
ese breakpoint se activan las columnas
50/50, 40/60, 60/40, 30/70 y 70/30, además de las rejillas iguales de tres,
cuatro y cinco columnas. Desde `64rem`, `80`, `60` y `40` recuperan su porcentaje
exacto y `full` permanece al 100%.

En el constructor, cada Artículo y Contenedor expone su distribución de forma
visible tanto en la propia caja como en el inspector. Elegir de una a cinco
columnas redibuja inmediatamente guías discontinuas y traslúcidas, conserva los
elementos existentes y muestra una acción `+` utilizable en cada columna vacía.
Al ampliar una distribución, el contenido previo permanece en la primera
columna; al reducirla, los hijos se reúnen en la primera siguiendo su orden
visual, sin perder UUID, contenido ni configuración. Cambiar entre proporciones
del mismo número de columnas tampoco redistribuye los elementos.

Los módulos pueden arrastrarse entre columnas y reordenarse dentro de ellas. La
misma operación dispone de destinos recorribles por teclado y anuncia columna y
posición; los controles Subir/Bajar siguen siendo el fallback completo. Las
guías se apilan de forma responsive bajo el breakpoint público sin crear
overflow ni alterar el árbol canónico.

El constructor añade, solo dentro de WebAdmin, un espacio interior responsive a
la guía discontinua de cada `section`. Ese gutter desplaza la caja completa de
sus hijos —incluido su propio borde de edición— y evita que títulos, textos,
contenedores o medios queden pegados a la línea de la sección. No modifica las
clases de presentación, los porcentajes ni el posible bleed del SSR público, y
se contrae sin provocar overflow documental en móvil.

Las imágenes usan el allowlist de radio `default`, `none`, `small`, `medium` o
`large`, expuesto como controles de radio con iconos. `default` resuelve a
`medium`, salvo para una imagen `full` hija directa de `section`, donde resuelve
a `none` para conservar el bleed.

El texto inline admite texto, saltos, enlaces y una allowlist de marcas:
`strong`, `em`, subrayado, tres tamaños tipográficos, colores de tema
`color00`-`color05`, colores básicos rojo, naranja, amarillo, verde, azul,
morado, rosa y gris, y valores RGBA validados y canonizados, tanto para texto
como para fondo. El color general de los módulos y el fondo de los contenedores
también aceptan `color00`-`color05` o RGBA canónico. Los grupos de tamaño, color
y fondo inline son mutuamente excluyentes. Las listas usan la misma riqueza
inline y pueden ser numeradas o con viñetas. En `Texto`, la barra visual convierte
el párrafo o los párrafos seleccionados en `ul` u `ol`, y permite revertir una
lista del mismo tipo a párrafos sin perder marcas ni enlaces.

Las listas admiten como máximo cuatro niveles. El editor y el SSR aplican una
sangría moderada desde el borde izquierdo —aproximadamente `1.25rem` en el
primer nivel y un incremento menor en los anidados— para que una lista siga
leyéndose como parte del texto y no quede desplazada hacia el centro del
módulo. Una quinta profundidad falla cerrada en el candidato y nunca se
persiste de forma truncada.

Las citas y los destacados son unidades semánticas del mismo flujo, no cajas de
layout libres. Una cita se proyecta como `blockquote`, con una línea funcional
a la izquierda, tipografía algo más destacada y en cursiva, y margen vertical
respecto a los bloques vecinos. Un destacado se proyecta como una región de
nota con fondo sutil, sin borde decorativo y con padding suficiente para que su
texto no toque el límite visual. Ambas presentaciones son coherentes en lienzo,
preview y salida pública.

La barra visual representa formato, listas y color mediante controles SVG y
muestras, con nombre accesible, tooltip, estado seleccionado y área táctil. Las
paletas muestran primero los seis colores del tema, separan después los colores
básicos y dejan el selector RGBA personalizado como última acción; los nombres
permanecen disponibles para tecnologías de asistencia, no como una lista visual
de texto. Las marcas son componibles: aplicar color y después negrita —o en el
orden inverso— conserva ambas sobre la misma selección, mantiene los párrafos
vecinos y no introduce bloques `p` vacíos.

La misma barra incorpora un selector de bloque para convertir la selección en
párrafo o H2-H6, y acciones SVG reconocibles para Cita y Destacado. El control
opera sobre unidades completas aunque la selección solo cubra parte del texto,
preserva enlaces, marcas y atributos seguros y nunca ofrece H1. El estado y el
nombre accesible de cada acción reflejan el bloque activo.

La edición visual adopta un comportamiento de escritura estable. Intro divide
un bloque con contenido cuando procede. Cada Intro sobre una línea vacía crea y
persiste, en cambio, un nodo hermano `{"type":"break"}` en el flujo tipado y
un `<br>` raíz en la fuente HTML. Escribir sobre esa línea sustituye únicamente
el salto activo por un nuevo párrafo; los demás saltos conservan posición y
multiplicidad. `Ctrl+Intro` mantiene un significado distinto: inserta un nodo
`break` inline, es decir, un `<br>` dentro de la unidad actual.

Dentro de una lista, Intro en un elemento con contenido crea el siguiente `li`,
mientras que Intro sobre un `li` vacío sale al flujo raíz. El siguiente Intro en
esa línea vacía crea otro salto raíz y escribir sustituye solo el activo por un
párrafo, sin devolver el caret a la lista. El mismo contrato se aplica antes del
primer bloque, entre bloques y al final. No se serializan fillers internos ni
`<p></p>`; Visual y HTML representan los saltos como hermanos reales y
conservan marcas, IDs, atributos seguros, marcador y posición del cursor.

La pestaña HTML representa siempre el contenido completo del módulo. En el modo
visual conserva el flujo tipado de párrafos, H2-H6, listas, citas, destacados y
saltos raíz. Un `<br>` hijo directo del módulo se proyecta como
`{"type":"break"}`; un `<br>` dentro de P, H2-H6, `li`, Cita o Destacado sigue
siendo un `break` inline. En HTML avanzado, los saltos raíz participan en el
round-trip Visual/HTML sin perder las clases, los atributos o el CSS de los
bloques vecinos.
Al activar el modo avanzado de `Texto`, la allowlist estructural es `div`, `p`,
`ul`, `ol`, `li`, `h2`, `h3`, `h4`, `h5`, `h6`, `span`, `strong`, `em`, `u`,
`a`, `br`, `small`, `mark`, `sup`, `sub`, `code`, `blockquote`, `aside` e
`iframe`. Un
`aside` de destacado debe conservar el marcador seguro
`data-content-callout="true"` y el rol de nota; H1 continúa prohibido.
Los atributos generales seguros son `class`, `id`, `title`, `lang`, `dir`, un
conjunto cerrado de roles y ARIA, más `data-content-*`; enlaces, listas y citas
añaden sus atributos específicos validados. Se rechazan etiquetas desconocidas,
comentarios, formularios, scripts, estilos inline, eventos, hooks
`data-*` internos y URLs peligrosas sin sustituir el borrador del modal.
Los `iframe` se limitan a rutas HTTPS de YouTube, Vimeo y Google Maps en una
allowlist compartida con HTML / Incrustación; el servidor fija `sandbox`,
`allow`, carga diferida, referrer policy, título y dimensiones acotadas.

Las fuentes HTML y CSS usan cuatro espacios por nivel, ajuste de línea sin
scroll horizontal y edición por pares. El gutter mide la altura visual de cada
línea lógica: una línea envuelta conserva un único número y deja sus
continuaciones sin numeración engañosa, también al redimensionar el modal.
Llaves, corchetes, paréntesis y comillas
envuelven la selección o crean su cierre; escribir el cierre existente avanza el
cursor y Retroceso retira un par vacío. Intro entre aperturas y cierres conserva
la sangría, crea la línea interior y coloca el cierre en su propia línea al
nivel de la apertura; `Ctrl+Intro` sale del par sin reformatearlo. En HTML,
completar una etiqueta permitida añade su cierre. El formateador deja cada
bloque en una línea nueva, anida los `li` dentro de `ul`/`ol` y mantiene los
elementos inline en la línea de su párrafo, sin reescribir una fuente avanzada que solo se haya abierto y
cerrado. Ambos editores comparten resaltado inspirado en VS Code Dark Modern y
un historial acotado de cien unidades que integra escritura nativa y operaciones
automáticas; deshacer o rehacer conserva valor, selección y estado neto del
borrador sin depender del historial programático de `textarea` del navegador.

HTML y CSS ofrecen autocompletado dinámico derivado de sus allowlists, no una
biblioteca distinta en cliente. En HTML, escribir el nombre de una etiqueta en
una posición válida —por ejemplo `p` o `<p`— abre las coincidencias permitidas;
Flecha arriba y Flecha abajo recorren la lista y Tab o Intro insertan apertura y
cierre, con el cursor entre ambas. La lista solo aparece después de escribir un
prefijo que tenga coincidencias: estar entre un par vacío no la abre y, cuando
no está visible, Intro conserva su función normal de edición. En CSS, las
sugerencias distinguen el contexto de propiedad del contexto de valor y solo
proponen propiedades y valores que el saneador puede aceptar; aceptar una
propiedad inserta `: ;` y deja el cursor en la posición del valor. Escape cierra
la lista sin alterar la fuente y aceptar una sugerencia con Tab o Intro participa
en el mismo historial undo/redo.

El CSS avanzado se guarda sin scope para que duplicar el módulo con otro UUID
mantenga una fuente editable. El servidor lo parsea con una lista cerrada de
propiedades, reglas normales, nesting nativo y únicamente `@media`/`@supports`.
Los selectores seguros de clase, ID o etiqueta no tienen que coincidir todavía
con el HTML del módulo: el usuario puede preparar primero `.clase { ... }` y
asignarla después. La validación depende de la sintaxis y del aislamiento, no de
la presencia momentánea del selector en el HTML. Al corregir una regla
incompleta, la UI retira el error obsoleto y distingue un fallo CSS de uno HTML.
Rechaza imports, URLs, escapes, selectores globales o de ancestros, funciones y
propiedades capaces de ejecutar, capturar o cubrir la página. Las declaraciones
de raíz usan una allowlist menor que no puede mover ni dimensionar el wrapper.
En SSR, clases e IDs se prefijan de forma determinista con el UUID del bloque;
la misma proyección reescribe selectores, fragmentos `href` e IDREF de ARIA. El
resultado se anida bajo `[data-ls-blog-custom="<uuid>"]`, con aislamiento y
containment, y nunca se emite mediante atributos `style`.

Una variante avanzada cuyo HTML se limita al flujo textual raíz
`p/h2-h6/ul/ol/blockquote/aside` sigue siendo editable desde Visual cuando su
CSS usa propiedades de presentación que no
cambian la estructura ni vuelven significativos los separadores entre bloques
—por ejemplo color, tipografía, borde o fondo—. El flujo puede conservar
atributos seguros en sus contenedores, incluida una clase propia: el AST sombra
edita el contenido inline, permite dividir con Intro y transformar entre P,
H2-H6, Cita y Destacado, y el serializador vuelve a insertar los atributos sin
alterar el CSS. No duplica `id` ni referencias ARIA al crear la unidad siguiente.
Las clases, IDs y demás atributos seguros se proyectan sobre los nodos editables
y se comprueban contra la fuente antes de sincronizar. El CSS presentacional
seguro se aplica directamente al único canvas Visual mediante un `<style nonce>`
y un selector exclusivo de su instancia. No hay una segunda preview ni un
segundo scroll, y dividir un bloque conserva sus atributos y su estilo sin
reconstruir el `contenteditable`. Las listas con atributos o estructura compleja
continúan bloqueadas para cambios estructurales desde Visual y se editan en HTML.

Si el HTML usa otra estructura —por ejemplo `div`— o el CSS puede cambiar layout
o espacios, Visual ofrece una preview sandbox explícita y dirige a HTML/CSS.
Nunca simula un lienzo editable que pudiera descartar datos. Cancelar, o editar y
deshacer hasta el estado inicial, conserva exactamente el JSON y la fuente
originales. La conversión al modelo estándar conserva su parser estricto y no se
activa por el mero hecho de que el flujo avanzado sea editable visualmente.
Cuando el HTML avanzado está realmente vacío, Visual crea únicamente el primer
párrafo tipado al comenzar a escribir; una lista vacía o varios párrafos vacíos
ya presentes conservan su estructura y nunca se colapsan a ese párrafo inicial.

La política se genera por respuesta. En el flujo avanzado editable, el nonce
autoriza únicamente la hoja scopeada que se monta en el diálogo y se mantiene
estable durante la escritura. Cuando la estructura o el CSS obligan a un modo
no editable, el mismo contrato autoriza el `<style>` del `srcdoc` en la CSP del
documento padre y en la CSP interna del iframe; el `sandbox` permanece sin
permisos y no se habilitan scripts, red, fuentes, medios ni navegación. El lienzo
y el iframe de fallback consumen la misma proyección saneada y scopeada que el
SSR, de modo que una regla segura aceptada no desaparece al salir de CSS. La existencia del
selector en el HTML nunca es un requisito de sintaxis: `.clase { color: red; }`
puede prepararse antes de asignar `class="clase"`.

El modal inline puede crecer verticalmente hasta el alto dinámico disponible
del viewport y mantiene scroll propio tanto en su tamaño normal como ampliado.
Un único `Texto` puede contener el desarrollo escrito completo de un artículo:
párrafos, H2-H6, listas, citas, destacados y enlaces. Sigue siendo un solo
módulo dentro de su columna y no crea por sí mismo contenedores exteriores
`section`, `article` o `div`, medios ni CTA. El importador previsto
será una acción independiente, `Pegar borrador completo`, disponible desde el
inserto raíz bajo el hero. Aceptará primero texto o Markdown, mostrará antes de
aplicar un árbol de `section`, encabezados, `article`, párrafos y listas, y solo
reemplazará el documento en memoria tras validar el candidato completo. H1,
medios y conflictos jerárquicos requerirán una decisión explícita; cancelar no
modificará el documento ni la DB. Ese importador no reutiliza el parser del
modo HTML/CSS avanzado ni convierte silenciosamente una fuente en la otra.

El documento canónico se valida completo en servidor y se renderiza mediante
SSR con `section`, `article`, `div`, encabezados, listas y módulos coherentes.
Cada salto de flujo raíz renderiza como hermano
`<br class="blogDocument__flowBreak">`; nunca se sustituye por un párrafo vacío
y, por sí solo, no convierte un módulo completo vacío en contenido publicable.
`body_text` nunca llega desde el navegador: se proyecta desde ese documento
validado dentro del mismo guardado.

La versión plana V1 sigue siendo legible y renderizable. Cuando su jerarquía
permite una proyección V2, el editor la construye primero en memoria. El mismo
proyector normaliza los documentos V2 anteriores que todavía contienen módulos
independientes de Título, Lista, Cita o Destacado. Una actualización de Composer,
una migración o la mera apertura nunca escriben contenido editorial; el
guardado, la restauración o la duplicación sí persisten la forma canónica de
`Texto`. Los documentos V2 anteriores a `text_align` también se aceptan y
equivalen a `start`, conservando su forma histórica hasta una de esas escrituras
editoriales.

La proyección de compatibilidad hacia V1 conserva también los saltos raíz de un
`Texto` tipado. Como V1 no dispone de ese nodo de flujo, pliega cada uno sobre el
vecino inline compatible más próximo —evita los encabezados como destino— y
preserva posición y multiplicidad sin inventar un párrafo vacío. En `Texto`
avanzado conserva además cada salto LF visible como un nodo `break`; no lo
colapsa a un espacio. Una línea que supere 20.000 bytes se divide únicamente en
límites UTF-8 válidos y la proyección falla cerrada si necesitaría más de 500
nodos inline. Así, la fuente de compatibilidad mantiene el mismo texto visible
que el documento V2 sin aceptar silenciosamente una representación truncada o
divergente.

Cada guardado efectivo crea una revisión inmutable que conserva documento,
metadatos y versión editorial. En un borrador alimenta su estado actual; en una
variante publicada con `0014`, alimenta únicamente el workspace privado hasta
su promoción explícita. Restaurar una revisión no reescribe el historial: crea
una revisión nueva, ya normalizada, y también exige la `lock_version` vigente.
Al abrir el editor para un artículo anterior, CORE proyecta `body_text` a
párrafos solo en memoria; la adopción estructurada ocurre exclusivamente al
guardar. Desde ese momento, el guardado legacy de texto plano queda bloqueado
para evitar dos fuentes de verdad.

### Adopción operativa del Texto unificado

`composer liquidstack:blog:adopt-unified-text` inspecciona por defecto y en
modo de solo lectura todas las variantes activas no QA. Admite filtros y salida
JSON, informa las ya unificadas, las pendientes, las que carecen de documento
estructurado y las que no pueden proyectarse sin pérdida. Excluye siempre la
categoría reservada Dummy y la papelera.

La escritura es deliberadamente monovariante y exige a la vez `--apply`,
`--yes`, un único `--post`, un único `--locale` y `--actor` con permisos
vigentes:

```bash
composer liquidstack:blog:adopt-unified-text \
  --apply --yes --actor=<uuid> --post=<uuid> --locale=<locale> --format=json
```

El servicio vuelve a comprobar bajo lock actor, post, locale, estado, versión y
huella de la instantánea antes de usar el guardado transaccional ordinario. En
un borrador crea un nuevo current y una revisión; en una variante publicada
solo crea o avanza su workspace privado. Nunca republica, no modifica la
cabecera pública y no reescribe revisiones históricas. No es un hook de Composer
ni una migración automática: cada aplicación requiere esa orden explícita y se
detiene de forma segura ante deriva concurrente.

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
| `blog.settings.manage` | Administrar los defaults globales de presentación del editor; no es delegable. |

Las cuentas protegidas de WebAdmin reciben todas. Un `site_admin` puede
delegar a editores las capacidades editoriales ordinarias mediante la gestión
existente. `blog.settings.manage` queda reservada a `site_admin` y
`system_superadmin`: no se delega ni se asigna a otros roles.

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
| `GET`/`HEAD` | `/admin/blog/posts/url` | Estado y decisión SEO de la URL histórica retirada. |
| `GET`/`HEAD` | `/admin/blog/posts/preview` | Lectura privada y textual del contenido guardado; compatibilidad legacy sin medios ni estilos públicos. |
| `POST` | `/admin/blog/posts/save` | Guardar con versión optimista. |
| `POST` | `/admin/blog/posts/publish` | Publicar una variante completa. |
| `POST` | `/admin/blog/posts/unpublish` | Retirar una variante. |
| `POST` | `/admin/blog/posts/url-resolution` | Marcar explícitamente una URL retirada como 410 o 301 equivalente. |
| `POST` | `/admin/blog/posts/duplicate` | Duplicar una variante como borrador independiente. |
| `POST` | `/admin/blog/posts/trash` | Enviar un borrador a la papelera. |
| `POST` | `/admin/blog/posts/restore` | Restaurar un borrador de la papelera. |
| `GET`/`HEAD` | `/admin/blog/posts/updated` | Destino PRG sin PII. |
| `GET`/`HEAD` | `/admin/blog/categories` | Listado localizado de categorías. |
| `GET`/`HEAD` | `/admin/blog/categories/new` | Alta de categoría o traducción. |
| `POST` | `/admin/blog/categories/create` | Crear categoría o traducción. |
| `GET`/`HEAD` | `/admin/blog/categories/edit` | Edición por UUID y locale. |
| `POST` | `/admin/blog/categories/save` | Guardado con versión optimista. |
| `POST` | `/admin/blog/categories/delete` | Borrado localizado con versión optimista y bloqueo si está en uso. |
| `GET`/`HEAD` | `/admin/blog/categories/assign` | Selección para un artículo. |
| `POST` | `/admin/blog/categories/assign` | Sustitución transaccional de asignaciones. |
| `GET`/`HEAD` | `/admin/blog/categories/updated` | Destino PRG sin datos editoriales. |
| `GET`/`HEAD` | `/admin/blog/editor` | Editor estructurado de una variante. |
| `POST` | `/admin/blog/editor/save` | `Guardar borrador`: persistencia atómica de metadatos, documento y revisión de trabajo. |
| `POST` | `/admin/blog/editor/publish` | `Publicar`: promoción atómica de la instantánea privada ya guardada. |
| `POST` | `/admin/blog/editor/seo-analysis` | Análisis SEO editorial advisory del payload actual, sin persistencia. |
| `GET`/`HEAD` | `/admin/blog/editor/preview` | Vista previa privada de la instantánea de trabajo guardada o de la proyección legacy. |
| `GET`/`HEAD` | `/admin/blog/editor/revisions` | Historial o detalle de una revisión. |
| `POST` | `/admin/blog/editor/restore` | Restauración mediante una revisión nueva. |
| `GET`/`HEAD` | `/admin/blog/settings/presentation` | Configuración global de estilos H2-H6 para títulos nuevos. |
| `POST` | `/admin/blog/settings/presentation` | Guardado con CSRF y versión optimista de los defaults editoriales. |

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
historial. Promocionar el borrador guardado exige conjuntamente
`blog.articles.edit`, `blog.articles.publish` y `webadmin.media.view`. La subida
de nuevos assets se realiza en `/admin/media` y requiere además
`webadmin.media.upload`; el editor solo selecciona medios ya disponibles. La
revalidación transaccional comprueba que cada UUID exista y tenga variantes
AVIF antes de persistir su referencia.

El detalle de una revisión ofrece, con permiso de edición, una acción accesible
para restaurarla. El POST exige CSRF y el lock actual; el éxito no reescribe la
revisión histórica, sino que añade otra revisión canónica e inmutable. Un lock
stale, una revisión ausente o contenido inválido vuelve al shell con un error
contextual y no modifica documento, referencias, lock ni historial.

El listado admite búsqueda Unicode literal por H1 o slug y filtros allowlisted
por estado e idioma activo. El mismo formulario `GET` funciona sin JavaScript,
preserva sus filtros al paginar y rechaza entradas o offsets no canónicos antes
de consultar. Se pagina en bloques de 50, consulta una fila adicional para saber
si existe página siguiente y nunca oculta silenciosamente las variantes
posteriores. `blog-admin-list.js` mejora progresivamente ese HTML SSR: cancela
la respuesta anterior al teclear, reemplaza solo el resultado validado y
mantiene `pushState`, `replaceState` y `popstate` navegables. Un fallo conserva
la navegación GET normal como fallback.

Cada fila proyecta además el nombre visible del autor sin exponer su correo o
ID interno, las categorías traducidas al locale de la variante y su directiva
Index/Follow efectiva. Las categorías se obtienen para toda la página mediante
una consulta acotada y se apilan semánticamente dentro de la celda. Las tres
métricas compactas conservan encabezados textuales para lectores de pantalla;
los iconos SVG son markup fijo del módulo y nunca proceden de datos editoriales.

La columna Estado mantiene siempre el texto oscuro: acompaña `Publicado` con
un LED verde que hace un pulso de tres segundos al mostrarse y `Borrador` con
un LED ámbar fijo.
La papelera incorpora la misma columna con LED rojo y el texto `Eliminado`,
como presentación derivada de su tombstone recuperable, sin añadir un tercer
estado al dominio. Los LED son decorativos, el texto aporta el significado y
la animación se desactiva cuando el navegador solicita movimiento reducido.

Las vistas previas cargan por UUID y locale la última instantánea de trabajo
guardada: el workspace privado cuando existe, el borrador actual o, si todavía
no hay documento, su proyección legacy en memoria. No representan cambios que
sigan únicamente en el cliente. Antes de abrir la preview inmersiva, el runtime
guarda el formulario si está sucio. El diálogo se muestra de inmediato con el
estado `Guardando borrador…`, pero el iframe permanece sin `src` hasta que el
guardado confirme un snapshot válido. Un fallo conserva el estado, evita abrir
una representación desfasada y ofrece reintentar o volver al editor sin duplicar
la acción pendiente.

Esta experiencia visual completa pertenece exclusivamente a
`/admin/blog/editor/preview`. La ruta histórica `/admin/blog/posts/preview` es
una lectura privada textual del contenido guardado: no representa el hero, los
medios ni los estilos públicos y nunca se rotula como preview visual. El listado
solo mejora como diálogo inmersivo enlaces exactos al endpoint moderno; sin el
contrato estructurado necesario conserva la navegación explícita a la lectura
textual.

La preview reutiliza el mismo `BlogDocumentHtmlRenderer` SSR que la salida
pública para construir `header` y `main`, funciona con borradores incompletos y
no necesita origen público. El enlace se verifica como same-origin antes de
incrustarlo en el `iframe`; la respuesta permite ese uso exclusivamente con
`Content-Security-Policy: frame-ancestors 'self'` y
`X-Frame-Options: SAMEORIGIN`. También envía
`Cache-Control: no-store, no-cache, must-revalidate, max-age=0`,
`X-Robots-Tag: noindex, nofollow, noarchive` y un meta robots equivalente. No
emite canonical, metadatos SEO públicos, slug ni una URL compartible. Un GET o
HEAD no publica, audita, adopta ni modifica la variante, el workspace o la
cabecera pública.

El runtime usa exactamente `loading`, `ready` y `error` como estados visibles.
El error devuelve el foco a una acción operable, las regiones vivas conservan
su anuncio y la toolbar puede refluir o desplazarse con viewport corto y zoom
alto. A los `12 s` falla cerrado; cerrar, reabrir o iniciar otra carga invalida
callbacks anteriores para que un `load` stale nunca sustituya el estado actual.
La selección de idioma conserva además su formulario nativo dentro de
`details`: si no existe soporte de diálogo o falla JavaScript, opciones,
banderas, estado y acciones siguen visibles y utilizables sin overflow.

El documento SSR válido marca su raíz como
`html[data-blog-preview-ready="true"]`. Después de `load`, el padre comprueba
ese marcador y la URL completa same-origin; una redirección a login, una
respuesta de error que llegue como HTML, un origen distinto o un marcador
ausente fallan cerrados y vacían el iframe. La ausencia de error de red por sí
sola nunca se interpreta como una preview correcta. La validación exige además
al menos una hoja `<link rel="stylesheet">` y que todas hayan terminado de
cargar. Si el documento no alcanza ese estado en `12 s`, el runtime oculta y
vacía el iframe, anuncia el error y mantiene deshabilitados los controles de
dispositivo. Cerrar, reabrir o reemplazar una carga invalida su generación y
cancela el timeout, por lo que una respuesta o callback obsoleto no puede
sobrescribir el estado vigente.

Cuando existe `preview_asset_adapter`, ese mismo documento mantiene
`#smooth-wrapper` y `#smooth-content` y carga exactamente el entry visual del
post público, incluido el GSAP importado por el consumidor. La toolbar
inmersiva permanece en el documento WebAdmin padre: nunca se inserta dentro
del `iframe`. La respuesta conserva `frame-ancestors 'self'`,
`X-Frame-Options: SAMEORIGIN`, `no-store` y robots privados aunque el adaptador
declare orígenes de desarrollo para sus assets.

Las hojas declaradas por el adaptador se emiten como `<link rel="stylesheet">`
antes del CSS avanzado; no se delega el estilo inicial a una importación SCSS
desde JavaScript. Si el documento contiene `customCss()`, CORE genera un nonce
nuevo para esa respuesta, lo incorpora a la CSP calculada por el conjunto de
assets y lo aplica al único `<style>` scopeado. El adaptador del proyecto debe
servir también las URLs absolutas que esas hojas referencian —por ejemplo
fuentes o imágenes— en desarrollo y en build; ampliar `style-src` no repara un
asset inexistente.

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
su `header` y `main`, pero sigue autenticada, limitada al mismo origen y
protegida con `no-store` y `noindex`.

La jerarquía visual administrativa es plana. Se construye con espacio,
tipografía, grid, fondos sobrios y separadores funcionales; no usa por defecto
bordes laterales de acento, franjas mediante pseudoelementos, rebordes
decorativos ni cadenas de tarjetas anidadas. Los bordes permanecen reservados
para controles, foco, tablas, separadores y estados seleccionados.

El editor representa en directo la futura composición pública sin introducir
un segundo `main` ni un segundo H1 en el documento de WebAdmin. Su lienzo neutral
expone conceptualmente un `header` con H1 y medio destacado seguido del `main`.
Debajo, cada caja visual corresponde al árbol semántico V2: secciones con un
encabezado estructural H2-H6, artículos opcionales, contenedores anidados de
forma acotada y módulos dentro de sus columnas. El alta propone H2 para la
sección y H3 para artículo o contenedor, pero el inspector permite cambiar el
nivel sin introducir nunca H1. Mover, duplicar o retirar un contenedor opera sobre todo su
subárbol, por lo que el orden visual y el SSR público conservan la misma
semántica. La UI usa `Contenedor`; el documento y el HTML conservan `div`.

Subir y bajar permanecen como controles completos de reordenación dentro del
contenedor actual. El drag & drop es una mejora progresiva adicional: solo
confirma la mutación al soltar sobre un punto que mantenga secciones en la
raíz, artículos como hijos de sección, encabezados H2-H6 y un máximo de tres
niveles de `div`. Impide ciclos y conserva intacto el documento durante
un arrastre cancelado o inválido. La misma selección de destinos puede
recorrerse con teclado desde el asa Mover, confirmarse con Intro o Espacio y
cancelarse con Escape; no sustituye los botones anteriores.

Las guías del constructor son andamiaje exclusivo de WebAdmin: `section`,
`article`, `div` y módulos usan líneas discontinuas largas y redondeadas; la
sangría visual evidencia la anidación sin alterar la anchura del SSR público.
Un clic sobre cualquier caja la selecciona, abre su configuración y aplica una
sombra difuminada del color semántico correspondiente. Ese foco no se recorta
aunque una sección ocupe todo el ancho del lienzo. Los módulos centran su
contenido visualmente en el eje vertical y mantienen únicamente un lápiz con
nombre accesible para abrir su edición; la configuración ya no depende de un
engranaje. Anchura y posición horizontal se eligen mediante grupos de botones
con estado seleccionado explícito. La posición usa los SVG module-owned
gestionados por Blog; tamaño muestra S/M/L/XL y el radio de imagen usa cinco
presets legacy únicamente por compatibilidad. La configuración nueva de Imagen
ofrece altura automática o 5–100dvh, `cover`/`contain`, posición vertical
Arriba/Centro/Abajo, radio porcentual 0–50% y overlay completo con modo, token
corporativo o RGBA y opacidad. Los sliders actualizan la preview sin reconstruir
el lienzo; radio porcentual y radio legacy son mutuamente excluyentes. Intro o
Espacio sobre una caja enfocada ofrecen el mismo acceso mediante teclado.

Cada encabezado se previsualiza con su etiqueta real `h2`-`h6` y una escala
tipográfica coherente con el tema, de modo que la jerarquía sea visible mientras
se construye. Nivel semántico y apariencia son independientes: el inspector
ofrece presets cerrados inspirados en los recursos de títulos del showroom y
puede aplicar el elegido, junto con color, grosor, tamaño y alineación, a todos
los encabezados del mismo nivel del artículo. Los identificadores se validan
como tokens estables y nunca aceptan nombres de clase o CSS arbitrario.

La presentación general vive en el módulo `Texto`; el editor rico modifica la
selección mediante marcas inline, que prevalecen por herencia sobre los valores
generales. Los H2-H6 interiores conservan su semántica y sus presets. La
pantalla `Estilo editorial`, anterior al editor, persiste por H2-H6 el preset,
tamaño, grosor, color y alineación que heredarán únicamente los títulos nuevos.
No usa `localStorage`, no reescribe documentos existentes y conserva los
defaults de código si `0012`/`0013` siguen pendientes.

`Texto` se edita en un único modal rico con vistas Visual, HTML y CSS. Los
módulos históricos de Título, Lista, Cita y Destacado abren el mismo flujo
mediante la proyección unificada solo en memoria. La aplicación de cambios es
atómica: cancelar no modifica el documento y aceptar valida de nuevo el
candidato completo antes de sustituir el estado del lienzo. Un guardado efectivo
persiste `Texto` aunque el contenido visible no haya necesitado mezclar tipos;
conserva los UUID, el orden, la presentación y los IDs interiores compatibles,
sin reescribir las revisiones anteriores.

El menú de inserción separa contenido y estructura. Incluye Texto, Imagen,
Vídeo de YouTube, HTML, Botón y Separador; Artículo y
Contenedor solo aparecen donde la jerarquía los permite. Títulos, listas, citas
y destacados nuevos nacen dentro de Texto desde la barra Visual o su fuente
HTML; sus bloques independientes legacy no aparecen en el menú. `Enlace`
también desaparece de las altas: los documentos históricos se proyectan a
`Botón` primario conservando UUID, etiqueta, URL, título, destino y presentación,
mientras los enlaces inline siguen perteneciendo a Texto. El bloque HTML
ofrece solo las vistas HTML y CSS y nunca ejecuta el fragmento
dentro del editor. El servidor sanea ambos con listas cerradas, descarta
scripts, eventos, estilos inline y URLs inseguras, y solo conserva iframes
HTTPS de proveedores permitidos. Texto avanzado comparte exactamente esa
política de iframe; el módulo HTML conserva además su lista propia de etiquetas
de incrustación. Las clases e identificadores locales se namespacifican con el
UUID del módulo al renderizar, igual que los selectores de su CSS.

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
mantiene el formulario POST nativo como fallback. En una variante publicada
con `0014`, la selección se guarda en el workspace post-wide separado y
continúa siendo privada hasta `Publicar`; la navegación y los feeds públicos
siguen leyendo la asignación aprobada. El catálogo Media presenta el tramo
reciente y añade todos los assets referenciados por el documento actual, aunque
sean más antiguos, para que una edición nunca pierda la posibilidad de resolver
o conservar una imagen ya usada. Subir sigue siendo responsabilidad de
`/admin/media` y de `webadmin.media.upload`.

El editor diferencia las dos acciones. `Guardar borrador` persiste la
instantánea privada sin cambiar lo publicado. El formulario de publicación
expone `Publicar borrador guardado`, y la barra de la preview inmersiva lo
resume como `Publicar`, junto a `Guardar borrador`, `Volver al editor` y los
controles desktop, tablet y móvil. La publicación siempre parte de la última
instantánea confirmada, no de estado exclusivo del navegador. Sin el gate de
`0014`, se conserva el flujo anterior y una variante publicada permanece de
solo lectura hasta retirarla.

El formulario completo continúa siendo la fuente de verdad. La mejora
progresiva sincroniza el documento canónico y guarda con `fetch` y el header
`X-LiquidStack-Editor: async`. Solo acepta como éxito una respuesta `200` JSON
con `ok`, `lock_version`, `document_sha256` y el `document` canónico válido; la
publicación asíncrona confirma `ok`, `status` y `lock_version`. El fallback SSR
mantiene el POST nativo y su `303` al editor exacto del mismo post y locale. Un
`403`, `409`, `422`, contenido o JSON inesperado, una redirección de login o un
fallo de red conservan todos los campos y bloques, muestran un error genérico y
no navegan. La mejora intercepta el submit antes de sincronizar o validar, de
modo que una excepción tampoco puede degradar en navegación nativa. Cuando hay
cambios pendientes, los enlaces internos y el logout muestran un diálogo
LiquidStack con las opciones de guardar, descartar o seguir editando. El editor
no registra `beforeunload` ni usa diálogos nativos del navegador; cerrar o
recargar la pestaña queda, por tanto, bajo el comportamiento normal del
navegador. Tras un éxito validado se actualizan la huella, el lock y el
documento confirmado.

Una excepción inesperada durante un POST asíncrono se traduce en JSON genérico
`unavailable`; no devuelve HTML de error, trazas ni la causa interna. El cliente
mantiene el borrador local y distingue ese resultado de una respuesta válida sin
intentar navegar.

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
`$blogArticle`, una instancia tipada de `BlogPublicArticleViewModel`, y
`$blogArticleShell`, un `BlogPublicArticleShellContext` con el nonce y la URL
del runtime público gestionado. La vista comienza con su único require previo al
`DOCTYPE`, `App/app/_moduleBlogPublicArticle.php`; ese adaptador prepara
catálogo, SEO y assets del stack, pero no emite cabeceras ni HTML. El view model
expone locale, canonical, alternates y
`x-default`, navegación localizada, title y description SEO, H1, extracto,
y cuatro proyecciones HTML ya saneadas: `bodyHtml()` conserva el cuerpo
histórico completo, incluida la portada; `headerMediaHtml()` mantiene el medio
aislado por compatibilidad; las vistas nuevas deben componer `headerHtml()` con
`mainHtml()` para aplicar la misma selección de hero y H1 que la preview sin
duplicar la portada. También expone la URL de portada opcional, plantilla
y fechas inmutables de publicación/actualización. `alternateUrls()` contiene
solo variantes publicadas
y alimenta `hreflang`; `languageNavigationUrls()` cubre todos los idiomas
activos y cae al índice localizado cuando el artículo aún no tiene traducción
publicada. Ambos contratos permanecen separados para no inventar alternates
SEO.
Los escalares permanecen sin escapar para que el proyecto los codifique según
su contexto; solo `bodyHtml()`, `mainHtml()`, `headerMediaHtml()` y
`headerHtml()` se imprimen como HTML prevalidado. `customCss()` es una quinta
proyección ya saneada y completamente scopeada; nunca contiene una etiqueta
`style`. La vista del proyecto debe emitirla sin escapar dentro de un único
`<style nonce="...">` usando el nonce ya entregado por el contexto. Una excepción,
un fichero que deje de ser regular o una salida vacía fallan de forma genérica,
sin volver silenciosamente al fallback.

La vista project-owned es responsable del documento, head, assets y layout. La
seguridad pertenece a la política compartida de
`App/config/modules/blog-public.php`: el controlador fusiona sus cabeceras
defensivas y CSP en la `Response` antes de entregar el HTML. La vista nunca
llama `header()`, genera un nonce paralelo ni requiere un include local de
seguridad. Si no se configura vista, el HTML standalone y sus metadatos siguen
siendo el fallback compatible; enlaza el asset gestionado
`/assets/modules/blog/blog-public.css` y el runtime gestionado
`/assets/modules/blog/blog-public.js`. El CSS fallback mantiene tipografía
explícita hasta H6 y espaciado responsive para las `section` y `article`
proyectadas, sin convertirlas en tarjetas ni añadir decoración estructural.
Su CSP permite exclusivamente estilos y scripts del mismo origen, el nonce
generado para el CSS avanzado presente y solo los orígenes exactos del
catálogo de iframes (`youtube-nocookie.com`, `youtube.com`,
`player.vimeo.com`, `www.google.com` y `maps.google.com`). La validación de
contenido sigue restringiendo además la ruta de cada proveedor.

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

Los iframes escritos en Texto avanzado o HTML permanecen en el
documento canónico, pero el SSR nunca entrega un nodo `iframe` ni un `src`
cargable: los sustituye por placeholders inertes. El runtime gestionado vuelve
a validar origen, ruta y atributos y solo materializa esos iframes cuando
`cookie_social=true`; al retirar el consentimiento, cambiar de pestaña o
restaurar la página los desmonta de inmediato y recupera el placeholder. A
diferencia del bloque YouTube con portada, no exige otro clic tras consentir.

El fallback standalone limita su CSP a `script-src 'self'` y a los orígenes
exactos devueltos por la misma política de iframe. Un `public_article_view`
carga el mismo script gestionado con el nonce de su contexto; cualquier origen
adicional se declara en `App/config/modules/blog-public.php`, nunca mediante una
cabecera escrita en la vista. Ampliar `frame-src` no carga contenido ni
sustituye el gate de CookieLAD.

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

Los soportes backend project-owned pueden obtener cards generales, filtros y
cards por categoría desde una única instancia creada por
`BlogPublicFeedFactory`; las vistas consumen únicamente sus proyecciones
tipadas. Ese feed comparte el mismo runtime y la misma conexión PDO durante la petición.
`BlogCategoryPublicFeedFactory` permanece como adaptador compatible para
consumidores anteriores. La proyección de categorías valida `0001+0003` sin
depender de las capacidades administrativas de `0004`, ejecuta consultas
acotadas sin N+1 y devuelve como máximo 100 filtros. La consulta lee 101 para
detectar el desbordamiento y falla cerrada en lugar de truncar silenciosamente
el catálogo. La proyección devuelve exclusivamente arrays
de presentación: locale, slug, nombre y contador para filtros; y locale, slug,
URL, H1, extracto, fechas y categorías localizadas para cards. Las categorías
de todas las cards de una página se resuelven mediante una sola consulta batch,
acotada a 50 slugs, sin N+1; excluyen siempre `Dummy` y solo proyectan locale,
slug y nombre. PDO, prefijos, IDs numéricos y UUIDs no cruzan hacia los
recursos.

El índice público reutilizable se resuelve mediante
`BlogPublicIndex::current()->resolve()`. El soporte gestionado
`App/app/_moduleBlogPublicIndex.php` es el único require previo al `DOCTYPE`:
convierte query, parámetros de ruta, servidor y catálogo de idioma en
`BlogPublicIndexInput` y `BlogPublicIndexTextCatalog`, resuelve la extensión
project-owned opcional de `App/config/modules/blog-public-index.php`, aplica
HTTP, SEO y la seguridad compartida de `App/config/modules/blog-public.php` y
deja disponible un `BlogPublicIndexPage` inmutable, sin HTML. El fichero legacy
`blog-public-index.php` queda reservado a una fuente `preview` opcional de
desarrollo; no debe contener una segunda configuración de seguridad y se omite
cuando no existe preview.
Redirect y HEAD terminan en esa frontera antes de maquetar. La fachada abre como
máximo un feed por request, compone filtros, archivo, cards, lookahead,
paginación, canonical, robots, alternates y estados `200|404|503`. Un fallo de
entorno, schema o PDO posterior a una configuración y origen válidos degrada a
la misma página `unavailable`, con `Retry-After`, en vez de fatalizar.

La vista project-owned conserva solo la maquetación: requiere ese soporte y
compone el H1 dentro de un recurso hero cuya raíz `<header>` precede a
`<main>`. Después llama explícitamente a los controladores `moduleBlogSearch01`,
`moduleBlogCategoryBar01`, `moduleBlogGrid02`, `moduleBlogPagination01` y
`moduleBlogArchive01` con los arrays ya proyectados. La colección, el paginador
y el archivo se renderizan primero en variables y se inyectan como slots
opcionales de `moduleBlogResults01`; este compositor posee su template, SCSS y
la única frontera reactiva `#blog-results[data-blog-results]`. Es singleton por
documento mientras filtros, historial, URLs y runtime de paginación dependan de
ese identificador canónico. No contiene PDO, superglobals, consultas,
buffering, cabeceras ni condiciones de shell. Cada controlador decide si sus
datos producen HTML y devuelve `''` cuando no existe una representación útil;
los snipers se invocan siempre. `sectionBlogCatalog01` recibe como slots hermanos
los dos formularios y `moduleBlogResults01`, aporta el H2 común y mantiene los
formularios fuera del target que reemplazan los runtimes. `moduleBlogGrid02`,
Pagination01 y Archive01 tienen siempre raíces `div` neutras, sin sumar
landmarks `section`/`nav`, y conservan sus IDs, clases, hooks, enlaces y estados.
Grid02 no duplica el heading exterior y sus cards son `<article>`/H3 con un
CTA visible configurado mediante `cta_label`; el índice tipado lo obtiene de
`blog_index_card_cta` para que las respuestas SSR y reactivas conserven idioma;
Archive01 conserva su heading interior y Pagination01 conserva enlaces SSR con
`aria-current`. Results01 exige que cada hijo tenga raíz `div` sin `role` y
Catalog01 repite la defensa frente a `section`/`nav`. Ninguno publica un
selector de tag ni un modo standalone. El
compositor rechaza cualquier slot que reintroduzca un `section` o `nav`
descendiente. `body`, `main`,
`section` y wrappers de página
se mantienen neutros: una agrupación funcional o presentacional compartida se
modela como recurso compositor, no como `div` crudo. Nav y footer se incluyen
sin condiciones. Los controladores visuales siguen agnósticos y nunca consultan
storage.

Internamente, la fachada construye un `BlogPublicCatalogQuery` acotado y lo
entrega a `BlogPublicFeed::cardsForQuery()`. El query valida hasta 480 bytes de entrada,
normaliza los espacios y admite búsquedas no vacías de 2 a 120 caracteres
Unicode; acepta hasta diez slugs de categoría, modo allowlisted `any|all`, un
máximo de 50 filas, offset público de hasta 10.000 y exclusión opcional. El
orden también es cerrado: `newest` ordena por publicación descendente,
`oldest` por publicación ascendente y `updated` por actualización descendente;
los tres usan un desempate estable y nunca interpolan una expresión recibida
del request. El repositorio aplica todos los filtros en una consulta preparada.
Cuando una vista necesita detectar la página siguiente, solicita expresamente
una fila adicional dentro de ese límite y no la expone como card. SQLite
registra una función determinista de
casefold Unicode una sola vez por conexión; MySQL conserva su conversión
Unicode nativa. El formulario SSR sigue siendo
funcional sin JavaScript y `moduleBlogFilters01` mejora progresivamente el
mismo GET mediante `fetch`, cancelación de peticiones e historial del
navegador. La mejora conserva la paridad del GET nativo, invalida respuestas
obsoletas durante el debounce, usa una única entrada reemplazable para cada
secuencia de búsqueda viva y sincroniza `title`, robots y canonical con el SSR
recibido.

`BlogPublicResourceQuery` encapsula la configuración de las composiciones
dinámicas: locale, alcance de categorías `all|selected`, modo `any|all`,
exclusión obligatoria de Dummy, cantidad visible, lookahead, offset, búsqueda,
slug excluido y uno de esos tres órdenes. `BlogPublicResourceFeed::batch()`
devuelve un `BlogPublicResourceBatch` inmutable con `items`, `has_next`,
`next_offset` y `next_url`. El array API infiere `limit = items + 1`; quien
construya el value object directamente debe reservar expresamente esa fila de
lookahead. `next_url`, cuando existe, es una URL relativa a la raíz validada y
la calcula el soporte backend project-owned: el feed no consulta ni inventa rutas.

Las vistas que solo necesitan una colección pública acotada usan como único
soporte previo al `DOCTYPE`
`App/app/_moduleBlogPublicCollections.php`. El hook expone
`$blogPublicCollections`; `latest($locale, $limit)` resuelve los publicados más
recientes y devuelve un `BlogPublicCollectionViewModel` con estado `ready`,
`empty` o `unavailable`. No emite cabeceras ni HTML y evita que cada vista
instancie `BlogPublicResourceFeed`, factories, `try/catch` o queries. Las
selecciones avanzadas se encapsulan en soporte backend propio, no en la
maquetación.

La proyección puede devolver hasta 100 categorías para otros consumidores,
pero `moduleBlogFilters01` muestra como máximo diez controles: ante un enlace
con filtros válidos, sitúa primero las categorías seleccionadas y completa el
resto en el orden del catálogo. Así el formulario SSR nunca ofrece más
selecciones simultáneas de las que acepta el query ni construye URLs extremas.

El índice project-owned acepta el header exacto
`X-LiquidStack-Partial: blog-results`, conserva `Vary` y puede responder con el
mismo documento HTML SSR completo: el runtime extrae los formularios,
`#blog-results`, `title`, robots y canonical sin insertar nav, footer o head en
el DOM vivo. Una optimización futura que reduzca ese cuerpo pertenece al soporte
backend o a una frontera SSR dedicada; nunca debe trocear el shell mediante
condiciones dentro de la vista ni introducir JSON como segunda fuente de verdad.

Las colecciones nuevas reutilizan esa misma respuesta HTML para cargar lotes
sin introducir JSON. El runtime compartido module-owned tiene su fuente en
`modules/blog/resources/project/src/js/modules/blog/blogCollectionLoader.js` y
se instala como `src/js/modules/blog/blogCollectionLoader.js`. Parte de un
enlace siguiente SSR navegable, acepta los modos `manual` y `near-end`, y solo
hace `GET` al mismo origen con el header parcial allowlisted. Antes de anexar
valida el ID y el tipo exactos de la colección recibida, elimina duplicados
mediante la clave estable de cada card y rechaza una página que anuncie
continuación sin aportar items nuevos. Mantiene estados accesibles `ready`,
`loading`, `error`, `end` y `empty`, permite reintentar, limita el avance
automático, invalida respuestas obsoletas, aborta al desmontar y emite
`liquidstack:blog-collection-appended` para que cada recurso reconcilie solo
los nodos añadidos. Sin JavaScript, con una respuesta inválida o fuera de sus
condiciones de mejora, el enlace SSR conserva la navegación normal.

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
- `moduleBlogSearch01`, buscador compacto que combina `q` con el orden cerrado
  `newest|oldest|updated` y conserva las categorías activas;
- `moduleBlogCategoryBar01`, barra de categorías combinable que conserva la
  búsqueda y el orden activos y permite coincidencia `any|all`;
- `moduleBlogPagination01`, navegación paginada SSR con página actual,
  anterior/siguiente y URLs root-relative calculadas por la vista, sobre raíz
  neutra para que la sección exterior aporte el contexto;
- `artBlogArticle01`, composición semántica del artículo para las plantillas
  básica y con portada, con cuerpo saneado por CORE e intro/retorno inyectables;
- `moduleBlogArchive01`, archivo por periodos con recuentos, estado actual,
  heading interior y raíz neutra;
- `moduleBlogResults01`, target reactivo singleton con slots opcionales para
  colección, paginación y archivo;
- `sectionBlogCatalog01`, compositor semántico con H2 que agrupa buscador,
  categorías y resultados sin admitir landmarks anidados;
- `sectionBlogGrid01`, rejilla de cards;
- `sectionBlogList01`, listado editorial centrado dentro de una columna
  responsive de lectura;
- `sectionBlogFeatured01`, entrada destacada con secundarias;
- `sectionBlogRelated01`, cards relacionadas por categorías compartidas;
- `sectionBlogSlider01`, carrusel con miniaturas explícitas, GSAP Draggable,
  Inertia, snap, bucle visual continuo y autoplay accesible;
- `moduleBlogGrid02`, rejilla dinámica con variantes `regular` y `bento`,
  media `16:9` limitada a `16rem`, título enlazado y CTA localizado por card,
  revelado GSAP de los lotes nuevos y fallback visible sin animación, raíz
  neutra y cards `<article>`/H3;
- `sectionBlogSlider02`, carrusel GSAP con Draggable, Inertia, snap y bucle
  visual continuo para cualquier conjunto no vacío, con media `16:9` y
  `object-fit: cover`;
- `sectionBlogStack01`, pila editorial vertical que, solo con 3–8 cards, altura
  suficiente, desktop y movimiento permitido, mejora mediante ScrollTrigger.

`moduleBlogSearch01` y `moduleBlogCategoryBar01` pueden preceder juntos a una
misma región de resultados. Cada formulario conserva en campos ocultos los
ejes que controla el otro y ambos reutilizan el runtime GET/SSR de
`moduleBlogFilters01`; tras una respuesta parcial, el runtime sincroniza
también los formularios compañeros para evitar estado obsoleto. Sin JavaScript
siguen siendo dos formularios GET completos. Ambos paneles conservan de 1,5 a
3 rem de padding responsive. CategoryBar separa además sus grupos, chips y
acciones mediante gaps responsive propios, sin depender de la vista o del
compositor exterior. `moduleBlogPagination01` conserva
enlaces SSR con filtros y orden y marca la actual con `aria-current="page"`.
Su runtime opcional intercepta solo clics simples same-origin dentro de
`#blog-results`, solicita la misma variante HTML parcial, sustituye el target y
sincroniza title, canonical, robots, History API y `popstate`. Timeout, offline,
HTML inválido o carrera conservan los resultados actuales y el enlace nativo
permanece como fallback.

`sectionBlogSlider01` adopta la experiencia de animación de `artSlider01` sin
sus selectores globales. Prioriza `thumbnail` sobre `media` cuando la card la
aporta explícitamente, admite varias instancias, arrastre de ratón o tacto,
Inertia, snap, controles anterior/siguiente, teclado y supresión del clic
accidental después de arrastrar. No existe un opt-out `wrap`: siempre que la
mejora JavaScript sea elegible, de uno a veinte originales forman un bucle
visual continuo. El controlador emite cada card una sola vez en SSR; el runtime
clona conjuntos completos antes y después hasta cubrir el viewport. Las copias
son solo de presentación: usan `inert`, `aria-hidden` y `role="presentation"`,
no conservan IDs, referencias a IDs, `data-blog-card-key` ni focos, mientras
las cards originales mantienen toda la semántica y la interacción.

Su autoplay configurable expone pausa y reanudación, y se detiene ante
interacción, foco, salida del viewport, documento oculto o movimiento reducido.
La reinicialización y HMR desmontan copias, Draggable, tweens, timers, listeners
y observadores. Un propietario por raíz compartido mediante `Symbol.for`
impide que dos identidades ESM/HMR desmonten la misma instancia y evita ese
parpadeo. Sin GSAP, con RTL o `prefers-reduced-motion`, permanece el scroll-snap
SSR; cero items mantiene el estado vacío.

`sectionBlogSlider02` conserva ese mismo contrato multiinstancia, de ownership,
copias visuales y bucle continuo para cualquier conjunto con uno o más
originales, y añade rueda horizontal. El autoplay está activo por defecto,
espera 6 segundos y anima cada transición durante 2 segundos; la vista puede
acotar ambos valores o desactivarlo. Su media queda acotada a `16:9`, usa
`object-fit: cover` y prioriza una `thumbnail` explícita sobre la imagen
editorial mayor. Cero items, RTL y movimiento reducido conservan el fallback
SSR, y resize reconstruye la cantidad de copias necesaria sin acumularlas. Su
cleanup retira copias, Draggable, tweens, timers, listeners y observadores.

`moduleBlogGrid02` mantiene una rejilla responsive normal o una composición
bento sin depender del número exacto de resultados; prioriza `thumbnail`,
acota la media a `16:9` con `object-fit: cover`, hereda en el enlace el tamaño
real del heading y añade un CTA compacto y sin sombra mediante `cta_label`.
En la variante regular, desktop conserva filas completas de tres cards iguales
y centra solo el resto real de una o dos; la regla tablet nunca puede ensanchar
ni desplazar el tercer item. `moduleBlogArchive01` limita y centra su propio
ancho y redistribuye 1/2/N periodos en filas incompletas centradas, aunque su
raíz siga siendo semánticamente neutra.
Su animación de entrada solo afecta cards no reveladas y se limpia o evita con
movimiento reducido.
`sectionBlogStack01` nunca secuestra el scroll: fuera del rango 3–8, con una
carga siguiente pendiente, viewport insuficiente o movimiento reducido se
presenta como listado vertical normal. Dentro del rango apto fija las cards de
forma progresiva, recalcula tras resize o anexado y destruye todos sus
ScrollTrigger al desmontar.

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

Esa API acepta de forma aditiva `categories` y `media` o `thumbnail` dentro de
cada item explícito. Las categorías se normalizan por slug y pueden recibir una
URL segura calculada por el proyecto; sin ella se muestran como texto. La media
solo se renderiza con `src`, `alt`, `width` y `height` válidos, como imagen lazy
y sin estilos inline; Slider01 y Slider02 prefieren la miniatura cuando ambas
variantes llegan ya proyectadas. El helper somete `src`, cada candidato de
`srcset` y `sizes` a validación estricta, conserva el fallback pequeño si un
atributo responsive opcional es inválido y calcula el `sizes` según la geometría
de cada recurso.

El feed público puede enriquecer las cards mediante un repositorio batch
opcional que compone los scopes Blog y WebAdmin. Para un lote no vacío ejecuta
como máximo dos `SELECT` constantes. El primero obtiene el documento actual y
sus referencias publicables; PHP selecciona la portada o, si falta, la primera
imagen según el orden canónico del documento. Solo cuando esa fase produce al
menos un medio elegible se ejecuta el segundo `SELECT`, limitado a las variantes
de los medios elegidos; un lote sin imagen termina tras la primera consulta.
Solo participa una localización
`published` con documento `CURRENT` íntegro; de WebAdmin se admiten únicamente
metadatos de variantes AVIF válidos.
La salida de presentación no contiene IDs: `thumbnail.src` es la mayor variante
disponible de hasta 900 px, `thumbnail.srcset` enumera como máximo ocho
candidatos en orden ascendente y el backend no emite `sizes`. Una variante sin
candidato pequeño, una referencia ajena, documento o metadatos corruptos, un
schema no preparado o storage no disponible omiten la miniatura afectada sin
dejar de entregar la card textual ni degradar al resolver por localización. Por
tanto, el número de consultas no crece con el número de cards y los
controladores visuales siguen libres de PDO.

La familia visual requiere el contrato SCSS estándar del consumidor. Si CORE
no puede leer, completar o verificar `src/scss/_config.scss`, ese ciclo publica
los assets autocontenidos del módulo bajo `public/assets/modules/blog`,
`src/js/modules/blog` y `src/scss/modules/blog`, pero retiene conjuntamente
controladores, templates, SCSS, JS y hooks de showroom estándar. Una
actualización posterior con el contrato reparado instala la familia completa.

El showroom usa diez fixtures Matrix localizados y no inserta ni ofrece ese
copy como fallback en la DB pública. Grid02, Slider01 y la única instancia de
Slider02 muestran los diez; sus configuraciones y límites 0/1/N se documentan
como comentarios y se cubren por pruebas, sin repetir el mismo recurso en la
vista. Stack01 usa ocho para respetar su contrato. Una
composición adicional combina Search01, CategoryBar01, Grid02 y Pagination01:
muestra 4 de los 10 fixtures por página, con tres páginas SSR navegables. Los
fixtures aportan media explícita y categorías seguras, sin consultar la DB. La
fuente canónica de sus 16 derivados AVIF vive en
`resources/img/dummy/responsive`, bajo sincronización gestionada de Composer, y
cubre 480, 899/900, 1800 y 2560 px; la copia instalada en el consumidor solo se
aplica cuando el feed no ha proyectado ya `thumbnail` o `media`.
Las claves legacy de `templates` se mantienen aditivamente durante la
transición. Un stack sin Blog no recibe los controladores, templates, estilos,
runtimes ni hooks de esta categoría.
Este corte se integra en CORE principal dentro de `Unreleased`; todavía no es
una release versionada y debe superar la matriz de adopción antes de publicarse. La QA
funcional-visual previa se ejecutó en Chrome real a 390, 768 y 1280 px sobre
centrado, miniaturas, varias instancias, controles, teclado, filtros
coordinados, historial, paginación SSR y ausencia de overflow o errores de
consola; detectó y corrigió además la altura intrínseca de Grid02 y la
contaminación del `nav` global sobre Pagination01. La regresión posterior del
bucle 0/1/N y del ownership ESM/HMR queda cubierta por la suite de recursos; su
repetición en Chrome después de reinstalar cada corte en un consumidor de
referencia forma parte del gate del consumidor antes de publicar.

## SEO técnico de artículos públicos

Cada variante publicada renderiza en servidor su `title`, meta description,
robots y canonical absoluto. `index` y `follow` se resuelven por variante; una
fila heredada sin preferencia explícita conserva el comportamiento
retrocompatible `index,follow`. La misma directiva llega al meta robots del SSR,
al view model project-owned y al header HTTP `X-Robots-Tag`. La migración
opcional `0015_blog_robots_preferences` persiste la preferencia viva y una copia
inmutable por revisión, con hash de integridad y gate propio; mientras está
pendiente, el módulo sigue operativo con los defaults anteriores. La política
pública ofrece además una extensión fail-closed que puede forzar
`noindex,nofollow` sin habilitar indexación. La migración
`0017_blog_dummy_category` crea la identidad interna canónica de Dummy y
`0018_blog_dummy_category_normalization` añade esa identidad, de forma
idempotente y sin borrar relaciones históricas, a las asignaciones vivas y a
los workspaces ligados a categorías legacy cuyo slug exacto sea `dummy`. La
política pública y el catálogo privado reconocen exclusivamente el UUID
canónico: esas variantes no se entregan, indexan ni muestran en Gestión Blog,
y no existe un parámetro GET que permita saltarse la exclusión.

La entrega también incluye Open Graph de tipo
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
composer liquidstack:webadmin:onboard --yes
composer liquidstack:doctor
```

`--plan` permanece offline y `--dry-run` solo lee. `--apply` no forma parte del
flujo por defecto: se ejecuta únicamente después de revisar el plan, crear y
comprobar un backup recuperable de DB y storage, y autorizar expresamente la
mutación.

Las migraciones de Blog asignan sus capacidades. Después de añadir Blog a una
instalación WebAdmin existente debe repetirse el onboarding idempotente para
verificar las dos identidades y su acceso, sin reactivar, duplicar ni sustituir
usuarios o invitaciones válidas. En una instalación nueva completa además la
entrega acotada y verifica el estado de acceso; nunca drena el outbox ordinario.
Ninguno de estos pasos se ejecuta desde `composer update`. El contrato completo
de bootstrap, entrega, recuperación e identidades project-owned vive en
[Bootstrap inicial de WebAdmin](webadmin-bootstrap.md).

Los gates de readiness usados durante una petición HTTP son deliberadamente
acotados. Verifican que los registros de migración requeridos existen con su
scope/checksum, prueban las tablas y columnas consumidas mediante `SELECT` de
cero filas y, cuando procede, comprueban invariantes operativas pequeñas como la
identidad Dummy. No recorren `INFORMATION_SCHEMA` ni encadenan todas las
postcondiciones de migraciones anteriores en cada request. Los verificadores
exhaustivos de DDL, índices, FKs, triggers, semillas y filas continúan íntegros
en `migrate`, `--dry-run` y `doctor`; optimizar HTTP no reduce la prueba de
adopción ni convierte un esquema parcial en válido. Cualquier excepción del
probe acotado mantiene el fallo cerrado.

La resolución pública y la administración normalizadas de artículos y
categorías exigen el prefijo ordenado `0001`–`0019`. La migración `0019` es la
frontera de despliegue fail-closed que supersede la postcondición de `0018` y
añade el registro consumido por las copias HTTP; distribuir el código sin
aplicarla no autoriza a degradar la duplicación a una operación no idempotente.
Los gates de capacidades opcionales siguen siendo independientes. El documento
estructurado V1 y sus revisiones requieren `0001+0003+0005`; el editor semántico
V2 suma `0011`.
Los defaults globales de encabezados requieren `0012+0013`; su gate es
opcional y nunca condiciona la disponibilidad del editor ni del Blog anterior.
El workspace privado, sus categorías pendientes y la cabecera de publicación
requieren `0014`; esta frontera también es opcional y, mientras no esté lista,
mantiene intacto el flujo editorial previo.
La selección de imágenes necesita además `0002_webadmin_media_library` en el
scope WebAdmin. `0003_webadmin_media_avif_source` solo amplía el formulario y
el procesador para aceptar originales AVIF; si está pendiente no bloquea Blog
ni los medios ya procesados. La caché LKG exige
el prefijo completo `0001`–`0006`, incluidas las migraciones intercaladas, y su
inicialización explícita. La colección analítica requiere `0001+0009`; su
consulta privada suma `0002+0010` y la capacidad `blog.analytics.view`. Una
migración compuesta
registrada solo retira la postcondición anterior mientras su propio contrato
completo continúe siendo válido.

La adopción de `0019` en un consumidor de referencia se verificó con backup y
restauración reales antes del `--apply`; el catálogo final quedó en `24/24`
migraciones y
cero pendientes. La regresión combinada del corte ejecutó `97` pruebas y
`7.027` aserciones. La prueba MariaDB aislada abrió dos procesos y dos
conexiones para las carreras de replay idéntico y payload incompatible; tres
pasadas consecutivas cerraron `1` prueba/`300` aserciones cada una y el teardown
dejó cero tablas y cero usuarios QA. Esta evidencia técnica no sustituye las
filas visuales de restauración, fallback sin JavaScript y limpieza recuperable
que deben completarse en navegador antes de cerrar la matriz E2E del consumidor.

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
- nuevas plantillas editoriales, presets adicionales y un maquetador libre
  fuera de la estructura semántica y las columnas controladas de V2;
- workflow de aprobación por roles, programación y borrado permanente;
- publicación versionada del workspace privado, preview inmersiva y presentación
  responsive ya integrados en CORE, después de la validación operativa completa
  descrita en el
  [registro del corte](mejoras-pendientes/blog-editor-visual-y-flujo-publicacion.md);
- redirecciones automáticas por cambio de slug;
- traducción mediante IA y generación automática de variantes;
- ampliaciones del medidor SEO, Search Console e Indexing API;
- suscripciones y notificaciones al publicar: requieren consentimiento,
  campañas, un outbox por lotes y un scheduler futuro según el
  [contrato pendiente](mejoras-pendientes/blog-notificaciones-suscriptores.md);
- localización independiente de la interfaz WebAdmin;
- HTML, CSS o JavaScript irrestrictos fuera del parser avanzado acotado de
  `Texto`.

El documento JSON V2, la compatibilidad no destructiva con V1 y V2 anteriores,
sus revisiones, el agregado, las variantes, permisos, URLs y estados forman la
base estable sobre la que se añadirán esas capacidades.
