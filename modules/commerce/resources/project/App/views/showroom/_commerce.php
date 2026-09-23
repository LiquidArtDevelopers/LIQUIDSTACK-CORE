<?php

/**
 * Veinte artículos de ropa ficticios para el showroom y para el fallback de
 * desarrollo del catálogo vacío. Nunca se insertan en la base de datos.
 */
$commerceShowroomLanguage = in_array(
    (string) ($showroomLanguage ?? 'es'),
    ['es', 'en', 'eu'],
    true
) ? (string) $showroomLanguage : 'es';

$commerceShowroomBasePath = isset($commerceShowroomBasePathOverride)
    && is_string($commerceShowroomBasePathOverride)
    && str_starts_with($commerceShowroomBasePathOverride, '/')
        ? $commerceShowroomBasePathOverride
        : match ($commerceShowroomLanguage) {
            'en' => '/en/showroom/commerce',
            'eu' => '/eu/showroom/commerce',
            default => '/es/showroom/commerce',
        };
$commerceShowroomCopy = [
    'es' => [
        'catalog_heading' => 'sectionCommerceCatalog01 · Catálogo Matrix de moda',
        'catalog_intro' => 'Veinte artículos ficticios permiten comprobar búsqueda, taxonomías, estados y una lista de interés sin consultar ni modificar la base de datos.',
        'item_heading' => 'artCommerceItem01 · Ficha ampliada de producto',
        'inquiry_heading' => 'sectionCommerceInquiry01 · Solicitud de información',
        'inquiry_intro' => 'Esta demostración valida la selección y el formulario en el navegador, pero nunca almacena datos ni envía correos.',
        'detail' => 'Ver ficha',
        'add' => 'Añadir a la lista',
        'added' => 'Quitar de la lista',
        'interest' => 'Ver lista de interés',
        'interest_summary' => 'artículos seleccionados',
        'added_status' => 'Artículo añadido a la lista de interés.',
        'removed_status' => 'Artículo retirado de la lista de interés.',
        'empty' => 'Ningún artículo coincide con los filtros.',
        'reference' => 'Referencia',
        'availability' => 'Disponibilidad',
        'commercial' => 'Condición comercial',
        'search_label' => 'Buscar artículos',
        'search_placeholder' => 'Nombre o referencia',
        'category_label' => 'Familia',
        'tag_label' => 'Etiqueta',
        'all' => 'Todas',
        'filter_submit' => 'Aplicar filtros',
        'filter_clear' => 'Limpiar filtros',
        'inquiry_unavailable' => 'Consulta no disponible',
        'features_heading' => 'Características',
        'back' => 'Volver al catálogo',
        'social_proof' => '{count} solicitudes de información recibidas',
        'list_heading' => 'Artículos seleccionados',
        'form_heading' => 'Datos de contacto',
        'inquiry_empty' => 'La lista está vacía.',
        'remove' => 'Quitar',
        'name' => 'Nombre',
        'email' => 'Correo electrónico',
        'phone' => 'Teléfono opcional',
        'message' => 'Mensaje opcional',
        'privacy' => 'Acepto el uso de estos datos para responder a mi consulta',
        'privacy_help' => 'Demostración local sin almacenamiento ni envío de información.',
        'submit' => 'Enviar solicitud',
        'reset' => 'Limpiar formulario',
        'notice' => 'Modo showroom: ninguna acción sale del navegador.',
        'success' => 'Demostración completada correctamente.',
        'invalid' => 'Revisa los campos obligatorios antes de continuar.',
        'empty_error' => 'Añade al menos un artículo antes de continuar.',
        'summary' => 'Artículo ficticio inspirado en Matrix para probar tarjetas, filtros y listas de interés con contenido de moda localizado.',
        'description' => 'Este artículo de demostración pertenece a {category}. Su contenido permite revisar una ficha amplia, atributos variables y acciones de consulta sin representar una marca, un precio ni un producto real.',
        'available' => 'Disponible para consulta',
        'limited' => 'Últimas unidades de muestra',
        'reserved' => 'Reserva de muestra',
        'unavailable' => 'No disponible',
        'price' => 'Precio bajo consulta',
        'image_alt' => 'Imagen abstracta de muestra para {title}',
        'material_label' => 'Material',
        'fit_label' => 'Corte',
        'color_label' => 'Color',
    ],
    'en' => [
        'catalog_heading' => 'sectionCommerceCatalog01 · Matrix fashion catalog',
        'catalog_intro' => 'Twenty fictional items demonstrate search, taxonomies, availability and an interest list without reading from or writing to the database.',
        'item_heading' => 'artCommerceItem01 · Extended product detail',
        'inquiry_heading' => 'sectionCommerceInquiry01 · Information request',
        'inquiry_intro' => 'This demonstration validates the selection and form in the browser, but never stores data or sends email.',
        'detail' => 'View details',
        'add' => 'Add to list',
        'added' => 'Remove from list',
        'interest' => 'View interest list',
        'interest_summary' => 'selected items',
        'added_status' => 'Item added to the interest list.',
        'removed_status' => 'Item removed from the interest list.',
        'empty' => 'No items match the filters.',
        'reference' => 'Reference',
        'availability' => 'Availability',
        'commercial' => 'Commercial terms',
        'search_label' => 'Search items',
        'search_placeholder' => 'Name or reference',
        'category_label' => 'Family',
        'tag_label' => 'Tag',
        'all' => 'All',
        'filter_submit' => 'Apply filters',
        'filter_clear' => 'Clear filters',
        'inquiry_unavailable' => 'Inquiry unavailable',
        'features_heading' => 'Features',
        'back' => 'Back to catalog',
        'social_proof' => '{count} information requests received',
        'list_heading' => 'Selected items',
        'form_heading' => 'Contact details',
        'inquiry_empty' => 'The list is empty.',
        'remove' => 'Remove',
        'name' => 'Name',
        'email' => 'Email address',
        'phone' => 'Optional phone',
        'message' => 'Optional message',
        'privacy' => 'I agree to the use of these details to reply to my inquiry',
        'privacy_help' => 'Local demonstration with no data storage or delivery.',
        'submit' => 'Send request',
        'reset' => 'Clear form',
        'notice' => 'Showroom mode: no action leaves the browser.',
        'success' => 'Demonstration completed successfully.',
        'invalid' => 'Review the required fields before continuing.',
        'empty_error' => 'Add at least one item before continuing.',
        'summary' => 'A fictional Matrix-inspired item for testing cards, filters and interest lists with localized fashion content.',
        'description' => 'This demonstration item belongs to {category}. Its content exercises an extended product page, variable attributes and inquiry actions without representing a real brand, price or product.',
        'available' => 'Available for inquiry',
        'limited' => 'Last sample units',
        'reserved' => 'Sample reservation',
        'unavailable' => 'Unavailable',
        'price' => 'Price on request',
        'image_alt' => 'Abstract sample image for {title}',
        'material_label' => 'Material',
        'fit_label' => 'Fit',
        'color_label' => 'Color',
    ],
    'eu' => [
        'catalog_heading' => 'sectionCommerceCatalog01 · Matrix moda-katalogoa',
        'catalog_intro' => 'Fikziozko hogei artikuluk bilaketa, taxonomiak, egoerak eta interes-zerrenda probatzen dituzte datu-basea irakurri edo aldatu gabe.',
        'item_heading' => 'artCommerceItem01 · Produktuaren fitxa hedatua',
        'inquiry_heading' => 'sectionCommerceInquiry01 · Informazio-eskaera',
        'inquiry_intro' => 'Erakustaldi honek hautaketa eta formularioa nabigatzailean balioztatzen ditu, baina ez du daturik gordetzen edo mezurik bidaltzen.',
        'detail' => 'Ikusi fitxa',
        'add' => 'Gehitu zerrendara',
        'added' => 'Kendu zerrendatik',
        'interest' => 'Ikusi interes-zerrenda',
        'interest_summary' => 'artikulu hautatuta',
        'added_status' => 'Artikulua interes-zerrendara gehitu da.',
        'removed_status' => 'Artikulua interes-zerrendatik kendu da.',
        'empty' => 'Ez dago iragazkiekin bat datorren artikulurik.',
        'reference' => 'Erreferentzia',
        'availability' => 'Erabilgarritasuna',
        'commercial' => 'Merkataritza-baldintza',
        'search_label' => 'Bilatu artikuluak',
        'search_placeholder' => 'Izena edo erreferentzia',
        'category_label' => 'Familia',
        'tag_label' => 'Etiketa',
        'all' => 'Guztiak',
        'filter_submit' => 'Aplikatu iragazkiak',
        'filter_clear' => 'Garbitu iragazkiak',
        'inquiry_unavailable' => 'Kontsulta ez dago erabilgarri',
        'features_heading' => 'Ezaugarriak',
        'back' => 'Itzuli katalogora',
        'social_proof' => '{count} informazio-eskaera jaso dira',
        'list_heading' => 'Hautatutako artikuluak',
        'form_heading' => 'Harremanetarako datuak',
        'inquiry_empty' => 'Zerrenda hutsik dago.',
        'remove' => 'Kendu',
        'name' => 'Izena',
        'email' => 'Helbide elektronikoa',
        'phone' => 'Aukerako telefonoa',
        'message' => 'Aukerako mezua',
        'privacy' => 'Datu hauek nire kontsultari erantzuteko erabiltzea onartzen dut',
        'privacy_help' => 'Datuak gorde edo bidaltzen ez dituen tokiko erakustaldia.',
        'submit' => 'Bidali eskaera',
        'reset' => 'Garbitu formularioa',
        'notice' => 'Showroom modua: ekintzak ez dira nabigatzailetik ateratzen.',
        'success' => 'Erakustaldia behar bezala osatu da.',
        'invalid' => 'Berrikusi nahitaezko eremuak jarraitu aurretik.',
        'empty_error' => 'Gehitu gutxienez artikulu bat jarraitu aurretik.',
        'summary' => 'Matrixen inspiratutako fikziozko artikulua, txartelak, iragazkiak eta interes-zerrendak eduki lokalizatuarekin probatzeko.',
        'description' => 'Erakustaldiko artikulu hau {category} familiakoa da. Edukiak produktu-fitxa hedatua, atributu aldakorrak eta kontsulta-ekintzak berrikusteko balio du, benetako marka, prezio edo produkturik irudikatu gabe.',
        'available' => 'Kontsultarako erabilgarri',
        'limited' => 'Laginen azken unitateak',
        'reserved' => 'Lagin-erreserba',
        'unavailable' => 'Ez dago erabilgarri',
        'price' => 'Prezioa kontsultapean',
        'image_alt' => '{title} artikulurako lagin-irudi abstraktua',
        'material_label' => 'Materiala',
        'fit_label' => 'Patroia',
        'color_label' => 'Kolorea',
    ],
];
$commerceShowroomCategoryLabels = [
    'es' => [
        'outerwear' => 'Ropa / Abrigos y chaquetas',
        'tops' => 'Ropa / Partes superiores',
        'bottoms' => 'Ropa / Partes inferiores',
        'dresses' => 'Ropa / Vestidos',
        'footwear' => 'Accesorios / Calzado',
        'accessories' => 'Accesorios / Complementos',
        'bags' => 'Accesorios / Bolsos y mochilas',
    ],
    'en' => [
        'outerwear' => 'Clothing / Coats and jackets',
        'tops' => 'Clothing / Tops',
        'bottoms' => 'Clothing / Bottoms',
        'dresses' => 'Clothing / Dresses',
        'footwear' => 'Accessories / Footwear',
        'accessories' => 'Accessories / Small accessories',
        'bags' => 'Accessories / Bags and backpacks',
    ],
    'eu' => [
        'outerwear' => 'Arropa / Berokiak eta jakak',
        'tops' => 'Arropa / Goiko jantziak',
        'bottoms' => 'Arropa / Beheko jantziak',
        'dresses' => 'Arropa / Soinekoak',
        'footwear' => 'Osagarriak / Oinetakoak',
        'accessories' => 'Osagarriak / Osagarri txikiak',
        'bags' => 'Osagarriak / Poltsak eta motxilak',
    ],
];
$commerceShowroomTagLabels = [
    'es' => [
        'technical' => 'Técnico',
        'unisex' => 'Unisex',
        'urban' => 'Urbano',
        'limited' => 'Edición limitada',
        'new' => 'Novedad',
    ],
    'en' => [
        'technical' => 'Technical',
        'unisex' => 'Unisex',
        'urban' => 'Urban',
        'limited' => 'Limited edition',
        'new' => 'New',
    ],
    'eu' => [
        'technical' => 'Teknikoa',
        'unisex' => 'Unisex',
        'urban' => 'Hirikoa',
        'limited' => 'Edizio mugatua',
        'new' => 'Berria',
    ],
];
$commerceShowroomTitles = [
    'es' => [
        'trinity-trench' => 'Gabardina Trinity',
        'nebuchadnezzar-coat' => 'Abrigo Nebuchadnezzar',
        'neo-jacket' => 'Chaqueta Neo',
        'zion-windbreaker' => 'Cortavientos Zion',
        'red-pill-tee' => 'Camiseta Píldora Roja',
        'blue-pill-tee' => 'Camiseta Píldora Azul',
        'white-rabbit-hoodie' => 'Sudadera Conejo Blanco',
        'green-code-knit' => 'Jersey Código Verde',
        'operator-trousers' => 'Pantalón Operator',
        'zion-cargo' => 'Pantalón cargo Zion',
        'residual-skirt' => 'Falda Residual',
        'oracle-dress' => 'Vestido Oráculo',
        'lobby-boots' => 'Botas Lobby',
        'jump-sneakers' => 'Zapatillas Jump',
        'agent-glasses' => 'Gafas Agente',
        'construct-cap' => 'Gorra Construct',
        'morpheus-gloves' => 'Guantes Morpheus',
        'keymaker-bag' => 'Bolso Keymaker',
        'sentinel-backpack' => 'Mochila Sentinel',
        'deja-vu-scarf' => 'Bufanda Déjà Vu',
    ],
    'en' => [
        'trinity-trench' => 'Trinity trench coat',
        'nebuchadnezzar-coat' => 'Nebuchadnezzar overcoat',
        'neo-jacket' => 'Neo jacket',
        'zion-windbreaker' => 'Zion windbreaker',
        'red-pill-tee' => 'Red Pill T-shirt',
        'blue-pill-tee' => 'Blue Pill T-shirt',
        'white-rabbit-hoodie' => 'White Rabbit hoodie',
        'green-code-knit' => 'Green Code knit',
        'operator-trousers' => 'Operator trousers',
        'zion-cargo' => 'Zion cargo trousers',
        'residual-skirt' => 'Residual skirt',
        'oracle-dress' => 'Oracle dress',
        'lobby-boots' => 'Lobby boots',
        'jump-sneakers' => 'Jump sneakers',
        'agent-glasses' => 'Agent sunglasses',
        'construct-cap' => 'Construct cap',
        'morpheus-gloves' => 'Morpheus gloves',
        'keymaker-bag' => 'Keymaker bag',
        'sentinel-backpack' => 'Sentinel backpack',
        'deja-vu-scarf' => 'Deja Vu scarf',
    ],
    'eu' => [
        'trinity-trench' => 'Trinity gabardina',
        'nebuchadnezzar-coat' => 'Nabukodonosor berokia',
        'neo-jacket' => 'Neo jaka',
        'zion-windbreaker' => 'Zion haize-babesa',
        'red-pill-tee' => 'Pilula Gorria kamiseta',
        'blue-pill-tee' => 'Pilula Urdina kamiseta',
        'white-rabbit-hoodie' => 'Untxi Zuri txanoduna',
        'green-code-knit' => 'Kode Berde jertsea',
        'operator-trousers' => 'Operator galtzak',
        'zion-cargo' => 'Zion cargo galtzak',
        'residual-skirt' => 'Residual gona',
        'oracle-dress' => 'Orakulua soinekoa',
        'lobby-boots' => 'Lobby botak',
        'jump-sneakers' => 'Jump zapatilak',
        'agent-glasses' => 'Agente betaurrekoak',
        'construct-cap' => 'Construct txapela',
        'morpheus-gloves' => 'Morpheus eskularruak',
        'keymaker-bag' => 'Keymaker poltsa',
        'sentinel-backpack' => 'Sentinel motxila',
        'deja-vu-scarf' => 'Deja Vu bufanda',
    ],
];

$commerceShowroomDefinitions = [
    ['trinity-trench', 'MX-APP-001', 'outerwear', ['technical', 'limited'], 1, 'technical', 'tailored', 'black', 'limited', true],
    ['nebuchadnezzar-coat', 'MX-APP-002', 'outerwear', ['unisex', 'urban'], 2, 'wool', 'relaxed', 'graphite', 'available', true],
    ['neo-jacket', 'MX-APP-003', 'outerwear', ['technical', 'new'], 3, 'technical', 'tailored', 'black', 'available', true],
    ['zion-windbreaker', 'MX-APP-004', 'outerwear', ['technical', 'unisex'], 4, 'recycled', 'relaxed', 'green', 'available', true],
    ['red-pill-tee', 'MX-APP-005', 'tops', ['unisex', 'limited'], 1, 'cotton', 'regular', 'red', 'limited', true],
    ['blue-pill-tee', 'MX-APP-006', 'tops', ['unisex', 'urban'], 2, 'cotton', 'regular', 'blue', 'available', true],
    ['white-rabbit-hoodie', 'MX-APP-007', 'tops', ['unisex', 'new'], 3, 'cotton', 'relaxed', 'white', 'available', true],
    ['green-code-knit', 'MX-APP-008', 'tops', ['urban', 'limited'], 4, 'knit', 'relaxed', 'green', 'reserved', true],
    ['operator-trousers', 'MX-APP-009', 'bottoms', ['technical', 'urban'], 1, 'technical', 'straight', 'graphite', 'available', true],
    ['zion-cargo', 'MX-APP-010', 'bottoms', ['unisex', 'technical'], 2, 'recycled', 'relaxed', 'khaki', 'available', true],
    ['residual-skirt', 'MX-APP-011', 'bottoms', ['urban', 'new'], 3, 'satin', 'tailored', 'black', 'limited', true],
    ['oracle-dress', 'MX-APP-012', 'dresses', ['limited', 'new'], 4, 'viscose', 'fluid', 'purple', 'reserved', true],
    ['lobby-boots', 'MX-APP-013', 'footwear', ['urban', 'limited'], 1, 'vegan-leather', 'regular', 'black', 'available', true],
    ['jump-sneakers', 'MX-APP-014', 'footwear', ['unisex', 'new'], 2, 'recycled', 'regular', 'white', 'available', true],
    ['agent-glasses', 'MX-APP-015', 'accessories', ['unisex', 'urban'], 3, 'acetate', 'regular', 'black', 'unavailable', false],
    ['construct-cap', 'MX-APP-016', 'accessories', ['unisex', 'new'], 4, 'cotton', 'adjustable', 'white', 'available', true],
    ['morpheus-gloves', 'MX-APP-017', 'accessories', ['technical', 'limited'], 1, 'vegan-leather', 'fitted', 'black', 'limited', true],
    ['keymaker-bag', 'MX-APP-018', 'bags', ['urban', 'new'], 2, 'recycled', 'compact', 'bronze', 'available', true],
    ['sentinel-backpack', 'MX-APP-019', 'bags', ['technical', 'unisex'], 3, 'technical', 'adjustable', 'graphite', 'available', true],
    ['deja-vu-scarf', 'MX-APP-020', 'accessories', ['unisex', 'limited'], 4, 'wool', 'fluid', 'green', 'unavailable', false],
];
$commerceShowroomTerms = [
    'es' => [
        'technical' => 'Tejido técnico', 'wool' => 'Lana',
        'recycled' => 'Fibra reciclada', 'cotton' => 'Algodón orgánico',
        'knit' => 'Punto fino', 'satin' => 'Satén',
        'viscose' => 'Viscosa', 'vegan-leather' => 'Piel vegana',
        'acetate' => 'Acetato', 'tailored' => 'Entallado',
        'relaxed' => 'Relajado', 'regular' => 'Regular',
        'straight' => 'Recto', 'fluid' => 'Fluido',
        'adjustable' => 'Ajustable', 'fitted' => 'Ceñido',
        'compact' => 'Compacto', 'black' => 'Negro',
        'graphite' => 'Grafito', 'green' => 'Verde código',
        'red' => 'Rojo', 'blue' => 'Azul', 'white' => 'Blanco',
        'khaki' => 'Caqui', 'purple' => 'Violeta', 'bronze' => 'Bronce',
    ],
    'en' => [
        'technical' => 'Technical fabric', 'wool' => 'Wool',
        'recycled' => 'Recycled fibre', 'cotton' => 'Organic cotton',
        'knit' => 'Fine knit', 'satin' => 'Satin',
        'viscose' => 'Viscose', 'vegan-leather' => 'Vegan leather',
        'acetate' => 'Acetate', 'tailored' => 'Tailored',
        'relaxed' => 'Relaxed', 'regular' => 'Regular',
        'straight' => 'Straight', 'fluid' => 'Fluid',
        'adjustable' => 'Adjustable', 'fitted' => 'Fitted',
        'compact' => 'Compact', 'black' => 'Black',
        'graphite' => 'Graphite', 'green' => 'Code green',
        'red' => 'Red', 'blue' => 'Blue', 'white' => 'White',
        'khaki' => 'Khaki', 'purple' => 'Purple', 'bronze' => 'Bronze',
    ],
    'eu' => [
        'technical' => 'Ehun teknikoa', 'wool' => 'Artilea',
        'recycled' => 'Zuntz birziklatua', 'cotton' => 'Kotoi organikoa',
        'knit' => 'Puntu fina', 'satin' => 'Satena',
        'viscose' => 'Biskosa', 'vegan-leather' => 'Larru beganoa',
        'acetate' => 'Azetatoa', 'tailored' => 'Gorputzera egokitua',
        'relaxed' => 'Erosoa', 'regular' => 'Arrunta',
        'straight' => 'Zuzena', 'fluid' => 'Arina',
        'adjustable' => 'Erregulagarria', 'fitted' => 'Estua',
        'compact' => 'Trinkoa', 'black' => 'Beltza',
        'graphite' => 'Grafitoa', 'green' => 'Kode berdea',
        'red' => 'Gorria', 'blue' => 'Urdina', 'white' => 'Zuria',
        'khaki' => 'Kakia', 'purple' => 'Morea', 'bronze' => 'Brontzea',
    ],
];
$commerceShowroomImages = [
    1 => [
        'src' => '/assets/img/dummy/responsive/dummy01-900.avif',
        'variants' => [[480, 300, 'dummy01-480.avif'], [900, 563, 'dummy01-900.avif'], [1800, 1125, 'dummy01-1800.avif'], [2560, 1600, 'dummy01-2560.avif']],
    ],
    2 => [
        'src' => '/assets/img/dummy/responsive/dummy02-899.avif',
        'variants' => [[480, 323, 'dummy02-480.avif'], [899, 605, 'dummy02-899.avif'], [1800, 1211, 'dummy02-1800.avif'], [2560, 1722, 'dummy02-2560.avif']],
    ],
    3 => [
        'src' => '/assets/img/dummy/responsive/dummy03-900.avif',
        'variants' => [[480, 318, 'dummy03-480.avif'], [900, 596, 'dummy03-900.avif'], [1800, 1193, 'dummy03-1800.avif'], [2560, 1696, 'dummy03-2560.avif']],
    ],
    4 => [
        'src' => '/assets/img/dummy/responsive/dummy04-900.avif',
        'variants' => [[480, 270, 'dummy04-480.avif'], [900, 506, 'dummy04-900.avif'], [1800, 1013, 'dummy04-1800.avif'], [2560, 1440, 'dummy04-2560.avif']],
    ],
];

$commerceCopy = $commerceShowroomCopy[$commerceShowroomLanguage];
$commerceCategories = $commerceShowroomCategoryLabels[
    $commerceShowroomLanguage
];
$commerceTags = $commerceShowroomTagLabels[$commerceShowroomLanguage];
$commerceTitles = $commerceShowroomTitles[$commerceShowroomLanguage];
$commerceTerms = $commerceShowroomTerms[$commerceShowroomLanguage];
$commerceShowroomProducts = [];
foreach ($commerceShowroomDefinitions as $position => $definition) {
    [
        $id, $reference, $category, $tags, $imageNumber, $material,
        $fit, $color, $availability, $acceptsInquiries,
    ] = $definition;
    $title = $commerceTitles[$id];
    $image = $commerceShowroomImages[$imageNumber];
    $variants = array_map(
        static fn (array $variant): array => [
            'width' => $variant[0],
            'height' => $variant[1],
            'path' => '/assets/img/dummy/responsive/' . $variant[2],
        ],
        $image['variants']
    );
    $commerceShowroomProducts[] = [
        'id' => $id,
        'path' => $commerceShowroomBasePath . '?product=' . $id
            . '#showroom-commerce-item',
        'title' => $title,
        'summary' => $commerceCopy['summary'],
        'description' => str_replace(
            '{category}',
            $commerceCategories[$category],
            $commerceCopy['description']
        ),
        'reference' => $reference,
        'availability' => $commerceCopy[$availability],
        'commercialLabel' => $commerceCopy['price'],
        'imageSrc' => $image['src'],
        'imageAlt' => str_replace(
            '{title}',
            $title,
            $commerceCopy['image_alt']
        ),
        'imageTitle' => $title,
        'imageVariants' => $variants,
        'features' => [
            [
                'label' => $commerceCopy['material_label'],
                'value' => $commerceTerms[$material],
            ],
            [
                'label' => $commerceCopy['fit_label'],
                'value' => $commerceTerms[$fit],
            ],
            [
                'label' => $commerceCopy['color_label'],
                'value' => $commerceTerms[$color],
            ],
        ],
        'acceptsInquiries' => $acceptsInquiries,
        'category' => $category,
        'tags' => $tags,
        'inquiry_count' => ($position + 1) * 3,
    ];
}

$commerceShowroomRawQuery = $_GET['q'] ?? '';
$commerceShowroomQuery = is_string($commerceShowroomRawQuery)
    ? trim($commerceShowroomRawQuery)
    : '';
$commerceShowroomQuery = preg_replace('/\s+/u', ' ', $commerceShowroomQuery);
$commerceShowroomQuery = is_string($commerceShowroomQuery)
    && strlen($commerceShowroomQuery) <= 100
    && preg_match('/[\p{Cc}\p{Cf}]/u', $commerceShowroomQuery) !== 1
        ? $commerceShowroomQuery
        : '';
$commerceShowroomCategory = is_string($_GET['category'] ?? null)
    && isset($commerceCategories[$_GET['category']])
        ? $_GET['category']
        : '';
$commerceShowroomTag = is_string($_GET['tag'] ?? null)
    && isset($commerceTags[$_GET['tag']])
        ? $_GET['tag']
        : '';
$commerceShowroomFold = static fn (string $value): string =>
    function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
$commerceShowroomNeedle = $commerceShowroomFold($commerceShowroomQuery);
$commerceShowroomFilteredProducts = array_values(array_filter(
    $commerceShowroomProducts,
    static function (array $product) use (
        $commerceShowroomNeedle,
        $commerceShowroomCategory,
        $commerceShowroomTag,
        $commerceShowroomFold
    ): bool {
        if ($commerceShowroomNeedle !== '') {
            $haystack = $commerceShowroomFold(
                $product['title'] . ' ' . $product['summary'] . ' '
                . $product['reference']
            );
            if (!str_contains($haystack, $commerceShowroomNeedle)) {
                return false;
            }
        }
        if (
            $commerceShowroomCategory !== ''
            && $product['category'] !== $commerceShowroomCategory
        ) {
            return false;
        }

        return $commerceShowroomTag === ''
            || in_array($commerceShowroomTag, $product['tags'], true);
    }
));
$commerceShowroomRawPage = $_GET['page'] ?? '1';
$commerceShowroomPage = is_string($commerceShowroomRawPage)
    && preg_match('/\A[1-9]\d{0,5}\z/D', $commerceShowroomRawPage) === 1
        ? min((int) $commerceShowroomRawPage, 166667)
        : 1;
$commerceShowroomPageSize = 6;
$commerceShowroomPageCount = max(
    1,
    (int) ceil(count($commerceShowroomFilteredProducts) / $commerceShowroomPageSize)
);
$commerceShowroomPage = min($commerceShowroomPage, $commerceShowroomPageCount);
$commerceShowroomPaginatedProducts = array_slice(
    $commerceShowroomFilteredProducts,
    ($commerceShowroomPage - 1) * $commerceShowroomPageSize,
    $commerceShowroomPageSize
);
$commerceShowroomHasNext = $commerceShowroomPage < $commerceShowroomPageCount;
$commerceShowroomProductsById = [];
foreach ($commerceShowroomProducts as $product) {
    $commerceShowroomProductsById[$product['id']] = $product;
}
$commerceShowroomSelectedId = is_string($_GET['product'] ?? null)
    && isset($commerceShowroomProductsById[$_GET['product']])
        ? $_GET['product']
        : $commerceShowroomProducts[0]['id'];
$commerceShowroomSelectedProduct = $commerceShowroomProductsById[
    $commerceShowroomSelectedId
];
$commerceShowroomSelectedItems = [];
$commerceShowroomRawItems = $_GET['items'] ?? '';
if (is_string($commerceShowroomRawItems)) {
    foreach (array_slice(explode(',', $commerceShowroomRawItems), 0, 20) as $id) {
        $id = trim($id);
        if (isset($commerceShowroomProductsById[$id])) {
            $commerceShowroomSelectedItems[$id] = $id;
        }
    }
}
$commerceShowroomInquiryItems = $commerceShowroomSelectedItems === []
    ? array_slice($commerceShowroomProducts, 0, 3)
    : array_values(array_intersect_key(
        $commerceShowroomProductsById,
        $commerceShowroomSelectedItems
    ));
$commerceShowroomCategoryOptions = [];
foreach ($commerceCategories as $value => $label) {
    $commerceShowroomCategoryOptions[] = [
        'value' => $value,
        'label' => $label,
    ];
}
$commerceShowroomTagOptions = [];
foreach ($commerceTags as $value => $label) {
    $commerceShowroomTagOptions[] = [
        'value' => $value,
        'label' => $label,
    ];
}
$commerceShowroomLabels = [
    'detail' => $commerceCopy['detail'],
    'add' => $commerceCopy['add'],
    'added' => $commerceCopy['added'],
    'interest' => $commerceCopy['interest'],
    'interest_summary' => $commerceCopy['interest_summary'],
    'added_status' => $commerceCopy['added_status'],
    'removed_status' => $commerceCopy['removed_status'],
    'empty' => $commerceCopy['empty'],
    'reference' => $commerceCopy['reference'],
    'availability' => $commerceCopy['availability'],
    'commercial' => $commerceCopy['commercial'],
    'search_label' => $commerceCopy['search_label'],
    'search_placeholder' => $commerceCopy['search_placeholder'],
    'category_label' => $commerceCopy['category_label'],
    'tag_label' => $commerceCopy['tag_label'],
    'all' => $commerceCopy['all'],
    'filter_submit' => $commerceCopy['filter_submit'],
    'filter_clear' => $commerceCopy['filter_clear'],
    'inquiry_unavailable' => $commerceCopy['inquiry_unavailable'],
    'features_heading' => $commerceCopy['features_heading'],
    'back' => $commerceCopy['back'],
    'social_proof' => $commerceCopy['social_proof'],
    'development_fixture' => '1',
];
$commerceShowroomLabels = array_merge(
    $commerceShowroomLabels,
    match ($commerceShowroomLanguage) {
        'en' => [
            'previous' => 'View previous products',
            'next' => 'View next products',
            'pause' => 'Pause automatic playback',
            'resume' => 'Resume automatic playback',
            'pagination_previous' => 'Previous page',
            'pagination_next' => 'Next page',
            'pagination_label' => 'Catalogue pagination',
        ],
        'eu' => [
            'previous' => 'Ikusi aurreko produktuak',
            'next' => 'Ikusi hurrengo produktuak',
            'pause' => 'Pausatu erreprodukzio automatikoa',
            'resume' => 'Jarraitu erreprodukzio automatikoarekin',
            'pagination_previous' => 'Aurreko orria',
            'pagination_next' => 'Hurrengo orria',
            'pagination_label' => 'Katalogoaren orrikatzea',
        ],
        default => [
            'previous' => 'Ver productos anteriores',
            'next' => 'Ver productos siguientes',
            'pagination_previous' => 'Página anterior',
            'pagination_next' => 'Página siguiente',
            'pagination_label' => 'Paginación del catálogo',
            'pause' => 'Pausar reproducción automática',
            'resume' => 'Reanudar reproducción automática',
        ],
    }
);
$commerceShowroomInquiryLabels = [
    'list_heading' => $commerceCopy['list_heading'],
    'form_heading' => $commerceCopy['form_heading'],
    'empty' => $commerceCopy['inquiry_empty'],
    'remove' => $commerceCopy['remove'],
    'name' => $commerceCopy['name'],
    'email' => $commerceCopy['email'],
    'phone' => $commerceCopy['phone'],
    'message' => $commerceCopy['message'],
    'privacy' => $commerceCopy['privacy'],
    'privacy_help' => $commerceCopy['privacy_help'],
    'submit' => $commerceCopy['submit'],
    'reset' => $commerceCopy['reset'],
    'notice' => $commerceCopy['notice'],
    'success' => $commerceCopy['success'],
    'invalid' => $commerceCopy['invalid'],
    'empty_error' => $commerceCopy['empty_error'],
    'development_fixture' => '1',
];

echo controller('sectionCommerceSlider01', 0, [
    'header_level' => 2,
    'header_text' => str_replace(
        'sectionCommerceCatalog01',
        'sectionCommerceSlider01',
        $commerceCopy['catalog_heading']
    ),
    'intro_text' => $commerceCopy['catalog_intro'],
    'items_data' => $commerceShowroomProducts,
    'items' => count($commerceShowroomProducts),
    'inquiry_path' => $commerceShowroomBasePath
        . '#showroom-commerce-inquiry',
    'basket_add_path' => $commerceShowroomBasePath,
    'basket_remove_path' => $commerceShowroomBasePath,
    'return_to' => $commerceShowroomBasePath,
    'locale' => $commerceShowroomLanguage,
    'labels' => $commerceShowroomLabels,
    'selected_items' => array_values($commerceShowroomSelectedItems),
]);

echo controller('sectionCommerceCatalog01', 0, [
    'header_level' => 2,
    'header_text' => $commerceCopy['catalog_heading'],
    'intro_text' => $commerceCopy['catalog_intro'],
    'items_data' => $commerceShowroomPaginatedProducts,
    'items' => count($commerceShowroomPaginatedProducts),
    'inquiry_path' => $commerceShowroomBasePath
        . '#showroom-commerce-inquiry',
    'catalog_path' => $commerceShowroomBasePath,
    'basket_add_path' => $commerceShowroomBasePath,
    'basket_remove_path' => $commerceShowroomBasePath,
    'return_to' => $commerceShowroomBasePath,
    'locale' => $commerceShowroomLanguage,
    'labels' => $commerceShowroomLabels,
    'query' => [
        'search' => $commerceShowroomQuery,
        'category' => $commerceShowroomCategory,
        'tag' => $commerceShowroomTag,
        'page' => $commerceShowroomPage,
    ],
    'has_next' => $commerceShowroomHasNext,
    'category_options' => $commerceShowroomCategoryOptions,
    'tag_options' => $commerceShowroomTagOptions,
    'selected_items' => array_values($commerceShowroomSelectedItems),
]);

echo controller('sectionCommerceCatalog02', 0, [
    'header_level' => 2,
    'header_text' => str_replace(
        'sectionCommerceCatalog01',
        'sectionCommerceCatalog02',
        $commerceCopy['catalog_heading']
    ),
    'intro_text' => $commerceCopy['catalog_intro'],
    'items_data' => $commerceShowroomPaginatedProducts,
    'items' => count($commerceShowroomPaginatedProducts),
    'inquiry_path' => $commerceShowroomBasePath
        . '#showroom-commerce-inquiry',
    'catalog_path' => $commerceShowroomBasePath,
    'basket_add_path' => $commerceShowroomBasePath,
    'basket_remove_path' => $commerceShowroomBasePath,
    'return_to' => $commerceShowroomBasePath,
    'locale' => $commerceShowroomLanguage,
    'labels' => $commerceShowroomLabels,
    'query' => [
        'search' => $commerceShowroomQuery,
        'category' => $commerceShowroomCategory,
        'tag' => $commerceShowroomTag,
        'page' => $commerceShowroomPage,
    ],
    'has_next' => $commerceShowroomHasNext,
    'category_options' => $commerceShowroomCategoryOptions,
    'tag_options' => $commerceShowroomTagOptions,
    'selected_items' => array_values($commerceShowroomSelectedItems),
]);

$commerceShowroomEscape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
?>
<section id="showroom-commerce-item" aria-labelledby="showroom-commerce-item-heading">
    <h2 id="showroom-commerce-item-heading"><?= $commerceShowroomEscape($commerceCopy['item_heading']) ?></h2>
    <?php
    echo controller('artCommerceItem01', 0, [
        'header_level' => 3,
        'item_data' => $commerceShowroomSelectedProduct,
        'inquiry_path' => $commerceShowroomBasePath
            . '#showroom-commerce-inquiry',
        'catalog_path' => $commerceShowroomBasePath,
        'basket_add_path' => $commerceShowroomBasePath,
        'basket_remove_path' => $commerceShowroomBasePath,
        'return_to' => $commerceShowroomBasePath,
        'locale' => $commerceShowroomLanguage,
        'labels' => $commerceShowroomLabels,
        'selected_items' => array_values($commerceShowroomSelectedItems),
        'inquiry_count' => $commerceShowroomSelectedProduct['inquiry_count'],
    ]);
    ?>
</section>
<div id="showroom-commerce-inquiry">
    <?php
    echo controller('sectionCommerceInquiry01', 0, [
        'header_level' => 2,
        'header_text' => $commerceCopy['inquiry_heading'],
        'intro_text' => $commerceCopy['inquiry_intro'],
        'items_data' => $commerceShowroomInquiryItems,
        'items' => count($commerceShowroomInquiryItems),
        'action' => $commerceShowroomBasePath,
        'basket_remove_path' => $commerceShowroomBasePath,
        'return_to' => $commerceShowroomBasePath
            . '#showroom-commerce-inquiry',
        'locale' => $commerceShowroomLanguage,
        'labels' => $commerceShowroomInquiryLabels,
    ]);
    ?>
</div>
<?php
unset(
    $commerceShowroomLanguage,
    $commerceShowroomBasePath,
    $commerceShowroomCopy,
    $commerceShowroomCategoryLabels,
    $commerceShowroomTagLabels,
    $commerceShowroomTitles,
    $commerceShowroomDefinitions,
    $commerceShowroomTerms,
    $commerceShowroomImages,
    $commerceCopy,
    $commerceCategories,
    $commerceTags,
    $commerceTitles,
    $commerceTerms,
    $commerceShowroomProducts,
    $commerceShowroomRawQuery,
    $commerceShowroomQuery,
    $commerceShowroomCategory,
    $commerceShowroomTag,
    $commerceShowroomFold,
    $commerceShowroomNeedle,
    $commerceShowroomFilteredProducts,
    $commerceShowroomRawPage,
    $commerceShowroomPage,
    $commerceShowroomPageSize,
    $commerceShowroomPageCount,
    $commerceShowroomPaginatedProducts,
    $commerceShowroomHasNext,
    $commerceShowroomProductsById,
    $commerceShowroomSelectedId,
    $commerceShowroomSelectedProduct,
    $commerceShowroomSelectedItems,
    $commerceShowroomRawItems,
    $commerceShowroomInquiryItems,
    $commerceShowroomCategoryOptions,
    $commerceShowroomTagOptions,
    $commerceShowroomLabels,
    $commerceShowroomInquiryLabels,
    $commerceShowroomEscape,
    $position,
    $definition,
    $id,
    $reference,
    $category,
    $tags,
    $imageNumber,
    $material,
    $fit,
    $color,
    $availability,
    $acceptsInquiries,
    $title,
    $image,
    $variants,
    $product,
    $value,
    $label
);
