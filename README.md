# Liquid Stack Core

`liquidstack/core` es el paquete comun para proyectos Liquid Stack.
Centraliza:

- Nucleo PHP (arranque, routing, helpers).
- Stubs backend reutilizables (`stubs/App`, `stubs/public`).
- Recursos frontend reutilizables (`resources/js`, `resources/scss`,
  `resources/img`, `resources/video`).
- Dependencias frontend minimas del core (`package.core.json`).
- Configuracion y skills base para agentes (`.codex`).

## Como sincroniza en proyectos cliente

Al ejecutar `composer install` o `composer update` en un proyecto que consume este paquete:

1. Se sincronizan los stubs y recursos de forma **aditiva por defecto**:
   - Un fichero nuevo de CORE se instala cuando todavía no existe en el
     consumidor.
   - Un fichero existente solo se actualiza si su estado registrado o una
     huella histórica de CORE permiten reconocer que sigue intacto. La
     comparación normaliza los finales de línea para que LF/CRLF no convierta
     un fichero intacto en una falsa personalización.
   - Si un controlador, template, SCSS, JS o asset de un recurso contiene una
     personalización desconocida, se conserva el grupo completo del recurso.
     Así no se mezclan piezas de contratos incompatibles.
   - La sincronización nunca borra ficheros del consumidor.
   - Los JSON de idiomas se fusionan recursivamente: CORE añade claves y
     propiedades ausentes, pero no sustituye ningún valor existente, incluidos
     `""`, `null`, `false`, `0` y las colecciones vacías. La inserción conserva
     el formato y los finales de línea del catálogo; no reserializa el fichero
     completo.

   El resultado se registra en `.liquidstack/core/managed-files.json`. Este
   manifiesto pertenece al proyecto consumidor y debe versionarse para que las
   siguientes actualizaciones puedan distinguir con precisión los ficheros
   intactos de sus personalizaciones.

   La familia `moduleFormContact01/02/03` incluye además un backend de contacto
   genérico (`formContact.php`, transporte PHPMailer, comprobaciones y
   catálogos de correo ES/EN/EU). El backend, las plantillas de email, el
   runtime legal, el footer y los logos son semillas: se instalan únicamente
   cuando faltan y cualquier variante local existente se conserva.
2. Se copian recursos frontend:
- `resources/js` -> `src/js/resources`.
- `resources/scss` -> `src/scss/resources`.
- `resources/img` -> `public/assets/img`.
- `resources/video` -> `public/assets/video`.

3. Se fusionan dependencias de `package.core.json` en el `package.json` del proyecto consumidor.
4. Se instala el watcher compartido de idiomas en
   `tools/liquidstack/vite/update-languages-plugin.mjs`. Si el proyecto
   conserva el bloque Vite legacy conocido, el instalador lo sustituye por el
   import del módulo sin alterar el resto de `vite.config.js` (puerto, entradas,
   plugins o build). Una configuración personalizada, enlazada o escrita como
   `vite.config.ts`/`.mjs`/`.cjs` se conserva intacta y requiere añadir
   manualmente el import y `createUpdateLanguagesPlugin(env)` a `plugins`.
   CORE nunca copia un `vite.config.js` completo sobre el consumidor.
5. Se sincroniza la guia base para agentes desde `.codex`:
   - `.codex/config.toml` se copia al proyecto solo si no existe. Una configuracion local existente nunca se sobrescribe.
   - Solo se consideran skills que sean subdirectorios directos de `.codex/skills` y contengan `SKILL.md`.
   - Las skills base se escriben siempre en `.codex/skills`, tambien en proyectos nuevos.
   - Cada carpeta de skill procedente de CORE es gestionada por CORE: sus archivos se actualizan y los archivos retirados de esa misma carpeta se eliminan.
   - Las skills locales hermanas, con nombres distintos a las de CORE, se conservan. Un manifiesto oculto `.liquidstack-core-skills.json` permite retirar unicamente carpetas que CORE gestionaba y que ya no existen en el origen.
   - Las skills base son autosuficientes y no dependen de guias `AGENTS_*.md`
     legacy en la raiz. Cada proyecto puede mantener un `AGENTS.md` minimo de
     compatibilidad y encapsular su contexto privado en skills locales con
     nombres propios.
   - Si una version previa de la sincronizacion dejo un manifiesto gestionado en `.agents/skills`, CORE retira solo esas copias antiguas y conserva las skills locales de ese directorio.
   - La sincronizacion rechaza destinos redirigidos mediante symlinks o junctions para no escribir ni borrar fuera del arbol real del proyecto.

La sincronizacion automatica anterior la realiza el plugin en los eventos
`post-install-cmd` y `post-update-cmd`. Los errores de la guia para agentes se
registran sin interrumpir Composer.

Son propiedad del proyecto y no se sobrescriben automáticamente las rutas,
el fichero `vite.config.js` completo, `src/scss/_config.scss`,
`src/scss/_global.scss`, los SCSS propios de páginas y las configuraciones
locales. Las vistas, idiomas de páginas y recursos adicionales con nombres
distintos también se conservan.

CORE puede migrar de forma quirúrgica el watcher legacy dentro de
`vite.config.js` únicamente cuando reconoce exactamente su implementación
histórica. Si la configuración no coincide con ese contrato, la preserva y
muestra las instrucciones para integrar el plugin manualmente.

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
- `STACK_CORE_RESOURCES_VIDEO_TARGET` (alias:
  `STACK_LIQUID_CORE_RESOURCES_VIDEO_TARGET`).

## Checklist: promover un recurso nuevo a CORE

Usa esta lista cada vez que subas un recurso nuevo al core.

### 1) Frontend del recurso

- Anadir JS en `resources/js`.
- Anadir SCSS en `resources/scss`.
- Si el recurso requiere imagenes:
- Dummies generales en `resources/img/dummy`.
- Iconos de sistema reutilizables en `resources/img/system`.
- Imagenes especificas del recurso en `resources/img/resources/<nombreRecurso>`.
- Logos genéricos de arranque en `resources/img/logos`; Composer solo los
  instalará cuando el proyecto todavía no tenga un fichero homónimo.
- Si requiere vídeo local reutilizable, añadir únicamente dummies y pistas
  genéricas en `resources/video`; los vídeos de cliente se mantienen fuera de
  CORE.

## Nota de estructura de imagenes

Esa estructura se conserva en destino. Ejemplo:

- Origen: `resources/img/resources/aniBackground01/*`
- Destino: `public/assets/img/resources/aniBackground01/*`

### 2) Registro del recurso en plantillas base del core

- Actualizar `src/js/templates.js` (imports + init del recurso).
- Actualizar `src/scss/templates.scss` (imports SCSS del recurso).

## Importante sobre `src/scss/_config.scss`, `src/scss/_global.scss` y `src/js/_global.js`

Esos archivos **no** se sincronizan automaticamente a proyectos cliente.
Solo se sincronizan de `src/`:

- `src/js/templates.js`
- `src/scss/templates.scss`

Por tanto:

- Los archivos `_config.scss` y `_global.scss` del proyecto cliente no se pisan.
- En este core se mantienen como referencia/base para desarrollo, pero no como override forzado en clientes.
- Los recursos estándar de CORE deben limitar sus referencias `c.$...` al
  contrato SCSS v1 de 40 variables documentado en
  `manifests/scss-config-contract-v1.json`.
- Un valor de tema específico de un recurso debe exponerse como una custom
  property CSS con fallback a una variable del contrato. El consumidor puede
  modificarla desde el contexto que hidrata la vista sin ampliar ni reescribir
  su `_config.scss`.
- Los SCSS de páginas son siempre locales y no forman parte de la
  sincronización gestionada.

### 3) Backend/stubs del recurso

- Actualizar idiomas de templates en:
- `stubs/App/config/languages/templates/es.json`
- `stubs/App/config/languages/templates/en.json`
- `stubs/App/config/languages/templates/eu.json`
- `App/tools/update-languages.php <slug>` hidrata de forma aditiva: conserva
  claves, tipos y propiedades existentes, incluidos los vacíos intencionales.
  La retirada de entradas antiguas solo se activa expresamente con
  `--prune-unused` y exige revisar el diff antes de conservar el resultado. El
  comando valida primero todos los catálogos para evitar escrituras parciales y
  siempre informa por fichero si fue `Creado`, `Actualizado` o quedó
  `Sin cambios`.
- Anadir controlador en `stubs/App/controllers/<recurso>.php`.
- Anadir template en `stubs/App/templates/_<recurso>.html`.
- Si el recurso amplía la edición inline, actualizar conjuntamente
  `resources/js/_inlineEditor.js` y `stubs/App/app/updateLanguage.php`, y
  comprobar que este último sigue registrado en
  `Installer::syncProjectAssets()`.
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

### Recursos compuestos y edición inline

Los recursos `hero06` y `hero07` son escenarios visuales con raíz `<header>`;
no contienen un H1 fijo. Reciben respectivamente `{hero06-content}` y
`{hero07-content}` para poder combinarse con `moduleH1Type03`,
`moduleH1Type04` u otro módulo equivalente. Los módulos H1 tienen raíz `<div>`
y admiten `header_level`, de modo que no añaden landmarks innecesarios y
conservan el escalado relacional de encabezados.

`src/scss/_global.scss` registra esos módulos como referencia de CORE, pero
ese entrypoint está protegido y no se sobrescribe en los consumidores. Cuando
un proyecto use los módulos fuera del bundle `templates`, debe añadir sus
propios `@use` al `_global.scss` local.

La familia de CTA incluye `moduleButtonType02`, con icono de imagen editable y
fallback `arrow-forward-outline.svg`; `moduleButtonType03`, con transición
expansiva e icono decorativo CSS; y `moduleButtonType04`, con interacción
convencional. Los tres conservan el enlace y el copy como objetos `data-lang`.

`art30` admite `items` entre 0 y 4 y `benefits` entre 0 y 6. Con
`benefits => 0` oculta el banner. En desarrollo, cada ficha y beneficio forma
un grupo editable; un `Ctrl + doble clic` en el fondo del banner abre todos sus
iconos y encabezados.

`art32` conserva el contrato semántico y de contenido de `art02`, pero integra
la variante de cards en caja: relleno, sombra, iconos filtrados y CTA opcional.
Admite `items`, `{header-primary}`, `header_level` y los slots
`{a-button-primary}` a `{z-button-primary}`. Sus columnas se adaptan sin
depender de modificadores SCSS de una vista concreta.

`artVideo01` compone encabezado, contenido, CTA y vídeo en dos columnas, con
`media_position => start|end`. `artVideo02` ofrece la variante vertical: el
article ocupa el 80 % en escritorio y el 90 % en tablet, mientras que el vídeo
se limita al 60 % y pasa al 100 % con relleno en móvil. Ambos parten de H3,
admiten `header_level` o un `{header-primary}` externo y omiten wrappers vacíos.

`moduleVideo01` permite seleccionar YouTube o vídeo local desde el editor
inline. YouTube usa una fachada ligera: no solicita thumbnail ni crea iframe
antes de `cookie_social=true`, y monta el iframe únicamente después de pulsar
reproducir. El modo local admite WebM, MP4, poster y pistas VTT editables,
valida rutas y extensiones y recarga el elemento `<video>` al guardar.

### Formularios de contacto modulares

`moduleFormContact01`, `moduleFormContact02` y `moduleFormContact03` comparten
el mismo HTML accesible y el mismo runtime asíncrono; solo cambia su diseño.
Son módulos atómicos con raíz `div`, sin encabezado documental, ficha de
contacto ni mapa, para poder combinarlos con otros recursos.

Por defecto envían el contrato legacy mediante `POST /form`:

```php
'/form' => 'formContact.php',
```

`App/config/routes/post.php` pertenece al proyecto y CORE no lo sobrescribe.
El proyecto consumidor debe conservar esa entrada. El backend genérico se
siembra desde CORE solo si no existe y utiliza:

- `App/app/formContact.php`
- `App/app/_phpmailer.php`
- `App/class/_comprobaciones.php`
- `App/config/languages/_email/{es,en,eu}.json`
- `_formContactAdmin.html` y `_formContactUser.html`

Configuración mínima en el `.env` del consumidor:

```dotenv
MAIL_HOST=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_PORT=465
MAIL_WEB=
MAIL_ADMIN=
EMISOR_NAME=
DOMAIN=
DOMAIN_URL=
```

`MAIL_LAD` y `MAIL_LAD_BIS` son copias ocultas opcionales. Las credenciales,
destinatarios, idiomas habilitados y plantillas personalizadas siguen siendo
responsabilidad de cada proyecto.

Este backend conserva compatibilidad con el flujo existente. No debe
considerarse todavía un sistema antispam endurecido: el reto aritmético se
resuelve en cliente y quedan pendientes para un refactor posterior CSRF,
limitación de frecuencia y validación server-side del consentimiento.

El editor inline sincronizado soporta fondos responsive, una imagen de fondo
única, colecciones de lista con icono opcional, medios de vídeo y grupos
compuestos opt-in. En `moduleList01` y en las listas editoriales nativas de los
recursos, `Ctrl + doble clic` sobre el propio texto abre todas las líneas del
bloque. La inicialización usa captura temprana, es segura frente a recargas HMR
y neutraliza listeners antiguos antes de que abran únicamente el `li`
pulsado. Su endpoint gestionado es `App/app/updateLanguage.php`; las rutas del
proyecto no se pisan, por lo que el consumidor debe conservar esta entrada en
su configuración POST:

```php
'/languages/update' => 'updateLanguage.php',
```

Cuando una imagen use variantes responsive, sus entradas relacionadas deben
seguir `<clave-base>_srcset01`, `<clave-base>_srcset02`, etc. y existir en
todos los idiomas. El editor las presenta junto a `src`, `alt` y `title` y
actualiza el atributo `srcset` sin recargar la página.

Al adoptar esta versión, Composer actualiza `App/app/updateLanguage.php` si
reconoce una copia intacta de CORE. Si el endpoint contiene una personalización
desconocida, conserva el grupo del editor inline para no mezclar contratos.

`src/js/_global.js` tampoco se sobrescribe. El proyecto debe conservar la
activación del editor:

```js
import initInlineEditor from "./resources/_inlineEditor.js";

initInlineEditor();
```

### Showroom canónico y ruta compatible

`_showroom.php` es el catálogo canónico de recursos. `_templates.php` se
mantiene como alias para no romper los stacks que todavía acceden a
`/{lang}/templates`. Ambas vistas usan el bundle y los idiomas `templates`.

Las rutas legacy que mantienen `resources => templates` y
`content => showroom` también son compatibles: la aplicación carga primero el
catálogo `templates` como base y después `showroom` como override. Así conserva
el copy particular existente y completa de forma aditiva las claves nuevas de
controladores sin reescribir el JSON legacy.

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

- Regenerar las huellas históricas después de cerrar los cambios gestionados:
  `php tools/build-managed-file-history.php`.
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

php tools/build-managed-file-history.php
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

## Mejoras pendientes

- [Autocompletado de recursos LiquidStack para VS Code](docs/mejoras-pendientes/autocompletado-vscode-recursos.md):
  propuesta de extensión propia para insertar controladores y completar sus
  opciones y slots públicos a partir de un índice generado por CORE.
