# Liquid Stack Core

`liquidstack/core` es el paquete comun para proyectos Liquid Stack.
Centraliza:

- Nucleo PHP (arranque, routing, helpers).
- Stubs backend reutilizables (`stubs/App`, `stubs/public`).
- Recursos frontend reutilizables (`resources/js`, `resources/scss`, `resources/img`).
- Dependencias frontend minimas del core (`package.core.json`).
- Configuracion y skills base para agentes (`.codex`).

## Como sincroniza en proyectos cliente

Al ejecutar `composer install` o `composer update` en un proyecto que consume este paquete:

1. Se copian stubs backend desde `stubs/` al proyecto.
   Los archivos homónimos gestionados por CORE se actualizan; los archivos
   adicionales del proyecto se conservan porque la sincronización no borra
   elementos que solo existen en el consumidor.
2. Se copian recursos frontend:
- `resources/js` -> `src/js/resources` (y copia en `vendor/liquidstack/core/resources/js`).
- `resources/scss` -> `src/scss/resources` (y copia en `vendor/liquidstack/core/resources/scss`).
- `resources/img` -> `public/assets/img`.
3. Se fusionan dependencias de `package.core.json` en el `package.json` del proyecto consumidor.
4. Se sincroniza la guia base para agentes desde `.codex`:
   - `.codex/config.toml` se copia al proyecto solo si no existe. Una configuracion local existente nunca se sobrescribe.
   - Solo se consideran skills que sean subdirectorios directos de `.codex/skills` y contengan `SKILL.md`.
   - Las skills base se escriben siempre en `.codex/skills`, tambien en proyectos nuevos.
   - Cada carpeta de skill procedente de CORE es gestionada por CORE: sus archivos se actualizan y los archivos retirados de esa misma carpeta se eliminan.
   - Las skills locales hermanas, con nombres distintos a las de CORE, se conservan. Un manifiesto oculto `.liquidstack-core-skills.json` permite retirar unicamente carpetas que CORE gestionaba y que ya no existen en el origen.
   - Si una version previa de la sincronizacion dejo un manifiesto gestionado en `.agents/skills`, CORE retira solo esas copias antiguas y conserva las skills locales de ese directorio.
   - La sincronizacion rechaza destinos redirigidos mediante symlinks o junctions para no escribir ni borrar fuera del arbol real del proyecto.

La sincronizacion automatica anterior la realiza el plugin en los eventos
`post-install-cmd` y `post-update-cmd`. Los errores de la guia para agentes se
registran sin interrumpir Composer.

## Scripts Composer y paquete raiz

Los scripts declarados en el `composer.json` de este repositorio solo estan
disponibles cuando `liquidstack/core` es el paquete raiz (por ejemplo, dentro
de un checkout de CORE):

```bash
composer test
composer release
composer liquidstack-core:sync-resources
composer liquidstack-core:sync-frontend-deps
```

Composer no importa los scripts de una dependencia en el `composer.json` del
proyecto consumidor. En los clientes, `composer install` y `composer update`
activan las sincronizaciones automaticamente mediante el plugin. Si un cliente
necesita comandos manuales, debe declararlos expresamente en el
`composer.json` de ese proyecto raiz:

```json
{
  "scripts": {
    "liquidstack-core:sync-resources": "App\\Core\\Composer\\Installer::syncResources",
    "liquidstack-core:sync-frontend-deps": "App\\Core\\Composer\\Installer::syncFrontendDependencies",
    "liquidstack-core:sync-agent-guidance": "App\\Core\\Composer\\Installer::syncAgentGuidance"
  }
}
```

Variables de entorno soportadas:

- `STACK_CORE_RESOURCES_TARGET` (alias: `STACK_LIQUID_CORE_RESOURCES_TARGET`).
- `STACK_CORE_RESOURCES_IMG_TARGET` (alias: `STACK_LIQUID_CORE_RESOURCES_IMG_TARGET`).

## Checklist: promover un recurso nuevo a CORE

Usa esta lista cada vez que subas un recurso nuevo al core.

### 1) Frontend del recurso

- Anadir JS en `resources/js`.
- Anadir SCSS en `resources/scss`.
- Si el recurso requiere imagenes:
- Dummies generales en `resources/img/dummy`.
- Iconos de sistema reutilizables en `resources/img/system`.
- Imagenes especificas del recurso en `resources/img/resources/<nombreRecurso>`.

## Nota de estructura de imagenes

Esa estructura se conserva en destino. Ejemplo:

- Origen: `resources/img/resources/aniBackground01/*`
- Destino: `public/assets/img/resources/aniBackground01/*`

### 2) Registro del recurso en plantillas base del core

- Actualizar `src/js/templates.js` (imports + init del recurso).
- Actualizar `src/scss/templates.scss` (imports SCSS del recurso).

## Importante sobre `src/scss/_config.scss`, `src/scss/_global.scss` y `src/js/_global.js`

Esos archivos **no** se sincronizan automaticamente a proyectos cliente en el instalador actual.
Solo se sincronizan de `src/`:

- `src/js/templates.js`
- `src/scss/templates.scss`

Por tanto:

- Los archivos `_config.scss` y `_global.scss` del proyecto cliente no se pisan.
- En este core se mantienen como referencia/base para desarrollo, pero no como override forzado en clientes.

### 3) Backend/stubs del recurso

- Actualizar idiomas de templates en:
- `stubs/App/config/languages/templates/es.json`
- `stubs/App/config/languages/templates/en.json`
- `stubs/App/config/languages/templates/eu.json`
- Anadir controlador en `stubs/App/controllers/<recurso>.php`.
- Anadir template en `stubs/App/templates/_<recurso>.html`.
- Registrar la composición en `stubs/App/views/_showroom.php`.
- Hacer que el encabezado principal del ejemplo contenga el identificador
  exacto del recurso para poder localizarlo con la busqueda del navegador. Si
  el encabezado se inyecta desde otro modulo, usar un indice independiente y
  mencionar los modulos relevantes en el rotulo.
- Conservar el lorem/Matrix de cuerpo e interiores y las imagenes dummy. No
  anadir un encabezado semanticamente falso dentro de un recurso visual que no
  lo tenga por contrato.
- Comprobar también `/templates`: `stubs/App/views/_templates.php` es el alias
  histórico que carga el mismo showroom.

### Showroom canónico y ruta compatible

`_showroom.php` es el catálogo canónico de recursos. `_templates.php` se
mantiene como alias para no romper los stacks que todavía acceden a
`/{lang}/templates`. Ambas vistas usan el bundle y los idiomas `templates`.

El instalador sincroniza las vistas, pero deliberadamente no sobrescribe
`App/config/routes/get.php` ni `App/config/rutas.js`, porque contienen rutas
propias de cada proyecto. Para exponer también `/showroom`, registra en el
consumidor una ruta equivalente a esta para cada idioma:

```php
'/es/showroom' => [
    'resources' => 'templates',
    'content'   => 'templates',
    'view'      => '../App/views/_showroom.php',
],
```

En `App/config/rutas.js`, la ruta homóloga debe apuntar igualmente a
`templates`:

```js
'/es/showroom': 'templates',
```

### 4) Dependencias NPM del recurso

Si el recurso necesita librerias nuevas (ejemplo `three`):

- Declararlas en `package.core.json`.

Reglas de fusion en proyecto cliente:

- Solo agrega paquetes faltantes.
- No borra paquetes del proyecto.
- No reemplaza versiones ya declaradas por el proyecto.

### 5) Validación antes de publicar

- Ejecutar `php -l` sobre los controladores y vistas añadidos.
- Validar los JSON de `templates` en todos los idiomas base.
- Compilar `src/scss/templates.scss`.
- Ejecutar `composer test`.
- Probar `/showroom` y `/templates` en un consumidor enlazado, sin usar como
  fixture un proyecto que tenga cambios locales coincidentes.

## Guia de trabajo local (localhost:1309)

Este repositorio no trae una app completa para renderizar por si solo.
La forma recomendada es trabajar con un proyecto laboratorio basado en `liquidstack_base` usando este core local enlazado.

### Paso 1) Preparar proyecto laboratorio

En el `composer.json` del proyecto laboratorio, usar repositorio `path` hacia este core local:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../LIQUIDSTACK-CORE",
      "options": { "symlink": true }
    }
  ]
}
```

Luego:

```bash
composer update liquidstack/core
```

Con `symlink: true`, los cambios que hagas en este repo se reflejan en el proyecto laboratorio.

### Paso 2) Instalar frontend del laboratorio

```bash
npm install
```

### Paso 3) Levantar Vite en puerto 1309

```bash
npm run dev -- --host localhost --port 1309
```

Si el script `dev` del laboratorio ya fija host/port, basta con `npm run dev`.

### Paso 4) Refrescar sincronizaciones cuando toque

En el proyecto laboratorio, ejecuta:

```bash
composer update liquidstack/core
```

Esto refresca stubs, recursos, dependencias frontend y guia para agentes. Los
comandos de sincronizacion selectiva solo estaran disponibles en el laboratorio
si se han declarado en el `composer.json` raiz como se muestra en la seccion
"Scripts Composer y paquete raiz".

## Publicacion de cambios del core

CORE incluye un comando interactivo que publica el commit y su etiqueta
anotada en una unica operacion atomica. Las preguntas se realizan mediante la
entrada interactiva nativa de Composer, tambien desde PowerShell en Windows:

Uso rapido desde PowerShell:

```powershell
cd C:\xampp\htdocs\__LIQUIDSTACK\LIQUIDSTACK-CORE

git add .
git commit -m "Descripción del cambio"

composer release
```

No es necesario ejecutar antes `git push`: `composer release` sube
simultaneamente `main` y la etiqueta. El comando:

1. exige estar en `main` con el arbol de trabajo limpio;
2. actualiza las etiquetas y comprueba que `main` no vaya por detras de
   `origin/main`;
3. muestra las siguientes opciones patch, minor y major;
4. permite escribir otra version antes de continuar;
5. ejecuta `composer validate` y `composer test`;
6. muestra commit, remoto y etiqueta y pide confirmacion;
7. crea un tag anotado y ejecuta un `git push --atomic`;
8. elimina el tag local recien creado si el push falla.

Una vez completado `composer release`, el commit ya forma parte de
`origin/main` y tiene una etiqueta asociada. Para añadir cambios posteriores,
crea un commit nuevo; no uses `git commit --amend` sobre el commit publicado.
Si la rama local y `origin/main` aparecen como divergidas, no uses
`git pull --ff-only` ni `git push --force`: conserva primero una referencia de
respaldo y reconcilia el historial antes de volver a publicar.

Ejemplo desde la etiqueta historica `v1.4.01`:

```text
Ultima etiqueta: v1.4.01 (interpretada como v1.4.1)
Patch: v1.4.2
Minor: v1.5.0
Major: v2.0.0
```

Las nuevas etiquetas deben usar SemVer estable canonico `vX.Y.Z`, sin ceros iniciales. Para
elegir directamente el incremento minor o simular el proceso:

```bash
composer release -- --bump=minor
composer release -- --version=v1.5.0
composer release -- --version=v1.5.0 --dry-run
```

La primera vez, ejecuta `composer install` para disponer de la suite local.
`vendor`, `composer.lock` y la cache de PHPUnit estan ignorados en este
repositorio.

El webhook de Packagist configurado en GitHub recibe el evento `push`, por lo
que una etiqueta publicada aparece automaticamente como nueva version del
paquete. No hace falta crear una GitHub Release ni guardar un token de
Packagist en el repositorio.

Despues de publicar:

1. En cada proyecto cliente: `composer update liquidstack/core`.
2. Ejecutar instalacion frontend (`npm install`, `pnpm install` o
   `yarn install`) si se anadieron dependencias.
