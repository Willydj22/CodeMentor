<?php
// buscar_video.php — busca videos de YouTube (YouTube Data API v3) para que
// CodeMentor los reproduzca dentro del chat. Requiere la constante YOUTUBE_API_KEY
// en config.php:  define('YOUTUBE_API_KEY', 'tu_clave');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

// Rate limiting: máximo RATE_LIMIT_MAX_REQUESTS por IP cada RATE_LIMIT_WINDOW_SEG
exigirRateLimit('buscar_video');

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

$apiKey = defined('YOUTUBE_API_KEY') ? YOUTUBE_API_KEY : '';
if ($apiKey === '' || $apiKey === 'TU_API_KEY_AQUI') {
    echo json_encode(['status' => 'error', 'message' => 'Falta configurar la API key de YouTube (YOUTUBE_API_KEY en config.php).']);
    exit;
}

$url = 'https://www.googleapis.com/youtube/v3/search?' . http_build_query([
    'part'              => 'snippet',
    'type'              => 'video',
    'q'                 => $termino,
    'maxResults'        => 5,
    'videoEmbeddable'   => 'true',      // solo videos que se pueden reproducir incrustados
    'safeSearch'        => 'moderate',
    'relevanceLanguage' => 'es',
    'key'               => $apiKey,
]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL,            $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT,        15);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
// (sin curl_close: no hace nada desde PHP 8.0 y está deprecado en 8.5)

if ($curlError) {
    echo json_encode(['status' => 'error', 'message' => 'No se pudo conectar con YouTube (¿hay internet?): ' . $curlError]);
    exit;
}

$decoded = json_decode($response, true);

if ($httpCode !== 200 || !$decoded) {
    $motivo = $decoded['error']['errors'][0]['reason'] ?? '';
    if ($motivo === 'quotaExceeded' || $motivo === 'dailyLimitExceeded') {
        $msg = 'Se agotó la cuota diaria de búsquedas de YouTube. Probá de nuevo mañana o pegá directamente el enlace del video.';
    } elseif ($motivo === 'keyInvalid' || $motivo === 'ipRefererBlocked' || $motivo === 'forbidden') {
        $msg = 'La API key de YouTube no es válida o tiene restricciones que bloquean este servidor.';
    } else {
        $msg = 'No se pudo consultar YouTube (código ' . $httpCode . ').';
    }
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

$videos = [];
foreach (($decoded['items'] ?? []) as $item) {
    $id = $item['id']['videoId'] ?? '';
    // Un ID de video de YouTube tiene siempre 11 caracteres [A-Za-z0-9_-]
    if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) continue;

    $sn = $item['snippet'] ?? [];
    $videos[] = [
        'id'        => $id,
        // La API devuelve los títulos con entidades HTML (&#39;, &amp;...)
        'titulo'    => html_entity_decode($sn['title'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'canal'     => html_entity_decode($sn['channelTitle'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'miniatura' => $sn['thumbnails']['medium']['url'] ?? ('https://i.ytimg.com/vi/' . $id . '/mqdefault.jpg'),
    ];
}

echo json_encode([
    'status'  => 'ok',
    'termino' => $termino,
    'videos'  => $videos,
]);
