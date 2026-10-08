<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

// Rate limiting: máximo RATE_LIMIT_MAX_REQUESTS por IP cada RATE_LIMIT_WINDOW_SEG
exigirRateLimit('context_images');

if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 3000) {
    echo json_encode(['status' => 'error', 'message' => 'Solicitud demasiado grande.']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || !isset($data['url']) || trim($data['url']) === '') {
    echo json_encode(['status' => 'error', 'message' => 'Falta la URL a analizar.']);
    exit;
}

$url = trim($data['url']);

if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $url)) {
    echo json_encode(['status' => 'error', 'message' => 'URL inválida.']);
    exit;
}

// Evitar que el servidor sea usado como proxy hacia direcciones internas
if (!urlEsSegura($url)) {
    echo json_encode(['status' => 'error', 'message' => 'No se permite esa URL.']);
    exit;
}

if (!defined('CONTEXT_DEV_API_KEY') || CONTEXT_DEV_API_KEY === 'TU_API_KEY_AQUI' || CONTEXT_DEV_API_KEY === '') {
    echo json_encode(['status' => 'error', 'message' => 'Falta configurar la API key de Context.dev.']);
    exit;
}

$endpoint = 'https://api.context.dev/v1/web/scrape/images?url=' . urlencode($url);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL,            $endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER,     [
    'Authorization: Bearer ' . CONTEXT_DEV_API_KEY,
]);
curl_setopt($ch, CURLOPT_TIMEOUT,        25);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión: ' . $curlError]);
    exit;
}

$decoded = json_decode($response, true);

if ($httpCode !== 200 || !$decoded || empty($decoded['success'])) {
    $msg = 'Error Context.dev (código ' . $httpCode . ').';
    if (isset($decoded['error']['message'])) $msg = $decoded['error']['message'];
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

// Solo nos interesan las imágenes con URL real (descartamos svg inline y base64,
// que no sirven como <img src> directo para mostrar en la grilla del chat)
$imagenes = [];
foreach (($decoded['images'] ?? []) as $img) {
    if (($img['type'] ?? '') === 'url' && !empty($img['src'])) {
        $imagenes[] = [
            'src' => $img['src'],
            'alt' => $img['alt'] ?? '',
        ];
    }
}
$imagenes = array_slice($imagenes, 0, 40);

if (empty($imagenes)) {
    echo json_encode(['status' => 'error', 'message' => 'No se encontraron imágenes en esa URL.']);
    exit;
}

$guardar = !empty($data['guardar']);

if (!$guardar) {
    echo json_encode([
        'status'   => 'ok',
        'url'      => $url,
        'imagenes' => $imagenes,
    ]);
    exit;
}

// ── Guardado en conocimiento_data (lista de imágenes en markdown) ──
$listaMd = "";
foreach ($imagenes as $img) {
    $listaMd .= "- " . $img['src'] . ($img['alt'] ? " (alt: " . $img['alt'] . ")" : "") . "\n";
}

$entrada = [
    'pregunta'         => 'Imágenes de ' . $url,
    'concepto'         => 'Imágenes de ' . $url,
    'descripcion'      => $listaMd,
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
    'status'       => $decodedGuardar['status'] ?? 'error',
    'url'          => $url,
    'imagenes'     => $imagenes,
    'guardado_msg' => $decodedGuardar['msg'] ?? null,
]);
