<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/guardar_logica.php';

// Rate limiting más permisivo aquí porque también recibe el guardado automático interno
exigirRateLimit('guardar');

// Límite de tamaño del body
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 100000) {
    echo json_encode(["status" => "error", "msg" => "Solicitud demasiado grande."]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || !isset($data["pregunta"])) {
    echo json_encode(["status" => "error", "msg" => "Datos inválidos"]);
    exit;
}

// ── Guardado MANUAL (modal +) ─────────────────────────────────
if (!isset($data["_auto"])) {
    $entrada = [
        "pregunta"        => trim($data["pregunta"]         ?? ""),
        "concepto"        => trim($data["concepto"]         ?? ""),
        "categoria"       => trim($data["categoria"]        ?? ""),
        "descripcion"     => trim($data["descripcion"]      ?? ""),
        "sintaxis"        => trim($data["sintaxis"]         ?? ""),
        "ejemplo"         => trim($data["ejemplo"]          ?? ""),
        "errores"         => trim($data["errores"]          ?? ""),
        "buenas_practicas"=> trim($data["buenas_practicas"] ?? ""),
        "palabras_clave"  => trim($data["palabras_clave"]   ?? ""),
        "_fecha"          => date("Y-m-d H:i:s"),
    ];

    if ($entrada["pregunta"] === "" || $entrada["concepto"] === "") {
        echo json_encode(["status" => "error", "msg" => "Pregunta y concepto son obligatorios"]);
        exit;
    }

    $archivoConocimiento = $carpetaConocimiento . "/conocimiento-" . date("Y-m") . ".jsonl";

    // Mismo filtro de duplicados que el guardado automático, y mismo
    // lock para no pisarse con un guardado automático en simultáneo.
    $resultado = conLockDeGuardado($carpetaConocimiento, function () use ($entrada, $carpetaConocimiento, $archivoConocimiento) {
        $existente = conceptoExiste($entrada["concepto"], $entrada["pregunta"], $carpetaConocimiento);
        if ($existente) {
            $esVieja = $existente['fecha'] && (strtotime($existente['fecha']) < strtotime('-' . DIAS_PARA_REFRESCAR . ' days'));
            if ($esVieja) {
                $ok = reemplazarLinea($existente['archivo'], $existente['linea_num'], $entrada);
                if (!$ok) {
                    return ["status" => "error", "msg" => "No se pudo escribir el archivo de conocimiento (revisar permisos/espacio en el servidor)."];
                }
                return ["status" => "ok", "msg" => "Entrada anterior (vieja) reemplazada"];
            }
            return ["status" => "skip", "msg" => "Ya existe una entrada equivalente para: " . $entrada["concepto"]];
        }
        $ok = guardarItem($entrada, $archivoConocimiento);
        if (!$ok) {
            return ["status" => "error", "msg" => "No se pudo escribir el archivo de conocimiento (revisar permisos/espacio en el servidor)."];
        }
        return ["status" => "ok"];
    });

    echo json_encode($resultado);
    exit;
}

// ── Guardado AUTOMÁTICO vía HTTP ────────────────────────────────
// Ya no es el camino principal (gemini.php ahora llama a
// ejecutarGuardadoAutomatico() directo, sin red), pero se deja
// funcionando por si algo externo pega directo a este endpoint.
$preguntaOriginal = trim($data["pregunta"]  ?? "");
$respuestaGemini  = trim($data["respuesta"] ?? "");

$resultado = ejecutarGuardadoAutomatico($preguntaOriginal, $respuestaGemini, $carpetaConocimiento);
echo json_encode($resultado);
