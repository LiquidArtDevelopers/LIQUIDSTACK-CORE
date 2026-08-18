# Hoja de ruta de WebAdmin y Liquid Blog

Este documento separa lo ya implementado de los siguientes cortes. WebAdmin y
Blog son módulos internos de `liquidstack/core`: un consumidor controlado es el
laboratorio completo de integración, pero rutas, idiomas, copy, datos, medios y
credenciales siempre
pertenecen al proyecto consumidor.

## Matriz de instalación

- Un proyecto sin zona de gestión no requiere ningún selector modular.
- `liquidstack/webadmin` activa autenticación, recuperación de credenciales,
  perfiles, permisos, editores, auditoría y biblioteca de medios.
- `liquidstack/blog` activa WebAdmin como dependencia y suma el dominio Blog.
- La antigua zona privada de negocio del stack no forma parte de WebAdmin y no
  reutiliza sus rutas, sesiones ni modelo de permisos.

Los selectores son lógicos y viven físicamente en CORE. Composer distribuye
código y assets module-managed, pero nunca crea tablas, ejecuta migraciones,
inicializa o modifica `storage`, escribe `.env` ni mueve datos entre entornos.

## Estado implementado

### 1. Acceso y administración base

Implementado:

- superadmin y admin del sitio protegidos y configurados por entorno;
- login, logout, invitación, alta inicial, recuperación y cambio de clave;
- alta, suspensión, reactivación y permisos delegables de editores;
- navegación única de WebAdmin filtrada por capacidades;
- shell compartido de ancho completo para WebAdmin y Blog, con un único
  `main`, navegación lateral e inspector contextual opcional;
- mejora progresiva accesible: sin JavaScript todo permanece en el flujo y,
  una vez enlazado, los drawers sincronizan foco, `aria-*` e `inert`;
- lenguaje visual plano basado en espacio, tipografía, grid y fondos, sin
  bordes laterales de acento, franjas o pseudoelementos decorativos;
- recuperación de contraseña con entrega síncrona sin cola, invitaciones
  mediante outbox one-shot y diagnóstico operativo.

### 2. Biblioteca compartida de medios

Implementada mediante `0002_webadmin_media_library`, con
`0003_webadmin_media_avif_source` como ampliación incremental de entrada y
`0005_webadmin_media_quarantine` para la retirada recuperable:

- subida privada de JPEG, PNG y WebP, más AVIF cuando 0003 está lista, validada
  por firma y decodificador;
- normalización y variantes responsive AVIF sin metadatos;
- cuota, rate limit, hashes, storage privado y entrega autenticada;
- cuarentena, restauración y purga deliberada de medios sin referencias;
- capacidades separadas `webadmin.media.view` y
  `webadmin.media.upload`;
- ALT, title y caption por uso editorial, nunca como metadatos globales del
  asset.

Blog consume esta biblioteca sin apropiarse de ella. DB y storage forman una
unidad de backup y restauración.

### 3. Blog editorial, categorías y etiquetas

El catálogo del scope Blog llega de `0001` a `0025` mediante fronteras
aditivas:

- artículos con variantes por idioma, slug, H1, title SEO, description,
  extracto, estado, preview y publicación independientes;
- categorías localizadas, lock optimista, asignación a artículos y proyección
  pública para filtros y cards;
- etiquetas localizadas por variante: `0020`–`0024` separan vocabulario,
  asignación live, head y workspace privado con CAS, y `0025` aporta
  `blog.tags.view`/`blog.tags.edit` en WebAdmin. Una frontera pendiente conserva
  el Blog anterior y una aplicada pero incompleta falla cerrada;
- índice project-owned por idioma y recursos Blog reutilizables, con diez
  fixtures Matrix solo en showroom para probar rejillas, listado centrado,
  destacados, sliders, filtros combinables y paginación sin insertar fallback
  dummy en la DB pública;
- ownership selectivo de `artBlogArticle01`, `moduleBlogArchive01`,
  `moduleBlogFilters01`, `moduleBlogSearch01`, `moduleBlogCategoryBar01`,
  `moduleBlogPagination01`, `moduleBlogResults01`, `sectionBlogCatalog01`,
  `sectionBlogGrid01`, `sectionBlogList01`,
  `sectionBlogFeatured01`, `sectionBlogRelated01`, `sectionBlogSlider01`,
  `moduleBlogGrid02`, `sectionBlogSlider02` y `sectionBlogStack01`: el
  manifiesto de CORE publica su ecosistema únicamente con
  `liquidstack/blog`;
- sitemap dinámico respaldado por la DB del entorno, sin escribir archivos ni
  necesitar deploy al publicar o retirar una URL;
- canonical, robots, Open Graph, Twitter Card y alternates `hreflang`/`x-default`
  coherentes entre el HTML público y el sitemap, limitados siempre a variantes
  publicadas.

El sufijo `category_assignment_workspace_items` fija en 29 bytes el máximo del
prefijo Blog. Un proyecto configurado con una versión hasta v1.23.0 que hubiera
usado 30–46 bytes debe migrar el namespace explícitamente antes de actualizar;
el runtime no trunca ni renombra tablas MySQL/MariaDB.

### 4. Editor estructurado v1 y constructor V2

Implementado mediante `0005_blog_structured_content` y la biblioteca Media:

- editor privado en `/admin/blog/editor` con documento JSON canónico
  `liquidstack.blog.document`, versión `1`;
- plantillas controladas `article-basic-01` y `article-cover-01`;
- ocho tipos de bloque: párrafo, heading H2-H6, lista, callout, enlace, imagen,
  YouTube y CTA;
- lienzo visual que proyecta `header` y el futuro `main` sin duplicar landmarks
  ni H1 en WebAdmin; H2 abre `section`, H3 abre `article`, H4-H6 permanecen en
  él y las operaciones de orden o borrado conservan subárboles completos;
- H1, title SEO, slug, description y extracto separados del cuerpo;
- locale elegido expresamente entre los activos todavía libres, visible junto
  a su `public_paths` y estable durante toda la edición;
- asignación contextual de categorías del mismo idioma y catálogo Media que
  suma a los recientes cualquier asset ya referenciado por el documento;
- input CSV SSR de etiquetas (0–30) con pastillas progresivas y guardado
  single-flight: coma, Intro, pegado y blur confirman términos, los cambios
  durante una petición se reencolan y la edición exige conjuntamente
  `blog.tags.view` y `blog.tags.edit`;
- guardado progresivo que conserva campos y bloques ante conflictos,
  validación, pérdida de autorización o red, valida estrictamente la
  redirección de éxito y protege cambios pendientes al abandonar la página;
- `body_text` derivado en servidor, nunca enviado como fuente paralela;
- revisiones inmutables y restauración mediante una revisión nueva;
- bloqueo optimista compartido con la variante y escritura transaccional de
  documento, referencias de medios, revisión y auditoría;
- proyección de artículos legacy al abrir, sin adopción hasta guardar;
- renderizado público estructurado con fallback legacy para variantes aún no
  adoptadas;
- imágenes AVIF públicas solo cuando están referenciadas por el documento
  actual de una variante publicada;
- runtime Lite YouTube module-owned que conserva el enlace externo SSR, no
  crea el iframe hasta un clic con consentimiento `cookie_social=true` y lo
  retira si CookieLAD revoca el permiso;
- assets del editor gestionados por el manifiesto del módulo y comprobados por
  `doctor` mediante `blog.assets` y el blocker
  `assets.missing_or_invalid`.

`0011_blog_layout_editor_v2` amplía este corte con Section, Article y Contenedor,
presets visuales de una a cinco columnas, drag accesible y módulos tipados. Texto
unifica párrafos, H2-H6, listas, citas y destacados; HTML/CSS solo se admite en
la variante avanzada saneada o en el módulo HTML. V1 permanece como contrato de
lectura y se proyecta sin reescribir revisiones históricas.

### 5. Entrega pública integrada y rendimiento — implementado

El renderer público ya permite que el proyecto aporte de forma tipada su shell,
head, navegación, footer y recursos de tema mediante una vista confinada a
`App/views`. La CSP y el nonce compartidos se configuran en
`App/config/modules/blog-public.php` y los aplica el controlador a la
`Response`, no la vista. El fallback autónomo conserva SSR, metadatos, cabeceras cerradas y
un CSS neutral responsive gestionado por el módulo. La frontera pública ya
difiere la sesión legacy únicamente para `GET`/`HEAD` reclamados: artículos,
sitemap y medios no crean `PHPSESSID`; el índice project-owned puede usar
`session => false`, mientras rutas ajenas y misses conservan el bootstrap
legacy. Este corte incorpora además:

- `artBlogArticle01` como composición LiquidStack real para
  `article-basic-01` y `article-cover-01`, con cuerpo HTML saneado por CORE,
  intro/retorno inyectables y fallback SSR para consumidores que aún no hayan
  adoptado el shell visual;
- separación SSR compatible: `bodyHtml()` conserva la salida histórica con
  portada, mientras las vistas nuevas requieren
  `_moduleBlogPublicArticle.php` y componen `headerMediaHtml()` antes de
  `mainHtml()` sin duplicar el medio destacado ni el H1;
- taxonomías públicas tipadas mediante `$articleCategories`, `$articleTags` y
  `$articleTaxonomiesHtml`, con grupos informativos separados y sin enlaces a
  rutas o archivos de etiquetas inexistentes;
- una proyección pública unificada y acotada para relacionados por categorías,
  archivo anual/mensual y periodos con recuento, reutilizando el mismo PDO y
  sin exponer borradores;
- los recursos gestionados `sectionBlogRelated01` y `moduleBlogArchive01`,
  junto a su muestra multidioma en showroom;
- la caché last-known-good del sitemap, que cada proyecto puede adoptar cuando
  la necesite. Es opt-in, exige el prefijo Blog completo 0001–0006, storage
  privado inicializado y una comprobación operativa real de locks/rename
  compartidos en producción.
  Solo una conexión DB clasificada como indisponible puede servir un snapshot
  vigente, íntegro y observable; el [contrato vigente](blog-sitemap-last-known-good-cache.md)
  falla cerrado para el resto de errores.

La integración visual es aditiva: actualizar CORE no sustituye de forma
silenciosa una vista, un head o un layout project-owned.

## Siguientes cortes

### 6. SEO editorial avanzado

El primer corte ya está implementado: revisa title, description, H1, slug,
longitud, primeras 100 palabras, jerarquía H2-H6, ALT, repetición mecánica,
concentración de términos y posible canibalización por idioma. Incluye preview
SERP, SSR del estado guardado y análisis reactivo del payload actual. Es
advisory, no persiste una puntuación y no bloquea ninguna transición. Véase el
[contrato del medidor SEO](blog-seo-editorial.md).

Quedan para cortes posteriores el focus phrase persistido, la segregación
temática profunda, datos estructurados editoriales, un read-model para
catálogos canónicos grandes y la asistencia mediante API/IA.

### 7. Traducción asistida

La independencia por idioma ya existe: cada variante conserva slug,
metadatos, documento, revisiones y estado propios. Queda pendiente una
integración IA que genere una nueva variante traducida mediante una acción
explícita. La API y sus credenciales se configurarán por entorno y el resultado
seguirá siendo editable y publicable de forma independiente. El editor actual
no infiere traducciones ni genera variantes implícitas.

### 8. Descubrimiento e indexación

Ya está implementada la búsqueda pública con filtros múltiples de categorías
`any|all`: el soporte gestionado `_moduleBlogPublicIndex.php` crea el
`BlogPublicCatalogQuery`, obtiene las cards mediante
`BlogPublicFeed::cardsForQuery()` y entrega a la vista una proyección tipada; el
formulario GET SSR permanece como fallback. La consulta limita la búsqueda normalizada a 2–120 caracteres
Unicode, diez categorías simultáneas, 50 resultados y un offset de 10.000;
los filtros localizados fallan cerrados si superan el máximo público de 100.
El orden admite exclusivamente `newest`, `oldest` y `updated`, siempre con
desempate estable. `moduleBlogFilters01` añade `fetch` abortable, debounce,
historial y región viva sin convertir JavaScript en requisito.

La misma `q` incluye nombre y slug de etiquetas live del locale mediante
`EXISTS`; nunca lee el workspace privado ni duplica cards. Categorías y
etiquetas se proyectan juntas por lote, sin IDs ni N+1. Este corte no añade
`tag[]`, un selector, archivo, ruta, canonical, hreflang o sitemap de etiquetas:
siguen siendo metadatos informativos y señal de la búsqueda textual existente.

El corte RESOURCE-001 amplía ese contrato en CORE. Search01 separa
búsqueda y orden; CategoryBar01 separa las categorías `any|all`; ambos pueden
componerse sobre la misma región porque conservan el estado del formulario
compañero y comparten el runtime GET/SSR. Pagination01 aporta navegación SSR
sin JavaScript. Grid02 ofrece rejilla regular o bento, Slider02 y el Slider01
actualizado encapsulan GSAP Draggable/Inertia/snap/autoplay por instancia y un
bucle visual continuo sin opción `wrap` para todo conjunto no vacío, incluido
un único artículo. El SSR emite solo los originales; las copias necesarias para
cubrir el viewport son visuales, `inert` y ajenas al árbol accesible. Un
ownership por raíz compartido entre identidades ESM/HMR evita desmontajes
cruzados. Stack01 mejora progresivamente una pila editorial. List01 centra su
columna; Slider01 prioriza miniaturas explícitas y Slider02 limita la media a
`16:9` con `object-fit: cover`, también priorizando `thumbnail` cuando existe.
El showroom mantiene una sola instancia de cada recurso e incluye una
composición real de esos controles con 4 de 10 fixtures por página; las demás
configuraciones quedan en comentarios y tests.
La proyección automática de media ya compone Blog y WebAdmin mediante un
adaptador opcional de como máximo dos consultas constantes por lote: la primera
selecciona portada o primera imagen del documento `CURRENT` publicado y la
segunda solo carga variantes si existe algún medio elegible. Proyecta AVIF sin
IDs ni N+1. El fallback usa la mayor variante de hasta 900 px, el `srcset` queda
ascendente y cada recurso decide su `sizes`; fallos de schema, storage o datos
degradan a una card textual. Los 16 derivados de los fixtures Dummy tienen
fuente gestionada por Composer en `resources/img/dummy/responsive`, cubren 480,
899/900, 1800 y 2560 px y no sobrescriben la media que llegue del feed.

Relacionados y archivo ya pertenecen al corte 5. Continúan pendientes RSS y las
composiciones que excedan la familia actual. La implementación
RESOURCE-001 se integra en CORE principal dentro de `Unreleased`; su publicación
versionada sigue abierta hasta cerrar la matriz final. La QA funcional-visual
previa cubrió Chrome real a 390, 768 y 1280 px,
incluidos filtros coordinados, paginación, varias instancias, teclado,
geometría de media y ausencia de overflow o errores de consola. También se
protegen el primer clic en enlaces durante la coordinación foco/Draggable y la
contención de Related01 en tablet. La regresión técnica final del bucle 0/1/N,
del ownership compartido y de estos bordes supera `212` pruebas y `6.455`
aserciones; la repetición Chrome tras instalar cada corte en un consumidor de
referencia forma parte del gate obligatorio antes de publicar.

- Antes de usar el catálogo en volúmenes altos, sustituir la búsqueda
  `LIKE` sobre H1, extracto y cuerpo por un read model o índice de texto
  completo compatible con MySQL/MariaDB, con límites de coste y frecuencia.
  Los límites actuales acotan entrada y salida, pero no evitan que la DB
  examine el cuerpo de todas las variantes publicadas candidatas.
- Antes de superar 100 categorías con entradas publicadas en un mismo idioma,
  añadir un guard operativo/write-side o una proyección de filtros paginada.
  Hoy las categorías sin entradas publicadas no cruzan los `JOIN` del feed,
  pero un locale que exceda ese techo falla cerrado para no presentar un
  catálogo parcial como si fuese completo.
- Los futuros recursos que admitan varias categorías reutilizarán la semántica
  ya validada `category_mode=any` (cualquier categoría) o
  `category_mode=all` (todas), su allowlist y sus límites.
- Integración con Search Console para observación e inspección y, solo cuando
  el tipo de contenido y la política vigente de Google lo permitan, con una
  API de solicitud de indexación. No se tratará la Indexing API como una API
  general para posts: antes de implementar se verificará su documentación
  oficial y elegibilidad actual. Cualquier petición será separada del sitemap,
  opcional y con reintentos auditables.

El sitemap dinámico ya es la fuente técnica inmediata de URLs. Una petición a
un buscador será complementaria y nunca condicionará la publicación ni se
presentará como garantía de rastreo o indexación.

### 9. Suscriptores y notificaciones de publicación

El MVP actual no registra suscriptores ni envía correo al publicar. Un corte
posterior añadirá consentimiento y baja, campañas idempotentes, un outbox
separado del correo de acceso, lotes y límites acotados, reintentos y un cron
por proyecto. El diseño completo está registrado como
[pendiente](mejoras-pendientes/blog-notificaciones-suscriptores.md).

### 10. Plantillas y ampliaciones del maquetador

- Nuevas plantillas de artículo basadas en recursos de showroom.
- Más composiciones filtradas sobre los feeds ya disponibles y futuros feeds
  como RSS; relacionados y archivo ya tienen proyección y recursos base.
- Vídeo local servido desde una frontera Media segura.
- Nuevos presets, plantillas y módulos tipados sobre el maquetador V2 ya
  existente, sin romper los documentos v1 ni aceptar estructuras arbitrarias.

### 11. Gestión del resto de la web

La biblioteca de medios, identidades, permisos y auditoría de WebAdmin podrán
servir a un futuro editor de contenido estático. Ese trabajo no se mezclará con
el editor inline de desarrollo ni con la zona privada de negocio legacy.

La localización de la interfaz WebAdmin será un contrato separado del locale
del artículo: el panel podrá mostrarse en un idioma mientras se edita otro, sin
usar el idioma de la sesión como filtro implícito de contenido. El modo HTML/CSS
avanzado ya existe como variante encapsulada de `Texto` V2: supersede la fuente
estándar histórica solo para ese módulo, conserva allowlists cerradas y no
sustituye el editor estructurado como flujo normal. Un editor HTML general para
el resto de la web sigue fuera de esta frontera.

## Reglas transversales de entrega

- Cada ampliación usa una migración aditiva e inmutable y un feature gate
  propio. Una migración nueva pendiente no inutiliza funciones ya aplicadas.
- El orden operativo es `doctor` → `migrate --plan` →
  `migrate --dry-run` → backup recuperable de DB y storage → autorización
  expresa → `migrate --apply` → verificación. Composer solo informa y
  distribuye; no ejecuta estos pasos.
- Local, staging y producción usan el mismo código. `RAIZ`, `DEV_MODE`,
  `LIQUIDSTACK_DB_*` y `LIQUIDSTACK_WEBADMIN_MEDIA_STORAGE_ROOT` seleccionan el
  entorno; cambiar valores no adopta, copia ni traslada datos.
- Ningún recurso recibe PDO, prefijos o secretos: consume proyecciones acotadas
  de servicios de aplicación.
- Toda mutación revalida sesión, lifecycle, `auth_version`, CSRF y capacidades
  dentro de la misma transacción que protege los datos.
- Antes de publicar CORE se validan migraciones SQLite/MySQL, checksums
  históricos, actualización aditiva de un consumidor, suite completa y pruebas
  reales en un consumidor de referencia con una ventana aislada del navegador.
