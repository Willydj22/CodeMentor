<?php
// ── entrenamiento_logica.php ─────────────────────────────────
// El "motor" de una ronda de entrenamiento automático: elegir la
// próxima pregunta no repetida, pedirle la respuesta a Gemini/Groq,
// y guardarla con el pipeline normal (ejecutarGuardadoAutomatico).
//
// La usan dos cosas distintas:
// - entrenamiento_auto.php: el endpoint que dispara el cron externo.
// - estado.php: el botón "Ejecutar ahora" del panel de admin.
// Así ambos comparten exactamente el mismo comportamiento, sin
// duplicar código.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/guardar_logica.php';      // ejecutarGuardadoAutomatico, normalizarDup
require_once __DIR__ . '/preguntas_entrenamiento.php';

if (!function_exists('logEntrenamiento')) {
    function logEntrenamiento($msg) {
        $logFile = __DIR__ . '/entrenamiento_auto.log';
        @file_put_contents($logFile, date('Y-m-d H:i:s') . ' — ' . $msg . "\n", FILE_APPEND);
    }
}

if (!function_exists('llamarGeminiEntrenamiento')) {
    function llamarGeminiEntrenamiento($instrucciones, $pregunta) {
        $apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
        if ($apiKey === 'TU_API_KEY_AQUI' || $apiKey === '') {
            return [false, null, 'Falta configurar la API key de Gemini.'];
        }
        $modelo = defined('GEMINI_MODELO') ? GEMINI_MODELO : 'gemini-2.5-flash';
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$modelo}:generateContent";
        $body = json_encode([
            'contents' => [
                ['role' => 'user',  'parts' => [['text' => $instrucciones]]],
                ['role' => 'model', 'parts' => [['text' => 'Entendido, estoy listo para ayudar.']]],
                ['role' => 'user',  'parts' => [['text' => $pregunta]]],
            ],
            'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 2048],
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode !== 200 || !$response) {
            return [false, null, "HTTP {$httpCode}" . ($curlError ? " — {$curlError}" : "")];
        }
        $decoded = json_decode($response, true);
        $texto = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!$texto) return [false, null, 'Gemini no devolvió respuesta.'];
        return [true, trim($texto), null];
    }
}

if (!function_exists('llamarGroqEntrenamiento')) {
    function llamarGroqEntrenamiento($apiKey, $instrucciones, $pregunta) {
        if ($apiKey === '' || $apiKey === 'TU_API_KEY_AQUI') return [false, null, 'Groq no configurado.'];
        $body = json_encode([
            'model' => GROQ_MODELO,
            'messages' => [
                ['role' => 'system', 'content' => $instrucciones],
                ['role' => 'user',   'content' => $pregunta],
            ],
            'temperature' => 0.7,
            'max_tokens'  => 2048,
        ]);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.groq.com/openai/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode !== 200 || !$response) {
            return [false, null, "HTTP {$httpCode}" . ($curlError ? " — {$curlError}" : "")];
        }
        $decoded = json_decode($response, true);
        $texto = $decoded['choices'][0]['message']['content'] ?? null;
        if (!$texto) return [false, null, 'Groq no devolvió respuesta.'];
        return [true, trim($texto), null];
    }
}

// Progreso actual, sin ejecutar nada: para mostrar en el panel.
if (!function_exists('progresoEntrenamiento')) {
    function progresoEntrenamiento($carpetaConocimiento) {
        $banco = bancoDePreguntasEntrenamiento();
        $rutaYaPreguntadas = $carpetaConocimiento . '/entrenamiento_ya_preguntadas.json';
        $yaPreguntadas = is_file($rutaYaPreguntadas)
            ? (json_decode(file_get_contents($rutaYaPreguntadas), true) ?: [])
            : [];
        return [
            'hechas' => count($yaPreguntadas),
            'total'  => count($banco),
        ];
    }
}

// Últimas $n líneas del log, más recientes primero.
if (!function_exists('ultimasLineasEntrenamiento')) {
    function ultimasLineasEntrenamiento($n = 30) {
        $logFile = __DIR__ . '/entrenamiento_auto.log';
        if (!is_file($logFile)) return [];
        $lineas = file($logFile, FILE_IGNORE_NEW_LINES) ?: [];
        $lineas = array_filter($lineas, fn($l) => trim($l) !== '');
        $lineas = array_slice($lineas, -$n);
        return array_reverse($lineas);
    }
}

// ── La función principal: una ronda completa ──────────────────
// $respetarEnfriamiento = false cuando lo dispara el botón "Ejecutar
// ahora" del panel (el admin lo pide a propósito, así que no tiene
// sentido frenarlo por el enfriamiento pensado para el cron).
if (!function_exists('ejecutarRondaEntrenamiento')) {
    function ejecutarRondaEntrenamiento($carpetaConocimiento, $respetarEnfriamiento = true) {
        if (!is_dir($carpetaConocimiento)) @mkdir($carpetaConocimiento, 0755, true);

        $enfriamientoSegundos = 600; // 10 minutos
        $rutaUltimaCorrida = $carpetaConocimiento . '/.entrenamiento_ultima_corrida';
        if ($respetarEnfriamiento && is_file($rutaUltimaCorrida)) {
            $ultima = (int) file_get_contents($rutaUltimaCorrida);
            $faltan = $enfriamientoSegundos - (time() - $ultima);
            if ($faltan > 0) {
                return ['status' => 'skip', 'msg' => "Todavía en enfriamiento, faltan {$faltan}s"];
            }
        }

        $rutaLock = $carpetaConocimiento . '/.entrenamiento.lock';
        $fhLock = fopen($rutaLock, 'c');
        if ($fhLock && !flock($fhLock, LOCK_EX | LOCK_NB)) {
            return ['status' => 'skip', 'msg' => 'Ya hay una corrida en curso'];
        }

        file_put_contents($rutaUltimaCorrida, (string) time());

        $rutaYaPreguntadas = $carpetaConocimiento . '/entrenamiento_ya_preguntadas.json';
        $yaPreguntadas = is_file($rutaYaPreguntadas)
            ? (json_decode(file_get_contents($rutaYaPreguntadas), true) ?: [])
            : [];
        $yaPreguntadasSet = array_flip($yaPreguntadas);

        $banco = bancoDePreguntasEntrenamiento();
        $siguiente = null;
        foreach ($banco as $item) {
            $clave = normalizarDup($item['pregunta']);
            if (!isset($yaPreguntadasSet[$clave])) {
                $siguiente = $item;
                break;
            }
        }

        if ($siguiente === null) {
            logEntrenamiento('Banco de preguntas agotado — todas ya fueron hechas al menos una vez.');
            if ($fhLock) { flock($fhLock, LOCK_UN); fclose($fhLock); }
            return ['status' => 'listo', 'msg' => 'Ya se preguntó todo el banco. Agregá más temas a preguntas_entrenamiento.php.'];
        }

        $pregunta = $siguiente['pregunta'];
        logEntrenamiento("Preguntando (categoría: {$siguiente['categoria']}): \"{$pregunta}\"");

        $instrucciones = "Eres 'CodeMentor', un asistente de IA conversacional que responde siempre en español, creado por bytech, "
            . "de forma clara, natural y directa, como lo haría un profesor experto en desarrollo de software, aplicaciones "
            . "Android y páginas web. Da una explicación completa y bien desarrollada, con ejemplos de código reales cuando "
            . "el tema lo permita. No repitas ni cites la pregunta al inicio de tu respuesta: anda directo al contenido.";

        [$ok, $respuesta, $error] = llamarGeminiEntrenamiento($instrucciones, $pregunta);

        if (!$ok) {
            logEntrenamiento("AVISO — Gemini falló ({$error}), probando Groq de respaldo");
            foreach (GROQ_API_KEYS as $i => $groqKey) {
                [$ok, $respuesta, $error] = llamarGroqEntrenamiento($groqKey, $instrucciones, $pregunta);
                if ($ok) { logEntrenamiento("OK — respondido con respaldo groq_" . ($i + 1)); break; }
            }
        }

        if (!$ok) {
            logEntrenamiento("ERROR — no se pudo obtener respuesta ni de Gemini ni de Groq: {$error}");
            if ($fhLock) { flock($fhLock, LOCK_UN); fclose($fhLock); }
            return ['status' => 'error', 'msg' => "No se pudo generar respuesta: {$error}"];
        }

        $yaPreguntadas[] = normalizarDup($pregunta);
        file_put_contents($rutaYaPreguntadas, json_encode($yaPreguntadas, JSON_UNESCAPED_UNICODE));

        $resultadoGuardado = ejecutarGuardadoAutomatico($pregunta, $respuesta, $carpetaConocimiento);
        logEntrenamiento("Resultado del guardado: " . json_encode($resultadoGuardado, JSON_UNESCAPED_UNICODE));

        if ($fhLock) { flock($fhLock, LOCK_UN); fclose($fhLock); }

        return [
            'status'    => 'ok',
            'pregunta'  => $pregunta,
            'categoria' => $siguiente['categoria'],
            'guardado'  => $resultadoGuardado,
            'progreso'  => count($yaPreguntadas) . ' / ' . count($banco),
        ];
    }
}
