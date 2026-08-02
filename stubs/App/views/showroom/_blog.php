<?php
/**
 * Recursos públicos de Blog. Los fixtures proceden del catálogo templates;
 * este partial no consulta la base de datos ni construye el runtime Blog.
 */
echo controller('sectionBlogGrid01', 0, [
    'items' => 4,
]);
