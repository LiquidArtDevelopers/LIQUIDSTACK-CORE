# Manual del stack LiquidStack

> Fichero gestionado por `liquidstack/core`. No lo personalices: usa el
> `README.md` del proyecto para documentación propia del cliente.

Todos los comandos se ejecutan desde la raíz del stack.

## Levantar y compilar

```powershell
npm install
npm run lad
```

LAD muestra los puertos reales de PHP y Vite; pueden cambiar si están ocupados.
Para generar producción:

```powershell
npm run build
```

## Actualizar CORE sin cambiar los módulos activos

```powershell
git status --short
composer require "liquidstack/core:^1.35" --with-all-dependencies
npm install
composer show liquidstack/core --locked
composer liquidstack:doctor --format=json
composer liquidstack:migrate --plan --format=json
composer liquidstack:migrate --dry-run --format=json
```

El comando anterior deja `^1.35` en `composer.json`. Ese caret permite que cada
actualización instale la última release estable `1.x`; no lo sustituyas por una
versión exacta como `1.35`.

Un update sincroniza código y recursos. Nunca migra la DB, crea cuentas,
inicializa Media ni envía correo automáticamente.

## Añadir módulos a un stack existente

Primero actualiza CORE:

```powershell
composer require "liquidstack/core:^1.35" --with-all-dependencies
```

Después ejecuta solo la opción necesaria. Blog y Commerce incluyen WebAdmin,
pero no se incluyen entre sí.

```powershell
# Solo WebAdmin
composer require "liquidstack/webadmin:*" --with-all-dependencies
```

```powershell
# Blog + WebAdmin
composer require "liquidstack/blog:*" --with-all-dependencies
```

```powershell
# Commerce + WebAdmin
composer require "liquidstack/commerce:*" --with-all-dependencies
```

```powershell
# Blog + Commerce + WebAdmin
composer require "liquidstack/blog:*" "liquidstack/commerce:*" `
    --with-all-dependencies
```

Revisa qué necesita la DB sin escribir:

```powershell
composer liquidstack:doctor --format=json
composer liquidstack:migrate --plan --format=json
composer liquidstack:migrate --dry-run --format=json
```

Si acabas de añadir Blog, prepara su vista pública antes de migrar:

```powershell
composer liquidstack:blog:adopt-public-shell
npm run build
composer liquidstack:blog:adopt-public-shell --apply --yes
composer liquidstack:doctor --format=json
```

Commerce nace con `public.enabled=true` en
`App/config/modules/commerce.php`. Ponlo en `false` mientras prepares el
catálogo si todavía no debe ser público.

## Aplicar migraciones pendientes

`--plan` no conecta. `--dry-run` consulta la DB sin escribir. Antes de aplicar,
comprueba el host de `LIQUIDSTACK_DB_*` y crea un backup recuperable de DB y
Media. Si el stack local apunta a producción, `--apply` escribirá en producción.

Tras revisar el plan y el backup:

```powershell
composer liquidstack:migrate --apply --yes --format=json
composer liquidstack:migrate --dry-run --format=json
composer liquidstack:doctor --format=json
```

No continúes hasta obtener cero pendientes y cero bloqueadores. Usa
`--allow-destructive --backup-confirmed` únicamente cuando el plan marque una
migración como destructiva; esos flags no crean el backup:

```powershell
composer liquidstack:migrate --apply --yes --format=json `
    --allow-destructive --backup-confirmed
composer liquidstack:migrate --dry-run --format=json
```

## Primera activación de WebAdmin

Con las migraciones a cero, inicializa Media una sola vez:

```powershell
composer liquidstack:media:init --yes --format=json
```

Arranca `npm run lad` y copia el origen PHP exacto que muestre. En otra consola:

```powershell
$LiquidStackOrigin = Read-Host 'Origen PHP anunciado por LAD'
$env:RAIZ = $LiquidStackOrigin
composer liquidstack:webadmin:onboard --yes --format=json
composer liquidstack:doctor --format=json
Remove-Item Env:RAIZ
```

Abre las dos invitaciones y activa las cuentas. No repitas onboarding por una
actualización normal si la DB ya estaba operativa. La creación de un proyecto
nuevo pertenece al `README.md` de `liquidstack/base`.

## Variables esenciales

La plantilla comentada completa es `.env.example`. Nunca versiones `.env` con
secretos reales.

| Grupo | Variables | Función |
| --- | --- | --- |
| Entorno | `RAIZ`, `DEV_MODE`, `DISPLAY_ERROR` | Origen activo y modo de ejecución. |
| Idiomas | `LANG_DEFAULT`, `MULTILANG`, `ES_SIMPLIFICADO`, `LANG_SKIP_UPDATE` | Rutas e hidratación de idiomas. |
| CookieLad | `COOKIE_LAD_KEY`, `COOKIE_LAD_COLOR` | Widget de consentimiento; clave vacía lo desactiva. |
| DB modular | `LIQUIDSTACK_DB_HOST`, `LIQUIDSTACK_DB_PORT`, `LIQUIDSTACK_DB_NAME`, `LIQUIDSTACK_DB_USER`, `LIQUIDSTACK_DB_PASSWORD`, `LIQUIDSTACK_DB_CHARSET` | Única DB compartida por WebAdmin, Blog y Commerce. |
| Seguridad | `LIQUIDSTACK_WEBADMIN_SECURITY_KEY` | Clave base64url privada de 43 caracteres; sustituye el valor demo. |
| Bootstrap | `LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL`, `LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL` | Dos invitaciones iniciales distintas. |
| SMTP | `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_NAME` | Transporte compartido de correo. |
| Formularios | `MAIL_ADMIN`, `MAIL_LAD`, `MAIL_LAD_BIS`, `MAIL_WEB` | Destinatarios; no son credenciales SMTP. |
| Transporte WebAdmin | `LIQUIDSTACK_WEBADMIN_MAIL_TRANSPORT` | Usa `smtp` o `local_capture_smtp`. |
| Captura SMTP local | `LIQUIDSTACK_WEBADMIN_SMTP_HOST`, `LIQUIDSTACK_WEBADMIN_SMTP_PORT`, `LIQUIDSTACK_WEBADMIN_MAIL_FROM_ADDRESS`, `LIQUIDSTACK_WEBADMIN_MAIL_FROM_NAME` | Capturador loopback solo para desarrollo. |
| Media | `LIQUIDSTACK_WEBADMIN_MEDIA_STORAGE_ROOT` | Storage privado y persistente fuera del árbol sustituido por deploy. |
| Blog | `LIQUIDSTACK_BLOG_SITEMAP_CACHE_ROOT` | Caché persistente del sitemap cuando está activada. |
| Commerce | `LIQUIDSTACK_COMMERCE_INQUIRY_RECIPIENT`, `LIQUIDSTACK_COMMERCE_PRIVACY_VERSION`, `LIQUIDSTACK_COMMERCE_DEVELOPMENT_FIXTURES` | Consultas, consentimiento y fixtures solo de desarrollo. |

`GSAP_TOKEN` pertenece al proceso npm/`.npmrc`, no al `.env` PHP. Todos los
módulos activos deben usar el mismo perfil físico de DB en
`App/config/modules/*.php`.

Para generar una clave WebAdmin válida sin mostrar otros secretos:

```powershell
php -r '$b=random_bytes(32); echo rtrim(strtr(base64_encode($b), "+/", "-_"), "="), PHP_EOL;'
```

## Operaciones opcionales

Ejecuta únicamente las que estén activas en el proyecto:

```powershell
# Caché del sitemap Blog configurada expresamente
composer liquidstack:blog:sitemap-cache:init

# Cola general de invitaciones WebAdmin
composer liquidstack:webadmin:mail:dispatch --limit=20 --format=json

# Correos de solicitudes Commerce
composer liquidstack:commerce-mail-dispatch --limit=20 --format=json
```

En producción, la caché del sitemap exige storage persistente compartido y
`--shared-storage-confirmed`. La adopción de Media legacy se reserva a una raíz
existente validada y a un backup conjunto ya comprobado:

```powershell
composer liquidstack:media:init --adopt-existing `
    --backup-confirmed --yes --format=json
```

## Actualizar JSON de idiomas estáticos

```powershell
php App/tools/update-languages.php global
php App/tools/update-languages.php templates
$LiquidStackContent = Read-Host 'Slug content de la vista'
php App/tools/update-languages.php $LiquidStackContent
```

`global` inspecciona includes globales, `templates` el showroom y el tercer
comando una vista cuyo `content` exista en `App/config/routes/get.php`. El
proceso es aditivo. Usa `--prune-unused` solo sobre una vista concreta y tras
revisar el diff. Blog y Commerce traducen su contenido editorial desde
WebAdmin/DB, no con este script.

## Cierre de cualquier actualización

```powershell
npm run build
git diff --check
git status --short
```

Comprueba además las superficies activas: web pública, `/admin`, Blog y/o
Commerce.
