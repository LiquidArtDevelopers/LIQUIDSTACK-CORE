# Evolución visual y flujo editorial del constructor Blog

Estado: implementado y cubierto en el corte funcional V2, integrado en CORE
principal dentro de `Unreleased`. Este documento registra la frontera que debe
validarse de forma íntegra antes de publicar una versión.

## Andamiaje implementado

- La UI denomina `Contenedor` al nodo cuya semántica persistida y pública sigue
  siendo `div`.
- `section`, `article`, `div` y los módulos usan guías discontinuas. Los módulos
  centran visualmente su contenido en el eje vertical sin cambiar la alineación
  del texto.
- Los controles de posición usan los SVG propios del módulo Blog como assets
  gestionados; no dependen de ficheros en la raíz de un consumidor.
- La escala visible es S, M, L y XL. Los valores canónicos son `s`, `m`, `l` y
  `xl`, con lectura compatible de `small`, `default`, `large` y `xlarge`.

## Presentación pública implementada

- El lienzo y el artículo público parten de fondo blanco. El shell y el
  andamiaje conservan una paleta administrativa propia, separada de los colores
  publicables del proyecto.
- Los módulos y contenedores publicables aceptan los tokens `color00` a
  `color05` o un RGBA validado y canonizado; nunca CSS arbitrario. Los defaults
  globales H2-H6 son deliberadamente más cerrados: `default` o tokens de tema,
  sin RGBA.
- Imagen admite altura automática o 5–100dvh, `cover`/`contain`, posición
  vertical Arriba/Centro/Abajo, radio porcentual 0–50% y overlay completo con
  modo, token corporativo o RGBA y opacidad. Los presets legacy `default`,
  `none`, `small`, `medium` y `large` siguen siendo compatibles en lectura y
  son mutuamente excluyentes con el radio porcentual; `default` equivale a
  `medium`, excepto en una imagen `full` hija directa de `section`, donde
  equivale a `none`.
- En móvil, `full`, `80`, `60` y `40` ocupan el 100%; solo los hijos directos de
  `section` reciben `1.5rem` de padding inline y una imagen `full` directa puede
  ir a sangre. Desde `48rem`, los anchos son 100%, 90%, 80% y 60%; los módulos
  `full` que no sean imágenes conservan ese padding y solo se retira de
  `80/60/40`. También se activan las proporciones de dos columnas y las rejillas
  de tres a cinco. Desde `64rem`, `80`, `60` y `40` recuperan su porcentaje exacto.
- La preview administrativa y el SSR público comparten este contrato de ancho,
  bleed, padding y columnas.

## Workspace privado y publicación implementados

- `0014_blog_private_draft_publication` añade, de forma aditiva,
  `editorial_workspaces`, `publication_heads`, `category_assignment_heads`,
  `category_assignment_workspaces` y `category_assignment_workspace_items`. El
  workspace editorial apunta a una revisión privada inmutable y a la versión
  pública de partida; la cabecera apunta a la revisión aprobada y a una versión
  de publicación creciente. Las tres tablas de categorías aportan un CAS global
  por post y un `category_workspace_version` propio para impedir sobrescrituras
  silenciosas entre variantes localizadas. No pertenecen al workspace de un
  locale.
- En una variante publicada, `Guardar borrador` persiste metadatos, documento y
  medios en el plano privado; el guardado de categorías alimenta su workspace
  post-wide separado. Ninguna de las dos acciones altera la publicación ni sus
  categorías visibles.
- `Publicar` promociona de forma atómica la última instantánea guardada, consume
  la versión exacta del workspace global de categorías, avanza la cabecera
  pública, actualiza las proyecciones compatibles, aplica el fencing del
  sitemap, registra auditoría y limpia los workspaces consumidos.
- `Retirar` adopta primero la instantánea editorial privada como estado del
  nuevo borrador; si no existe, conserva la última publicación. Después retira
  el workspace localizado y la cabecera pública. Retirar o enviar una
  localización a la papelera no cambia ni elimina las categorías post-wide,
  públicas o pendientes.
- La interfaz mantiene acciones distintas: `Guardar borrador`, `Publicar
  borrador guardado` en el formulario y `Publicar` en la preview inmersiva. La
  promoción exige capacidades de edición, publicación y consulta de medios.
- El gate es opcional. Sin `0014`, el Blog conserva el flujo anterior y las
  variantes publicadas permanecen de solo lectura hasta su retirada.

## Preview inmersiva implementada

- La preview ocupa el viewport en un diálogo y reutiliza el mismo renderer SSR
  que la publicación. Su barra ofrece desktop, tablet y móvil, además de
  `Guardar borrador`, `Publicar` y `Volver al editor`.
- Antes de abrirla, el runtime guarda los cambios en memoria si el formulario
  está sucio. El `iframe` solo acepta la URL privada same-origin.
- La respuesta permite el frame del propio origen mediante CSP y
  `X-Frame-Options: SAMEORIGIN`, y conserva `no-store`, `noindex`, `nofollow` y
  `noarchive` en cabeceras y HTML.
- GET y HEAD nunca publican, auditan, adoptan ni modifican la variante, el
  workspace o la cabecera pública.

## Edición de `Texto` implementada

- La fuente HTML estándar histórica sigue disponible para hacer round-trip del
  `content` tipado y de las listas compatibles; no admite HTML o CSS libre ni
  amplía su allowlist.
- La variante avanzada de `Texto` V2 la supersede solo cuando el módulo adopta
  la forma mutuamente excluyente `html`/`css`. Su HTML usa la allowlist
  estructural documentada en `docs/liquid-blog.md`; el CSS acepta únicamente la
  política cerrada del backend, incluido nesting y `@media`/`@supports`.
- IDs, clases, fragmentos, IDREF y selectores se namespacifican con el UUID. El
  CSS se encapsula bajo el wrapper del módulo y el shell lo emite en un
  `<style nonce>` compatible con su CSP; nunca se convierte en estilo inline o
  global.

## Deuda futura real

- Publicar una versión del corte solo después de revisar compatibilidad,
  migración explícita, `doctor`, rollback y QA HTTP.
- Ejecutar la matriz de integración MySQL/MariaDB del entorno de adopción real,
  además de la cobertura aislada del LAB, antes de recomendar producción.
- Diseñar aprobación por roles, publicación programada y borrado permanente.
- Añadir crop y focal point, vídeo local, audio, reemplazo y garbage collection
  de medios.
- Incorporar importación completa de borradores, nuevas plantillas y presets,
  traducción asistida y un maquetador libre fuera de las columnas controladas
  de V2.
- Resolver redirecciones automáticas tras cambios de slug y las ampliaciones
  futuras del medidor SEO e integraciones externas.
