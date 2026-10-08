<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

// Rate limiting: máximo RATE_LIMIT_MAX_REQUESTS por IP cada RATE_LIMIT_WINDOW_SEG
exigirRateLimit('supadata');

// Límite de tamaño del body para evitar abuso
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 5000) {
    echo json_encode(['status' => 'error', 'message' => 'Solicitud demasiado grande.']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || !isset($data['url']) || trim($data['url']) === '') {
    echo json_encode(['status' => 'error', 'message' => 'Falta la URL a extraer.']);
    exit;
}

$url = trim($data['url']);

// Validación básica de URL
if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $url)) {
    echo json_encode(['status' => 'error', 'message' => 'URL inválida.']);
    exit;
}

// Evitar que el servidor sea usado como proxy hacia direcciones internas
if (!urlEsSegura($url)) {
    echo json_encode(['status' => 'error', 'message' => 'No se permite esa URL.']);
    exit;
}

if (!defined('SUPADATA_API_KEY') || SUPADATA_API_KEY === 'TU_API_KEY_AQUI' || SUPADATA_API_KEY === '') {
    echo json_encode(['status' => 'error', 'message' => 'Falta configurar la API key de Supadata.']);
    exit;
}

$endpoint = 'https://api.supadata.ai/v1/web/scrape?url=' . urlencode($url);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL,            $endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER,     [
    'x-api-key: ' . SUPADATA_API_KEY,
]);
curl_setopt($ch, CURLOPT_TIMEOUT,        30);

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
    $msg = 'Error Supadata (código ' . $httpCode . ').';
    if (isset($decoded['error']['message'])) $msg = $decoded['error']['message'];
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

// El endpoint /web/scrape devuelve el contenido en 'content' (markdown)
$contenido = $decoded['content'] ?? $decoded['markdown'] ?? null;

if (!$contenido) {
    echo json_encode(['status' => 'error', 'message' => 'No se pudo extraer contenido de esa URL.']);
    exit;
}

// Limitar tamaño del contenido devuelto
$contenido = mb_substr($contenido, 0, 8000, 'UTF-8');

// El título viene aparte en la respuesta de Supadata, si no hay, usamos la URL
$titulo = trim($decoded['title'] ?? '') ?: $url;

$guardar = !empty($data['guardar']); // true si el cliente pide guardarlo en conocimiento_data

if (!$guardar) {
    echo json_encode([
        'status'    => 'ok',
        'url'       => $url,
        'titulo'    => $titulo,
        'contenido' => $contenido,
    ]);
    exit;
}

// ── Guardado directo en conocimiento_data (sin pasar por Gemini) ──
$entrada = [
    'pregunta'         => $titulo,
    'concepto'         => $titulo,
    'descripcion'      => $contenido,
    'sintaxis'         => '',
    'ejemplo'          => '',
    'errores'          => '',
    'buenas_practicas' => '',
];

$chGuardar = curl_init();
curl_setopt($chGuardar, CURLOPT_URL, (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/guardar.php');
curl_setopt($chGuardar, CURLOPT_RETURNTRANSFER, true);
curl_setopt($chGuardar, CURLOPT_POST, true);
curl_setopt($chGuardar, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($chGuardar, CURLOPT_POSTFIELDS, json_encode($entrada));
curl_setopt($chGuardar, CURLOPT_TIMEOUT, 15);
$respGuardar = curl_exec($chGuardar);
curl_close($chGuardar);

$decodedGuardar = json_decode($respGuardar, true);

echo json_encode([
    'status'         => $decodedGuardar['status'] ?? 'error',
    'url'            => $url,
    'titulo'         => $titulo,
    'guardado_msg'   => $decodedGuardar['msg'] ?? null,
]);
