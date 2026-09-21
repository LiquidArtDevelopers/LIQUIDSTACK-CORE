# Liquid Commerce

Liquid Commerce es un modulo interno de `liquidstack/core`. El nombre
`liquidstack/commerce` es un selector logico, no un paquete fisico separado.
Commerce depende de WebAdmin y no depende de Blog.

## Activacion y exposicion publica

La dependencia directa `liquidstack/commerce` activa providers, navegacion,
capacidades y migraciones. Retirar el selector deja de registrar el modulo,
pero nunca elimina datos, medios ni ficheros publicados.

La configuracion project-owned vive en `App/config/modules/commerce.php` y
separa la seleccion del modulo de su exposicion publica. `public.enabled=false`
permite preparar el catalogo desde WebAdmin sin reclamar rutas publicas. Las
migraciones siempre se planifican y aplican mediante los comandos explicitos de
LiquidStack; Composer no conecta a la DB ni crea contenido.

Commerce, WebAdmin y cualquier otro modulo activo usan el mismo perfil fisico
de conexion. Commerce conserva un namespace de tablas propio, cuyo prefijo
neutral es `ls_commerce_`.

## Producto y localizaciones

`Product` es la identidad interna estable. El copy project-owned puede mostrar
Productos, Vehiculos, Furgonetas u otra denominacion.

Un producto separa:

- estado editorial: `draft`, `active`, `inactive` o `archived`;
- disponibilidad: `available`, `reserved`, `sold` o `unavailable`;
- referencia opcional, fechas y bloqueo optimista;
- precio opcional en unidades menores y moneda ISO; sin precio se presenta
  como consulta comercial, nunca como cero.

Al crear un producto se reserva una variante para cada locale activo. El locale
principal debe contener los campos publicables. Una variante sin traduccion no
copia el texto principal: se resuelve como fallback en lectura y WebAdmin la
identifica expresamente. El fallback publico es `noindex, follow`, usa canonical
hacia la variante principal y no entra en sitemap ni `hreflang` hasta disponer
de traduccion real.

## Taxonomias, atributos y URL

Las categorias tienen identidad estable, padre opcional, orden y
localizaciones. Se rechazan ciclos y profundidades fuera del limite. Las
etiquetas son planas, con identidad estable y traducciones. Un producto puede
pertenecer a varias categorias, pero una unica categoria canonica determina su
URL; las restantes solo participan en filtros.

La ruta publica localizada sigue esta forma:

```text
/{base}/{familia}/{subfamilia}/{producto}
```

El path completo se registra de forma unica por locale. Renombrar o mover una
categoria o cambiar el slug crea historia y redireccion; nunca se reconstruye
una URL publicada sin conservar su ciclo anterior.

Las caracteristicas son definiciones tipadas y reutilizables asociables a
categorias. Los tipos iniciales son texto, numero, booleano, seleccion simple,
seleccion multiple y fecha. Las etiquetas y opciones se localizan; los valores
numericos y booleanos son comunes y el texto libre puede localizarse. Cada
definicion declara unidad, orden y si puede participar en filtros. No se crean
columnas dinamicas ni se usa un JSON opaco como indice publico.

En la primera versión, las definiciones y opciones de atributos son
append-only: pueden crearse y asignarse, pero renombrarlas, reordenarlas o
archivarlas queda fuera del contrato hasta disponer de historial y migración de
valores. Los valores de cada producto sí son editables.

## Medios

Commerce reutiliza Media de WebAdmin para portada y galeria. ALT y caption
pertenecen al uso localizado, no al asset global. Un provider de uso impide
retirar un medio todavia referenciado. No existe un segundo storage Commerce.

## Modo consulta

El unico modo transaccional inicial es `inquiry`. La UI publica denomina
"Lista de interes" a la cesta, aunque el dominio conserve una cesta reusable.
La venta no aparece como opcion seleccionable hasta que existan pedidos,
inventario, impuestos, proveedor de pago, webhooks y reembolsos probados.

La cesta publica usa un token opaco en una cookie necesaria, host-only,
`HttpOnly` y `SameSite=Lax`; nunca guarda PII. En desarrollo adopta
`LIQUIDSTACK_DEV_PROJECT_ID` para no colisionar entre stacks que comparten
`localhost`, con independencia del puerto elegido. Los productos se conservan
por UUID y se revalidan al enviar la consulta.

La cookie es estrictamente funcional: no depende de una categoría opcional de
CookieLad y no se replica en `localStorage` ni `sessionStorage`. El JavaScript
público trabaja contra los endpoints de Commerce y el servidor mantiene el
estado autoritativo.

Las rutas localizadas de la lista se declaran en `inquiry_paths`. El
destinatario se obtiene de `LIQUIDSTACK_COMMERCE_INQUIRY_RECIPIENT` y conserva
`MAIL_ADMIN` solo como compatibilidad; la version del consentimiento se fija
con `LIQUIDSTACK_COMMERCE_PRIVACY_VERSION`.

Una consulta se persiste antes de contactar SMTP. Cada linea guarda una
instantanea de titulo, URL, imagen, precio visible y locale. En la misma
operacion se crean dos trabajos idempotentes del outbox Commerce: confirmacion
al visitante y aviso al destinatario administrativo configurado. Commerce
reutiliza el transporte SMTP de WebAdmin, pero no su outbox de credenciales.
Un fallo de correo no pierde la consulta y puede reintentarse mediante
`composer liquidstack:commerce-mail-dispatch`.

En producción debe programarse un worker periódico y acotado, por ejemplo:

```text
composer liquidstack:commerce-mail-dispatch --limit=20
```

Cada mensaje incluye la instantánea localizada de los productos, su referencia
y precio cuando existan y una URL pública de la portada. El correo nunca
expone la ruta privada del storage de Media.

## Interes publico y privacidad

El contador representa solicitudes aceptadas, no aperturas ni altas en cesta.
No se presenta como numero de usuarios porque una persona anonima puede enviar
mas de una solicitud. La proyeccion publica admite umbral y tramos, por ejemplo
"Mas de 5 solicitudes de informacion". Idempotencia, rate limit y proteccion
anti-bot preceden al incremento.

El límite antiabuso persiste únicamente HMACs de la IP y del correo. El valor
bruto no se guarda en esa tabla: como máximo se aceptan diez intentos por IP en
diez minutos y tres por dirección en una hora. Superar un límite responde `429`
sin crear una consulta ni incrementar el contador.

La solicitud conserva la version del consentimiento de privacidad y una
politica de retencion. La purga de PII puede mantener un agregado anonimo sin
reidentificacion. Datos personales, destinatarios y credenciales nunca se
escriben en logs, manifiestos, argumentos CLI ni diagnosticos.

## Superficies

Commerce aporta al shell compartido de WebAdmin la gestion de productos,
taxonomias, atributos, solicitudes y ajustes, filtrada por capacidades. La
capa publica usa hooks tipados y shells project-owned para catalogo, ficha y
consulta. Las vistas no consultan PDO ni emiten cabeceras de seguridad.

Los recursos visuales Commerce pueden inspirarse en Blog, pero conservan
controladores, templates, SCSS, JS y semantica propios. Commerce no consulta
tablas, repositorios ni clases del dominio Blog.

## Venta futura

Una ampliacion de venta creara pedidos y pagos separados de las consultas. La
cesta podra producir una `Inquiry` o, en un modo futuro explicitamente listo,
un `Order`. Una consulta historica nunca se convierte implicitamente en pedido
y LiquidStack nunca almacena datos de tarjeta.

## Puesta en marcha

1. Seleccionar `liquidstack/commerce` y actualizar `liquidstack/core`.
2. Revisar `App/config/modules/commerce.php`; `public.enabled=false` mantiene el
   catálogo privado mientras se prepara.
3. Configurar `LIQUIDSTACK_COMMERCE_INQUIRY_RECIPIENT` y una versión estable en
   `LIQUIDSTACK_COMMERCE_PRIVACY_VERSION`; las credenciales SMTP siguen siendo
   las compartidas con WebAdmin.
4. Ejecutar `composer liquidstack:doctor`, `migrate --plan` y
   `migrate --dry-run`; crear un backup recuperable de DB y Media antes de
   autorizar `migrate --apply`.
5. Inicializar Media cuando corresponda, generar el bundle y completar el QA
   de WebAdmin, catálogo, ficha, lista de interés, correo y sitemap.
6. Activar `public.enabled=true` únicamente cuando rutas, traducciones, política
   de privacidad y contenido estén listos.

Las migraciones, cambios de `.env`, inicialización de storage y envío de correo
son acciones explícitas. Ni `composer install` ni `composer update` escriben en
la DB o inventan configuración productiva.
