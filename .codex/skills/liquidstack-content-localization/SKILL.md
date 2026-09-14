---
name: liquidstack-content-localization
description: "Localización editorial de proyectos LiquidStack: traducir vistas estáticas basadas en rutas, content y catálogos JSON, y crear o revisar variantes por locale de publicaciones Blog mediante WebAdmin. Usar al traducir copy público preservando hidratación, SEO, medios, taxonomías y estado editorial; no usar para traducir la interfaz administrativa ni para rediseñar la infraestructura i18n."
---

# Localizar contenido LiquidStack

## Preparación

1. Leer las instrucciones del repositorio y ejecutar `git status --short`.
   Preservar copy, catálogos, vistas y borradores concurrentes; no limpiar ni
   sustituir cambios ajenos.
2. Fijar locale fuente, locale destino, alcance público y fuente editorial
   canónica. No asumir que el fichero o publicación más reciente es la versión
   aprobada.
3. Clasificar el contenido que ya exista en el locale destino como aprobado,
   pendiente, placeholder, filtración del idioma fuente o vacío intencional.
   No sustituir una traducción previa solo porque el encargo diga «todo».
4. Usar también `dev-stack` para vistas y catálogos. Cuando la traducción sea
   pública y tenga intención orgánica, usar `seo-content` y cualquier contexto
   SEO local del consumidor.
5. Para publicaciones Blog, usar además `liquidstack-module-operations`. Operar
   contenido mediante WebAdmin o los servicios oficiales; no editar tablas ni
   ficheros de `vendor`.

## Elegir el circuito

- Para páginas, includes o copy global basado en JSON, leer
  [contenido estático](references/static-content.md).
- Para crear, completar o revisar una variante idiomática de una publicación,
  leer [localizaciones Blog](references/blog-localizations.md).
- Si el encargo incluye ambos, inventariarlos por separado. Las vistas
  estáticas se versionan en Git; los artículos viven en la base de datos y
  tienen su propio ciclo de borrador y publicación.

## Criterio editorial común

- Localizar el mensaje, no sustituir palabras de forma mecánica. Mantener el
  registro, la audiencia y la terminología profesional del mercado destino.
- Conservar la intención canónica de la pareja de URLs. Adaptar `title`, meta
  description, H1, encabezados, CTA, anchors, formularios, ALT y demás copy
  visible sin convertir un tema secundario en una nueva intención principal.
- No inventar ni amplificar cifras, rentabilidades, coberturas, partners,
  certificaciones, condiciones o claims. Contrastar los hechos con las fuentes
  aprobadas del consumidor. Si el original es obsoleto o contradictorio,
  señalarlo y mantener el destino sin publicar hasta resolverlo.
- Mantener nombres propios, entidades legales, identificadores, marcas y
  denominaciones regulatorias oficiales. Traducir o explicar un término solo
  cuando exista una forma correcta en el idioma destino.
- Localizar los enlaces internos hacia su equivalente real. Conservar anclas,
  parámetros funcionales y URLs externas salvo que exista un destino oficial
  expresamente equivalente.
- Mantener la misma jerarquía semántica: una traducción no autoriza a cambiar
  H1-H6, landmarks, orden de secciones, recursos, layout ni comportamiento.
  Si la fuente incumple la jerarquía requerida, informar por separado; corregir
  ese defecto solo cuando la revisión estructural forme parte del alcance.
- No traducir contratos de máquina: claves, IDs, clases, nombres de campos,
  enums, códigos, slugs internos usados como identificador `content`,
  `src`/`srcset`, datos técnicos, logs o payloads que no se muestren al
  usuario. Los slugs públicos sí se localizan cuando el router o el Blog
  soportan una URL equivalente estable.

## Autorización y cierre

- Crear o guardar un borrador no implica permiso para publicarlo. Publicar solo
  cuando el resultado solicitado sea una variante pública o el usuario lo haya
  autorizado de forma inequívoca.
- Que las variantes fuente ya estén publicadas solo delimita el inventario; no
  autoriza por sí mismo a publicar los destinos. Antes de una publicación en
  lote, identificar sus efectos sobre fecha, sitemap, feeds, hreflang,
  indexación y cualquier integración editorial configurada.
- Separar los defectos del original de los introducidos por la traducción y
  entregar un inventario de páginas o variantes completadas, pendientes y no
  publicadas.
- No declarar terminado el trabajo por paridad de claves o por un guardado
  correcto. Verificar el HTML SSR, la navegación entre idiomas y la
  presentación responsive del contenido final.
