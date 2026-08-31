<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Rutas.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $accion = $_REQUEST['accion'] ?? '';

    if ($accion === 'listar') {
        echo json_encode(['ok' => true, 'rutas' => Rutas::cargar()]);
        exit;
    }

    if ($accion === 'eliminar_ruta') {
        $rutaId = trim($_REQUEST['ruta_id'] ?? '');
        if ($rutaId === '') {
            throw new Exception('Debes indicar la ruta a eliminar.');
        }
        $rutas = Rutas::eliminarRuta($rutaId);
        echo json_encode(['ok' => true, 'rutas' => $rutas]);
        exit;
    }

    if ($accion === 'eliminar_lugar') {
        $rutaId = trim($_REQUEST['ruta_id'] ?? '');
        $lugar = trim($_REQUEST['lugar'] ?? '');
        if ($rutaId === '' || $lugar === '') {
            throw new Exception('Debes indicar la ruta y el lugar a eliminar.');
        }
        $rutas = Rutas::eliminarLugar($rutaId, $lugar);
        echo json_encode(['ok' => true, 'rutas' => $rutas]);
        exit;
    }

    if ($accion === 'crear_ruta') {
        $rutaId = trim($_REQUEST['ruta_id'] ?? '');
        $nombre = trim($_REQUEST['nombre'] ?? '');
        $grupo = trim($_REQUEST['grupo'] ?? '');
        
        if ($rutaId === '' || $nombre === '') {
            throw new Exception('Debes indicar el ID y nombre de la ruta.');
        }
        
        $rutas = Rutas::crearRuta($rutaId, $nombre, $grupo);
        echo json_encode(['ok' => true, 'rutas' => $rutas]);
        exit;
    }

    if ($accion === 'agregar_lugar') {
        $rutaId = trim($_REQUEST['ruta_id'] ?? '');
        $lugar = trim($_REQUEST['lugar'] ?? '');
        
        if ($rutaId === '' || $lugar === '') {
            throw new Exception('Debes indicar la ruta y el lugar a agregar.');
        }
        
        $rutas = Rutas::agregarLugar($rutaId, $lugar);
        echo json_encode(['ok' => true, 'rutas' => $rutas]);
        exit;
    }

    throw new Exception('Acción no reconocida.');
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}