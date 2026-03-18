# Liquid Stack Core

`liquidstack/core` es el paquete comun para proyectos Liquid Stack.
Centraliza:

- Nucleo PHP (arranque, routing, helpers).
- Stubs backend reutilizables (`stubs/App`, `stubs/public`).
- Recursos frontend reutilizables (`resources/js`, `resources/scss`, `resources/img`).
- Dependencias frontend minimas del core (`package.core.json`).

## Como sincroniza en proyectos cliente

Al ejecutar `composer install` o `composer update` en un proyecto que consume este paquete:

1. Se copian stubs backend desde `stubs/` al proyecto.
2. Se copian recursos frontend:
- `resources/js` -> `src/js/resources` (y copia en `vendor/liquidstack/core/resources/js`).
- `resources/scss` -> `src/scss/resources` (y copia en `vendor/liquidstack/core/resources/scss`).
- `resources/img` -> `public/assets/img`.
3. Se fusionan dependencias de `package.core.json` en el `package.json` del proyecto consumidor.

Scripts disponibles:

```bash
composer liquidstack-core:sync-resources
composer liquidstack-core:sync-frontend-deps
```

Alias legado soportado:

```bash
composer stack-liquid-core:sync-resources
```

Variables de entorno soportadas:

- `STACK_CORE_RESOURCES_TARGET` (alias: `STACK_LIQUID_CORE_RESOURCES_TARGET`).
- `STACK_CORE_RESOURCES_IMG_TARGET` (alias: `STACK_LIQUID_CORE_RESOURCES_IMG_TARGET`).

## Checklist: traer un recurso nuevo desde liquidstack_base

Usa esta lista cada vez que subas un recurso nuevo al core.

### 1) Frontend del recurso

- Anadir JS en `resources/js`.
- Anadir SCSS en `resources/scss`.
- Si el recurso requiere imagenes:
- Dummies generales en `resources/img/dummy`.
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
- Actualizar `stubs/App/views/_templates.php` para mostrar el ejemplo del recurso.

### 4) Dependencias NPM del recurso

Si el recurso necesita librerias nuevas (ejemplo `three`):

- Declararlas en `package.core.json`.

Reglas de fusion en proyecto cliente:

- Solo agrega paquetes faltantes.
- No borra paquetes del proyecto.
- No reemplaza versiones ya declaradas por el proyecto.

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

Si cambias recursos en `resources/`:

```bash
composer liquidstack-core:sync-resources
```

Si cambias dependencias en `package.core.json`:

```bash
composer liquidstack-core:sync-frontend-deps
```

Si cambias stubs o `src/js/templates.js` / `src/scss/templates.scss`, ejecuta:

```bash
composer update liquidstack/core
```

## Publicacion de cambios del core

1. Subir cambios al repo de `liquidstack/core`.
2. Etiquetar version SemVer.
3. En cada proyecto cliente: `composer update liquidstack/core`.
4. Ejecutar instalacion frontend del proyecto (`npm install`, `pnpm install` o `yarn install`) si se anadieron dependencias.
