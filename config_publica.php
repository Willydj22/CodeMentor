<?php
// ── config_publica.php — único valor de configuración que necesita el
// frontend (index.html), sin exponer el resto de config_codementor.json ni
// requerir sesión de admin. Es de solo lectura: no acepta POST ni
// parámetros, así que no hay nada que un visitante pueda modificar acá.

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_codementor.php';
exigirRateLimit('config_publica');

$config = cargarConfigCodeMentor();

echo json_encode([
    'status' => 'ok',
    'umbral_evitar_ia' => (float) $config['umbral_evitar_ia'],
]);
