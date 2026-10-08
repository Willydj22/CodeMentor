<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

// Rate limiting: máximo RATE_LIMIT_MAX_REQUESTS por IP cada RATE_LIMIT_WINDOW_SEG
exigirRateLimit('buscar_imagenes');

$data = json_decode(file_get_contents("php://input"), true);

$termino = isset($data['termino']) ? trim($data['termino']) : '';

if ($termino === '') {
    echo json_encode(['status' => 'error', 'message' => 'Falta el término de búsqueda.']);
    exit;
}

// Límite de longitud del término
if (mb_strlen($termino, 'UTF-8') > 100) {
    echo json_encode(['status' => 'error', 'message' => 'El término de búsqueda es demasiado largo.']);
    exit;
}

// ── Filtro de contenido: bloquear búsquedas sexuales/NSFW ────────
// Pixabay ya tiene safesearch=true más abajo, pero esto evita gastar
// la consulta a la API directamente y da un mensaje más claro.
require_once __DIR__ . '/filtro_contenido.php';
if (contienePedidoNSFW($termino)) {
    echo json_encode(['status' => 'error', 'message' => 'No puedo buscar ese tipo de imagen.']);
    exit;
}

if (PIXABAY_API_KEY === '' || PIXABAY_API_KEY === 'TU_API_KEY_AQUI') {
    echo json_encode(['status' => 'error', 'message' => 'Falta configurar la API key de Pixabay.']);
    exit;
}

$url = 'https://pixabay.com/api/?' . http_build_query([
    'key'        => PIXABAY_API_KEY,
    'q'          => $termino,
    'lang'       => 'es',
    'per_page'   => 12,
    'safesearch' => 'true',
]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL,            $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT,        15);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión con Pixabay: ' . $curlError]);
    exit;
}

$decoded = json_decode($response, true);

if ($httpCode !== 200 || !$decoded) {
    echo json_encode(['status' => 'error', 'message' => 'No se pudo consultar Pixabay (código ' . $httpCode . ').']);
    exit;
}

$hits = $decoded['hits'] ?? [];

// ── Guardar en el caché compartido con generar_imagen.php ──────────────
// Así, si más adelante piden "generá una imagen de X" con una descripción
// parecida, CodeMentor reutiliza esta foto real en vez de generar una con IA
// (más rápido y sin gastar créditos). Se guarda solo la más relevante
// (la primera que devuelve Pixabay) y solo si no había ninguna imagen
// guardada ya para este término; si la descarga falla, no afecta a la
// búsqueda que se le muestra al usuario.
require_once __DIR__ . '/imagen_cache.php';
$primeraURL = $hits[0]['largeImageURL'] ?? ($hits[0]['webformatURL'] ?? '');
if ($primeraURL !== '' && !hayImagenEnCache($termino)) {
    $chImg = curl_init();
    curl_setopt($chImg, CURLOPT_URL,            $primeraURL);
    curl_setopt($chImg, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chImg, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($chImg, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($chImg, CURLOPT_TIMEOUT,        8);

    $imgBin  = curl_exec($chImg);
    $imgCode = curl_getinfo($chImg, CURLINFO_HTTP_CODE);
    $imgMime = curl_getinfo($chImg, CURLINFO_CONTENT_TYPE) ?: 'image/jpeg';
    curl_close($chImg);

    if ($imgCode === 200 && $imgBin && stripos($imgMime, 'image/') === 0) {
        guardarImagenEnCache($termino, $imgBin, $imgMime, 'pixabay (búsqueda)');
    }
}

// Solo devolver los campos que realmente se usan en el front
$imagenes = array_map(function ($img) {
    return [
        'previewURL'   => $img['previewURL']   ?? '',
        'largeImageURL'=> $img['largeImageURL']?? '',
        'usuario'      => $img['user']         ?? '',
        'vistas'       => $img['views']        ?? 0,
    ];
}, $hits);

$respuesta = json_encode([
    'status'   => 'ok',
    'termino'  => $termino,
    'imagenes' => $imagenes,
]);

// Responder primero al usuario y guardar las imágenes después, para que la
// descarga no demore la búsqueda.
ignore_user_abort(true);
@set_time_limit(90);
if (function_exists('fastcgi_finish_request')) {
    echo $respuesta;
    fastcgi_finish_request();
} else {
    header('Content-Length: ' . strlen($respuesta));
    header('Connection: close');
    echo $respuesta;
    while (ob_get_level() > 0) { @ob_end_flush(); }
    @flush();
}

// ── Guardar todas las imágenes de la búsqueda en imagenes_buscadas/<término>/ ──
require_once __DIR__ . '/imagenes_guardadas.php';
guardarImagenesBusqueda($termino, $hits, 12);
