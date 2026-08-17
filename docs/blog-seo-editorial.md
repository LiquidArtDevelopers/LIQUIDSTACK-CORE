# Medidor SEO editorial del Blog

## Alcance

El primer corte del medidor vive en `App\Core\Blog\Seo` y reutiliza el
`BlogDraft` y el `BlogDocument` estructurado que ya forman cada revisión. Es
determinista, explicable y estrictamente orientativo:

- no calcula una puntuación de 0 a 100;
- no bloquea guardar, publicar, retirar ni restaurar revisiones;
- no persiste resultados y no necesita una migración;
- no llama a APIs externas ni traduce contenido;
- devuelve únicamente `Bien`, `Revisar` o `Pendiente` con una explicación.

Los intervalos de longitud son referencias editoriales, no garantías de
posicionamiento. La claridad y la intención de búsqueda prevalecen sobre
forzar una cifra.

## Comprobaciones v1

El análisis cubre:

- longitud Unicode de title, meta description, H1 y slug;
- solapamiento semántico entre title y H1 sin exigir igualdad;
- recuento de palabras y presencia de la propuesta en las primeras 100;
- jerarquía H2-H6 sin saltos, encabezados repetidos o vacíos;
- ALT informativo e imágenes marcadas como decorativas;
- palabras repetidas mecánicamente y concentración anómala de términos;
- preview SERP con locale, URL, title y descripción;
- posible canibalización frente a variantes publicadas del mismo idioma y un
  inventario estático opcional.

El panel se renderiza en servidor con la última instantánea de trabajo guardada.
Cuando `0014_blog_private_draft_publication` está lista, esa instantánea es el
workspace privado de una variante publicada y no su cabecera pública anterior.
Mientras se edita, `blog-editor.js` solicita una revisión del payload actual al
endpoint privado
`POST /admin/blog/editor/seo-analysis` con 650 ms de debounce y cancela la
petición anterior mediante `AbortController`. El endpoint exige sesión,
capacidad `blog.articles.edit`, acceso a medios y CSRF válido. Sus respuestas
son `no-store` y nunca contienen el documento completo.

`Guardar borrador` y el guardado privado de categorías no cambian el artículo
público ni sus asignaciones visibles. El análisis de canibalización continúa
comparando solo publicaciones del mismo idioma y excluye siempre el artículo
actual; `Publicar` es una acción separada y la única que promociona la
instantánea guardada a las proyecciones públicas.

Si el medidor falla, el editor continúa disponible. El panel muestra un estado
pendiente o temporalmente no disponible; guardar y publicar conservan sus
contratos previos.

## Inventario canónico estático opcional

Cada consumidor puede crear:

`App/config/seo/canonical-pages.json`

El fichero es project-owned y CORE no lo genera ni sobrescribe. Contrato v1:

```json
{
  "schema": "liquidstack.seo.canonical-pages",
  "version": 1,
  "pages": [
    {
      "locale": "es",
      "url": "/asesoria-fiscal",
      "h1": "Asesoría fiscal",
      "seo_title": "Asesoría fiscal para empresas"
    }
  ]
}
```

Las claves son exactas, las URLs deben ser paths públicos seguros y
`seo_title` puede ser `null`. Un fichero inválido no rompe el editor: la
canibalización pasa a `Pendiente` para evitar un falso verde.

La lectura de artículos publicados está acotada a 200 candidatos más uno para
detectar overflow. Si el catálogo supera ese límite, el resultado también es
`Pendiente`. Un futuro read-model de términos permitirá analizar catálogos
mayores sin lecturas completas.

## Ciclo de vida de URL pública

La URL publicada se conserva en un historial independiente del borrador. El
contrato no infiere sustitutos ni crea redirecciones de conveniencia:

- retirar una publicación recuperable responde `404` y permite republicar la
  misma URL;
- declarar expresamente que una URL no tendrá sustituto responde `410`;
- una redirección `301` exige una acción autenticada y un artículo sustituto
  publicado en el mismo idioma;
- si el sustituto deja de publicarse, la URL antigua falla cerrada con `404`;
- nunca se redirige automáticamente a inicio, categoría o una landing vacía.

Publicar, retirar y resolver definitivamente comparten la transacción del CAS,
capacidad `blog.articles.publish`, CSRF y auditoría. `GET` y `HEAD`, sitemap y
feeds son de solo lectura y solo exponen la proyección publicada vigente.

## Fuera de este corte

Quedan expresamente fuera la keyword objetivo persistida, el análisis con IA,
la traducción, Search Console, datos de terceros y un constructor de reglas
por proyecto. Cualquier ampliación debe mantener el carácter advisory y la
independencia del flujo de publicación.
