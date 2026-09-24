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

## Elegir el recorrido de DB

| Situación | Decisión sobre Media |
| --- | --- |
| DB local durante el desarrollo | Usa Media local; al publicar, exporta la DB y copia/verifica Media en el mismo corte. |
| DB remota usada desde desarrollo | Media sigue en el equipo local; cópiala/verifícala en el servidor antes de abrir producción. |
| Runtime de producción | Configura una ruta absoluta, privada y persistente que el deploy no sustituya. |
| DB heredada o demo | Composer y las migraciones no refrescan sus datos; el instalador demo solo copia bytes, no filas ni referencias. |

WebAdmin, Blog y Commerce comparten una sola conexión `LIQUIDSTACK_DB_*`.
No existen dos bloques simultáneos «local» y «producción»: cada runtime usa el
único bloque de su `.env` privado. CORE no carga por sí solo
`.env.development` o `.env.production`; si el proyecto usa perfiles, su
tooling materializa primero el perfil elegido en `.env`.

Antes de cualquier comando con escritura, comprueba qué host está activo con
`composer liquidstack:doctor --format=json`. Cambiar las variables selecciona
otro destino, pero no copia esquema, contenido ni Media.

Media mantiene su propio filesystem en cada runtime. En desarrollo loopback,
`LIQUIDSTACK_WEBADMIN_MEDIA_STORAGE_ROOT` puede quedar vacía para usar
`storage/liquidstack/webadmin/media`. Si se define, debe ser siempre una ruta
absoluta; una ruta relativa como `storage/liquidstack/webadmin/media` es
inválida.

### Caso 1: crear contenido en DB local y promoverlo después

1. Configura el `.env` local con `LIQUIDSTACK_DB_HOST=127.0.0.1` o
   `localhost`, crea una DB vacía y sigue «Aplicar migraciones pendientes» y
   «Primera activación de WebAdmin».
2. Crea el contenido en WebAdmin. Las imágenes se guardan en
   `LIQUIDSTACK_WEBADMIN_MEDIA_STORAGE_ROOT`, no dentro de la DB.
3. Antes del corte, congela escrituras y crea un backup conjunto de DB y Media.
   Verifica que `.staging` esté vacío.
4. Exporta la DB completa —estructura, datos y `ls_module_migrations`— a una
   ubicación privada fuera del repositorio. Este bloque solicita la contraseña
   sin incluirla en el historial de consola:

   ```powershell
   $LsDumpTool = (Resolve-Path -LiteralPath (Read-Host 'Ruta de mysqldump.exe')).Path
   $LsBackupDir = (Resolve-Path -LiteralPath (Read-Host 'Directorio privado para el backup')).Path
   $LsLocalHost = Read-Host 'Host de la DB local'
   [int] $LsLocalPort = Read-Host 'Puerto de la DB local'
   $LsLocalDb = Read-Host 'Nombre de la DB local'
   $LsLocalUser = Read-Host 'Usuario de la DB local'
   $LsDumpFile = Join-Path $LsBackupDir ("liquidstack-$((Get-Date).ToString('yyyyMMdd-HHmmss')).sql")
   & $LsDumpTool "--host=$LsLocalHost" "--port=$LsLocalPort" `
       "--user=$LsLocalUser" --password --single-transaction --quick `
       --default-character-set=utf8mb4 "--result-file=$LsDumpFile" $LsLocalDb
   if ($LASTEXITCODE -ne 0) { throw 'Falló la exportación de la DB.' }
   Get-Item -LiteralPath $LsDumpFile | Select-Object FullName, Length
   ```

5. Crea en el hosting una DB realmente vacía y su usuario acotado. Impórtala
   mediante el panel del proveedor o, si el cliente MySQL puede llegar al
   destino mediante red confiable/VPN/túnel, con:

   ```powershell
   $LsMysqlTool = (Resolve-Path -LiteralPath (Read-Host 'Ruta de mysql.exe')).Path
   $LsDumpFile = (Resolve-Path -LiteralPath (Read-Host 'Ruta del dump SQL')).Path
   $LsProdHost = Read-Host 'Host o extremo local del túnel'
   $LsProdPort = Read-Host 'Puerto'
   $LsProdDb = Read-Host 'Nombre de la DB productiva vacía'
   $LsProdUser = Read-Host 'Usuario de la DB productiva'
   $LsSqlPath = $LsDumpFile.Replace('\', '/')
   if ($LsSqlPath -match '\s') { throw 'Mueve el dump a una ruta privada sin espacios.' }
   if ((Read-Host 'Escribe IMPORTAR para continuar') -cne 'IMPORTAR') { throw 'Importación cancelada.' }
   & $LsMysqlTool "--host=$LsProdHost" "--port=$LsProdPort" `
       "--user=$LsProdUser" --password "--database=$LsProdDb" `
       --default-character-set=utf8mb4 "--execute=SOURCE $LsSqlPath"
   if ($LASTEXITCODE -ne 0) { throw 'Falló la importación de la DB.' }
   ```

6. Antes de copiar Media, crea fuera del repositorio un inventario relativo de
   bytes y SHA-256. Ejecuta el mismo bloque sobre la copia productiva y compara
   ambos CSV; el número de ficheros por sí solo no demuestra integridad:

   ```powershell
   $LsMediaRoot = (Resolve-Path -LiteralPath (Read-Host 'Ruta Media que se va a inventariar')).Path
   $LsInventoryDir = (Resolve-Path -LiteralPath (Read-Host 'Directorio privado para el inventario')).Path
   $LsStaging = Join-Path $LsMediaRoot '.staging'
   if ((Test-Path -LiteralPath $LsStaging) -and @(Get-ChildItem -LiteralPath $LsStaging -Force).Count -ne 0) {
       throw 'Media contiene un staging pendiente; detén el corte.'
   }
   $LsInventoryFile = Join-Path $LsInventoryDir ("media-$((Get-Date).ToString('yyyyMMdd-HHmmss')).csv")
   Get-ChildItem -LiteralPath $LsMediaRoot -Recurse -Force -File |
       ForEach-Object {
           [PSCustomObject]@{
               Path = $_.FullName.Substring($LsMediaRoot.Length).TrimStart('\', '/')
               Bytes = $_.Length
               Sha256 = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash
           }
       } | Sort-Object Path | Export-Csv -LiteralPath $LsInventoryFile -NoTypeInformation -Encoding UTF8
   Get-Item -LiteralPath $LsInventoryFile | Select-Object FullName, Length
   ```

   Copia después la raíz completa a su ubicación productiva privada y
   persistente, incluidos marker, dotfiles y cuarentena. No uses una
   sincronización con borrado sobre una raíz viva y no ejecutes
   `media:init --adopt-existing` sobre una raíz que ya conserva su marker.
7. Configura el `.env` del servidor con sus `LIQUIDSTACK_DB_*` y
   `LIQUIDSTACK_WEBADMIN_MEDIA_STORAGE_ROOT`. Repite `doctor`, plan y dry-run;
   aplica únicamente migraciones nuevas si las hubiera, después de otro
   backup. Compara recuentos y prueba login, Media, Blog, Commerce e imágenes.

Si producción ya contiene datos o ha recibido escrituras, no la sobrescribas
con el dump local: requiere una reconciliación específica. Conserva un rollback
capaz de restaurar DB y Media del mismo punto lógico.

### Caso 2: desarrollar desde el principio contra una DB productiva vacía

1. Crea la DB y el usuario en el hosting. Desde el equipo local usa únicamente
   un endpoint autorizado y cifrado —preferentemente VPN o túnel—. CORE no
   configura todavía TLS/CA para una conexión MySQL pública directa.
2. En el `.env` privado local, `LIQUIDSTACK_DB_HOST` es el host remoto o el
   extremo local del túnel. En el `.env` privado del servidor puede ser
   `127.0.0.1` o el hostname interno del proveedor. No copies el `.env` local
   completo a producción.
3. Desde un solo entorno ejecuta `doctor`, plan, dry-run y el `--apply`
   autorizado de la sección siguiente. Esos comandos escriben directamente en
   producción aunque se lancen desde el portátil.
4. No hay que trasladar después la DB, pero Media sigue siendo filesystem: una
   subida hecha desde PHP local queda en el storage local aunque sus filas
   estén en la DB productiva. Antes de abrir la web, congela escrituras, haz
   backup conjunto y copia/verifica esa raíz Media en producción como en el
   caso 1. Si no hubo uploads, inicializa la raíz productiva vacía allí.
5. Ejecuta onboarding una sola vez con el origen del entorno cuyos enlaces
   deban utilizarse. En producción debe ser el HTTPS real, no localhost.

Tras el corte, producción es la única fuente de escritura. No vuelvas a subir
o retirar medios desde PHP local contra esa DB: crearías filas productivas que
solo tienen bytes locales. Para seguir desarrollando, usa una copia aislada de
DB y Media o una infraestructura de storage compartido expresamente validada.

### Datos demo heredados de BASE

Reinstalar el proyecto, actualizar CORE o aplicar migraciones no actualiza el
contenido de una DB ya importada. Si se reutiliza una DB procedente de una
versión anterior de BASE, conservará sus posts, assets y referencias antiguos.
Las migraciones no actualizan ni reponen esos datos demo.

Para obtener el snapshot demo actual, importa el `example_liquidstack_dev.sql`
de la misma versión de BASE únicamente sobre una DB vacía o desechable y tras
un backup. No lo importes sobre contenido que deba conservarse. Cuando ese SQL
ya contenga los posts y metadatos Media, prepara el storage de cada entorno:

```powershell
composer liquidstack:media:init --yes --format=json
composer liquidstack:demo-blog-media:install
```

El segundo comando solo existe en proyectos derivados de BASE que conserven
su instalador demo: copia los AVIF, pero no crea filas ni referencias en la DB.
Si local y producción comparten la misma DB remota, cada runtime necesita su
propia raíz Media coherente con esas mismas filas.

### Caso 3: añadir módulos a un stack y DB existentes

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

`composer require` no aplica migraciones ni borra datos. Antes de continuar,
comprueba que WebAdmin y todos los módulos activos usan la misma conexión y que
sus prefijos no colisionan con tablas existentes. En proyectos nuevos debe ser
`connection => liquidstack`; conserva `shared` únicamente en un consumidor
legacy que ya lo utilice de forma intencionada y común a todos los módulos.

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

Después del dry-run, crea y verifica un backup conjunto de la DB y del storage
Media actuales. Aplica el plan mediante la sección siguiente y cierra la
adopción con:

```powershell
composer liquidstack:media:init --yes --format=json
composer liquidstack:webadmin:onboard --yes --format=json
composer liquidstack:doctor --format=json
npm install
npm run build
```

`media:init` es idempotente sobre una raíz ya inicializada. Repetir onboarding
tras añadir un módulo reconcilia las capacidades y las dos identidades
protegidas; no equivale a repetirlo después de una actualización ordinaria.
Comprueba `/admin`, la web pública y las rutas Blog o Commerce añadidas.

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
actualización normal si la DB ya estaba operativa; añadir un módulo sí requiere
la reconciliación idempotente descrita en el caso 3. La creación de un proyecto
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

GSAP se instala desde el paquete público de npm y no requiere `GSAP_TOKEN` ni
un registro privado en `.npmrc`. Los consumidores que conservaban exactamente
el alias legacy se migran al actualizar CORE; ejecuta después `npm install`.
CORE no modifica el `.npmrc` privado e ignorado: si aún contiene únicamente la
configuración legacy de `npm.greensock.com`, elimínalo; si contiene otros
registros necesarios, retira solo las líneas de GreenSock antes de instalar.
Todos los módulos activos deben usar el mismo perfil físico de DB en
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
