<?php
header('Content-Type: application/json; charset=utf-8');

$total = 0;
$archivo = __DIR__ . '/conocimiento.jsonl';

if (file_exists($archivo)) {
    $fh = fopen($archivo, 'r');
    if ($fh) {
        while (($linea = fgets($fh)) !== false) {
            if (trim($linea) !== '') $total++;
        }
        fclose($fh);
    }
}

echo json_encode(['status' => 'ok', 'total' => $total]);
