---
name: dev-stack
description: "Desarrollo y mantenimiento de proyectos LiquidStack PHP/Vite: creación o mejora de recursos, templates, vistas/snipers, controladores PHP, placeholders, data-lang, JSON de idiomas, SCSS, JS, items variables, jerarquía semántica, hidratación y promoción posterior a liquidstack/core. Usar cuando Codex deba crear, refactorizar, revisar o registrar recursos y lógica de renderizado de un stack consumidor."
---

# Dev Stack

## Preparación

1. Leer por completo el `AGENTS.md` aplicable y `AGENTS_DEV.md` si existe.
2. Ejecutar `git status --short` antes de editar y preservar cambios ajenos.
3. Inspeccionar un recurso de la misma familia antes de crear otro. Mantener sus convenciones de nombres, firma del controlador, placeholders e imports.
4. Verificar la firma vigente de `controller()` y `resolve_header_levels()` en `src/Core/Support/helpers.php` o en el paquete instalado. Si una guía antigua contradice el helper real, seguir el código vigente y corregir la guía del proyecto.
5. Tratar el proyecto consumidor como laboratorio. No modificar `vendor/liquidstack/core` como fuente.

## Modelo del stack

El contenido se resuelve por URL e idioma. La aplicación carga los JSON de la ruta como variables-objeto globales y la vista invoca recursos con `controller()`. Cada controlador carga un template HTML, reemplaza placeholders y devuelve el HTML renderizado.

Un recurso reutilizable completo suele incluir:

- `App/templates/_<recurso>.html`
- `App/controllers/<recurso>.php`
- una llamada `controller('<recurso>', $index, $params)` en la vista
- `src/scss/resources/_<recurso>.scss`
- `src/js/resources/_<recurso>.js` solo si necesita comportamiento
- imports SCSS/JS en la entrada de la vista
- ejemplo e hidratación en `App/views/_templates.php`
- valores de referencia en `App/config/languages/templates/{es,eu,en}.json`, según los idiomas presentes

Seguir siempre el patrón real del repositorio si una ruta difiere.

## Contrato del recurso

### Template HTML

- Mantener la naturaleza semántica del recurso (`article`, `section`, `header`, etc.).
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
- Registrar en `App/views/_templates.php` una instancia completa y funcional que actúe como contrato de referencia.

### Controlador PHP

- Iniciar todo controlador de contenido con el docblock de rangos mínimos y máximos de copy.
- Mantener el prefijo de las claves exactamente igual al nombre del controlador.
- Combinar `$pad` (instancia `00`, `01`, etc.) y `$letter` para items variables.
- Generar claves únicas y estables; por ejemplo, `recurso_00_a_img`.
- Sustituir todos los placeholders y devolver el HTML renderizado.
- No redactar ni alterar copy de cliente salvo petición expresa.
- Para enlaces configurables, usar el helper de URL localizada del stack cuando exista.

### Idiomas e hidratación

- Conservar en los JSON de `templates` valores dummy para todos los items mostrados en `_templates.php`.
- No borrar ni inventar valores dummy acordados con un cliente.
- Mantener cada `data-lang` como objeto con las propiedades necesarias (`text`, `alt`, `title`, `href`, etc.); evitar entradas planas si el recurso necesita atributos.
- Ejecutar `php tools/update-languages.php <vista>` o la ruta equivalente del proyecto después de registrar el controlador en una vista.
- Revisar el diff de los JSON: el script no sustituye la validación humana.

### SCSS

- Cargar configuración con `@use '../config' as c;` o la ruta equivalente.
- Escribir estilos nuevos mobile-first.
- Usar nesting dentro del selector del recurso, incluidos los `@media`.
- Usar `@media (min-width: c.$tablet)` y `@media (min-width: c.$desktop)`; evitar `max-width` salvo motivo concreto.
- Usar variables de configuración para colores, tipografías, medidas y breakpoints siempre que existan.
- Evitar selectores globales y dependencias de una vista concreta.

### JavaScript

- Crear JS solo cuando aporte comportamiento.
- Exportar una función `init` siguiendo el patrón de recursos existentes.
- Acotar selectores a la raíz del recurso y soportar varias instancias.
- Basar items variables en índices/elementos del DOM, no en claves de idioma usadas como `data-*`.
- Limpiar listeners, animaciones, timelines, `ScrollTrigger`, RAF, observadores o WebGL cuando el recurso pueda reinicializarse.
- Registrar import e inicialización tanto en la entrada de la vista como en `src/js/templates.js` cuando el showroom de templates lo necesite.

## Jerarquía de encabezados

La vista consumidora decide el nivel semántico. Si se inyecta `{header-primary}` con un nivel distinto, recalcular los descendientes de forma relativa y limitar el resultado a `h6`.

Usar el helper compartido del stack:

```php
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
5. Verificar imports/inits de la vista y del showroom.
6. Compilar o ejecutar las pruebas frontend pertinentes.
7. Inspeccionar el recurso en navegador en mobile, tablet y desktop cuando esté disponible.
8. Comparar `git status --short` y confirmar que no se tocó copy o trabajo ajeno.

Cuando el recurso quede estable y reutilizable, usar la skill `liquidstack-resource-migration` para promoverlo a CORE.
