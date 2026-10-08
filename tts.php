<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

// Rate limiting: máximo RATE_LIMIT_MAX_REQUESTS por IP cada RATE_LIMIT_WINDOW_SEG
exigirRateLimit('tts');

$data = json_decode(file_get_contents("php://input"), true);

$texto = isset($data['texto']) ? trim($data['texto']) : '';

if ($texto === '') {
    echo json_encode(['status' => 'error', 'message' => 'Falta el texto a convertir en audio.']);
    exit;
}

// Límite de longitud: generoso pero evita abuso y timeouts del hosting.
if (mb_strlen($texto, 'UTF-8') > 600) {
    echo json_encode(['status' => 'error', 'message' => 'El texto es demasiado largo (máximo 600 caracteres).']);
    exit;
}

// ── Intento 1: VoiceRSS (gratis, requiere API key sin tarjeta) ──────
// Alta en https://www.voicerss.org/registration.aspx -> 350 solicitudes/día gratis.
function llamarVoiceRSS($texto) {
    if (!defined('VOICERSS_API_KEY') || VOICERSS_API_KEY === '' || VOICERSS_API_KEY === 'TU_API_KEY_AQUI') {
        return [false, null, 'VoiceRSS no está configurado.'];
    }

    $url = 'https://api.voicerss.org/?' . http_build_query([
        'key' => VOICERSS_API_KEY,
        'hl'  => 'es-es',
        'src' => $texto,
        'f'   => '44khz_16bit_mono',
        'c'   => 'MP3',
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        20);
    $audio     = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return [false, null, 'Error de conexión con VoiceRSS: ' . $curlError];
    }
    // VoiceRSS devuelve el audio crudo si sale bien; si falla, devuelve
    // texto plano tipo "ERROR: The API key is not valid...".
    if ($httpCode !== 200 || !$audio || stripos(substr($audio, 0, 20), 'ERROR') === 0) {
        $msg = 'Error VoiceRSS';
        if ($audio && stripos(substr($audio, 0, 20), 'ERROR') === 0) $msg = trim($audio);
        return [false, null, $msg];
    }

    return [true, $audio, null];
}

// ── Intento 2 (respaldo): endpoint no-oficial de Google Translate ───
// No requiere API key. Cada request individual admite ~200 caracteres,
// así que para textos más largos se parte en fragmentos (por oración,
// sin cortar palabras) y se concatenan los MP3 resultantes.
function partirEnFragmentos($texto, $maxLen = 180) {
    // Primero por oraciones (. ! ? seguido de espacio), después por
    // palabras si una "oración" sigue siendo más larga que $maxLen.
    $oraciones = preg_split('/(?<=[\.\!\?])\s+/u', $texto, -1, PREG_SPLIT_NO_EMPTY);
    $fragmentos = [];
    $actual = '';

    foreach ($oraciones as $oracion) {
        if (mb_strlen($oracion, 'UTF-8') > $maxLen) {
            // Oración demasiado larga: la partimos por palabras.
            $palabras = explode(' ', $oracion);
            foreach ($palabras as $palabra) {
                $candidato = trim($actual . ' ' . $palabra);
                if (mb_strlen($candidato, 'UTF-8') > $maxLen && $actual !== '') {
                    $fragmentos[] = $actual;
                    $actual = $palabra;
                } else {
                    $actual = $candidato;
                }
            }
            continue;
        }
        $candidato = trim($actual . ' ' . $oracion);
        if (mb_strlen($candidato, 'UTF-8') > $maxLen && $actual !== '') {
            $fragmentos[] = $actual;
            $actual = $oracion;
        } else {
            $actual = $candidato;
        }
    }
    if ($actual !== '') $fragmentos[] = $actual;

    return $fragmentos;
}

function llamarGoogleTranslateTTS($texto) {
    $fragmentos = partirEnFragmentos($texto);
    // Tope de fragmentos para no colgar el hosting compartido con
    // decenas de requests encadenados (600 caracteres ≈ 4 fragmentos).
    if (count($fragmentos) > 8) {
        return [false, null, 'El texto es demasiado largo para el respaldo.'];
    }

    $audioTotal = '';
    foreach ($fragmentos as $fragmento) {
        $url = 'https://translate.google.com/translate_tts?' . http_build_query([
            'ie'     => 'UTF-8',
            'q'      => $fragmento,
            'tl'     => 'es',
            'client' => 'tw-ob',
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,            $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        20);
        curl_setopt($ch, CURLOPT_HTTPHEADER,     ['User-Agent: Mozilla/5.0']);
        $audio     = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return [false, null, 'Error de conexión con el respaldo: ' . $curlError];
        }
        if ($httpCode !== 200 || !$audio) {
            return [false, null, 'El respaldo de audio no está disponible (código ' . $httpCode . ').'];
        }
        $audioTotal .= $audio;
    }

    return [true, $audioTotal, null];
}

$ok = false;
$audio = null;
$errorPrimario = null;
$motor = 'voicerss';

[$ok, $audio, $errorPrimario] = llamarVoiceRSS($texto);

if (!$ok) {
    $motor = 'google_translate';
    [$ok, $audio, $errorRespaldo] = llamarGoogleTranslateTTS($texto);

    if (!$ok) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'No se pudo generar el audio. ' . $errorPrimario . ' | Respaldo: ' . $errorRespaldo,
        ]);
        exit;
    }
}

echo json_encode([
    'status' => 'ok',
    'audio'  => 'data:audio/mpeg;base64,' . base64_encode($audio),
    'motor'  => $motor,
]);
