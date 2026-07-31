# Auditoría de compatibilidad en proyectos consumidores

> Estado: pendiente y obligatoria antes de desplegar de forma general una
> versión de CORE con cambios estructurales.

## Objetivo

Comprobar en proyectos reales que `composer update liquidstack/core` sigue
siendo aditivo: incorpora recursos y correcciones sin sustituir
personalizaciones, borrar claves de idioma ni romper frontend, backend,
formularios, autenticación o editor inline.

Esta revisión es especialmente importante después de modificar el Installer,
el manifiesto gestionado, el contrato SCSS, las semillas, el sistema de
idiomas o la infraestructura del showroom.

## Inventario inicial

Localizar todos los `composer.json` que requieran `liquidstack/core` y registrar:

- proyecto y responsable;
- versión actual de CORE;
- versión de PHP y Node;
- estado limpio o personalizado del repositorio;
- rutas y funcionalidades críticas;
- entorno local disponible y forma de recuperación.

AIWA y ARRO serán los primeros consumidores de referencia. BASE se valida
como instalación nueva y no como actualización destructiva mientras tenga
cambios locales pendientes.

## Protocolo por proyecto

1. Crear una rama o referencia recuperable y guardar `git status -sb`.
2. Registrar la versión actual con `composer show liquidstack/core`.
3. Ejecutar la actualización de CORE de forma aislada.
4. Conservar la salida completa de `CORE sync seguro`, prestando atención a
   archivos actualizados, preservados, semillas y errores.
5. Revisar `git diff --name-status` y confirmar expresamente que se conservan:
   - `_config.scss`, variables y modificadores propios;
   - rutas, vistas y entrypoints locales;
   - copy y valores existentes de los JSON;
   - branding, correo, credenciales, base de datos y medios privados;
   - recursos modificados deliberadamente por el proyecto.
6. Ejecutar PHP lint, JSON lint, PHPUnit y el build de Vite.
7. Probar al menos:
   - inicio y páginas principales;
   - idiomas y cambio de URL;
   - formularios y respuestas asíncronas;
   - login y recuperación si existen;
   - editor inline de texto, imágenes, fondos, listas y vídeo;
   - showroom/templates y sus subrutas;
   - sitemap, robots y rutas privadas;
   - responsive en móvil, tablet y escritorio.
8. Repetir la sincronización sin cambios intermedios y comprobar que sea
   idempotente.
9. Registrar resultado, incidencias y cualquier baseline que CORE deba
   reconocer antes de continuar el despliegue.

## Criterio de salida

La actualización no se considera segura para el resto de proyectos hasta que:

- AIWA y ARRO hayan pasado el protocolo;
- un starter limpio creado desde BASE funcione;
- no haya pérdidas de copy ni sobreescrituras de personalizaciones;
- una segunda sincronización produzca cero cambios inesperados;
- exista una ruta de recuperación comprobada.

Si un consumidor falla, se detiene el despliegue y la corrección se realiza en
CORE y sus pruebas; no se parchea únicamente el proyecto afectado.
