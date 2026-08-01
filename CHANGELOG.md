# Changelog

Todas las versiones de `liquidstack/core` siguen [Semantic Versioning](https://semver.org/lang/es/) a partir de la 1.0.0. Documenta cada release en esta cronología y añade instrucciones de actualización visibles para los proyectos cliente.

## [Unreleased]
### Añadido
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
  DB compartida, esquema, clave de seguridad y protección de argumentos en
  trazas. El informe no revela valores y distingue `runtime_ready` de
  `bootstrap_ready`.
- Esquema inicial versionado de identidad, roles, capacidades, tokens,
  sesiones, límites, auditoría, outbox y estado; bootstrap explícito e
  idempotente de `system_superadmin`/`site_admin`; y primer flujo HTTP aislado
  de login, autorización, panel mínimo y logout. Las migraciones y el bootstrap
  nunca se ejecutan durante `composer update`.
- Contrato PDO compartido estricto para MySQL/MariaDB y SQLite, registro de
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
