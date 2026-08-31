<?php
/**
 * Resultado por ruta con kilometraje real POR DESTINATARIO (dirección exacta).
 * Recorre cada dirección de cada cliente; para la Ruta Interna Pichincha calcula
 * además el ORDEN ÓPTIMO de visita (vecino más cercano + 2-opt) desde el hub.
 * Devuelve, por ruta, los tramos con km acumulado, los totales y el precio.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Rutas.php';
require_once __DIR__ . '/../includes/Camiones.php';
require_once __DIR__ . '/../includes/Itinerarios.php';
require_once __DIR__ . '/../includes/Geolocalizacion.php';
require_once __DIR__ . '/../includes/CotizacionParser.php';

header('Content-Type: application/json; charset=utf-8');
set_time_limit(300);

// Nombres bonitos para la columna "Ciudades" del tarifario.
function nombreBonito(string $ciudad): string
{
    static $mapa = [
        'LATACUNGA' => 'Latacunga', 'PUJILI' => 'Pujilí', 'AMBATO' => 'Ambato', 'PELILEO' => 'Pelileo',
        'RIOBAMBA' => 'Riobamba', 'CUENCA' => 'Cuenca', 'LOJA' => 'Loja', 'EL EMPALME' => 'El Empalme',
        'MILAGRO' => 'Milagro', 'BUCAY' => 'Bucay', 'ALFREDO BAQUERIZO MORENO-JUJAN' => 'Alfredo Baquerizo Moreno (Jujan)',
        'GUAYAQUIL' => 'Guayaquil', 'LA LIBERTAD' => 'La Libertad', 'MACHALA' => 'Machala', 'PINAS' => 'Piñas',
        'SANTO DOMINGO' => 'Santo Domingo', 'CHONE' => 'Chone', 'MANTA' => 'Manta', 'QUITO' => 'Quito',
        'CAYAMBE' => 'Cayambe', 'CUYABENO' => 'Cuyabeno', 'SANTA ELENA' => 'Santa Elena', 'EL CARMEN' => 'El Carmen',
        'GUALACEO' => 'Gualaceo', 'LAGO AGRIO' => 'Lago Agrio', 'PUYO' => 'Puyo',
    ];
    $k = CotizacionParser::normalize($ciudad);
    return $mapa[$k] ?? ucwords(mb_strtolower($ciudad, 'UTF-8'));
}

try {
    if (empty($_SESSION['cotizacion'])) {
        throw new Exception('Primero debes subir un archivo de cotización.');
    }
    $hub = Geolocalizacion::hub();
    if ($hub === null) {
        throw new Exception('Falta configurar el hub (Amaguaña) en data/coordenadas.json.');
    }
    $hubPt = ['lat' => $hub['lat'], 'lon' => $hub['lon']];

    $ciudades = $_SESSION['cotizacion']['ciudades'];
    $rutasConfig = Rutas::cargar();
    $itinerarios = Itinerarios::cargar();

    // Agrupar por ruta.
    $porRuta = [];
    $sinAsignar = [];
    foreach ($ciudades as $c) {
        $rutaId = Rutas::buscarRutaDeCiudad($c['ciudad'], $rutasConfig);
        if ($rutaId === null) { $sinAsignar[] = $c; continue; }
        $porRuta[$rutaId][] = $c;
    }

    $resultado = [];
    foreach ($porRuta as $rutaId => $ciudadesRuta) {
        // Orden de ciudades (itinerario); Pichincha se optimiza aparte.
        $ordenItin = [];
        if (!empty($itinerarios[$rutaId])) {
            foreach ($itinerarios[$rutaId] as $i => $paso) {
                $ordenItin[CotizacionParser::normalize($paso['ciudad'])] = $i;
            }
        }
        usort($ciudadesRuta, function ($a, $b) use ($ordenItin) {
            $ia = $ordenItin[CotizacionParser::normalize($a['ciudad'])] ?? 999;
            $ib = $ordenItin[CotizacionParser::normalize($b['ciudad'])] ?? 999;
            return $ia <=> $ib;
        });

        // Construir las paradas (una por dirección de cliente).
        $paradas = [];
        foreach ($ciudadesRuta as $c) {
            $dirs = is_array($c['direcciones'] ?? null) ? array_values($c['direcciones']) : [];
            if (empty($dirs)) {
                $coords = Geolocalizacion::coordsDe($c['ciudad']);
                if ($coords) $paradas[] = ['ciudad' => $c['ciudad'], 'cliente' => '', 'direccion' => '', 'coords' => $coords, 'nivel' => 'ciudad'];
                continue;
            }
            foreach ($dirs as $d) {
                $direccion = trim((string)($d['direccion'] ?? ''));
                $coords = Geolocalizacion::coordsDe($c['ciudad'], $direccion !== '' ? $direccion : null);
                if (!$coords) continue;
                $paradas[] = [
                    'ciudad' => $c['ciudad'], 'cliente' => $d['cliente'] ?? '',
                    'direccion' => $direccion, 'coords' => $coords, 'nivel' => $coords['nivel'] ?? 'ciudad',
                ];
            }
        }

        // Orden ÓPTIMO de visita para TODAS las rutas (vecino más cercano + 2-opt),
        // empezando en Amaguaña. Matriz de distancias reales entre hub + paradas.
        $pts = [$hubPt];
        foreach ($paradas as $p) $pts[] = ['lat' => $p['coords']['lat'], 'lon' => $p['coords']['lon']];
        $M = !empty($paradas) ? Geolocalizacion::matrizConduccion($pts) : null;

        $optimizada = false;
        $ordenIdx = count($paradas) ? range(1, count($paradas)) : []; // por defecto: como lista
        if (count($paradas) > 2 && $M) {
            $ordenIdx = array_values(array_filter(optimizarRuta($M), fn($i) => $i > 0));
            $optimizada = true;
        }

        // Tramos: Amaguaña → 1er punto → 2do punto → …  (cada tramo desde el punto anterior).
        $tramos = [];
        $acumulado = 0;
        $anteriorCoords = $hubPt;
        $prevIdx = 0;
        foreach ($ordenIdx as $idx) {
            $p = $paradas[$idx - 1];
            $km = $M ? $M[$prevIdx][$idx] : Geolocalizacion::kmConduccion($anteriorCoords, $p['coords'])['km'];
            $acumulado = round($acumulado + $km, 1);
            $tramos[] = [
                'ciudad' => $p['ciudad'], 'cliente' => $p['cliente'], 'direccion' => $p['direccion'],
                'km_tramo' => round($km, 1), 'km_acumulado' => $acumulado, 'nivel' => $p['nivel'],
            ];
            $anteriorCoords = $p['coords'];
            $prevIdx = $idx;
        }

        $pesoTotal = array_sum(array_map(fn($c) => (float)($c['peso_kg'] ?? 0), $ciudadesRuta));
        $volTotal = array_sum(array_map(fn($c) => (float)($c['volumen_m3'] ?? 0), $ciudadesRuta));
        $cajasTotal = array_sum(array_map(fn($c) => (float)($c['cajas'] ?? 0), $ciudadesRuta));
        $totalIda = $acumulado;
        $mult = ($rutaId === 'ruta1_oriente') ? 1.25 : 1.0;

        // Lista de ciudades (únicas, orden itinerario) para el tarifario.
        $nombresCiudades = [];
        foreach ($ciudadesRuta as $c) {
            $nb = nombreBonito($c['ciudad']);
            if (!in_array($nb, $nombresCiudades, true)) $nombresCiudades[] = $nb;
        }

        $resultado[$rutaId] = [
            'nombre' => $rutasConfig[$rutaId]['nombre'] ?? $rutaId,
            'grupo' => $rutasConfig[$rutaId]['grupo'] ?? '',
            'ciudades' => $ciudadesRuta,
            'ciudades_lista' => implode(', ', $nombresCiudades),
            'peso_total_kg' => round($pesoTotal, 2),
            'peso_total_ton' => round($pesoTotal / 1000, 3),
            'volumen_total_m3' => round($volTotal, 3),
            'cajas_total' => (int)$cajasTotal,
            'camion_sugerido' => Camiones::sugerir($pesoTotal, $volTotal),
            'ruta_sugerida' => [
                'tramos' => $tramos,
                'km_total_ida' => round($totalIda, 1),
                'km_total_ida_vuelta' => round($totalIda * 2, 1),
            ],
            'multiplicador' => $mult,
            'precio' => round($totalIda * $mult, 2),
            'optimizada' => $optimizada,
        ];
    }

    $_SESSION['ruta_direcciones'] = [
        'fecha' => date('d/m/Y H:i'),
        'archivo' => $_SESSION['cotizacion']['archivo'] ?? '',
        'rutas' => $resultado,
    ];

    echo json_encode(['ok' => true, 'rutas' => $resultado, 'sin_asignar' => $sinAsignar], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/** Longitud de una ruta (suma de tramos consecutivos). */
function _pathLen(array $p, array $M): float
{
    $s = 0.0;
    for ($i = 0; $i < count($p) - 1; $i++) $s += $M[$p[$i]][$p[$i + 1]];
    return $s;
}

/**
 * Ruta abierta óptima desde el nodo 0 (hub): vecino más cercano + mejora 2-opt.
 * $M = matriz NxN de distancias. Devuelve el orden de índices (empieza en 0).
 */
function optimizarRuta(array $M): array
{
    $n = count($M);
    $vis = [0]; $used = array_fill(0, $n, false); $used[0] = true; $cur = 0;
    for ($k = 1; $k < $n; $k++) {
        $best = -1; $bd = INF;
        for ($x = 0; $x < $n; $x++) if (!$used[$x] && $M[$cur][$x] < $bd) { $bd = $M[$cur][$x]; $best = $x; }
        $vis[] = $best; $used[$best] = true; $cur = $best;
    }
    $improved = true;
    while ($improved) {
        $improved = false;
        for ($i = 1; $i < $n - 1; $i++) {
            for ($k = $i + 1; $k < $n; $k++) {
                $nw = array_merge(array_slice($vis, 0, $i), array_reverse(array_slice($vis, $i, $k - $i + 1)), array_slice($vis, $k + 1));
                if (_pathLen($nw, $M) + 0.01 < _pathLen($vis, $M)) { $vis = $nw; $improved = true; }
            }
        }
    }
    return $vis;
}
