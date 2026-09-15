---
name: liquidstack-deployment
description: "Configuración, revisión y validación de CI/CD y despliegues de proyectos LiquidStack: GitHub Actions, build de producción, artefactos, sitemap y robots estáticos, módulos dinámicos y QA posterior. Usar al crear o modificar workflows, pipelines o runbooks de despliegue de un stack consumidor; no usar para publicar releases del paquete liquidstack/core."
---

# LiquidStack Deployment

## Preparación

1. Leer las instrucciones del consumidor, ejecutar `git status --short` e
   identificar rama de publicación, public root y método real de despliegue.
2. Inspeccionar `composer.json`, `package.json`, `.env.production`,
   `.gitignore`, `vite.config.*` y los scripts de build; no asumir que todos
   los stacks tienen exactamente los mismos comandos.
3. Mantener credenciales, tokens SSH/FTP, claves de base de datos y el `.env`
   persistente fuera del repositorio y de los logs. El `.env` temporal que CI
   prepare para construir tampoco debe entrar en el artefacto ni sobrescribir
   el `.env` persistente del servidor. Un workflow nuevo requiere autorización
   para crear secretos, entornos o mutar producción.
4. Separar release de CORE, build del consumidor y despliegue: una etiqueta de
   `liquidstack/core` no publica por sí sola ninguna web consumidora.

## Contrato del build de producción

- Cuando `package.json` use el contrato LiquidStack habitual, ejecutar
  `npm run build`; no sustituirlo por una llamada directa a `vite build`.
  El lifecycle de npm ejecuta antes `prebuild`, y el script `build` debe aplicar
  el perfil de producción, ejecutar `php App/tools/build-sitemap.php` y
  compilar Vite.
- Instalar antes las dependencias necesarias para esos pasos. Usar el lockfile
  efectivo del proyecto (`composer install` y `npm ci` cuando corresponda),
  sin `--no-plugins` ni `--no-scripts` cuando CORE deba sincronizar sus piezas,
  y hacer fallar el job si instalación, pruebas, sitemap o Vite fallan.
- Confirmar que `RAIZ` es el dominio HTTPS canónico de producción, nunca
  localhost, staging ni un host legado. El generador estático vigente usa esa
  clave aunque otros pasos admitan aliases. `.env.production` debe contener
  solo overrides no secretos; los secretos proceden del entorno protegido del
  workflow.
- Construir una sola vez y desplegar ese mismo workspace o artefacto. El
  paquete que llega al servidor debe contener, además del runtime que necesite
  el hosting, `public/sitemap.xml`, `public/robots.txt`, el manifest de Vite y
  los assets generados. En un destino Apache debe incluir también
  `public/.htaccess`. No generar el sitemap en un job y desplegar después otro
  checkout sin esos resultados.
- En local, restaurar el perfil de desarrollo tras probar un build. En CI el
  job es efímero y debe conservar el perfil de producción hasta empaquetar.

## Sitemap estático

- `App/tools/build-sitemap.php` construye `public/sitemap.xml` desde
  `App/config/routes/get.php` y actualiza las declaraciones `Sitemap:` de
  `public/robots.txt`. Debe ejecutarse antes de cada despliegue, aunque el
  commit parezca modificar solo copy.
- Cambiar copy no añade una URL. Una ruta nueva entra cuando está declarada en
  `get.php`, tiene `content` y no queda excluida por `sitemap => false` o por
  las reglas técnicas del generador. Las variantes localizadas deben compartir
  el mismo identificador `content` para construir sus `hreflang`.
- No afirmar que una edición de copy cambia `<lastmod>`: comprobar primero si
  la versión instalada del generador lo emite. El contrato actual de CORE no
  incluye `lastmod` en el sitemap estático.
- Validar el XML generado, el host de todas las URL, los alternates y que
  robots anuncie tanto el sitemap estático como los sitemaps dinámicos activos.

## Sitemap dinámico del Blog

- `/blog-sitemap.xml` es una respuesta PHP construida desde la base de datos de
  producción; no es un fichero que deba generarse, descargarse ni incluirse en
  el artefacto del workflow.
- Guardar o crear un borrador no lo anuncia. Tras publicar o republicar una
  variante con el runtime y la base de datos disponibles, la siguiente
  petición al endpoint debe reflejarla sin commit, build, cron ni despliegue.
  Retirarla debe eliminarla del sitemap.
- Solo se anuncian variantes publicadas e indexables. `noindex`, Dummy, estados
  incompletos o retirados quedan fuera; cada locale se evalúa por separado.
- El workflow sí debe conservar la línea del sitemap Blog en `robots.txt` y
  desplegar su configuración/ruta. La base de datos y, si se activa LKG, su
  storage privado deben ser persistentes y no borrarse con cada release.
- La actualización del endpoint es inmediata tras la transacción de
  publicación; el rastreo y la indexación por buscadores ocurren después según
  sus propios tiempos y no pueden garantizarse desde el deploy.

## Validación del workflow

Antes de darlo por terminado:

1. Verificar que el trigger previsto ejecuta pruebas y `npm run build` antes
   del paso que sube archivos.
2. Comprobar dentro del artefacto que sitemap y robots existen, son válidos y
   usan exclusivamente el origen canónico de producción.
3. Confirmar que los ficheros de descubrimiento llegan realmente al document
   root y no quedan reemplazados por un checkout o build posterior; excluir
   expresamente del artefacto cualquier `.env` temporal de CI.
4. Hacer QA HTTP postdeploy: canonicalización de host/HTTPS, `200` para
   sitemap y robots, tipo de contenido correcto y
   `Cache-Control: public, no-cache, must-revalidate` sin `Expires` prolongado.
5. Con Blog activo, comprobar `/blog-sitemap.xml`, su ETag, que no crea sesión
   y que una petición condicional puede devolver `304`.
6. No aplicar migraciones automáticamente solo por configurar CI/CD. Preparar
   plan y dry-run y mantener cualquier `apply` bajo autorización explícita.

## Entrega

Indicar qué dispara el workflow, qué commit se construye, qué artefacto se
despliega, qué entorno controla el origen canónico y qué comprobaciones se han
realizado. Distinguir siempre entre “el sitemap está disponible” y “el buscador
ya lo ha rastreado o indexado”.
