---
name: dev-stack
description: "Desarrollo y mantenimiento de proyectos LiquidStack PHP/Vite: creación o mejora de recursos, templates, vistas/snipers, controladores PHP, placeholders, data-lang, JSON de idiomas, SCSS, JS, items variables, jerarquía semántica, hidratación y promoción posterior a liquidstack/core. Usar cuando Codex deba crear, refactorizar, revisar o registrar recursos y lógica de renderizado de un stack consumidor."
---

# Dev Stack

## Preparación

1. Respetar las instrucciones aplicables del repositorio y cargar las skills
   locales complementarias cuando existan; no depender de guías legacy en la
   raíz del proyecto.
2. Ejecutar `git status --short` antes de editar y preservar cambios ajenos.
3. Inspeccionar un recurso de la misma familia antes de crear otro. Mantener sus convenciones de nombres, firma del controlador, placeholders e imports.
4. Verificar la firma vigente de `controller()` y `resolve_header_levels()` en `src/Core/Support/helpers.php` o en el paquete instalado. Si una guía antigua contradice el helper real, seguir el código vigente y corregir la guía del proyecto.
5. Tratar el proyecto consumidor como laboratorio. No modificar `vendor/liquidstack/core` como fuente.

## Modelo del stack

El contenido se resuelve por URL e idioma. La aplicación carga los JSON de la ruta como variables-objeto globales y la vista invoca recursos con `controller()`. Cada controlador carga un template HTML, reemplaza placeholders y devuelve el HTML renderizado.

En desarrollo, Vite puede ejecutar automáticamente la sincronización de
idiomas al cambiar PHP. La hidratación normal es aditiva: añade claves y
propiedades ausentes, pero no elimina entradas ni sustituye valores existentes,
incluidos los vacíos intencionales. La poda requiere ejecutar expresamente el
actualizador con `--prune-unused` y revisar su diff. Esa automatización no
registra los `@use` SCSS ni sustituye la revisión humana del diff.

Un recurso reutilizable completo suele incluir:

- `App/templates/_<recurso>.html`
- `App/controllers/<recurso>.php`
- una llamada `controller('<recurso>', $index, $params)` en la vista
- `src/scss/resources/_<recurso>.scss`
- `src/js/resources/_<recurso>.js` solo si necesita comportamiento
- imports SCSS/JS en la entrada de la vista
- ejemplo e hidratación en el partial de categoría
  `App/views/showroom/_<categoria>.php`; `_showroom.php` es el shell canónico
  y `_templates.php` carga ese mismo catálogo como alias compatible
- valores de referencia en `App/config/languages/templates/{es,eu,en}.json`, según los idiomas presentes

Seguir siempre el patrón real del repositorio si una ruta difiere.

## Contrato del recurso

### Semántica estructural obligatoria

Determinar la familia semántica antes de escribir el template. Las etiquetas no son contenedores de layout intercambiables:

| Raíz del recurso | Encabezado natural | Unidades interiores | Encabezado interior natural |
| --- | --- | --- | --- |
| `section` | `h2` | `article` | `h3` |
| `article` | `h3` | `div` | `h4` |

- En un recurso raíz `section`, usar `article` para segregar unidades de contenido autónomas.
- En un recurso raíz `article`, usar `div` para cards, items y wrappers internos. No introducir `section` ni `header` como simples cajas visuales.
- Usar otra región semántica únicamente cuando el contenido cumpla realmente su función HTML y el patrón de la familia lo contemple.
- Inspeccionar un recurso hermano y fijar antes de implementar: raíz, nivel base, etiqueta de item y nivel hijo.
- Inyectar otro nivel de encabezado no cambia la raíz ni las etiquetas de los items; solo escala los encabezados interiores de forma relativa.

### Template HTML

- Mantener la naturaleza semántica definida en la tabla anterior.
- Usar placeholders para todo contenido y atributo dinámico.
- Evitar encabezados rígidos cuando el recurso deba adaptarse a distintas posiciones de la jerarquía.
- Permitir un número variable de items cuando corresponda; no duplicar manualmente bloques que el controlador puede generar.
- Mantener clases estables como hooks de SCSS y JS.

### Vista o sniper

Usar la firma real de `controller()`:

```php
controller('nombre', $index, [
    '{placeholder}' => $value,
    'items' => 3,
    'header_level' => 2,
])
```

- `$index` distingue instancias del mismo recurso en una vista y genera `$pad` (`00`, `01`, etc.).
- `$params` reúne reemplazos de placeholders y opciones del controlador.
- `items` controla los elementos repetibles y debe viajar dentro de `$params`, no como cuarto argumento.
- `header_level` fija el nivel principal cuando no puede inferirse del placeholder inyectado.
- Registrar una instancia completa y funcional en el partial adecuado de
  `App/views/showroom/`: `heroes`, `particles`, `gsap-specials`, `common`,
  `cards-grids`, `media`, `forms-interactive` o `modules-sections`. Comprobar
  que `/showroom/<categoria>` y `/templates/<categoria>` cargan el mismo
  catálogo.
- No añadir recursos directamente al shell `_showroom.php`. Un experimento
  local que aún no viaja a CORE debe usar `App/views/showroom/_local.php` y
  limitarse por `$showroomCategory`, sin modificar los ficheros gestionados.
- Hacer que el copy del encabezado principal del ejemplo contenga el
  identificador exacto del recurso (`art02`, `sectionHScroll01`, etc.) para
  localizarlo con la búsqueda del navegador. Si se inyecta un encabezado
  externo, usar una instancia propia y mencionar también los módulos
  relevantes cuando identifiquen la composición.
- Mantener el lorem/Matrix en el cuerpo y en los encabezados interiores, y
  conservar las imágenes dummy existentes. Un recurso visual o atómico sin
  encabezado por contrato no debe recibir un encabezado semánticamente falso
  dentro de su template solo para rotular el catálogo.

### Controlador PHP

- Iniciar todo controlador de contenido con el docblock de rangos mínimos y máximos de copy.
- Mantener el prefijo de las claves exactamente igual al nombre del controlador.
- Combinar `$pad` (instancia `00`, `01`, etc.) y `$letter` para items variables.
- Generar claves únicas y estables; por ejemplo, `recurso_00_a_img`.
- Sustituir todos los placeholders y devolver el HTML renderizado.
- No redactar ni alterar copy de cliente salvo petición expresa.
- Para enlaces configurables, usar el helper de URL localizada del stack cuando exista.

### Idiomas e hidratación

- Conservar en los JSON de `templates` valores dummy para todos los items
  mostrados en los partials del showroom; ambas familias de rutas deben usar el contenido
  `templates`.
- No borrar ni inventar valores dummy acordados con un cliente.
- Mantener cada `data-lang` como objeto con las propiedades necesarias (`text`, `alt`, `title`, `href`, etc.); evitar entradas planas si el recurso necesita atributos.
- Para el showroom canónico, ejecutar
  `php App/tools/update-languages.php templates` o la ruta equivalente: tanto
  `/showroom` como `/templates` usan el slug de contenido `templates`.
- Para cualquier otra vista, hidratar el slug real configurado en `content`.
- Al renombrar una clave, trasladar primero su valor. La hidratación ordinaria
  conserva la clave antigua; retirarla manualmente o usar `--prune-unused`
  únicamente después de verificar que ya no tiene consumidores.
- El actualizador completa claves y propiedades ausentes desde los templates,
  pero conserva cualquier clave o propiedad ya declarada, aunque su valor sea
  `""`, `null` o tenga una forma legacy. Revisar igualmente la forma final de
  cada objeto.
- Los recursos con varios ejes variables (`items`, `list_items`, `subitems`,
  `benefits`, filas, etc.) deben declarar esos contadores de forma estática en
  el sniper y disponer de una prueba de hidratación que confirme todos los
  ejes.
- Revisar el diff de los JSON: el script no sustituye la validación humana.

### Editor inline

- Colocar `data-lang` en el elemento editable o en su unidad interactiva
  inmediata, y comprobar que overlays o pseudoelementos no bloquean el gesto
  del editor.
- En imágenes, conservar la entrada base como objeto con `src`, `alt` y
  `title`. Si el recurso renderiza `srcset`, nombrar e hidratar sus entradas
  relacionadas como `<clave-base>_srcset01`, `<clave-base>_srcset02`, etc. en
  todos los idiomas; no usar sufijos alternativos que el editor no pueda
  asociar a la imagen.
- Usar `data-inline-group` solo en `DEV_MODE` y únicamente cuando varios
  campos de una misma unidad deban editarse juntos.
- En una lista editorial editable, emitir solo en `DEV_MODE`
  `data-inline-collection="lines"` y una
  `data-inline-collection-key` única en su `ul`/`ol`; marcar cada unidad de
  texto inmediata con `data-inline-collection-item` además de `data-lang`.
  Mantener el selector de icono como contrato opcional y no aplicar esta
  agrupación a menús, formularios ni listas estructurales o compuestas.
- Hacer idempotente cualquier listener del editor que se reinstale mediante
  HMR, retirando el handler anterior antes de registrar el nuevo.

### Formularios con backend

- Fijar el contrato completo antes de replicar variantes visuales: nombres de
  campo, endpoint, método, validaciones, esquema JSON, códigos de error y
  estados de carga, éxito y reinicio.
- Mantener IDs únicos por instancia, asociaciones `label`/`for`,
  `aria-describedby`, errores locales y envío asíncrono acotado a la raíz.
- Si se promueve a CORE, incluir en el mismo lote el runtime, una semilla
  backend genérica, traducciones de correo y pruebas de instalación. El
  instalador debe preservar endpoints, transporte, credenciales, destinatarios
  y catálogos ya personalizados por cada consumidor.
- Las rutas y secretos pertenecen al proyecto: documentar la entrada POST
  requerida sin copiar ni sobrescribir `routes/post.php` o `.env`.

### SCSS

- Cargar configuración con `@use '../config' as c;` o la ruta equivalente.
- Escribir estilos nuevos mobile-first.
- Usar nesting dentro del selector del recurso, incluidos los `@media`.
- Usar `@media (min-width: c.$tablet)` y `@media (min-width: c.$desktop)`; evitar `max-width` salvo motivo concreto.
- Usar variables de configuración para colores, tipografías, medidas y breakpoints siempre que existan.
- Limitar los recursos estándar a las familias cromáticas `color00`,
  `color01`, `color02` y `color03`, incluidos sus `bis` y filtros
  `colorNNSVG`. `color04` es un terciario opcional del tema y no debe ser una
  dependencia de un recurso distribuido; tampoco usar `color05+` ni los
  aliases legacy `filterColor*`.
- Mantener en cada stack el contrato cromático v2: blancos en `color00`,
  negros y grises en `color01`, corporativo principal en `color02`,
  secundario en `color03` y terciario opcional en `color04`. Cada familia
  dispone de variantes y filtro SVG. Las variables adicionales pertenecen al
  proyecto.
- Si una rejilla depende de `items`, no fijar una cantidad de columnas que
  contradiga al controlador. Preferir `auto-fit` cuando conserve el diseño o
  emitir un modificador saneado `recurso--items-N` y cubrir en SCSS los rangos
  admitidos.
- Evitar selectores globales y dependencias de una vista concreta.

### JavaScript

- Crear JS solo cuando aporte comportamiento.
- Exportar una función `init` siguiendo el patrón de recursos existentes.
- Acotar selectores a la raíz del recurso y soportar varias instancias.
- Basar items variables en índices/elementos del DOM, no en claves de idioma usadas como `data-*`.
- Limpiar listeners, animaciones, timelines, `ScrollTrigger`, RAF, observadores o WebGL cuando el recurso pueda reinicializarse.
- Registrar import e inicialización en la entrada de la vista. Para el
  showroom, añadir el init al módulo dinámico
  `src/js/showroom/<categoria>.js` y sus estilos a
  `src/scss/showroom/<categoria>.scss`; no volver a convertir
  `templates.js`/`templates.scss` en bundles monolíticos.
- Un recurso local en pruebas puede usar
  `src/js/showroom/local/<categoria>.js` e importar allí su SCSS. CORE
  descubrirá ese hook sin que el proyecto personalice el entrypoint
  gestionado.

## Jerarquía de encabezados

La vista consumidora decide el nivel semántico. Si se inyecta `{header-primary}` con un nivel distinto, recalcular los descendientes de forma relativa y limitar el resultado a `h6`.

El nivel por defecto depende de la raíz: `2` para un recurso `section` y `3` para un recurso `article`. La inyección de otro nivel cambia los encabezados, no las etiquetas estructurales del recurso.

Usar el helper compartido del stack:

```php
// Recurso raíz article: h3 principal y h4 hijo por defecto.
$headerLevels = resolve_header_levels($params, '{header-primary}', 3);
$primaryTag   = 'h' . $headerLevels['base'];
$secondaryTag = 'h' . $headerLevels['child'];
$deeperTag    = 'h' . min($headerLevels['base'] + 2, 6);
```

`resolve_header_levels()` consume `header_level` desde `$params`, también puede inferirlo del markup inyectado y devuelve `base` y el hijo inmediato. Calcular profundidades adicionales relativamente con `min(..., 6)`. No usar el nombre obsoleto `base_heading_level` ni codificar en el template una jerarquía específica de una sola URL.

## Validación

Antes de entregar:

1. Ejecutar `php -l` en cada PHP modificado.
2. Ejecutar el actualizador de idiomas para las vistas afectadas y revisar el diff.
3. Verificar cantidad de `$items`, prefijos, `$pad`, `$letter` y unicidad de claves.
4. Verificar placeholders sin resolver y estructura de objetos `data-lang`.
5. Verificar imports/inits de la vista y de la categoría del showroom.
6. Compilar o ejecutar las pruebas frontend pertinentes.
7. Inspeccionar el recurso en navegador en mobile, tablet y desktop cuando esté disponible.
8. Auditar que ningún SCSS de recurso use `color04+` ni `filterColor*`.
9. Comparar `git status --short` y confirmar que no se tocó copy o trabajo ajeno.

Cuando el recurso quede estable y reutilizable, usar la skill `liquidstack-resource-migration` para promoverlo a CORE.
