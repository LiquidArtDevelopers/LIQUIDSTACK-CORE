# Integración real de WebAdmin y Blog con MySQL/MariaDB

CORE incluye pruebas de contrato opt-in que ejecutan los ciclos mínimos de
WebAdmin y Blog contra un servidor MySQL/MariaDB real. El arnés WebAdmin:

1. comprueba que la conexión apunta a una base aislada y vacía;
2. crea una colisión ajena dentro del namespace y demuestra que el runner no
   crea registro, tablas ni semillas y preserva definición y datos byte a byte;
3. retira solo esa fixture y aplica el catálogo mediante `MigrationRunner`;
4. verifica el registro, la postcondición y las semillas canónicas;
5. comprueba que una segunda aplicación no modifica nada;
6. ejecuta dos veces el bootstrap y confirma su idempotencia;
7. reclama y confirma mediante ACK las dos invitaciones bootstrap, comprobando
   tokens y estados de outbox sobre InnoDB sin contactar un SMTP externo;
8. vincula una invitación, activa la cuenta de prueba y verifica su credencial;
9. comprueba login, sesión, autorización y gate HTTP de esquema reales;
10. solicita y confirma una recuperación, cambia la contraseña, demuestra que
    la sesión autenticada anterior queda revocada y accede con la nueva;
11. recorre la gestión real de un editor: invitación, catálogo delegable,
    reenvío idempotente, suspensión, nueva invitación, activación, sustitución
    de capacidades, sesión propia, revocación y reactivación;
12. enfrenta dos actores en workers separados al mismo correo y exige una sola
    identidad, una invitación ganadora y un conflicto controlado, nunca un
    error de almacenamiento;
13. retiene una fila del outbox mientras otro worker suspende al editor y
    demuestra que este espera antes de bloquear el usuario (`outbox → user`);
14. reencola la invitación bootstrap aún pendiente de activar y omite de forma
    idempotente la identidad ya activada;
15. ejecuta workers aislados contra locks InnoDB reales para demostrar que los
    órdenes `user → session` y `user → action → session` no abren el ciclo
    inverso que produciría un deadlock;
16. vuelve a comprobar postcondiciones y gate, y elimina exclusivamente las
    tablas conocidas que creó en esa base.

El arnés Blog usa la misma conexión aislada y, además:

1. genera prefijos efímeros aleatorios y validados para WebAdmin y Blog;
2. aplica WebAdmin `0001` y Blog `0001` + `0002`, verificando que la migración
   de capacidades Blog queda registrada contra el scope WebAdmin;
3. comprueba postcondiciones, capacidades, idempotencia y gate HTTP reales;
4. autentica un actor WebAdmin y recorre alta, idioma adicional, guardado,
   publicación, resolución pública, sitemap y retirada;
5. enfrenta dos conexiones a una versión editorial obsoleta y exige un
   conflicto optimista sin pérdida de datos;
6. verifica que cada mutación genera auditoría WebAdmin mínima dentro de la
   misma transacción y que un fallo de auditoría revierte el alta completa;
7. elimina solo el registro y las tablas pertenecientes a sus dos prefijos.

La suite normal no abre ninguna conexión y muestra esta prueba como omitida.
Solo se habilita cuando
`LIQUIDSTACK_TEST_MYSQL_INTEGRATION` vale exactamente `1`.

## Barreras de seguridad

- La prueba no carga ni inspecciona `.env`.
- Solo lee variables con el prefijo `LIQUIDSTACK_TEST_MYSQL_`.
- La conexión pasa por `SharedPdoConnectionFactory`, de modo que valida el
  mismo contrato PDO y activa las mismas invariantes UTC, strict mode, claves
  foráneas, unicidad y checks que el runtime real.
- El nombre de la base debe cumplir
  `liquidstack_core_test_[a-z0-9_]{1,32}`; cualquier otro se rechaza antes de
  construir PDO.
- La base debe existir previamente y no puede contener tablas, vistas,
  rutinas, triggers ni eventos.
- Se toma un lock exclusivo por base para impedir dos ejecuciones simultáneas.
- PHPUnit no crea ni elimina la propia base de datos.
- La limpieza se limita a `ls_module_migrations` y a las tablas exactas de los
  contratos. El arnés Blog usa prefijos `lsit_web_<token>_` y
  `lsit_blog_<token>_` nuevos en cada ejecución; no elimina otros objetos ni
  usa comodines.
- Si la comprobación inicial detecta contenido, la prueba falla sin borrar
  nada.

Debe usarse una base descartable y un usuario dedicado, nunca una conexión de
desarrollo o producción. El operador puede preparar fuera de PHPUnit algo
equivalente a:

```sql
CREATE DATABASE liquidstack_core_test_webadmin
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'liquidstack_test'@'localhost'
    IDENTIFIED BY '<contraseña-exclusiva-de-pruebas>';

GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, REFERENCES
    ON liquidstack_core_test_webadmin.*
    TO 'liquidstack_test'@'localhost';
```

La creación del usuario y de la base es deliberadamente responsabilidad del
operador. Una vez terminada la prueba, la base continúa existiendo y vuelve a
quedar vacía.

## Ejecución en PowerShell

Definir las seis variables solo en la consola desde la que se lanza PHPUnit:

```powershell
$env:LIQUIDSTACK_TEST_MYSQL_INTEGRATION = '1'
$env:LIQUIDSTACK_TEST_MYSQL_HOST = '127.0.0.1'
$env:LIQUIDSTACK_TEST_MYSQL_PORT = '3306'
$env:LIQUIDSTACK_TEST_MYSQL_DATABASE = 'liquidstack_core_test_webadmin'
$env:LIQUIDSTACK_TEST_MYSQL_USERNAME = 'liquidstack_test'
$env:LIQUIDSTACK_TEST_MYSQL_PASSWORD = '<contraseña-exclusiva-de-pruebas>'

composer test:mysql-integration
```

La variable de contraseña debe estar declarada. El arnés admite una cadena
vacía de forma intencional cuando el entorno de ejecución puede representarla,
aunque para el uso habitual se recomienda la cuenta dedicada con contraseña
del ejemplo.

Después pueden retirarse de la sesión:

```powershell
Remove-Item Env:LIQUIDSTACK_TEST_MYSQL_INTEGRATION
Remove-Item Env:LIQUIDSTACK_TEST_MYSQL_HOST
Remove-Item Env:LIQUIDSTACK_TEST_MYSQL_PORT
Remove-Item Env:LIQUIDSTACK_TEST_MYSQL_DATABASE
Remove-Item Env:LIQUIDSTACK_TEST_MYSQL_USERNAME
Remove-Item Env:LIQUIDSTACK_TEST_MYSQL_PASSWORD
```

## Ejecución en una shell POSIX o CI

```bash
LIQUIDSTACK_TEST_MYSQL_INTEGRATION=1 \
LIQUIDSTACK_TEST_MYSQL_HOST=127.0.0.1 \
LIQUIDSTACK_TEST_MYSQL_PORT=3306 \
LIQUIDSTACK_TEST_MYSQL_DATABASE=liquidstack_core_test_webadmin \
LIQUIDSTACK_TEST_MYSQL_USERNAME=liquidstack_test \
LIQUIDSTACK_TEST_MYSQL_PASSWORD='<contraseña-exclusiva-de-pruebas>' \
composer test:mysql-integration
```

En CI, la base debe aprovisionarse como servicio efímero antes del comando.
No debe persistirse ninguna de estas credenciales en el repositorio.

## Validación habitual

La red de seguridad no sustituye la suite portable basada en SQLite. Tampoco
es una prueba del proveedor SMTP: el ciclo de integración valida persistencia,
claim y ACK sin enviar correo fuera del proceso:

```bash
composer test
composer test:module-e2e
```

Sin el opt-in, `composer test` termina correctamente e informa de las pruebas
MySQL/MariaDB omitidas. Antes de una release con cambios de DDL, debe ejecutarse
además `composer test:mysql-integration` al menos sobre una versión soportada
de MySQL y otra de MariaDB.
