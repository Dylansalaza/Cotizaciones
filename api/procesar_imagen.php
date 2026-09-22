<?php
/**
 * Procesa una FOTO/imagen de una tabla "por local" (con columna RUTA) y la
 * convierte en la cotización, sin necesidad de tener el Excel. Usa la API de
 * Claude (visión) para leer la tabla y arma la misma estructura de "ciudades"
 * que el resto de la app, lista para el recorrido de entrega por ruta.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/VisionExtractor.php';

header('Content-Type: application/json; charset=utf-8');
set_time_limit(300);

try {
    if (!isset($_FILES['imagen']) || $_FILES['imagen']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No se recibió la imagen correctamente.');
    }

    $tmpName = $_FILES['imagen']['tmp_name'];
    $originalName = $_FILES['imagen']['name'];
    $mime = (string)($_FILES['imagen']['type'] ?? '');

    // Validar que sea una imagen admitida.
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $extValidas = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $esImagen = strpos($mime, 'image/') === 0 || in_array($ext, $extValidas, true);
    if (!$esImagen) {
        throw new Exception('Solo se admiten imágenes (.jpg, .png, .webp, .gif).');
    }

    // Guardar la imagen subida en uploads (para pasar la ruta al lector).
    $destino = UPLOAD_DIR . uniqid('img_') . ($ext !== '' ? '.' . $ext : '.jpg');
    if (!move_uploaded_file($tmpName, $destino)) {
        // En algunos entornos move_uploaded_file puede fallar; usar copy como respaldo.
        if (!@copy($tmpName, $destino)) {
            throw new Exception('No se pudo guardar la imagen subida.');
        }
    }

    try {
        $ciudades = VisionExtractor::procesarImagen($destino, $mime);
    } finally {
        @unlink($destino); // borrar la imagen subida pase lo que pase
    }

    if (empty($ciudades)) {
        throw new Exception('No se encontraron datos utilizables en la imagen.');
    }

    $_SESSION['cotizacion'] = [
        'archivo' => $originalName,
        'fecha' => date('Y-m-d H:i:s'),
        'ciudades' => $ciudades,
    ];
    echo json_encode(['ok' => true, 'ciudades' => $ciudades], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
