<?php
// ── config_codementor.php — Configuración ajustable del comportamiento de CodeMentor ──
// A diferencia de config.php (credenciales/infra, fijas en el servidor),
// esto son parámetros de comportamiento del matching que el admin puede
// tocar desde el panel (estado.php, pestaña "Configuración") sin editar
// código ni redesplegar.
//
// Se guardan en un JSON dentro de conocimiento_data/ — esa carpeta ya
// está protegida por su propio .htaccess (Deny from all / Require all
// denied), así que este archivo queda tan a salvo de acceso directo por
// URL como el resto del conocimiento.

define('CONFIG_CODEMENTOR_ARCHIVO', __DIR__ . '/conocimiento_data/config_codementor.json');

// Valores de fábrica: si el archivo todavía no existe, o le falta alguna
// clave (porque una actualización agregó un parámetro nuevo), se completa
// con esto.
function configCodeMentorPorDefecto() {
    return [
        // 0.0–1.0: qué tan parecida tiene que ser una pregunta para
        // aceptar el match aproximado (el fallback que se usa SOLO
        // cuando el match exacto no encontró nada).
        'umbral_aproximado' => 0.60,

        // 0.0–1.0: cuánto pesa la similitud de palabras sueltas (Jaccard)
        // en el score aproximado; el resto (1 − esto) es el peso de los
        // bigramas (orden/contexto de las palabras). Ver similitudJaccard()
        // en buscar.php.
        'peso_jaccard' => 0.70,

        // Resultados que devuelve /buscar.php cuando el cliente no pide
        // una cantidad puntual (sigue pudiendo pedir hasta 20 igual).
        'maximo_resultados' => 5,

        // Palabras propias a ignorar en el matching, ADEMÁS de las de
        // fábrica (vocativos, muletillas). Separadas por coma.
        'stopwords_extra' => '',

        // 0.0–1.0: ESTE es el umbral que de verdad decide si se reutiliza
        // una respuesta ya guardada en vez de gastar crédito llamando a la
        // IA. Es un umbral DISTINTO de 'umbral_aproximado' de arriba: ese
        // controla qué candidatos devuelve /buscar.php en general (para
        // listarlos, para el panel, etc.); este otro lo usa el frontend
        // (index.html) para decidir "¿esta respuesta local alcanza, o
        // mando la pregunta a la IA?". A propósito suele ser más exigente
        // que 'umbral_aproximado' — un match débil puede servir para
        // mostrarlo como sugerencia, pero no para dárselo al usuario como
        // si fuera LA respuesta sin que la IA la revise.
        'umbral_evitar_ia' => 0.60,
    ];
}

// Lee la config guardada y la completa con los valores de fábrica para
// cualquier clave faltante o vieja que ya no se use.
function cargarConfigCodeMentor() {
    $defecto = configCodeMentorPorDefecto();
    if (!is_file(CONFIG_CODEMENTOR_ARCHIVO)) return $defecto;

    $contenido = @file_get_contents(CONFIG_CODEMENTOR_ARCHIVO);
    $guardado  = $contenido !== false ? json_decode($contenido, true) : null;
    if (!is_array($guardado)) return $defecto;

    // array_merge (defecto primero) completa claves nuevas sin romper lo
    // ya guardado; array_intersect_key descarta claves viejas que ya no
    // se usan, para que el JSON no vaya acumulando basura con el tiempo.
    return array_intersect_key(array_merge($defecto, $guardado), $defecto);
}

// Guarda solo las claves que correspondan, mezcladas sobre lo que ya
// había (así un guardado parcial no borra el resto de la config).
// Devuelve true/false según si la escritura realmente ocurrió.
function guardarConfigCodeMentor($datos) {
    $carpeta = dirname(CONFIG_CODEMENTOR_ARCHIVO);
    if (!is_dir($carpeta)) @mkdir($carpeta, 0755, true);

    $actual = cargarConfigCodeMentor();
    $nuevo  = array_merge($actual, array_intersect_key($datos, configCodeMentorPorDefecto()));

    $ok = @file_put_contents(
        CONFIG_CODEMENTOR_ARCHIVO,
        json_encode($nuevo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
    return $ok !== false;
}
