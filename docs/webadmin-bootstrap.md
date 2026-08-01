# Bootstrap inicial de WebAdmin

El bootstrap crea exclusivamente las identidades iniciales
`system_superadmin` y `site_admin`. Es una operación explícita posterior a las
migraciones; no forma parte del request web, de `composer update` ni del
instalador de recursos.

## Contrato de ejecución

`WebAdminBootstrapService` recibe un PDO ya conectado, la configuración
validada y un entorno ya cargado. Los correos proceden de:

- `LIQUIDSTACK_WEBADMIN_SYSTEM_SUPERADMIN_EMAIL`;
- `LIQUIDSTACK_WEBADMIN_SITE_ADMIN_EMAIL`.

Se canonizan, deben ser válidos y representar a dos personas distintas. PDO
debe usar `ERRMODE_EXCEPTION` y, en MySQL/MariaDB, prepares nativos. Los
valores de los correos no aparecen en la salida ni en la auditoría.

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
roles o invitaciones.

## Orden operativo

Desde la raíz del consumidor:

```console
composer liquidstack:doctor
composer liquidstack:migrate --dry-run
composer liquidstack:migrate --apply
composer liquidstack:webadmin:bootstrap
composer liquidstack:webadmin:mail:dispatch
```

`migrate --apply` y bootstrap son mutaciones distintas, cada una con su propia
confirmación. El bootstrap nunca aplica migraciones. Antes de preguntar y antes
de escribir, el comando vuelve a inspeccionar el esquema, exige todas las
migraciones WebAdmin `applied` y compara el hash del plan para impedir que una
espera interactiva autorice otro estado.

La pregunta interactiva tiene respuesta negativa por defecto. En una
automatización previamente autorizada:

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
se programa como tarea one-shot recurrente; véase
[Correo y outbox de WebAdmin](webadmin-mail-outbox.md).

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
composer liquidstack:webadmin:mail:dispatch
```

Si el dispatcher devuelve reintentos, se conserva su backoff; no se debe usar
`--resend-invites` como sustituto del mecanismo normal de retry. Se reserva para
recuperar invitaciones bootstrap enviadas pero no activadas o entregas que ya
agotaron sus cinco intentos.

## Integración interna

La frontera que invoque el servicio directamente debe cargar el entorno con
`ProjectEnvironmentLoader`, abrir la conexión compartida mediante
`SharedPdoConnectionFactory` y cargar `WebAdminConfig`. Cualquier comando que
habilite esta mutación debe conservar la confirmación explícita y el doble
preflight del plan. `WebAdminBootstrapCommandRuntimeFactory` implementa esa
composición sin ejecutar trabajo durante la construcción de capabilities de
Composer.
