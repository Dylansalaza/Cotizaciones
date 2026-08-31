<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/CotizacionParser.php';
require_once __DIR__ . '/../includes/Rutas.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if (!isset($_FILES['excel']) || $_FILES['excel']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No se recibió el archivo correctamente.');
    }

    $tmpName = $_FILES['excel']['tmp_name'];
    $originalName = $_FILES['excel']['name'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($ext !== 'xlsx') throw new Exception('Solo se admiten archivos .xlsx.');
    
    $destino = UPLOAD_DIR . uniqid('cot_') . '.xlsx';
    move_uploaded_file($tmpName, $destino);
    $ciudades = CotizacionParser::parse($destino);

    if (empty($ciudades)) {
        throw new Exception('No se encontraron datos en el Excel.');
    }

    $_SESSION['cotizacion'] = [
        'archivo' => $originalName,
        'fecha' => date('Y-m-d H:i:s'),
        'ciudades' => $ciudades,
    ];
    echo json_encode(['ok' => true, 'ciudades' => $ciudades]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}