<?php
// ── config.php — Configuración centralizada y credenciales ─────
// Este archivo NO debe ser accesible directamente desde el navegador.
// Ver protección en .htaccess.

// Todos los endpoints de la API (gemini.php, buscar.php, guardar.php,
// etc.) cargan este archivo primero, así que este es el lugar correcto
// para asegurar que NINGÚN aviso de PHP (Notice/Warning/Deprecated) se
// imprima directo en la salida — eso rompe el JSON que espera el
// frontend (pasó con "Deprecated: curl_close()" en PHP 8.5+ en Termux,
// pero puede pasar con cualquier otro aviso, en cualquier entorno).
// Se siguen registrando en el log de errores del servidor para poder
// debuggear, solo que ya no van al navegador.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Si alguien intenta acceder a este archivo directamente por URL, cortar
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Acceso denegado.');
}

// ── Configuración y secretos ───────────────────────────────────
// Este archivo NO contiene claves. Cada valor se lee, en este orden, de:
//   1) una variable de entorno (ideal en producción), o
//   2) config.local.php (ignorado por Git), o
//   3) el valor por defecto vacío de abajo.
// Lista completa de variables en .env.example.

// Valores locales opcionales: en hostings compartidos que no permiten
// variables de entorno, copia config.local.example.php a config.local.php
// y rellénalo. Ese archivo está en .gitignore y nunca se sube al repo.
$GLOBALS['CODEMENTOR_LOCAL'] = is_file(__DIR__ . '/config.local.php')
    ? (array) (require __DIR__ . '/config.local.php')
    : [];

function env_o(string $var, string $fallback = ''): string {
    $v = getenv($var);
    if ($v !== false && $v !== '') return $v;
    $l = $GLOBALS['CODEMENTOR_LOCAL'][$var] ?? '';
    return ($l !== '') ? (string) $l : $fallback;
}

// ── Base de datos MySQL ──────────────────────────────────────
define('DB_HOST', env_o('DB_HOST', 'localhost'));
define('DB_PORT', (int) env_o('DB_PORT', '3306'));
define('DB_NAME', env_o('DB_NAME', 'codementor'));
define('DB_USER', env_o('DB_USER', ''));
define('DB_PASS', env_o('DB_PASS', ''));



define('YOUTUBE_API_KEY', env_o('YOUTUBE_API_KEY', ''));



// ── API Gemini (motor principal) ──────────────────────────────
define('GEMINI_API_KEY', env_o('GEMINI_API_KEY', ''));
define('GEMINI_MODELO',  env_o('GEMINI_MODELO', 'gemini-2.5-flash'));

// ── API Groq (respaldo cuando se agota Gemini) ─────────────────
// Lista de claves de Groq (cada una de una cuenta distinta = cuota
// diaria independiente). Para sumar una nueva cuenta de respaldo,
// solo hay que agregar otra línea acá; no hace falta tocar gemini.php.
define('GROQ_API_KEYS', array_values(array_filter([
    env_o('GROQ_API_KEY_1', ''),
    env_o('GROQ_API_KEY_2', ''),
    env_o('GROQ_API_KEY_3', ''),
    env_o('GROQ_API_KEY_4', ''),
], fn($k) => $k !== '')));
define('GROQ_MODELO', env_o('GROQ_MODELO', 'openai/gpt-oss-120b'));

define('SUPADATA_API_KEY', env_o('SUPADATA_API_KEY', ''));

define('SERPAPI_KEY', env_o('SERPAPI_KEY', ''));

define('CONTEXT_DEV_API_KEY', env_o('CONTEXT_DEV_API_KEY', ''));










// ── API Pixabay (búsqueda de imágenes) ───────────────────────
define('PIXABAY_API_KEY', env_o('PIXABAY_API_KEY', ''));

define('VOICERSS_API_KEY', env_o('VOICERSS_API_KEY', ''));
// ── API remove.bg (quitar fondo de imágenes) ──────────────────
// Antes vivía hardcodeada directamente en remove_background.php
define('REMOVE_BG_API_KEY', env_o('REMOVE_BG_API_KEY', ''));

// ── WhatsApp Cloud API (Meta) ──────────────────────────────────
// El token temporal dura 24h — cuando lo cambies por uno permanente
// (System User Token, no vence), solo actualizás este valor acá.
// IMPORTANTE: pegá tus valores reales directo en el servidor, no
// los mandes por chat/email — ya tratá el token con el mismo
// cuidado que las otras claves de este archivo.
define('WHATSAPP_TOKEN',              env_o('WHATSAPP_TOKEN',              ''));
define('WHATSAPP_PHONE_NUMBER_ID',    env_o('WHATSAPP_PHONE_NUMBER_ID',    ''));
define('WHATSAPP_BUSINESS_ACCOUNT_ID',env_o('WHATSAPP_BUSINESS_ACCOUNT_ID',''));
// Este lo inventás vos mismo (cualquier string), y tiene que coincidir
// EXACTO con el que pongas en Meta Developers al configurar el webhook.
define('WHATSAPP_VERIFY_TOKEN',       env_o('WHATSAPP_VERIFY_TOKEN',       ''));


// ── Panel de administración (estado.php) ─────────────────────
// Define ADMIN_PASSWORD_HASH (recomendado) o ADMIN_PASSWORD. Si ambos
// están vacíos, el panel queda bloqueado (ver estado.php).
define('ADMIN_PASSWORD', env_o('ADMIN_PASSWORD', ''));

// Recomendado: en vez de dejar la contraseña en texto plano acá,
// generá un hash y ponelo en ADMIN_PASSWORD_HASH (env var o abajo).
// estado.php usa el hash automáticamente si está definido; si no,
// cae al texto plano de arriba por compatibilidad. Para generarlo:
//   php -r "echo password_hash('tu-nueva-contraseña', PASSWORD_DEFAULT);"
define('ADMIN_PASSWORD_HASH', env_o('ADMIN_PASSWORD_HASH', ''));

// ── Rate limiting ─────────────────────────────────────────────
define('RATE_LIMIT_DIR', __DIR__ . '/rate_limit_data');
define('RATE_LIMIT_MAX_REQUESTS', 20);   // máximo de requests
define('RATE_LIMIT_WINDOW_SEG',   60);   // por ventana de tiempo (segundos)

// ── Sesiones de usuario (login/registro) ──────────────────────
// Cookie de sesión propia para no chocar con la del panel admin
// (estado.php usa la sesión PHP por defecto). Se usa desde auth.php
// y chats.php para que ambos vean la misma sesión de usuario.
function iniciarSesionSegura() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30, // 30 días
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('codementor_usuario');
    session_start();
}

function usuarioActual() {
    iniciarSesionSegura();
    if (empty($_SESSION['usuario_id'])) return null;
    return [
        'id'     => (int) $_SESSION['usuario_id'],
        'nombre' => $_SESSION['usuario_nombre'] ?? '',
        'email'  => $_SESSION['usuario_email'] ?? '',
    ];
}

// Corta la ejecución con 401 si no hay sesión de usuario activa.
function exigirUsuario() {
    $u = usuarioActual();
    if (!$u) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Necesitás iniciar sesión.']);
        exit;
    }
    return $u;
}

function conectar() {
    // PDO::ATTR_TIMEOUT no siempre controla el tiempo de la conexión
    // inicial (depende del driver/mysqlnd) — por eso primero probamos
    // con fsockopen, que sí respeta un timeout corto y confiable. Si el
    // host no responde (p. ej. sin internet), fallamos rápido acá en
    // vez de dejar que PDO se cuelgue.
    $conexion = @fsockopen(DB_HOST, DB_PORT, $errno, $errstr, 1.5);
    if ($conexion === false) {
        throw new PDOException("No se pudo alcanzar el host de base de datos (sin conexión): {$errstr}");
    }
    fclose($conexion);

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT
         . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $opciones = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT             => 2,
    ];
    return new PDO($dsn, DB_USER, DB_PASS, $opciones);
}

// ── Rate limiting simple basado en archivos ──────────────────
// Devuelve true si la IP puede continuar, false si excedió el límite
function rateLimitPermitir($clave) {
    if (!is_dir(RATE_LIMIT_DIR)) mkdir(RATE_LIMIT_DIR, 0755, true);

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
    $archivo = RATE_LIMIT_DIR . '/' . md5($ip . '_' . $clave) . '.json';

    $ahora = time();
    $registros = [];

    if (file_exists($archivo)) {
        $contenido = @file_get_contents($archivo);
        $registros = json_decode($contenido, true) ?: [];
    }

    // Filtrar solo los timestamps dentro de la ventana activa
    $registros = array_filter($registros, fn($t) => ($ahora - $t) < RATE_LIMIT_WINDOW_SEG);

    if (count($registros) >= RATE_LIMIT_MAX_REQUESTS) {
        return false;
    }

    $registros[] = $ahora;
    @file_put_contents($archivo, json_encode(array_values($registros)));
    return true;
}

// Corta la ejecución con 429 si se excedió el límite
function exigirRateLimit($clave) {
    if (!rateLimitPermitir($clave)) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Demasiadas solicitudes. Esperá un momento antes de volver a intentar.']);
        exit;
    }
}

// ── Protección SSRF ────────────────────────────────────────────
// context_images.php y supadata.php reciben una URL del cliente y el
// SERVIDOR la va a buscar (server-side fetch). Sin este chequeo, alguien
// podría mandar una URL que apunte a la red interna del hosting
// (localhost, IPs privadas, metadata de la nube, etc.) y usar el
// servidor como proxy para escanear o acceder a esos recursos internos.
// Esta función valida que el host de la URL resuelva a una IP pública.
function urlEsSegura($url) {
    $partes = parse_url($url);
    if (!$partes || empty($partes['scheme']) || !in_array(strtolower($partes['scheme']), ['http', 'https'], true)) {
        return false;
    }
    $host = $partes['host'] ?? '';
    if ($host === '') return false;

    // Si el host ya es una IP literal, validarla directamente.
    // Si es un dominio, resolverlo y validar la(s) IP(s) reales.
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (empty($ips)) return false;

    foreach ($ips as $ip) {
        $esPublica = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
        if ($esPublica === false) return false; // cualquier IP privada/reservada/loopback => rechazar
    }
    return true;
}
