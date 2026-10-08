<?php
// ── estado.php — Panel de revisión de conocimiento aprendido ──

// Cookies de sesión más seguras: no accesibles por JS, solo por HTTPS
// (si el sitio corre en HTTPS) y no enviadas en navegación cross-site.
$cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'secure'   => $cookieSecure,
    'samesite' => 'Lax',
]);
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config_codementor.php'; // cargarConfigCodeMentor, guardarConfigCodeMentor — parámetros ajustables del matching
require_once __DIR__ . '/guardar_logica.php'; // listarPendientes, eliminarPendiente, ejecutarGuardadoAutomatico, etc.
require_once __DIR__ . '/imagen_cache.php'; // rutasCacheImagen, CACHE_IMAGENES_DIR, etc.
require_once __DIR__ . '/entrenamiento_logica.php'; // ejecutarRondaEntrenamiento, progresoEntrenamiento, etc.

// Token CSRF para el formulario de login y las acciones del panel
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrfValido() {
    $enviado = $_POST['csrf_token'] ?? '';
    return isset($_SESSION['csrf_token']) && $enviado !== '' && hash_equals($_SESSION['csrf_token'], $enviado);
}

// ── Login (con rate limit anti fuerza bruta) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && !isset($_POST['action'])) {
    if (!rateLimitPermitir('estado_login')) {
        $_SESSION['login_error'] = true;
        header('Location: estado.php');
        exit;
    }

    if (!csrfValido()) {
        $_SESSION['login_error'] = true;
        header('Location: estado.php');
        exit;
    }

    $passwordOk = false;
    if (ADMIN_PASSWORD_HASH === '' && ADMIN_PASSWORD === '') {
        // Sin contraseña configurada: el panel permanece bloqueado
        $passwordOk = false;
    } elseif (ADMIN_PASSWORD_HASH !== '') {
        $passwordOk = password_verify($_POST['password'], ADMIN_PASSWORD_HASH);
    } else {
        $passwordOk = hash_equals(ADMIN_PASSWORD, $_POST['password']);
    }

    if ($passwordOk) {
        // Regenerar el ID de sesión al loguearse: evita session fixation
        // (que alguien fije de antemano un session id y lo use ya logueado)
        session_regenerate_id(true);
        $_SESSION['codementor_admin'] = true;
        unset($_SESSION['csrf_token']); // se regenera uno nuevo para la sesión ya autenticada
    } else {
        $_SESSION['login_error'] = true;
    }
    header('Location: estado.php');
    exit;
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: estado.php');
    exit;
}

$autenticado = $_SESSION['codementor_admin'] ?? false;

// ── Servir el binario de una imagen cacheada (GET, solo admin) ──
// La carpeta imagenes_generadas/ tiene "Require all denied" en su
// .htaccess, así que no se puede linkear directo: esta ruta la sirve
// leyendo el archivo desde PHP, reutilizando la sesión ya logueada.
if (isset($_GET['imagen'])) {
    if (!$autenticado) { http_response_code(403); exit; }

    // Solo aceptamos la clave tal cual la genera md5() (32 hex): así
    // basename()/path traversal quedan descartados de entrada, no solo
    // "limpiados".
    $clave = $_GET['imagen'];
    if (!preg_match('/^[a-f0-9]{32}$/', $clave)) { http_response_code(404); exit; }

    $rutaBin  = CACHE_IMAGENES_DIR . $clave . '.bin';
    $rutaMeta = CACHE_IMAGENES_DIR . $clave . '.json';
    if (!is_file($rutaBin) || !is_file($rutaMeta)) { http_response_code(404); exit; }

    $meta = json_decode(file_get_contents($rutaMeta), true);
    header('Content-Type: ' . ($meta['mime'] ?? 'image/png'));
    header('Content-Length: ' . filesize($rutaBin));
    header('Cache-Control: private, max-age=3600');
    readfile($rutaBin);
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Todas las acciones POST (incluida eliminar) requieren sesión autenticada
// Y un token CSRF válido, para que un sitio externo no pueda disparar
// un borrado con la sesión ya logueada del admin (cross-site request forgery).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!$autenticado) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['status' => 'error', 'msg' => 'No autenticado']);
        exit;
    }
    if (!csrfValido()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['status' => 'error', 'msg' => 'Token de seguridad inválido, recargá la página.']);
        exit;
    }
}

// Si no está autenticado, mostrar solo el formulario de login
if (!$autenticado) {
    $errorLogin = isset($_SESSION['login_error']);
    unset($_SESSION['login_error']);
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CodeMentor — Acceso</title>
    <style>
      * { box-sizing: border-box; margin: 0; padding: 0; }
      body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        background: #1a1a1a; color: #ececec;
        min-height: 100vh; display: flex; align-items: center; justify-content: center;
      }
      .login-box {
        background: #2a2a2a; border: 1px solid #333; border-radius: 14px;
        padding: 32px; width: 90%; max-width: 340px; text-align: center;
      }
      .login-box .icono { font-size: 32px; margin-bottom: 12px; }
      .login-box h1 { font-size: 15px; font-weight: 600; margin-bottom: 20px; }
      .login-box input {
        width: 100%; background: #1e1e1e; border: 1px solid #3a3a3a;
        border-radius: 10px; color: #ececec; font-size: 14px;
        padding: 10px 14px; outline: none; margin-bottom: 12px;
      }
      .login-box input:focus { border-color: #666; }
      .login-box button {
        width: 100%; background: #ececec; color: #212121; border: none;
        border-radius: 10px; padding: 10px; cursor: pointer;
        font-size: 14px; font-weight: 600;
      }
      .login-box .error { color: #e74c3c; font-size: 12px; margin-bottom: 12px; }
    </style>
    </head>
    <body>
      <div class="login-box">
        <div class="icono">❄</div>
        <h1>Acceso al panel de CodeMentor</h1>
        <?php if ($errorLogin): ?>
          <div class="error">Contraseña incorrecta</div>
        <?php endif; ?>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="password" name="password" placeholder="Contraseña" autofocus required>
          <button type="submit">Entrar</button>
        </form>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// Acciones del panel (ya validamos autenticación + CSRF arriba)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $accionPanel = $_POST['action'];

    // ── Conocimiento: eliminar un ítem ──────────────────────────
    if ($accionPanel === 'eliminar') {
        $archivo = __DIR__ . '/conocimiento_data/' . basename($_POST['archivo'] ?? '');
        $indice  = (int) ($_POST['indice'] ?? -1);

        if (!file_exists($archivo) || $indice < 0) {
            echo json_encode(['status' => 'error', 'msg' => 'Parámetros inválidos']);
            exit;
        }

        $esJsonl = substr($archivo, -6) === '.jsonl';

        if ($esJsonl) {
            $lineas = file($archivo, FILE_IGNORE_NEW_LINES);
            if ($lineas === false) $lineas = [];
            // Ignorar líneas vacías para que el índice coincida con el que ve el usuario
            $lineas = array_values(array_filter($lineas, fn($l) => trim($l) !== ''));

            if (!isset($lineas[$indice])) {
                echo json_encode(['status' => 'error', 'msg' => 'Ítem no encontrado']);
                exit;
            }

            array_splice($lineas, $indice, 1);
            $contenido = '';
            foreach ($lineas as $l) {
                $contenido .= $l . "\n";
            }
            file_put_contents($archivo, $contenido, LOCK_EX);
        } else {
            $items = json_decode(file_get_contents($archivo), true);
            if (!is_array($items) || !isset($items[$indice])) {
                echo json_encode(['status' => 'error', 'msg' => 'Ítem no encontrado']);
                exit;
            }

            array_splice($items, $indice, 1);
            file_put_contents($archivo, json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        echo json_encode(['status' => 'ok']);
        exit;
    }

    // ── Conocimiento: editar categoría / palabras clave de un ítem ──
    if ($accionPanel === 'editar_campos') {
        $archivo = __DIR__ . '/conocimiento_data/' . basename($_POST['archivo'] ?? '');
        // También se permite el legado conocimiento.json, que vive un
        // nivel arriba de conocimiento_data (ver lectura más abajo).
        if (($_POST['archivo'] ?? '') === 'conocimiento.json') {
            $archivo = __DIR__ . '/conocimiento.json';
        }
        $indice  = (int) ($_POST['indice'] ?? -1);
        // Límite generoso pero acotado: son campos cortos, no texto libre largo.
        $categoria      = mb_substr(trim((string) ($_POST['categoria']      ?? '')), 0, 60,  'UTF-8');
        $palabrasClave  = mb_substr(trim((string) ($_POST['palabras_clave'] ?? '')), 0, 300, 'UTF-8');
        $nivelesValidos = ['', 'basico', 'intermedio', 'avanzado'];
        $nivelDificultad = trim((string) ($_POST['nivel_dificultad'] ?? ''));
        if (!in_array($nivelDificultad, $nivelesValidos, true)) $nivelDificultad = '';
        $estadosValidos = ['borrador', 'publicado', 'archivado'];
        $estadoItem = trim((string) ($_POST['estado'] ?? ''));
        if (!in_array($estadoItem, $estadosValidos, true)) $estadoItem = 'publicado';

        if (!file_exists($archivo) || $indice < 0) {
            echo json_encode(['status' => 'error', 'msg' => 'Parámetros inválidos']);
            exit;
        }

        $esJsonl = substr($archivo, -6) === '.jsonl';

        if ($esJsonl) {
            $lineas = file($archivo, FILE_IGNORE_NEW_LINES);
            if ($lineas === false) $lineas = [];
            $lineas = array_values(array_filter($lineas, fn($l) => trim($l) !== ''));

            if (!isset($lineas[$indice])) {
                echo json_encode(['status' => 'error', 'msg' => 'Ítem no encontrado']);
                exit;
            }
            $item = json_decode($lineas[$indice], true);
            if (!is_array($item)) {
                echo json_encode(['status' => 'error', 'msg' => 'Ítem inválido']);
                exit;
            }
            $item['categoria']         = $categoria;
            $item['palabras_clave']    = $palabrasClave;
            $item['nivel_dificultad']  = $nivelDificultad;
            $item['estado']            = $estadoItem;
            $item['fecha_actualizacion'] = date("Y-m-d H:i:s");
            $lineas[$indice] = json_encode($item, JSON_UNESCAPED_UNICODE);

            $contenido = '';
            foreach ($lineas as $l) $contenido .= $l . "\n";
            $ok = file_put_contents($archivo, $contenido, LOCK_EX) !== false;
        } else {
            $items = json_decode(file_get_contents($archivo), true);
            if (!is_array($items) || !isset($items[$indice])) {
                echo json_encode(['status' => 'error', 'msg' => 'Ítem no encontrado']);
                exit;
            }
            $items[$indice]['categoria']           = $categoria;
            $items[$indice]['palabras_clave']      = $palabrasClave;
            $items[$indice]['nivel_dificultad']    = $nivelDificultad;
            $items[$indice]['estado']              = $estadoItem;
            $items[$indice]['fecha_actualizacion'] = date("Y-m-d H:i:s");
            $ok = file_put_contents($archivo, json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
        }

        if (!$ok) {
            echo json_encode(['status' => 'error', 'msg' => 'No se pudo escribir el archivo (revisar permisos/espacio).']);
            exit;
        }
        echo json_encode([
            'status'              => 'ok',
            'categoria'           => $categoria,
            'palabras_clave'      => $palabrasClave,
            'nivel_dificultad'    => $nivelDificultad,
            'estado'              => $estadoItem,
        ]);
        exit;
    }

    // ── Conocimiento: agregar un ítem nuevo a mano desde el panel ──
    if ($accionPanel === 'agregar_conocimiento') {
        $limpiar = function($clave, $max) {
            return mb_substr(trim((string) ($_POST[$clave] ?? '')), 0, $max, 'UTF-8');
        };
        $pregunta        = $limpiar('pregunta', 500);
        $concepto        = $limpiar('concepto', 200);
        $categoria       = $limpiar('categoria', 60);
        $descripcion     = $limpiar('descripcion', 5000);
        $sintaxis        = $limpiar('sintaxis', 3000);
        $ejemplo         = $limpiar('ejemplo', 3000);
        $errores         = $limpiar('errores', 3000);
        $buenasPracticas = $limpiar('buenas_practicas', 3000);
        $palabrasClave   = $limpiar('palabras_clave', 300);

        $nivelesValidos  = ['', 'basico', 'intermedio', 'avanzado'];
        $nivelDificultad = trim((string) ($_POST['nivel_dificultad'] ?? ''));
        if (!in_array($nivelDificultad, $nivelesValidos, true)) $nivelDificultad = '';

        $estadosValidos = ['borrador', 'publicado', 'archivado'];
        $estadoItem = trim((string) ($_POST['estado'] ?? 'publicado'));
        if (!in_array($estadoItem, $estadosValidos, true)) $estadoItem = 'publicado';

        $forzar = !empty($_POST['forzar']);

        if ($pregunta === '' || $concepto === '' || $descripcion === '') {
            echo json_encode(['status' => 'error', 'msg' => 'Pregunta, concepto y descripción son obligatorios.']);
            exit;
        }

        $archivoConocimiento = $carpetaConocimiento . "/conocimiento-" . date("Y-m") . ".jsonl";

        $resultado = conLockDeGuardado($carpetaConocimiento, function () use (
            $pregunta, $concepto, $categoria, $descripcion, $sintaxis, $ejemplo,
            $errores, $buenasPracticas, $palabrasClave, $nivelDificultad, $estadoItem,
            $forzar, $carpetaConocimiento, $archivoConocimiento
        ) {
            // A diferencia del guardado automático (que reemplaza entradas
            // viejas si el duplicado pasó DIAS_PARA_REFRESCAR), acá se deja
            // en manos del admin: se avisa y, si confirma, se guarda igual
            // como entrada nueva — nunca se pisa la existente sola.
            if (!$forzar) {
                $existente = conceptoExiste($concepto, $pregunta, $carpetaConocimiento);
                if ($existente) {
                    $cuando = $existente['fecha'] ? ' (guardado el ' . substr($existente['fecha'], 0, 10) . ')' : '';
                    return [
                        'status' => 'duplicado',
                        'msg'    => "Ya existe un concepto parecido{$cuando}. ¿Guardar igual?",
                    ];
                }
            }

            $entrada = [
                "pregunta"            => $pregunta,
                "concepto"            => $concepto,
                "categoria"           => $categoria,
                "descripcion"         => $descripcion,
                "sintaxis"            => $sintaxis,
                "ejemplo"             => $ejemplo,
                "errores"             => $errores,
                "buenas_practicas"    => $buenasPracticas,
                "palabras_clave"      => $palabrasClave,
                "nivel_dificultad"    => $nivelDificultad,
                "estado"              => $estadoItem,
                "_fecha"              => date("Y-m-d H:i:s"),
                "_origen"             => "manual_panel",
                "_pregunta_original"  => $pregunta,
                "fecha_actualizacion" => date("Y-m-d H:i:s"),
            ];

            $ok = guardarItem($entrada, $archivoConocimiento);
            if (!$ok) {
                return ['status' => 'error', 'msg' => 'No se pudo escribir el archivo de conocimiento (revisar permisos/espacio en el servidor).'];
            }
            logAuto("OK — agregado a mano desde el panel: " . $concepto);
            return ['status' => 'ok', 'concepto' => $concepto];
        });

        echo json_encode($resultado);
        exit;
    }

    // ── Configuración: guardar parámetros ajustables del matching ──
    if ($accionPanel === 'guardar_config') {
        if (!empty($_POST['restaurar'])) {
            $datos = configCodeMentorPorDefecto();
        } else {
            $umbral        = (float) ($_POST['umbral_aproximado'] ?? 0.60);
            $peso          = (float) ($_POST['peso_jaccard']      ?? 0.70);
            $maximo        = (int)   ($_POST['maximo_resultados'] ?? 5);
            $stopEx        = mb_substr(trim((string) ($_POST['stopwords_extra'] ?? '')), 0, 500, 'UTF-8');
            $umbralEvitarIa = (float) ($_POST['umbral_evitar_ia'] ?? 0.60);

            // Nunca confiar en que el cliente mandó algo dentro de rango,
            // aunque el <input> del panel ya tenga min/max/step puestos.
            if ($umbral < 0)  $umbral = 0;
            if ($umbral > 1)  $umbral = 1;
            if ($peso < 0)    $peso = 0;
            if ($peso > 1)    $peso = 1;
            if ($maximo < 1)  $maximo = 1;
            if ($maximo > 20) $maximo = 20;
            if ($umbralEvitarIa < 0) $umbralEvitarIa = 0;
            if ($umbralEvitarIa > 1) $umbralEvitarIa = 1;

            $datos = [
                'umbral_aproximado' => round($umbral, 2),
                'peso_jaccard'      => round($peso, 2),
                'maximo_resultados' => $maximo,
                'stopwords_extra'   => $stopEx,
                'umbral_evitar_ia'  => round($umbralEvitarIa, 2),
            ];
        }

        $ok = guardarConfigCodeMentor($datos);
        if (!$ok) {
            echo json_encode(['status' => 'error', 'msg' => 'No se pudo escribir config_codementor.json (revisar permisos/espacio en el servidor).']);
            exit;
        }
        echo json_encode(['status' => 'ok', 'config' => cargarConfigCodeMentor()]);
        exit;
    }

    // ── Pendientes: reintentar guardado automático (Gemini/Groq) ──
    if ($accionPanel === 'reintentar_pendiente') {
        $indice = (int) ($_POST['indice'] ?? -1);
        $item = null;
        foreach (listarPendientes($carpetaConocimiento) as $p) {
            if ($p['_indice'] === $indice) { $item = $p; break; }
        }
        if ($item === null) {
            echo json_encode(['status' => 'error', 'msg' => 'Ítem no encontrado (puede que ya se haya procesado)']);
            exit;
        }

        @set_time_limit(60);
        $resultado = ejecutarGuardadoAutomatico($item['pregunta'], $item['respuesta'], $carpetaConocimiento);
        if (in_array($resultado['status'], ['ok', 'skip'], true)) {
            eliminarPendiente($carpetaConocimiento, $indice);
        }
        echo json_encode($resultado);
        exit;
    }

    // ── Pendientes: guardar tal cual, sin pasar por Gemini/Groq ──
    if ($accionPanel === 'guardar_pendiente_directo') {
        $indice = (int) ($_POST['indice'] ?? -1);
        $item = null;
        foreach (listarPendientes($carpetaConocimiento) as $p) {
            if ($p['_indice'] === $indice) { $item = $p; break; }
        }
        if ($item === null) {
            echo json_encode(['status' => 'error', 'msg' => 'Ítem no encontrado (puede que ya se haya procesado)']);
            exit;
        }

        $conceptoManual = trim($_POST['concepto'] ?? '');
        if ($conceptoManual === '') $conceptoManual = mb_substr($item['pregunta'], 0, 80);

        $entradaPendiente = [
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
        $archivoDestino = $carpetaConocimiento . "/conocimiento-" . date("Y-m") . ".jsonl";

        $resultado = conLockDeGuardado($carpetaConocimiento, function () use ($entradaPendiente, $carpetaConocimiento, $archivoDestino) {
            $existente = conceptoExiste($entradaPendiente["concepto"], $entradaPendiente["pregunta"], $carpetaConocimiento);
            if ($existente) {
                return ["status" => "skip", "msg" => "Ya existe una entrada equivalente para: " . $entradaPendiente["concepto"]];
            }
            $ok = guardarItem($entradaPendiente, $archivoDestino);
            if (!$ok) {
                return ["status" => "error", "msg" => "No se pudo escribir el archivo de conocimiento (revisar permisos/espacio en el servidor)."];
            }
            return ["status" => "ok", "concepto" => $entradaPendiente["concepto"]];
        });

        if ($resultado['status'] === 'ok') {
            eliminarPendiente($carpetaConocimiento, $indice);
            logAuto("OK — guardado a mano desde el panel (pendientes): " . $entradaPendiente["concepto"]);
        }
        echo json_encode($resultado);
        exit;
    }

    // ── Pendientes: descartar (no se guarda, se borra de la cola) ──
    if ($accionPanel === 'descartar_pendiente') {
        $indice = (int) ($_POST['indice'] ?? -1);
        $ok = eliminarPendiente($carpetaConocimiento, $indice);
        echo json_encode($ok ? ['status' => 'ok'] : ['status' => 'error', 'msg' => 'No se pudo eliminar']);
        exit;
    }

    // ── Usuarios: listar los chats de un usuario ────────────────
    if ($accionPanel === 'chats_usuario') {
        $usuarioId = (int) ($_POST['usuario_id'] ?? 0);
        if ($usuarioId <= 0) { echo json_encode(['status' => 'error', 'msg' => 'Falta usuario_id']); exit; }

        try {
            $pdo = conectar();
            $stmt = $pdo->prepare(
                'SELECT c.id, c.titulo, c.actualizado_en, COUNT(m.id) AS total_mensajes
                 FROM chats c LEFT JOIN mensajes m ON m.chat_id = c.id
                 WHERE c.usuario_id = ?
                 GROUP BY c.id ORDER BY c.actualizado_en DESC'
            );
            $stmt->execute([$usuarioId]);
            echo json_encode(['status' => 'ok', 'chats' => $stmt->fetchAll()]);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'msg' => 'Error de base de datos']);
        }
        exit;
    }

    // ── Usuarios: ver los mensajes de un chat puntual ───────────
    if ($accionPanel === 'mensajes_chat') {
        $chatId = (int) ($_POST['chat_id'] ?? 0);
        if ($chatId <= 0) { echo json_encode(['status' => 'error', 'msg' => 'Falta chat_id']); exit; }

        try {
            $pdo = conectar();
            $stmt = $pdo->prepare('SELECT rol, contenido, creado_en FROM mensajes WHERE chat_id = ? ORDER BY id ASC');
            $stmt->execute([$chatId]);
            echo json_encode(['status' => 'ok', 'mensajes' => $stmt->fetchAll()]);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'msg' => 'Error de base de datos']);
        }
        exit;
    }

    // ── Usuarios: eliminar un chat puntual ──────────────────────
    if ($accionPanel === 'eliminar_chat') {
        $chatId = (int) ($_POST['chat_id'] ?? 0);
        if ($chatId <= 0) { echo json_encode(['status' => 'error', 'msg' => 'Falta chat_id']); exit; }

        try {
            $pdo = conectar();
            $stmt = $pdo->prepare('DELETE FROM chats WHERE id = ?');
            $stmt->execute([$chatId]);
            echo json_encode(['status' => 'ok']);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'msg' => 'Error de base de datos']);
        }
        exit;
    }

    // ── Usuarios: eliminar una cuenta (y sus chats/mensajes, por CASCADE) ──
    if ($accionPanel === 'eliminar_usuario') {
        $usuarioId = (int) ($_POST['usuario_id'] ?? 0);
        if ($usuarioId <= 0) { echo json_encode(['status' => 'error', 'msg' => 'Falta usuario_id']); exit; }

        try {
            $pdo = conectar();
            $stmt = $pdo->prepare('DELETE FROM usuarios WHERE id = ?');
            $stmt->execute([$usuarioId]);
            echo json_encode(['status' => 'ok']);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'error', 'msg' => 'Error de base de datos']);
        }
        exit;
    }

    // ── Imágenes: eliminar una imagen del caché ─────────────────
    if ($accionPanel === 'eliminar_imagen') {
        $clave = (string) ($_POST['clave'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $clave)) {
            echo json_encode(['status' => 'error', 'msg' => 'Parámetros inválidos']);
            exit;
        }
        $rutaBin  = CACHE_IMAGENES_DIR . $clave . '.bin';
        $rutaMeta = CACHE_IMAGENES_DIR . $clave . '.json';
        if (!is_file($rutaBin) && !is_file($rutaMeta)) {
            echo json_encode(['status' => 'error', 'msg' => 'Imagen no encontrada']);
            exit;
        }
        $ok = true;
        if (is_file($rutaBin))  $ok = @unlink($rutaBin)  && $ok;
        if (is_file($rutaMeta)) $ok = @unlink($rutaMeta) && $ok;

        echo json_encode($ok
            ? ['status' => 'ok']
            : ['status' => 'error', 'msg' => 'No se pudo eliminar (revisar permisos)']);
        exit;
    }

    // ── Imágenes: editar la descripción/término guardado ────────
    // Solo cambia la etiqueta que se muestra en el panel; la clave del
    // caché (el nombre de archivo) queda igual, así que esto no rompe
    // el "match" con futuros pedidos de generar_imagen.php/buscar_imagenes.php.
    if ($accionPanel === 'editar_imagen_termino') {
        $clave = (string) ($_POST['clave'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $clave)) {
            echo json_encode(['status' => 'error', 'msg' => 'Parámetros inválidos']);
            exit;
        }
        $rutaMeta = CACHE_IMAGENES_DIR . $clave . '.json';
        if (!is_file($rutaMeta)) {
            echo json_encode(['status' => 'error', 'msg' => 'Imagen no encontrada']);
            exit;
        }
        $terminoNuevo = mb_substr(trim((string) ($_POST['termino'] ?? '')), 0, 300, 'UTF-8');
        if ($terminoNuevo === '') {
            echo json_encode(['status' => 'error', 'msg' => 'La descripción no puede quedar vacía']);
            exit;
        }
        $meta = json_decode(file_get_contents($rutaMeta), true);
        if (!is_array($meta)) $meta = [];
        $meta['termino'] = $terminoNuevo;
        $ok = file_put_contents($rutaMeta, json_encode($meta, JSON_UNESCAPED_UNICODE)) !== false;

        echo json_encode($ok
            ? ['status' => 'ok', 'termino' => $terminoNuevo]
            : ['status' => 'error', 'msg' => 'No se pudo guardar (revisar permisos)']);
        exit;
    }

    // ── Entrenamiento automático: ejecutar una ronda a mano ──────
    if ($accionPanel === 'ejecutar_entrenamiento_ahora') {
        // Puede tardar bastante (Gemini/Groq para responder + para
        // estructurar la respuesta), así que se estira el límite de
        // tiempo solo para esta acción puntual.
        @set_time_limit(100);
        // false = no respetar el enfriamiento de 10 min: el admin lo
        // está pidiendo a propósito desde el panel.
        $resultado = ejecutarRondaEntrenamiento($carpetaConocimiento, false);
        echo json_encode(array_merge(['ok_general' => true], $resultado), JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['status' => 'error', 'msg' => 'Acción inválida']);
    exit;
}

// ── Pendientes: respuestas que fallaron al estructurarse (Gemini
// y Groq fallaron los dos) y quedaron guardadas completas para
// poder reintentar, guardar a mano o descartar. Antes, esto se
// perdía para siempre: el log solo anotaba la pregunta y un conteo
// de palabras, nunca la respuesta completa.
$pendientes = array_reverse(listarPendientes($carpetaConocimiento));

// ── Leer todos los archivos de conocimiento_data ─────────────
$carpeta  = __DIR__ . '/conocimiento_data';
$archivos = array_merge(
    glob($carpeta . '/*.json') ?: [],
    glob($carpeta . '/*.jsonl') ?: []
);

$porArchivo = [];
$totalGlobal = 0;
$totalAuto   = 0;
$totalManual = 0;
// Guardamos la fecha más reciente que tiene cada archivo, para poder
// ordenar las secciones por contenido real y no por el nombre del
// archivo (ver uksort más abajo).
$fechaMaxPorArchivo = [];

foreach ($archivos as $f) {
    $nombre  = basename($f);
    $esJsonl = substr($f, -6) === '.jsonl';

    if ($esJsonl) {
        $items  = [];
        $lineas = file($f, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lineas as $linea) {
            $linea = trim($linea);
            if ($linea === '') continue;
            $item = json_decode($linea, true);
            if (is_array($item)) $items[] = $item;
        }
    } else {
        $items = json_decode(file_get_contents($f), true);
    }

    if (!is_array($items) || empty($items)) continue;
    $porArchivo[$nombre] = $items;
    $totalGlobal += count($items);

    $fechaMax = '';
    foreach ($items as $it) {
        if (($it['_origen'] ?? '') === 'auto') $totalAuto++;
        else $totalManual++;
        $fechaItem = $it['_fecha'] ?? '';
        if ($fechaItem > $fechaMax) $fechaMax = $fechaItem;
    }
    $fechaMaxPorArchivo[$nombre] = $fechaMax;
}

// Ordenar las secciones (archivos) por la fecha más reciente que
// contiene cada una, de más nueva a más vieja. Así, en cuanto se crea
// el .jsonl de un mes nuevo y tiene al menos un ítem, su sección sube
// arriba de todo automáticamente — sin depender de que el nombre del
// archivo ordene bien alfabéticamente.
uksort($porArchivo, function($a, $b) use ($fechaMaxPorArchivo) {
    return strcmp($fechaMaxPorArchivo[$b] ?? '', $fechaMaxPorArchivo[$a] ?? '');
});

// Leer también conocimiento.json legado: se agrega AL FINAL para que
// quede abajo de todo, ya que los archivos con fecha de arriba son
// siempre más nuevos que este archivo legado.
if (file_exists(__DIR__ . '/conocimiento.json')) {
    $items = json_decode(file_get_contents(__DIR__ . '/conocimiento.json'), true);
    if (is_array($items)) {
        $porArchivo['conocimiento.json'] = $items;
        $totalGlobal += count($items);
        $totalManual += count($items);
    }
}
// ── Leer usuarios registrados (login/registro) y sus totales ────
$usuariosDb = [];
$errorUsuarios = null;
try {
    $pdo = conectar();
    $stmt = $pdo->query(
        'SELECT u.id, u.nombre, u.email, u.creado_en,
                COUNT(DISTINCT c.id) AS total_chats,
                COUNT(m.id) AS total_mensajes
         FROM usuarios u
         LEFT JOIN chats c ON c.usuario_id = u.id
         LEFT JOIN mensajes m ON m.chat_id = c.id
         GROUP BY u.id, u.nombre, u.email, u.creado_en
         ORDER BY u.creado_en DESC'
    );
    $usuariosDb = $stmt->fetchAll();
} catch (Throwable $e) {
    $errorUsuarios = 'No se pudo conectar a la base de datos.';
}

// ── Leer imágenes generadas (caché compartido: IA + búsqueda) ───
$imagenes = [];
if (is_dir(CACHE_IMAGENES_DIR)) {
    foreach ((glob(CACHE_IMAGENES_DIR . '*.json') ?: []) as $rutaMeta) {
        $clave   = basename($rutaMeta, '.json');
        $rutaBin = CACHE_IMAGENES_DIR . $clave . '.bin';
        if (!is_file($rutaBin)) continue; // metadata huérfana sin binario

        $meta = json_decode(file_get_contents($rutaMeta), true);
        if (!is_array($meta)) continue;

        $imagenes[] = [
            'clave'   => $clave,
            'termino' => $meta['termino'] ?? '(sin descripción)',
            'motor'   => $meta['motor']   ?? 'desconocido',
            'fecha'   => $meta['fecha']   ?? '',
            'peso'    => filesize($rutaBin),
        ];
    }
    // Más recientes primero
    usort($imagenes, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
}

// ── Progreso y log del entrenamiento automático ──────────────
$progresoEntrenamiento = progresoEntrenamiento($carpetaConocimiento);
$configCodeMentor = cargarConfigCodeMentor();
$logEntrenamiento      = ultimasLineasEntrenamiento(40);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CodeMentor — Panel de conocimiento</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  background: #1a1a1a;
  color: #ececec;
  min-height: 100vh;
}

/* ── HEADER ── */
.header {
  background: #212121;
  border-bottom: 1px solid #333;
  padding: 16px 24px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  position: sticky;
  top: 0;
  z-index: 10;
}
.header-titulo {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 16px;
  font-weight: 600;
}
.header-titulo span { font-size: 22px; }
.header-volver {
  font-size: 13px;
  color: #888;
  text-decoration: none;
  padding: 6px 14px;
  border: 1px solid #444;
  border-radius: 8px;
  transition: border-color 0.2s, color 0.2s;
}
.header-volver:hover { border-color: #666; color: #ececec; }

/* ── STATS ── */
.stats {
  display: flex;
  gap: 12px;
  padding: 20px 24px;
  flex-wrap: wrap;
}
.stat {
  background: #2a2a2a;
  border: 1px solid #333;
  border-radius: 12px;
  padding: 14px 20px;
  flex: 1;
  min-width: 120px;
}
.stat-numero {
  font-size: 28px;
  font-weight: 700;
  color: #ececec;
  line-height: 1;
}
.stat-label {
  font-size: 12px;
  color: #666;
  margin-top: 4px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.stat.auto   .stat-numero { color: #5dade2; }
.stat.manual .stat-numero { color: #a8d8a8; }

/* ── CONTENIDO ── */
.contenido { padding: 0 24px 40px; }

/* ── ARCHIVO SECCIÓN ── */
.seccion {
  margin-bottom: 32px;
}
.seccion-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
  padding-bottom: 8px;
  border-bottom: 1px solid #2a2a2a;
}
.seccion-titulo {
  font-size: 13px;
  font-weight: 600;
  color: #888;
  text-transform: uppercase;
  letter-spacing: 0.6px;
}
.seccion-count {
  font-size: 12px;
  color: #555;
}

/* ── CARDS ── */
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 12px;
}

.card {
  background: #2a2a2a;
  border: 1px solid #333;
  border-radius: 12px;
  padding: 14px 16px;
  position: relative;
  transition: border-color 0.2s;
}
.card:hover { border-color: #444; }
.card.auto   { border-left: 3px solid #2e6a8e; }
.card.manual { border-left: 3px solid #3a7a4a; }

.card-concepto {
  font-size: 14px;
  font-weight: 600;
  color: #ececec;
  margin-bottom: 6px;
  padding-right: 60px;
}
.card-descripcion {
  font-size: 13px;
  color: #999;
  line-height: 1.5;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.card-meta {
  margin-top: 10px;
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.badge {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.5px;
  text-transform: uppercase;
  padding: 2px 8px;
  border-radius: 20px;
}
.badge.auto   { background: #1a3a50; color: #5dade2; }
.badge.manual { background: #1a3a20; color: #a8d8a8; }
.card-fecha {
  font-size: 11px;
  color: #555;
}
.card-pregunta-orig {
  font-size: 11px;
  color: #555;
  font-style: italic;
  margin-top: 4px;
  display: -webkit-box;
  -webkit-line-clamp: 1;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* Botón eliminar */
.btn-eliminar {
  position: absolute;
  top: 10px;
  right: 10px;
  width: 26px;
  height: 26px;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 6px;
  color: #555;
  font-size: 14px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s, color 0.15s, border-color 0.15s;
  line-height: 1;
}
.btn-eliminar:hover {
  background: #3a1a1a;
  border-color: #7a3a3a;
  color: #e74c3c;
}

/* Botón editar categoría/palabras clave */
.btn-editar-campos {
  position: absolute;
  top: 10px;
  right: 40px;
  width: 26px;
  height: 26px;
  background: transparent;
  border: 1px solid transparent;
  border-radius: 6px;
  color: #555;
  font-size: 13px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s, color 0.15s, border-color 0.15s;
  line-height: 1;
}
.btn-editar-campos:hover {
  background: #1a2a3a;
  border-color: #3a5a7a;
  color: #5dade2;
}

/* Badge de categoría */
.badge-categoria {
  font-size: 10px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 20px;
  background: #3a2a1a;
  color: #e0a85d;
}

/* Palabras clave (tags) */
.card-palabras-clave {
  margin-top: 8px;
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
}
.tag-clave {
  font-size: 10.5px;
  color: #999;
  background: #222;
  border: 1px solid #383838;
  border-radius: 6px;
  padding: 2px 7px;
}

/* Edición inline de categoría / palabras clave */
.card-edicion-campos {
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px dashed #3a3a3a;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.card-edicion-campos input {
  width: 100%;
  background: #1e1e1e;
  border: 1px solid #3a3a3a;
  border-radius: 8px;
  color: #ececec;
  font-size: 12.5px;
  padding: 7px 10px;
  outline: none;
}
.card-edicion-campos input:focus { border-color: #5dade2; }
.edicion-botones {
  display: flex;
  gap: 8px;
  margin-top: 2px;
}
.btn-guardar-campos, .btn-cancelar-campos {
  flex: 1;
  border-radius: 8px;
  border: none;
  padding: 7px 0;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}
.btn-guardar-campos { background: #2e6a8e; color: #ececec; }
.btn-guardar-campos:hover { background: #35789e; }
.btn-guardar-campos:disabled { opacity: 0.6; cursor: default; }
.btn-cancelar-campos { background: #333; color: #bbb; }
.btn-cancelar-campos:hover { background: #3a3a3a; }

/* Toast notificación */
#toast {
  position: fixed;
  bottom: 24px;
  left: 50%;
  transform: translateX(-50%) translateY(20px);
  background: #2a2a2a;
  border: 1px solid #444;
  border-radius: 10px;
  padding: 10px 20px;
  font-size: 13px;
  color: #ececec;
  opacity: 0;
  transition: opacity 0.2s, transform 0.2s;
  pointer-events: none;
  z-index: 100;
}
#toast.visible {
  opacity: 1;
  transform: translateX(-50%) translateY(0);
}

/* Empty state */
.empty {
  text-align: center;
  padding: 60px 20px;
  color: #555;
  font-size: 14px;
}
.empty .icono { font-size: 40px; margin-bottom: 12px; }

/* ── BUSCADOR ── */
.buscador-wrap { padding: 16px 24px 0; }
.buscador-caja { position: relative; max-width: 420px; }
.buscador-caja input {
  width: 100%; background: #2a2a2a; border: 1px solid #333;
  border-radius: 10px; color: #ececec; font-size: 13.5px;
  padding: 10px 36px 10px 14px; outline: none; font-family: inherit;
}
.buscador-caja input:focus { border-color: #5dade2; }
.buscador-caja input::placeholder { color: #666; }
.buscador-limpiar {
  position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
  background: none; border: none; color: #666; cursor: pointer;
  font-size: 15px; padding: 4px 6px; display: none; line-height: 1;
}
.buscador-limpiar:hover { color: #ececec; }
.buscador-resultados { font-size: 12px; color: #666; margin-top: 6px; }
mark { background: #5dade2; color: #10202c; border-radius: 3px; padding: 0 1px; }
.seccion.oculta-busqueda, .card.oculta-busqueda { display: none !important; }

/* ── TABS ── */
.tabs {
  display: flex; gap: 4px;
  padding: 0 24px; margin-top: 4px;
  border-bottom: 1px solid #2a2a2a;
}
.tab-btn {
  background: none; border: none; cursor: pointer;
  color: #888; font-size: 13.5px; font-weight: 600;
  padding: 12px 16px; border-bottom: 2px solid transparent;
  font-family: inherit;
}
.tab-btn.activo { color: #ececec; border-bottom-color: #5dade2; }
.tab-panel { display: none; }
.tab-panel.activo { display: block; }

/* ── USUARIOS ── */
.usuarios-lista { padding: 20px 24px 40px; display: flex; flex-direction: column; gap: 10px; }
.usuario-card {
  background: #2a2a2a; border: 1px solid #333; border-radius: 12px;
  overflow: hidden;
}
.usuario-header {
  display: flex; align-items: center; justify-content: space-between;
  gap: 10px; padding: 14px 16px; cursor: pointer;
}
.usuario-info { min-width: 0; }
.usuario-nombre { font-size: 14px; font-weight: 600; color: #ececec; }
.usuario-email { font-size: 12px; color: #888; margin-top: 2px; }
.usuario-fecha { font-size: 11px; color: #555; margin-top: 2px; }
.usuario-stats { display: flex; gap: 8px; flex-shrink: 0; align-items: center; }
.usuario-badge {
  font-size: 11px; color: #a8b8c8; background: #1e2a38;
  border-radius: 20px; padding: 3px 10px; white-space: nowrap;
}
.usuario-header .btn-eliminar { position: static; flex-shrink: 0; }
.usuario-chats {
  display: none; border-top: 1px solid #333; padding: 10px 16px 14px;
  background: #232323;
}
.usuario-chats.abierto { display: block; }
.chat-item {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  padding: 9px 10px; border-radius: 8px; cursor: pointer; font-size: 13px;
}
.chat-item:hover { background: #2c2c2c; }
.chat-item .chat-titulo { color: #ececec; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.chat-item .chat-meta { font-size: 11px; color: #666; flex-shrink: 0; }
.chat-mensajes {
  display: none; margin: 6px 0 10px; padding: 10px 12px;
  background: #1e1e1e; border: 1px solid #333; border-radius: 8px;
  max-height: 320px; overflow-y: auto;
}
.chat-mensajes.abierto { display: block; }
.msg-item { padding: 6px 0; font-size: 12.5px; line-height: 1.5; border-bottom: 1px solid #2a2a2a; }
.msg-item:last-child { border-bottom: none; }
.msg-rol { font-weight: 600; margin-right: 6px; }
.msg-rol.user { color: #5dade2; }
.msg-rol.bot { color: #a8d8a8; }
.msg-fecha { color: #555; font-size: 10.5px; margin-left: 6px; }
.usuarios-vacio { text-align: center; padding: 60px 20px; color: #555; font-size: 14px; }

/* ── PENDIENTES ── */
.pendientes-lista { padding: 20px 24px 40px; display: flex; flex-direction: column; gap: 14px; }
.pendiente-card {
  background: #2a2a2a; border: 1px solid #333; border-left: 3px solid #b8863a;
  border-radius: 12px; padding: 16px;
}
.pendiente-pregunta { font-size: 14px; font-weight: 600; color: #ececec; margin-bottom: 6px; }
.pendiente-fecha { font-size: 11px; color: #555; margin-bottom: 8px; }
.pendiente-motivo { font-size: 12px; color: #e0a84a; margin-bottom: 10px; }
.pendiente-respuesta {
  background: #1e1e1e; border-radius: 8px; padding: 10px; font-size: 13px;
  color: #ccc; white-space: pre-wrap; max-height: 220px; overflow-y: auto; margin-bottom: 10px;
}
.pendiente-concepto {
  width: 100%; background: #1e1e1e; border: 1px solid #3a3a3a; border-radius: 8px;
  color: #ececec; font-size: 13px; padding: 8px 10px; margin-bottom: 10px; font-family: inherit;
}
.pendiente-botones { display: flex; gap: 8px; flex-wrap: wrap; }
.btn-mini {
  background: #ececec; color: #212121; border: none; border-radius: 8px;
  padding: 8px 14px; font-size: 12.5px; font-weight: 600; cursor: pointer; font-family: inherit;
}
.btn-mini.secundario { background: #3a3a3a; color: #ececec; }
.btn-mini.peligro { background: #4a2a2a; color: #e77; }
.btn-mini:disabled { opacity: 0.5; cursor: default; }
.pendiente-resultado { font-size: 12px; margin-top: 8px; }

/* ── IMÁGENES ── */
.grid-imagenes {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 14px;
  padding: 20px 24px 40px;
}
.img-card {
  background: #2a2a2a; border: 1px solid #333; border-radius: 12px;
  overflow: hidden; transition: border-color 0.2s;
}
.img-card:hover { border-color: #444; }
.img-card-miniatura {
  width: 100%; aspect-ratio: 1 / 1; object-fit: cover;
  display: block; background: #1e1e1e; cursor: zoom-in;
}
.img-card-cuerpo { padding: 12px 14px; position: relative; }
.img-card-termino {
  font-size: 13px; color: #ececec; line-height: 1.4;
  padding-right: 56px;
  display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;
}
.img-card-meta { margin-top: 8px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.badge-motor {
  font-size: 10px; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase;
  padding: 2px 8px; border-radius: 20px; background: #1a3a50; color: #5dade2;
}
.img-card-peso { font-size: 11px; color: #555; }
.img-card .btn-eliminar, .img-card .btn-editar-campos { top: 10px; }
.img-card-edicion { margin-top: 10px; padding-top: 10px; border-top: 1px dashed #3a3a3a; display: none; flex-direction: column; gap: 6px; }
.img-card-edicion textarea {
  width: 100%; background: #1e1e1e; border: 1px solid #3a3a3a; border-radius: 8px;
  color: #ececec; font-size: 12.5px; padding: 7px 10px; outline: none; resize: vertical;
  min-height: 56px; font-family: inherit;
}
.img-card-edicion textarea:focus { border-color: #5dade2; }

/* Visor de imagen a pantalla completa */
.img-visor {
  display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85);
  z-index: 200; align-items: center; justify-content: center; padding: 24px; cursor: zoom-out;
}
.img-visor.abierto { display: flex; }
.img-visor img { max-width: 100%; max-height: 100%; border-radius: 8px; }

/* ── ENTRENAMIENTO ── */
.entrenamiento-panel { padding: 20px 24px 40px; }
.entrenamiento-barra-wrap {
  background: #2a2a2a; border: 1px solid #333; border-radius: 12px; padding: 16px 18px; margin-bottom: 18px;
}
.entrenamiento-barra-top { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px; }
.entrenamiento-barra-titulo { font-size: 13px; color: #aaa; }
.entrenamiento-barra-numero { font-size: 15px; color: #ececec; font-weight: 600; }
.entrenamiento-barra-fondo { background: #1a1a1a; border-radius: 20px; height: 10px; overflow: hidden; }
.entrenamiento-barra-relleno { background: linear-gradient(90deg, #2a6fb0, #5dade2); height: 100%; border-radius: 20px; transition: width 0.4s; }
.btn-ejecutar-ahora {
  margin-top: 14px; background: #1a3a50; color: #5dade2; border: 1px solid #2a6fb0;
  border-radius: 8px; padding: 9px 16px; font-size: 13px; font-weight: 600; cursor: pointer;
}
.btn-ejecutar-ahora:hover { background: #204a68; }
.btn-ejecutar-ahora:disabled { opacity: 0.5; cursor: default; }
.entrenamiento-resultado { margin-top: 10px; font-size: 12.5px; color: #ccc; white-space: pre-wrap; }
.entrenamiento-auto-toggle {
  display: flex; align-items: center; gap: 6px; margin-top: 12px; font-size: 12.5px; color: #aaa; cursor: pointer;
}
.entrenamiento-auto-toggle input { cursor: pointer; }
.entrenamiento-auto-toggle select {
  background: #1e1e1e; color: #ececec; border: 1px solid #3a3a3a; border-radius: 6px;
  padding: 2px 6px; font-size: 12.5px;
}
.entrenamiento-auto-estado { margin-top: 6px; font-size: 12px; color: #5dade2; min-height: 16px; }
.entrenamiento-auto-aviso { margin-top: 6px; font-size: 11.5px; color: #d18a3d; line-height: 1.4; max-width: 480px; }
.entrenamiento-log-titulo { font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin: 18px 0 8px; }
.entrenamiento-log {
  background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 10px; padding: 12px 14px;
  font-family: monospace; font-size: 11.5px; color: #999; max-height: 420px; overflow-y: auto;
}
.entrenamiento-log-linea { padding: 3px 0; border-bottom: 1px solid #232323; white-space: pre-wrap; word-break: break-word; }
.entrenamiento-log-linea:last-child { border-bottom: none; }

/* ── CONFIGURACIÓN ── */
.config-panel { display: flex; flex-direction: column; gap: 16px; max-width: 480px; }
.config-aviso { font-size: 12.5px; color: #aaa; background: #232323; border: 1px solid #333; border-radius: 10px; padding: 10px 12px; line-height: 1.5; }
.config-campo { display: flex; flex-direction: column; gap: 5px; }
.config-campo label { font-size: 13px; font-weight: 600; color: #ececec; }
.config-ayuda { font-size: 11.5px; color: #888; line-height: 1.4; }
.config-campo input, .config-campo textarea {
  width: 100%; background: #1e1e1e; border: 1px solid #3a3a3a;
  border-radius: 8px; color: #ececec; font-size: 13px;
  padding: 8px 10px; outline: none; font-family: inherit; resize: vertical;
}
.config-campo input:focus, .config-campo textarea:focus { border-color: #5dade2; }
.config-resultado { font-size: 12.5px; margin-top: 2px; }
.config-resultado.ok { color: #6fcf97; }
.config-resultado.error { color: #e77; }

/* ── ALTA DE CONOCIMIENTO (pestaña Conocimiento) ── */
.alta-conocimiento-wrap { margin-bottom: 16px; }
.btn-toggle-alta {
  background: #1a3a50; color: #5dade2; border: 1px solid #2a6fb0;
  border-radius: 8px; padding: 9px 16px; font-size: 13px; font-weight: 600;
  cursor: pointer; font-family: inherit;
}
.btn-toggle-alta:hover { background: #204a68; }
.alta-conocimiento-panel {
  margin-top: 10px; background: #232323; border: 1px solid #333; border-radius: 12px;
  padding: 14px; display: flex; flex-direction: column; gap: 12px; max-width: 560px;
}
</style>
</head>
<body>

<div class="header">
  <div class="header-titulo">
    <span>❄</span> CodeMentor — Panel
  </div>
  <div style="display:flex; gap:8px;">
    <a href="index.html" class="header-volver">← Volver al chat</a>
    <a href="estado.php?logout=1" class="header-volver">Cerrar sesión</a>
  </div>
</div>

<div class="tabs">
  <button class="tab-btn" data-tab="conocimiento">Conocimiento</button>
  <button class="tab-btn" data-tab="usuarios">Usuarios (<?= count($usuariosDb) ?>)</button>
  <button class="tab-btn" data-tab="pendientes">Pendientes (<?= count($pendientes) ?>)</button>
  <button class="tab-btn" data-tab="imagenes">Imágenes (<?= count($imagenes) ?>)</button>
  <button class="tab-btn" data-tab="entrenamiento">Entrenamiento (<?= $progresoEntrenamiento['hechas'] ?>/<?= $progresoEntrenamiento['total'] ?>)</button>
  <button class="tab-btn" data-tab="configuracion">Configuración</button>
</div>

<div id="tab-conocimiento" class="tab-panel">

<div class="alta-conocimiento-wrap">
  <button class="btn-toggle-alta" onclick="toggleAltaConocimiento()">+ Agregar conocimiento</button>
  <div class="alta-conocimiento-panel" id="alta-conocimiento-panel" style="display:none;">
    <div class="config-campo">
      <label for="alta-pregunta">Pregunta *</label>
      <input type="text" id="alta-pregunta" maxlength="500" placeholder="¿Cómo se centra un div con flexbox?">
    </div>
    <div class="config-campo">
      <label for="alta-concepto">Concepto *</label>
      <input type="text" id="alta-concepto" maxlength="200" placeholder="Flexbox: centrado">
    </div>
    <div class="config-campo">
      <label for="alta-categoria">Categoría</label>
      <input type="text" id="alta-categoria" maxlength="60" placeholder="CSS">
    </div>
    <div class="config-campo">
      <label for="alta-descripcion">Descripción *</label>
      <textarea id="alta-descripcion" rows="3" maxlength="5000"></textarea>
    </div>
    <div class="config-campo">
      <label for="alta-sintaxis">Sintaxis</label>
      <textarea id="alta-sintaxis" rows="2" maxlength="3000"></textarea>
    </div>
    <div class="config-campo">
      <label for="alta-ejemplo">Ejemplo</label>
      <textarea id="alta-ejemplo" rows="3" maxlength="3000"></textarea>
    </div>
    <div class="config-campo">
      <label for="alta-errores">Errores comunes</label>
      <textarea id="alta-errores" rows="2" maxlength="3000"></textarea>
    </div>
    <div class="config-campo">
      <label for="alta-buenas-practicas">Buenas prácticas</label>
      <textarea id="alta-buenas-practicas" rows="2" maxlength="3000"></textarea>
    </div>
    <div class="config-campo">
      <label for="alta-palabras-clave">Palabras clave</label>
      <input type="text" id="alta-palabras-clave" maxlength="300" placeholder="separadas, por, coma">
    </div>
    <div class="config-campo">
      <label for="alta-nivel">Nivel</label>
      <select id="alta-nivel">
        <option value="">(sin especificar)</option>
        <option value="basico">Básico</option>
        <option value="intermedio">Intermedio</option>
        <option value="avanzado">Avanzado</option>
      </select>
    </div>
    <div class="config-campo">
      <label for="alta-estado">Estado</label>
      <select id="alta-estado">
        <option value="publicado" selected>Publicado</option>
        <option value="borrador">Borrador</option>
        <option value="archivado">Archivado</option>
      </select>
    </div>
    <div class="edicion-botones">
      <button class="btn-guardar-campos" id="btn-guardar-alta" onclick="guardarAltaConocimiento()">Guardar</button>
      <button class="btn-cancelar-campos" onclick="toggleAltaConocimiento()">Cancelar</button>
    </div>
    <div class="config-resultado" id="alta-resultado"></div>
  </div>
</div>

<div class="buscador-wrap">
  <div class="buscador-caja">
    <input type="text" id="buscador-conocimiento" placeholder="Buscar por pregunta o parte de una respuesta...">
    <button class="buscador-limpiar" id="btn-limpiar-buscador" title="Limpiar" onclick="limpiarBusqueda()">✕</button>
  </div>
  <div class="buscador-resultados" id="buscador-resultados"></div>
</div>

<div class="stats">
  <div class="stat">
    <div class="stat-numero"><?= $totalGlobal ?></div>
    <div class="stat-label">Total</div>
  </div>
  <div class="stat auto">
    <div class="stat-numero"><?= $totalAuto ?></div>
    <div class="stat-label">Aprendidos</div>
  </div>
  <div class="stat manual">
    <div class="stat-numero"><?= $totalManual ?></div>
    <div class="stat-label">Manuales</div>
  </div>
  <div class="stat">
    <div class="stat-numero"><?= count($archivos) ?></div>
    <div class="stat-label">Archivos</div>
  </div>
</div>

<div class="contenido">

<?php if (empty($porArchivo)): ?>
  <div class="empty">
    <div class="icono">📭</div>
    CodeMentor todavía no tiene conocimiento guardado.
  </div>
<?php else: ?>
  <?php foreach ($porArchivo as $nombre => $items): ?>
    <?php if (empty($items)) continue; ?>
    <div class="seccion" data-archivo="<?= htmlspecialchars($nombre) ?>">
      <div class="seccion-header">
        <span class="seccion-titulo"><?= htmlspecialchars($nombre) ?></span>
        <span class="seccion-count"><?= count($items) ?> conceptos</span>
      </div>
      <div class="grid">
        <?php
          // Mostrar más reciente primero dentro de este archivo, sin
          // tocar el índice original ($i): eliminar()/editar() lo usan
          // para saber qué línea tocar en el .jsonl real, así que solo
          // cambiamos el ORDEN en que se recorren, no los índices.
          $itemsOrdenados = [];
          foreach ($items as $i => $item) $itemsOrdenados[] = ['i' => $i, 'item' => $item];
          usort($itemsOrdenados, function($a, $b) {
              $fechaA = $a['item']['_fecha'] ?? '';
              $fechaB = $b['item']['_fecha'] ?? '';
              $cmp = strcmp($fechaB, $fechaA); // descendente: más nueva primero
              if ($cmp !== 0) return $cmp;
              // Empate de fecha (p.ej. _fecha sin hora, mismo día): el que
              // se agregó después en el archivo (índice más alto) es más
              // reciente, así que va primero.
              return $b['i'] <=> $a['i'];
          });
        ?>
        <?php foreach ($itemsOrdenados as $entry): $i = $entry['i']; $item = $entry['item']; ?>
          <?php
            $esAuto   = ($item['_origen'] ?? '') === 'auto';
            $concepto = $item['concepto']     ?? '(sin concepto)';
            $desc     = $item['descripcion']  ?? '';
            $fecha    = $item['_fecha']        ?? '';
            $pregOrig = $item['_pregunta_original'] ?? '';
            $categoria = $item['categoria']       ?? '';
            $palClave  = $item['palabras_clave']  ?? '';
          ?>
          <div class="card <?= $esAuto ? 'auto' : 'manual' ?>"
               id="card-<?= $nombre ?>-<?= $i ?>"
               data-archivo="<?= htmlspecialchars($nombre) ?>" data-indice="<?= $i ?>">
            <button class="btn-eliminar"
                    onclick="eliminar('<?= htmlspecialchars($nombre) ?>', <?= $i ?>, this)"
                    title="Eliminar">✕</button>
            <button class="btn-editar-campos"
                    onclick="toggleEdicionCampos(this)"
                    title="Editar categoría / palabras clave">✎</button>
            <div class="card-concepto"><?= htmlspecialchars($concepto) ?></div>
            <?php if ($desc): ?>
              <div class="card-descripcion"><?= htmlspecialchars($desc) ?></div>
            <?php endif; ?>
            <div class="card-meta">
              <span class="badge <?= $esAuto ? 'auto' : 'manual' ?>">
                <?= $esAuto ? 'Auto' : 'Manual' ?>
              </span>
              <?php if ($categoria !== ''): ?>
                <span class="badge-categoria card-categoria"><?= htmlspecialchars($categoria) ?></span>
              <?php endif; ?>
              <?php if ($fecha): ?>
                <span class="card-fecha"><?= htmlspecialchars(substr($fecha, 0, 10)) ?></span>
              <?php endif; ?>
            </div>
            <?php if ($palClave !== ''): ?>
              <div class="card-palabras-clave">
                <?php foreach (array_filter(array_map('trim', explode(',', $palClave))) as $pc): ?>
                  <span class="tag-clave"><?= htmlspecialchars($pc) ?></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if ($pregOrig): ?>
              <div class="card-pregunta-orig">"<?= htmlspecialchars($pregOrig) ?>"</div>
            <?php endif; ?>
            <div class="card-edicion-campos" style="display:none;">
              <input type="text" class="input-categoria" placeholder="Categoría (ej: PHP, HTML, Android)"
                     value="<?= htmlspecialchars($categoria) ?>" maxlength="60">
              <input type="text" class="input-palabras-clave" placeholder="Palabras clave separadas por coma"
                     value="<?= htmlspecialchars($palClave) ?>" maxlength="300">
              <div class="edicion-botones">
                <button class="btn-guardar-campos" onclick="guardarCampos(this)">Guardar</button>
                <button class="btn-cancelar-campos" onclick="toggleEdicionCampos(this)">Cancelar</button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

</div>
</div><!-- /tab-conocimiento -->

<div id="tab-usuarios" class="tab-panel">
  <?php if ($errorUsuarios): ?>
    <div class="usuarios-vacio">⚠️ <?= htmlspecialchars($errorUsuarios) ?></div>
  <?php elseif (empty($usuariosDb)): ?>
    <div class="usuarios-vacio">
      <div class="icono" style="font-size:40px; margin-bottom:12px;">👤</div>
      Todavía no hay usuarios registrados.
    </div>
  <?php else: ?>
    <div class="usuarios-lista">
      <?php foreach ($usuariosDb as $u): ?>
        <div class="usuario-card" data-usuario-id="<?= (int) $u['id'] ?>">
          <div class="usuario-header" onclick="toggleUsuario(<?= (int) $u['id'] ?>)">
            <div class="usuario-info">
              <div class="usuario-nombre"><?= htmlspecialchars($u['nombre']) ?></div>
              <div class="usuario-email"><?= htmlspecialchars($u['email']) ?></div>
              <div class="usuario-fecha">Registrado: <?= htmlspecialchars(substr($u['creado_en'], 0, 16)) ?></div>
            </div>
            <div class="usuario-stats">
              <span class="usuario-badge"><?= (int) $u['total_chats'] ?> chats</span>
              <span class="usuario-badge"><?= (int) $u['total_mensajes'] ?> mensajes</span>
              <button class="btn-eliminar" title="Eliminar usuario"
                      onclick="event.stopPropagation(); eliminarUsuario(<?= (int) $u['id'] ?>, this)">✕</button>
            </div>
          </div>
          <div class="usuario-chats" id="chats-usuario-<?= (int) $u['id'] ?>"></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div><!-- /tab-usuarios -->

<div id="tab-pendientes" class="tab-panel">
  <?php if (empty($pendientes)): ?>
    <div class="usuarios-vacio">
      <div class="icono" style="font-size:40px; margin-bottom:12px;">🎉</div>
      No hay pendientes.
    </div>
  <?php else: ?>
    <div class="pendientes-lista">
      <?php foreach ($pendientes as $p): ?>
        <div class="pendiente-card" data-indice="<?= (int) $p['_indice'] ?>">
          <div class="pendiente-pregunta"><?= htmlspecialchars($p['pregunta'] ?? '') ?></div>
          <div class="pendiente-fecha"><?= htmlspecialchars($p['_fecha'] ?? '') ?></div>
          <?php if (!empty($p['motivo'])): ?>
            <div class="pendiente-motivo">Motivo del fallo: <?= htmlspecialchars($p['motivo']) ?></div>
          <?php endif; ?>
          <div class="pendiente-respuesta"><?= htmlspecialchars($p['respuesta'] ?? '') ?></div>
          <input type="text" class="pendiente-concepto" placeholder="Concepto para guardar tal cual (opcional)">
          <div class="pendiente-botones">
            <button class="btn-mini pendiente-reintentar">Reintentar (Gemini/Groq)</button>
            <button class="btn-mini secundario pendiente-guardar">Guardar tal cual</button>
            <button class="btn-mini peligro pendiente-descartar">Descartar</button>
          </div>
          <div class="pendiente-resultado"></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div><!-- /tab-pendientes -->

<div id="tab-imagenes" class="tab-panel">
  <?php if (empty($imagenes)): ?>
    <div class="usuarios-vacio">
      <div class="icono" style="font-size:40px; margin-bottom:12px;">🖼️</div>
      Todavía no hay imágenes generadas.
    </div>
  <?php else: ?>
    <div class="grid-imagenes">
      <?php foreach ($imagenes as $img): ?>
        <div class="img-card" data-clave="<?= htmlspecialchars($img['clave']) ?>">
          <img class="img-card-miniatura"
               src="estado.php?imagen=<?= urlencode($img['clave']) ?>"
               alt="<?= htmlspecialchars($img['termino']) ?>"
               loading="lazy"
               onclick="abrirVisor(this.src)">
          <div class="img-card-cuerpo">
            <button class="btn-eliminar" title="Eliminar" onclick="eliminarImagen(this)">✕</button>
            <button class="btn-editar-campos" title="Editar descripción" onclick="toggleEdicionImagen(this)">✎</button>
            <div class="img-card-termino"><?= htmlspecialchars($img['termino']) ?></div>
            <div class="img-card-meta">
              <span class="badge-motor"><?= htmlspecialchars($img['motor']) ?></span>
              <?php if ($img['fecha']): ?>
                <span class="card-fecha"><?= htmlspecialchars(substr($img['fecha'], 0, 16)) ?></span>
              <?php endif; ?>
              <span class="img-card-peso"><?= round($img['peso'] / 1024) ?> KB</span>
            </div>
            <div class="img-card-edicion">
              <textarea class="img-card-input-termino" maxlength="300"><?= htmlspecialchars($img['termino']) ?></textarea>
              <div class="edicion-botones">
                <button class="btn-guardar-campos" onclick="guardarTerminoImagen(this)">Guardar</button>
                <button class="btn-cancelar-campos" onclick="toggleEdicionImagen(this)">Cancelar</button>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div><!-- /tab-imagenes -->

<div id="tab-entrenamiento" class="tab-panel">
  <div class="entrenamiento-panel">
    <?php
      $pct = $progresoEntrenamiento['total'] > 0
        ? round(($progresoEntrenamiento['hechas'] / $progresoEntrenamiento['total']) * 100)
        : 0;
    ?>
    <div class="entrenamiento-barra-wrap">
      <div class="entrenamiento-barra-top">
        <span class="entrenamiento-barra-titulo">Progreso del banco de preguntas</span>
        <span class="entrenamiento-barra-numero"><?= $progresoEntrenamiento['hechas'] ?> / <?= $progresoEntrenamiento['total'] ?> (<?= $pct ?>%)</span>
      </div>
      <div class="entrenamiento-barra-fondo">
        <div class="entrenamiento-barra-relleno" style="width: <?= $pct ?>%;"></div>
      </div>
      <button class="btn-ejecutar-ahora" id="btn-ejecutar-entrenamiento" onclick="ejecutarEntrenamientoAhora()">▶ Ejecutar una ronda ahora</button>

      <label class="entrenamiento-auto-toggle">
        <input type="checkbox" id="chk-auto-entrenamiento" onchange="alternarAutoEjecucion(this.checked)">
        Auto-ejecutar cada <select id="sel-intervalo-auto" onchange="if(document.getElementById('chk-auto-entrenamiento').checked){alternarAutoEjecucion(true)}">
          <option value="2">2 min ⚠️</option>
          <option value="10">10 min</option>
          <option value="15" selected>15 min</option>
          <option value="30">30 min</option>
          <option value="60">1 hora</option>
        </select> mientras esta pestaña esté abierta
      </label>
      <div class="entrenamiento-auto-aviso">⚠️ El botón manual no respeta el enfriamiento de 10 min del servidor. Con 2 min, cada tick dispara una ronda real — puede agotar la cuota de Gemini/Groq rápido si lo dejás mucho tiempo.</div>
      <div class="entrenamiento-auto-estado" id="entrenamiento-auto-estado"></div>

      <div class="entrenamiento-resultado" id="entrenamiento-resultado"></div>
    </div>

    <div class="entrenamiento-log-titulo">Registro reciente</div>
    <div class="entrenamiento-log">
      <?php if (empty($logEntrenamiento)): ?>
        <div class="entrenamiento-log-linea">Todavía no hay actividad registrada.</div>
      <?php else: ?>
        <?php foreach ($logEntrenamiento as $linea): ?>
          <div class="entrenamiento-log-linea"><?= htmlspecialchars($linea) ?></div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div><!-- /tab-entrenamiento -->

<div id="tab-configuracion" class="tab-panel">
  <div class="config-panel">
    <div class="config-aviso">
      Hay <strong>dos umbrales distintos</strong>, y controlan cosas distintas:
      "Umbral aproximado" decide qué resultados devuelve <strong>/buscar.php</strong> en general
      (para listarlos en el chat, en el panel, etc.). "Umbral para evitar la IA" es el que de
      verdad decide si el chat reutiliza una respuesta guardada o gasta un crédito llamando a
      la IA — normalmente conviene dejarlo igual o más alto que el aproximado, porque usar un
      match débil como respuesta final es más arriesgado que solo mostrarlo como candidato.
    </div>

    <div class="config-campo">
      <label for="cfg-umbral">Umbral aproximado</label>
      <div class="config-ayuda">Qué tan parecida tiene que ser una pregunta para aceptar el match aproximado. Más alto = más estricto (menos resultados, pero más relevantes). Más bajo = más permisivo.</div>
      <input type="number" id="cfg-umbral" min="0" max="1" step="0.01" value="<?= htmlspecialchars($configCodeMentor['umbral_aproximado']) ?>">
    </div>

    <div class="config-campo">
      <label for="cfg-umbral-ia">Umbral para evitar la IA (reciclar respuestas)</label>
      <div class="config-ayuda">Score mínimo (0–1) para que el chat use una respuesta ya guardada en vez de consultarle a la IA. Más bajo = reutiliza más seguido y gasta menos créditos, a costa de que a veces la respuesta no encaje perfecto. Lo usa index.html, no buscar.php.</div>
      <input type="number" id="cfg-umbral-ia" min="0" max="1" step="0.01" value="<?= htmlspecialchars($configCodeMentor['umbral_evitar_ia']) ?>">
    </div>

    <div class="config-campo">
      <label for="cfg-peso">Peso de palabras sueltas (Jaccard)</label>
      <div class="config-ayuda">De 0 a 1. El resto (1 − este valor) es el peso de los bigramas (orden de las palabras). Más alto favorece coincidencia de vocabulario; más bajo favorece frases con el mismo orden.</div>
      <input type="number" id="cfg-peso" min="0" max="1" step="0.01" value="<?= htmlspecialchars($configCodeMentor['peso_jaccard']) ?>">
    </div>

    <div class="config-campo">
      <label for="cfg-maximo">Resultados máximos por defecto</label>
      <div class="config-ayuda">Cuántos resultados devuelve /buscar.php cuando quien pregunta no pide una cantidad puntual (1 a 20).</div>
      <input type="number" id="cfg-maximo" min="1" max="20" step="1" value="<?= (int) $configCodeMentor['maximo_resultados'] ?>">
    </div>

    <div class="config-campo">
      <label for="cfg-stopwords">Stopwords propias</label>
      <div class="config-ayuda">Palabras a ignorar en el matching, además de las de fábrica (vocativos, muletillas). Separadas por coma.</div>
      <textarea id="cfg-stopwords" rows="3" placeholder="ej: porfavor, tipo, osea"><?= htmlspecialchars($configCodeMentor['stopwords_extra']) ?></textarea>
    </div>

    <div class="edicion-botones">
      <button class="btn-guardar-campos" id="btn-guardar-config" onclick="guardarConfigCodeMentor()">Guardar cambios</button>
      <button class="btn-cancelar-campos" onclick="restaurarConfigCodeMentor()">Restaurar valores de fábrica</button>
    </div>
    <div class="config-resultado" id="config-resultado"></div>
  </div>
</div><!-- /tab-configuracion -->

<div class="img-visor" id="img-visor" onclick="cerrarVisor()">
  <img id="img-visor-img" src="" alt="">
</div>

<div id="toast"></div>

<script>
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>

async function eliminar(archivo, indice, btn) {
  if (!confirm('¿Eliminar este concepto?')) return

  btn.disabled = true
  btn.innerText = '...'

  const form = new FormData()
  form.append('action',      'eliminar')
  form.append('archivo',     archivo)
  form.append('indice',      indice)
  form.append('csrf_token',  CSRF_TOKEN)

  try {
    const r = await fetch('estado.php', { method: 'POST', body: form })
    const d = await r.json()

    if (d.status === 'ok') {
      const card = document.getElementById(`card-${archivo}-${indice}`)
      card.style.transition = 'opacity 0.3s, transform 0.3s'
      card.style.opacity    = '0'
      card.style.transform  = 'scale(0.95)'
      setTimeout(() => card.remove(), 300)
      mostrarToast('✓ Concepto eliminado')
    } else {
      btn.disabled = false
      btn.innerText = '✕'
      mostrarToast('Error: ' + d.msg)
    }
  } catch(e) {
    btn.disabled = false
    btn.innerText = '✕'
    mostrarToast('Error de conexión')
  }
}

function mostrarToast(msg) {
  const t = document.getElementById('toast')
  t.innerText = msg
  t.classList.add('visible')
  setTimeout(() => t.classList.remove('visible'), 2500)
}

/* ---------------- EDITAR CATEGORÍA / PALABRAS CLAVE ---------------- */
function toggleEdicionCampos(btn) {
  const card = btn.closest('.card')
  const panel = card.querySelector('.card-edicion-campos')
  const abierto = panel.style.display !== 'none'
  panel.style.display = abierto ? 'none' : 'flex'
  if (!abierto) panel.querySelector('.input-categoria').focus()
}

async function guardarCampos(btn) {
  const card = btn.closest('.card')
  const archivo = card.dataset.archivo
  const indice  = card.dataset.indice
  const categoria     = card.querySelector('.input-categoria').value.trim()
  const palabrasClave = card.querySelector('.input-palabras-clave').value.trim()

  btn.disabled = true
  btn.innerText = 'Guardando…'

  const form = new FormData()
  form.append('action',         'editar_campos')
  form.append('archivo',        archivo)
  form.append('indice',         indice)
  form.append('categoria',      categoria)
  form.append('palabras_clave', palabrasClave)
  form.append('csrf_token',     CSRF_TOKEN)

  try {
    const r = await fetch('estado.php', { method: 'POST', body: form })
    const d = await r.json()

    if (d.status === 'ok') {
      // Actualizar el badge de categoría
      let elCategoria = card.querySelector('.card-categoria')
      const meta = card.querySelector('.card-meta')
      if (d.categoria) {
        if (!elCategoria) {
          elCategoria = document.createElement('span')
          elCategoria.className = 'badge-categoria card-categoria'
          meta.appendChild(elCategoria)
        }
        elCategoria.textContent = d.categoria
      } else if (elCategoria) {
        elCategoria.remove()
      }

      // Actualizar los tags de palabras clave
      let elTags = card.querySelector('.card-palabras-clave')
      if (d.palabras_clave) {
        if (!elTags) {
          elTags = document.createElement('div')
          elTags.className = 'card-palabras-clave'
          card.querySelector('.card-edicion-campos').before(elTags)
        }
        elTags.innerHTML = d.palabras_clave.split(',')
          .map(p => p.trim()).filter(Boolean)
          .map(p => `<span class="tag-clave">${escapeHtml(p)}</span>`).join('')
      } else if (elTags) {
        elTags.remove()
      }

      card.querySelector('.card-edicion-campos').style.display = 'none'
      mostrarToast('✓ Guardado')
    } else {
      mostrarToast('Error: ' + d.msg)
    }
  } catch (e) {
    mostrarToast('Error de conexión')
  } finally {
    btn.disabled = false
    btn.innerText = 'Guardar'
  }
}

/* ---------------- ALTA DE CONOCIMIENTO ---------------- */

function toggleAltaConocimiento() {
  const panel = document.getElementById('alta-conocimiento-panel')
  panel.style.display = panel.style.display === 'none' ? 'flex' : 'none'
}

function mostrarResultadoAlta(ok, texto) {
  const el = document.getElementById('alta-resultado')
  el.textContent = texto
  el.className = 'config-resultado ' + (ok ? 'ok' : 'error')
}

async function guardarAltaConocimiento(forzar = false) {
  const pregunta    = document.getElementById('alta-pregunta').value.trim()
  const concepto    = document.getElementById('alta-concepto').value.trim()
  const descripcion = document.getElementById('alta-descripcion').value.trim()

  if (!pregunta || !concepto || !descripcion) {
    mostrarResultadoAlta(false, 'Pregunta, concepto y descripción son obligatorios.')
    return
  }

  const btn = document.getElementById('btn-guardar-alta')
  btn.disabled = true
  btn.innerText = 'Guardando…'

  const form = new FormData()
  form.append('action', 'agregar_conocimiento')
  form.append('csrf_token', CSRF_TOKEN)
  form.append('pregunta', pregunta)
  form.append('concepto', concepto)
  form.append('categoria', document.getElementById('alta-categoria').value.trim())
  form.append('descripcion', descripcion)
  form.append('sintaxis', document.getElementById('alta-sintaxis').value.trim())
  form.append('ejemplo', document.getElementById('alta-ejemplo').value.trim())
  form.append('errores', document.getElementById('alta-errores').value.trim())
  form.append('buenas_practicas', document.getElementById('alta-buenas-practicas').value.trim())
  form.append('palabras_clave', document.getElementById('alta-palabras-clave').value.trim())
  form.append('nivel_dificultad', document.getElementById('alta-nivel').value)
  form.append('estado', document.getElementById('alta-estado').value)
  if (forzar) form.append('forzar', '1')

  try {
    const r = await fetch('estado.php', { method: 'POST', body: form })
    const d = await r.json()

    if (d.status === 'ok') {
      mostrarToast('✓ Conocimiento agregado: ' + d.concepto)
      location.hash = 'conocimiento'
      location.reload()
      return
    } else if (d.status === 'duplicado') {
      if (confirm(d.msg)) {
        await guardarAltaConocimiento(true)
        return
      }
      mostrarResultadoAlta(false, 'Cancelado — no se guardó.')
    } else {
      mostrarResultadoAlta(false, 'Error: ' + d.msg)
    }
  } catch (e) {
    mostrarResultadoAlta(false, 'Error de conexión')
  } finally {
    btn.disabled = false
    btn.innerText = 'Guardar'
  }
}

/* ---------------- CONFIGURACIÓN ---------------- */

function mostrarResultadoConfig(ok, texto) {
  const el = document.getElementById('config-resultado')
  el.textContent = texto
  el.className = 'config-resultado ' + (ok ? 'ok' : 'error')
}

async function enviarConfigCodeMentor(datosExtra) {
  const btnGuardar = document.getElementById('btn-guardar-config')
  btnGuardar.disabled = true

  const form = new FormData()
  form.append('action', 'guardar_config')
  form.append('csrf_token', CSRF_TOKEN)
  for (const [clave, valor] of Object.entries(datosExtra)) form.append(clave, valor)

  try {
    const r = await fetch('estado.php', { method: 'POST', body: form })
    const d = await r.json()

    if (d.status === 'ok') {
      document.getElementById('cfg-umbral').value     = d.config.umbral_aproximado
      document.getElementById('cfg-peso').value       = d.config.peso_jaccard
      document.getElementById('cfg-maximo').value     = d.config.maximo_resultados
      document.getElementById('cfg-stopwords').value  = d.config.stopwords_extra
      document.getElementById('cfg-umbral-ia').value  = d.config.umbral_evitar_ia
      mostrarResultadoConfig(true, '✓ Guardado — ya se usa en la próxima búsqueda')
      mostrarToast('✓ Configuración guardada')
    } else {
      mostrarResultadoConfig(false, 'Error: ' + d.msg)
    }
  } catch (e) {
    mostrarResultadoConfig(false, 'Error de conexión')
  } finally {
    btnGuardar.disabled = false
  }
}

function guardarConfigCodeMentor() {
  enviarConfigCodeMentor({
    umbral_aproximado: document.getElementById('cfg-umbral').value,
    peso_jaccard:       document.getElementById('cfg-peso').value,
    maximo_resultados:  document.getElementById('cfg-maximo').value,
    stopwords_extra:    document.getElementById('cfg-stopwords').value,
    umbral_evitar_ia:   document.getElementById('cfg-umbral-ia').value,
  })
}

function restaurarConfigCodeMentor() {
  if (!confirm('¿Restaurar los valores de fábrica? Se pierden los ajustes guardados.')) return
  enviarConfigCodeMentor({ restaurar: '1' })
}

/* ---------------- IMÁGENES ---------------- */
async function eliminarImagen(btn) {
  if (!confirm('¿Eliminar esta imagen del caché? No se puede deshacer.')) return

  const card  = btn.closest('.img-card')
  const clave = card.dataset.clave
  btn.disabled = true

  const form = new FormData()
  form.append('action',     'eliminar_imagen')
  form.append('clave',      clave)
  form.append('csrf_token', CSRF_TOKEN)

  try {
    const r = await fetch('estado.php', { method: 'POST', body: form })
    const d = await r.json()
    if (d.status === 'ok') {
      card.style.transition = 'opacity 0.3s, transform 0.3s'
      card.style.opacity    = '0'
      card.style.transform  = 'scale(0.95)'
      setTimeout(() => card.remove(), 300)
      mostrarToast('✓ Imagen eliminada')
    } else {
      btn.disabled = false
      mostrarToast('Error: ' + d.msg)
    }
  } catch (e) {
    btn.disabled = false
    mostrarToast('Error de conexión')
  }
}

function toggleEdicionImagen(btn) {
  const card  = btn.closest('.img-card')
  const panel = card.querySelector('.img-card-edicion')
  const abierto = panel.style.display === 'flex'
  panel.style.display = abierto ? 'none' : 'flex'
  if (!abierto) panel.querySelector('.img-card-input-termino').focus()
}

async function guardarTerminoImagen(btn) {
  const card    = btn.closest('.img-card')
  const clave   = card.dataset.clave
  const textarea = card.querySelector('.img-card-input-termino')
  const termino = textarea.value.trim()

  if (!termino) { mostrarToast('La descripción no puede quedar vacía'); return }

  btn.disabled = true
  btn.innerText = 'Guardando…'

  const form = new FormData()
  form.append('action',     'editar_imagen_termino')
  form.append('clave',      clave)
  form.append('termino',    termino)
  form.append('csrf_token', CSRF_TOKEN)

  try {
    const r = await fetch('estado.php', { method: 'POST', body: form })
    const d = await r.json()
    if (d.status === 'ok') {
      card.querySelector('.img-card-termino').textContent = d.termino
      card.querySelector('.img-card-miniatura').alt = d.termino
      card.querySelector('.img-card-edicion').style.display = 'none'
      mostrarToast('✓ Guardado')
    } else {
      mostrarToast('Error: ' + d.msg)
    }
  } catch (e) {
    mostrarToast('Error de conexión')
  } finally {
    btn.disabled = false
    btn.innerText = 'Guardar'
  }
}

function abrirVisor(src) {
  document.getElementById('img-visor-img').src = src
  document.getElementById('img-visor').classList.add('abierto')
}
function cerrarVisor() {
  document.getElementById('img-visor').classList.remove('abierto')
  document.getElementById('img-visor-img').src = ''
}

/* ---------------- ENTRENAMIENTO ---------------- */
let intervaloAutoEntrenamiento = null

async function ejecutarEntrenamientoAhora(silencioso = false) {
  const btn = document.getElementById('btn-ejecutar-entrenamiento')
  const out = document.getElementById('entrenamiento-resultado')
  if (!silencioso) {
    btn.disabled = true
    btn.innerText = 'Ejecutando… puede tardar hasta 1 minuto'
    out.textContent = ''
  }

  const form = new FormData()
  form.append('action',     'ejecutar_entrenamiento_ahora')
  form.append('csrf_token', CSRF_TOKEN)

  try {
    const r = await fetch('estado.php', { method: 'POST', body: form })
    const d = await r.json()

    if (d.status === 'ok') {
      out.textContent = `✓ Pregunta (${d.categoria}): "${d.pregunta}"\nGuardado: ${JSON.stringify(d.guardado)}\nProgreso: ${d.progreso}`
      mostrarToast('✓ Ronda completada')
      // Si el auto-modo está activo, avisamos con un flag en
      // sessionStorage para que, tras el reload, sepa que tiene que
      // re-armar el intervalo (el setInterval no sobrevive un reload).
      if (localStorage.getItem('codementor_entrenamiento_auto') === '1') {
        sessionStorage.setItem('codementor_reanudar_auto_entrenamiento', '1')
      }
      setTimeout(() => location.reload(), 1500)
    } else if (d.status === 'skip') {
      out.textContent = `⏳ ${d.msg}`
    } else if (d.status === 'listo') {
      out.textContent = `🎉 ${d.msg}`
      detenerAutoEjecucion() // no tiene sentido seguir intentando si ya no quedan preguntas
    } else {
      out.textContent = `✗ ${d.msg || 'Error desconocido'}`
    }
  } catch (e) {
    out.textContent = '✗ Error de conexión'
  } finally {
    if (!silencioso) {
      btn.disabled = false
      btn.innerText = '▶ Ejecutar una ronda ahora'
    }
  }
}

function alternarAutoEjecucion(activar) {
  const estadoEl = document.getElementById('entrenamiento-auto-estado')
  if (intervaloAutoEntrenamiento) { clearInterval(intervaloAutoEntrenamiento); intervaloAutoEntrenamiento = null }

  if (!activar) {
    localStorage.removeItem('codementor_entrenamiento_auto')
    estadoEl.textContent = ''
    return
  }

  const minutos = parseInt(document.getElementById('sel-intervalo-auto').value, 10)
  localStorage.setItem('codementor_entrenamiento_auto', '1')
  localStorage.setItem('codementor_entrenamiento_auto_minutos', String(minutos))
  estadoEl.textContent = `Activo — se ejecuta sola cada ${minutos} min mientras esta pestaña esté abierta.`

  intervaloAutoEntrenamiento = setInterval(() => ejecutarEntrenamientoAhora(true), minutos * 60 * 1000)
}

function detenerAutoEjecucion() {
  document.getElementById('chk-auto-entrenamiento').checked = false
  alternarAutoEjecucion(false)
}

// Al cargar la página: si el auto-modo quedó activado (de antes de un
// reload), reactivar el checkbox y el intervalo solos.
(function restaurarAutoEjecucion() {
  if (localStorage.getItem('codementor_entrenamiento_auto') !== '1') return
  const chk = document.getElementById('chk-auto-entrenamiento')
  const sel = document.getElementById('sel-intervalo-auto')
  if (!chk || !sel) return
  const minutosGuardados = localStorage.getItem('codementor_entrenamiento_auto_minutos')
  if (minutosGuardados) sel.value = minutosGuardados
  chk.checked = true
  alternarAutoEjecucion(true)
  // Si veníamos de un reload justo después de una ronda, esperar el
  // intervalo normal antes de la próxima (no disparar doble de una).
  sessionStorage.removeItem('codementor_reanudar_auto_entrenamiento')
})()

/* ---------------- TABS ---------------- */
// Recuerda la pestaña activa en el hash de la URL (#entrenamiento,
// #imagenes, etc.), así sobrevive a un location.reload() — por
// ejemplo, después de "Ejecutar una ronda ahora" en Entrenamiento.
function activarTab(nombreTab) {
  const btn   = document.querySelector('.tab-btn[data-tab="' + nombreTab + '"]')
  const panel = document.getElementById('tab-' + nombreTab)
  if (!btn || !panel) return false
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('activo'))
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('activo'))
  btn.classList.add('activo')
  panel.classList.add('activo')
  return true
}

document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    activarTab(btn.dataset.tab)
    history.replaceState(null, '', '#' + btn.dataset.tab)
  })
})

// Al cargar la página: restaurar la pestaña del hash si hay una
// válida, si no, Conocimiento por defecto.
if (!activarTab(location.hash.replace('#', ''))) {
  activarTab('conocimiento')
}

/* ---------------- PENDIENTES ---------------- */
async function accionPendiente(card, accion, extra = {}) {
  const indice = card.dataset.indice
  const resultadoEl = card.querySelector('.pendiente-resultado')
  const botones = card.querySelectorAll('button')
  botones.forEach(b => b.disabled = true)
  resultadoEl.textContent = 'Procesando…'

  const form = new FormData()
  form.append('action', accion)
  form.append('indice', indice)
  form.append('csrf_token', CSRF_TOKEN)
  Object.entries(extra).forEach(([k, v]) => form.append(k, v))

  try {
    const r = await fetch('estado.php', { method: 'POST', body: form })
    const d = await r.json()

    if (d.status === 'ok') {
      resultadoEl.textContent = '✓ Guardado' + (d.concepto ? (': ' + d.concepto) : '') + (d.msg ? (' — ' + d.msg) : '')
      card.style.opacity = '0.5'
      setTimeout(() => card.remove(), 1200)
      mostrarToast('✓ Guardado en el conocimiento')
    } else if (d.status === 'skip') {
      resultadoEl.textContent = 'ℹ ' + (d.msg || 'Ya existía, se sacó de pendientes.')
      card.style.opacity = '0.5'
      setTimeout(() => card.remove(), 1500)
    } else if (accion === 'descartar_pendiente') {
      resultadoEl.textContent = '🗑 Descartado'
      card.remove()
    } else {
      resultadoEl.textContent = '✘ ' + (d.msg || 'Falló de nuevo, se mantiene en pendientes.')
      botones.forEach(b => b.disabled = false)
    }
  } catch (e) {
    resultadoEl.textContent = '✘ Error de conexión'
    botones.forEach(b => b.disabled = false)
  }
}

document.querySelectorAll('.pendiente-card').forEach(card => {
  card.querySelector('.pendiente-reintentar').addEventListener('click', () => accionPendiente(card, 'reintentar_pendiente'))
  card.querySelector('.pendiente-descartar').addEventListener('click', () => {
    if (confirm('¿Descartar esta respuesta sin guardarla?')) accionPendiente(card, 'descartar_pendiente')
  })
  card.querySelector('.pendiente-guardar').addEventListener('click', () => {
    const concepto = card.querySelector('.pendiente-concepto').value
    accionPendiente(card, 'guardar_pendiente_directo', { concepto })
  })
})

/* ---------------- USUARIOS Y CHATS ---------------- */
const chatsCargados = {} // usuario_id -> true, para no volver a pedir la lista

async function toggleUsuario(usuarioId) {
  const cont = document.getElementById('chats-usuario-' + usuarioId)
  const abierto = cont.classList.contains('abierto')

  if (abierto) { cont.classList.remove('abierto'); return }
  cont.classList.add('abierto')

  if (chatsCargados[usuarioId]) return // ya se cargó antes, no repetir el fetch

  cont.innerHTML = '<div style="padding:8px; color:#666; font-size:12.5px;">Cargando…</div>'

  const form = new FormData()
  form.append('action', 'chats_usuario')
  form.append('usuario_id', usuarioId)
  form.append('csrf_token', CSRF_TOKEN)

  try {
    const r = await fetch('estado.php', { method: 'POST', body: form })
    const d = await r.json()

    if (d.status !== 'ok') {
      cont.innerHTML = '<div style="padding:8px; color:#e74c3c; font-size:12.5px;">Error al cargar los chats.</div>'
      return
    }
    if (!d.chats.length) {
      cont.innerHTML = '<div style="padding:8px; color:#666; font-size:12.5px;">Este usuario todavía no tiene chats.</div>'
      chatsCargados[usuarioId] = true
      return
    }

    cont.innerHTML = ''
    d.chats.forEach(chat => {
      const item = document.createElement('div')
      item.className = 'chat-item'
      item.innerHTML = `
        <span class="chat-titulo">${escapeHtml(chat.titulo)}</span>
        <span class="chat-meta">${chat.total_mensajes} msj · ${escapeHtml(chat.actualizado_en.slice(0,16))}</span>
        <button class="btn-eliminar" title="Eliminar chat" style="position:static;">✕</button>
      `
      const btnBorrar = item.querySelector('.btn-eliminar')
      btnBorrar.addEventListener('click', (e) => { e.stopPropagation(); eliminarChat(chat.id, item, mensajesDiv) })

      const mensajesDiv = document.createElement('div')
      mensajesDiv.className = 'chat-mensajes'

      item.addEventListener('click', () => toggleMensajes(chat.id, mensajesDiv))

      cont.appendChild(item)
      cont.appendChild(mensajesDiv)
    })
    chatsCargados[usuarioId] = true
  } catch (e) {
    cont.innerHTML = '<div style="padding:8px; color:#e74c3c; font-size:12.5px;">Error de conexión.</div>'
  }
}

async function toggleMensajes(chatId, mensajesDiv) {
  const abierto = mensajesDiv.classList.contains('abierto')
  if (abierto) { mensajesDiv.classList.remove('abierto'); return }

  if (mensajesDiv.dataset.cargado) { mensajesDiv.classList.add('abierto'); return }

  mensajesDiv.innerHTML = '<div style="color:#666; font-size:12px;">Cargando…</div>'
  mensajesDiv.classList.add('abierto')

  const form = new FormData()
  form.append('action', 'mensajes_chat')
  form.append('chat_id', chatId)
  form.append('csrf_token', CSRF_TOKEN)

  try {
    const r = await fetch('estado.php', { method: 'POST', body: form })
    const d = await r.json()
    if (d.status !== 'ok') {
      mensajesDiv.innerHTML = '<div style="color:#e74c3c; font-size:12px;">Error al cargar los mensajes.</div>'
      return
    }
    mensajesDiv.innerHTML = d.mensajes.map(m => `
      <div class="msg-item">
        <span class="msg-rol ${m.rol}">${m.rol === 'bot' ? 'CodeMentor' : 'Usuario'}:</span>
        ${escapeHtml(m.contenido)}
        <span class="msg-fecha">${escapeHtml(m.creado_en.slice(0,16))}</span>
      </div>
    `).join('') || '<div style="color:#666; font-size:12px;">Sin mensajes.</div>'
    mensajesDiv.dataset.cargado = '1'
  } catch (e) {
    mensajesDiv.innerHTML = '<div style="color:#e74c3c; font-size:12px;">Error de conexión.</div>'
  }
}

async function eliminarChat(chatId, item, mensajesDiv) {
  if (!confirm('¿Eliminar esta conversación? No se puede deshacer.')) return

  const form = new FormData()
  form.append('action', 'eliminar_chat')
  form.append('chat_id', chatId)
  form.append('csrf_token', CSRF_TOKEN)

  try {
    const r = await fetch('estado.php', { method: 'POST', body: form })
    const d = await r.json()
    if (d.status === 'ok') {
      item.remove()
      mensajesDiv.remove()
      mostrarToast('✓ Chat eliminado')
    } else {
      mostrarToast('Error: ' + d.msg)
    }
  } catch (e) {
    mostrarToast('Error de conexión')
  }
}

async function eliminarUsuario(usuarioId, btn) {
  if (!confirm('¿Eliminar esta cuenta? Se borran también todos sus chats y mensajes. No se puede deshacer.')) return

  btn.disabled = true

  const form = new FormData()
  form.append('action', 'eliminar_usuario')
  form.append('usuario_id', usuarioId)
  form.append('csrf_token', CSRF_TOKEN)

  try {
    const r = await fetch('estado.php', { method: 'POST', body: form })
    const d = await r.json()
    if (d.status === 'ok') {
      const card = document.querySelector(`.usuario-card[data-usuario-id="${usuarioId}"]`)
      if (card) { card.style.opacity = '0'; setTimeout(() => card.remove(), 200) }
      mostrarToast('✓ Usuario eliminado')
    } else {
      btn.disabled = false
      mostrarToast('Error: ' + d.msg)
    }
  } catch (e) {
    btn.disabled = false
    mostrarToast('Error de conexión')
  }
}

function escapeHtml(str) {
  const d = document.createElement('div')
  d.textContent = str ?? ''
  return d.innerHTML
}

/* ---------------- BUSCADOR DE CONOCIMIENTO ---------------- */
const buscadorInput = document.getElementById('buscador-conocimiento')
const buscadorResultados = document.getElementById('buscador-resultados')
const btnLimpiarBuscador = document.getElementById('btn-limpiar-buscador')

// Guardamos el texto original de cada tarjeta una sola vez, para poder
// resaltar y "des-resaltar" sin perder el contenido real.
const tarjetasConocimiento = Array.from(document.querySelectorAll('#tab-conocimiento .card')).map(card => {
  const elConcepto    = card.querySelector('.card-concepto')
  const elDescripcion = card.querySelector('.card-descripcion')
  const elPregOrig    = card.querySelector('.card-pregunta-orig')
  return {
    card,
    elConcepto,    concepto:    elConcepto    ? elConcepto.textContent    : '',
    elDescripcion, descripcion: elDescripcion ? elDescripcion.textContent : '',
    elPregOrig,    pregOrig:    elPregOrig    ? elPregOrig.textContent    : '',
  }
})

function normalizarTexto(s) {
  return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
}

// Misma idea que quitarIntroRelleno() en buscar.php: "Quiero crear un
// chatbot", "Necesito hacer un blog", etc. comparten el mismo arranque
// genérico pero hablan de temas totalmente distintos. Antes, buscar
// "Quiero crear" en el panel traía de todo (streaming, páginas web,
// chatbots...) porque el filtro comparaba contra ese arranque literal
// en vez de contra el tema real. Se recorta SOLO al principio del
// texto de búsqueda, nunca en cualquier posición.
function quitarIntroRelleno(texto) {
  const verbosDeseo   = 'quiero|quisiera|queria|necesito|necesitaria|deseo|megustaria|me gustaria|meencantaria|me encantaria'
  const conectorAyuda = '(?:que me ayudes a |ayudame a |ayuda (?:con|para) )?'
  const verboAccion   = 'crear|hacer|armar|construir|desarrollar|empezar|iniciar|arrancar|programar|implementar|aprender|saber|entender|comprender|hacerme|hacerte'

  const patronDeseo = new RegExp(`^(?:${verbosDeseo})\\s+${conectorAyuda}(?:${verboAccion})?\\s*`)
  const patronComo  = new RegExp(`^(?:como puedo|como hago para|de que manera puedo|de que forma puedo)\\s+(?:${verboAccion})?\\s*`)

  let anterior = null
  while (anterior !== texto) {
    anterior = texto
    texto = texto.replace(patronDeseo, '').replace(patronComo, '').trim()
  }
  return texto
}

// Reemplaza el contenido del elemento marcando la primera coincidencia
// con <mark>, sin tocar el resto del texto original.
function resaltarCoincidencia(el, textoOriginal, query) {
  if (!el) return
  if (!query) { el.textContent = textoOriginal; return }

  const norm  = normalizarTexto(textoOriginal)
  const qNorm = normalizarTexto(query)
  const idx   = norm.indexOf(qNorm)

  if (idx === -1) { el.textContent = textoOriginal; return }

  el.innerHTML = ''
  el.append(document.createTextNode(textoOriginal.slice(0, idx)))
  const marca = document.createElement('mark')
  marca.textContent = textoOriginal.slice(idx, idx + query.length)
  el.append(marca)
  el.append(document.createTextNode(textoOriginal.slice(idx + query.length)))
}

function filtrarConocimiento() {
  const query = buscadorInput.value.trim()
  const qNorm = quitarIntroRelleno(normalizarTexto(query))
  btnLimpiarBuscador.style.display = query ? 'flex' : 'none'

  let totalVisibles = 0

  tarjetasConocimiento.forEach(t => {
    const coincide = !qNorm
      || normalizarTexto(t.concepto).includes(qNorm)
      || normalizarTexto(t.descripcion).includes(qNorm)
      || normalizarTexto(t.pregOrig).includes(qNorm)

    t.card.classList.toggle('oculta-busqueda', !coincide)
    if (coincide) totalVisibles++

    resaltarCoincidencia(t.elConcepto, t.concepto, query)
    resaltarCoincidencia(t.elDescripcion, t.descripcion, query)
    resaltarCoincidencia(t.elPregOrig, t.pregOrig, query)
  })

  // Ocultar secciones (archivos) que se quedaron sin ninguna tarjeta visible
  document.querySelectorAll('#tab-conocimiento .seccion').forEach(sec => {
    const visibles = sec.querySelectorAll('.card:not(.oculta-busqueda)').length
    sec.classList.toggle('oculta-busqueda', visibles === 0)
  })

  buscadorResultados.textContent = !query
    ? ''
    : (totalVisibles === 0
        ? `Sin resultados para "${query}"`
        : `${totalVisibles} resultado${totalVisibles === 1 ? '' : 's'} para "${query}"`)
}

if (buscadorInput) {
  buscadorInput.addEventListener('input', filtrarConocimiento)
}

function limpiarBusqueda() {
  buscadorInput.value = ''
  filtrarConocimiento()
  buscadorInput.focus()
}
</script>
</body>
</html>
