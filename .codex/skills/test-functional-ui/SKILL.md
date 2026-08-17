---
name: test-functional-ui
description: Diseñar, ejecutar y cerrar QA de cualquier interfaz funcional mediante dos capas complementarias: pruebas de requisitos/código/integración y exploración adversarial de recorridos de usuario en navegador real. Usar al crear, cambiar, auditar o dar por terminados formularios, editores, modales, listados, filtros, uploads, navegación, dashboards, áreas privadas o cualquier herramienta donde la UX y la respuesta ante usos habituales, alternativos, incorrectos o inesperados sean relevantes.
---

# Probar UI funcional

## Mantener dos capas obligatorias

1. Ejecutar las pruebas técnicas que correspondan: requisitos, dominio,
   contratos, código, seguridad, accesibilidad, integración, red, persistencia y
   regresión. No sustituirlas por pruebas manuales.
2. Añadir una exploración práctica en navegador real. Los tests automatizados
   demuestran comportamientos conocidos; esta capa busca deuda emergente al
   usar la aplicación de formas que nadie había especificado.

No revisar la implementación línea por línea como método de esta segunda capa.
Empezar como caja negra, sin asumir el camino imaginado por quien desarrolló la
UI. Usar el código después para diagnosticar y corregir lo descubierto.

## Inventariar líneas de usabilidad

- Dividir el producto por objetivos completos de una persona, no por páginas,
  componentes, endpoints o clases. Ejemplos: registrarse, iniciar sesión, crear
  contenido, editarlo, buscar y filtrar, subir un archivo, pagar, duplicar,
  revisar/restaurar, configurar una cuenta o eliminar/recuperar algo.
- Incluir todos los puntos de entrada y los saltos entre herramientas. Un modal
  o una pantalla aislada no equivale al recorrido completo.
- Identificar para cada recorrido: inicio, objetivo, variantes legítimas,
  decisiones, estados persistentes, salida, cancelación, recuperación y
  conexiones con otros recorridos.
- Ejercitar el 100 % de las líneas inventariadas. Esta cobertura significa que
  todo recorrido funcional se ha tanteado; no que se hayan enumerado todas las
  combinaciones posibles ni que pueda prometerse la ausencia de defectos
  desconocidos.

## Explorar como personas distintas

Recorrer cada línea varias veces y variar el comportamiento:

- **Habitual:** completar el objetivo de la forma más evidente.
- **Alternativo:** alcanzar el mismo objetivo por otros accesos, órdenes o
  herramientas disponibles.
- **Novato:** tocar controles para descubrirlos, interpretar literalmente el
  copy, equivocarse y buscar cómo volver.
- **Impaciente:** doble clic, submits repetidos, acciones rápidas mientras hay
  loading y navegación antes de terminar.
- **Distraído:** abandonar a mitad, volver, recargar, usar atrás/adelante,
  reabrir desde BFCache o continuar en otra pestaña.
- **Uso incorrecto:** omitir pasos, dejar campos vacíos, elegir combinaciones
  incoherentes, pegar contenido extraño o enorme y accionar fuera de orden.
- **Experto:** teclado, atajos, edición rápida, varias pestañas y flujos no
  lineales.
- **Contextos distintos:** móvil, escritorio, viewport corto, zoom, touch,
  teclado, lector, permisos diferentes, sesión vencida, offline, red lenta,
  timeout y respuestas fallidas o inesperadas.

No limitarse a un guion cerrado. Seguir cada comportamiento sorprendente y
convertirlo en una nueva rama de exploración. Tocar todas las herramientas
accesibles del recorrido e intentar combinarlas.

## Buscar fallos de UX activamente

Observar si la interfaz:

- explica el estado actual, lo que ocurrirá y el resultado real;
- mantiene foco, teclado, scroll, responsive, jerarquía y controles utilizables;
- evita parpadeos, saltos, solapes, controles muertos y esperas indefinidas;
- impide duplicados, pérdida de datos, corrupción, éxito falso y estados
  contradictorios;
- conserva lo escrito ante errores y ofrece una recuperación clara;
- responde de forma segura y comprensible a cancelar, cerrar, volver, repetir,
  interrumpir o hacer algo fuera de lo esperado;
- mantiene coherencia entre UI, petición/respuesta y estado persistido.

Una operación técnicamente segura pero incomprensible, sin salida o difícil de
recuperar sigue siendo deuda de UX.

## Ejecutar con seguridad y evidencia

- Usar un consumidor y datos QA controlados. Capturar huella previa, evitar
  publicar o notificar a personas reales y preferir limpieza recuperable.
- No efectuar pagos, envíos externos, borrados permanentes ni otras mutaciones
  materiales fuera del alcance autorizado.
- En cada hallazgo guardar pasos, viewport, foco, resultado visual, consola/red,
  códigos, tiempos y estado persistente no sensible.
- Contrastar cuando aplique tres planos: lo que la persona ve y puede hacer, la
  petición/respuesta real y el estado oficial posterior.

## Corregir y cerrar

1. Reproducir el fallo con el recorrido mínimo sin perder el contexto que lo
   desencadenó.
2. Diagnosticarlo y aplicar un arreglo acotado.
3. Añadir una regresión automatizada proporcional al riesgo.
4. Repetir el uso que falló y los recorridos adyacentes en navegador real.
5. Registrar la nueva rama en el inventario para futuras sesiones.

No cerrar la UI con fallos de UX reproducibles conocidos, salvo deuda aceptada
explícitamente por el usuario. El objetivo de cierre es cero fallos de UX
conocidos dentro del alcance ejercitado, además de la primera capa técnica en
verde. Una captura aislada, un DOM falso o una mega-suite sin interacción real
no sustituyen esta exploración; la exploración manual tampoco sustituye los
tests automatizados.

Cuando la UI pertenezca a CORE, promover junto al cambio las regresiones y el
inventario de recorridos, y repetir la exploración tras instalar mediante
Composer en al menos un stack consumidor.
