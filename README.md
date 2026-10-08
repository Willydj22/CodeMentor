# CodeMentor

Asistente de programación en español con chat web. Backend en PHP, base de datos MySQL y motores de IA con respaldo automático.

## Qué hace

- **Chat con IA** con historial por usuario (registro e inicio de sesión).
- **Respaldo entre motores:** Gemini como principal; si se agota la cuota, pasa a las cuentas de Groq en orden.
- **Base de conocimiento propia:** busca en MySQL (y en archivos `.jsonl` locales sin conexión) antes de recurrir a la IA.
- **Panel de administración** (`estado.php`) y entrenamiento automático.
- **Rate limiting:** 20 solicitudes por minuto por IP y endpoint (ventana deslizante, basada en archivos).
- Extras opcionales: búsqueda de imágenes y video, texto a voz, generación de imágenes.

## Seguridad

- Consultas preparadas (PDO) en todas las consultas con datos del usuario.
- Contraseñas con `password_hash` / `password_verify`.
- Validación de URLs contra SSRF antes de que el servidor las visite.
- Sin secretos en el repositorio: todo se lee de variables de entorno o de `config.local.php` (ignorado por Git).
- El panel de admin queda **bloqueado** hasta que definas una contraseña.

## Instalación

Requisitos: PHP 8+ con extensiones `curl`, `pdo_mysql` y `mbstring`; MySQL/MariaDB.

1. Clona el repo y crea la base de datos:
   ```
   mysql -u USUARIO -p NOMBRE_BD < schema.sql
   ```
2. Configura los secretos, con **una** de estas opciones:
   - Variables de entorno (ver `.env.example`), o
   - `cp config.local.example.php config.local.php` y rellénalo (para hostings compartidos).
3. Genera el hash de la contraseña de admin y ponlo en `ADMIN_PASSWORD_HASH`:
   ```
   php -r "echo password_hash('tu-contraseña', PASSWORD_DEFAULT);"
   ```
4. Arranca en local:
   ```
   php -S localhost:8000
   ```
   y abre `http://localhost:8000`.

Mínimo para que funcione el chat: la base de datos y `GEMINI_API_KEY` o al menos un `GROQ_API_KEY_1`.

## Estructura

| Archivo | Función |
|---|---|
| `index.html` | Interfaz del chat |
| `gemini.php` | Endpoint principal: Gemini → Groq |
| `buscar.php` | Búsqueda en la base de conocimiento |
| `conversaciones_api.php`, `usuario_api.php` | Chats y usuarios |
| `estado.php` | Panel de administración |
| `config.php` | Configuración, rate limit y conexión a BD |
| `schema.sql` | Esquema de la base de datos |

## Limitaciones conocidas

- `estado.php` es un archivo muy grande y debería dividirse en módulos.
- El rate limit con archivos puede superarse levemente bajo solicitudes simultáneas (usar `flock()` o Redis lo resolvería).
- No hay tests automatizados.
- `schema.sql` se infirió de las consultas del código; revísalo contra tu base real.
