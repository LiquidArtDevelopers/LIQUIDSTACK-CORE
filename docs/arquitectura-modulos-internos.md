# Módulos internos de LiquidStack

LiquidStack mantiene un único repositorio, paquete físico, versión y release:
`liquidstack/core`. WebAdmin y Blog viven dentro de CORE como módulos internos;
los nombres `liquidstack/webadmin` y `liquidstack/blog` son selectores lógicos,
no repositorios ni descargas independientes.

## Selección desde un proyecto

La selección se obtiene únicamente de las dependencias directas de producción
del `composer.json` raíz:

| `require` directo | Capas activas |
| --- | --- |
| `liquidstack/core` | Core |
| `liquidstack/webadmin` | Core + WebAdmin |
| `liquidstack/blog` | Core + WebAdmin + Blog |

Blog declara internamente su dependencia de WebAdmin. No se inspeccionan
`require-dev`, `replace`, `provide`, `composer.lock` ni
`Composer\InstalledVersions`: CORE reemplaza ambos nombres lógicos y esas
fuentes producirían falsos positivos.

Con una versión compatible de CORE ya instalada y sus plugins habilitados:

```bash
composer require liquidstack/webadmin
composer require liquidstack/blog
```

El plugin transforma únicamente esos nombres exactos en selectores `:*` antes
de que Composer intente buscarlos como paquetes físicos. Si se usa una versión
antigua de CORE, el fallback explícito es:

```bash
composer require liquidstack/webadmin:*
composer require liquidstack/blog:*
```

Con `--no-plugins` el alias puede quedar registrado, pero no se ejecuta la
sincronización post-update. Después debe ejecutarse `composer install` o
`composer update` con el plugin de CORE habilitado; `:*` no sustituye ese hook.

No deben instalarse con `--dev`. Para recibir nuevas versiones del código
físico se actualiza CORE:

```bash
composer update liquidstack/core
```

## Manifiestos y cierre de dependencias

Cada módulo dispone de `modules/<id>/module.json`. El manifiesto versiona:

- selector Composer lógico;
- dependencias entre módulos;
- providers por responsabilidad;
- ficheros que, si fueran necesarios, se publican en el proyecto consumidor.

El catálogo valida IDs, nombres de paquete, dependencias, ciclos y rutas
relativas. Las dependencias se ordenan antes del módulo solicitante, por lo que
Blog siempre registra WebAdmin primero.

Los tipos de provider reservados son rutas, middleware, servicios, navegación,
capacidades, migraciones y sitemap. Un provider solo se consulta si su módulo
está activo.

## Sincronización y propiedad de datos

El PHP de los módulos permanece en CORE, bajo `vendor`, y no se duplica en el
proyecto. Solo los ficheros declarados expresamente en `project_files` pueden
publicarse. Sus destinos quedan limitados al namespace propio del módulo en
`public/assets/modules`, `src/js/modules` o `src/scss/modules`; un manifiesto
no puede apuntar a rutas, configuración, datos ni contenido del cliente. Esa
publicación reutiliza el sincronizador seguro de CORE:

- instala ficheros ausentes;
- actualiza únicamente versiones reconocidas como gestionadas;
- conserva personalizaciones locales;
- no usa mirrors destructivos;
- no ejecuta migraciones durante `composer install` o `composer update`.

Cada entrada publicada forma un grupo de actualización independiente por
defecto. Un manifiesto puede dar el mismo `group` a varias entradas que deban
actualizarse atómicamente, sin congelar por ello todos los assets del módulo.

Desactivar o retirar un selector deja de registrar el módulo, pero nunca borra
tablas, usuarios, artículos, medios, uploads, configuración ni ficheros ya
publicados. Las migraciones serán comandos explícitos con preflight, plan,
confirmación y diagnóstico.

Siguen siendo siempre propiedad del proyecto:

- `.env`;
- `App/config/routes/get.php` y `post.php`;
- `App/config/modules/*.php`;
- `robots.txt` y cualquier sitemap existente;
- copy, vistas publicadas, medios y datos del cliente.

## Enrutado previsto

WebAdmin usará `/admin` como prefijo neutro configurable. Su provider se
resolverá antes del enrutado multidioma para impedir que `admin` se interprete
como un locale. Las rutas de la zona privada legacy permanecerán separadas.

Las rutas públicas estáticas del proyecto tendrán prioridad sobre slugs
dinámicos del Blog. Los sitemaps de Blog serán endpoints PHP alimentados por la
DB de producción; publicar un artículo no modificará el repositorio ni
requerirá deploy.

## Estado de implementación

El catálogo, los selectores, el cierre de dependencias y la publicación
selectiva ya constituyen el contrato base. Los providers de interfaz,
autenticación, migraciones y CRUD se incorporan por cortes funcionales
posteriores; declarar hoy un selector no debe interpretarse como que esos MVP
ya estén terminados.
