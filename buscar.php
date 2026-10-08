<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_codementor.php';
exigirRateLimit('buscar');

$configCodeMentor = cargarConfigCodeMentor();

$data     = json_decode(file_get_contents('php://input'), true);
$pregunta = isset($data['pregunta']) ? trim($data['pregunta']) : '';
$maximo   = isset($data['maximo'])   ? (int) $data['maximo']  : (int) $configCodeMentor['maximo_resultados'];
$offline  = isset($data['offline']) && $data['offline'] === true;
if ($maximo < 1)  $maximo = 5;
if ($maximo > 20) $maximo = 20;

if ($pregunta === '') {
    echo json_encode(['status' => 'ok', 'resultados' => []]);
    exit;
}

// ── Match exacto ────────────────────────────────────────────
// Se compara el texto normalizado (minúsculas, sin acentos, sin
// signos, espacios colapsados) de la pregunta del usuario contra
// 'pregunta' y 'concepto' de cada item. Solo cuenta si son
// EXACTAMENTE iguales una vez normalizados — no hay tolerancia a
// fraseo distinto, sinónimos, ni coincidencia parcial.
function esMatchExacto($pregunta, $item) {
    $preguntaNorm = normalizar($pregunta);
    if ($preguntaNorm === '') return false;

    $candidatosTexto = [$item['pregunta'] ?? '', $item['concepto'] ?? '', $item['_pregunta_original'] ?? ''];
    foreach ($candidatosTexto as $texto) {
        if ($texto === '') continue;
        if (normalizar($texto) === $preguntaNorm) return true;
    }
    return false;
}

// ── Normalización ────────────────────────────────────────────
function normalizar($texto) {
    $texto = mb_strtolower($texto, 'UTF-8');
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $texto);
    if ($t !== false) $texto = $t;
    $texto = preg_replace('/[¿?¡!.,;:()\"\'\-]/', ' ', $texto);
    $texto = preg_replace('/\s+/', ' ', $texto);
    $texto = trim($texto);
    $texto = quitarIntroRelleno($texto);
    return trim($texto);
}

// ── Frases de relleno al inicio de la pregunta ────────────────
// "Quiero crear un chatbot", "Quiero hacer un blog", "Necesito armar
// una tienda online"... comparten el mismo arranque genérico pero
// después hablan de temas totalmente distintos entre sí. Si ese
// arranque queda como texto/tokens normales, dos preguntas terminan
// pareciéndose solo por compartir "quiero crear" — que no dice nada
// del tema real — y eso ensucia tanto el buscador del panel (según lo
// visto en la práctica: buscar "Quiero crear" traía de todo, sin
// relación entre sí) como el matching aproximado de acá abajo.
//
// Se recorta SOLO al principio del texto, nunca en cualquier posición:
// si alguien pregunta por algo que en el medio de la frase menciona
// "crear" o "hacer" con otro sentido, no se toca.
function quitarIntroRelleno($texto) {
    $verbosDeseo  = 'quiero|quisiera|queria|necesito|necesitaria|deseo|megustaria|me gustaria|meencantaria|me encantaria';
    $conectorAyuda = '(?:que me ayudes a |ayudame a |ayuda (?:con|para) )?';
    $verboAccion  = 'crear|hacer|armar|construir|desarrollar|empezar|iniciar|arrancar|programar|implementar|aprender|saber|entender|comprender|hacerme|hacerte|profundizar|ampliar|explicar|detallar|repasar|revisar|practicar|mejorar';

    $patronDeseo = '/^(?:' . $verbosDeseo . ')\s+' . $conectorAyuda . '(?:' . $verboAccion . ')?\s*/u';
    $patronComo  = '/^(?:como puedo|como hago para|de que manera puedo|de que forma puedo)\s+(?:' . $verboAccion . ')?\s*/u';

    // En bucle por si se encadenan ("hola quiero saber como puedo crear...")
    $anterior = null;
    while ($anterior !== $texto) {
        $anterior = $texto;
        $texto = preg_replace($patronDeseo, '', $texto);
        $texto = preg_replace($patronComo, '', $texto);
        $texto = trim($texto);
    }
    return $texto;
}

$STOPWORDS = array_flip([
    "el","la","los","las","un","una","unos","unas","de","del","al","a",
    "en","con","por","para","que","se","su","sus","le","les","me","mi",
    "nos","es","y","o","pero","si","como","mas","muy","ya","no",
    "cual","cuales","quien","donde","cuando",
    "este","esta","estos","estas","ese","esa","esos","esas",
    "hay","fue","ser","son","era","han","he","ha","te","tu",
    "yo","ella","eso","esto","lo","tan","sin","sobre",
    "entre","hasta","desde","hacia","tambien","todo","todos",
    // Vocativos y relleno: el usuario casi siempre nombra al bot o saluda,
    // y esas palabras no aportan nada para distinguir de qué está preguntando.
    "codementor","frost","ia","bot","hola","hey","ey","oye","che","buenas","buenos",
    "porfa","porfavor","dale","gracias","amigo","amiga"
]);

// Stopwords propias agregadas desde el panel (Configuración), además de
// las de fábrica de arriba.
if (!empty($configCodeMentor['stopwords_extra'])) {
    foreach (explode(',', $configCodeMentor['stopwords_extra']) as $palabra) {
        $palabra = trim(mb_strtolower($palabra, 'UTF-8'));
        if ($palabra !== '') $STOPWORDS[$palabra] = true;
    }
}

// ── Sinónimos: palabras que significan lo mismo se reducen a una sola ──
// ("invierto un texto" y "invertir una cadena" quedan igual). Se aplica a la
// pregunta y a las entradas por igual, dentro de tokens().
$GRUPOS_SINONIMOS = [
    "crear" => ["crea", "genera", "generar", "haz", "hazme", "hacer", "construye", "construir", "desarrolla", "desarrollar", "escribe", "escribir", "escribeme", "implementa", "implementar"],
    "declarar" => ["declaras", "declaro", "declara", "declare", "declarando", "declaracion"],
    "javascript" => ["js"],
    "python" => ["py"],
    "android" => ["androide"],
    "ios" => ["iphone"],
    "invertir" => ["invierto", "inviertes", "invierte", "invertido", "invertida", "invirtiendo", "revertir", "reverso", "revierto", "reversa", "reverse"],
    "cadena" => ["texto", "textos", "string", "strings", "cadenas"],
    "arreglo" => ["array", "arrays", "lista", "listas", "vector", "arreglos"],
    "bucle" => ["ciclo", "ciclos", "loop", "bucles"],
    "funcion" => ["funciones", "function", "func"],
    "ordenar" => ["ordena", "ordeno", "ordenamiento", "sort", "ordenado", "clasificar"],
    "imprimir" => ["imprime", "imprimo", "print", "mostrar", "muestra", "muestro"],
    "sumar" => ["suma", "sumo", "sumas"],
    "eliminar" => ["borrar", "borro", "elimina", "quitar", "remover"],
    "buscar" => ["busca", "busco", "encontrar", "encuentra", "hallar"],
    "consulta" => ["query", "queries", "consultas"],
    "pila" => ["stack", "pilas"],
    "cola" => ["queue", "colas"],
    "primo" => ["primos"],
    "clase" => ["clases", "class"],
    "objeto" => ["objetos", "object"],
    "variable" => ["variables"],
    "convertir" => ["convierte", "convierto", "transformar", "transforma"],
    "longitud" => ["largo", "tamano", "length"],
    "numero" => ["numeros"],
    "empleados" => ["empleado", "trabajadores"],
    "salario" => ["sueldo", "salarios", "sueldos"],
];
$SINONIMOS = [];
foreach ($GRUPOS_SINONIMOS as $canon => $lista) {
    foreach ($lista as $w) $SINONIMOS[$w] = $canon;
}
// Acciones concretas: si la pregunta pide una y la entrada habla de otra
// ("borrar una variable" vs "declarar una variable"), no es la misma pregunta.
// Tecnologías: si la pregunta nombra una (ios, android, python…) y la entrada
// nombra otras distintas ("apps para ANDROID" vs "apps para iOS"), no coinciden.
$TECNOLOGIAS = ["ios","android","python","java","javascript","typescript","php","sql","mysql","html","css","react","swift","kotlin","ruby","bash","linux","git","flutter","angular","vue","django","flask","laravel","wordpress","jquery"];
$ACCIONES = ["invertir","ordenar","imprimir","sumar","eliminar","buscar","convertir","declarar"];
foreach (["dame","muestrame","necesito","quiero","puedes","podrias"] as $w) $STOPWORDS[$w] = true;

function tokens($texto) {
    global $STOPWORDS, $SINONIMOS;
    $norm = normalizar($texto);
    if ($norm === '') return [];
    $palabras = explode(' ', $norm);
    $out = [];
    foreach ($palabras as $p) {
        if (mb_strlen($p) > 1 && !isset($STOPWORDS[$p])) $out[] = $SINONIMOS[$p] ?? $p;
    }
    return $out;
}

function similitudJaccard($a, $b) {
    global $configCodeMentor, $ACCIONES, $TECNOLOGIAS;
    $ta = array_values(array_unique(tokens($a)));
    $tb = array_values(array_unique(tokens($b)));
    if (!count($ta) || !count($tb)) return 0.0;
    $setA = array_flip($ta);
    $inter = 0;
    foreach ($tb as $t) if (isset($setA[$t])) $inter++;
    $union = count($ta) + count($tb) - $inter;
    $jaccard = $union > 0 ? $inter / $union : 0.0;

    $bgA = [];
    for ($i = 0; $i < count($ta)-1; $i++) $bgA[] = $ta[$i].'_'.$ta[$i+1];
    $bgB = [];
    for ($i = 0; $i < count($tb)-1; $i++) $bgB[] = $tb[$i].'_'.$tb[$i+1];
    $bgInter = count(array_intersect($bgA, $bgB));
    $bigramScore = count($bgA) > 0 ? $bgInter / count($bgA) : 0.0;

    $pesoJaccard = $configCodeMentor['peso_jaccard'] ?? 0.70;
    $score = $jaccard * $pesoJaccard + $bigramScore * (1 - $pesoJaccard);

    $accQ = array_values(array_intersect($ta, $ACCIONES));
    $accB = array_values(array_intersect($tb, $ACCIONES));
    sort($accQ); sort($accB);
    if (count($accQ) > 0 && $accQ !== $accB) $score *= 0.5;

    $tecQ = array_intersect($ta, $TECNOLOGIAS);
    $tecB = array_intersect($tb, $TECNOLOGIAS);
    if (count($tecQ) > 0 && count($tecB) > 0 && count(array_intersect($tecQ, $tecB)) === 0) $score *= 0.4;

    return $score;
}

// ── Leer y puntuar un archivo .jsonl contra la pregunta ────────
function leerYPuntuarJsonl($ruta, $pregunta, &$candidatos) {
    if (!file_exists($ruta)) return;
    $fh = fopen($ruta, 'r');
    if (!$fh) return;
    while (($linea = fgets($fh)) !== false) {
        $linea = trim($linea);
        if ($linea === '') continue;
        $item = json_decode($linea, true);
        if (!is_array($item)) continue;
        if (!isset($item['pregunta']) && !isset($item['concepto'])) continue;

        if (esMatchExacto($pregunta, $item)) {
            $candidatos[] = ['item' => $item, 'score' => 1.0];
        }
    }
    fclose($fh);
}

// Todos los .jsonl de conocimiento: el histórico en la raíz + todos
// los archivos mensuales que guardar.php va creando en conocimiento_data/
// (antes acá solo se leía conocimiento.jsonl y por eso nunca se
// encontraban las respuestas nuevas: se guardaban en conocimiento_data
// pero buscar.php no miraba esa carpeta).
function archivosDeConocimiento() {
    $archivos = [__DIR__ . '/conocimiento.jsonl'];
    $carpeta = __DIR__ . '/conocimiento_data';
    if (is_dir($carpeta)) {
        foreach (glob($carpeta . '/*.jsonl') as $f) {
            $archivos[] = $f;
        }
    }
    return $archivos;
}


// ── Fallback aproximado sobre un array de items ya cargado ─────
function puntuarAproximado($items, $pregunta, $umbral, &$candidatos, &$vistos) {
    foreach ($items as $item) {
        $score = max(
            similitudJaccard($pregunta, $item['pregunta'] ?? ''),
            similitudJaccard($pregunta, $item['concepto'] ?? '') * 0.9,
            similitudJaccard($pregunta, $item['_pregunta_original'] ?? '')
        );
        if ($score >= $umbral) {
            $clave = normalizar($item['concepto'] ?? ($item['pregunta'] ?? ''));
            if (isset($vistos[$clave])) continue;
            $vistos[$clave] = true;
            $candidatos[] = ['item' => $item, 'score' => $score];
        }
    }
}

// ── Fallback aproximado leyendo un .jsonl línea por línea ───────
function puntuarAproximadoJsonl($ruta, $pregunta, $umbral, &$candidatos, &$vistos) {
    if (!file_exists($ruta)) return;
    $fh = fopen($ruta, 'r');
    if (!$fh) return;
    $items = [];
    while (($linea = fgets($fh)) !== false) {
        $linea = trim($linea);
        if ($linea === '') continue;
        $item = json_decode($linea, true);
        if (is_array($item)) $items[] = $item;
    }
    fclose($fh);
    puntuarAproximado($items, $pregunta, $umbral, $candidatos, $vistos);
}

// 1. Pre-filtro en MySQL: traer ítems que compartan al menos
//    una palabra clave con la pregunta (LIKE sobre concepto o pregunta)
$palabrasClave = tokens($pregunta);
$candidatos = [];
$UMBRAL_APROXIMADO = (float) $configCodeMentor['umbral_aproximado']; // usado solo si el match exacto no encontró nada

if ($offline) {
    // El navegador ya nos avisó que no hay red: ni intentamos MySQL,
    // vamos directo a los .jsonl locales (exacto, y si no hay nada, aproximado).
    foreach (archivosDeConocimiento() as $archivo) {
        leerYPuntuarJsonl($archivo, $pregunta, $candidatos);
    }
    if (count($candidatos) === 0) {
        $vistos = [];
        foreach (archivosDeConocimiento() as $archivo) {
            puntuarAproximadoJsonl($archivo, $pregunta, $UMBRAL_APROXIMADO, $candidatos, $vistos);
        }
    }
} else {

try {
    $pdo = conectar();

    if (count($palabrasClave) > 0) {
        // Construir cláusula WHERE dinámica
        $condiciones = [];
        $params = [];
        foreach ($palabrasClave as $i => $pal) {
            $condiciones[] = "(concepto LIKE :p{$i} OR pregunta LIKE :p{$i}b)";
            $params[":p{$i}"]  = "%{$pal}%";
            $params[":p{$i}b"] = "%{$pal}%";
        }
        $where = implode(' OR ', $condiciones);
        $sql = "SELECT * FROM conocimiento WHERE {$where} LIMIT 100";
    } else {
        $sql = "SELECT * FROM conocimiento LIMIT 100";
        $params = [];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $filas = $stmt->fetchAll();

    // Calcular score de similitud en PHP sobre los pre-filtrados
    foreach ($filas as $item) {
        if (esMatchExacto($pregunta, $item)) {
            $candidatos[] = ['item' => $item, 'score' => 1.0];
        }
    }

    // También leer los .jsonl de conocimiento (raíz + conocimiento_data/)
    foreach (archivosDeConocimiento() as $archivo) {
        leerYPuntuarJsonl($archivo, $pregunta, $candidatos);
    }

    // Fallback aproximado: solo si el match exacto no encontró nada
    if (count($candidatos) === 0) {
        $vistos = [];
        puntuarAproximado($filas, $pregunta, $UMBRAL_APROXIMADO, $candidatos, $vistos);
        foreach (archivosDeConocimiento() as $archivo) {
            puntuarAproximadoJsonl($archivo, $pregunta, $UMBRAL_APROXIMADO, $candidatos, $vistos);
        }
    }

} catch (PDOException $e) {
    // Si MySQL falla (p. ej. offline, sin acceso al host remoto), responder
    // solo con los .jsonl — primero match exacto, y si no hay nada, aproximado.
    // Este es el camino real cuando se usa CodeMentor local en Termux sin internet.
    foreach (archivosDeConocimiento() as $archivo) {
        leerYPuntuarJsonl($archivo, $pregunta, $candidatos);
    }
    if (count($candidatos) === 0) {
        $vistos = [];
        foreach (archivosDeConocimiento() as $archivo) {
            puntuarAproximadoJsonl($archivo, $pregunta, $UMBRAL_APROXIMADO, $candidatos, $vistos);
        }
    }
}

} // fin del if (!$offline)

// ── Ordenar y devolver ────────────────────────────────────────
// En caso de empate de score (p. ej. entradas duplicadas), gana la
// que tenga más contenido desarrollado (descripcion/sintaxis/ejemplo/etc.)
function riquezaContenido($item) {
    $campos = ['descripcion', 'sintaxis', 'ejemplo', 'errores', 'buenas_practicas'];
    $len = 0;
    foreach ($campos as $c) $len += mb_strlen($item[$c] ?? '');
    return $len;
}

usort($candidatos, function($a, $b) {
    $cmp = $b['score'] <=> $a['score'];
    if ($cmp !== 0) return $cmp;
    return riquezaContenido($b['item']) <=> riquezaContenido($a['item']);
});
$top = array_slice($candidatos, 0, $maximo);
$resultados = array_map(function($c) {
    $item = $c['item'];
    $item['_score'] = round($c['score'], 4);
    return $item;
}, $top);

$respuesta = ['status' => 'ok', 'resultados' => $resultados];

// Si el request manda "debug": true, se agrega qué configuración se usó
// realmente en ESTE request — sirve para confirmar, sin adivinar, que el
// panel y buscar.php están hablando del mismo archivo config_codementor.json
// (y no una versión vieja cacheada o un deploy a medio subir).
if (isset($data['debug']) && $data['debug'] === true) {
    $respuesta['_debug'] = [
        'config_usada'        => $configCodeMentor,
        'archivo_config'      => CONFIG_CODEMENTOR_ARCHIVO,
        'archivo_config_existe' => is_file(CONFIG_CODEMENTOR_ARCHIVO),
        'umbral_aplicado_a_este_request' => $UMBRAL_APROXIMADO,
    ];
}

echo json_encode($respuesta);
