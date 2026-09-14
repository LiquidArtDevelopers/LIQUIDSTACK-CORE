# Localización de contenido estático

## Inventariar la fuente de verdad

1. Leer los locales activos en `App/config/langs.php` o en el fichero vigente
   del consumidor.
2. Inspeccionar el catálogo GET real, normalmente
   `App/config/routes/get.php`, y cualquier mapa cliente como
   `App/config/rutas.js`. Para cada URL fuente registrar:

   - URL equivalente del locale destino;
   - `content`, `resources` y `view`;
   - catálogo global o include que también aporta copy;
   - estado de indexación y pertenencia al sitemap.

3. Delimitar qué significa «todas»: páginas públicas indexables, públicas
   `noindex`, legales, errores, redirects/descargas, rutas privadas y superficies
   de sistema/showroom. No incluir una familia implícitamente solo porque exista
   en el router.
4. Buscar también copy visible hardcodeado en vistas, includes, controladores y
   JavaScript, incluido el contenido condicional. Si falta infraestructura para
   localizarlo, separar ese cambio de la traducción y aplicar `dev-stack` solo
   cuando el alcance permita modificarla.
5. Comprobar cómo el stack empareja idiomas. Algunas versiones legacy resuelven
   equivalencias por la posición de las rutas: en ese caso, mantener número,
   orden y contrato `content/view` de todos los idiomas. No reordenar solo un
   locale.
6. Inspeccionar la carga efectiva de catálogos y su precedencia. Habitualmente
   se combina `global/<locale>.json` con
   `<content>/<locale>.json`, pero manda el código vigente del consumidor.
7. Distinguir claves activas de entradas históricas. La igualdad total entre
   JSON no demuestra que la página esté traducida; revisar el HTML SSR y los
   `data-lang` que la vista renderiza realmente.
8. Si falta la ruta equivalente o el catálogo destino, no simular una
   traducción con la URL fuente. Tratarlo como alta i18n: preservar el mecanismo
   de emparejado, actualizar el mapa SEO cuando proceda y pedir dirección si el
   encargo no autorizaba cambios de routing.

## Hidratar antes de traducir

Ejecutar el actualizador sobre cada slug `content` real, con la ruta vigente del
proyecto. El patrón habitual es:

```powershell
php App/tools/update-languages.php <content>
```

- Procesar cada slug por separado para mantener un diff atribuible.
- Registrar antes el diff y los ficheros ya modificados. Si el catálogo fuente,
  destino o global tiene cambios concurrentes de origen incierto, no ejecutar
  una herramienta que vaya a escribirlo hasta poder preservar esos cambios.
- Usar la hidratación normal, que es aditiva. No usar `--prune-unused` durante
  una traducción: el descubrimiento estático no puede demostrar que una clave
  dinámica o histórica carezca de consumidores.
- Revisar el diff inmediatamente. El actualizador añade claves o propiedades,
  pero conserva valores existentes, incluidos vacíos y formas legacy; no
  garantiza corrección lingüística ni estructural.
- Si la hidratación toca otros locales o el catálogo global, preservar el copy
  ya aprobado y separar esos cambios de la traducción.

## Editar el catálogo destino

Traducir únicamente valores destinados al usuario y conservar la forma exacta
de cada objeto.

| Propiedad | Tratamiento |
| --- | --- |
| `text`, `title`, `alt`, `ariaLabel`, `placeholder` | Localizar cuando se rendericen para el usuario. |
| `content` | Decidir por la clave: traducir copy y metadatos; conservar robots, tokens y valores técnicos. |
| `value` | Traducir solo si es una etiqueta visible; conservar valores contractuales de formularios. |
| `href` | Sustituir por la URL interna equivalente; conservar externos, `mailto:`, `tel:`, anclas y parámetros salvo necesidad real. |
| `src`, `srcset`, IDs, clases y `data-*` técnicos | No traducir. |

- Si un valor contiene HTML confiable, traducir solo sus nodos de texto y
  atributos editoriales; preservar etiquetas, estructura y saneamiento.
- No copiar el JSON fuente entero sobre el destino: eso puede restaurar slugs,
  enlaces o metadatos del locale incorrecto.
- Clasificar cada valor destino antes de cambiarlo. Mantener traducciones
  aprobadas; completar ausencias; sustituir placeholders o filtraciones solo
  después de confirmar que son los valores activos de esa URL.
- Conservar marcas de formato y espacios significativos. No construir frases
  traducidas concatenando fragmentos si el recurso admite una unidad completa.
- Ajustar la extensión al rango indicado por el controlador sin empobrecer el
  significado. Si el idioma destino desborda, corregir el recurso únicamente
  cuando el problema sea real y esté dentro del alcance.

## QA por URL

Validar en la respuesta inicial del servidor, no solo después del JavaScript:

- status esperado y `<html lang>` correcto;
- `title`, description, Open Graph/Twitter, canonical, hreflang y robots;
- número de H1 y jerarquía H2-H6 requeridos; si la fuente ya falla, registrar el
  defecto sin introducir un refactor estructural no solicitado;
- ausencia de placeholders, lorem, dummy o texto residual del locale fuente;
- CTA, formularios, ARIA, ALT y mensajes visibles localizados;
- enlaces internos en el locale correcto y selector de idioma reversible;
- imágenes y descargas intactas;
- ausencia de errores de consola y desbordes en móvil, tablet y escritorio.

Comprobar sintaxis de todos los JSON modificados, repetir la hidratación para
detectar deriva y ejecutar las pruebas/build proporcionados por el consumidor.
Si el build completo sincroniza CORE, limpia artefactos o regenera el sitemap,
reservarlo para el cierre controlado y revisar por separado esos efectos.
