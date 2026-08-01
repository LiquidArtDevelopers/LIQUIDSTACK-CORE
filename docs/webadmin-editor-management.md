# Gestión delegada de editores en WebAdmin

WebAdmin incorpora una superficie acotada para que un administrador del sitio
gestione accesos editoriales sin poder crear ni modificar identidades
protegidas. Esta funcionalidad pertenece al módulo `webadmin`; no es el acceso
a la zona privada de negocio y no depende de que el proyecto tenga Blog.

## Alcance

Desde la ruta configurada de WebAdmin —`/admin` por defecto— se puede:

- consultar editores mediante paginación opaca;
- invitar una identidad nueva;
- reenviar una invitación que ya no tenga entrega pendiente;
- suspender y reactivar un editor;
- sustituir únicamente el subconjunto de capacidades que el actor puede
  delegar.

No existe una acción HTTP para crear, elevar, degradar o eliminar
`system_superadmin` y `site_admin`. Esas cuentas continúan bajo el contrato de
bootstrap descrito en [webadmin-bootstrap.md](webadmin-bootstrap.md).

## Capacidades

Las decisiones se toman con capacidades efectivas, nunca con nombres de rol:

| Capacidad | Permite |
| --- | --- |
| `webadmin.users.view` | Listar y consultar editores. |
| `webadmin.users.invite` | Invitar y reenviar invitaciones. |
| `webadmin.users.suspend` | Suspender y reactivar editores. |
| `webadmin.users.capabilities.manage` | Sustituir capacidades delegables. |

El esquema inicial marca como delegable únicamente
`webadmin.users.view`. Las capacidades administrativas de invitación,
suspensión y asignación no pueden propagarse desde esta UI. Un módulo futuro,
por ejemplo Blog, podrá sembrar capacidades `blog.*`; solo aparecerán si el
módulo está activo, la capacidad está marcada como delegable y el actor también
la posee.

Una sustitución no es un borrado total. WebAdmin solo añade o retira
capacidades del subconjunto administrable por ese actor y conserva:

- capacidades de módulos desactivados;
- capacidades no delegables;
- capacidades delegables que el actor no posee.

## Identidades administrables

Una cuenta objetivo debe tener el rol delegable `editor`, no puede tener ningún
rol protegido ni otro rol no delegable y nunca puede ser la propia cuenta del
actor. El servicio revalida estos datos dentro de la misma transacción que la
mutación. Los identificadores internos de base de datos no salen en HTML, URL,
DTO ni cursor; las rutas y el cursor firmado usan exclusivamente el UUID
público de la identidad. La clave interna de ordenación se resuelve dentro de
la transacción después de autorizar el listado. La persistencia de auditoría
conserva deliberadamente la FK interna del actor para integridad referencial,
pero no la expone por HTTP; el objetivo se registra mediante su UUID público.

El nombre visible es opcional. El correo se canonicaliza y sigue siendo único.
Los formularios y errores no incluyen contraseñas, tokens, hashes, IDs internos
ni detalles de almacenamiento.

## Rutas HTTP

El prefijo mostrado es el predeterminado y cambia junto con la configuración de
WebAdmin:

| Método | Ruta | Contrato |
| --- | --- | --- |
| `GET` | `/admin/users` | Listado; admite solo `after`. |
| `GET` | `/admin/users/invite` | Formulario de invitación. |
| `POST` | `/admin/users/invite` | Crea editor y encola invitación. |
| `GET` | `/admin/users/edit?user={uuid}` | Detalle y acciones autorizadas. |
| `POST` | `/admin/users/capabilities` | Sustitución acotada de capacidades. |
| `POST` | `/admin/users/suspend` | Suspensión. |
| `POST` | `/admin/users/resume` | Reactivación. |
| `POST` | `/admin/users/invite/resend` | Reenvío de invitación. |
| `GET` | `/admin/users/updated` | Destino PRG genérico para una sesión WebAdmin válida. |

Los `POST` aceptan exclusivamente
`application/x-www-form-urlencoded`, un conjunto exacto de campos y el CSRF de
la sesión autenticada. `capabilities[]` es una lista canónica, sin duplicados ni
estructuras anidadas. Todos los éxitos e idempotencias terminan con `303` hacia
la pantalla genérica; no se transportan resultados o PII en la query. Ese
destino no vuelve a exigir `webadmin.users.view`: quien ejecutó una operación
con otro gate independiente puede leer la confirmación. El enlace de retorno
apunta al listado si puede consultarlo y al panel en caso contrario.

Las navegaciones `HEAD` producen cuerpo vacío y no deslizan sesiones, consultan
usuarios ni mutan la base de datos. Todas las respuestas mantienen `no-store`,
`noindex`, CSP restrictiva, denegación de framing y las cabeceras privadas del
resto de WebAdmin.

## Ciclo de vida

### Invitación

Solo se crea una identidad inexistente. La operación inserta de forma atómica
el usuario `invited`, su fila de credenciales vacía, el rol `editor`, las
capacidades permitidas, una entrega `invite` pendiente y la auditoría. El correo
real se genera y envía después mediante el outbox; la petición HTTP nunca habla
con SMTP.

### Reenvío

Si ya existe una entrega `pending` o `processing`, el reenvío es idempotente y
no crea otra. Cuando la entrega anterior está terminada, se revocan tokens de
acción y sesiones ligadas a ellos antes de encolar la nueva invitación. Solo es
válido para una cuenta que nunca fue activada.

### Suspensión

Una suspensión incrementa `auth_version`, revoca sesiones y tokens de acción,
termina entregas abiertas y cambia el estado a `suspended`. Así, una sesión que
estuviera activa antes de la operación deja de ser válida inmediatamente.

### Reactivación

Una cuenta previamente activada vuelve a `active`. Si nunca fue activada,
vuelve a `invited` y se encola una invitación nueva; en ese caso el actor también
necesita `webadmin.users.invite`. `auth_version` nunca disminuye. Una credencial
con política antigua no impide reactivar la cuenta: el acceso seguirá exigiendo
el flujo de recuperación para adoptar la política vigente.

## Transacciones, concurrencia y outbox

La autorización mostrada por la UI es solo una ayuda de presentación. Cada
mutación vuelve a bloquear y validar SID, CSRF, versión de autenticación,
estado del actor, capacidades, identidad objetivo, roles y estado de ciclo de
vida dentro de la transacción.

En MySQL/MariaDB se usan prepares nativos, `SELECT ... FOR UPDATE` y un reintento
acotado para deadlock, timeout o colisión de correo. En SQLite se usa
`BEGIN IMMEDIATE`. El orden canónico evita ciclos con el despachador:

1. outbox objetivo, cuando la operación lo afecta;
2. usuarios actor/objetivo por ID ascendente;
3. sesión autenticada del actor;
4. tokens de acción objetivo;
5. sesiones objetivo.

El lock de tokens carga únicamente los aún vivos. Las sesiones vinculadas a
todo el historial se localizan mediante una subconsulta con un número constante
de parámetros, por lo que la retención histórica no expande un `IN (...)` ni
alcanza límites de placeholders. El deslizamiento de sesión es idempotente:
MySQL puede informar cero filas modificadas si los timestamps ya coinciden sin
que ello signifique que la fila bloqueada haya desaparecido.

Solo se actualizan filas previamente bloqueadas. Una excepción revierte también
la auditoría y la entrega, de modo que no quedan invitaciones parciales.

## Auditoría y privacidad

Cada éxito, idempotencia y denegación autorizada genera un evento con UUID de
petición, actor, sesión, código, resultado y, cuando corresponde, UUID público
del objetivo. No se guardan correo, nombre, CSRF, SID, token de acción, hash,
IP, user-agent ni payload del formulario en `metadata_json`.

Los DTO de editores ocultan correo y nombre ante `var_dump`, `var_export` y
serialización accidental; los valores solo se revelan explícitamente para
renderizar la respuesta privada. Las excepciones públicas de almacenamiento no
incluyen SQL, parámetros ni PII.

## Validación antes de publicar

Además de la suite normal, este corte debe comprobarse con:

```powershell
composer test
composer test:mysql-integration
composer test:module-e2e
composer validate --strict
composer audit
```

La prueba MySQL/MariaDB es opt-in, usa exclusivamente una base aislada con el
prefijo exigido por su runbook y elimina las tablas de prueba al finalizar. No
debe apuntarse nunca a la base real de un consumidor.
