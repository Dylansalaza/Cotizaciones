<?php
/**
 * Recalcula el kilometraje REAL (geolocalización + ruteo por carretera) de
 * todas las rutas del sistema y actualiza:
 *
 *   - data/distancias.json  : km reales desde el hub (Amaguaña) a cada ciudad.
 *   - data/itinerarios.json : km_tramo real entre paradas consecutivas, en el
 *                             orden de entrega ya configurado de cada ruta.
 *
 * Para las ciudades sin dirección exacta se usa el nombre de la ciudad como
 * referencia de geocodificación (todas las rutas: Sierra, Costa, Oriente,
 * Ruta 2 y Pichincha).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Rutas.php';
require_once __DIR__ . '/../includes/Itinerarios.php';
require_once __DIR__ . '/../includes/Geolocalizacion.php';
require_once __DIR__ . '/../includes/CotizacionParser.php';

header('Content-Type: application/json; charset=utf-8');
set_time_limit(300); // el proceso puede tardar por las llamadas a los servicios

try {
    $hub = Geolocalizacion::hub();
    if ($hub === null) {
        throw new Exception('Falta configurar el hub (Amaguaña) en data/coordenadas.json (clave "_hub").');
    }

    $rutas = Rutas::cargar();
    $itinerarios = Itinerarios::cargar();

    $distancias = [];   // CIUDAD => km reales desde el hub (para distancias.json)
    $detalle = [];      // filas para la tabla del frontend
    $errores = [];      // ciudades que no se pudieron geolocalizar
    $hayAprox = false;  // si algún tramo cayó al respaldo (sin OSRM)

    // Cache local de coords ya resueltas en esta corrida (evita recomputar).
    $coordCache = [];
    $coordDe = function (string $ciudad) use (&$coordCache, &$errores) {
        $clave = CotizacionParser::normalize($ciudad);
        if (array_key_exists($clave, $coordCache)) return $coordCache[$clave];
        $c = Geolocalizacion::coordsDe($ciudad);
        $coordCache[$clave] = $c;
        if ($c === null) $errores[$clave] = $ciudad;
        return $c;
    };

    // Fuente del orden de paradas: itinerarios.json (secuencia de entrega). Las
    // rutas sin itinerario usan el orden de sus lugares en rutas.json.
    $ordenPorRuta = [];
    foreach ($rutas as $rutaId => $ruta) {
        if (!empty($itinerarios[$rutaId])) {
            $ordenPorRuta[$rutaId] = array_map(fn($p) => $p['ciudad'], $itinerarios[$rutaId]);
        } else {
            $ordenPorRuta[$rutaId] = $ruta['lugares'] ?? [];
        }
    }

    $nuevosItinerarios = $itinerarios;

    foreach ($ordenPorRuta as $rutaId => $ciudades) {
        $nombreRuta = $rutas[$rutaId]['nombre'] ?? $rutaId;
        $anterior = $hub; // el primer tramo se mide desde el hub
        $tramos = [];

        foreach ($ciudades as $ciudad) {
            $coords = $coordDe($ciudad);
            $clave = CotizacionParser::normalize($ciudad);

            if ($coords === null) {
                // No se pudo geolocalizar: se conserva la parada sin km real.
                $tramos[] = ['ciudad' => $ciudad, 'km_tramo' => 0, 'estimado' => true, 'sin_geo' => true];
                continue;
            }

            // Km del tramo (desde la parada anterior) y km desde el hub.
            $tramo = Geolocalizacion::kmConduccion($anterior, $coords);
            $desdeHub = Geolocalizacion::kmConduccion($hub, $coords);
            if ($tramo['aprox'] || $desdeHub['aprox']) $hayAprox = true;

            $distancias[$clave] = (int)round($desdeHub['km']);

            $tramos[] = [
                'ciudad'    => $ciudad,
                'km_tramo'  => $tramo['km'],
                'estimado'  => $tramo['aprox'], // real => false; respaldo => true
            ];

            $detalle[] = [
                'zona'              => $nombreRuta,
                'referencia'        => $coords['referencia'] !== '' ? $coords['referencia'] : $ciudad,
                'km_desde_amaguana' => $desdeHub['km'],
            ];

            $anterior = $coords;
        }

        if (!empty($tramos)) {
            $nuevosItinerarios[$rutaId] = $tramos;
        }
    }

    // Persistir resultados reales.
    Itinerarios::guardar($nuevosItinerarios);
    ksort($distancias);
    file_put_contents(
        __DIR__ . '/../data/distancias.json',
        json_encode($distancias, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );

    $kmTotalSuma = array_sum(array_column($detalle, 'km_desde_amaguana'));

    echo json_encode([
        'ok' => true,
        'hub' => $hub['nombre'],
        'detalle' => $detalle,
        'km_total_suma' => round($kmTotalSuma, 2),
        'ciudades_actualizadas' => count($detalle),
        'aproximado' => $hayAprox, // true si algún tramo usó respaldo sin OSRM
        'errores' => array_values($errores),
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
