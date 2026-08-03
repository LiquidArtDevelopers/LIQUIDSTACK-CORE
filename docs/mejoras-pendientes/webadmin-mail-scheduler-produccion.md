# Scheduler de correo de WebAdmin en producción

> Estado: pendiente de implantación por proyecto y entorno de producción.
> CORE dispone del dispatcher one-shot y del outbox de invitaciones, pero no
> instala ni configura hoy ningún cron, tarea programada o daemon.

## Frontera actual

La recuperación de contraseña no depende de este pendiente. La petición
`POST` intenta entregar su correo de forma síncrona y dirigida al destinatario
de esa solicitud. No crea trabajo en el outbox, no queda esperando un proceso
posterior y no se recupera ejecutando el dispatcher. Si SMTP no confirma la
entrega, la interfaz muestra un fallo genérico y permite iniciar una nueva
solicitud, sin exponer diagnósticos del proveedor, host, destinatario ni causa.
La diferencia respecto al resultado ordinario puede revelar que una identidad
era elegible durante un fallo SMTP; es el compromiso aceptado por el feedback
inmediato y deberá reevaluarse si producción exige no enumeración estricta.

El outbox de WebAdmin queda reservado a entregas que nacen fuera de ese flujo
síncrono, principalmente:

- invitaciones iniciales creadas por el bootstrap;
- invitaciones y reenvíos explícitos de editores;
- futuras notificaciones administrativas que declaren expresamente este
  contrato.

Mientras no se implante el scheduler, esas entregas se procesan de forma
explícita desde la raíz del consumidor:

```console
composer liquidstack:webadmin:mail:dispatch --limit=20 --format=json
```

El comando procesa un lote finito y termina. No espera, no se convierte en
daemon y nunca debe invocarse desde `composer install` o `composer update`.

## Objetivo del pendiente

Cada producción que active WebAdmin deberá poder programar el mismo comando
one-shot con los ejecutables, directorio de trabajo y entorno reales de ese
despliegue. CORE documentará adaptadores de operación, pero no puede crear una
tarea portable porque cron, systemd timers, paneles de hosting y el
Programador de tareas de Windows tienen contratos distintos.

La implantación deberá:

1. ejecutarse por proyecto, sin una cola global compartida entre clientes;
2. cargar secretos desde el entorno seguro del proceso, nunca desde argumentos
   visibles ni desde el repositorio;
3. impedir solapes en la capa de scheduling, aunque leases y fencing sigan
   protegiendo la consistencia en DB;
4. usar un `--limit` entre 1 y 100 y terminar después de cada lote;
5. registrar solo código de salida y contadores seguros;
6. alertar ante errores consecutivos sin imprimir correos, tokens,
   credenciales, respuestas SMTP, DSN o SQL;
7. mantener `zend.exception_ignore_args=On` en el PHP utilizado por la tarea;
8. conservar la entrega `at-least-once`, el backoff y el límite de intentos
   del outbox, aceptando el duplicado excepcional tras un ACK perdido.

La frecuencia concreta pertenecerá al runbook del hosting. Un intervalo corto
puede reducir la espera de una invitación, pero no autoriza a ejecutar un bucle
permanente ni a ignorar límites del proveedor SMTP.

## Fuera de alcance

- Volver a encolar recuperaciones de contraseña.
- Enviar filas antiguas o ajenas desde el `POST` de recuperación.
- Provisionar tareas del servidor durante Composer o una migración.
- Compartir workers, credenciales o colas entre proyectos consumidores.
- Usar el scheduler como sustituto de la notificación de fallo en la UI.
- Manipular manualmente estados, locks, hashes o tokens del outbox.

Las futuras campañas para suscriptores de Blog tendrán su propio dominio,
outbox y límites para que un envío masivo no retrase las invitaciones de
WebAdmin. Su alcance está separado en
[Notificaciones de Blog a suscriptores](blog-notificaciones-suscriptores.md).

## Criterios para cerrar el pendiente

- Runbook probado al menos en un cron Unix y en un hosting administrado o tarea
  equivalente, sin secretos en la definición visible.
- Exclusión de solapes y alerta verificadas con códigos de salida reales.
- Prueba de caída SMTP, backoff, recuperación posterior y fallo terminal.
- Prueba de actualización y despliegue que demuestre que Composer no crea,
  habilita ni borra la tarea.
- Procedimiento documentado para pausar, reanudar y retirar el scheduler sin
  modificar filas a mano.
- Confirmación expresa de que el formulario de recuperación continúa siendo
  síncrono y no consulta ni alimenta el outbox.
