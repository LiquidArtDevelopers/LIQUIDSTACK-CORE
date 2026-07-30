---
name: liquidstack-resource-migration
description: Migración y promoción de recursos entre un proyecto LiquidStack consumidor, liquidstack/core, el laboratorio BASE y proyectos React. Usar al copiar recursos, convertir diseños, promover un recurso probado a CORE, portar GSAP/Three/Draggable, registrar templates/showroom o comprobar qué debe viajar mediante Composer.
---

# LiquidStack Resource Migration

## Preparación

1. Respetar las instrucciones aplicables y cargar las skills locales
   complementarias de cada repositorio; no depender de guías legacy en la
   raíz.
2. Ejecutar `git status --short` en el proyecto consumidor y en CORE.
3. No tocar un repositorio con cambios coincidentes sin identificar primero su propietario y alcance.
4. Tratar `liquidstack/core` como fuente canónica de recursos estables y el proyecto consumidor como laboratorio.
5. No editar `vendor/liquidstack/core` como fuente de verdad.

Localizaciones habituales en este entorno, que deben verificarse antes de usarse:

- BASE: `C:\xampp\htdocs\__LIQUIDSTACK\LIQUIDSTACK-BASE`
- CORE: `C:\xampp\htdocs\__LIQUIDSTACK\LIQUIDSTACK-CORE`
- showroom BASE: `http://localhost:1309/es/showroom` (con `/es/templates`
  como alias compatible)

En otros entornos, localizar CORE mediante Composer, el repositorio configurado o las instrucciones del proyecto; no inventar rutas.

## Comparación antes de migrar

Antes de asumir que un recurso es canónico:

1. Comparar la implementación del consumidor, BASE y CORE.
2. Inspeccionar el recurso renderizado si el entorno local está disponible.
3. Identificar contrato, dependencias, imágenes, opciones, items y niveles de encabezado.
4. Conservar cambios locales que no formen parte de la migración.

Si un recurso existe en un consumidor o en BASE pero no en CORE, tratarlo como local/en pruebas hasta que se promocione expresamente.

## Promoción de consumidor a CORE

Promover solo cuando el recurso funcione y sea reutilizable sin copy ni supuestos de un cliente.

### Contrato

Definir:

- nombre estable del recurso
- template y naturaleza semántica
- campos de copy, enlaces e imágenes
- items y opciones variables
- nivel base de encabezados
- clases/variantes
- dependencias JS y ciclo de limpieza

### Mapa de ficheros

Copiar o adaptar:

| Consumidor | CORE |
| --- | --- |
| `src/scss/resources/_<recurso>.scss` | `resources/scss/_<recurso>.scss` |
| `src/js/resources/_<recurso>.js` | `resources/js/_<recurso>.js` |
| `public/assets/img/...` | `resources/img/...` |
| `public/assets/video/...` | `resources/video/...` |
| `App/templates/_<recurso>.html` | `stubs/App/templates/_<recurso>.html` |
| `App/controllers/<recurso>.php` | `stubs/App/controllers/<recurso>.php` |
| `App/config/languages/templates/*.json` | `stubs/App/config/languages/templates/*.json` |
| ejemplo de `App/views/_showroom.php` | `stubs/App/views/_showroom.php` |
| `App/app/updateLanguage.php` cuando cambia el editor | `stubs/App/app/updateLanguage.php` |

Actualizar además:

- `src/scss/templates.scss`
- `src/js/templates.js` si hay comportamiento
- `package.core.json` si se añade una dependencia frontend
- `resources/img` con dummies reutilizables, no imágenes privadas de cliente
- `resources/video` con medios y pistas dummy estrictamente necesarios, nunca
  vídeos privados del cliente
- README/CHANGELOG de CORE cuando cambie el contrato público o la actualización requiera pasos

No es necesario inventariar individualmente cada recurso en el Installer
mientras esté dentro de estos directorios ya sincronizados; sí es obligatorio
registrar el showroom canónico, mantener `_templates.php` como acceso
compatible, registrar el controlador y comprobar la hidratación. Ambas rutas
usan normalmente el bundle y los idiomas `templates`.

Si el recurso amplía infraestructura compartida —por ejemplo fondos
responsive, colecciones o selectores de icono del editor inline— promover en
el mismo lote el runtime común, el endpoint correspondiente y sus pruebas. Un
fichero nuevo situado fuera de los directorios ya espejados debe registrarse
explícitamente en `Installer::syncProjectAssets()` y comprobarse en un fixture
de Composer; copiar únicamente el recurso visual deja la funcionalidad
incompleta.

Cuando un recurso reutiliza un backend configurable —formularios, correo,
autenticación o integraciones— distribuir una semilla genérica solo si falta y
preservar las copias locales existentes junto con sus catálogos y transporte.
No copiar a CORE destinatarios, credenciales, BCC, branding, contenido legal o
plantillas propias del cliente. Si un runtime canónico contiene datos
regulatorios locales, separarlo en una variante o marcarlo como preservable
antes de permitir que Composer actualice el consumidor.

Los directorios de medios no se consideran distribuidos solo por existir en
`resources`: verificar que `Installer::syncResources()` los copie al destino
público correspondiente y que preserve archivos locales no gestionados.
En particular, `resources/img/logos` puede aportar logos genéricos a un stack
nuevo, pero Composer debe conservar cualquier fichero homónimo ya existente en
`public/assets/img/logos`; el branding del consumidor nunca se sobrescribe
desde CORE.

CORE no debe copiar ni sobrescribir `App/config/routes/get.php` o
`App/config/rutas.js` completos: contienen rutas propias de cada consumidor.
Si se incorpora `/showroom`, registrar o documentar en cada proyecto una ruta
con `resources => templates`, `content => templates` y la vista
`_showroom.php`; mapear igualmente esa URL a `templates` en `rutas.js`.
Por el mismo motivo, al promover el editor de idiomas se sincroniza
`App/app/updateLanguage.php`, pero se conserva la ruta POST local del
consumidor. Verificar que `/languages/update` siga apuntando a
`updateLanguage.php` sin sustituir el fichero de rutas completo.

### Idiomas

- Mantener el mismo prefijo que el controlador.
- Conservar `$pad`, `$letter`, objetos `data-lang` y valores dummy de referencia.
- Conservar en el showroom el identificador exacto del recurso dentro del
  encabezado principal de su ejemplo. Si el encabezado es un módulo inyectado,
  mantener índices independientes para no mezclar rótulos de recursos
  distintos.
- Fusionar JSON por clave; no sustituir a ciegas archivos completos si CORE contiene otros recursos.
- Incluir todos los idiomas base existentes en CORE.

### Validación

1. Ejecutar `php -l` en controladores y vistas modificados.
2. Validar JSON.
3. Compilar frontend y probar `/showroom` y el alias `/templates`.
4. Probar múltiples instancias e items.
5. Revisar mobile, tablet y desktop.
6. Verificar limpieza de JS, accesibilidad y jerarquía de encabezados.
7. Comparar consumidor y CORE para confirmar que no falta ningún fichero.
8. Ejecutar una sincronización Composer en un fixture o consumidor limpio.

No probar un `composer update` destructivo sobre BASE si tiene cambios locales que colisionan.

## Semántica de composición

- Conservar un `article` como `article`; no neutralizarlo por comodidad.
- Dejar que la vista consumidora establezca la jerarquía.
- En una sección con varios artículos, usar normalmente H2 para el concepto de sección y H3 para cada artículo.
- Si un único artículo expresa exactamente el mismo concepto que la sección, evitar un H2 redundante e inyectar el H2 como encabezado primario del artículo.
- Recalcular descendientes de forma relativa hasta `h6`.
- Mantener H2 de sección y H3 de artículo cuando representen niveles conceptuales distintos.

## LiquidStack a React

1. Leer primero la versión CORE y después la versión local si es más nueva.
2. Convertir placeholders y variables globales en props tipadas o claves i18n.
3. Preservar clases LiquidStack como hooks de estilo/comportamiento.
4. Controlar el nivel de encabezado desde la página React.
5. Acotar selectores al root del componente.
6. Limpiar timelines GSAP, `ScrollTrigger`, `Draggable`, listeners, RAF, observadores y contextos WebGL.
7. Mantener copy y backend del cliente fuera del componente reutilizable.

## React a LiquidStack

1. Diseñar primero el contrato reutilizable.
2. Convertir props en placeholders, params de controlador y claves de idioma.
3. Implementar HTML semántico, SCSS con variables del config y JS inicializable.
4. Registrar todos los ficheros del mapa de CORE.
5. Separar UI de integraciones backend específicas.
6. Sincronizar a un consumidor y validar allí el resultado renderizado.

## Entrega

Indicar:

- qué versión era local y cuál quedó canónica
- qué ficheros se promovieron
- qué registros e hidratación se añadieron
- si hace falta release SemVer, `composer update liquidstack/core` o `npm install`
- qué integración específica del cliente quedó fuera de CORE

No publicar commits ni etiquetas sin autorización expresa del usuario. Cuando
el usuario autorice una release y CORE ya esté validado, usar `composer release`
desde `main` en lugar de crear o subir el tag manualmente. El comando muestra
las propuestas patch/minor/major, permite editar la versión, repite las
validaciones y publica rama + tag anotado mediante un push atómico. Si solo se
prepara la migración, informar de la versión recomendada y dejar la publicación
pendiente.
