<?php
/**
 * Configuración de la clave de Google Maps Geocoding API.
 *
 * La clave se guarda en /config.local.php (un archivo PHP que el servidor
 * ejecuta y NO sirve como texto), de modo que no queda expuesta públicamente
 * como pasaría con un .json bajo /data.
 *
 *   ?accion=estado  -> { ok, configurada: bool }
 *   POST accion=guardar, api_key=...  -> guarda la clave
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Geolocalizacion.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $accion = $_REQUEST['accion'] ?? 'estado';

    if ($accion === 'estado') {
        echo json_encode(['ok' => true, 'configurada' => Geolocalizacion::hayGoogle()]);
        exit;
    }

    if ($accion === 'guardar') {
        $key = trim($_REQUEST['api_key'] ?? '');
        if ($key === '') {
            throw new Exception('Debes ingresar la clave de la API.');
        }
        // Validación de forma básica (evita guardar basura). Las claves de
        // Google son alfanuméricas con - y _.
        if (!preg_match('/^[A-Za-z0-9_\-]{20,}$/', $key)) {
            throw new Exception('La clave no tiene un formato válido de Google API Key.');
        }
        $php = "<?php\n// Generado automáticamente. No compartir este archivo.\nreturn ['google_api_key' => '" . addslashes($key) . "'];\n";
        $ruta = __DIR__ . '/../config.local.php';
        if (file_put_contents($ruta, $php) === false) {
            throw new Exception('No se pudo guardar la clave (permisos de escritura).');
        }
        echo json_encode(['ok' => true, 'configurada' => true]);
        exit;
    }

    throw new Exception('Acción no reconocida.');
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
