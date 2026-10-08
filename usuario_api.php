<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

iniciarSesionSegura();

if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 20000) {
    echo json_encode(['status' => 'error', 'message' => 'Solicitud demasiado grande.']);
    exit;
}

$data   = json_decode(file_get_contents('php://input'), true) ?: [];
$accion = $data['accion'] ?? ($_GET['accion'] ?? '');

function responder($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr);
    exit;
}

function normalizarEmail($email) {
    return mb_strtolower(trim((string) $email), 'UTF-8');
}

switch ($accion) {

    // ── Estado actual de la sesión ──────────────────────────────
    case 'me': {
        $u = usuarioActual();
        if (!$u) responder(['status' => 'ok', 'autenticado' => false]);
        responder(['status' => 'ok', 'autenticado' => true, 'usuario' => $u]);
    }

    // ── Registro ─────────────────────────────────────────────────
    case 'registro': {
        exigirRateLimit('auth_registro');

        $nombre = trim((string) ($data['nombre'] ?? ''));
        $email  = normalizarEmail($data['email'] ?? '');
        $pass   = (string) ($data['password'] ?? '');

        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 80) {
            responder(['status' => 'error', 'message' => 'El nombre debe tener entre 2 y 80 caracteres.']);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            responder(['status' => 'error', 'message' => 'El email no es válido.']);
        }
        if (mb_strlen($pass) < 8) {
            responder(['status' => 'error', 'message' => 'La contraseña debe tener al menos 8 caracteres.']);
        }

        try {
            $pdo = conectar();

            $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                responder(['status' => 'error', 'message' => 'Ya existe una cuenta con ese email.']);
            }

            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO usuarios (nombre, email, password_hash) VALUES (?, ?, ?)');
            $stmt->execute([$nombre, $email, $hash]);
            $id = (int) $pdo->lastInsertId();

            // Regenerar el ID de sesión al pasar de anónimo a autenticado (evita session fixation)
            session_regenerate_id(true);
            $_SESSION['usuario_id']     = $id;
            $_SESSION['usuario_nombre'] = $nombre;
            $_SESSION['usuario_email']  = $email;

            responder(['status' => 'ok', 'usuario' => ['id' => $id, 'nombre' => $nombre, 'email' => $email]]);
        } catch (Throwable $e) {
            error_log('auth registro: ' . $e->getMessage());
            responder(['status' => 'error', 'message' => 'No se pudo crear la cuenta. Intentá de nuevo.'], 500);
        }
    }

    // ── Login ────────────────────────────────────────────────────
    case 'login': {
        exigirRateLimit('auth_login');

        $email = normalizarEmail($data['email'] ?? '');
        $pass  = (string) ($data['password'] ?? '');

        if ($email === '' || $pass === '') {
            responder(['status' => 'error', 'message' => 'Completá el email y la contraseña.']);
        }

        try {
            $pdo = conectar();
            $stmt = $pdo->prepare('SELECT id, nombre, email, password_hash FROM usuarios WHERE email = ?');
            $stmt->execute([$email]);
            $u = $stmt->fetch();

            // Mensaje genérico a propósito: no revelar si el email existe o no.
            if (!$u || !password_verify($pass, $u['password_hash'])) {
                responder(['status' => 'error', 'message' => 'Email o contraseña incorrectos.']);
            }

            session_regenerate_id(true);
            $_SESSION['usuario_id']     = (int) $u['id'];
            $_SESSION['usuario_nombre'] = $u['nombre'];
            $_SESSION['usuario_email']  = $u['email'];

            responder(['status' => 'ok', 'usuario' => ['id' => (int) $u['id'], 'nombre' => $u['nombre'], 'email' => $u['email']]]);
        } catch (Throwable $e) {
            error_log('auth login: ' . $e->getMessage());
            responder(['status' => 'error', 'message' => 'No se pudo iniciar sesión. Intentá de nuevo.'], 500);
        }
    }

    // ── Logout ───────────────────────────────────────────────────
    case 'logout': {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        responder(['status' => 'ok']);
    }

    default:
        responder(['status' => 'error', 'message' => 'Acción inválida.'], 400);
}
