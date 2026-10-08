<?php
// ── entrenamiento_auto.php ───────────────────────────────────
// Endpoint que dispara UNA ronda de entrenamiento automático (ver
// entrenamiento_logica.php). Pensado para un cron externo
// (cron-job.org, por ejemplo) — no para tocarlo a mano seguido.
//
// URL: entrenamiento_auto.php?clave=TU_CLAVE_SECRETA
// (definí ENTRENAMIENTO_CLAVE en config.php)

require_once __DIR__ . '/config.php';

@ini_set('max_execution_time', '90');
@set_time_limit(90);

header('Content-Type: application/json; charset=utf-8');

if (!defined('ENTRENAMIENTO_CLAVE') || ENTRENAMIENTO_CLAVE === '' || ENTRENAMIENTO_CLAVE === 'CAMBIAR_ESTO') {
    echo json_encode(['status' => 'error', 'msg' => 'Falta configurar ENTRENAMIENTO_CLAVE en config.php']);
    exit;
}
$claveRecibida = (string) ($_GET['clave'] ?? '');
if (!hash_equals(ENTRENAMIENTO_CLAVE, $claveRecibida)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'msg' => 'Clave inválida']);
    exit;
}

require_once __DIR__ . '/entrenamiento_logica.php';

$carpetaConocimiento = __DIR__ . '/conocimiento_data';
$resultado = ejecutarRondaEntrenamiento($carpetaConocimiento, true);

echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
