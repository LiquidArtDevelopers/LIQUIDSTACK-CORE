# Changelog

Todas las versiones de `liquidstack/core` siguen [Semantic Versioning](https://semver.org/lang/es/) a partir de la 1.0.0. Documenta cada release en esta cronología y añade instrucciones de actualización visibles para los proyectos cliente.

## [Unreleased]


## [1.35.0] - 2026-09-23

### Añadido

- Nuevos recursos `sectionBlogCatalog02`, `sectionCommerceCatalog02` y
  `sectionCommerceSlider01`, distribuidos con sus controladores, templates,
  estilos, runtime y ejemplos de showroom.
- Commerce incorpora paginación pública de seis elementos con enlaces
  anterior/siguiente y conserva filtros y página en su contrato tipado.

### Cambiado

- Los filtros de Blog y de los dos catálogos Commerce reaccionan con debounce
  a texto, categorías, etiquetas y ordenación, sustituyen resultados y
  paginación sin recargar la página y mantienen el formulario GET como
  fallback sin JavaScript.
- Las familias visuales Blog y Commerce unifican la tipografía de texto y
  controles, mejoran contraste y aire, y eliminan los bordes decorativos de
  las fichas Commerce.
- Los fixtures Matrix de Commerce pueden vestir un catálogo público vacío
  solo con el doble opt-in de desarrollo.

### Corregido

- La navegación suave resuelve correctamente anchors recibidos desde otra URL
  y mejora la coordinación del desplazamiento con hashes.
- `navMegamenu01` hidrata la flecha con su clave prefijada y deja de generar
  claves falsas para los wrappers legales al ejecutar `update-languages.php`.

### Instrucciones de actualización

- Si Commerce ya está seleccionado, ejecuta `composer update liquidstack/core`.
- En un consumidor antiguo sin Commerce, actualiza primero CORE y ejecuta
  después `composer require liquidstack/commerce`; esto no aplica migraciones.
- Revisa `composer liquidstack:sync --plan`, genera el bundle frontend y valida
  Blog/Commerce antes de desplegar.

## [1.34.1] - 2026-09-22

### Corregido

- Commerce deja activa por defecto su superficie pública en proyectos nuevos;
  cada consumidor puede desactivarla expresamente cuando no vaya a utilizarla.

## [1.34.0] - 2026-09-21

### Añadido

- El showroom reconoce `commerce` y distribuye sus hooks PHP, JavaScript y
  SCSS para previsualizar los tres recursos públicos.
- Veinte prendas Matrix localizadas ejercitan catálogo, ficha, taxonomías,
  filtros y solicitud como fixtures visuales, sin DB, WebAdmin ni correo.

### Cambiado

- El catálogo admite hasta veinte elementos por render y el megamenú acepta
  una ruta project-owned exacta para enlaces públicos opcionales.

## [1.33.3] - 2026-09-21

### Corregido

- Commerce vuelve a identificar el idioma de categorías y etiquetas en
  WebAdmin, por lo que los selectores de jerarquía y familia muestran las
  categorías disponibles y la tabla indica correctamente cada locale.
- La lista de interés muestra la confirmación accesible tras registrar una
  solicitud, sin lanzar un error JavaScript al procesar el hash del redirect.

## [1.33.2] - 2026-09-21

### Documentación

- La skill operativa canónica incorpora Commerce: selector y dependencias,
  configuración segura, migraciones, Media, lista de interés, inquiry/outbox
  y el inventario mínimo de QA que recibirán los stacks consumidores.

## [1.33.1] - 2026-09-21

### Corregido

- La migración inicial de Commerce ya es compatible con MariaDB 10.4: la
  prevención de ciclos permanece en el repositorio de dominio sin declarar un
  `CHECK` que MariaDB rechaza por referenciar la clave `AUTO_INCREMENT`.

## [1.33.0] - 2026-09-21

### Añadido

- Nuevo selector lógico `liquidstack/commerce`, dependiente de WebAdmin y
  desacoplado de Blog. Incluye catálogo localizado, productos con estados y
  precio opcional, categorías jerárquicas, etiquetas, atributos tipados,
  portada/galería sobre Media, slugs canónicos con historial y sitemap.
- WebAdmin incorpora gestión de productos, taxonomías, atributos, medios,
  solicitudes y ajustes mediante capacidades, CSRF, optimistic locking y
  archivado seguro. La venta permanece visible como evolución deshabilitada;
  el único modo operativo es la solicitud de información.
- La superficie pública distribuye shells personalizables de catálogo, ficha y
  lista de interés, búsqueda y filtros, fallback localizado y recursos
  responsive. CORE sirve los shells exactos tras un miss del router estático,
  sin exigir duplicar rutas project-owned.
- Lista de interés persistida en servidor mediante cookie opaca necesaria y
  dos correos idempotentes por solicitud —visitante y administración— a través
  de un outbox propio y el comando acotado
  `liquidstack:commerce-mail-dispatch`.

### Seguridad

- El envío revalida productos y consentimiento dentro de la transacción,
  aplica honeypot, comprobación same-origin, idempotencia y límites persistentes
  por HMAC de IP y correo. Los contadores públicos representan solicitudes
  aceptadas y no identidades de usuarios.
- Las imágenes públicas sólo exponen derivados AVIF de Media referenciados por
  productos activos; nunca publican `storage_key`. La cesta no contiene PII,
  no usa storage del navegador y queda aislada por proyecto en localhost.

### Operación y documentación

- Tres migraciones iniciales crean catálogo, interacción/outbox y capacidades
  sin escribir en DB durante Composer. Se documentan configuración, backup,
  migración explícita, Media compartido, worker de correo y activación pública.
- Commerce distribuye 26 ficheros project-owned y tres recursos visuales con
  catálogos ES/EU/EN; las rutas se derivan de los idiomas activos y los fixtures
  requieren doble opt-in de desarrollo.

## [1.32.4] - 2026-09-21

### fixed

- Recurso Megamenu01 corregido lo de RRSS.

## [1.32.3] - 2026-09-21

### fixed

- Recurso ModuleButtonType03 corregido el padding.

## [1.32.2] - 2026-09-21

### fixed

- Comienzo de corrección del bug del smoother

## [1.32.1] - 2026-09-21

### fixed

- Se mejora el readme.

## [1.32.0] - 2026-09-21

### Seguridad
- El parser canónico de la clave WebAdmin rechaza ahora el sentinel público
  `EXAMPLE_ONLY_CHANGE_ME_BEFORE_REAL_USE_0000`, aunque tenga formato base64url
  válido; `doctor` lo declara inválido y el runtime falla antes de abrir PDO.

### Corregido
- Las suites largas de CORE, E2E y MySQL ya no heredan el timeout general de
  300 segundos de Composer. El gate de release comprueba además el historial
  gestionado y el E2E modular. El flujo interactivo detecta la única versión
  pendiente del changelog, falla claramente si falta o es ambigua y solicita
  la descripción del tag; el bloque copiable queda reducido a
  `composer release`.

### Documentación
- Se aclara el contrato de conexión modular: `shared` permanece como fallback
  técnico compatible, mientras BASE y los proyectos nuevos seleccionan
  explícitamente `liquidstack` con un único bloque `LIQUIDSTACK_DB_*`.
- Se documenta que el host es el endpoint visto desde cada runtime, sin esquema
  ni puerto, que CORE solo consume el entorno ya resuelto y que una DB remota
  requiere una red confiable o un túnel mientras PDO no tenga contrato TLS/CA.

## [1.31.3] - 2026-09-15

### Añadido
- Nueva skill distribuida `liquidstack-github-actions` para crear y auditar
  pipelines de build/deploy sin mezclar el artefacto con secretos, DB o storage
  runtime, con patrones separados para releases, in-place y sync con borrado.
- Runbook operativo distribuido para promocionar DB y Media a producción como
  una unidad verificable, con inventario, copia inicial, QA y rollback.

### Corregido
- El storage privado de Media y de la caché LKG del sitemap admite ahora su
  ruta canónica bajo `project/storage/liquidstack/...` en producción cuando se
  declara explícitamente, queda fuera del document root efectivo y el deploy la
  preserva. El solapamiento directo o inverso con la raíz pública sigue fallando
  cerrado.

### Seguridad
- La guía deja explícito que `.gitignore` no protege Media frente a Actions y
  exige una raíz productiva absoluta y persistente fuera del deploy. El deploy
  ordinario no puede ejecutar migraciones, onboarding ni copiar o borrar datos.

## [1.31.2] - 2026-09-15

### Añadido
- Nueva skill distribuida `liquidstack-deployment` para crear y auditar
  workflows de consumidores sin omitir el build que genera sitemap/robots,
  y para diferenciar esos artefactos estáticos del sitemap dinámico del Blog.

## [1.31.1] - 2026-09-15

### Corregido
- El sitemap dinámico del Blog excluye ahora las variantes publicadas con
  `noindex`, mantiene las variantes `index,nofollow` y conserva el valor por
  defecto compatible cuando una localización aún no tiene una fila explícita
  de preferencias de robots. Las filas incoherentes se omiten de forma segura
  y la nueva identidad de caché impide reutilizar snapshots anteriores al
  cambio de elegibilidad.
- El instalador reconcilia de forma aditiva un bloque delimitado en
  `public/.htaccess` para que `sitemap.xml` y `robots.txt` no hereden una caché
  prolongada. Las reglas propias del proyecto se preservan y ambos documentos
  pasan a exigir revalidación HTTP.

## [1.31.0] - 2026-09-14

### Añadido
- Nueva skill distribuida `liquidstack-content-localization`, con circuitos
  separados para catálogos estáticos y variantes por locale del Blog, además
  de validación SSR, SEO, enlaces, medios, taxonomías y publicación.

### Corregido
- Las listas públicas del Blog recuperan viñeta o numeración nativas, marcador
  corporativo, tipografía del cuerpo y un ritmo vertical responsive uniforme,
  tanto en contenido estructurado como en Texto avanzado o HTML seguro.
- El editor de Texto avanzado permite retirar por completo el CSS personalizado
  —también si solo quedan espacios o saltos de línea—, canoniza el valor vacío
  en cliente y servidor y devuelve el bloque al flujo estructurado cuando su
  HTML es compatible, sin relajar el rechazo del CSS no permitido.

## [1.30.0] - 2026-09-14

### Añadido
- `hero01` admite una capa de imagen editable mediante el opt-in
  `with_image`; conserva por defecto su composición histórica y documenta un
  objeto dummy completo en los catálogos ES, EN y EU.

### Corregido
- El editor inline prioriza el contrato de fondo cuando el gesto se realiza
  sobre su target visual, aunque el `<img>` tenga `data-lang`. Así `hero00`
  vuelve a editar conjuntamente mobile, tablet, desktop y fallback sin
  interceptar el H1 o el copy del mismo recurso.

### Cambiado
- El entrypoint del showroom de héroes vuelve a separar y comentar el bloque
  copiable de parallax de `hero00`, incluida su limpieza HMR, y elimina el
  intercambio CSS de fondos que ya sustituye el `<picture>` nativo.
## [1.29.0] - 2026-09-10

### Añadido
- Nuevo comando `composer liquidstack:sync` con catálogo `--plan`, preview
  `--dry-run` estrictamente de solo lectura y aplicación confirmada mediante
  `--apply --plan-hash=... --yes`. La salida usa exclusivamente IDs relativos
  y códigos estables.

### Cambiado
- La skill canónica de migración descubre CORE, consumidor y `vendor-dir`
  desde Git y Composer; ya no presupone una ubicación local o XAMPP.

### Seguridad
- La aplicación recupera primero transacciones interrumpidas, vuelve a
  calcular el plan bajo el lock del proyecto y rechaza hashes obsoletos antes
  de aplicar nuevas mutaciones. La nueva superficie conserva las políticas y
  grupos atómicos existentes y no introduce borrados, renames, migraciones,
  cambios de entorno ni otras mutaciones de lifecycle.
- El preflight bloquea estado, historial, catálogos JSON, roots autorizados o
  scaffolds transaccionales inválidos; liga el hash al proyecto, destinos y
  contrato SCSS, y evita renames atómicos externos entre filesystems.
- La limpieza de journals valida su layout, conserva el marcador ante carreras
  de cierre y retira únicamente slots regulares mediante `unlink`, sin recorrer
  symlinks, junctions ni directorios. Los bindings se congelan durante el apply
  y se revalidan antes de cada mutación; un `committed` terminal nunca revierte
  estado ni condiciona el cleanup al contenido posterior del consumidor.
- Las mutaciones independientes usan el mismo journal transaccional que los
  grupos, sustituyen ficheros mediante promoción en vez de escritura in-place y
  no propagan cambios a hard links externos. Los paths físicos se congelan en
  memoria preservando el casing operativo, las colisiones que aparecen tras
  retargetear un alias bloquean el plan y los cambios concurrentes de junction
  se restauran sobre el destino originalmente autorizado.

## [1.28.2] - 2026-09-10

### Corregido
- El sincronizador consulta también el historial canónico cuando el estado de
  instalación está desfasado. Una copia histórica exacta puede así actualizar
  todo su grupo y añadir dependencias nuevas, mientras un cambio local
  desconocido continúa preservándose.
- `navMegamenu01` ya no contiene una sede real como fallback: conserva el
  parámetro `offices`, pero sin datos explícitos parte de una colección vacía.
  Admite además enlaces públicos project-owned mediante `public_link_keys` y
  permite ocultar el acceso privado heredado con `show_private_access=false`.
  Las redes sociales incompletas y la imagen opcional de `footerInfo01` se
  omiten en vez de generar enlaces o imágenes que apunten a la raíz. Los
  ejemplos de dominio de los helpers usan ahora `example.com`.
- El integrador del head reconoce también el contrato moderno que escapa los
  dos orígenes dinámicos de Vite y mantiene el atributo de nonce CSP en ambos
  scripts. Las apariciones parciales, adicionales o sin el escaper canónico
  continúan difiriendo la migración de `lad`.
- Los textos legales aceptan `VITE_BUSINESS_ADDRESS` como nombre canónico de
  la dirección y conservan `VITE_BUSINESS_ADRESS` únicamente como alias
  retrocompatible.
- El runtime legal y la pareja controlador/template de `footerInfo01` pasan de
  semillas a gestión por huella. Las copias canónicas actuales o históricas se
  actualizan, las personalizadas se preservan y el footer se aplica como un
  único grupo atómico para no mezclar contratos incompatibles.
- El catálogo inicial del showroom identifica sus ejemplos como LiquidStack,
  elimina copy heredado de clientes o clubes y referencias sectoriales
  heredadas, y usa el dominio reservado `example.com` para enlaces y correos
  ficticios propios del proyecto; los embeds de proveedores conservan sus
  dominios técnicos.

## [1.28.1] - 2026-09-09

### Corregido
- El shell público de Blog carga ahora los módulos H1 usados por `hero00`,
  `hero06` y `hero07`, y presenta autor, rol y fecha como una firma legible que
  no compite por espacio con el contenido del hero. El fallback standalone
  aplica el mismo contrato visual.
- Se elimina whitespace residual de `art11` para que las actualizaciones de
  consumidores mantengan limpio `git diff --check`.

## [1.28.0] - 2026-09-09

### Añadido
- `art02v1` independiza la variante de perfiles fotográficos de `art02` con
  avatares circulares, tarjetas adaptables, CTA opcional y prefijo de idiomas
  propio.
- Blog distribuye un shell público neutral y personalizable para el detalle de
  artículo mediante `App/views/blog-article.php`, `src/js/blogArticle.js` y
  `src/scss/blogArticle.scss`. Las tres piezas se sincronizan como un grupo
  atómico: avanzan mientras conservan una huella conocida y se preservan juntas
  cuando el proyecto personaliza cualquiera de ellas.
- El nuevo comando `composer liquidstack:blog:adopt-public-shell` comprueba en
  solo lectura que el scaffold y sus dependencias project-owned están completos.
  El flujo productivo mantiene el fallback standalone durante ese preflight y
  `npm run build`, exige que el manifest contenga `src/js/blogArticle.js` y solo
  después permite la variante confirmada `--apply --yes`, que crea una
  configuración Blog mínima cuando no existe o añade
  exclusivamente `public_article_view` a un `return` literal reconocido; una
  configuración dinámica o incompatible se conserva intacta y recibe la entrada
  manual necesaria.

### Cambiado
- `art05v1` muestra la imagen completa mediante `contain`, adopta un borde
  neutral y aplica el color de encabezado también cuando se inyecta un
  encabezado externo.
- `artAccordion02` adopta como diseño canónico una superficie más contenida,
  imagen sin recorte, acordeones sin sombra y encabezados con mayor peso, sin
  depender de modificadores de instancia ni de colores privados del proyecto.
- `liquidstack:doctor` distingue ahora un Blog `standalone` de un shell
  `project` completo. El fallback sigue siendo compatible y operativo, pero
  produce un aviso accionable; una vista configurada sin hook, dependencias
  globales, catálogos activos, head compatible con metadata/nonce, entrypoint o
  bundle Vite requerido falla de forma visible. Si detecta el loader de
  CookieLad, valida también sus autorizaciones CSP exactas sin exponerlas.

## [1.27.0] - 2026-09-09

### Añadido
- `art05v1` convierte la variante fotográfica de tarjetas probada en ARRO en
  un recurso autónomo, multiinstancia y personalizable, con una, dos y tres
  columnas según viewport y últimas filas centradas.

### Cambiado
- `art05` acota el tamaño fluido de los encabezados de ficha para mantener su
  jerarquía visual con copys largos.
- `sectionBlogSlider02` reduce el tamaño de los títulos de tarjeta y añade
  respiración vertical responsive al viewport del carrusel.

## [1.26.2] - 2026-09-06

### Cambiado
- Mejora de los estilos del art11

## [1.26.1] - 2026-08-26

### Cambiado
- La skill canónica `liquidstack-module-operations` incorpora un preflight
  Windows para identificar el PHP efectivo antes de Composer, `doctor` o
  `npm run lad`. Separa los requisitos base de WebAdmin de los de Media,
  documenta el override de proceso `LIQUIDSTACK_DEV_PHP_BINARY` y evita tratar
  fallos de extensiones o `php.ini` como migraciones pendientes.

## [1.26.0] - 2026-08-20

### Cambiado
- `npm run lad` aísla ahora las cookies WebAdmin autenticada, preautenticada y
  de acción con una identidad opaca estable por directorio de proyecto. Varios
  stacks `localhost` pueden conservar sesiones simultáneas aunque sus puertos
  PHP/Vite cambien entre arranques; producción mantiene los nombres de cookie
  configurados existentes.
- Gestión Blog incorpora una columna SEO calculada sobre la última instantánea
  editorial guardada de cada variante. Resume en porcentaje las once
  comprobaciones on-page del medidor, sin persistir resultados ni incluir la
  canibalización, y presenta de forma accesible los niveles rojo 0–49, naranja
  50–79 y verde 80–100 como una cifra porcentual plana. Index y Follow usan
  checks centrados con el mismo verde, y las acciones de cada fila reservan
  espacio para sus cinco controles posibles sin partirse. La proyección se
  resuelve en lote y un fallo del diagnóstico deja la celda como no disponible
  sin romper el listado.

## [1.25.0] - 2026-08-20
### Cambiado
- `npm run lad` adopta un supervisor de desarrollo gestionado que inicia PHP
  desde 1309 y Vite desde 5173, eligiendo de forma incremental el siguiente
  puerto libre para cada servicio. Los overrides
  `LIQUIDSTACK_DEV_APP_PORT` y `LIQUIDSTACK_DEV_VITE_PORT` son exactos y
  fallan si no pueden utilizarse. El proceso inyecta `RAIZ` y los orígenes
  efectivos de aplicación/Vite sin persistir los puertos elegidos en los
  perfiles `.env*`; el `swap-env` previo conserva su activación habitual del
  perfil de desarrollo. Mantiene el router PHP canónico y, al cerrarse,
  detiene únicamente los dos servicios que ha creado.
- `composer release` exige que la versión propuesta tenga una sección fechada
  propia en `CHANGELOG.md` antes de ejecutar validaciones, crear la etiqueta o
  publicar el push atómico. Una entrada olvidada en `Unreleased` bloquea ahora
  el release con una instrucción explícita y sin modificar Git.

## [1.24.0] - 2026-08-18
### Cambiado
- Blog incorpora etiquetas localizadas por variante, independientes de las
  categorías post-wide. El editor admite de cero a treinta nombres mediante un
  campo CSV funcional sin JavaScript y una mejora progresiva de pastillas con
  coma, Intro, pegado, IME, guardado single-flight y CAS propio; los cambios de
  categorías y etiquetas que llegan durante una petición se reencolan sin
  pérdida y `Publicar` espera y promociona atómicamente documento, categorías y
  etiquetas. Las migraciones aditivas `0020`–`0025` separan vocabulario,
  asignación live, heads, workspace privado y capacidades `blog.tags.view` /
  `blog.tags.edit`; mientras estén pendientes el Blog anterior sigue operativo,
  y una frontera aplicada pero corrupta falla cerrada en runtime y doctor.
  Nombres y respuestas se canonizan en NFC mediante un polyfill portable,
  rechazan controles invisibles peligrosos y conservan ZWJ para emoji.
- La búsqueda pública `q` encuentra también el nombre o slug de etiquetas live
  sin añadir parámetros, filtros, archivos ni URLs nuevas. Cards y artículo
  reciben una proyección tipada sin IDs y en una consulta batch, pero las APIs
  legacy conservan su shape; el detalle presenta categorías y etiquetas como
  grupos informativos separados y no interactivos. Duplicar una variante copia
  las etiquetas efectivas del mismo locale, añadir un locale comienza vacío y
  borrador, papelera y retirada nunca filtran un workspace privado al público.
- El prefijo de tablas Blog queda limitado a 29 bytes para que
  `category_assignment_workspace_items` respete el máximo de 64 bytes de
  MySQL/MariaDB. Los proyectos configurados con versiones hasta v1.23.0 que
  adoptaron prefijos de 30–46 bytes necesitan una migración explícita del
  namespace antes de actualizar; CORE falla temprano y no trunca ni renombra
  tablas.
- `art11` expone cada cifra animada y su sufijo como dos claves `data-lang`
  agrupadas solo en desarrollo, de modo que el editor inline permite modificar
  conjuntamente `target.value` y `suffix.text`. El contador anima únicamente
  el nodo numérico, relee el objetivo vigente, refleja los guardados sin
  destruir el sufijo y limpia tweens, `ScrollTrigger` y observadores al
  reinicializarse o durante HMR.
- El editor visual de Blog muestra al final de su barra inferior el estado
  oficial `Borrador` o `Publicado`, con texto, indicador y color accesibles. El
  estado se deriva de la variante persistida, permanece `Publicado` al guardar
  un workspace privado y solo cambia en cliente después de validar una
  publicación asíncrona correcta; el inspector se sincroniza en la misma
  operación.

## Historial acumulado hasta 1.23.0 (sin segmentar)

Las entradas siguientes ya estaban presentes en `v1.23.0`, pero el changelog
histórico no conserva su asignación exacta por release. Se mantienen sin
atribuirlas a una versión concreta.

### Cambiado
- WebAdmin incorpora el cierre explícito `liquidstack:webadmin:onboard`: valida
  la configuración local de correo y las migraciones antes de crear las dos
  identidades protegidas, entrega
  únicamente sus invitaciones y solo finaliza correctamente cuando ambas
  cuentas están activas o disponen de un enlace entregado y vigente. El flujo
  es idempotente, no se ejecuta como efecto lateral de Composer, no publica
  identidades privadas en CORE y mantiene el reenvío de enlaces caducados o
  fallidos como una operación separada y confirmada.
- `moduleBlogGrid02` omite la limpieza GSAP cuando la rejilla no contiene
  tarjetas. Los estados vacíos, los reemplazos reactivos, reduced motion y el
  cleanup/HMR dejan de invocar `gsap.set([])` y ya no generan el aviso
  `GSAP target not found`; una regresión cubre tanto el vacío inicial como la
  sustitución de una rejilla vacía desconectada.
- El índice público del Blog dispone ahora de una fachada tipada y reutilizable
  en `src/Core/Blog/PublicIndex`: valida request y rutas, reutiliza una sola
  fachada/feed por request para filtros, archivo, resultados y paginación, y proyecta
  estados HTTP/SEO sin generar HTML. El soporte gestionado de un solo require
  `App/app/_moduleBlogPublicIndex.php` resuelve también CSP, redirects, HEAD y
  extensiones project-owned antes del `DOCTYPE`; las vistas consumidoras quedan
  reducidas a HTML neutro y snipers incondicionales, mientras cada recurso
  decide su estado vacío y posee sus estilos. El H1 del índice se compone en
  un recurso hero con raíz `<header>` antes de `<main>`, y
  `sectionBlogCatalog01` agrupa bajo su H2 la búsqueda, las categorías y la
  región de resultados como slots hermanos. `moduleBlogResults01` compone
  colección, paginador y archivo mediante slots opcionales y es propietario
  del target reactivo singleton, que ya no se escribe como `div` crudo en la
  vista. `moduleBlogGrid02`, Pagination01 y Archive01 conservan sus hooks y
  estilos sobre raíces siempre neutras; las cards continúan como
  `<article>`/H3 y Grid02 añade un CTA localizado, visible y sin sombra junto
  a un título que conserva el tamaño del heading. La rejilla regular mantiene
  tres cards iguales por fila en desktop —también con 3, 9 o 15 resultados— y
  centra únicamente los restos reales de una o dos cards. Archive01 recupera
  un ancho de contenido autocontenido y centra sus filas 1/2/N sin depender de
  la vista. Search01 y CategoryBar01 incorporan un padding responsive más
  generoso; CategoryBar01 amplía también la separación entre grupos, chips y
  acciones sin estrechar los controles en móvil. El compositor rechaza
  landmarks anidados. GET, HEAD y la
  variante parcial conservan paridad de status, cache,
  robots y canonical; los fallos de
  runtime/PDO degradan a 503 recuperable. `public_index` configura el tamaño de
  lote y las rutas limpias por locale, mientras la paginación mantiene enlaces
  SSR y mejora progresiva sin recarga. El adaptador usa el grupo independiente
  `public-index-support`, de modo que una personalización visual no bloquea su
  distribución.
- `sectionBlogSlider01` y `sectionBlogSlider02` eliminan la opción pública
  `wrap`: todo conjunto no vacío forma un carril visual continuo cuando la
  mejora JavaScript es elegible, incluido un único artículo. El SSR conserva
  una sola copia semántica de cada card y el runtime replica conjuntos a ambos
  lados hasta cubrir el viewport; esas copias son `inert`, `aria-hidden`, no
  retienen IDs, IDREF, claves de card ni elementos enfocables y se desmontan al
  limpiar. Un propietario por raíz compartido mediante `Symbol.for` evita que
  dos identidades ESM/HMR se destruyan entre sí y provoquen parpadeos. Se
  distingue además el foco nacido de un `pointerdown` del foco de teclado: el
  primer clic limpio sobre el título o CTA ya no recentra el carril ni se
  cancela como si fuese un arrastre, mientras un drag real sigue suprimiendo
  la navegación accidental. `sectionBlogRelated01` usa una caja acotada
  (`border-box` y `min-width: 0`) para que su padding y contenido flexible no
  ensanchen el grid del artículo en tablet; su miniatura queda contenida en una
  caja `16:9`, sin margen intrínseco y con `object-fit: cover`, incluso cuando
  el medio de origen es vertical, panorámico o cuadrado. Se conservan Draggable, Inertia,
  snap, autoplay, controles, teclado,
  multiinstancia y cleanup; cero resultados sigue siendo vacío y los fallbacks
  sin mejora conservan los originales SSR. La regresión de recursos queda en
  `212` pruebas y `6.455` aserciones; la comprobación Chrome posterior a cada
  reinstalación en un consumidor de referencia forma parte del gate previo a
  publicar el corte.
- Se incorpora la skill genérica `$test-functional-ui` para cerrar cualquier
  interfaz funcional con dos capas inseparables: pruebas técnicas y exploración
  adversarial de todos los recorridos de usuario en navegador real. La guía se
  distribuye automáticamente a los stacks consumidores y exige tantear usos
  habituales, alternativos, incorrectos e inesperados sin confundir cobertura
  de recorridos con una promesa de ausencia absoluta de defectos desconocidos.
- CORE amplía la familia pública Blog con `moduleBlogGrid02` regular/bento,
  `sectionBlogSlider02` multiinstancia, `sectionBlogStack01` y los controles
  combinables `moduleBlogSearch01`, `moduleBlogCategoryBar01` y
  `moduleBlogPagination01`. Search01 y CategoryBar01 conservan mutuamente
  búsqueda, orden y categorías sobre el mismo runtime GET/SSR; Pagination01
  conserva enlaces SSR y añade sustitución parcial progresiva con history,
  popstate, timeout y fallback nativo. `sectionBlogList01` centra su columna editorial
  y `sectionBlogSlider01` incorpora miniaturas explícitas y la experiencia GSAP
  de `artSlider01`: Draggable, Inertia, snap, bucle visual continuo, autoplay
  pausable, teclado,
  varias instancias, fallbacks accesibles y cleanup completo. Slider02 mantiene
  esa interacción —6 s de espera y 2 s de transición por defecto— y limita cada
  media a `16:9` con `object-fit: cover`, priorizando `thumbnail` cuando llega
  proyectada. Stack01 usa ScrollTrigger solo con 3–8 cards y un viewport apto;
  Grid02 limita también su media a `16:9`/`16rem`, prefiere `thumbnail` y revela
  de forma incremental los nuevos lotes. El showroom incluye una
  composición paginada que muestra 4 de 10 fixtures Matrix por página.
  `BlogPublicResourceQuery` incorpora el orden cerrado
  `newest|oldest|updated`, proyecta categorías en una consulta batch sin IDs ni
  N+1 y `BlogPublicResourceBatch` transporta items y continuación. Un loader
  module-owned mejora el enlace SSR con HTML same-origin, modos manual/near-end,
  deduplicación, estados y aborto seguro. El helper acepta categorías y media
  explícitas y valida estrictamente `src`, `srcset` y `sizes`, aplicando el
  `sizes` específico de cada recurso. La media automática del feed usa ya un
  adaptador batch opcional Blog+WebAdmin con un máximo de dos `SELECT` constantes
  por lote: el primero inspecciona documentos y referencias y el segundo solo
  carga variantes cuando se ha elegido algún medio. Selecciona la portada o
  primera imagen del documento `CURRENT` publicado y proyecta AVIF sin IDs ni
  N+1. Usa como `src` la mayor variante de hasta 900 px y
  un `srcset` ascendente, dejando `sizes` fuera del backend; un esquema o storage
  no preparado y cualquier dato corrupto degradan solo a la card textual. Los
  16 derivados Dummy del showroom —480, 899/900, 1800 y 2560 px— tienen fuente
  gestionada por Composer en `resources/img/dummy/responsive`, se sincronizan al
  consumidor y respetan cualquier miniatura que ya entregue el feed. La QA
  funcional-visual en Chrome real queda cerrada a 390, 768 y 1280 px; corrigió
  además la altura intrínseca de Grid02 y aisló Pagination01 del `nav` global.
  RESOURCE-001 quedó versionado en CORE desde `v1.22.0`.
- Duplicar un artículo o añadir un locale crea un borrador privado con lock
  inicial, documento actual y revisión propia número `1` cuando la fuente es
  estructurada, sin clonar publicación ni historial. El duplicado independiente
  toma las categorías del workspace post-wide privado o, si no existe, de la
  relación live; el nuevo locale comparte la asignación de su agregado. Un
  workspace editorial stale, incompatible o corrupto falla cerrado. La nueva
  migración aditiva `0019_blog_copy_operation_idempotency` registra la intención
  completa y hace seguro el replay o doble submit: el mismo `operation_id` y
  payload devuelve el destino original, mientras una reutilización incompatible
  responde con conflicto contextual sin crear un segundo borrador. Cada opción
  de idioma dispone de su propio UUID y se resincroniza en `pageshow` para
  BFCache; si el destino de un replay ya está en Papelera, la operación responde
  `409` contextual sin duplicar ni degradar a indisponibilidad.
- El catálogo editorial retira el módulo independiente `Enlace`; su lectura
  histórica se proyecta sin pérdida a un `Botón` primario y los enlaces inline
  continúan dentro de `Texto`. `Botón` incorpora alineación izquierda, centrada
  o derecha. El módulo `HTML` comparte el editor de fuente y expone únicamente
  HTML/CSS. HTML y Texto avanzado admiten iframes saneados de YouTube, Vimeo y
  Google Maps: el SSR los mantiene inertes y el runtime solo los materializa
  con consentimiento social CookieLad, retirándolos al revocarlo.
- Hero00 usa media responsive `<picture>/<img>` sin estilo inline, conserva un
  único parallax sobre `.hero00-media` con movimiento reducido y permite
  encuadrar la portada arriba, al centro o abajo desde el selector multimedia.
  Imagen añade altura automática o 5–100dvh, cover/contain, posición vertical,
  radio 0–50% y overlay seguro con modo, color corporativo o libre y opacidad.
- `Texto` representa cada Intro sobre una línea vacía como un nodo
  `{"type":"break"}` y un `<br>` raíz persistente. Escribir en la línea activa
  sustituye solo ese salto por un `<p>`; `Ctrl+Intro` conserva el `<br>` inline.
  Visual, HTML tipado y avanzado, SSR y la proyección V1 preservan posición y
  multiplicidad sin crear `<p></p>`.
- Las nuevas altas editoriales unifican Párrafo, Título H2-H6, Lista, Cita y
  Destacado dentro de un único módulo `Texto`. Los nodos independientes legacy
  se proyectan uno a uno en memoria al abrir; cancelar no escribe, mientras que
  guardar, restaurar, duplicar o copiar un locale persiste ya la forma unificada
  sin modificar revisiones históricas. Visual incorpora selector de bloque e
  iconos accesibles de cita/destacado. Intro crea la siguiente unidad semántica;
  dos pulsaciones al final de una lista salen al flujo raíz y escribir convierte
  únicamente la línea activa en párrafo, mientras `Ctrl+Intro` inserta un `<br>`
  en la unidad actual. HTML y
  CSS ofrecen sugerencias contextuales navegables con teclado a partir de las
  allowlists del saneador. Un `Texto`
  tipado o su HTML avanzado admiten hasta 200.000 bytes, frente al máximo global
  de 300.000 bytes del documento; CSS conserva su límite independiente de
  30.000 bytes. Las listas quedan acotadas a cuatro niveles y usan una sangría
  moderada; las citas se presentan con línea izquierda y cursiva, y los
  destacados con fondo sutil, sin borde y con padding.
- Artículo y Contenedor permiten cambiar visiblemente entre presets de una a
  cinco columnas, con proporciones controladas, guías punteadas traslúcidas y
  acciones `+` en columnas vacías. Aumentar columnas conserva el contenido en
  la primera; reducirlas reúne los hijos por orden visual sin perder UUID ni
  configuración. Los módulos pueden moverse entre columnas mediante arrastre o
  destinos de teclado, manteniendo Subir/Bajar como fallback y el apilado
  responsive sin overflow.
- El editor de `Texto` unifica una experiencia Visual/HTML/CSS sin pérdida:
  controles SVG y paletas de muestras, fuente Dark Modern con ajuste de líneas,
  gutter medido, pares automáticos, sangría de cuatro espacios e historial
  propio para escritura, IME y operaciones estructurales. El HTML avanzado que
  puede proyectarse al flujo de párrafos, H2-H6, listas, citas y destacados, y
  el CSS meramente presentacional, siguen editables en Visual. Los atributos
  seguros de esos contenedores, incluida una clase propia, se preservan al
  aplicar formato inline, dividir con Intro o transformar entre P, H2-H6, Cita
  y Destacado. El CSS presentacional seguro se aplica directamente al único
  canvas editable, con nonce CSP y scope exclusivo; ya no existe una segunda
  vista ni un segundo scroll. Las listas con estructura o atributos complejos y
  el CSS sensible al layout permanecen cerrados a cambios estructurales desde
  Visual y se editan en preview sandbox y fuente. El canvas ya representa el
  contenido avanzado, color y negrita se componen sin crear párrafos vacíos y cancelar o
  deshacer hasta el origen conserva exactamente la fuente y el JSON. Los
  selectores seguros pueden declararse antes de existir en el HTML y el iframe
  inline usa un nonce nuevo por respuesta, coordinado con las CSP del padre y
  del `srcdoc`, sin conceder permisos al sandbox.
- La toolbar de Texto respeta `hidden` aunque su layout base sea flex y mantiene
  invisible el `select` nativo de tamaño también cuando está deshabilitado, por
  lo que no vuelve a superponer su etiqueta o flecha sobre el icono SVG.
- La proyección V2→V1 de un `Texto` avanzado conserva cada LF como `break`,
  divide líneas largas solo en límites UTF-8 válidos y falla cerrada al superar
  el máximo de nodos. El texto visible de compatibilidad ya no puede divergir
  del que valida el borrador estructurado.
- La preview visual privada vive en `/admin/blog/editor/preview`; la ruta legacy
  `/admin/blog/posts/preview` se identifica como lectura textual sin medios ni
  estilos. La preview moderna abre de inmediato su estado de guardado, mantiene
  el iframe sin `src` hasta confirmar el snapshot y valida URL same-origin,
  marcador SSR, presencia de hojas públicas y carga completa de todas ellas
  antes de declarar éxito. Sus stylesheets son bloqueantes antes de
  `customCss()` y el CSS avanzado recibe un nonce coordinado con la CSP. Login,
  error HTTP, marcador/hoja ausente o timeout de `12 s` fallan cerrados, ocultan
  el iframe y deshabilitan los dispositivos; cerrar o reabrir cancela timers y
  callbacks obsoletos.
- Los gates HTTP de Blog y WebAdmin sustituyen las cadenas de postcondiciones
  exhaustivas por registros de migración/checksum, probes de tablas y columnas
  mediante consultas de cero filas e invariantes operativas acotadas. Las
  auditorías completas de DDL, índices, claves, triggers, semillas y filas se
  conservan sin cambios en migraciones, `--dry-run` y `doctor`.
- Los encabezados estructurados proponen H2 en sección y H3 en artículo o
  contenedor, pero permiten elegir H2-H6 —nunca H1— y conservan el nivel real en
  validación, preview y SSR. Las guías de sección añaden una sangría responsive
  exclusiva del constructor para que sus hijos no queden a sangre.
- La columna Estado de Gestión Blog combina texto oscuro con un LED editorial:
  verde con un pulso de tres segundos para `Publicado`, ámbar fijo para
  `Borrador` y rojo fijo con
  `Eliminado` en la nueva columna de la papelera. El icono es decorativo, el
  texto evita depender del color y `prefers-reduced-motion` detiene el pulso.
- Gestión Blog y su papelera muestran `Actualizado` con fecha corta
  `dd/mm/aaaa`, manteniendo la hora localizada y la zona IANA del perfil. La
  firma pública del artículo conserva deliberadamente la fecha larga según su
  locale.
- El editor Blog intercepta el guardado progresivo antes de cualquier
  sincronización o validación, conserva el fallback SSR sin JavaScript y elimina
  por completo `beforeunload`, `window.confirm` y los avisos nativos. Los enlaces
  internos y el logout con cambios pendientes usan ahora un diálogo LiquidStack
  accesible para guardar, descartar o continuar; los fallos y excepciones
  mantienen el formulario, el foco y la URL.
- Blog añade `0018_blog_dummy_category_normalization`: conserva categorías y
  relaciones históricas, pero incorpora idempotentemente el UUID Dummy canónico
  a toda asignación viva o workspace ligada a un slug legacy exacto `dummy`.
  Gestión Blog excluye esa identidad antes de filtros, orden y paginación, sin
  opt-out, y los gates privados y públicos fallan cerrados hasta completar la
  normalización.
- El planner de migraciones puede reanudar de forma auditable un superseder
  `retrySafe` cuyo DDL MySQL/MariaDB quedó confirmado antes de fallar un
  verificador: exige el superset exacto, registros anteriores íntegros y drift
  de un verificador ya supersedido; la migración sigue pendiente hasta
  reejecutar su SQL idempotente y verificar antes de registrarla. El verificador
  Blog 0015 acepta además los tipos con display width de MariaDB y cualifica sus
  metadatos de claves foráneas.
- La sincronización gestionada tolera los handles temporales que Vite, PHP u
  otros watchers pueden mantener sobre backups ya confirmados en Windows. El
  cleanup hace reintentos acotados y, si el bloqueo persiste, queda marcado
  como `cleanup_pending` para retomarlo sin abortar Composer ni impedir que el
  mismo recurso avance a una versión posterior; los rollbacks pendientes
  mantienen su validación estricta.
- Blog añade acciones editoriales atómicas: duplicación completa como borrador
  y papelera recuperable mediante las migraciones append-only `0007`/`0008` y
  `blog.articles.delete`. Documento actual, referencias y categorías se copian
  con medios revalidados; trash/restore usan CSRF, capacidades, auditoría y
  bloqueo optimista. Las variantes publicadas deben retirarse antes y no se
  incorpora purge ni borrado permanente. La duplicación conserva compatibilidad
  antes de `0007`, mientras la superficie de papelera queda feature-gated.
- Blog incorpora analítica first-party opcional, desactivada por defecto y
  condicionada a CookieLAD. Las migraciones append-only `0009`/`0010` añaden
  sesiones y vistas pseudónimas y la capacidad `blog.analytics.view`, sin
  persistir IP, User-Agent, referrer ni la sesión WebAdmin. El tracker se carga
  desde un asset separado solo con marcador SSR y `cookie_analytics=true`,
  mide tiempo visible/en foco, limpia identidad al revocar y usa endpoints
  POST exactos pre-bootstrap sin `PHPSESSID`. Cada vista requiere un grant HMAC
  SSR efímero que ata origen, ruta, localización y UUID de vista; las firmas
  inválidas se rechazan antes de PDO y el HTML portador nunca se comparte en
  caché. El listado privado consume un
  reporte batch tipado. `retention_days` se aplica mediante el comando
  explícito `liquidstack:blog:analytics:purge --yes`; CORE no activa la
  colección, no aplica migraciones y no instala el cron de producción.
- WebAdmin exige para toda contraseña nueva un mínimo de ocho caracteres
  Unicode y al menos una minúscula, una mayúscula, un número y un signo,
  conservando UTF-8 válido y un máximo de 1024 bytes. El login separa esa
  política de creación de la comprobación acotada de credenciales existentes,
  de modo que los hashes Argon2id vigentes creados con la política anterior
  continúan autenticando hasta que el usuario cambie su contraseña.
  Las pantallas de acceso, recuperación, activación y reset pasan a la familia
  reutilizable `artAuth02`/`moduleFormAuth*02`: fondo oscuro basado en la
  paleta estándar `color01..03`, formulario claro y un checklist accesible que
  confirma en directo longitud, minúscula, mayúscula, número, signo y
  coincidencia. El JavaScript no añade campos ni sustituye la validación del
  servidor, y el submit permanece disponible como fallback sin JavaScript.
- WebAdmin y Blog unifican sus pantallas administrativas de gestión en un shell
  responsive de ancho completo, con navegación lateral filtrada por capacidades, un único
  `main` e inspector contextual opcional. La mejora progresiva solo convierte
  navegación e inspector en drawers después de enlazar el JavaScript y mantiene
  sincronizados `aria-expanded`, foco e `inert`; sin JavaScript todo permanece
  accesible en el flujo normal. La UI administrativa pasa a una composición
  plana basada en espacio, tipografía, grid y fondos, sin franjas,
  pseudoelementos o bordes laterales de acento decorativos. La preview privada
  conserva su documento aislado para representar el `header` y el `main`.
- El editor Blog pasa a un lienzo visual que proyecta `header`, H1 y el futuro
  `main` sin anidar landmarks ni duplicar el H1 en la página administrativa.
  Los encabezados admiten H2-H6 sin H1: una sección exige un primer encabezado
  y propone H2, mientras artículo y contenedor proponen H3, pero la persona
  puede cambiar cualquiera de ellos a H2-H6 y dejar los saltos para la
  auditoría SEO. Mover o retirar un contenedor conserva todo su subárbol
  semántico. La creación exige elegir expresamente un locale activo todavía
  libre y muestra su `public_paths`; ese locale queda estable y nunca se
  sustituye por un prefijo inferido. El inspector integra asignación de
  categorías del mismo idioma y un catálogo de medios que conserva todos los
  assets ya referenciados aunque no estén entre los recientes.
  El guardado progresivo conserva campos y documento ante `409`, `422`, pérdida
  de autorización o red, solo acepta la redirección esperada al mismo editor y
  avisa antes de abandonar cambios pendientes. Para la salida SSR,
  `bodyHtml()` permanece como compatibilidad histórica, mientras las vistas
  nuevas separan `headerMediaHtml()` y `mainHtml()` sin duplicar la portada.
  La traducción asistida por IA continúa diferida y no crea variantes de forma
  implícita.
- Blog incorpora un medidor SEO editorial advisory en el editor estructurado:
  renderiza el estado guardado por SSR y reanaliza el payload actual mediante
  `POST /admin/blog/editor/seo-analysis`, con CSRF, capacidades, `no-store`,
  debounce y `AbortController`. Sus checks Unicode cubren metadatos, coherencia
  title/H1, primeras 100 palabras, H2-H6, ALT, repetición, concentración y
  canibalización same-locale contra publicaciones e inventario project-owned.
  No crea score, migración ni llamadas IA, y cualquier fallo degrada el panel
  sin bloquear editor, guardado o publicación.
- Blog completa su primera composición pública basada en recursos:
  `artBlogArticle01` presenta `article-basic-01` y `article-cover-01` con
  semántica `article`, cuerpo saneado por CORE, intro y retorno inyectables y
  estilos responsive; el fallback SSR anterior permanece disponible para
  consumidores sin shell visual adoptado. El feed público suma consultas
  tipadas y acotadas de relacionados por categorías compartidas, archivo
  anual/mensual y periodos con recuento, reutilizando el mismo PDO y excluyendo
  borradores. Los nuevos `sectionBlogRelated01` y `moduleBlogArchive01`, junto
  con `artBlogArticle01`, se distribuyen selectivamente con Blog, se registran
  en showroom y conservan grupos `managed_hash` independientes.
  `artBlogArticle01` protege sus placeholders internos y desplaza también los
  H2-H6 del cuerpo cuando el encabezado externo cambia de rango; las filas
  incompletas de relacionados se centran y el archivo identifica el periodo
  vigente mediante `aria-current="date"`.
- Blog incorpora una caché privada last-known-good del sitemap, opcional y
  desactivada por defecto. La migración append-only
  `0006_blog_sitemap_publication_state` mantiene una revisión pública monótona
  y la generación del storage; `publish`/`unpublish` escriben un fence durable
  antes de cambiar visibilidad y actualizan la revisión en la misma
  transacción. El comando explícito
  `liquidstack:blog:sitemap-cache:init` inicializa la raíz privada y exige en
  producción la confirmación del storage compartido. Solo una conexión DB
  clasificada como indisponible puede servir un snapshot vigente e íntegro,
  identificado por las cabeceras `X-LiquidStack-Sitemap-Source: stale-cache`
  y `Warning: 110`; errores de esquema, configuración, consulta no clasificada,
  render, overflow o storage continúan fallando cerrados. Composer no activa
  la capacidad, no ejecuta la migración y no crea su storage. La identidad
  incluye el prefijo de tablas, `doctor` contrasta generación DB y marker, los
  write runtimes comparten una única factoría de coordinator y el staging de
  una caída se limpia únicamente mediante una rutina privada y acotada.
- Se fija la frontera operativa del correo modular: la recuperación de
  contraseña entrega de forma síncrona, no crea trabajo en el outbox y muestra
  un fallo genérico si SMTP no confirma el mensaje; las invitaciones conservan
  el dispatcher one-shot. La automatización de ese dispatcher mediante un
  scheduler queda registrada como adopción futura por proyecto, nunca como un
  efecto de Composer.
- Se documenta como trabajo expresamente posterior al MVP la notificación de
  publicaciones Blog a suscriptores, con consentimiento, campañas, outbox
  separado, lotes y límites acotados, reintentos y cron propio por consumidor.
- El perfil `smtp` de WebAdmin comparte ahora el contrato general
  `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USERNAME`,
  `MAIL_PASSWORD` y `MAIL_FROM_NAME`. `MAIL_USERNAME` autentica y es también
  la dirección `From` y `RAIZ` + `DEV_MODE` determinan el origen tipado de los
  enlaces. Para no romper bloques generales anteriores, la ausencia de las
  claves nuevas conserva únicamente `465 => smtps`, `587 => starttls` y
  `EMISOR_NAME` como nombre visible si `MAIL_FROM_NAME` está ausente o vacío;
  las instalaciones nuevas deben declarar ambas claves.
  Los destinatarios `MAIL_ADMIN`, `MAIL_LAD` y `MAIL_LAD_BIS` continúan
  limitados a formularios y nunca reciben por copia invitaciones o
  recuperaciones. WebAdmin conserva su outbox, transporte endurecido y entrega
  sin CC/BCC. `LIQUIDSTACK_WEBADMIN_PUBLIC_ORIGIN` y el namespace dedicado
  `LIQUIDSTACK_WEBADMIN_SMTP_*`/`LIQUIDSTACK_WEBADMIN_MAIL_FROM_*` quedan como
  compatibilidad de bloque completo, sin mezcla por campos; el origen aislado
  puede seguir como alias de Blog sin seleccionar por sí solo ese transporte.
  `local_capture_smtp` mantiene intacto su contrato loopback sin auth ni TLS.
- Los backends distribuidos `formContact.php` y `_phpmailer.php` pasan de
  semilla inmutable a sincronización atómica por huella: CORE actualiza las
  versiones históricas intactas y preserva como grupo cualquier
  personalización local.
- Las skills de desarrollo y migración exigen regenerar y validar
  `manifests/managed-file-history.json` después del último cambio de un
  recurso gestionado y antes de publicar CORE. La rutina distingue ese
  historial canónico del estado local `.liquidstack/core/managed-files.json`
  de cada consumidor.

### Corregido
- El guardado estructurado ya no convierte un nodo semántico `break` en un LF
  para rechazarlo después como carácter de control. Backend y editor comparten
  ahora el cómputo de bytes y los límites agregados/escalares para saltos raíz,
  saltos inline, listas y contenido avanzado, evitando el falso `422`.
- El detalle de revisión incorpora restauración accesible con CSRF y lock
  optimista. Restaurar añade una revisión canónica inmutable; una revisión
  ausente, un lock stale o contenido inválido devuelve un error contextual en
  el shell sin alterar documento, referencias ni historial.
- El selector de idioma conserva un fallback `details` compuesto y usable sin
  JavaScript. La preview alinea CSS y runtime con `loading|ready|error`, devuelve
  foco a una acción operable, conserva anuncios vivos, admite zoom/viewport
  corto, falla cerrada a los `12 s` e ignora cargas obsoletas tras cerrar,
  reabrir o iniciar una generación nueva.
- El editor Visual de `Texto` permite salir de la última entrada de una lista
  con doble Intro y escribir inmediatamente en el párrafo hermano. El filler de
  caret y el `list-style-type` que usa la preview existen solo en el DOM visual:
  no entran en el flujo ni en el HTML serializado, no dejan un párrafo residual
  al cambiar de pestaña sin escribir y siguen rechazados por la fuente HTML
  estricta. Se preservan `ul`/`ol`, clase, ARIA, marcador, `start`, CSS, marcas e
  IDs de los elementos anteriores sin duplicarlos.
- `hero03` conserva un único H1 semántico en el bloque de marca y convierte
  su texto frontal animado en contenido decorativo oculto a tecnologías de
  asistencia; el historial gestionado reconoce tanto esta versión como las
  huellas anteriores del controlador y el template.
- Las subvistas segmentadas de `/showroom` y `/templates` vuelven a respetar
  la composición semántica y visual del stack: agrupan recursos `article` y
  módulos autónomos dentro de `section`, mantienen fuera los recursos cuya
  raíz ya es `section` y usan una `section` para el índice de categorías.
- La imagen de portada de `article-cover-01` deja de cargarse de forma tardía:
  el renderer conserva `lazy` para imágenes de contenido y anchas, pero marca
  el único bloque `cover` inicial como `eager` y `fetchpriority="high"` para no
  penalizar el LCP de los shells públicos que lo usan como hero.
- `doctor` ya no trata controladores, templates, SCSS, JS ni hooks de showroom
  opcionales como assets operativos obligatorios de un módulo. El diagnóstico
  limita `webadmin.assets` y `blog.assets` a los namespaces runtime gestionados
  por el módulo y expande cada directorio declarado a sus ficheros fuente
  exactos; así detecta la ausencia de un bundle concreto sin bloquear un
  proyecto que haya retenido legítimamente la familia visual estándar. Un
  directorio runtime vacío o cuyo source atraviese un enlace falla cerrado.
  En destino, cada ruta exigida debe resolver además a un fichero regular; un
  directorio con nombre `.js` o `.css` ya no produce un falso positivo.
- Los ficheros `managed_hash` de un mismo grupo se publican ahora mediante
  lock por proyecto, staging, journal y backup en el volumen del consumidor.
  Si falla una promoción, CORE revierte conjuntamente altas y actualizaciones,
  conserva intacto el manifiesto de estado y permite reintentar sin mezclar
  versiones; una interrupción se recupera antes del siguiente plan y un
  rollback incierto detiene Composer conservando sus backups. La restauración
  solo retira huellas exactas del staging, contempla `rename` en Windows y sus
  temporales quedan excluidos de Git.
- La búsqueda pública Blog mide ahora el texto normalizado en caracteres
  Unicode —2 a 120 tras un techo defensivo de 480 bytes—, limita el offset a
  10.000 y compara correctamente mayúsculas acentuadas en SQLite mediante un
  casefold determinista registrado una vez por conexión. Los filtros públicos
  de categorías consultan una fila adicional y fallan cerrados al superar 100,
  sin truncar silenciosamente el catálogo. `moduleBlogFilters01` ordena primero
  cualquier selección válida y muestra como máximo diez categorías, el mismo
  techo que acepta la consulta GET incluso sin JavaScript.
- El sitemap estático deja de asignar la hora de cada build como `lastmod` a
  todas las rutas. Como el stack no conoce una fecha de modificación real para
  ese contenido project-owned, omite el elemento opcional y produce XML
  idempotente; el sitemap dinámico del Blog mantiene las fechas reales de DB.
- Las respuestas `GET`/`HEAD` de namespaces públicos modulares ya no abren la
  sesión PHP legacy ni heredan `PHPSESSID`/cabeceras de caché de sesión. El
  dispatcher realiza primero un claim de prefijo barato, sin construir el
  provider ni abrir PDO; una ruta estática con prioridad conserva la sesión,
  salvo opt-out literal `session => false`, y un miss modular la inicia antes
  del 404 legacy. Los medios públicos Blog se declaran además como prefijo
  pre-bootstrap para no caer en redirecciones multidioma; el sitemap exacto,
  POST, showroom, ficheros públicos y rutas ajenas conservan sus prioridades.
- El selector público de idioma ya no depende de que CookieLAD esté cargado o
  responda correctamente para traducir o navegar. La preferencia
  `cookie_custom_lang` solo se persiste cuando `cookie_custom` vale exactamente
  `true`; si el runtime de traducción falla, el enlace conserva una navegación
  segura al `href` localizado del mismo origen. Los catálogos global y de vista
  se cargan de forma conjunta antes de mutar DOM o URL, y el runtime viaja en
  un grupo Composer independiente de cualquier personalización del showroom.
  Si se solicitan varios idiomas antes de completar la red, solo la selección
  más reciente puede aplicar contenido, cambiar URL o ejecutar el fallback.
  El helper gestionado expone además `bindLanguageNavigation()` para shells
  públicos DB-backed: captura los enlaces localizados antes del traductor
  legacy, conserva pestaña nueva/descarga y navega por su `href` sin hacer POST
  a `/languages`; así el cambio de idioma no crea `PHPSESSID` por accidente.
- En producción, las vistas resuelven JS y CSS por la entrada exacta
  `src/js/{resources}.js` del manifest de Vite. Ya no eligen el primer fichero
  de un glob por prefijo, que podía confundir `blog` con `blogArticle` o con el
  chunk homónimo del showroom. El fallback para builds legacy solo se usa
  cuando existe un único candidato inequívoco y mantiene `$css` como URL.
- `update-languages.php` conserva el contenido existente sin inferir claves de
  variables locales como `$entry->text` ni confundir ids HTML con claves
  traducibles; los recursos alimentados mediante `items_data` siguen sin
  rehidratar fixtures de items que pertenecen a la DB.
- WebAdmin muestra etiquetas legibles para todas las capacidades delegables y
  Blog ajusta enlaces y acciones al estado real, acceso a Media y permiso de
  publicación. Las categorías ofrecen solo idiomas activos aún no asociados,
  ocultan el alta cuando están completas y conservan compatibilidad con
  adaptadores anteriores. Crear una variante revalida además edición y acceso
  a Media dentro de la misma transacción antes de escribir el borrador.
- El editor estructurado usa encabezados reales para nombrar cada bloque,
  restaura el foco tras reordenar, añadir o eliminar contenido, reanuncia
  mensajes idénticos en su región viva y corrige el copy de YouTube. El recurso
  `sectionBlogGrid01` usa foco y hover con contraste suficiente y permite
  cortar títulos o extractos largos sin desbordar el viewport. Las cuatro
  secciones Blog conservan además un `{header-primary}` externo, escalan sus
  cards relativamente y mantienen `aria-labelledby` unido al ID real. Los
  enlaces internos y la acción del filtro rechazan barras invertidas para que
  el navegador no pueda normalizarlas como un origen externo.
- Los artículos Blog publicados generan robots, canonical, Open Graph y Twitter
  Card, y comparten con el sitemap un conjunto `hreflang`/`x-default` formado
  solo por variantes publicadas. La imagen social se limita a la portada formal
  de `article-cover-01`; documentos legacy o sin portada no infieren una imagen
  arbitraria ni exponen medios de borrador.
- El helper histórico `schemaWebPageAccessibility()` deja de publicar
  afirmaciones WCAG, contraste, descripciones largas o control total por
  teclado que el runtime no puede auditar; conserva una firma compatible y el
  esquema `WebPage` para no romper consumidores. Su JSON-LD neutraliza cierres
  de `script`, caracteres HTML y bytes UTF-8 inválidos antes de insertarse en
  el documento, y acepta un nonce CSP opcional estrictamente validado.
- Los verificadores compuestos de WebAdmin y Blog reconocen ahora las formas
  equivalentes con las que MySQL 8 y MariaDB publican `CHECK`, `LCASE`,
  `REGEXP_LIKE`, `IS NULL`, índices y defaults en `INFORMATION_SCHEMA`, sin
  producir falsos `migration.postcondition_failed`. A la vez exigen checks
  activos, claves primarias, índices completos/visibles, FKs en el mismo
  schema y ausencia de huérfanos. El SQL, los checksums y las versiones de
  contrato de las migraciones publicadas permanecen congelados.
  En SQLite, la validación preserva el contenido literal de defaults y
  `CHECK`, descarta comentarios y rechaza índices de expresión inesperados.
- El router de desarrollo cambia al directorio `public` antes de cargar el
  front controller, preservando las rutas relativas de las vistas legacy sin
  dejar de enrutar endpoints dinámicos con extensión como el sitemap de Blog.
- El sitemap dinámico de Blog se declara ahora como endpoint público exacto
  pre-bootstrap: llega al módulo antes de la redirección multidioma y no crea
  `PHPSESSID`. Las rutas GET, los ficheros y las subrutas de showroom propiedad
  del proyecto conservan prioridad; catálogos incompletos caen al flujo legacy
  y los artículos continúan usando la resolución pública tardía.

### Añadido
- Comando seguro `composer liquidstack:blog:adopt-unified-text` para planificar
  en dry-run la normalización del contenido activo y, con `--apply --yes`,
  adoptar exactamente un `--post` y un `--locale` bajo la identidad de
  `--actor`. Excluye siempre Dummy y papelera, vuelve a comprobar lock, estado y
  huella dentro del guardado ordinario, crea current+revisión en borradores y
  solo un workspace privado en publicaciones; nunca republica ni reescribe el
  historial.
- Proyección pública Blog unificada: una instancia de
  `BlogPublicFeedFactory` comparte runtime y conexión PDO para cards generales,
  filtros y cards por categoría. El factory específico anterior permanece
  compatible. El sitemap dinámico suma un `ETag` fuerte, revalidación
  `If-None-Match` correcta para `GET`/`HEAD`, respuestas `304` sin cuerpo,
  `Content-Length` verificable y un límite estricto de 50 MiB.
- Familia visual Blog con ownership selectivo: `moduleBlogFilters01`,
  `sectionBlogGrid01`, `sectionBlogList01`, `sectionBlogFeatured01` y
  `sectionBlogSlider01` publican controlador, template, SCSS y JS únicamente
  al activar `liquidstack/blog`. Sus grupos gestionados preservan
  personalizaciones locales de forma coherente, el showroom usa fixtures
  Matrix sin fallback de DB y las copias base anteriores migran solo cuando
  coinciden con una huella histórica verificada.
- Runtime público Lite YouTube gestionado por el módulo Blog. Conserva el
  enlace externo SSR sin JavaScript o consentimiento, no crea el iframe
  `youtube-nocookie.com` hasta un clic primario con `cookie_social=true`, no
  precarga miniaturas de terceros y desmonta la reproducción si CookieLAD
  revoca el permiso. El fallback standalone y los shells project-owned usan
  CSP acotadas y el asset se distribuye solo al activar `liquidstack/blog`.
- La hoja de ruta Blog conserva como requisitos explícitos los fixtures de las
  cuatro películas Matrix, la semántica de filtros de categorías `any/all`, la
  futura caché condicional del sitemap y los límites de elegibilidad de Google
  Indexing API; Search Console e indexación nunca sustituyen al sitemap ni
  garantizan que una URL sea indexada.
- El runtime de traducción reconoce el atributo de catálogo `ariaLabel` y lo
  aplica como `aria-label` sin reemplazar el contenido interior del elemento;
  permite localizar controles iconográficos conservando su estructura.
- Punto de extensión opcional `public_article_view` para integrar el detalle
  público Blog con un shell project-owned confinado a `App/views`. La vista
  recibe un `BlogPublicArticleViewModel` tipado con SEO, alternates publicados,
  navegación localizada con fallback al índice, contenido saneado, portada,
  plantilla y fechas, sin PDO ni IDs internos. El fallback
  standalone compatible incorpora el CSS neutral y responsive gestionado
  `blog-public.css`; conserva una CSP cerrada con `style-src 'self'`, mientras
  el shell del proyecto es propietario de su CSP y mantiene el resto de
  cabeceras defensivas.
- Comando explícito e idempotente `composer liquidstack:media:init` para
  preparar el storage privado de WebAdmin después de sus migraciones. Valida
  el perfil local o la ruta persistente de producción, rechaza symlinks,
  junctions, raíces peligrosas y la adopción implícita de directorios no vacíos
  sin ownership; crea el marcador `.liquidstack-webadmin-media`, el lock,
  staging y un `.gitignore` interno. Admite confirmación interactiva o `--yes`,
  exige `--yes` en JSON y, en modo normal, no abre DB, procesa imágenes ni
  configura correo. Una vía excepcional
  `--adopt-existing --backup-confirmed --yes` permite marcar un
  storage legacy únicamente tras verificar bajo lock una correspondencia
  bidireccional completa DB↔FS; cualquier mismatch falla sin mutar el layout.
  Los eventos automáticos de Composer continúan sin mutar DB o storage.
- Vista previa privada y no cacheable de la última versión guardada de cada
  variante Blog. Usa `blog.articles.view`, admite borradores incompletos y no
  crea canonical, URL pública, entrada de sitemap, auditoría ni mutaciones.
- Laboratorio HTTP local tipado para WebAdmin y Blog: solo acepta loopback
  cuando `DEV_MODE=1`, `RAIZ`, `Host` y puerto coinciden y `REMOTE_ADDR`
  representa el peer loopback;
  producción conserva HTTPS obligatorio y las cabeceras `Forwarded` no amplían
  confianza. Blog usa `RAIZ` como origen canónico, con el origen WebAdmin
  anterior como alias compatible. CORE distribuye además un router seguro para
  `php -S`, incluso si un contrato SCSS independiente bloquea los recursos.
  Migra únicamente el script `lad` canónico cuando el router gestionado está
  disponible, sin pisar variantes personalizadas, y permite que
  `/blog-sitemap.xml` llegue al front controller. Si `RAIZ` y el alias legacy
  difieren en producción, conserva temporalmente la URL anterior y `doctor`
  avisa en vez de romper el frontend público.
- Perfil de correo `local_capture_smtp` para recorrer en el laboratorio el
  outbox y las acciones de credencial reales con un capturador SMTP loopback.
  Usa `RAIZ` solo bajo `DEV_MODE=1`, no imprime tokens, no admite credenciales,
  TLS, relay ni hosts remotos y queda bloqueado antes de PDO fuera del perfil;
  el transporte productivo conserva autenticación y STARTTLS/SMTPS estrictos.
- Perfil de DB modular dedicado y opt-in `liquidstack`, coexistente con el
  default compatible `shared`. WebAdmin y Blog pueden usar las variables
  `LIQUIDSTACK_DB_HOST`, `LIQUIDSTACK_DB_PORT`, `LIQUIDSTACK_DB_NAME`,
  `LIQUIDSTACK_DB_USER`, `LIQUIDSTACK_DB_PASSWORD` y
  `LIQUIDSTACK_DB_CHARSET=utf8mb4`, con validación cerrada, DSN construido
  internamente y sin fallback a `BBDD_*`. Ambos módulos deben seleccionar el
  mismo perfil; `.env`, configuración y datos siguen siendo project-owned y
  ningún update o cambio de perfil migra contenido automáticamente.
- Infraestructura de módulos internos para WebAdmin y Blog dentro del único
  paquete físico `liquidstack/core`: manifiestos validados, cierre de la
  dependencia Blog → WebAdmin, selección exclusiva desde `require` directo y
  publicación aditiva de ficheros declarados sin ejecutar migraciones.
- Selectores Composer lógicos `liquidstack/webadmin` y `liquidstack/blog`
  mediante `replace: self.version`, con normalización automática a `:*` al
  ejecutar `composer require` sin constraint y fallback documentado para
  ejecuciones sin plugins.
- Capability CLI del plugin con `liquidstack:doctor` (texto o JSON) y
  `liquidstack:migrate` en modos exclusivos `--plan`, `--dry-run` y `--apply`.
  El plan permanece offline, el dry-run consulta la DB en solo lectura y apply
  es la única mutación, con confirmación, hash esperado, lock y doble puerta
  para cambios destructivos. Ningún modo expone secretos, SQL o mensajes PDO.
- Dispatcher neutral de módulos antes del bootstrap y la sesión legacy.
  WebAdmin reserva `/admin` solo cuando su selector está activo, responde en
  fallo cerrado sin crear `PHPSESSID`, conserva GET/POST públicos y detecta
  estáticamente colisiones con rutas project-owned sin ejecutar sus PHP.
- Configuración WebAdmin opcional en `App/config/modules/webadmin.php`, con
  defaults seguros y diagnóstico operativo de configuración, ruta, assets,
  DB modular seleccionada, esquema, clave de seguridad y protección de argumentos en
  trazas. El informe no revela valores y distingue `runtime_ready` de
  `bootstrap_ready`.
- Esquema inicial versionado de identidad, roles, capacidades, tokens,
  sesiones, límites, auditoría, outbox y estado; bootstrap explícito e
  idempotente de `system_superadmin`/`site_admin`; y primer flujo HTTP aislado
  de login, autorización, panel mínimo y logout. Las migraciones y el bootstrap
  nunca se ejecutan durante `composer update`.
- Contrato PDO estricto para MySQL/MariaDB y SQLite, registro de
  migraciones con checksum/scope, postcondiciones auditables de esquema,
  constraints, semillas y datos, precondición versionada de namespace vacío
  antes de cualquier escritura y gate HTTP acotado que evita repetir la
  inspección completa de `INFORMATION_SCHEMA` en cada petición.
- Autenticación WebAdmin con política productiva fija `argon2id-v1`, sesiones
  revocables, CSRF HMAC estable por sesión, autorización revalidada contra DB,
  rate limit, auditoría sin secretos y preflight HTTPS/morfología antes de PDO.
  HTTP y CLI comparten además el cargador y la precedencia del entorno, por lo
  que los secretos de proceso funcionan aunque `variables_order` omita `E` y
  un `.env` inválido bloquea el runtime sin usar una configuración parcial.
- Flujos HTTP no enumerables de activación inicial y recuperación de
  contraseña. El token de correo se vincula a una sesión de acción, responde
  con `303` hacia una URL limpia, exige CSRF y nunca inicia login automático.
  Las cookies autenticada, preautenticada y de acción quedan separadas por
  propósito; la contraseña conserva el contrato UTF-8 de 15–1024 bytes.
- Outbox asíncrono de WebAdmin y transporte SMTP desacoplado, con origen
  público HTTPS explícito, tokens brutos solo en memoria, leases de cinco
  minutos, fencing, cinco intentos y backoff acotado. El nuevo comando
  `liquidstack:webadmin:mail:dispatch` procesa lotes one-shot de 1–100,
  devuelve contadores seguros en texto/JSON y falla con código no cero ante
  retry, fallo terminal o resultado cercado.
- `liquidstack:webadmin:bootstrap --resend-invites` recupera de forma
  confirmada invitaciones iniciales ya enviadas o fallidas: omite entregas
  abiertas y cuentas activadas, revoca enlaces anteriores y reencola sin
  enviar directamente. `doctor` informa `mail_ready`/`mail_blockers` por
  separado de `runtime_ready` y `bootstrap_ready`.
- Gestión privada de editores en WebAdmin: listado paginado por cursor firmado,
  invitación y reenvío mediante outbox, suspensión/reactivación, capacidades
  estrictamente delegables y UI accesible bajo `/admin/users`. Las mutaciones
  revalidan SID, CSRF, versión, lifecycle, roles y permisos dentro de la
  transacción; excluyen self/roles protegidos, preservan asignaciones fuera del
  alcance del actor y auditan sin PII ni secretos. El cursor transporta solo el
  UUID público; los locks de sesión usan parámetros constantes aunque crezca el
  historial de tokens, y los touches idempotentes son compatibles con el
  recuento de filas modificadas de MySQL/MariaDB.
- MVP `Liquid Blog 0001`: esquema versionado MySQL/SQLite, capacidades
  delegables registradas sobre el scope efectivo de WebAdmin, artículos con
  variantes localizadas independientes, borrador/publicación, concurrencia
  optimista, UI privada accesible en `/admin/blog` y auditoría atómica. Las
  rutas públicas DB-backed se resuelven después de las rutas estáticas del
  proyecto y el sitemap dinámico refleja publicar/retirar sin modificar el
  repositorio ni desplegar; detecta de forma acotada el límite de 50.000 URLs.
- Biblioteca privada WebAdmin `0002`: subida validada de JPEG, PNG y WebP,
  variantes responsive AVIF sin metadatos, cuota y rate limit, storage fuera
  de `public`, entrega autenticada y assets administrativos distribuidos por
  el manifiesto modular. `composer install` y `composer update` nunca crean ni
  modifican la DB o el storage.
- Liquid Blog `0003` a `0005`: categorías localizadas y asignables, capacidades
  independientes, documentos JSON canónicos con ocho bloques controlados,
  adopción compatible del contenido legacy, revisiones inmutables y
  restauración transaccional. El editor integra idiomas, categorías,
  publicación y biblioteca Media; la salida pública conserva fallback legacy
  y solo entrega un AVIF si el documento actual publicado lo referencia.
- Gates de funciones y diagnóstico aditivos: una migración futura pendiente no
  inutiliza las fronteras anteriores ya verificadas, mientras que schemas
  parciales o assets modulares ausentes fallan cerrados y se explican en
  `liquidstack:doctor`. Los checksums publicados quedan congelados para impedir
  la reescritura accidental de migraciones ya aplicadas.
- El prefijo neutral ya no puede sombrear silenciosamente rutas legacy con
  claves dinámicas: una clave calculada, concatenada o añadida mediante índice
  bloquea el registro y aparece como `route_file.dynamic_key`. La autorización
  exige además el token secreto de sesión y no confía en DTOs construibles.
- Arnés opt-in `composer test:mysql-integration` para probar en una DB aislada
  colisiones sin mutación, runner real, postcondiciones, idempotencia,
  bootstrap, outbox/ACK, activación, login, reset, revocación de sesión y los
  órdenes concurrentes `user → session`, `user → action → session` y
  `outbox → user`. También prueba una carrera real de invitación duplicada con
  dos actores y exige un único ganador sobre InnoDB, además del gate y la
  limpieza exacta. El corte se ha validado localmente sobre MariaDB 10.4.32;
  MySQL 8 queda incorporado como matriz obligatoria de CI o entorno compatible.
- Skill base `liquidstack-module-operations`, distribuida a los consumidores
  para guiar la activación, diagnóstico, adopción y evolución segura de
  WebAdmin y Blog.
- Resolución de dependencias de desarrollo fijada al mínimo soportado PHP 8.1,
  evitando que un entorno local PHP 8.2 genere un lock de pruebas incompatible
  con el contrato declarado del paquete.
- Showroom segmentado en ocho categorías con índice ligero, menú accesible,
  parciales PHP y chunks JS/SCSS cargados bajo demanda. Las subrutas se
  resuelven solo desde un padre `/showroom` o `/templates` ya registrado y
  nunca reescriben los ficheros de rutas del consumidor. El submenú permanece
  visible bajo la navegación global tanto con ScrollSmoother como con scroll
  táctil nativo.
- Copy del shell y de las categorías integrado en el catálogo `templates`
  ES/EN/EU, incluida la conservación de la subruta al cambiar de idioma sin
  recargar la página, la reescritura de enlaces del índice y la resolución
  SSR del selector de idioma.
- Hooks locales preservados `App/views/showroom/_local.php` y
  `src/js/showroom/local/<categoria>.js` para ampliar el catálogo sin
  personalizar el grupo gestionado por CORE.
- Sincronización segura y aditiva para proyectos consumidores. CORE instala
  ficheros nuevos, actualiza copias intactas reconocidas por su estado o por
  huellas históricas normalizadas y registra el resultado versionable en
  `.liquidstack/core/managed-files.json`. Las personalizaciones desconocidas se
  conservan por grupo de recurso y no se elimina ningún fichero del proyecto.
- Fusión recursiva de catálogos JSON que añade claves y propiedades ausentes
  sin sustituir valores existentes, incluidos valores vacíos intencionales.
  La inserción conserva el formato y los finales de línea del catálogo. Las
  semillas de backend, email, runtime legal, footer y logos se instalan
  únicamente cuando faltan.
- Contrato SCSS v2 con la estructura común de blancos (`color00`), negros y
  grises (`color01`), colores corporativos (`color02`, `color03` y el
  terciario opcional `color04`), sus variantes y filtros `colorNNSVG`.
  Composer añade solo las declaraciones que falten, con `!default` y dentro
  de un bloque acotado, sin reemplazar valores ni variables extra del
  consumidor. Los recursos estándar quedan limitados a `color00..color03`.
  Si el contrato no puede garantizarse, la sincronización gestionada se omite
  antes de modificar recursos. Los antiguos acentos `color04` conservan un
  fallback visible a `color02`; el config v2 los activa como `color03`
  mediante custom properties sobrescribibles.
- Historial de huellas gestionadas y comando
  `php tools/build-managed-file-history.php` para regenerarlo antes de
  publicar una versión. La huella canónica de texto tolera LF/CRLF y
  diferencias exclusivamente al final del fichero (sin salto final o con
  líneas vacías), conservando las huellas anteriores por compatibilidad.
- Compatibilidad con rutas legacy de showroom: cuando una ruta usa el bundle
  `templates` y el contenido `showroom`, se carga `templates` como catálogo
  base y `showroom` como override local.
- Eliminado el BOM UTF-8 de `artHeroScroll01.php`, que podía emitir salida
  invisible antes de las cabeceras HTTP al incluir el controlador.
- Recursos `art33` y `art34`, con raíz `article`, fichas `div`, encabezados
  relativos, cantidad variable de ítems, contenido inyectable y CTA por ficha
  o general.
- Familia `moduleFormContact01/02/03`, con tres presentaciones del mismo
  formulario atómico, envío asíncrono same-origin, IDs accesibles por
  instancia y catálogo completo ES/EN/EU.
- Backend genérico compatible con `POST /form`, plantillas de correo y
  catálogos ES/EN/EU. Composer instala las semillas solo cuando faltan y
  conserva las personalizaciones locales del endpoint, transporte y correo.
- Dieciocho recursos reutilizables promovidos desde el laboratorio: los
  escenarios `hero06` y `hero07`, los módulos `moduleH1Type03`,
  `moduleH1Type04` y `moduleH2Type02`, los artículos `art20` a `art31` y
  `artAccordion02`, con semántica, encabezados relativos, ítems escalables y
  variantes alternas.
- `art32`, variante autónoma de `art02` con cards en caja, iconos filtrados,
  CTA opcional, encabezados relativos y cantidad variable de ítems. El
  showroom incluye ocho cards dummy para comprobar su distribución.
- Familia de CTA ampliada con `moduleButtonType03`, inspirado en una transición
  expansiva, y `moduleButtonType04`, con estados hover, focus y active
  convencionales. `moduleButtonType02` incorpora ahora una imagen de icono
  editable con fallback de sistema.
- Módulo `moduleTable01`, con `caption`, encabezados y celdas editables,
  semántica tabular accesible, entre 1 y 26 filas, entre 1 y 8 columnas y
  desplazamiento horizontal responsive.
- Acordeón accesible `artAccordion02` animado con GSAP, compatible con
  movimiento reducido y protegido frente a interacciones rápidas.
- Recursos `artVideo01` y `artVideo02`, compuestos con `moduleVideo01` para
  alternar YouTube ligero o vídeo local. Incluyen encabezados relativos,
  contenido y CTA inyectables, composición horizontal o vertical, edición
  inline de proveedor, fuentes, poster y pistas VTT, y bloqueo de cualquier
  petición social hasta recibir `cookie_social=true`.
- Edición inline de colecciones para `moduleList01`, selector de marcador,
  edición de fondos responsive o de imagen única y guardado por lotes mediante
  el nuevo stub gestionado `App/app/updateLanguage.php`. El endpoint bloquea
  conjuntamente lectura y escritura y rechaza catálogos JSON corruptos sin
  sobrescribirlos; el instalador avisa antes de sustituir una copia local
  distinta. Los recursos compuestos pueden agrupar campos hermanos mediante
  `data-inline-group`, y la inicialización sustituye el listener anterior
  durante HMR para evitar modales duplicados.
- Once iconos SVG de sistema requeridos por los nuevos recursos, sincronizados
  a `public/assets/img/system` en los stacks consumidores.
- Distribución de vídeo local reutilizable desde `resources/video` hacia
  `public/assets/video`, con dummies MP4/WebM, pistas VTT ES/EN/EU, destino
  configurable y conservación de vídeos propios del proyecto consumidor.
- Recursos `art02little`, `moduleParrafo01` y `moduleList01`, con composición
  variable de uno a tres ítems, variantes de imagen o icono, contenido
  inyectable y jerarquía de encabezados relativa.
- Vista canónica `_showroom.php`, compartida con el acceso histórico
  `_templates.php`, y registro de las nuevas composiciones en los idiomas
  base ES, EN y EU.
- Encabezados de catálogo identificados por el nombre exacto de cada recurso,
  con índices independientes para las composiciones que comparten módulos, de
  modo que `/showroom` y `/templates` puedan recorrerse con la búsqueda del
  navegador sin sustituir el lorem ni las imágenes dummy.
- Assets SVG usados por las variantes del nuevo recurso en
  `resources/img/system`, sincronizados a los proyectos consumidores.
- `src/Core/Composer/Installer.php` sincroniza la guía base para agentes desde `.codex` durante `composer install`/`composer update`: conserva cualquier `.codex/config.toml` local, actualiza las skills base en `.codex/skills`, mantiene intactas las skills locales no gestionadas por CORE y rechaza destinos redirigidos mediante symlinks o junctions.
- Watcher Vite compartido para hidratar idiomas al editar vistas e includes,
  distribuido a `tools/liquidstack/vite`. El instalador migra de forma
  idempotente el bloque legacy conocido y conserva intacta cualquier
  configuración Vite personalizada.
- Suite reproducible con `composer test` para validar la sincronización, los eventos del plugin, la convivencia con skills locales, la retirada de copias gestionadas antiguas y la protección frente a junctions.
- Comando interactivo `composer release` para sugerir la siguiente versión SemVer estable, ejecutar las validaciones y publicar `main` junto con un tag anotado mediante un push atómico. El webhook existente de Packagist recibe la nueva etiqueta sin almacenar tokens adicionales.
- `src/Core/Composer/Installer.php` sincroniza tambien `resources/img` hacia `public/assets/img` durante `composer install`/`composer update`; puedes sobrescribir el destino con `STACK_CORE_RESOURCES_IMG_TARGET` (alias legado: `STACK_LIQUID_CORE_RESOURCES_IMG_TARGET`).
- `src/Core/Composer/Installer.php` ahora fusiona dependencias frontend desde `package.core.json` al `package.json` del proyecto consumidor durante `composer install`/`composer update`, añadiendo solo paquetes faltantes sin sobrescribir versiones existentes.
- `App/tools/build-sitemap.php` ahora crea/actualiza `public/robots.txt` y garantiza que la entrada del sitemap apunte al host de producción definido en las variables de entorno.

- La demostración de `art02` en el showroom usa ocho cards con iconos de
  sistema y copy Matrix suficientemente extenso en ES, EN y EU para comprobar
  alturas, saltos de línea y distribución responsive.

### Corregido
- `composer liquidstack:migrate --plan` usa ahora un diagnóstico realmente
  `catalog-only`: valida solo Composer, selección modular, providers de
  migración y sus metadatos, sin quedar bloqueado por el origen público, SMTP,
  credenciales o cualquier otro requisito operativo ajeno al plan offline.
- `build-sitemap.php` normaliza CRLF sin duplicar líneas vacías en Windows y
  respeta `sitemap => false` para excluir rutas privadas declaradas por cada
  proyecto.
- Las plantillas legacy de recuperación de contraseña dejan de incluir marca,
  enlaces y dominio de un cliente concreto. `navMegamenu01` admite ahora el
  parámetro `offices`; un starter puede pasar un array vacío sin alterar el
  fallback de compatibilidad de los stacks existentes.
- El selector cromático del editor inline ya no interpreta las variables
  `colorNNSVG` como colores CSS editables y elimina el sufijo Sass `!default`
  de las variables añadidas por Composer antes de mostrarlas.
- La traducción de atributos `href` conserva rutas de raíz, anclas, query
  strings, URLs relativas al protocolo y esquemas externos; los enlaces
  relativos ordinarios mantienen su prefijo de idioma. `moduleButtonType04`
  aplica el mismo criterio a sus rutas de raíz.
- Las rutas, el fichero Vite completo, `_global.scss`, los SCSS de página y
  las configuraciones locales quedan fuera de la sincronización gestionada
  por ser propiedad de cada proyecto. `_config.scss` tampoco se sustituye:
  solo recibe las variables ausentes del contrato cromático.
- El instalador conserva el runtime legal y `footerInfo01` cuando un proyecto
  los ha personalizado, evitando que una actualización de CORE sustituya copy
  regulatorio o branding del consumidor.
- `moduleH1Type02` admite `header_level`; `art15` recupera su anchura completa
  y `artVideo01` limita su anchura al 80 % en tablet y al 60 % en escritorio.
- El editor inline prioriza las colecciones editoriales en fase de captura y
  neutraliza listeners antiguos que hayan quedado vivos durante HMR, de modo
  que `Ctrl + doble clic` sobre el texto de un `li` abre siempre el listado
  completo. El selector de icono pasa a ser opcional y el mismo contrato se
  aplica a las listas nativas de `art17`, `art18`, `artPricingGlass01`,
  `sectionParallax01`, `artHeroScroll01` y `artZipper`, sin interceptar las
  imágenes editables ni las listas estructurales de navegación.
- La sincronización de imágenes instala los logos genéricos que falten, pero
  conserva los logos homónimos existentes en cada consumidor para no sustituir
  su branding durante `composer install` o `composer update`.
- La hidratación de idiomas es ahora aditiva por defecto, conserva claves,
  tipos y propiedades existentes —también las vacías— y reserva la poda para
  `--prune-unused`. El detector reconoce llamadas anidadas y los ejes
  `list_items`, `subitems`, `benefits`, `items_row1` e `items_row2`, evitando
  pérdidas de copy en recursos con listas o colecciones variables. Además,
  valida los JSON antes de escribir, muestra `Creado`, `Actualizado` o
  `Sin cambios` por catálogo y resuelve el destino desde la vista y las rutas
  reales del consumidor.
- `art15` mejora la legibilidad responsive del texto destacado mediante un
  interlineado fluido.
- `art16`, `hero00`, `hero06` y `hero07` exponen correctamente sus imágenes al
  editor inline sin alterar su estructura semántica ni su composición;
  `art16` y `hero00` preservan URLs absolutas y escapan sus atributos y estilos.
  El encabezado de `art16` admite además `header_level` y conserva sus estilos
  entre H1 y H6.
- `art30` conserva `div` como raíz de cada card, sitúa el enlace dentro y
  mantiene accesible el texto de las cards no interactivas; también desaparecen
  correctamente sus beneficios vacíos y el medio vacío de `artAccordion02`.
  Su galería queda acotada a cuatro fichas y su banner a seis beneficios
  hidratados; fotos, fichas, iconos y el banner completo son editables inline.
  Los beneficios se alinean por su borde superior aunque su copy tenga alturas
  diferentes.
- `art28` hidrata sus variantes responsive con las claves relacionadas
  `_img_srcset01` y `_img_srcset02`, de modo que el editor inline pueda
  modificar la imagen y su `srcset` sin mantener un dummy distinto en pantalla.
- El entrypoint del showroom importa GSAP antes de usar `delayedCall`, evitando
  un error al redimensionar y manteniendo la actualización de fondos responsive.
- `art26` ya no reserva una celda de grid para una CTA ausente y `art31`
  aplica su layout de encabezado también cuando se escala a H1.
- Las skills base dejan de depender de guías `AGENTS_*.md` legacy y absorben
  las reglas comunes de desarrollo y SEO, permitiendo que cada consumidor
  mantenga su contexto privado en skills locales no gestionadas por CORE.
- `art02little` expone el número de tarjetas como modificador de clase y usa
  tres columnas en escritorio cuando recibe tres ítems, sin alterar las
  proporciones existentes de las composiciones de uno o dos.
- `composer release` distingue una rama simplemente atrasada de un historial
  local y remoto divergente, evitando recomendar `git pull --ff-only` cuando
  ese comando no puede resolver el estado.
- `art02` usa `gap` en ambos ejes y reduce de cuatro a tres líneas la
  altura reservada para sus encabezados de tarjeta, corrigiendo la falta de
  separación vertical y el exceso de espacio.
- `art32` calcula sus cuatro columnas descontando los gaps y evita el desborde
  horizontal que producía el `min-width` heredado de `art02` en portátiles. Su
  filtro de icono usa una custom property con fallback del contrato SCSS v2.
- `art10` resuelve siempre su clase, `art05` normaliza las rutas de imagen y
  `hero02` ya no deja un placeholder de edición sin resolver. `hero04` genera
  su textura de dithering en memoria y deja de solicitar el asset inexistente
  `LDR_LLL1_0.png`. El catálogo EN elimina además tres claves duplicadas.
- El editor inline retira durante HMR los listeners anteriores de doble clic,
  `Ctrl` + clic sobre enlaces y cambio de idioma, evitando manejadores
  duplicados.
- El idioma EN recupera todas las claves de referencia de los recursos
  interactivos registrados en el showroom, sin eliminar las entradas
  adicionales propias de CORE.
- `composer release` gestiona sus preguntas mediante la entrada interactiva
  nativa de Composer, evitando que PowerShell pierda `STDIN` en procesos PHP hijos.
- Se fijan finales de línea LF para los ficheros de texto y se evitan los avisos
  de conversión LF/CRLF al preparar commits desde Windows.
- `src/Core/Application.php` vuelve a adjuntar los assets compilados cuando una ruta define `resources` aunque no tenga fichero de contenidos asociado y mejora la lectura del flag `DEV_MODE`.
- `src/Core/Support/Paths.php` permite sobreescribir la ruta pública mediante variables de entorno y amplía las heurísticas para localizar automáticamente docroots habituales (`public_html`, `www`, `web`, `htdocs`, `httpdocs`) cuando el proyecto no usa la carpeta `public`, además de detectar el `DOCUMENT_ROOT` proporcionado por el servidor como origen principal de los assets.

### Instrucciones de actualización
- Detén temporalmente el watcher de Vite antes de actualizar para evitar que
  regenere idiomas mientras Composer sincroniza el showroom y sus catálogos.
- Ejecuta `composer update liquidstack/core` para recibir los nuevos recursos,
  el showroom, la configuración y las skills base. Las skills locales deben
  usar nombres de carpeta distintos a los gestionados por CORE.
- Si el proyecto usa el `vite.config.js` legacy estándar, Composer activará el
  watcher compartido automáticamente. En configuraciones Vite personalizadas,
  añade el import indicado por el instalador y
  `createUpdateLanguagesPlugin(env)` dentro de `plugins`.
- Si el proyecto expone `/showroom`, configura esa ruta con
  `resources => templates` y `content => templates`; CORE no sobrescribe los
  ficheros de rutas propios de cada consumidor.
- Las personalizaciones desconocidas de `App/app/updateLanguage.php` y
  `_inlineEditor.js` se conservan juntas. Revisa el aviso de Composer y migra
  ese grupo manualmente si quieres adoptar el contrato nuevo. Conserva además
  la ruta POST `/languages/update` y la inicialización de `_inlineEditor.js` en
  el `_global.js` local.
- Vuelve a ejecutar `php App/tools/build-sitemap.php` tras definir la variable de entorno `RAIZ` (o su alias de host de producción) para regenerar el sitemap y sincronizar el `robots.txt` del proyecto.

## [1.0.0] - 2024-04-07
### Añadido
- Punto de partida para versionado semántico del núcleo y publicación de notas de versión en el README.
- Guía de actualización para clientes que consumen el paquete vía Composer.
- Registro de pruebas mínimas (helpers, controladores y smoke test de `public/index.php`).

### Instrucciones de actualización
- Actualiza la dependencia en el proyecto cliente a `^1.0` y ejecuta `composer update liquidstack/core`.
- Revisa las notas de la sección "Avisos por release" del README antes de desplegar una nueva versión.
- Ejecuta la batería de validaciones documentada en el README del proyecto cliente tras actualizar.
