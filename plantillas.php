<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

// Rate limiting: máximo RATE_LIMIT_MAX_REQUESTS por IP cada RATE_LIMIT_WINDOW_SEG
exigirRateLimit('plantillas');

// Catálogo estático: los .zip viven en /plantillas/ (subilos tal cual,
// sin descomprimir, con estos mismos nombres de archivo).
$plantillas = [
    [
        'id' => 'forty',
        'nombre' => 'Forty',
        'categoria' => 'Portafolio / Landing',
        'descripcion' => 'Landing de una página con menú lateral desplegable, banner grande, tiles de servicios y formulario de contacto. Ideal para portafolios personales o páginas de presentación de un negocio.',
        'archivo' => 'html5up-forty.zip',
    ],
    [
        'id' => 'editorial',
        'nombre' => 'Editorial',
        'categoria' => 'Blog / Revista',
        'descripcion' => 'Estilo revista/blog con menú lateral fijo, listado de artículos y galería de imágenes. Ideal para blogs personales, revistas digitales o portfolios de escritura.',
        'archivo' => 'html5up-editorial.zip',
    ],
    [
        'id' => 'paradigm-shift',
        'nombre' => 'Paradigm Shift',
        'categoria' => 'Negocios / Agencia',
        'descripcion' => 'Landing minimalista de una página con imagen de fondo a pantalla completa y paneles de contenido. Ideal para agencias, estudios o presentación de servicios.',
        'archivo' => 'html5up-paradigm-shift.zip',
    ],
    [
        'id' => 'dimension',
        'nombre' => 'Dimension',
        'categoria' => 'Portafolio / Fotografía',
        'descripcion' => 'Panel de introducción a pantalla completa con menú lateral deslizante y mucho contenido visual. Ideal para portfolios fotográficos o creativos.',
        'archivo' => 'html5up-dimension.zip',
    ],
    [
        'id' => 'story',
        'nombre' => 'Story',
        'categoria' => 'Narrativa / Scrollytelling',
        'descripcion' => 'Diseño de scroll narrativo, tipo línea de tiempo, con secciones que se revelan a medida que se avanza. Ideal para contar una historia, un proceso o la trayectoria de un proyecto.',
        'archivo' => 'html5up-story.zip',
    ],
    [
        'id' => 'ethereal',
        'nombre' => 'Ethereal',
        'categoria' => 'Fotografía / Minimalista',
        'descripcion' => 'Enfoque fotográfico a pantalla completa con transiciones suaves al hacer scroll. Ideal para portfolios visuales donde la imagen es la protagonista.',
        'archivo' => 'html5up-ethereal.zip',
    ],
    [
        'id' => 'hyperspace',
        'nombre' => 'Hyperspace',
        'categoria' => 'Tecnología / Startup',
        'descripcion' => 'Tema oscuro con banner a pantalla completa y grilla de features. Ideal para landings de productos tecnológicos, apps o startups.',
        'archivo' => 'html5up-hyperspace.zip',
    ],
    [
        'id' => 'solid-state',
        'nombre' => 'Solid State',
        'categoria' => 'Negocios / SaaS',
        'descripcion' => 'Tema oscuro con bordes redondeados, tabla de precios y secciones de features. Ideal para presentar un producto, servicio o plan de suscripción.',
        'archivo' => 'html5up-solid-state.zip',
    ],
    [
        'id' => 'massively',
        'nombre' => 'Massively',
        'categoria' => 'Blog / Agencia',
        'descripcion' => 'Híbrido entre landing y blog: hero grande, grilla de posts/artículos y showcase de elementos de UI. Ideal para agencias que también publican contenido.',
        'archivo' => 'html5up-massively.zip',
    ],
    [
        'id' => 'teleposadastv',
        'nombre' => 'Teleposadas TV',
        'categoria' => 'Sitio de noticias con panel de administración',
        'descripcion' => 'A diferencia de las anteriores, no es una plantilla estática: es un sitio completo en PHP para un canal/portal de noticias, con panel de administración (noticias, usuarios, horarios de programación, actividad). Ideal como base para un sitio de noticias o TV local con backend propio.',
        'archivo' => 'teleposadastv.zip',
    ],
];

echo json_encode(['status' => 'ok', 'plantillas' => $plantillas]);
