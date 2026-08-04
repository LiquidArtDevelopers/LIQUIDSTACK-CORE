# Pendiente operativo: scheduler de retención analítica Blog

CORE entrega el comando one-shot y acotado:

```bash
composer liquidstack:blog:analytics:purge --yes --format=json
```

No lo ejecuta durante `composer install`, `composer update`, migraciones ni
peticiones HTTP. Antes de activar `analytics.enabled` en un proyecto de
producción hay que configurar en su servidor un cron o scheduler que invoque
el comando con la periodicidad acordada y desde la raíz correcta del proyecto.

La adopción debe comprobar:

- usuario del proceso, directorio de trabajo y PHP/Composer correctos;
- acceso al mismo `.env` y a la misma DB que el runtime web;
- salida y código de proceso monitorizados, sin registrar secretos;
- ejecución solapada bloqueada por el scheduler;
- primera ejecución manual con backup y revisión de los contadores;
- `retention_days` acordado con la política de privacidad del cliente.

El comando elimina sesiones vencidas sin vistas recientes y sus vistas por la
FK `ON DELETE CASCADE`; después elimina vistas vencidas que pertenezcan a una
sesión todavía activa. No instala daemon, no reintenta por sí solo y no
modifica la configuración. La automatización concreta sigue
pendiente por proyecto hasta disponer de su infraestructura de producción.
