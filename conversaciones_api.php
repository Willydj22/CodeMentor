<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

$usuario = exigirUsuario(); // corta con 401 si no hay sesión

if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 100000) {
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

// Verifica que el chat pertenezca al usuario actual; corta con 404 si no.
function chatDeUsuario($pdo, $chatId, $usuarioId) {
    $stmt = $pdo->prepare('SELECT id, titulo FROM chats WHERE id = ? AND usuario_id = ?');
    $stmt->execute([$chatId, $usuarioId]);
    $chat = $stmt->fetch();
    if (!$chat) {
        responder(['status' => 'error', 'message' => 'Chat no encontrado.'], 404);
    }
    return $chat;
}

try {
    $pdo = conectar();

    switch ($accion) {

        // ── Listar los chats del usuario, más reciente primero ──────
        case 'listar': {
            $stmt = $pdo->prepare(
                'SELECT id, titulo, actualizado_en FROM chats
                 WHERE usuario_id = ? ORDER BY actualizado_en DESC LIMIT 100'
            );
            $stmt->execute([$usuario['id']]);
            responder(['status' => 'ok', 'chats' => $stmt->fetchAll()]);
        }

        // ── Crear un chat nuevo (vacío) ──────────────────────────────
        case 'crear': {
            exigirRateLimit('chats_crear');
            $stmt = $pdo->prepare('INSERT INTO chats (usuario_id, titulo) VALUES (?, ?)');
            $stmt->execute([$usuario['id'], 'Nueva conversación']);
            $id = (int) $pdo->lastInsertId();
            responder(['status' => 'ok', 'chat' => ['id' => $id, 'titulo' => 'Nueva conversación']]);
        }

        // ── Obtener los mensajes de un chat ──────────────────────────
        case 'mensajes': {
            $chatId = (int) ($data['chat_id'] ?? 0);
            if ($chatId <= 0) responder(['status' => 'error', 'message' => 'Falta chat_id.'], 400);
            chatDeUsuario($pdo, $chatId, $usuario['id']);

            $stmt = $pdo->prepare('SELECT rol, contenido, creado_en FROM mensajes WHERE chat_id = ? ORDER BY id ASC');
            $stmt->execute([$chatId]);
            responder(['status' => 'ok', 'mensajes' => $stmt->fetchAll()]);
        }

        // ── Guardar un mensaje (user o bot) en un chat ───────────────
        // Si el chat_id es 0/null, crea un chat nuevo automáticamente
        // y usa los primeros ~40 caracteres del mensaje como título.
        case 'guardar_mensaje': {
            exigirRateLimit('chats_guardar');

            $chatId    = (int) ($data['chat_id'] ?? 0);
            $rol       = ($data['rol'] ?? '') === 'bot' ? 'bot' : 'user';
            $contenido = trim((string) ($data['contenido'] ?? ''));

            if ($contenido === '') responder(['status' => 'error', 'message' => 'Mensaje vacío.'], 400);
            if (mb_strlen($contenido) > 20000) $contenido = mb_substr($contenido, 0, 20000);

            if ($chatId > 0) {
                chatDeUsuario($pdo, $chatId, $usuario['id']);
            } else {
                $titulo = mb_substr(preg_replace('/\s+/', ' ', $contenido), 0, 60);
                $stmt = $pdo->prepare('INSERT INTO chats (usuario_id, titulo) VALUES (?, ?)');
                $stmt->execute([$usuario['id'], $titulo !== '' ? $titulo : 'Nueva conversación']);
                $chatId = (int) $pdo->lastInsertId();
            }

            $stmt = $pdo->prepare('INSERT INTO mensajes (chat_id, rol, contenido) VALUES (?, ?, ?)');
            $stmt->execute([$chatId, $rol, $contenido]);

            $stmt = $pdo->prepare('UPDATE chats SET actualizado_en = NOW() WHERE id = ?');
            $stmt->execute([$chatId]);

            responder(['status' => 'ok', 'chat_id' => $chatId]);
        }

        // ── Renombrar un chat ─────────────────────────────────────────
        case 'renombrar': {
            $chatId = (int) ($data['chat_id'] ?? 0);
            $titulo = trim((string) ($data['titulo'] ?? ''));
            if ($chatId <= 0 || $titulo === '') responder(['status' => 'error', 'message' => 'Datos inválidos.'], 400);
            $titulo = mb_substr($titulo, 0, 120);

            chatDeUsuario($pdo, $chatId, $usuario['id']);
            $stmt = $pdo->prepare('UPDATE chats SET titulo = ? WHERE id = ?');
            $stmt->execute([$titulo, $chatId]);
            responder(['status' => 'ok']);
        }

        // ── Eliminar un chat (y sus mensajes, por CASCADE) ───────────
        case 'eliminar': {
            $chatId = (int) ($data['chat_id'] ?? 0);
            if ($chatId <= 0) responder(['status' => 'error', 'message' => 'Falta chat_id.'], 400);

            chatDeUsuario($pdo, $chatId, $usuario['id']);
            $stmt = $pdo->prepare('DELETE FROM chats WHERE id = ? AND usuario_id = ?');
            $stmt->execute([$chatId, $usuario['id']]);
            responder(['status' => 'ok']);
        }

        default:
            responder(['status' => 'error', 'message' => 'Acción inválida.'], 400);
    }
} catch (Throwable $e) {
    error_log('chats.php: ' . $e->getMessage());
    responder(['status' => 'error', 'message' => 'Error interno.'], 500);
}
