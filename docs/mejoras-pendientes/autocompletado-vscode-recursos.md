# Autocompletado de recursos LiquidStack para VS Code

> Estado: pendiente de implementación.
>
> Última revisión: 2026-07-29.
>
> Esta propuesta debe retomarse en un hilo de trabajo independiente. No existe
> todavía una extensión ni un contrato IDE definitivo.

## Objetivo

Facilitar la composición de vistas LiquidStack desde VS Code sin tener que
recordar de memoria:

- los nombres disponibles para `controller('<recurso>')`;
- las opciones admitidas por el tercer argumento, como `items`, `variant` o
  `header_level`;
- los slots públicos que aceptan HTML inyectado, como
  `{header-primary}`, `{a-content}` o `{a-button-primary}`;
- los valores por defecto, límites y variantes de cada opción;
- el controlador, template, SCSS y ejemplo de showroom asociados.

La experiencia objetivo es:

```php
echo controller('art02', 0, [
    'items' => 8,
    '{header-primary}' => $h2,
    '{a-button-primary}' => $button,
]);
```

Al escribir `controller('art`, VS Code debe sugerir recursos. Dentro del array
de parámetros debe ofrecer únicamente las opciones y slots públicos del
recurso seleccionado, indicando tipo, valor por defecto y documentación.

## Decisión técnica recomendada

Crear una extensión propia de VS Code, provisionalmente denominada
`LiquidStack Tools`, respaldada por un indexador de recursos proporcionado por
CORE.

Los snippets de proyecto pueden complementar la extensión con un prefijo como
`ls-controller`, pero no deben ser la fuente principal. Son plantillas
estáticas y no pueden decidir sus sugerencias según el controlador elegido, el
valor de `items` o el contenido real del workspace.

Para este alcance basta inicialmente con la API directa de VS Code:

- `CompletionItemProvider` para nombres, opciones y slots;
- `HoverProvider` para documentación y contrato semántico;
- `DefinitionProvider` para navegar a controlador y template;
- diagnósticos para claves desconocidas o duplicadas;
- comandos para insertar y abrir recursos.

No hace falta introducir un Language Server hasta que la funcionalidad deba
reutilizarse en otros editores o requiera análisis semántico mucho más amplio.

## Resolución de recursos

El índice debe reproducir la precedencia real del helper `controller()`:

1. `App/controllers` y `App/templates` del proyecto consumidor;
2. `vendor/liquidstack/core/stubs/App/controllers` y
   `vendor/liquidstack/core/stubs/App/templates` como fallback;
3. cuando se trabaje directamente en CORE, `stubs/App/controllers` y
   `stubs/App/templates`.

Un recurso local con el mismo nombre debe prevalecer sobre el de CORE. El
catálogo no puede generarse únicamente desde CORE: durante la auditoría inicial
AIWA contenía un controlador adicional propio.

La extensión debe vigilar cambios en:

- los directorios anteriores;
- `composer.lock`;
- los metadatos declarativos que se definan para casos no inferibles.

## Indexador perteneciente a CORE

CORE debe exponer un indexador PHP reutilizable por la extensión. Debe analizar
los archivos de forma estática, por ejemplo mediante `token_get_all()`, sin
usar `require` sobre los controladores ni ejecutar código del proyecto.

El indexador puede deducir automáticamente:

- el nombre desde el archivo y `function controller_<nombre>`;
- el template desde `render('App/templates/_<nombre>.html', ...)`;
- opciones literales leídas desde `$params`;
- defaults, límites o enums cuando estén expresados de forma reconocible;
- `header_level` cuando el recurso use `resolve_header_levels()`;
- slots literales declarados en `$vars`;
- documentación de copy desde el docblock;
- etiqueta raíz y encabezados del template.

El resultado debe ser JSON generado y cacheable, no un catálogo central
rellenado manualmente.

Ejemplo orientativo:

```json
{
  "name": "art02little",
  "source": "project",
  "controllerFile": "App/controllers/art02little.php",
  "templateFile": "App/templates/_art02little.html",
  "rootTag": "article",
  "options": {
    "items": {
      "type": "integer",
      "default": 2,
      "minimum": 1,
      "maximum": 3
    },
    "variant": {
      "type": "enum",
      "values": ["image", "icon"]
    },
    "header_level": {
      "type": "integer",
      "minimum": 1,
      "maximum": 6
    }
  },
  "slots": {
    "{header-primary}": {
      "type": "html",
      "description": "Encabezado principal inyectado"
    }
  },
  "repeatedSlots": [
    {
      "pattern": "{letter}-content",
      "countOption": "items"
    },
    {
      "pattern": "{letter}-button-primary",
      "countOption": "items"
    }
  ]
}
```

## Información que actualmente no puede inferirse con seguridad

Los controladores terminan normalmente con `array_replace($vars, $params)`.
Esto hace que cualquier placeholder interno pueda sobrescribirse técnicamente,
pero no significa que todos deban mostrarse como API pública.

El indexador debe distinguir:

- **Opciones públicas:** `items`, `variant`, `benefits`, `header_level`, etc.
- **Slots públicos:** contenido o módulos que la vista puede inyectar.
- **Campos avanzados:** overrides permitidos pero no recomendados.
- **Placeholders internos:** `classVar`, `*-dl`, `*-text`, atributos generados
  y otros detalles que no deben contaminar el autocompletado habitual.

Los slots repetidos se construyen a menudo dinámicamente mediante letras y no
pueden descubrirse de forma fiable con una expresión regular. La solución
gradual propuesta es:

1. inferir automáticamente todo lo inequívoco;
2. permitir anotaciones mínimas para slots dinámicos o públicos;
3. comprobar esas anotaciones mediante tests;
4. estudiar posteriormente helpers declarativos consumidos por el propio
   controlador, de modo que defaults, enums y límites tengan una única fuente
   de verdad.

No debe crearse manualmente un snippet distinto para cada recurso.

## Funcionalidades del MVP

### Autocompletado

- Sugerir recursos al escribir el primer argumento de `controller()`.
- Insertar opcionalmente la llamada completa mediante un snippet dinámico.
- Detectar el recurso seleccionado dentro del tercer argumento.
- Sugerir opciones y slots públicos todavía no usados.
- Mostrar tipo, default, rango, enum y descripción.
- Si `items` es literal, generar los slots de `a` hasta la letra
  correspondiente.
- Proponer la clave correcta ante errores como `{primary-header}` cuando el
  contrato expone `{header-primary}`.

### Documentación y navegación

- Hover sobre el nombre con:
  - tipo semántico y etiqueta raíz;
  - encabezado natural y escalado;
  - docblock de copy;
  - opciones, slots y defaults;
  - procedencia local o CORE.
- Ctrl+clic hacia el controlador.
- Comandos para abrir template, SCSS y ejemplo del showroom.

### Inserción asistida

Añadir el comando `LiquidStack: Insertar recurso`, accesible desde la paleta y
mediante un atajo configurable. Debe permitir:

1. buscar el recurso;
2. elegir índice;
3. indicar el número de items cuando proceda;
4. seleccionar slots opcionales;
5. insertar PHP formateado.

No se debe imponer inicialmente una combinación global de teclas que pueda
entrar en conflicto con otras extensiones.

## Integración con Composer y recursos nuevos

- La extensión debe leer el CORE realmente instalado en `vendor`, por lo que
  un `composer update liquidstack/core` actualizará el catálogo base sin
  necesitar una versión nueva de la extensión por cada recurso.
- Los recursos locales se indexarán directamente desde `App`.
- La caché generada no debe versionarse.
- CORE puede regenerarla mediante un comando específico o la extensión al
  detectar cambios.
- La rutina de creación y promoción de recursos deberá incluir la revisión del
  contrato IDE o sus anotaciones.
- Al promover un recurso desde un consumidor a CORE deben viajar también sus
  metadatos no inferibles y sus pruebas de contrato.
- La sincronización no debe sobrescribir la configuración `.vscode` privada de
  cada proyecto.

## Repositorio y distribución

La extensión debe vivir en un repositorio independiente de
`liquidstack/core`, porque tiene toolchain, dependencias y ciclo de versiones
propios. CORE conservará el indexador y el contrato de recursos.

Orden recomendado:

1. desarrollar y probar la extensión localmente;
2. generar un `.vsix` para uso interno;
3. validar el flujo en CORE, AIWA y un consumidor limpio;
4. publicar cuando sea estable;
5. solo entonces recomendar su ID desde `.vscode/extensions.json`, sin
   pretender instalarla automáticamente.

## Pruebas mínimas

- El indexador localiza todos los controladores de CORE y sus templates.
- Un controlador local prevalece sobre otro homónimo de CORE.
- Los recursos sin template o con función incorrecta generan diagnóstico.
- `items`, enums, límites y `header_level` se extraen correctamente.
- `art02little` genera slots repetidos según `items`.
- Los placeholders internos no aparecen en las sugerencias normales.
- Las claves ya usadas no vuelven a sugerirse.
- El autocompletado funciona con arrays en una y varias líneas.
- Los cambios tras `composer update` invalidan la caché.
- El paquete VSIX se prueba en Windows y en un workspace consumidor.

## Fuera del MVP

- Preview visual embebida del showroom.
- Edición directa de los JSON de idioma.
- Refactor automático de todos los controladores actuales.
- Soporte para otros editores mediante LSP.
- Publicación automática en Marketplace.

## Próximo hilo de trabajo

1. Elegir nombre y repositorio de la extensión.
2. Definir el esquema JSON del índice.
3. Implementar el indexador estático en CORE con fixtures de recursos
   representativos.
4. Crear el MVP de `CompletionItemProvider`.
5. Añadir navegación, hover y comando de inserción.
6. Empaquetar un VSIX y probarlo en AIWA.
7. Documentar instalación y decidir el mecanismo de publicación.

## Referencias oficiales

- [Snippets en Visual Studio Code](https://code.visualstudio.com/docs/editing/userdefinedsnippets)
- [Programmatic Language Features](https://code.visualstudio.com/api/language-extensions/programmatic-language-features)
- [Publicación y empaquetado de extensiones](https://code.visualstudio.com/api/working-with-extensions/publishing-extension)
- [Extensiones recomendadas por workspace](https://code.visualstudio.com/docs/configure/extensions/extension-marketplace#_workspace-recommended-extensions)
