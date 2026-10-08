<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

// Best-effort: en hostings como InfinityFree esto suele ser ignorado (el
// límite real lo impone la infraestructura, no el ini de PHP), pero no
// hace daño intentarlo. La protección real está en los timeouts de curl
// más abajo (20s), que dejan margen para que el script termine y
// devuelva un JSON de error prolijo ANTES de que el hosting mate el
// proceso a la fuerza y devuelva su propio HTML de error (lo que rompe
// el response.json() del frontend).
@ini_set('max_execution_time', '40');
@set_time_limit(40);

// Rate limiting: máximo RATE_LIMIT_MAX_REQUESTS por IP cada RATE_LIMIT_WINDOW_SEG
exigirRateLimit('gemini');

// Límite de tamaño del body para evitar abuso
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 50000) {
    echo json_encode(['status' => 'error', 'message' => 'Solicitud demasiado grande.']);
    exit;
}

// ── Modo local ───────────────────────────────────────────────
// PHP_SAPI vale 'cli-server' solo cuando el sitio corre con `php -S`
// (CodeMentor en Termux). En ese caso se usa primero el modelo local
// (llama-server); si falla, se cae a Gemini/Groq como antes.
$modoLocal = (PHP_SAPI === 'cli-server');
if ($modoLocal) {
    @ini_set('max_execution_time', '120');
    @set_time_limit(120);
}

// ── Log de errores de este endpoint (chat principal) ──────────
// Antes, cuando fallaban Gemini y TODAS las keys de Groq, el detalle
// de cada intento se perdía (solo quedaba el error de Gemini, que ni
// siquiera es el más útil). Este log deja registro de cada intento,
// igual que ya hace guardar_logica.php con auto_guardado.log, para
// poder diagnosticar sin tener que especular.
$logChatFile = __DIR__ . '/chat_errores.log';
function logChat($msg) {
    global $logChatFile;
    @file_put_contents($logChatFile, date('Y-m-d H:i:s') . ' — ' . $msg . "\n", FILE_APPEND);
}

// Gemini es opcional: si no hay clave definida, se usa local/Groq.
$apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
$modelo = defined('GEMINI_MODELO') ? GEMINI_MODELO : 'gemini-2.5-flash';
// La key va en un header (x-goog-api-key) en vez de en la URL, para que
// no quede registrada en access logs del servidor ni en el Referer.
$url    = "https://generativelanguage.googleapis.com/v1beta/models/{$modelo}:generateContent";

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || !isset($data['pregunta']) || trim($data['pregunta']) === '') {
    echo json_encode(['status' => 'error', 'message' => 'Falta la pregunta.']);
    exit;
}

// Límite de longitud de la pregunta
$pregunta = trim($data['pregunta']);
if (mb_strlen($pregunta, 'UTF-8') > 2000) {
    echo json_encode(['status' => 'error', 'message' => 'La pregunta es demasiado larga.']);
    exit;
}

$sinGemini = ($apiKey === 'TU_API_KEY_AQUI' || $apiKey === '');
if (!$modoLocal && $sinGemini && (!defined('GROQ_API_KEYS') || !GROQ_API_KEYS)) {
    echo json_encode(['status' => 'error', 'message' => 'No hay ningún motor de IA configurado (falta la key de Groq).']);
    exit;
}

$contexto  = isset($data['contexto'])  ? trim($data['contexto'])  : '';
$historial = isset($data['historial']) && is_array($data['historial']) ? $data['historial'] : [];
// Limitar historial a 10 turnos para evitar payloads gigantes
$historial = array_slice($historial, $modoLocal ? -6 : -10);

// ── Saneo de turnos gigantes del historial ────────────────────
// Si alguna vez el usuario pega un archivo enorme directo en el chat
// (en vez de usar el adjunto de subir_archivo.php), ese mensaje queda
// en historialSesion en el cliente y se reenvía completo en cada
// turno siguiente — esto fue lo que probablemente tumbó a las 7 keys
// de Groq de una: el payload crecía sin parar. Acá se recorta
// cualquier turno demasiado largo a un resumen corto, así un mensaje
// viejo no sigue pesando lo mismo para siempre.
const HISTORIAL_TURNO_MAX = 1500;
foreach ($historial as &$turnoSaneado) {
    if (isset($turnoSaneado['texto']) && mb_strlen((string) $turnoSaneado['texto'], 'UTF-8') > HISTORIAL_TURNO_MAX) {
        $turnoSaneado['texto'] = mb_substr((string) $turnoSaneado['texto'], 0, 300, 'UTF-8')
            . ' […mensaje largo recortado…]';
    }
}
unset($turnoSaneado);

// ── Archivo adjunto (opcional) ─────────────────────────────────
// El cliente manda solo un "archivo_id" corto (no el contenido); el
// contenido se lee del disco acá, server-side, y se agrega SOLO al
// contexto de esta llamada puntual — nunca queda guardado dentro de
// historialSesion, así que no se acumula turno tras turno.
// 100000 caracteres deja entrar archivos como index.html (~80KB)
// completos, sin recortarlos antes de llegar al modelo.
const ARCHIVO_ADJUNTO_MAX_CHARS = 100000;
$archivoTexto = '';
if (isset($data['archivo_id']) && preg_match('/^[a-f0-9]{32}$/', (string) $data['archivo_id'])) {
    $rutaArchivo = __DIR__ . '/archivos_data/' . $data['archivo_id'] . '.txt';
    if (is_file($rutaArchivo)) {
        $archivoTexto = (string) file_get_contents($rutaArchivo);
        if (mb_strlen($archivoTexto, 'UTF-8') > ARCHIVO_ADJUNTO_MAX_CHARS) {
            $archivoTexto = mb_substr($archivoTexto, 0, ARCHIVO_ADJUNTO_MAX_CHARS, 'UTF-8')
                . "\n\n[…archivo recortado por longitud, pedile al usuario la sección puntual si necesitás más…]";
        }
    }
}

// ── Instrucción de sistema ───────────────────────────────────
$instrucciones  = "Eres 'CodeMentor', un asistente de IA conversacional que responde siempre en español, creado por bytech, ";

$instrucciones .= "de forma clara, natural y directa, como lo haría un profesor experto en desarrollo de software, aplicaciones android,paginas web. ";
$instrucciones .= "Si el usuario pregunta algo fuera de ese tema, respóndele igual con normalidad usando tu conocimiento general. ";
$instrucciones .= "Si te preguntan con qué modelo estás hecho, qué tan grande sos o cuántos parámetros tenés, respondé que estás entrenado con 8 mil millones de parámetros. ";
$instrucciones .= "No repitas, reformules ni cites la pregunta del usuario al inicio de tu respuesta (nada de 'Buena pregunta', 'Quieres saber sobre...', ni repetir su mensaje textual): anda directo al contenido de la respuesta.\n\n";
$instrucciones .= "Si el usuario adjuntó un archivo de código y te pide corregir o modificar algo: NO reescribas ni repitas el archivo completo. Respondé solo con el fragmento de código puntual que cambia (la función, el bloque CSS, o las líneas específicas), e indicá claramente qué parte del archivo reemplaza o dónde va, para que el usuario lo pueda pegar directo. Si el pedido realmente requiere tocar varias partes separadas, listá cada fragmento por separado con su ubicación, en vez de un archivo entero.\n\n";

if ($archivoTexto !== '') {
    $instrucciones .= "El usuario adjuntó un archivo de código. Este es su contenido completo, como referencia:\n\n"
        . "```\n" . $archivoTexto . "\n```\n\n";
}

if ($contexto !== '') {
    $instrucciones .= "A continuación tienes fragmentos de la base de conocimiento propia del usuario que PUEDEN ser útiles ";
    $instrucciones .= "para responder la pregunta. Úsalos como referencia principal si son relevantes; si no tienen relación ";
    $instrucciones .= "con la pregunta, ignóralos y responde con tu propio conocimiento. ";
    $instrucciones .= "Si los usas: basa tu respuesta SOLO en su código, da UN solo ejemplo corto (máximo 15 líneas), ";
    $instrucciones .= "no inventes variantes ni agregues ejemplos extra, y explica en 2 o 3 frases como máximo:\n\n" . $contexto;
}

// ── Construir historial ──────────────────────────────────────
$contents = [];
$contents[] = ['role' => 'user',  'parts' => [['text' => $instrucciones]]];
$contents[] = ['role' => 'model', 'parts' => [['text' => 'Entendido, estoy listo para ayudar.']]];

foreach ($historial as $turno) {
    if (!isset($turno['rol']) || !isset($turno['texto'])) continue;
    $rol = ($turno['rol'] === 'user') ? 'user' : 'model';
    $contents[] = ['role' => $rol, 'parts' => [['text' => (string) $turno['texto']]]];
}
$contents[] = ['role' => 'user', 'parts' => [['text' => $pregunta]]];

$body = json_encode([
    'contents' => $contents,
    'generationConfig' => [
        'temperature'     => 0.7,
        'maxOutputTokens' => 2048,
    ]
]);

// ── Intento 1: Gemini ──────────────────────────────────────────
function llamarGemini($url, $apiKey, $body) {
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
        return [false, null, 'Error de conexión: ' . $curlError];
    }

    $decoded = json_decode($response, true);

    if ($httpCode !== 200 || !$decoded) {
        $msg = 'Error Gemini (código ' . $httpCode . ').';
        if (isset($decoded['error']['message'])) $msg = $decoded['error']['message'];
        return [false, null, $msg];
    }

    $texto = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!$texto) {
        $razon = $decoded['candidates'][0]['finishReason'] ?? 'desconocida';
        return [false, null, 'Sin respuesta (razón: ' . $razon . ').'];
    }

    return [true, trim($texto), null];
}

// ── Intento 2 y 3 (respaldo): Groq, se usa solo si Gemini falla ─
// (sin crédito, cuota agotada, error de red, etc.)
function llamarGroq($apiKey, $instrucciones, $historial, $pregunta) {
    if ($apiKey === '' || $apiKey === 'TU_API_KEY_AQUI') {
        return [false, null, 'Groq no está configurado.'];
    }

    $mensajes = [];
    $mensajes[] = ['role' => 'system', 'content' => $instrucciones];
    foreach ($historial as $turno) {
        if (!isset($turno['rol']) || !isset($turno['texto'])) continue;
        $rol = ($turno['rol'] === 'user') ? 'user' : 'assistant';
        $mensajes[] = ['role' => $rol, 'content' => (string) $turno['texto']];
    }
    $mensajes[] = ['role' => 'user', 'content' => $pregunta];

    $body = json_encode([
        'model'       => GROQ_MODELO,
        'messages'    => $mensajes,
        'temperature' => 0.7,
        'max_tokens'  => 2048,
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            'https://api.groq.com/openai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey]);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $body);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return [false, null, 'Error de conexión con Groq: ' . $curlError];
    }

    $decoded = json_decode($response, true);

    if ($httpCode !== 200 || !$decoded) {
        $msg = 'Error Groq (código ' . $httpCode . ').';
        if (isset($decoded['error']['message'])) $msg = $decoded['error']['message'];
        return [false, null, $msg];
    }

    $texto = $decoded['choices'][0]['message']['content'] ?? null;
    if (!$texto) {
        return [false, null, 'Groq no devolvió respuesta.'];
    }

    return [true, trim($texto), null];
}

// ── Modelo local: llama-server de llama.cpp (API compatible con OpenAI) ──
// Por defecto en http://127.0.0.1:8081. Se puede cambiar definiendo
// LLAMA_URL en config.php.
function llamarLocal($instrucciones, $historial, $pregunta) {
    $url = defined('LLAMA_URL') ? LLAMA_URL : 'http://127.0.0.1:8081/v1/chat/completions';

    $mensajes = [];
    $mensajes[] = ['role' => 'system', 'content' => $instrucciones];
    foreach ($historial as $turno) {
        if (!isset($turno['rol']) || !isset($turno['texto'])) continue;
        $rol = ($turno['rol'] === 'user') ? 'user' : 'assistant';
        $mensajes[] = ['role' => $rol, 'content' => (string) $turno['texto']];
    }
    $mensajes[] = ['role' => 'user', 'content' => $pregunta];

    $body = json_encode([
        'messages'         => $mensajes,
        'temperature'      => (($GLOBALS['contexto'] ?? '') !== '') ? 0.3 : 0.7,
        'max_tokens'       => 512,
        'stream'           => false,
        // Sin esto, un modelo tan chico (0.5B) tiende a "loopearse" y
        // repetir el mismo bloque cuando se le pide más de un ejemplo:
        // estos parámetros penalizan repetir tokens/frases ya generadas.
        'repeat_penalty'   => 1.3,
        'repeat_last_n'    => 256,
        'frequency_penalty' => 0.4,
        'presence_penalty' => 0.4,
        'top_p'            => 0.9,
        'top_k'            => 40,
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $body);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT,        110);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return [false, null, 'No se pudo conectar con llama-server (' . $url . '): ' . $curlError];
    }

    $decoded = json_decode($response, true);

    if ($httpCode !== 200 || !$decoded) {
        $msg = 'Error llama-server (código ' . $httpCode . ').';
        if (isset($decoded['error']['message'])) $msg .= ' ' . $decoded['error']['message'];
        return [false, null, $msg];
    }

    $texto = $decoded['choices'][0]['message']['content'] ?? null;
    if (!$texto) {
        return [false, null, 'llama-server no devolvió respuesta.'];
    }

    return [true, trim($texto), null];
}

// ── Elegir motor: local primero (si aplica), luego Gemini, luego Groq ──
$ok          = false;
$respuesta   = null;
$errorLocal  = null;
$errorGemini = null;
$motor       = 'gemini';

if ($modoLocal) {
    $motor = 'local';
    [$ok, $respuesta, $errorLocal] = llamarLocal($instrucciones, $historial, $pregunta);
}

if (!$ok) {
    $motor = 'gemini';
    if ($sinGemini) {
        $errorGemini = 'Gemini desactivado (sin API key).';
    } else {
        [$ok, $respuesta, $errorGemini] = llamarGemini($url, $apiKey, $body);
    }
    if (!$ok && !$sinGemini) {
        logChat("AVISO — Gemini falló ({$errorGemini}), probando Groq de respaldo. Largo del body: " . strlen($body) . ' bytes');
    }
}

if (!$ok) {
    $erroresGroq = [];
    foreach (GROQ_API_KEYS as $i => $groqKey) {
        $motor = 'groq_' . ($i + 1);
        [$ok, $respuesta, $errorGroq] = llamarGroq($groqKey, $instrucciones, $historial, $pregunta);
        if ($ok) {
            logChat("OK — respondido con respaldo: {$motor}");
            break;
        }
        $erroresGroq[] = "{$motor}: {$errorGroq}";
    }

    if (!$ok) {
        // Fallaron Gemini y todas las claves de Groq: se loguea el detalle
        // completo de cada intento (antes se perdía) para poder ver la
        // próxima vez si fue cuota, tamaño del payload, timeout, etc.
        $detalleCompleto = 'Gemini: ' . $errorGemini;
        if ($errorLocal) $detalleCompleto = 'Modelo local: ' . $errorLocal . ' | ' . $detalleCompleto;
        if ($erroresGroq) $detalleCompleto .= ' | Groq -> ' . implode(' ; ', $erroresGroq);
        logChat("ERROR — fallaron Gemini y las " . count(GROQ_API_KEYS) . " keys de Groq. {$detalleCompleto}");

        // Al usuario le mostramos un mensaje amigable, no el JSON/texto
        // crudo del proveedor (confunde y no le sirve para nada). El
        // detalle técnico completo queda en chat_errores.log para
        // diagnosticar.
        echo json_encode(['status' => 'error', 'message' => 'CodeMentor está muy solicitado en este momento, probá de nuevo en unos segundos.']);
        exit;
    }
}

// ── Guardado automático en segundo plano ─────────────────────
// Se dispara DESPUÉS de responder al usuario, sin bloquearlo.
// Antes esto se hacía con un curl hacia guardar.php (a sí mismo por
// HTTP), lo cual con el servidor embebido de PHP (`php -S`, usado en
// Termux) causaba un deadlock: el servidor es de un solo hilo, así
// que no podía atender esa segunda petición mientras seguía "ocupado"
// con esta misma. Ahora se llama directo como función PHP normal —
// más rápido y sin ese problema en ningún entorno.
require_once __DIR__ . '/guardar_logica.php';

// Enviar la respuesta al usuario PRIMERO para que no espere el guardado
$payload = json_encode(['status' => 'ok', 'respuesta' => $respuesta, 'motor' => $motor]);

header('Content-Length: ' . strlen($payload));
header('Connection: close');
echo $payload;

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ignore_user_abort(true);
    if (session_id()) session_write_close();
    flush();
}

// ── Guardado automático (el usuario ya recibió su respuesta) ──
// Reiniciamos el límite de tiempo acá: el de arriba (40s) ya se venía
// gastando con la llamada principal a Gemini. Esta segunda etapa puede
// llamar a Gemini + hasta 7 keys de Groq de respaldo para estructurar
// la respuesta; cada intento ahora tiene timeout de 12s (antes 30s) y
// el ciclo de Groq se corta solo a los 40s acumulados (ver
// guardar_logica.php), así que 100s deja margen real para llegar
// siempre a guardarPendiente() en vez de que PHP mate el script a
// mitad de camino. Aun así, si algo inesperado se pasa de este límite,
// el register_shutdown_function() de dentro de ejecutarGuardadoAutomatico()
// actúa como última red de seguridad para no perder la conversación.
@set_time_limit(100);
ejecutarGuardadoAutomatico($pregunta, $respuesta, $carpetaConocimiento);
