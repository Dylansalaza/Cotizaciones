<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Rutas.php';
require_once __DIR__ . '/../includes/Camiones.php';
require_once __DIR__ . '/../includes/Itinerarios.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (empty($_SESSION['cotizacion'])) {
        throw new Exception('Primero debes subir un archivo de cotización.');
    }

    $ciudades = $_SESSION['cotizacion']['ciudades'];
    $rutasConfig = Rutas::cargar();

    foreach ($ciudades as &$c) {
        $c['ruta_id'] = Rutas::buscarRutaDeCiudad($c['ciudad'], $rutasConfig);
    }
    unset($c);

    $resultado = [];
    $sinAsignar = [];

    foreach ($ciudades as $c) {
        if ($c['ruta_id'] === null) {
            $sinAsignar[] = $c;
            continue;
        }
        $resultado[$c['ruta_id']]['nombre'] = $rutasConfig[$c['ruta_id']]['nombre'];
        $resultado[$c['ruta_id']]['grupo'] = $rutasConfig[$c['ruta_id']]['grupo'];
        $resultado[$c['ruta_id']]['ciudades'][] = $c;
    }

    foreach ($resultado as $rutaId => &$r) {
        $pesoTotal = array_sum(array_column($r['ciudades'], 'peso_kg'));
        $volumenTotal = array_sum(array_column($r['ciudades'], 'volumen_m3'));
        $cajasTotal = array_sum(array_column($r['ciudades'], 'cajas'));

        $r['peso_total_kg'] = round($pesoTotal, 2);
        $r['peso_total_ton'] = round($pesoTotal / 1000, 3);
        $r['volumen_total_m3'] = round($volumenTotal, 3);
        $r['cajas_total'] = (int)$cajasTotal;
        $r['camion_sugerido'] = Camiones::sugerir($pesoTotal, $volumenTotal);
        $r['ruta_sugerida'] = Itinerarios::sugerirRuta($rutaId, $r['ciudades']);
    }
    unset($r);

    echo json_encode([
        'ok' => true,
        'archivo' => $_SESSION['cotizacion']['archivo'],
        'fecha' => $_SESSION['cotizacion']['fecha'],
        'rutas' => $resultado,
        'sin_asignar' => $sinAsignar,
        'catalogo_rutas' => $rutasConfig,
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}