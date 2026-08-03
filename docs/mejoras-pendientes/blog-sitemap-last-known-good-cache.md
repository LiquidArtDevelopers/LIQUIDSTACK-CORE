# Caché last-known-good del sitemap Blog

## Estado

El sitemap dinámico ya dispone de un `ETag` fuerte derivado de los bytes XML,
revalidación `If-None-Match` para `GET` y `HEAD`, y un límite estricto de
50 MiB. La respuesta continúa consultando la DB antes de validar el `ETag`, por
lo que cualquier publicación o retirada queda reflejada inmediatamente.

No se activa todavía una caché persistente de última respuesta válida. Servir
un XML anterior solo porque la DB no responde puede volver a anunciar una URL
que acaba de retirarse. Un TTL por sí solo limita el tiempo del error, pero no
cumple el contrato de no exponer variantes que ya son borrador.

## Contrato necesario antes de activarla

La implementación debe cerrarse como una única frontera:

1. Añadir una revisión pública estable, persistida y actualizada dentro de la
   misma transacción que `publish` y `unpublish`. No debe inferirse mediante
   `MAX(updated_at)`: al retirar la URL más reciente ese valor puede retroceder.
2. Vincular cada snapshot al origen canónico, rutas públicas, idioma
   predeterminado, versión del renderer y revisión pública. Un cambio de
   cualquiera de esos valores invalida el fichero.
3. Invalidar el snapshot antes de hacer visible una transición pública y
   coordinar generador e invalidación con un lock acotado. Una escritura
   concurrente nacida de una revisión anterior nunca puede restaurar el
   snapshot invalidado.
4. Guardar únicamente XML producido por una consulta completa y válida de
   variantes publicadas. Overflow, esquema inválido, configuración inválida o
   error de persistencia no generan caché.
5. Mantener un único snapshot, con TTL máximo explícito, tamaño máximo de
   50 MiB, hash de integridad y escritura temporal seguida de promoción
   atómica. Rechazar ficheros truncados, futuros, corruptos o con identidad
   distinta.
6. En producción, exigir una raíz absoluta persistente y compartida por todos
   los nodos que puedan servir Blog. Una caché local por nodo no puede
   garantizar la retirada inmediata. En desarrollo loopback puede usarse una
   raíz privada bajo `storage/liquidstack/blog`.
7. Rechazar symlinks, junctions, `public`, `vendor`, `.git`, raíces de disco y
   destinos dentro del deploy productivo. La ausencia o invalidez de la caché
   no debe bloquear la respuesta fresca desde DB.
8. Una respuesta last-known-good debe ser observable mediante un código de log
   estable y cabeceras no sensibles. No debe delegarse el stale a un proxy con
   `stale-if-error`, porque ese proxy desconoce la invalidación editorial.

## Revalidación HTTP

El `ETag` seguirá siendo el validador autoritativo. No debe atenderse
`If-Modified-Since` usando el máximo `updated_at` de las URLs: además de poder
retroceder tras una retirada, dos transiciones pueden compartir el mismo
segundo. `Last-Modified` solo será seguro si nace de una revisión pública
monótona; mientras tanto se omite y se conserva el `<lastmod>` individual de
cada URL.

## Pruebas de aceptación

- snapshot fresco, hit por revisión y revalidación 304;
- caída de DB con snapshot vigente y sin snapshot utilizable;
- expiración, corrupción, truncado, exceso de bytes e identidad distinta;
- publicación y retirada atómicas con invalidación;
- carrera real generador frente a retirada, en SQLite y MySQL/MariaDB opt-in;
- rollback editorial que no deja una revisión o marker incoherentes;
- varios nodos sobre la misma raíz;
- `GET` y `HEAD` sin `PHPSESSID`, conservando prioridad project-owned.

