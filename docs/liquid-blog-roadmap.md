# Hoja de ruta de WebAdmin y Liquid Blog

Este documento separa lo ya implementado de los siguientes cortes. WebAdmin y
Blog son módulos internos de `liquidstack/core`: AIWA es el laboratorio completo
de integración, pero rutas, idiomas, copy, datos, medios y credenciales siempre
pertenecen al proyecto consumidor.

## Matriz de instalación

- Un proyecto sin zona de gestión no requiere ningún selector modular.
- `liquidstack/webadmin` activa autenticación, recuperación de credenciales,
  perfiles, permisos, editores, auditoría y biblioteca de medios.
- `liquidstack/blog` activa WebAdmin como dependencia y suma el dominio Blog.
- La antigua zona privada de negocio del stack no forma parte de WebAdmin y no
  reutiliza sus rutas, sesiones ni modelo de permisos.

Los selectores son lógicos y viven físicamente en CORE. Composer distribuye
código y assets module-managed, pero nunca crea tablas, ejecuta migraciones,
inicializa o modifica `storage`, escribe `.env` ni mueve datos entre entornos.

## Estado implementado

### 1. Acceso y administración base

Implementado:

- superadmin y admin del sitio protegidos y configurados por entorno;
- login, logout, invitación, alta inicial, recuperación y cambio de clave;
- alta, suspensión, reactivación y permisos delegables de editores;
- navegación única de WebAdmin filtrada por capacidades;
- correo asíncrono mediante outbox y diagnóstico operativo.

### 2. Biblioteca compartida de medios

Implementada mediante `0002_webadmin_media_library`:

- subida privada de JPEG, PNG o WebP, validada por firma y decodificador;
- normalización y variantes responsive AVIF sin metadatos;
- cuota, rate limit, hashes, storage privado y entrega autenticada;
- capacidades separadas `webadmin.media.view` y
  `webadmin.media.upload`;
- ALT, title y caption por uso editorial, nunca como metadatos globales del
  asset.

Blog consume esta biblioteca sin apropiarse de ella. DB y storage forman una
unidad de backup y restauración.

### 3. Blog editorial y categorías

Implementado mediante `0001` a `0004` del scope Blog:

- artículos con variantes por idioma, slug, H1, title SEO, description,
  extracto, estado, preview y publicación independientes;
- categorías localizadas, lock optimista, asignación a artículos y proyección
  pública para filtros y cards;
- índice project-owned por idioma y recurso Blog reutilizable, con fixtures
  Matrix solo en showroom;
- sitemap dinámico respaldado por la DB del entorno, sin escribir archivos ni
  necesitar deploy al publicar o retirar una URL;
- canonical, robots, Open Graph, Twitter Card y alternates `hreflang`/`x-default`
  coherentes entre el HTML público y el sitemap, limitados siempre a variantes
  publicadas.

### 4. Editor estructurado v1

Implementado mediante `0005_blog_structured_content` y la biblioteca Media:

- editor privado en `/admin/blog/editor` con documento JSON canónico
  `liquidstack.blog.document`, versión `1`;
- plantillas controladas `article-basic-01` y `article-cover-01`;
- ocho tipos de bloque: párrafo, heading H2/H3, lista, callout, enlace, imagen,
  YouTube y CTA;
- H1, title SEO, slug, description y extracto separados del cuerpo;
- `body_text` derivado en servidor, nunca enviado como fuente paralela;
- revisiones inmutables y restauración mediante una revisión nueva;
- bloqueo optimista compartido con la variante y escritura transaccional de
  documento, referencias de medios, revisión y auditoría;
- proyección de artículos legacy al abrir, sin adopción hasta guardar;
- renderizado público estructurado con fallback legacy para variantes aún no
  adoptadas;
- imágenes AVIF públicas solo cuando están referenciadas por el documento
  actual de una variante publicada;
- assets del editor gestionados por el manifiesto del módulo y comprobados por
  `doctor` mediante `blog.assets` y el blocker
  `assets.missing_or_invalid`.

El constructor libre de secciones, filas y columnas tipo Divi no pertenece a
este corte. El documento v1 permite ampliar plantillas controladas sin aceptar
HTML, clases o estilos arbitrarios del usuario.

## Siguientes cortes

### 5. Entrega pública integrada y rendimiento

El renderer público ya permite que el proyecto aporte de forma tipada su shell,
head, navegación, footer, recursos de tema y CSP mediante una vista confinada a
`App/views`. El fallback autónomo conserva SSR, metadatos, cabeceras cerradas y
un CSS neutral responsive gestionado por el módulo. Los siguientes pasos son:

- convertir `article-basic-01` y `article-cover-01` en composiciones visuales
  reales sobre recursos LiquidStack, conservando el documento v1 y el fallback
  SSR cuando un consumidor no haya adoptado todavía el nuevo shell;
- ampliar de forma aditiva el runtime público de bloques sin acoplarlo al bundle
  administrativo ni introducir dependencias en el fallback;
- mantener el enlace externo accesible de YouTube como fallback y añadir, si
  se activa reproducción inline, un runtime Lite YouTube que solo cree el
  iframe después de consentimiento social válido de CookieLAD;
- impedir que cualquier ruta HTML pública Blog —índice project-owned o detalle
  modular— abra la sesión legacy, cree `PHPSESSID` o fuerce `no-store` sin una
  necesidad funcional;
- reunir, cuando sea posible, cards y categorías en una proyección pública
  acotada para evitar runtimes PDO duplicados por petición.

La integración visual seguirá siendo aditiva: actualizar CORE no sustituirá de
forma silenciosa una vista, un head o un layout project-owned.

### 6. SEO editorial avanzado

- Medidor de title, description, H1, slug, longitud, densidad y keyword
  stuffing.
- Validación de jerarquía H1/H2/H3, segregación temática y cobertura del
  contenido.
- Comparación con URLs canónicas del sitio para detectar posible
  canibalización.
- Datos estructurados y preview de metadatos por idioma.

El medidor ayudará y explicará; no publicará, reescribirá ni bloqueará sin una
regla editorial explícita.

### 7. Traducción asistida

La independencia por idioma ya existe: cada variante conserva slug,
metadatos, documento, revisiones y estado propios. Queda pendiente una
integración IA que genere una nueva variante traducida mediante una acción
explícita. La API y sus credenciales se configurarán por entorno y el resultado
seguirá siendo editable y publicable de forma independiente.

### 8. Descubrimiento e indexación

- Búsqueda y filtros reactivos mediante `fetch`, conservando HTML inicial
  funcional sin JavaScript.
- Relacionados, destacados, archivos, RSS y nuevos recursos dinámicos.
- Integración con Search Console o Indexing API para solicitar indexación tras
  publicar, separada del sitemap y con reintentos auditables.

El sitemap dinámico ya es la fuente técnica inmediata de URLs. Una petición a
un buscador será complementaria y nunca condicionará la publicación.

### 9. Plantillas, recursos y maquetador

- Nuevas plantillas de artículo basadas en recursos de showroom.
- Más recursos públicos Blog: sliders, relacionados y composiciones filtradas.
- Hacer que controladores, templates, SCSS, JS, fixtures y catálogos propios de
  Blog sean `module-owned` y se sincronicen únicamente con el selector
  `liquidstack/blog`; ocultar solo la subruta de showroom no constituye
  distribución selectiva suficiente.
- Vídeo local servido desde una frontera Media segura.
- Maquetador futuro de secciones, filas, columnas y módulos, construido sobre
  un esquema versionado y sin romper los documentos v1.

### 10. Gestión del resto de la web

La biblioteca de medios, identidades, permisos y auditoría de WebAdmin podrán
servir a un futuro editor de contenido estático. Ese trabajo no se mezclará con
el editor inline de desarrollo ni con la zona privada de negocio legacy.

La localización de la interfaz WebAdmin será un contrato separado del locale
del artículo: el panel podrá mostrarse en un idioma mientras se edita otro, sin
usar el idioma de la sesión como filtro implícito de contenido. También queda
reservado un modo HTML avanzado, saneado, auditable, restaurable y protegido por
una capacidad específica; no sustituirá el editor estructurado como flujo
normal.

## Reglas transversales de entrega

- Cada ampliación usa una migración aditiva e inmutable y un feature gate
  propio. Una migración nueva pendiente no inutiliza funciones ya aplicadas.
- El orden operativo es `doctor` → `migrate --plan` →
  `migrate --dry-run` → backup recuperable de DB y storage → autorización
  expresa → `migrate --apply` → verificación. Composer solo informa y
  distribuye; no ejecuta estos pasos.
- Local, staging y producción usan el mismo código. `RAIZ`, `DEV_MODE`,
  `LIQUIDSTACK_DB_*` y `LIQUIDSTACK_WEBADMIN_MEDIA_STORAGE_ROOT` seleccionan el
  entorno; cambiar valores no adopta, copia ni traslada datos.
- Ningún recurso recibe PDO, prefijos o secretos: consume proyecciones acotadas
  de servicios de aplicación.
- Toda mutación revalida sesión, lifecycle, `auth_version`, CSRF y capacidades
  dentro de la misma transacción que protege los datos.
- Antes de publicar CORE se validan migraciones SQLite/MySQL, checksums
  históricos, actualización aditiva de un consumidor, suite completa y pruebas
  reales en AIWA con una ventana aislada del navegador.
