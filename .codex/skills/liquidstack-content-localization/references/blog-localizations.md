# Localización de publicaciones Blog

## Identificar el agregado correcto

1. Identificar origen administrativo, proyecto/tenant, entorno y conexión
   efectiva sin revelar secretos. Distinguir laboratorio, staging y producción
   antes de cualquier POST: WebAdmin muta la base de datos del entorno abierto.
2. Confirmar que Blog y WebAdmin están operativos, la versión instalada ofrece
   la acción de añadir locale y el idioma destino figura en
   `App/config/langs.php` y `public_paths` de
   `App/config/modules/blog.php`. Usar `doctor` como comprobación de solo
   lectura cuando esté disponible; no aplicar migraciones para traducir.
3. Fijar el estado fuente incluido por el encargo —por ejemplo, solo variantes
   publicadas— y recorrer todas las páginas del catálogo. Excluir fixtures
   `Dummy`, QA y papelera; registrar recuento y fecha de corte.
4. Para cada agregado registrar de forma recuperable, fuera del contenido
   público: identificador, locale y estado fuente, lock/revisión o workspace
   elegido, locale destino y estado actual del destino. No guardar cuerpos,
   tokens ni secretos en ese checkpoint.
5. Clasificar la variante destino:

   - `published`: revisar su estado sin sustituirla; una modificación debe usar
     el workspace privado soportado y estar expresamente incluida;
   - `draft`: inspeccionar revisiones, autoría y cambios existentes. Reutilizar
     el agregado no significa que pueda sobrescribirse trabajo concurrente;
   - ausente: crear la localización vinculada mediante la operación oficial.

6. Abrir la preview o revisión fuente y confirmar qué instantánea debe
   traducirse. Si una publicación tiene workspace privado pendiente, la copia
   de locale puede tomar ese borrador en lugar de la proyección pública. No
   descartar, publicar ni copiar ese estado por intuición: resolver primero con
   el usuario cuál es la fuente aprobada.

## Crear una variante vinculada

Desde `/admin/blog`, usar la acción disponible en la versión instalada
equivalente a
«Duplicar o añadir idioma» y elegir un locale todavía ausente. La operación
correcta es añadir locale al mismo agregado (`addLocalizationCopy()`), no
duplicar el artículo como otro post del mismo idioma.

La nueva variante:

- conserva el `post_id` agregado y nace como borrador privado;
- comienza sin slug ni publicación propia;
- copia la instantánea editorial elegible, su documento estructurado,
  metadatos, preferencias robots y referencias de medios;
- crea documento y revisión inicial propios cuando hay contenido estructurado;
- no publica, no copia el historial ni modifica la variante fuente;
- comparte las asignaciones de categorías post-wide del agregado;
- comienza sin etiquetas, porque estas pertenecen a cada localización.

Usar siempre la operación oficial e idempotente que exponga esa versión del
admin. No asumir que un consumidor antiguo dispone del mismo contrato, crear
filas por SQL, cambiar el locale de una variante existente ni repetir el POST
al margen del flujo del módulo.

En lotes, confirmar cada creación antes de avanzar y actualizar el checkpoint.
Si una mutación falla, detener esa variante, registrar el último estado
confirmado y conservar los borradores parciales; no intentar «deshacer» con SQL
ni enviarlos a la papelera automáticamente.

## Localizar en el editor

Trabajar en el editor de la variante destino y completar:

- H1, slug, title SEO, meta description y extracto;
- documento estructurado completo: encabezados, párrafos, listas, citas,
  destacados, CTA, botones, enlaces y HTML/CSS editorial permitido;
- texto, title, ALT y caption de cada uso localizado de medios;
- URLs internas hacia páginas o artículos equivalentes ya existentes;
- categoría mostrada en el locale destino y etiquetas propias traducidas.

Mantener la estructura `section > article > div`, los niveles H2-H6, columnas,
presentación, recursos y referencias de medios. Reutilizar los assets existentes
mediante sus UUID; no volver a subir la misma imagen para traducir su ALT.
ALT, title y caption pertenecen al uso localizado dentro del artículo, no al
asset global de la biblioteca.

Comprobar que cada categoría asignada tenga nombre/slug en el locale destino.
Si falta, crear esa localización únicamente cuando las taxonomías estén en el
alcance; de lo contrario mantener el post en borrador. Normalizar y deduplicar
las etiquetas conforme al módulo, con un máximo de treinta por variante.

Los enlaces entre artículos solo deben apuntar a una variante destino publicada
o incluida de forma verificable en el mismo lote autorizado. No enlazar a un
borrador inaccesible ni convertir silenciosamente un enlace interno en otro
idioma.

Fijar el slug final antes de la primera publicación. Debe ser natural, estable
y único dentro del locale, y se concatena al `public_path` configurado: no
inventar prefijos. No crear una redirección si nunca existió otra URL pública.

## Guardar, verificar y publicar

1. Guardar el borrador para persistir una revisión canónica.
2. Abrir la preview privada guardada y compararla con la fuente: estructura,
   medios, jerarquía, contenido y responsive.
3. Revisar el medidor SEO como ayuda, no como garantía: intención, title/H1,
   description, slug, introducción, encabezados, ALT y posible canibalización
   solo frente al mismo locale.
4. Comprobar categorías y etiquetas pendientes, enlaces, robots y ausencia de
   texto fuente.
5. Publicar únicamente si el alcance lo autoriza. Que la fuente esté publicada
   no concede ese permiso. Antes del primer POST del lote, confirmar el alcance
   común y revisar fecha, sitemap, feeds, hreflang, indexación, webhooks o
   notificaciones que la versión instalada pueda activar. `Publicar` promociona la
   instantánea ya guardada; guardar el borrador no altera la versión pública.

Después de publicar, validar la URL SSR y confirmar:

- status, locale, H1, metadatos, medios y documento correctos;
- canonical propio y URL bajo el `public_path` destino;
- hreflang únicamente entre variantes publicadas y `x-default` efectivo;
- inclusión en el sitemap Blog cuando sea indexable;
- card, categorías, etiquetas, búsqueda y enlaces relacionados en el idioma
  correcto;
- navegación de idioma hacia la traducción y fallback previsto cuando otra
  variante no esté publicada.

La fecha de publicación pertenece a la variante destino y normalmente será la
fecha real de su publicación. No prometer que se heredará la fecha fuente si el
módulo no ofrece esa operación.
