# Changelog

Todas las versiones de `liquidstack/core` siguen [Semantic Versioning](https://semver.org/lang/es/) a partir de la 1.0.0. Documenta cada release en esta cronología y añade instrucciones de actualización visibles para los proyectos cliente.

## [Unreleased]
### Añadido
- `App/tools/build-sitemap.php` ahora crea/actualiza `public/robots.txt` y garantiza que la entrada del sitemap apunte al host de producción definido en las variables de entorno.

### Corregido
- `src/Core/Application.php` vuelve a adjuntar los assets compilados cuando una ruta define `resources` aunque no tenga fichero de contenidos asociado y mejora la lectura del flag `DEV_MODE`.

### Instrucciones de actualización
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
