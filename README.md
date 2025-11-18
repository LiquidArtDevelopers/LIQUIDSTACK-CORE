# Liquid Stack Core

Este paquete incluye tanto el núcleo PHP de Liquid Stack como los recursos de front reutilizables para JavaScript y SCSS.

## Versionado, changelog y avisos de release

`liquidstack/core` adopta versionado semántico desde la `1.0.0`. Los cambios deben registrarse en `CHANGELOG.md` junto con los pasos de actualización recomendados para proyectos cliente.
- Cada release debe añadir en este README un breve aviso con breaking changes o nuevas deprecaciones para que los integradores tengan la guía a mano durante los despliegues.
- Cuando se marquen APIs como `@deprecated`, indica la versión de retirada esperada y mantén la compatibilidad hasta la siguiente versión mayor.

### Avisos de release

- **1.0.0**: Se formaliza el esquema SemVer, se publica el `CHANGELOG.md` y se documenta la política de deprecación. Ejecuta la suite de pruebas (`composer test`) después de actualizar.

## Sincronización de assets

Los recursos del paquete (`vendor/liquidstack/core/resources/js` y `vendor/liquidstack/core/resources/scss`) se copian automáticamente después de `composer install` y `composer update` hacia un directorio accesible para el proyecto. Por defecto se depositan en `src/js/resources` y `src/scss/resources` (además de mantener una copia en `vendor/liquidstack/core/resources`), pero puedes cambiar el destino indicando la variable de entorno `STACK_CORE_RESOURCES_TARGET` con una ruta absoluta o relativa al proyecto (por ejemplo, `src/resources`). Se mantiene `STACK_LIQUID_CORE_RESOURCES_TARGET` como alias heredado para facilitar la migración.

Si necesitas relanzar la sincronización manualmente ejecuta el script Composer dedicado:

```bash
composer liquidstack-core:sync-resources
```

El alias heredado `composer stack-liquid-core:sync-resources` permanece disponible para proyectos que aún no hayan actualizado sus scripts.

## Qué entra en el core y cómo se replica en los proyectos

- **PHP agnóstico**: los controladores y templates reutilizables viven en `stubs/App/controllers` y `stubs/App/templates` y se copian automáticamente a `App/` tras `composer install`/`composer update`. Si existe un controlador homónimo en `App/controllers`, se prioriza como override local; en caso contrario se recurre al del core. Las herramientas CLI compartidas (`App/tools`) también se replican al proyecto para que puedan ejecutarse allí, de modo que el directorio `App/tools` reaparece en cada instalación aunque no se mantenga versionado.
- **Entrypoint y helpers**: se sincronizan `public/index.php`, `App/config/helpers.php`, `App/app/url.php`, los scripts de idiomas y el sitemap. No se distribuye ningún `App/bootstrap.php`; en los proyectos cliente se puede eliminar con seguridad si quedó como rastro de versiones antiguas.
- **Assets front**: continúan en `resources/js` y `resources/scss` y se copian con `liquidstack-core:sync-resources`.

Algunos módulos JS incluidos en `resources/js` emplean GSAP para animaciones.

Para migrar código agnóstico que todavía resida en un proyecto cliente, muévelo a la ruta equivalente bajo `stubs/App/` y ajusta la versión del paquete. Tras publicar la nueva versión, un `composer update liquidstack/core` en los proyectos heredados desplegará los cambios sin tocar sus carpetas específicas (`App/files`, `App/models`, `App/views`, `App/config`, etc.).

## Dónde actualizar el core y cómo publicarlo

- El código fuente del core vive en el paquete `liquidstack/core` (este repositorio). Cualquier cambio agnóstico debe aplicarse aquí, dentro de `stubs/` para PHP o `resources/` para assets front (en versiones antiguas estas rutas tenían nombres distintos; migrar a las rutas actuales cuando corresponda).
- Una vez incorporados los cambios, sube la nueva versión del paquete al repositorio VCS que comparten los proyectos cliente (por ejemplo, el repo remoto configurado para `liquidstack/core`) y etiqueta con SemVer (`git tag 1.0.X`).
- En los proyectos que consumen el stack, ejecuta `composer update liquidstack/core` para que Composer tome la nueva release y refresque controladores, templates, tools y assets agnósticos.

## Integración con Vite

Para consumir los assets desde Vite añade un alias que apunte al directorio donde se han copiado (por defecto `vendor/liquidstack/core`):

```js
import { defineConfig } from "vite";
import { resolve } from "path";

export default defineConfig({
  resolve: {
    alias: {
      "~liquidstack-core": resolve(__dirname, "vendor/liquidstack/core"),
    },
  },
});
```

Con el alias creado puedes importar directamente los recursos empaquetados:

```scss
@use "~liquidstack-core/src/scss/_global.scss";
@use "~liquidstack-core/resources/scss/_artAccordion01.scss";
```

```js
import "~liquidstack-core/src/js/_global.js";
import "~liquidstack-core/resources/js/_toast.js";
```

Los archivos de configuración global (`_config.scss`, `_global.scss` y `_global.js`) se ubican ahora fuera de `resources` para reflejar que su contenido debe adaptarse a cada proyecto. Puedes importarlos directamente desde `src/` o usarlos como punto de partida para tus propias variantes.

Si cambiaste el destino con `STACK_CORE_RESOURCES_TARGET` (o su alias legado `STACK_LIQUID_CORE_RESOURCES_TARGET`), ajusta el alias para que apunte a esa ruta antes de compilar con Vite.
