<?php
// ── guardar_logica.php — Lógica de guardado compartida ─────────
// Antes esto vivía solo dentro de guardar.php, y gemini.php lo
// disparaba haciendo un curl HTTP hacia guardar.php (a sí mismo).
// Eso funciona en un hosting real con Apache/Nginx (maneja varias
// conexiones a la vez), pero con el servidor embebido de PHP
// (`php -S`, usado en Termux) es de UN SOLO HILO: gemini.php queda
// esperando su propia respuesta mientras el servidor todavía está
// "ocupado" con la petición de gemini.php — deadlock, y el guardado
// nunca se ejecuta (el curl expira en 15s sin haber llamado a nada).
//
// La solución: sacar la lógica a una función normal de PHP que
// gemini.php pueda llamar directo (misma ejecución, sin red), y que
// guardar.php (el endpoint HTTP) siga funcionando igual para el
// guardado MANUAL desde el modal, llamando a la misma función.

require_once __DIR__ . '/config.php';

$carpetaConocimiento = __DIR__ . "/conocimiento_data";
if (!is_dir($carpetaConocimiento)) {
    @mkdir($carpetaConocimiento, 0755, true);
    if (!is_dir($carpetaConocimiento)) {
        // Si ni siquiera se pudo crear la carpeta, todo lo que sigue va a
        // fallar en silencio (guardarItem, el lock, pendientes.jsonl...).
        // Esto queda en el error_log de PHP del hosting para poder verlo.
        error_log("[codementor] No se pudo crear conocimiento_data en {$carpetaConocimiento} — revisar permisos del directorio padre.");
    }
}

// Devuelve true/false según si la escritura realmente ocurrió. Antes
// esta función no chequeaba el resultado de file_put_contents(): si la
// escritura fallaba en silencio (permisos, disco lleno, la carpeta no
// existe de verdad), el resto del código de todas formas asumía éxito
// y logueaba "OK — guardado" aunque no se hubiera escrito nada.
function guardarItem($entrada, $archivoConocimiento) {
    $linea = json_encode($entrada, JSON_UNESCAPED_UNICODE) . "\n";
    $resultado = file_put_contents($archivoConocimiento, $linea, FILE_APPEND | LOCK_EX);
    return $resultado !== false;
}

// ── Lock global de guardado ──────────────────────────────────
// En hostings compartidos (InfinityFree, etc.) el almacenamiento suele
// ser sobre red, y ahí el LOCK_EX de file_put_contents no siempre evita
// que dos guardados casi simultáneos (dos preguntas seguidas, o dos
// usuarios a la vez) se pisen: uno puede leer el archivo justo antes de
// que el otro le agregue una línea, y al reescribirlo la borra sin que
// nadie vea ningún error. Esta función obliga a que TODO el proceso de
// "revisar si ya existe + guardar/reemplazar" pase de a uno, usando un
// archivo de lock aparte con flock(), que sí es confiable para esto.
function conLockDeGuardado($carpetaConocimiento, callable $accion) {
    $rutaLock = $carpetaConocimiento . '/.guardado.lock';
    $fh = fopen($rutaLock, 'c');
    if (!$fh) {
        // Si ni siquiera se puede abrir el lock, seguimos sin bloquear
        // (mejor guardar sin lock que no guardar nada).
        return $accion();
    }
    flock($fh, LOCK_EX);
    try {
        return $accion();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

$STOPWORDS_DUP = array_flip([
    "el","la","los","las","un","una","unos","unas","de","del","al","a",
    "en","con","por","para","que","se","su","sus","le","les","me","mi",
    "nos","es","y","o","pero","si","como","mas","muy","ya","no",
    "cual","cuales","quien","donde","cuando",
    "este","esta","estos","estas","ese","esa","esos","esas",
    "hay","fue","ser","son","era","han","he","ha","te","tu",
    "yo","ella","eso","esto","lo","tan","sin","sobre",
    "entre","hasta","desde","hacia","tambien","todo","todos",
    "codementor","frost","ia","bot","hola","hey","ey","oye","che","buenas","buenos",
    "porfa","porfavor","dale","gracias","amigo","amiga"
]);

function normalizarDup($texto) {
    $texto = mb_strtolower($texto, 'UTF-8');
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $texto);
    if ($t !== false) $texto = $t;
    $texto = preg_replace('/[¿?¡!.,;:()\"\'\-]/', ' ', $texto);
    $texto = preg_replace('/\s+/', ' ', $texto);
    $texto = trim($texto);
    $texto = quitarIntroRellenoDup($texto);
    return trim($texto);
}

// Misma lógica que quitarIntroRelleno() en buscar.php: sin esto, dos
// preguntas iguales en el fondo ("Quiero crear un blog" / "Necesito
// hacer un blog") se ven distintas para conceptoExiste() solo por el
// arranque, y se termina guardando el mismo concepto dos veces.
function quitarIntroRellenoDup($texto) {
    $verbosDeseo  = 'quiero|quisiera|queria|necesito|necesitaria|deseo|megustaria|me gustaria|meencantaria|me encantaria';
    $conectorAyuda = '(?:que me ayudes a |ayudame a |ayuda (?:con|para) )?';
    $verboAccion  = 'crear|hacer|armar|construir|desarrollar|empezar|iniciar|arrancar|programar|implementar|aprender|saber|entender|comprender|hacerme|hacerte';

    $patronDeseo = '/^(?:' . $verbosDeseo . ')\s+' . $conectorAyuda . '(?:' . $verboAccion . ')?\s*/u';
    $patronComo  = '/^(?:como puedo|como hago para|de que manera puedo|de que forma puedo)\s+(?:' . $verboAccion . ')?\s*/u';

    $anterior = null;
    while ($anterior !== $texto) {
        $anterior = $texto;
        $texto = preg_replace($patronDeseo, '', $texto);
        $texto = preg_replace($patronComo, '', $texto);
        $texto = trim($texto);
    }
    return $texto;
}

function tokensDup($texto) {
    global $STOPWORDS_DUP;
    $norm = normalizarDup($texto);
    if ($norm === '') return [];
    $out = [];
    foreach (explode(' ', $norm) as $p) {
        if (mb_strlen($p) > 1 && !isset($STOPWORDS_DUP[$p])) $out[] = $p;
    }
    return $out;
}

// Umbrales de duplicados (antes: 0.75 y 85)
defined('UMBRAL_DUP_TOKENS')   || define('UMBRAL_DUP_TOKENS', 0.65);   // similitud por palabras (0 a 1)
defined('UMBRAL_DUP_CONCEPTO') || define('UMBRAL_DUP_CONCEPTO', 80);   // similar_text sobre el concepto (0 a 100)

function similitudTokens($a, $b) {
    $ta = array_unique(tokensDup($a));
    $tb = array_unique(tokensDup($b));
    if (!count($ta) || !count($tb)) return 0.0;
    $setA = array_flip($ta);
    $inter = 0;
    foreach ($tb as $t) if (isset($setA[$t])) $inter++;
    $union = count($ta) + count($tb) - $inter;
    $jaccard = $union > 0 ? $inter / $union : 0.0;
    // Contención: si el texto más corto (mínimo 2 palabras) está entero dentro
    // del más largo y cubre al menos la mitad de él, es el mismo tema.
    // Ej.: "Atributo alt" vs "Atributo alt en imágenes".
    $minSize = min(count($ta), count($tb));
    if ($minSize >= 2 && $inter === $minSize && $jaccard >= 0.5) {
        return max($jaccard, UMBRAL_DUP_TOKENS);
    }
    return $jaccard;
}

function conceptoExiste($concepto, $pregunta, $carpetaConocimiento) {
    $archivos = glob($carpetaConocimiento . '/conocimiento-*.jsonl') ?: [];
    foreach ($archivos as $archivoConocimiento) {
        $fh = fopen($archivoConocimiento, 'r');
        if (!$fh) continue;

        $numLinea = -1;
        while (($linea = fgets($fh)) !== false) {
            $numLinea++;
            $lineaTrim = trim($linea);
            if ($lineaTrim === '') continue;
            $item = json_decode($lineaTrim, true);
            if (!is_array($item)) continue;

            $existConcepto = trim($item['concepto'] ?? '');
            $existPregunta = trim($item['pregunta'] ?? '');
            if (!$existConcepto && !$existPregunta) continue;

            $match = false;

            if ($existConcepto) {
                similar_text(mb_strtolower($concepto, 'UTF-8'), mb_strtolower($existConcepto, 'UTF-8'), $pct);
                if ($pct >= UMBRAL_DUP_CONCEPTO) $match = true;
            }

            if (!$match && similitudTokens($pregunta, $existPregunta) >= UMBRAL_DUP_TOKENS) $match = true;
            if (!$match && similitudTokens($pregunta, $existConcepto) >= UMBRAL_DUP_TOKENS) $match = true;
            if (!$match && similitudTokens($concepto, $existPregunta) >= UMBRAL_DUP_TOKENS) $match = true;

            if ($match) {
                fclose($fh);
                return [
                    'archivo'   => $archivoConocimiento,
                    'linea_num' => $numLinea,
                    'fecha'     => $item['_fecha'] ?? null,
                ];
            }
        }
        fclose($fh);
    }
    return null;
}

function reemplazarLinea($archivo, $numLinea, $entradaNueva) {
    $lineas = file($archivo, FILE_IGNORE_NEW_LINES);
    if ($lineas === false || !isset($lineas[$numLinea])) return false;
    $lineas[$numLinea] = json_encode($entradaNueva, JSON_UNESCAPED_UNICODE);
    $contenido = implode("\n", $lineas) . "\n";
    return file_put_contents($archivo, $contenido, LOCK_EX) !== false;
}

if (!defined('DIAS_PARA_REFRESCAR')) define('DIAS_PARA_REFRESCAR', 180);

// Detecta si un texto probablemente contiene código (bloques ```,
// etiquetas HTML, tags PHP, o sintaxis típica de C-like/JS/CSS).
// Se usa para no descartar por "respuesta corta" cuando en realidad
// lo importante es el código pegado por el usuario, y para avisarle
// al prompt de estructuración que revise la PREGUNTA además de la
// RESPUESTA.
function pareceCodigo($texto) {
    if ($texto === null || $texto === '') return false;
    $patrones = [
        '/```/',                          // bloque de código markdown
        '/<\?php/i',                      // PHP con etiqueta de apertura
        '/\$[a-zA-Z_][a-zA-Z0-9_]*\s*=/',  // variable PHP: $nombre = ...
        '/->[a-zA-Z_]|::[a-zA-Z_]/',       // ->método() o Clase::método() (PHP)
        '/<\/?[a-z][\w-]*(\s[^>]*)?>/i',   // etiquetas HTML
        '/\bfunction\s*\w*\s*\(/i',        // function foo(
        '/=>\s*{|\)\s*{\s*$/m',            // arrow/función con llave
        '/;\s*$/m',                         // líneas terminadas en ;
        '/\b(const|let|var)\s+\w+\s*=/',   // declaraciones JS
        '/\b(SELECT|INSERT\s+INTO|UPDATE|DELETE\s+FROM|CREATE\s+TABLE|ALTER\s+TABLE)\b/i', // SQL
    ];
    foreach ($patrones as $p) {
        if (preg_match($p, $texto)) return true;
    }
    return false;
}

$logFile = __DIR__ . "/auto_guardado.log";
function logAuto($msg) {
    global $logFile;
    file_put_contents($logFile, date("Y-m-d H:i:s") . " — " . $msg . "\n", FILE_APPEND);
}

// ── Cola de pendientes ──────────────────────────────────────────
// El log (auto_guardado.log) solo anota la pregunta y un conteo de
// palabras cuando algo falla — la respuesta completa de Gemini/Groq
// NUNCA queda guardada ahí, así que hasta ahora, si el guardado
// automático fallaba, esa respuesta se perdía para siempre.
// Esta función la guarda completa (pregunta + respuesta + motivo del
// error) en conocimiento_data/pendientes.jsonl, para poder reintentar
// el guardado o pasarla a mano al conocimiento desde pendientes.php.
// Devuelve true/false según si la escritura realmente ocurrió. Es la
// red de seguridad final: si esto falla, ya no hay dónde más guardar
// la conversación, así que como último recurso queda en el error_log
// de PHP del hosting (siempre disponible, no depende del disco de la app).
function guardarPendiente($preguntaOriginal, $respuestaGemini, $motivo, $carpetaConocimiento) {
    $archivo = $carpetaConocimiento . "/pendientes.jsonl";
    $linea = [
        "pregunta"  => $preguntaOriginal,
        "respuesta" => $respuestaGemini,
        "motivo"    => $motivo,
        "_fecha"    => date("Y-m-d H:i:s"),
    ];
    $texto     = json_encode($linea, JSON_UNESCAPED_UNICODE) . "\n";
    $resultado = @file_put_contents($archivo, $texto, FILE_APPEND | LOCK_EX);

    if ($resultado === false) {
        error_log("[codementor] FALLÓ guardarPendiente() en {$archivo} (revisar permisos/espacio). Pregunta: \"{$preguntaOriginal}\" | Motivo original: {$motivo}");
        error_log("[codementor] Contenido perdido (respaldo en error_log): " . $texto);
        return false;
    }
    return true;
}
function eliminarPendiente($carpetaConocimiento, $indice) {
    $archivo = $carpetaConocimiento . "/pendientes.jsonl";
    if (!file_exists($archivo)) return false;
    $lineas = file($archivo, FILE_IGNORE_NEW_LINES);
    if ($lineas === false) return false;
    $lineas = array_values(array_filter($lineas, fn($l) => trim($l) !== ''));
    if (!isset($lineas[$indice])) return false;
    array_splice($lineas, $indice, 1);
    $contenido = '';
    foreach ($lineas as $l) $contenido .= $l . "\n";
    return file_put_contents($archivo, $contenido, LOCK_EX) !== false;
}

// Lee todas las líneas de pendientes.jsonl como array asociativo (con índice).
function listarPendientes($carpetaConocimiento) {
    $archivo = $carpetaConocimiento . "/pendientes.jsonl";
    if (!file_exists($archivo)) return [];
    $lineas = file($archivo, FILE_IGNORE_NEW_LINES) ?: [];
    $out = [];
    $i = 0;
    foreach ($lineas as $l) {
        if (trim($l) === '') continue;
        $item = json_decode($l, true);
        if (is_array($item)) {
            $item['_indice'] = $i;
            $out[] = $item;
        }
        $i++;
    }
    return $out;
}

function limpiarJsonTexto($texto) {
    $texto = preg_replace('/^```json\s*/i', '', trim($texto));
    $texto = preg_replace('/^```\s*/i',     '', $texto);
    $texto = preg_replace('/```\s*$/',      '', $texto);
    return trim($texto);
}

function estructurarConGemini($url, $apiKey, $body) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ["Content-Type: application/json", "x-goog-api-key: " . $apiKey]);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $body);
    // 12s en vez de 30s: esta llamada corre en segundo plano con hasta
    // 7 respaldos de Groq detrás. Con 30s por intento, un corte de red
    // agota los 60s de max_execution_time a mitad del ciclo de Groq y
    // el script muere ANTES de llegar a guardarPendiente() — la
    // conversación se pierde sin dejar rastro. Fallar rápido acá deja
    // presupuesto de tiempo para agotar los respaldos y de verdad
    // llegar a guardarPendiente() si todos fallan.
    curl_setopt($ch, CURLOPT_TIMEOUT,        12);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode !== 200 || !$response) {
        return [false, null, "HTTP {$httpCode}" . ($curlError ? " — {$curlError}" : "")];
    }

    $decoded = json_decode($response, true);
    $texto   = $decoded["candidates"][0]["content"]["parts"][0]["text"] ?? "";
    $texto   = limpiarJsonTexto($texto);
    $entrada = json_decode($texto, true);

    if (!is_array($entrada) || empty($entrada["concepto"]) || empty($entrada["descripcion"])) {
        return [false, null, "JSON inválido. Texto crudo: " . substr($texto, 0, 200)];
    }
    return [true, $entrada, null];
}

function estructurarConGroq($apiKey, $prompt) {
    if ($apiKey === "" || $apiKey === "TU_API_KEY_AQUI") {
        return [false, null, "Groq no configurado."];
    }

    $body = json_encode([
        "model"       => GROQ_MODELO,
        "messages"    => [
            ["role" => "system", "content" => "Respondés únicamente con JSON válido, sin texto adicional ni bloques de código."],
            ["role" => "user",   "content" => $prompt],
        ],
        "temperature" => 0.2,
        "max_tokens"  => 2048,
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            'https://api.groq.com/openai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey]);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $body);
    // Ídem estructurarConGemini(): 12s, no 30s. Con hasta 7 keys de
    // Groq para probar, hay que dejar presupuesto para llegar todas.
    curl_setopt($ch, CURLOPT_TIMEOUT,        12);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode !== 200 || !$response) {
        return [false, null, "HTTP {$httpCode}" . ($curlError ? " — {$curlError}" : "")];
    }

    $decoded = json_decode($response, true);
    $texto   = $decoded["choices"][0]["message"]["content"] ?? "";
    $texto   = limpiarJsonTexto($texto);
    $entrada = json_decode($texto, true);

    if (!is_array($entrada) || empty($entrada["concepto"]) || empty($entrada["descripcion"])) {
        return [false, null, "JSON inválido. Texto crudo: " . substr($texto, 0, 200)];
    }
    return [true, $entrada, null];
}

// ── Función principal: hace todo el proceso de guardado automático ──
// La llama tanto guardar.php (cuando lo dispara un request HTTP externo)
// como gemini.php (llamada directa, en el mismo proceso, sin red).
// Devuelve un array ["status" => "ok"|"skip"|"error", "msg"/"concepto" => ...]
function ejecutarGuardadoAutomatico($preguntaOriginal, $respuestaGemini, $carpetaConocimiento) {
    $archivoConocimiento = $carpetaConocimiento . "/conocimiento-" . date("Y-m") . ".jsonl";

    // ── Red de seguridad final contra timeouts fatales ─────────────
    // Un "Maximum execution time exceeded" es un error FATAL de PHP:
    // no se puede atrapar con try/catch, mata el script en el punto
    // exacto donde estaba (ej: a mitad de un curl_exec en el loop de
    // Groq) y NINGÚN código de más abajo llega a ejecutarse — ni
    // siquiera guardarPendiente(). register_shutdown_function() es la
    // única forma de reaccionar a eso: PHP la sigue llamando aunque el
    // script haya muerto por timeout, así que la usamos para guardar
    // el pendiente como último recurso si nada se guardó todavía.
    $yaResuelto = false;
    register_shutdown_function(function () use (&$yaResuelto, $preguntaOriginal, $respuestaGemini, $carpetaConocimiento) {
        if ($yaResuelto) return;
        $error = error_get_last();
        $esFatal = $error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
        $motivo  = $esFatal ? ('Fatal: ' . $error['message']) : 'El script terminó sin resolver el guardado (motivo desconocido).';
        logAuto("ERROR — shutdown inesperado durante el guardado automático ({$motivo})");
        guardarPendiente($preguntaOriginal, $respuestaGemini, $motivo, $carpetaConocimiento);
        logAuto("→ rescatada en pendientes.jsonl por la red de seguridad de shutdown");
    });

    logAuto("Intento de guardado. Pregunta: \"{$preguntaOriginal}\" | Palabras respuesta: " . str_word_count(strip_tags($respuestaGemini)));

    // Filtro 1: longitud mínima
    // Filtro 1: longitud mínima — salvo que haya código de por medio.
    // Un intercambio tipo "pegué este código, ¿qué hace?" suele tener
    // una respuesta corta en palabras (el código no cuenta como
    // palabras normales), pero es justo el caso que más vale guardar.
    $palabras   = str_word_count(strip_tags($respuestaGemini));
    $hayCodigo  = pareceCodigo($preguntaOriginal) || pareceCodigo($respuestaGemini);
    if ($palabras < 80 && !$hayCodigo) {
        logAuto("SKIP — respuesta muy corta ({$palabras} palabras)");
        $yaResuelto = true;
        return ["status" => "skip", "msg" => "Respuesta muy corta ({$palabras} palabras)"];
    }
    if ($palabras < 80 && $hayCodigo) {
        logAuto("Respuesta corta ({$palabras} palabras) pero se detectó código — no se descarta");
    }

    // Filtro 2: detectar respuestas negativas de Gemini
    $negativas = ["lo siento", "no puedo", "no tengo información", "no sé", "no es posible",
                  "fuera de mi", "disculpa", "lamentablemente", "no estoy seguro"];
    $respLower = mb_strtolower($respuestaGemini, 'UTF-8');
    foreach ($negativas as $neg) {
        if (mb_strpos($respLower, $neg) !== false) {
            logAuto("SKIP — frase negativa detectada: \"{$neg}\"");
            $yaResuelto = true;
            return ["status" => "skip", "msg" => "Respuesta negativa detectada"];
        }
    }

    $apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    $modelo = defined('GEMINI_MODELO') ? GEMINI_MODELO : 'gemini-2.5-flash';
    $url    = "https://generativelanguage.googleapis.com/v1beta/models/{$modelo}:generateContent";

    $prompt  = "Tenés esta pregunta de un usuario y la respuesta de un asistente de IA.\n\n";
    $prompt .= "PREGUNTA: {$preguntaOriginal}\n\n";
    $prompt .= "RESPUESTA:\n{$respuestaGemini}\n\n";
    $prompt .= "IMPORTANTE SOBRE CÓDIGO: si la PREGUNTA incluye un fragmento de código (el usuario lo pegó para preguntar qué hace, pedir que lo corrijan, etc.), identificá primero de qué se trata ese código (lenguaje, qué hace) y usá eso como base del 'concepto' y la 'categoria', aunque la RESPUESTA no lo repita textualmente. Usá el código de la PREGUNTA (o el de la RESPUESTA si lo corrige/mejora) como 'ejemplo', y no lo dejes vacío solo porque la explicación es corta.\n\n";
    $prompt .= "Convertí esta información en un objeto JSON con exactamente estos 10 campos:\n";
    $prompt .= "- pregunta: la pregunta original del usuario (texto corto)\n";
    $prompt .= "- concepto: el nombre del concepto principal (ej: 'Tabla HTML', 'Flexbox', 'Función PHP')\n";
    $prompt .= "- categoria: la tecnología o área general a la que pertenece (ej: 'HTML', 'CSS', 'PHP', 'JavaScript', 'Android', 'Bases de datos'). Una sola categoría, corta.\n";
    $prompt .= "- descripcion: explicación clara en 2-4 oraciones\n";
    $prompt .= "- sintaxis: la estructura o sintaxis del código (solo código, sin explicación)\n";
    $prompt .= "- ejemplo: un ejemplo de código funcional y concreto\n";
    $prompt .= "- errores: errores comunes relacionados (1-3 oraciones)\n";
    $prompt .= "- buenas_practicas: recomendaciones clave (1-3 oraciones)\n";
    $prompt .= "- palabras_clave: 3 a 8 palabras o frases cortas separadas por coma, que alguien usaría para buscar este concepto (sinónimos, términos relacionados, nombre en inglés y español si aplica)\n";
    $prompt .= "- nivel_dificultad: 'basico', 'intermedio' o 'avanzado', según el conocimiento previo que hace falta para entender la respuesta\n\n";
    $prompt .= "IMPORTANTE:\n";
    $prompt .= "- Respondé SOLO con el JSON, sin texto adicional, sin bloques de código, sin explicaciones.\n";
    $prompt .= "- Dejá sintaxis y ejemplo vacíos únicamente si NI la pregunta NI la respuesta tienen código.\n";
    $prompt .= "- NUNCA inventes un ejemplo de código de un tema distinto al de la PREGUNTA/RESPUESTA (por ejemplo, no pongas un ejemplo de CSS/Flexbox si la conversación no tiene nada que ver con CSS). El 'ejemplo' y la 'sintaxis' tienen que salir del código real presente en la PREGUNTA o la RESPUESTA — si no hay código real, van vacíos, nunca inventados.\n";
    $prompt .= "- El JSON debe ser válido y parseable directamente.\n";

    $body = json_encode([
        "contents" => [
            ["role" => "user", "parts" => [["text" => $prompt]]]
        ],
        "generationConfig" => [
            "temperature"     => 0.2,
            "maxOutputTokens" => 2048,
        ]
    ]);

    $motorEstructura = "gemini";
    $sinGemini = ($apiKey === '' || $apiKey === 'TU_API_KEY_AQUI');
    if ($sinGemini) {
        [$ok, $entrada, $errorEstructura] = [false, null, 'Gemini desactivado (sin API key).'];
    } else {
        [$ok, $entrada, $errorEstructura] = estructurarConGemini($url, $apiKey, $body);
    }

    if (!$ok) {
        if (!$sinGemini) logAuto("AVISO — Gemini falló al estructurar ({$errorEstructura}), probando Groq de respaldo");

        // Presupuesto de tiempo para todo el ciclo de respaldos de Groq.
        // Con hasta 7 keys, aunque cada intento ahora tenga timeout de
        // 12s, en el peor caso (todas fallan por timeout real) serían
        // 84s — más que suficiente para volver a comerse el límite de
        // ejecución. Este corte por tiempo garantiza que, si los
        // respaldos se están yendo muy largo, cortamos ANTES de que
        // PHP mate el script, dejando margen real para llegar a
        // guardarPendiente() de abajo en vez de perder todo en un
        // fatal sin log.
        $limiteGroq = time() + 40;
        foreach (GROQ_API_KEYS as $i => $groqKey) {
            if (time() >= $limiteGroq) {
                logAuto("AVISO — se cortó el ciclo de Groq por presupuesto de tiempo, con " . (count(GROQ_API_KEYS) - $i) . " keys sin probar");
                $errorEstructura = ($errorEstructura ?: 'timeout') . " (ciclo de Groq cortado por presupuesto de tiempo)";
                break;
            }
            $motorEstructura = 'groq_' . ($i + 1);
            [$ok, $entrada, $errorEstructura] = estructurarConGroq($groqKey, $prompt);
            if ($ok) break;
        }
    }

    if (!$ok) {
        logAuto("ERROR — No se pudo estructurar ni con Gemini ni con Groq. Último error: {$errorEstructura}");
        $guardado = guardarPendiente($preguntaOriginal, $respuestaGemini, $errorEstructura, $carpetaConocimiento);
        $yaResuelto = true;
        if ($guardado) {
            logAuto("→ guardada en pendientes.jsonl para reintentar/guardar a mano desde pendientes.php");
            return ["status" => "error", "msg" => "No se pudo estructurar la respuesta (Gemini y Groq fallaron), pero quedó guardada en pendientes.jsonl"];
        }
        // guardarPendiente() ya deja rastro en error_log si esto pasa
        return ["status" => "error", "msg" => "No se pudo estructurar la respuesta y tampoco se pudo guardar en pendientes.jsonl (ver error_log del servidor)"];
    }

    if ($motorEstructura !== 'gemini') {
        logAuto("OK — estructurado con respaldo: {$motorEstructura}");
    }

    // Filtro 3: verificar duplicado + guardar — todo dentro del lock,
    // para que dos guardados simultáneos no se pisen (ver conLockDeGuardado).
    // Por si Gemini/Groq no incluyen alguno de los campos nuevos: evita
    // que queden ausentes del todo (undefined index más adelante, en
    // buscar.php / estado.php) en vez de una cadena vacía prolija.
    $entrada["categoria"]        = trim((string) ($entrada["categoria"]        ?? ""));
    $entrada["palabras_clave"]   = trim((string) ($entrada["palabras_clave"]   ?? ""));
    $nivelValido = ["basico", "intermedio", "avanzado"];
    $nivelPedido = trim((string) ($entrada["nivel_dificultad"] ?? ""));
    $entrada["nivel_dificultad"] = in_array($nivelPedido, $nivelValido, true) ? $nivelPedido : "";
    $entrada["_origen"]  = "auto";
    $entrada["_fecha"]   = date("Y-m-d H:i:s");
    $entrada["_pregunta_original"] = $preguntaOriginal;
    // Nuevo: las entradas que pasan el guardado automático ya superaron
    // el filtro de calidad (concepto + descripción no vacíos), así que
    // arrancan como "publicado". "borrador" queda para las que se
    // completen/editen a mano desde estado.php antes de darlas por buenas.
    $entrada["estado"]              = "publicado";
    $entrada["fecha_actualizacion"] = $entrada["_fecha"];

    $resultadoFinal = conLockDeGuardado($carpetaConocimiento, function () use ($entrada, $preguntaOriginal, $respuestaGemini, $carpetaConocimiento, $archivoConocimiento) {
        $existente = conceptoExiste($entrada["concepto"], $preguntaOriginal, $carpetaConocimiento);

        if ($existente) {
            $esVieja = $existente['fecha'] && (strtotime($existente['fecha']) < strtotime('-' . DIAS_PARA_REFRESCAR . ' days'));
            if (!$esVieja) {
                logAuto("SKIP — concepto duplicado (reciente): " . $entrada["concepto"]);
                return ["status" => "skip", "msg" => "Concepto ya existe: " . $entrada["concepto"]];
            }
            $ok = reemplazarLinea($existente['archivo'], $existente['linea_num'], $entrada);
            if (!$ok) {
                logAuto("ERROR — no se pudo escribir al REEMPLAZAR (¿permisos/disco?): " . $entrada["concepto"]);
                guardarPendiente($preguntaOriginal, $respuestaGemini, "Falló la escritura al reemplazar en {$existente['archivo']} (revisar permisos/espacio)", $carpetaConocimiento);
                return ["status" => "error", "msg" => "No se pudo escribir el archivo de conocimiento (revisar permisos/espacio en el servidor). Se guardó en pendientes.jsonl."];
            }
            logAuto("OK — reemplazada entrada vieja: " . $entrada["concepto"]);
            return ["status" => "ok", "concepto" => $entrada["concepto"], "msg" => "Entrada anterior reemplazada"];
        }

        $ok = guardarItem($entrada, $archivoConocimiento);
        if (!$ok) {
            logAuto("ERROR — file_put_contents falló al guardar en {$archivoConocimiento} (¿permisos/disco/la carpeta no existe?): " . $entrada["concepto"]);
            guardarPendiente($preguntaOriginal, $respuestaGemini, "Falló la escritura en {$archivoConocimiento} (revisar permisos/espacio)", $carpetaConocimiento);
            return ["status" => "error", "msg" => "No se pudo escribir el archivo de conocimiento (revisar permisos/espacio en el servidor). Se guardó en pendientes.jsonl."];
        }
        logAuto("OK — guardado: " . $entrada["concepto"]);
        return ["status" => "ok", "concepto" => $entrada["concepto"]];
    });

    // Llegamos hasta acá sin timeout: sea éxito, skip o un error ya
    // manejado (que a su vez ya llamó a guardarPendiente() arriba),
    // el resultado es terminal. Recién ahora avisamos al shutdown
    // handler que no hace falta ninguna red de seguridad extra.
    $yaResuelto = true;
    return $resultadoFinal;
}
