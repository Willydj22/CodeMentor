<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

// Rate limiting: máximo RATE_LIMIT_MAX_REQUESTS por IP cada RATE_LIMIT_WINDOW_SEG
exigirRateLimit('google_search');

// Límite de tamaño del body
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 3000) {
    echo json_encode(['status' => 'error', 'message' => 'Solicitud demasiado grande.']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || !isset($data['q']) || trim($data['q']) === '') {
    echo json_encode(['status' => 'error', 'message' => 'Falta el término de búsqueda.']);
    exit;
}

$q = trim($data['q']);
if (mb_strlen($q, 'UTF-8') > 300) {
    echo json_encode(['status' => 'error', 'message' => 'La búsqueda es demasiado larga.']);
    exit;
}

if (!defined('SERPAPI_KEY') || SERPAPI_KEY === 'TU_API_KEY_AQUI' || SERPAPI_KEY === '') {
    echo json_encode(['status' => 'error', 'message' => 'Falta configurar la API key de SerpApi.']);
    exit;
}

$params = [
    'engine'         => 'google',
    'q'              => $q,
    'google_domain'  => 'google.com',
    'gl'             => 'ar',   // país (ar = Argentina, ajustá si querés otro)
    'hl'             => 'es',   // idioma de resultados
    'api_key'        => SERPAPI_KEY,
];

$endpoint = 'https://serpapi.com/search.json?' . http_build_query($params);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL,            $endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT,        20);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión: ' . $curlError]);
    exit;
}

$decoded = json_decode($response, true);

if ($httpCode !== 200 || !$decoded) {
    $msg = 'Error SerpApi (código ' . $httpCode . ').';
    if (isset($decoded['error'])) $msg = $decoded['error'];
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

// ── Simplificar resultados: solo lo útil para el chat/contexto ──
$resultados = [];

if (!empty($decoded['organic_results']) && is_array($decoded['organic_results'])) {
    foreach (array_slice($decoded['organic_results'], 0, 5) as $r) {
        $resultados[] = [
            'titulo'   => $r['title']   ?? '',
            'enlace'   => $r['link']    ?? '',
            'snippet'  => $r['snippet'] ?? '',
        ];
    }
}

// Respuesta directa de Google (caja de "answer box"), si existe
$respuestaDirecta = $decoded['answer_box']['answer']
    ?? $decoded['answer_box']['snippet']
    ?? null;

if (empty($resultados) && !$respuestaDirecta) {
    echo json_encode(['status' => 'error', 'message' => 'No se encontraron resultados.']);
    exit;
}

echo json_encode([
    'status'           => 'ok',
    'q'                => $q,
    'respuesta_directa'=> $respuestaDirecta,
    'resultados'       => $resultados,
]);
