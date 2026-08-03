# Notificaciones de Blog a suscriptores

> Estado: fuera del MVP actual y pendiente de diseño, migraciones,
> implementación y validación operativa. Activar `liquidstack/blog` no crea
> suscriptores, formularios, campañas, colas, cron ni envía correos al publicar.

## Objetivo futuro

Liquid Blog podrá avisar a personas suscritas cuando se publique una variante
de artículo. Este dominio pertenecerá a Blog, no a las cuentas de acceso de
WebAdmin ni a la zona privada legacy. WebAdmin aportará autenticación,
capacidades y auditoría para gestionarlo; una suscripción pública no será un
usuario del panel.

El primer corte deberá contemplar:

- alta verificable, preferentemente mediante doble opt-in;
- baja autónoma, inmediata y sin exigir login;
- locale y preferencias explícitas, con categorías solo si el proyecto las
  habilita;
- supresión duradera de direcciones dadas de baja o bloqueadas;
- trazabilidad mínima de consentimiento y cambios, sin registrar más datos de
  los necesarios;
- remitente y transporte derivados del contrato SMTP general del consumidor,
  sin credenciales propias dentro de la DB.

Antes de implementarlo habrá que cerrar las obligaciones legales y de
privacidad aplicables en cada proyecto. CORE no activará por defecto un
formulario ni marcará consentimientos en nombre del cliente.

## Campañas y outbox

Publicar no abrirá SMTP ni recorrerá suscriptores dentro de la petición. La
transacción editorial registrará de forma atómica un evento o campaña estable
para la variante y revisión publicadas. Un proceso posterior materializará o
reclamará destinatarios mediante consultas acotadas y enviará lotes finitos.

El diseño deberá garantizar:

- unicidad por campaña, suscriptor y publicación para no duplicar una entrega
  por reintentar la misma transición;
- decisión explícita sobre si una retirada cancela las filas aún no enviadas;
- reenvío solo mediante una acción administrativa diferenciada y auditable;
- snapshot mínimo del contenido enviado, o una versión estable que impida que
  un lote largo mezcle revisiones distintas;
- separación física o lógica respecto al outbox de invitaciones WebAdmin;
- ningún fallback que envíe contenido dummy, borradores o una variante de otro
  idioma.

El outbox usará claim, lease, fencing y ACK. La semántica será
`at-least-once`: un proveedor puede aceptar un mensaje y caer el worker antes
de confirmar el ACK. Los enlaces de baja y verificación serán de un solo uso o
revocables, con el valor bruto fuera de logs y hashes persistidos cuando
corresponda.

## Lotes, límites y reintentos

El dispatcher futuro será one-shot y no un daemon. El contrato inicial deberá
fijar como mínimo:

- lote configurable dentro de un rango cerrado de 1 a 100, con un default
  conservador;
- límite de destinatarios por campaña y por proyecto antes de materializar
  trabajo;
- ritmo máximo acorde al proveedor SMTP, sin permitir un valor ilimitado;
- una sola campaña activa por publicación y control de concurrencia entre
  workers;
- máximo de cinco intentos por entrega;
- backoff mínimo de 60, 300, 900 y 3600 segundos tras los cuatro primeros
  fallos;
- fallo terminal después del quinto intento, visible al operador mediante
  contadores y métricas sin datos personales;
- posibilidad de pausar campañas nuevas sin perder el estado ya confirmado.

Los límites finales deberán vivir en configuración tipada y acotada. No se
aceptarán enteros arbitrarios desde el request ni se usará el tamaño total de
la audiencia como una única carga en memoria.

## Scheduler futuro

La entrega necesitará un cron o scheduler por consumidor y producción. Será
un proceso distinto del dispatcher de invitaciones para impedir que una
campaña masiva retrase correos de acceso. Un posible comando
`liquidstack:blog:mail:dispatch` es solo un nombre de diseño: no existe todavía
y no debe documentarse como operativo hasta implementar su runtime, comando y
pruebas.

El scheduler futuro deberá heredar las reglas de operación segura de
[WebAdmin](webadmin-mail-scheduler-produccion.md): ejecución one-shot,
prevención de solapes, secretos fuera de argumentos, contadores seguros,
alertas, límites y ninguna mutación automática durante Composer.

## Panel y permisos futuros

La UI administrativa deberá separar, mediante capacidades propias:

- consulta y exportación autorizada de suscriptores;
- configuración editorial de una campaña;
- envío o reenvío explícito;
- pausa y cancelación de trabajo pendiente;
- consulta de métricas agregadas y fallos terminales.

No se mostrará el listado completo de destinatarios en logs o mensajes de
error. Exportar, borrar, suprimir o reactivar una identidad exigirá auditoría y
un contrato de permisos específico.

## Criterios antes de entrar en el MVP

- Migraciones aditivas con postcondiciones SQLite y MySQL/MariaDB.
- Modelo de consentimiento, verificación, baja y supresión revisado.
- Publicación rápida aunque existan miles de destinatarios.
- Tests de idempotencia, dos workers, ACK perdido, reintentos, límites y
  cancelación por retirada.
- Capturador SMTP local para QA sin destinatarios externos.
- Prueba opt-in con proveedor real y cuentas controladas.
- Runbook de cron por proyecto, monitorización, backup y recuperación.
- Recursos públicos y privados accesibles, multidioma y sin editor inline para
  datos procedentes de DB.
