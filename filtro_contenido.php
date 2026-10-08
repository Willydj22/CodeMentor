<?php
// ── filtro_contenido.php ─────────────────────────────────────
// Filtro básico de contenido para pedidos de generación/búsqueda de
// imágenes. Pollinations.ai no tiene ningún filtro propio (genera
// literalmente lo que se le pida), así que esto corta el pedido ANTES
// de gastar tiempo/créditos llamando a la API.
//
// No pretende ser perfecto ni "a prueba de balas" (nada basado en
// lista de palabras lo es), pero frena el uso casual/directo que es
// el caso más común. Se puede complementar más adelante revisando la
// imagen ya generada con algún servicio de moderación de imágenes.

if (!function_exists('normalizarParaFiltro')) {
    function normalizarParaFiltro($texto) {
        $texto = mb_strtolower($texto, 'UTF-8');
        // Sacar tildes/diacríticos para no depender de cómo lo tipeen
        $texto = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto) ?: $texto;
        // Normalizar separadores comunes usados para esquivar filtros
        // (n.u.d.a, des-nuda, nu_da) a espacios/nada
        $texto = preg_replace('/[._\-*]+/', '', $texto);
        return $texto;
    }
}

if (!function_exists('contienePedidoNSFW')) {
    function contienePedidoNSFW($texto) {
        $t = normalizarParaFiltro($texto);

        // Términos explícitos de desnudez/contenido sexual (español + inglés).
        // Uso \b donde tiene sentido; varios ya son raíces (p.ej. "desnud")
        // para cubrir desnuda/desnudo/desnudez/desnudarse, etc.
        $terminos = [
            // desnudez / cuerpo
            'desnud', 'nude', 'naked', 'nsfw', 'sin ropa', 'sin nada puesto',
            'topless', 'nalgas desnudas', 'senos descubiertos', 'pechos desnudos',
            // sexual explícito
            'sexo explicito', 'sexual explicito', 'porno', 'pornografia', 'porn',
            'xxx', 'erotic', 'erotica', 'erotico', 'hentai', 'ecchi',
            'masturbaci', 'masturbat', 'orgasmo', 'penetracion', 'felacion',
            'genitales', 'vagina', 'pene erecto', 'erecto', 'senos grandes desnudos',
            'onlyfans',
            // fetiche / bondage explícito
            'bondage sexual', 'fetiche sexual',
        ];

        foreach ($terminos as $termino) {
            $terminoNorm = normalizarParaFiltro($termino);
            if ($terminoNorm !== '' && mb_strpos($t, $terminoNorm) !== false) {
                return true;
            }
        }

        // Bloqueo reforzado: cualquier combinación de "menor de edad" +
        // insinuación de desnudez/sexual, sin importar si algún término
        // suelto de arriba no matcheó. Esto nunca se debe poder saltear.
        $palabrasMenor = ['nina', 'nino', 'menor de edad', 'adolescente', 'colegiala', 'preadolescente'];
        $palabrasSexual = ['desnud', 'sexual', 'sexo', 'sin ropa', 'erotic'];
        foreach ($palabrasMenor as $pm) {
            if (mb_strpos($t, normalizarParaFiltro($pm)) !== false) {
                foreach ($palabrasSexual as $ps) {
                    if (mb_strpos($t, normalizarParaFiltro($ps)) !== false) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
