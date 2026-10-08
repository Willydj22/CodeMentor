<?php
// imagen_cache.php — caché compartido de imágenes entre buscar_imagenes.php
// (fotos reales de Pixabay) y generar_imagen.php (IA). Ambos usan la misma
// carpeta y la misma clave (término normalizado): así, una foto encontrada
// con "buscá imágenes de X" puede reaparecer si más tarde piden "generá una
// imagen de X" con una descripción parecida, sin gastar créditos de IA.
if (!defined('CACHE_IMAGENES_DIR')) {
    define('CACHE_IMAGENES_DIR', __DIR__ . '/imagenes_generadas/');
}

if (!function_exists('normalizarTermino')) {
    function normalizarTermino($termino) {
        $t = mb_strtolower(trim($termino), 'UTF-8');
        $translit = @iconv('UTF-8', 'ASCII//TRANSLIT', $t); // saca tildes/diacríticos
        if ($translit !== false) $t = $translit;
        $t = preg_replace('/\s+/', ' ', $t);
        return trim($t);
    }
}

if (!function_exists('rutasCacheImagen')) {
    function rutasCacheImagen($termino) {
        $clave = md5(normalizarTermino($termino));
        return [
            CACHE_IMAGENES_DIR . $clave . '.bin',
            CACHE_IMAGENES_DIR . $clave . '.json',
        ];
    }
}

if (!function_exists('hayImagenEnCache')) {
    function hayImagenEnCache($termino) {
        [$rutaBin, $rutaMeta] = rutasCacheImagen($termino);
        return is_file($rutaBin) && is_file($rutaMeta);
    }
}

// Guarda una imagen en el caché compartido. No pisa una ya existente: gana
// la primera que llegó, sea de una búsqueda o de una generación con IA.
// Devuelve true si quedó guardada (ahora o de antes), false si no se pudo
// escribir (por ejemplo, sin permisos de escritura en el hosting).
if (!function_exists('guardarImagenEnCache')) {
    function guardarImagenEnCache($termino, $binario, $mime, $motor) {
        if (hayImagenEnCache($termino)) return true;
        [$rutaBin, $rutaMeta] = rutasCacheImagen($termino);
        if (!@is_dir(CACHE_IMAGENES_DIR)) @mkdir(CACHE_IMAGENES_DIR, 0755, true);
        if (!@is_writable(CACHE_IMAGENES_DIR)) return false;
        $okBin  = @file_put_contents($rutaBin, $binario) !== false;
        $okMeta = @file_put_contents($rutaMeta, json_encode([
            'mime'    => $mime,
            'termino' => $termino,
            'motor'   => $motor,
            'fecha'   => date('c'),
        ])) !== false;
        return $okBin && $okMeta;
    }
}
