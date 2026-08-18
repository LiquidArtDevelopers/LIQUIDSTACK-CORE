# Bootstrap inicial de WebAdmin

El bootstrap crea exclusivamente las identidades iniciales
`system_superadmin` y `site_admin`. Es una operación explícita posterior a las
migraciones; no forma parte del request web, de `composer update` ni del
instalador de recursos. En un stack nuevo, el punto de entrada operativo es
`liquidstack:webadmin:onboard`: compone ese bootstrap con la entrega y
verificación de las invitaciones protegidas sin convertir Composer en un
provisionador de cuentas o correo.

## Contrato de ejecución

`WebAdminBootstrapService` recibe un PDO ya conectado, la configuración
validada y un entorno ya cargado. Los correos proceden de:

- `LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL`;
- `LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL`.

Se canonizan, deben ser válidos y representar dos identidades o correos
distintos. PDO
debe usar `ERRMODE_EXCEPTION` y, en MySQL/MariaDB, prepares nativos. Los
valores de los correos no aparecen en la salida ni en la auditoría.

Ambos valores son project-owned. Pueden vivir en el gestor de secretos del
proyecto o inyectarse de forma transitoria desde un perfil privado del
operador, pero no se codifican en CORE, sus manifests, stubs, documentación o
Git. No existe una contraseña bootstrap en el entorno: cada identidad establece
su propia credencial desde su enlace de activación.

La operación bloquea la fila `bootstrap.initial_accounts` dentro de una
transacción (`SELECT … FOR UPDATE` en MySQL/MariaDB y `BEGIN IMMEDIATE` en
SQLite). Una ejecución nueva:

- crea dos usuarios `invited` con UUID v4 criptográfico;
- crea una credencial vacía para cada usuario;
- asigna el rol protegido correspondiente con origen `bootstrap`;
- encola una invitación `pending`, todavía sin crear ni guardar un token;
- registra eventos estables bajo `webadmin.bootstrap`, sin correo ni secretos;
- cambia la fila de estado a `completed` como última mutación antes del commit.

Solo se reconcilia una identidad ya marcada inequívocamente por una asignación
del mismo rol y origen `bootstrap`. Un usuario, rol, capacidad, credencial,
propietario reservado u outbox incompatible revierte la operación completa.
Nunca se reasigna un rol reservado ni se retira un permiso existente.

Una vez que el estado es `completed`, la DB es la fuente de verdad. Cambiar los
correos del entorno no renombra ni reemplaza cuentas. La ejecución ordinaria
posterior devuelve `already_completed` sin recrear usuarios, credenciales,
roles o invitaciones. Tras un onboarding verificado, las dos variables de
bootstrap pueden retirarse del entorno del proyecto: las cuentas permanecen
en la DB y las reejecuciones validan sus propietarios sin volver a leer esos
correos. Deben volver a declararse solo para una base realmente nueva.
Como `doctor` valida si el entorno actual podría bootstrapear otra base y no
relee identidades privadas, después de retirarlas puede mostrar
`bootstrap_ready=false` como advertencia no bloqueante. La postcondición del
stack ya inicializado es la salida 2/2 de `webadmin:onboard` y el estado de la
DB, no la permanencia de esas variables one-shot.

## Orden operativo

Desde la raíz del consumidor:

```console
composer liquidstack:doctor
composer liquidstack:migrate --plan
composer liquidstack:migrate --dry-run
composer liquidstack:migrate --apply
composer liquidstack:webadmin:onboard --yes
composer liquidstack:doctor
```

`migrate --plan` permanece offline y `migrate --dry-run` abre la conexión solo
para leer. `migrate --apply` y bootstrap son mutaciones distintas, cada una con
su propia confirmación; el onboarding dispone también de una autorización
explícita para la mutación y el efecto SMTP. `--apply` solo se ejecuta después
de revisar el dry-run y confirmar un backup recuperable. Ni bootstrap ni
onboarding aplican migraciones.
Antes de preguntar y antes de escribir, el comando vuelve a inspeccionar el
esquema, exige todas las migraciones WebAdmin `applied` y compara el hash del
plan para impedir que una espera interactiva autorice otro estado.

La pregunta interactiva tiene respuesta negativa por defecto. En una
automatización previamente autorizada que solo necesite el bootstrap de bajo
nivel:

```console
composer liquidstack:webadmin:bootstrap --yes
composer liquidstack:webadmin:bootstrap --yes --format=json
```

`--format=json` exige siempre `--yes`. Una consola no interactiva sin `--yes`
falla cerrada. La salida contiene solo estado y contadores, nunca correos,
credenciales, tokens, DSN, SQL o mensajes internos de PDO.

`bootstrap_ready` exige selección, configuración, ruta, assets, conexión,
esquema y ambos correos iniciales. Es independiente de `runtime_ready`, que
añade clave de seguridad, Argon2id y protección de argumentos en trazas. El
panel no debe exponerse hasta que el runtime HTTP esté listo.

El bootstrap solo **encola** las invitaciones. Para crearlas, enviarlas y
marcarlas como entregadas hay que ejecutar después el dispatcher. En producción
se invoca actualmente de forma explícita; su programación recurrente sigue el
[scheduler pendiente](mejoras-pendientes/webadmin-mail-scheduler-produccion.md).
Véase también [Correo y outbox de WebAdmin](webadmin-mail-outbox.md).

## Onboarding obligatorio de un stack nuevo

Después de aplicar las migraciones, una adopción nueva usa:

```console
composer liquidstack:webadmin:onboard --yes
composer liquidstack:webadmin:onboard --yes --format=json
```

El comando es idempotente y opera únicamente sobre los propietarios exactos de
`system_superadmin` y `site_admin` cuyo rol protegido procede de `bootstrap`.
No consume invitaciones de editores ni otras filas del outbox. Para cada una de
las dos identidades exige como postcondición uno de estos estados:

- la cuenta ya está `active`; o
- la invitación fue aceptada por el transporte SMTP y su token asociado consta
  entregado, vigente, sin uso y sin revocación.

La aceptación SMTP no promete la entrega final del proveedor o del buzón, pero
sí evita declarar el onboarding correcto cuando el transporte rechazó el
mensaje. Si queda trabajo `pending` o `processing`, backoff, fallo terminal o
un resultado cercado, la operación no se considera completada. Una segunda
ejecución conserva las cuentas activas y las invitaciones válidas ya enviadas,
sin duplicarlas.

El onboarding no sustituye, revive ni reenvía de forma implícita una invitación
expirada, enviada anteriormente sin activar o fallida de forma terminal. Esos
casos requieren la recuperación confirmada `--resend-invites` descrita a
continuación y después una nueva ejecución de onboarding. No se deben editar a
mano estados, intentos, tokens o fechas para eludir el backoff.

Este comando nunca se registra como hook de `composer install` o
`composer update`: en ese momento pueden faltar configuración, migraciones,
backup, conectividad SMTP o autorización para enviar correo. La automatización
consiste en convertirlo en el cierre obligatorio y verificable de la adopción,
una vez preparadas esas dependencias.

## Reenvío explícito de invitaciones iniciales

Si una cuenta bootstrap sigue sin activar y su invitación ya fue enviada o
acabó en fallo terminal, puede prepararse un nuevo envío:

```console
composer liquidstack:webadmin:bootstrap --resend-invites
composer liquidstack:webadmin:bootstrap --resend-invites --yes
composer liquidstack:webadmin:bootstrap --resend-invites --yes --format=json
```

Esta modalidad tiene una confirmación diferenciada y solo funciona si el
bootstrap inicial ya está `completed`. Dentro de una transacción, para cada
identidad reservada:

- omite cuentas ya activadas, suspendidas o incompatibles;
- omite un outbox `pending` o `processing`, porque ya existe una entrega viva;
- admite el caso de una entrega anterior `sent` o `failed` terminal;
- revoca todos los tokens de invitación vivos y sus sesiones de acción antes
  de encolar una nueva fila `pending`;
- audita únicamente el código estable y el rol, sin destinatario ni token.

La revocación evita que el enlace anterior y el nuevo sean válidos a la vez.
La operación es idempotente en términos operativos: repetirla después de
reencolar no duplica trabajo, porque la fila abierta queda omitida. El resultado
informa solo `queued_invites` y `skipped_identities`.

El reenvío tampoco manda correo por sí mismo. Debe seguirle:

```console
composer liquidstack:webadmin:onboard --yes
```

El dispatcher general continúa disponible para el scheduler y para trabajos de
correo que no pertenezcan al par protegido.

Si la entrega devuelve reintentos, se conserva su backoff; no se debe usar
`--resend-invites` como sustituto del mecanismo normal de retry. Se reserva para
recuperar invitaciones bootstrap enviadas pero no activadas o entregas que ya
agotaron sus cinco intentos.

## Integración interna

La frontera que invoque el servicio directamente debe cargar el entorno con
`ProjectEnvironmentLoader`, cargar `WebAdminConfig`, resolver su perfil y abrir
la factoría PDO correspondiente. `shared` sigue siendo el default compatible y
usa `BBDD_*`; el opt-in `liquidstack` exige
`LIQUIDSTACK_DB_HOST`, `LIQUIDSTACK_DB_PORT`, `LIQUIDSTACK_DB_NAME`,
`LIQUIDSTACK_DB_USER`, `LIQUIDSTACK_DB_PASSWORD` y
`LIQUIDSTACK_DB_CHARSET=utf8mb4`. Si Blog está activo debe seleccionar el mismo
perfil que WebAdmin; una discrepancia o un entorno incompleto falla antes de
conectar y nunca cae silenciosamente a `shared`.

`.env` y `App/config/modules/*.php` pertenecen al proyecto y Composer no los
modifica. Cambiar de perfil después del bootstrap tampoco mueve identidades,
roles, outbox o el registro de migraciones: exige backup y migración manual
verificada antes de alterar la configuración. La conexión dedicada inicial se
limita a `localhost` o redes confiables mientras no exista un contrato TLS con
CA y verificación del servidor.

Cualquier comando que habilite esta mutación debe conservar la confirmación
explícita y el doble preflight del plan.
`WebAdminBootstrapCommandRuntimeFactory` implementa esa composición sin
ejecutar trabajo durante la construcción de capabilities de Composer.
