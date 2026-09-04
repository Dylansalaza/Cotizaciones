<?php
/**
 * Ubicaciones (función independiente del cálculo de kilometraje).
 *
 * Toma las ciudades/direcciones de la cotización cargada y devuelve una lista
 * plana de puntos EXACTOS (lat/lon), cada uno con su provincia y ciudad. El
 * frontend los agrupa por provincia o por ciudad, según elija el usuario.
 * No calcula rutas, km ni precios: solo entrega los puntos para poder verlos y
 * compartirlos por WhatsApp (y, más adelante, ubicar dónde está el camión).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Geolocalizacion.php';
require_once __DIR__ . '/../includes/CotizacionParser.php';
require_once __DIR__ . '/../includes/Rutas.php';

header('Content-Type: application/json; charset=utf-8');
set_time_limit(300);

/** Texto en formato bonito (Título), respetando guiones. */
function tituloBonito(string $txt, string $fallback = ''): string
{
    $txt = trim($txt);
    if ($txt === '') return $fallback;
    return ucwords(mb_strtolower($txt, 'UTF-8'), " \t\r\n\f\v-");
}

try {
    if (empty($_SESSION['cotizacion'])) {
        throw new Exception('Primero debes subir un archivo de cotización.');
    }

    $ciudades = $_SESSION['cotizacion']['ciudades'];
    $rutasConfig = Rutas::cargar();

    $puntos = [];
    $totalPuntos = 0;
    $sinUbicar = 0;

    foreach ($ciudades as $c) {
        $ciudad = (string)($c['ciudad'] ?? '');
        if ($ciudad === '') continue;

        $provRaw = trim((string)($c['provincia'] ?? ''));
        $provNombre = tituloBonito($provRaw, 'Sin provincia');
        $provKey = CotizacionParser::normalize($provRaw !== '' ? $provRaw : 'SIN PROVINCIA');
        $ciudadNombre = tituloBonito($ciudad);
        $ciudadKey = CotizacionParser::normalize($ciudad);

        // Ruta fija a la que pertenece la ciudad (según data/rutas.json).
        $rutaId = Rutas::buscarRutaDeCiudad($ciudad, $rutasConfig);
        if ($rutaId !== null) {
            $rutaKey    = $rutaId;
            $rutaNombre = $rutasConfig[$rutaId]['nombre'] ?? $rutaId;
            $rutaGrupo  = $rutasConfig[$rutaId]['grupo'] ?? '';
        } else {
            $rutaKey    = '_sin_ruta';
            $rutaNombre = 'Sin ruta asignada';
            $rutaGrupo  = '';
        }

        // Direcciones exactas de la ciudad (Quito viene como mapa; el resto como lista).
        $dirs = is_array($c['direcciones'] ?? null) ? array_values($c['direcciones']) : [];

        if (empty($dirs)) {
            // Sin direcciones: usamos el centro de la ciudad como único punto.
            $coords = Geolocalizacion::coordsDe($ciudad);
            $totalPuntos++;
            if (!$coords) { $sinUbicar++; continue; }
            $puntos[] = [
                'provincia'     => $provNombre,
                'provincia_key' => $provKey,
                'ciudad'        => $ciudadNombre,
                'ciudad_key'    => $ciudadKey,
                'ruta'          => $rutaNombre,
                'ruta_key'      => $rutaKey,
                'ruta_grupo'    => $rutaGrupo,
                'cliente'       => '',
                'direccion'     => '',
                'cajas'         => (float)($c['cajas'] ?? 0),
                'lat'           => $coords['lat'],
                'lon'           => $coords['lon'],
                'nivel'         => $coords['nivel'] ?? 'ciudad',
            ];
            continue;
        }

        // Un punto por cada dirección de cliente.
        foreach ($dirs as $d) {
            $direccion = trim((string)($d['direccion'] ?? ''));
            $cliente   = trim((string)($d['cliente'] ?? ''));
            $coords = Geolocalizacion::coordsDe($ciudad, $direccion !== '' ? $direccion : null);
            $totalPuntos++;
            if (!$coords) { $sinUbicar++; continue; }
            $puntos[] = [
                'provincia'     => $provNombre,
                'provincia_key' => $provKey,
                'ciudad'        => $ciudadNombre,
                'ciudad_key'    => $ciudadKey,
                'ruta'          => $rutaNombre,
                'ruta_key'      => $rutaKey,
                'ruta_grupo'    => $rutaGrupo,
                'cliente'       => $cliente,
                'direccion'     => $direccion,
                'cajas'         => (float)($d['cajas'] ?? 0),
                'lat'           => $coords['lat'],
                'lon'           => $coords['lon'],
                'nivel'         => $coords['nivel'] ?? 'ciudad',
            ];
        }
    }

    echo json_encode([
        'ok'           => true,
        'puntos'       => $puntos,
        'total_puntos' => $totalPuntos,
        'sin_ubicar'   => $sinUbicar,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
