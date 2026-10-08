<?php
// ── subir_archivo.php — Subida de archivos de código (HTML/CSS/JS) ──
// Objetivo: que el usuario pueda adjuntar un archivo real (en vez de
// pegar el contenido a mano en el chat) para que CodeMentor lo analice.
//
// Por qué un endpoint aparte en vez de meterlo en gemini.php:
// 1. gemini.php limita el body a 50000 bytes (anti-abuso para
//    preguntas normales); un archivo de código necesita más margen.
// 2. Si el contenido se guarda acá y gemini.php lo lee del disco por
//    su cuenta (usando solo un "archivo_id" corto), el archivo NUNCA
//    queda pegado dentro de historialSesion en el cliente. Eso evita
//    el problema que tuvimos: un paste gigante quedaba en el
//    historial y se reenviaba entero en CADA mensaje siguiente,
//    inflando el payload hasta romper los límites de Groq.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

exigirRateLimit('subir_archivo');

// ── Límites y validación ──────────────────────────────────────
// 300 KB es un margen razonable para un HTML/CSS/JS de una sola
// pantalla o componente; suficiente para el caso de David sin abrir
// la puerta a subir cualquier cosa.
const ARCHIVO_TAMANO_MAX = 300000;

// Solo texto plano de código: nada de binarios. Ampliado más allá de
// HTML/CSS/JS para cubrir lo que CodeMentor también enseña (PHP, Python,
// Android/Kotlin, etc.) — siempre se guarda con extensión .txt en el
// servidor sin importar la original, así que aunque sea código PHP no
// hay riesgo de que el hosting lo ejecute por accidente.
const EXTENSIONES_PERMITIDAS = [
    'html', 'htm', 'css', 'js', 'jsx', 'ts', 'tsx', 'json', 'xml', 'txt', 'md',
    'php', 'py', 'java', 'kt', 'sql', 'sh', 'yml', 'yaml', 'c', 'cpp', 'h',
];

if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['status' => 'error', 'message' => 'No se recibió ningún archivo.']);
    exit;
}

$archivo = $_FILES['archivo'];

if ($archivo['size'] > ARCHIVO_TAMANO_MAX) {
    $kbMax = intdiv(ARCHIVO_TAMANO_MAX, 1000);
    echo json_encode(['status' => 'error', 'message' => "El archivo supera el máximo permitido ({$kbMax} KB)."]);
    exit;
}

$nombreOriginal = $archivo['name'];
$extension = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));

if (!in_array($extension, EXTENSIONES_PERMITIDAS, true)) {
    echo json_encode(['status' => 'error', 'message' => 'Tipo de archivo no permitido. Subí HTML, CSS, JS, PHP, Python, Java, Kotlin, SQL u otro archivo de texto/código.']);
    exit;
}

$contenido = file_get_contents($archivo['tmp_name']);
if ($contenido === false) {
    echo json_encode(['status' => 'error', 'message' => 'No se pudo leer el archivo subido.']);
    exit;
}

// Rechazar binarios disfrazados: si tiene bytes nulos, no es texto.
if (strpos($contenido, "\0") !== false) {
    echo json_encode(['status' => 'error', 'message' => 'El archivo no parece ser texto plano.']);
    exit;
}

// Si no viene en UTF-8 válido, lo convertimos (evita romper el JSON
// más adelante en gemini.php al mandarlo a la API).
if (!mb_check_encoding($contenido, 'UTF-8')) {
    $convertido = @mb_convert_encoding($contenido, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
    if ($convertido !== false) $contenido = $convertido;
}

// ── Guardado ───────────────────────────────────────────────────
$carpetaArchivos = __DIR__ . '/archivos_data';
if (!is_dir($carpetaArchivos)) {
    @mkdir($carpetaArchivos, 0755, true);
    if (!is_dir($carpetaArchivos)) {
        error_log('[codementor] No se pudo crear archivos_data en ' . $carpetaArchivos);
        echo json_encode(['status' => 'error', 'message' => 'No se pudo guardar el archivo en el servidor.']);
        exit;
    }
}

// Limpieza simple de archivos viejos (>24h) en cada subida, para no
// acumular basura indefinidamente en hostings sin cron.
foreach (glob($carpetaArchivos . '/*.txt') ?: [] as $viejo) {
    if (filemtime($viejo) < time() - 86400) @unlink($viejo);
}

$archivoId = bin2hex(random_bytes(16));
$rutaDestino = $carpetaArchivos . '/' . $archivoId . '.txt';

$ok = file_put_contents($rutaDestino, $contenido, LOCK_EX);
if ($ok === false) {
    error_log('[codementor] Falló file_put_contents al guardar archivo subido en ' . $rutaDestino);
    echo json_encode(['status' => 'error', 'message' => 'No se pudo guardar el archivo en el servidor.']);
    exit;
}

// Guardamos el nombre original aparte (no confiamos en el nombre de
// archivo del cliente para nada que toque el filesystem).
file_put_contents($carpetaArchivos . '/' . $archivoId . '.nombre.txt', $nombreOriginal, LOCK_EX);

echo json_encode([
    'status'     => 'ok',
    'archivo_id' => $archivoId,
    'nombre'     => $nombreOriginal,
    'tamano'     => strlen($contenido),
]);
