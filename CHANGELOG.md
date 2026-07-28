# Changelog

Todas las versiones de `liquidstack/core` siguen [Semantic Versioning](https://semver.org/lang/es/) a partir de la 1.0.0. Documenta cada release en esta cronología y añade instrucciones de actualización visibles para los proyectos cliente.

## [Unreleased]
### Añadido
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
- Suite reproducible con `composer test` para validar la sincronización, los eventos del plugin, la convivencia con skills locales, la retirada de copias gestionadas antiguas y la protección frente a junctions.
- Comando interactivo `composer release` para sugerir la siguiente versión SemVer estable, ejecutar las validaciones y publicar `main` junto con un tag anotado mediante un push atómico. El webhook existente de Packagist recibe la nueva etiqueta sin almacenar tokens adicionales.
- `src/Core/Composer/Installer.php` sincroniza tambien `resources/img` hacia `public/assets/img` durante `composer install`/`composer update`; puedes sobrescribir el destino con `STACK_CORE_RESOURCES_IMG_TARGET` (alias legado: `STACK_LIQUID_CORE_RESOURCES_IMG_TARGET`).
- `src/Core/Composer/Installer.php` ahora fusiona dependencias frontend desde `package.core.json` al `package.json` del proyecto consumidor durante `composer install`/`composer update`, añadiendo solo paquetes faltantes sin sobrescribir versiones existentes.
- `App/tools/build-sitemap.php` ahora crea/actualiza `public/robots.txt` y garantiza que la entrada del sitemap apunte al host de producción definido en las variables de entorno.

### Corregido
- `composer release` distingue una rama simplemente atrasada de un historial
  local y remoto divergente, evitando recomendar `git pull --ff-only` cuando
  ese comando no puede resolver el estado.
- `art02` usa `gap` en ambos ejes y reduce de cuatro a tres líneas la
  altura reservada para sus encabezados de tarjeta, corrigiendo la falta de
  separación vertical y el exceso de espacio.
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
- Ejecuta `composer update liquidstack/core` para recibir los nuevos recursos,
  el showroom, la configuración y las skills base. Las skills locales deben
  usar nombres de carpeta distintos a los gestionados por CORE.
- Si el proyecto expone `/showroom`, configura esa ruta con
  `resources => templates` y `content => templates`; CORE no sobrescribe los
  ficheros de rutas propios de cada consumidor.
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
