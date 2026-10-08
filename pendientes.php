<?php
// ── pendientes.php — Recuperar guardados que fallaron ──────────
// Cuando ejecutarGuardadoAutomatico() no puede estructurar la
// respuesta (Gemini y Groq fallan los dos), la pregunta+respuesta
// completa se guarda en conocimiento_data/pendientes.jsonl (ver
// guardar_logica.php). Este panel permite:
//   - Ver esas respuestas completas (antes solo quedaba un resumen
//     en auto_guardado.log, sin el texto).
//   - Reintentar el guardado automático (por si el fallo fue algo
//     puntual, como un HTTP 429 momentáneo).
//   - Guardarla "a mano" directo al conocimiento, sin pasar por
//     Gemini/Groq, si el reintento sigue fallando.
//   - Descartarla si no vale la pena guardarla.
//
// Usa la MISMA sesión de admin que estado.php: hay que haber
// iniciado sesión ahí primero (este archivo no tiene login propio).

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/guardar_logica.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrfValidoPend() {
    $enviado = $_POST['csrf_token'] ?? '';
    return isset($_SESSION['csrf_token']) && $enviado !== '' && hash_equals($_SESSION['csrf_token'], $enviado);
}

$autenticado = $_SESSION['codementor_admin'] ?? false;
if (!$autenticado) {
    header('Location: estado.php');
    exit;
}

// ── Acciones (AJAX, devuelven JSON) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrfValidoPend()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'msg' => 'Token de seguridad inválido, recargá la página.']);
        exit;
    }

    $indice = (int) ($_POST['indice'] ?? -1);
    $pendientes = listarPendientes($carpetaConocimiento);
    $item = null;
    foreach ($pendientes as $p) if ($p['_indice'] === $indice) { $item = $p; break; }

    if ($item === null) {
        echo json_encode(['status' => 'error', 'msg' => 'Ítem no encontrado (puede que ya se haya procesado)']);
        exit;
    }

    // ── Reintentar el guardado automático (Gemini → Groq) ───────
    if ($_POST['action'] === 'reintentar') {
        @set_time_limit(60);
        $resultado = ejecutarGuardadoAutomatico($item['pregunta'], $item['respuesta'], $carpetaConocimiento);
        // Si esta vez funcionó (ok) o el concepto ya existe (skip),
        // ya no hace falta seguir en la cola de pendientes.
        if (in_array($resultado['status'], ['ok', 'skip'], true)) {
            eliminarPendiente($carpetaConocimiento, $indice);
        }
        echo json_encode($resultado);
        exit;
    }

    // ── Guardar tal cual, sin pasar por Gemini/Groq ─────────────
    // Vuelca la pregunta y la respuesta crudas directo al conocimiento.
    // Sirve cuando el reintento automático sigue fallando pero la
    // respuesta en sí es buena y no queremos perderla.
    if ($_POST['action'] === 'guardar_directo') {
        $conceptoManual = trim($_POST['concepto'] ?? '');
        if ($conceptoManual === '') $conceptoManual = mb_substr($item['pregunta'], 0, 80);

        $entrada = [
            "pregunta"         => $item['pregunta'],
            "concepto"         => $conceptoManual,
            "descripcion"      => $item['respuesta'],
            "sintaxis"         => "",
            "ejemplo"          => "",
            "errores"          => "",
            "buenas_practicas" => "",
            "_fecha"           => date("Y-m-d H:i:s"),
            "_origen"          => "pendiente_manual",
        ];
        $archivoConocimiento = $carpetaConocimiento . "/conocimiento-" . date("Y-m") . ".jsonl";

        $resultado = conLockDeGuardado($carpetaConocimiento, function () use ($entrada, $carpetaConocimiento, $archivoConocimiento) {
            $existente = conceptoExiste($entrada["concepto"], $entrada["pregunta"], $carpetaConocimiento);
            if ($existente) {
                return ["status" => "skip", "msg" => "Ya existe una entrada equivalente para: " . $entrada["concepto"]];
            }
            guardarItem($entrada, $archivoConocimiento);
            return ["status" => "ok", "concepto" => $entrada["concepto"]];
        });

        if ($resultado['status'] === 'ok') {
            eliminarPendiente($carpetaConocimiento, $indice);
            logAuto("OK — guardado a mano desde pendientes.php: " . $entrada["concepto"]);
        }
        echo json_encode($resultado);
        exit;
    }

    // ── Descartar (no se guarda, se borra de la cola) ───────────
    if ($_POST['action'] === 'descartar') {
        $ok = eliminarPendiente($carpetaConocimiento, $indice);
        echo json_encode($ok ? ['status' => 'ok'] : ['status' => 'error', 'msg' => 'No se pudo eliminar']);
        exit;
    }

    echo json_encode(['status' => 'error', 'msg' => 'Acción desconocida']);
    exit;
}

// ── Vista (HTML) ─────────────────────────────────────────────────
$pendientes = array_reverse(listarPendientes($carpetaConocimiento));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CodeMentor — Pendientes</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    background: #1a1a1a; color: #ececec; padding: 20px;
  }
  h1 { font-size: 18px; margin-bottom: 4px; }
  .sub { color: #999; font-size: 13px; margin-bottom: 20px; }
  a.volver { color: #9ecbff; font-size: 13px; text-decoration: none; }
  .vacio { color: #999; padding: 40px 0; text-align: center; }
  .item {
    background: #2a2a2a; border: 1px solid #333; border-radius: 12px;
    padding: 16px; margin-bottom: 14px;
  }
  .item .pregunta { font-weight: 600; font-size: 14px; margin-bottom: 6px; }
  .item .meta { color: #999; font-size: 12px; margin-bottom: 10px; }
  .item .motivo { color: #e0a84a; font-size: 12px; margin-bottom: 10px; }
  .item .respuesta {
    background: #1e1e1e; border-radius: 8px; padding: 10px; font-size: 13px;
    white-space: pre-wrap; max-height: 220px; overflow-y: auto; margin-bottom: 10px;
  }
  .item input[type=text] {
    width: 100%; background: #1e1e1e; border: 1px solid #3a3a3a; border-radius: 8px;
    color: #ececec; font-size: 13px; padding: 8px 10px; margin-bottom: 10px;
  }
  .botones { display: flex; gap: 8px; flex-wrap: wrap; }
  button {
    background: #ececec; color: #212121; border: none; border-radius: 8px;
    padding: 8px 14px; font-size: 13px; font-weight: 600; cursor: pointer;
  }
  button.secundario { background: #3a3a3a; color: #ececec; }
  button.peligro { background: #4a2a2a; color: #e77; }
  button:disabled { opacity: 0.5; cursor: default; }
  .resultado { font-size: 12px; margin-top: 8px; }
</style>
</head>
<body>
  <a class="volver" href="estado.php">← Volver al panel</a>
  <h1>Pendientes de guardado</h1>
  <p class="sub">Respuestas que la IA no pudo estructurar automáticamente (Gemini y Groq fallaron). Podés reintentar, guardarlas tal cual, o descartarlas.</p>

  <?php if (empty($pendientes)): ?>
    <div class="vacio">No hay pendientes. 🎉</div>
  <?php else: foreach ($pendientes as $p): ?>
    <div class="item" data-indice="<?= (int)$p['_indice'] ?>">
      <div class="pregunta"><?= htmlspecialchars($p['pregunta'] ?? '') ?></div>
      <div class="meta"><?= htmlspecialchars($p['_fecha'] ?? '') ?></div>
      <?php if (!empty($p['motivo'])): ?>
        <div class="motivo">Motivo del fallo: <?= htmlspecialchars($p['motivo']) ?></div>
      <?php endif; ?>
      <div class="respuesta"><?= htmlspecialchars($p['respuesta'] ?? '') ?></div>
      <input type="text" class="concepto-manual" placeholder="Concepto para guardar tal cual (opcional)">
      <div class="botones">
        <button class="reintentar">Reintentar (Gemini/Groq)</button>
        <button class="guardar-directo secundario">Guardar tal cual</button>
        <button class="descartar peligro">Descartar</button>
      </div>
      <div class="resultado"></div>
    </div>
  <?php endforeach; endif; ?>

<script>
const csrf = <?= json_encode($_SESSION['csrf_token']) ?>;

async function accion(item, accion, extra = {}) {
  const indice = item.dataset.indice;
  const resultadoEl = item.querySelector('.resultado');
  const botones = item.querySelectorAll('button');
  botones.forEach(b => b.disabled = true);
  resultadoEl.textContent = 'Procesando…';

  try {
    const body = new URLSearchParams({ action: accion, indice, csrf_token: csrf, ...extra });
    const res = await fetch('pendientes.php', { method: 'POST', body });
    const data = await res.json();

    if (data.status === 'ok') {
      resultadoEl.textContent = '✔ Guardado' + (data.concepto ? (': ' + data.concepto) : '') + (data.msg ? (' — ' + data.msg) : '');
      item.style.opacity = '0.5';
      setTimeout(() => item.remove(), 1200);
    } else if (data.status === 'skip') {
      resultadoEl.textContent = 'ℹ ' + (data.msg || 'Ya existía, se descartó de pendientes.');
      item.style.opacity = '0.5';
      setTimeout(() => item.remove(), 1500);
    } else if (accion === 'descartar') {
      resultadoEl.textContent = '🗑 Descartado';
      item.remove();
    } else {
      resultadoEl.textContent = '✘ ' + (data.msg || 'Falló de nuevo, se mantiene en pendientes.');
      botones.forEach(b => b.disabled = false);
    }
  } catch (e) {
    resultadoEl.textContent = '✘ Error de red: ' + e.message;
    botones.forEach(b => b.disabled = false);
  }
}

document.querySelectorAll('.item').forEach(item => {
  item.querySelector('.reintentar').addEventListener('click', () => accion(item, 'reintentar'));
  item.querySelector('.descartar').addEventListener('click', () => {
    if (confirm('¿Descartar esta respuesta sin guardarla?')) accion(item, 'descartar');
  });
  item.querySelector('.guardar-directo').addEventListener('click', () => {
    const concepto = item.querySelector('.concepto-manual').value;
    accion(item, 'guardar_directo', { concepto });
  });
});
</script>
</body>
</html>
