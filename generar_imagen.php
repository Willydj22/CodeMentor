<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

// Rate limiting: máximo RATE_LIMIT_MAX_REQUESTS por IP cada RATE_LIMIT_WINDOW_SEG
exigirRateLimit('generar_imagen');

// La generación puede tardar bastante más que una búsqueda normal.
@ini_set('max_execution_time', '45');
@set_time_limit(45);

// Límite de tamaño del body para evitar abuso
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 20000) {
    echo json_encode(['status' => 'error', 'message' => 'Solicitud demasiado grande.']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$termino = isset($data['termino']) ? trim($data['termino']) : '';

if ($termino === '') {
    echo json_encode(['status' => 'error', 'message' => 'Falta describir la imagen a generar.']);
    exit;
}

// Límite de longitud del prompt
if (mb_strlen($termino, 'UTF-8') > 300) {
    echo json_encode(['status' => 'error', 'message' => 'La descripción es demasiado larga (máx. 300 caracteres).']);
    exit;
}

// ── Filtro de contenido: bloquear pedidos sexuales/NSFW ──────────
// Pollinations no filtra nada por su cuenta, así que esto corta el
// pedido antes de gastar tiempo/créditos generando la imagen.
require_once __DIR__ . '/filtro_contenido.php';
if (contienePedidoNSFW($termino)) {
    echo json_encode(['status' => 'error', 'message' => 'No puedo generar ese tipo de imagen.']);
    exit;
}

// ── Caché compartido: si ya hay una imagen para esta misma descripción
// (generada antes con IA, o encontrada con "buscá imágenes de..."), la
// devolvemos desde disco y no gastamos créditos de ninguna API. "Misma
// descripción" = mismo texto salvo mayúsculas/tildes/espacios de más.
// La lógica del caché vive en imagen_cache.php, compartida con
// buscar_imagenes.php.
require_once __DIR__ . '/imagen_cache.php';
[$rutaBin, $rutaMeta] = rutasCacheImagen($termino);

if (is_file($rutaBin) && is_file($rutaMeta)) {
    $meta = json_decode(file_get_contents($rutaMeta), true);
    $bin  = file_get_contents($rutaBin);
    if ($meta && $bin !== false) {
        echo json_encode([
            'status'  => 'ok',
            'termino' => $termino,
            'motor'   => ($meta['motor'] ?? 'desconocido') . ' (caché)',
            'imagen'  => 'data:' . ($meta['mime'] ?? 'image/png') . ';base64,' . base64_encode($bin),
            'cache'   => true,
        ]);
        exit;
    }
}

// ── Intento 1 (opcional): Gemini / "Nano Banana" ──────────────
// Tu GEMINI_API_KEY hoy solo tiene acceso a gemini-2.5-flash (texto),
// no al modelo de imágenes (gemini-2.5-flash-image), así que este
// intento está APAGADO por defecto para no perder tiempo llamando a
// algo que va a fallar seguro. El día que tengas acceso al modelo de
// imágenes, definí en config.php:
//   define('GEMINI_IMAGEN_DISPONIBLE', true);
// (opcionalmente también GEMINI_IMAGEN_MODELO si el nombre cambia)
// y este intento se activa solo, con Pollinations como respaldo igual.
$usarGemini = defined('GEMINI_IMAGEN_DISPONIBLE') && GEMINI_IMAGEN_DISPONIBLE === true;

function generarConGemini($termino) {
    $apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    if ($apiKey === '' || $apiKey === 'TU_API_KEY_AQUI') {
        return [false, null, null, 'Gemini no está configurado.'];
    }

    $modelo = defined('GEMINI_IMAGEN_MODELO') ? GEMINI_IMAGEN_MODELO : 'gemini-2.5-flash-image';
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$modelo}:generateContent";

    $body = json_encode([
        'contents' => [[
            'role'  => 'user',
            'parts' => [['text' => $termino]],
        ]],
        'generationConfig' => [
            'responseModalities' => ['TEXT', 'IMAGE'],
        ],
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey]);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $body);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return [false, null, null, 'Error de conexión con Gemini: ' . $curlError];
    }

    $decoded = json_decode($response, true);

    if ($httpCode !== 200 || !$decoded) {
        $msg = 'Error Gemini (código ' . $httpCode . ').';
        if (isset($decoded['error']['message'])) $msg = $decoded['error']['message'];
        return [false, null, null, $msg];
    }

    $partes = $decoded['candidates'][0]['content']['parts'] ?? [];
    foreach ($partes as $parte) {
        if (isset($parte['inlineData']['data'])) {
            $mime = $parte['inlineData']['mimeType'] ?? 'image/png';
            return [true, $parte['inlineData']['data'], $mime, null];
        }
    }

    $razon = $decoded['candidates'][0]['finishReason'] ?? 'desconocida';
    return [false, null, null, 'Gemini no devolvió ninguna imagen (razón: ' . $razon . ').'];
}

// ── Intento 2 (respaldo): Pollinations.ai — gratis, sin API key ──
function generarConPollinations($termino) {
    $seed = random_int(1, 999999);
    $url  = 'https://image.pollinations.ai/prompt/' . rawurlencode($termino)
          . '?width=1024&height=1024&seed=' . $seed . '&nologo=true';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        35);

    $imgData   = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $mime      = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return [false, null, null, 'Error de conexión con Pollinations: ' . $curlError];
    }
    if ($httpCode !== 200 || !$imgData) {
        return [false, null, null, 'Pollinations no devolvió una imagen (código ' . $httpCode . ').'];
    }

    $mime = $mime ?: 'image/jpeg';
    // Pollinations a veces responde 200 con un JSON de error en vez de bytes de imagen.
    if (stripos($mime, 'image/') !== 0) {
        return [false, null, null, 'Pollinations no devolvió una imagen válida.'];
    }

    // Sin key de Pollinations, `nologo=true` no se respeta y siempre estampa
    // su marca de agua en la esquina inferior derecha. Como no la podemos
    // sacar de raíz sin una key, la disimulamos difuminando esa esquina.
    [$imgData, $mime] = taparMarcaDeAgua($imgData, $mime);

    return [true, base64_encode($imgData), $mime, null];
}

// Difumina la esquina inferior derecha (donde Pollinations pone su logo)
// recortándola, aplicándole desenfoque gaussiano varias veces, y pegándola
// de nuevo en la imagen. Si GD no está disponible en el hosting, devuelve
// la imagen sin tocar (mejor mostrarla con marca de agua que no mostrar nada).
function taparMarcaDeAgua($imgData, $mimeOriginal) {
    if (!function_exists('imagecreatefromstring')) {
        return [$imgData, $mimeOriginal];
    }

    $im = @imagecreatefromstring($imgData);
    if (!$im) {
        return [$imgData, $mimeOriginal];
    }

    $ancho = imagesx($im);
    $alto  = imagesy($im);

    // Zona aproximada del logo/texto "pollinations.ai": esquina inferior
    // derecha, ~34% del ancho y ~10% del alto de la imagen.
    $zonaAncho = (int) round($ancho * 0.34);
    $zonaAlto  = (int) round($alto  * 0.10);
    $x0 = max(0, $ancho - $zonaAncho);
    $y0 = max(0, $alto  - $zonaAlto);

    $recorte = imagecreatetruecolor($zonaAncho, $zonaAlto);
    imagecopy($recorte, $im, 0, 0, $x0, $y0, $zonaAncho, $zonaAlto);
    for ($i = 0; $i < 15; $i++) {
        imagefilter($recorte, IMG_FILTER_GAUSSIAN_BLUR);
    }
    imagecopy($im, $recorte, $x0, $y0, 0, 0, $zonaAncho, $zonaAlto);
    imagedestroy($recorte);

    ob_start();
    imagepng($im);
    $salida = ob_get_clean();
    imagedestroy($im);

    return [$salida, 'image/png'];
}

// ── Elegir motor: Gemini primero (si está habilitado), Pollinations como respaldo/único ──
$errorGemini = 'Desactivado (tu key no tiene acceso al modelo de imágenes de Gemini).';
if ($usarGemini) {
    $motor = 'gemini';
    [$ok, $base64, $mime, $errorGemini] = generarConGemini($termino);
} else {
    $ok = false;
}

if (!$ok) {
    $motor = 'pollinations';
    [$ok, $base64, $mime, $errorPollinations] = generarConPollinations($termino);
}

if (!$ok) {
    $detalle = $usarGemini ? ('Gemini: ' . $errorGemini . ' | ') : '';
    $detalle .= 'Pollinations: ' . $errorPollinations;
    echo json_encode(['status' => 'error', 'message' => 'No se pudo generar la imagen. ' . $detalle]);
    exit;
}

// Guardamos en caché (si el hosting lo permite) para no gastar créditos
// de nuevo si piden exactamente esta misma descripción más adelante.
guardarImagenEnCache($termino, base64_decode($base64), $mime, $motor);

echo json_encode([
    'status'  => 'ok',
    'termino' => $termino,
    'motor'   => $motor,
    'imagen'  => 'data:' . $mime . ';base64,' . $base64,
]);
