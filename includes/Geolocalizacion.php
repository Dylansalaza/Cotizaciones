<?php
require_once __DIR__ . '/CotizacionParser.php';

/**
 * Geolocalización real de ciudades/sectores y cálculo de distancia por
 * carretera (no en línea recta) entre dos puntos.
 *
 *  - Geocodificación: Nominatim (OpenStreetMap). Convierte un nombre de
 *    ciudad o una dirección exacta en coordenadas {lat, lon}.
 *  - Distancia de conducción: OSRM (Open Source Routing Machine), servidor
 *    público de demostración. Devuelve kilómetros reales de recorrido en auto.
 *
 * Las coordenadas se guardan en data/coordenadas.json (caché permanente) para
 * no volver a geocodificar la misma ciudad. Las distancias de conducción se
 * guardan en data/distancias_cache.json.
 *
 * El "hub" (origen de todos los recorridos) es el Centro de Distribución en
 * Amaguaña, definido bajo la clave "_hub" de coordenadas.json.
 */
class Geolocalizacion
{
    private const NOMINATIM = 'https://nominatim.openstreetmap.org/search';
    private const OSRM = 'https://router.project-osrm.org/route/v1/driving/';
    private const OSRM_TABLE = 'https://router.project-osrm.org/table/v1/driving/';
    private const GOOGLE = 'https://maps.googleapis.com/maps/api/geocode/json';
    private const GOOGLE_DM = 'https://maps.googleapis.com/maps/api/distancematrix/json';
    private const USER_AGENT = 'cotizador-encomiendas/1.0 (logistica interna)';

    // Robustez de red: en hostings gratuitos (p. ej. Render) los servicios
    // públicos de geolocalización (Nominatim/Photon/OSRM) suelen ir lentos,
    // limitar por tasa o estar bloqueados. Para que el cálculo NUNCA se quede
    // colgado y siempre termine usando las coordenadas de ciudad como respaldo:
    //  - cada petición tiene un timeout corto,
    //  - hay un presupuesto de tiempo total por cálculo, y
    //  - tras varios fallos seguidos se deja de intentar la red en esta corrida.
    private const TIME_BUDGET = 60;   // segundos máx. gastados en red por cálculo
    private const MAX_FALLOS  = 3;    // fallos seguidos antes de apagar la red

    private static bool  $redCaida   = false; // se apagó la red en esta corrida
    private static int   $fallosRed  = 0;     // fallos de conexión consecutivos
    private static ?float $limiteRed = null;  // instante límite del presupuesto

    private static function coordsPath(): string { return __DIR__ . '/../data/coordenadas.json'; }
    private static function cachePath(): string  { return __DIR__ . '/../data/distancias_cache.json'; }
    private static function configPath(): string { return __DIR__ . '/../config.local.php'; }

    /** Clave de Google Maps Geocoding API (o '' si no está configurada). */
    public static function googleKey(): string
    {
        if (defined('GOOGLE_MAPS_API_KEY') && GOOGLE_MAPS_API_KEY !== '') {
            return GOOGLE_MAPS_API_KEY;
        }
        if (is_file(self::configPath())) {
            $cfg = include self::configPath();
            if (is_array($cfg) && !empty($cfg['google_api_key'])) {
                return (string)$cfg['google_api_key'];
            }
        }
        return '';
    }

    public static function hayGoogle(): bool
    {
        return self::googleKey() !== '';
    }

    public static function cargarCoords(): array
    {
        if (!is_file(self::coordsPath())) return [];
        return json_decode((string)file_get_contents(self::coordsPath()), true) ?: [];
    }

    private static function guardarCoords(array $coords): void
    {
        file_put_contents(
            self::coordsPath(),
            json_encode($coords, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /** Coordenadas del Centro de Distribución (hub). */
    public static function hub(): ?array
    {
        $coords = self::cargarCoords();
        if (empty($coords['_hub']['lat'])) return null;
        return [
            'lat' => (float)$coords['_hub']['lat'],
            'lon' => (float)$coords['_hub']['lon'],
            'nombre' => $coords['_hub']['nombre'] ?? 'Centro de Distribución',
        ];
    }

    /**
     * Devuelve las coordenadas de una ciudad/sector. Si se pasa una dirección
     * exacta, se geocodifica esa dirección; si no, se usa solo el nombre de la
     * ciudad (comportamiento por defecto de todas las rutas). El resultado se
     * cachea en coordenadas.json bajo la clave normalizada de la ciudad.
     *
     * @return array|null ['lat','lon','fuente','referencia'] o null si falla.
     */
    public static function coordsDe(string $ciudad, ?string $direccion = null): ?array
    {
        $coords = self::cargarCoords();
        $clave = CotizacionParser::normalize($ciudad);

        // Coordenadas de la ciudad (para validar la dirección y como respaldo).
        $centroCiudad = !empty($coords[$clave]['lat'])
            ? ['lat' => (float)$coords[$clave]['lat'], 'lon' => (float)$coords[$clave]['lon']]
            : null;

        // Con dirección exacta: se geocodifica con Google (mucho mejor que OSM
        // para direcciones ecuatorianas). Se cachea aparte por dirección.
        if ($direccion !== null && trim($direccion) !== '') {
            $claveDir = $clave . ' :: ' . CotizacionParser::normalize($direccion);
            if (!empty($coords[$claveDir]['lat'])) {
                return self::normalizarEntrada($coords[$claveDir]);
            }
            // Limpiar saltos de línea y espacios repetidos de la dirección
            // (en el Excel a veces vienen en varias líneas) para la consulta.
            $dirLimpia = preg_replace('/\s*[\r\n]+\s*/', ', ', trim($direccion));
            $dirLimpia = trim(preg_replace('/\s+/', ' ', $dirLimpia));

            // Geocodificación de la dirección exacta: Google solo si hay clave
            // (opcional); por defecto, motor 100% libre (Nominatim + Photon con
            // extracción de POI/referencia).
            $geo = null;
            if (self::hayGoogle()) {
                $geo = self::geocodeGoogle($dirLimpia . ', ' . $ciudad . ', Ecuador', $centroCiudad);
            }
            if (!$geo) {
                $geo = self::geocodeLibre($direccion, $ciudad, $centroCiudad);
            }
            if ($geo) {
                $coords[$claveDir] = $geo + [
                    'referencia' => $dirLimpia . ', ' . $ciudad,
                    'nivel' => 'direccion',
                ];
                self::guardarCoords($coords);
                return self::normalizarEntrada($coords[$claveDir]);
            }
            // La dirección exacta no se pudo geolocalizar: se cae a la ciudad,
            // marcando el nivel como aproximado. Se cachea el fallback para que
            // las siguientes corridas no vuelvan a consultar esta dirección.
            if ($centroCiudad !== null) {
                $coords[$claveDir] = [
                    'lat' => (float)$coords[$clave]['lat'],
                    'lon' => (float)$coords[$clave]['lon'],
                    'fuente' => $coords[$clave]['fuente'] ?? 'ciudad',
                    'referencia' => $dirLimpia . ', ' . $ciudad,
                    'nivel' => 'ciudad',
                ];
                self::guardarCoords($coords);
                return self::normalizarEntrada($coords[$claveDir]);
            }
        }

        if (!empty($coords[$clave]['lat'])) {
            return self::normalizarEntrada($coords[$clave]);
        }

        // Ciudad no cacheada: Google primero, Nominatim como respaldo.
        $geo = self::geocodeGoogle($ciudad . ', Ecuador', null) ?: self::geocodificar($ciudad . ', Ecuador');
        if (!$geo) return null;
        $coords[$clave] = $geo + ['referencia' => $ciudad . ', Ecuador', 'nivel' => 'ciudad'];
        self::guardarCoords($coords);
        return self::normalizarEntrada($coords[$clave]);
    }

    private static function normalizarEntrada(array $e): array
    {
        return [
            'lat' => (float)$e['lat'],
            'lon' => (float)$e['lon'],
            'fuente' => $e['fuente'] ?? 'cache',
            'referencia' => $e['referencia'] ?? '',
            'nivel' => $e['nivel'] ?? 'ciudad',
            'precision' => $e['precision'] ?? '',
        ];
    }

    /**
     * Geocodifica con Google Maps. Si $centroCiudad viene dado, valida que el
     * resultado esté razonablemente cerca (para descartar coincidencias en otra
     * ciudad). Devuelve null si no hay clave, si falla, o si queda muy lejos.
     */
    private static function geocodeGoogle(string $direccion, ?array $centroCiudad): ?array
    {
        $key = self::googleKey();
        if ($key === '') return null;

        $url = self::GOOGLE . '?' . http_build_query([
            'address' => $direccion,
            'key' => $key,
            'region' => 'ec',
            'components' => 'country:EC',
            'language' => 'es',
        ]);
        $resp = self::httpGet($url);
        if ($resp === null) return null;
        $j = json_decode($resp, true);
        if (($j['status'] ?? '') !== 'OK' || empty($j['results'][0]['geometry']['location'])) {
            return null;
        }
        $loc = $j['results'][0]['geometry']['location'];
        $punto = ['lat' => (float)$loc['lat'], 'lon' => (float)$loc['lng']];

        // Validación: si conocemos el centro de la ciudad y el resultado queda
        // a más de 40 km, probablemente Google acertó otra localidad -> descartar.
        if ($centroCiudad !== null && self::haversine($centroCiudad, $punto) > 40) {
            return null;
        }

        return [
            'lat' => $punto['lat'],
            'lon' => $punto['lon'],
            'fuente' => 'google',
            'precision' => $j['results'][0]['geometry']['location_type'] ?? '',
        ];
    }

    /**
     * Geocodificación de dirección con servicios LIBRES (sin clave):
     * Nominatim (OSM) + Photon (Komoot), probando varias formas de la dirección
     * (referencia/POI, parroquia interna, dirección completa). Devuelve el primer
     * resultado ESPECÍFICO (no un contorno de ciudad) y cercano a la ciudad, o
     * null si ninguna estrategia ubica algo más preciso que la ciudad.
     */
    private static function geocodeLibre(string $direccion, string $ciudad, ?array $centroCiudad): ?array
    {
        $dir = trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', $direccion)));
        $consultas = self::candidatosDireccion($dir, $ciudad, self::extraerLocalidad($direccion));

        foreach ($consultas as $q) {
            $p = self::photon($q, $centroCiudad);
            if ($p && self::esEspecifico($p['tipo']) && self::cerca($centroCiudad, $p)) {
                return ['lat' => $p['lat'], 'lon' => $p['lon'], 'fuente' => 'photon', 'precision' => $p['tipo']];
            }
            $n = self::nominatimDetalle($q);
            if ($n && self::esEspecifico($n['tipo']) && self::cerca($centroCiudad, $n)) {
                return ['lat' => $n['lat'], 'lon' => $n['lon'], 'fuente' => 'nominatim', 'precision' => $n['tipo']];
            }
        }
        return null;
    }

    private static function photon(string $q, ?array $centro): ?array
    {
        $params = ['q' => $q, 'limit' => 1];
        if ($centro) { $params['lat'] = $centro['lat']; $params['lon'] = $centro['lon']; }
        $resp = self::httpGet('https://photon.komoot.io/api/?' . http_build_query($params));
        if ($resp === null) return null;
        $j = json_decode($resp, true);
        $f = $j['features'][0] ?? null;
        if (!$f || empty($f['geometry']['coordinates'])) return null;
        $c = $f['geometry']['coordinates'];
        return ['lat' => (float)$c[1], 'lon' => (float)$c[0], 'tipo' => $f['properties']['osm_value'] ?? ($f['properties']['type'] ?? '')];
    }

    private static function nominatimDetalle(string $q): ?array
    {
        $resp = self::httpGet(self::NOMINATIM . '?' . http_build_query([
            'q' => $q, 'format' => 'jsonv2', 'limit' => 1, 'countrycodes' => 'ec',
        ]));
        if ($resp === null) return null;
        usleep(1100000); // Nominatim: máx 1 petición por segundo (solo si hubo respuesta)
        $j = json_decode($resp, true);
        if (!is_array($j) || empty($j[0]['lat'])) return null;
        return ['lat' => (float)$j[0]['lat'], 'lon' => (float)$j[0]['lon'], 'tipo' => $j[0]['type'] ?? ''];
    }

    /**
     * Construye consultas candidatas (de la más específica a la más general)
     * a partir de una dirección informal ecuatoriana: referencia/POI, sector /
     * ciudadela / barrio, avenida con nombre, y la dirección completa.
     */
    private static function candidatosDireccion(string $dir, string $ciudad, ?string $loc = null): array
    {
        $suf = ($loc ? ', ' . $loc : '') . ', ' . $ciudad . ', Ecuador';
        $c = [];

        // 1) POI / referencia después de un conector ("diagonal a", "frente a"…)
        if (preg_match('/\b(?:diagonal a|junto a|frente a|frente al|a lado de|al lado de|contiguo a|detr[aá]s de|atr[aá]s de|cerca de|referencia:?)\s+(.+?)(?:,| - |\.|$)/iu', $dir, $m)) {
            $r = trim(preg_replace('/^(?:la|el|los|las)\s+/iu', '', trim($m[1])));
            if (mb_strlen($r) >= 4) $c[] = $r . $suf;
        }
        // 2) sector / ciudadela / barrio / conjunto / urbanización
        if (preg_match('/\b(?:sector|ciudadela|cdla\.?|barrio|conjunto|urbanizaci[oó]n|urb\.?)\s+(.+?)(?:,|\.|$| entre | referencia| timbrar| a la altura)/iu', $dir, $m)) {
            $s = trim($m[1]);
            if (mb_strlen($s) >= 3) $c[] = $s . ', ' . $ciudad . ', Ecuador';
        }
        // 3) POI típico nombrado
        if (preg_match('/((?:estaci[oó]n|gasolinera|petroecuador|iglesia|parque|coliseo|terminal|mercado|universidad|hospital|estadio|aeropuerto)[^,\-\n]*)/iu', $dir, $m)) {
            $c[] = trim($m[1]) . $suf;
        }
        // 4) avenida con nombre (corta el número/intersección)
        if (preg_match('/\b(?:Av\.?|Avenida)\s+([A-Za-zÁÉÍÓÚÑáéíóúñ\.\' ]{3,28}?)(?:\s+(?:N?\d|Oe\d|S\d|y |,|entre|casa|local)|,|$)/u', $dir, $m)) {
            $c[] = 'Avenida ' . trim($m[1]) . ', ' . $ciudad . ', Ecuador';
        }
        // 5) dirección completa
        $c[] = $dir . $suf;

        return array_values(array_unique($c));
    }

    /** Extrae un punto de referencia/POI de una dirección informal ecuatoriana. */
    private static function extraerReferencia(string $dir): ?string
    {
        if (preg_match('/\b(?:diagonal a|junto a|frente a|frente al|a lado de|al lado de|contiguo a|detr[aá]s de|atr[aá]s de|cerca de|referencia:?)\s+(.+?)(?:,| - |$)/iu', $dir, $m)) {
            $r = trim(preg_replace('/^(?:la|el|los|las)\s+/iu', '', trim($m[1])));
            if (mb_strlen($r) >= 4) return $r;
        }
        if (preg_match('/((?:estaci[oó]n|gasolinera|petroecuador|iglesia|parque|coliseo|terminal|mercado|universidad|hospital|estadio|aeropuerto)[^,\-\n]*)/iu', $dir, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /** Extrae una parroquia/localidad interna de la dirección (si la trae). */
    private static function extraerLocalidad(string $dir): ?string
    {
        if (preg_match('/\bPARROQUIA\s+([A-Za-zÁÉÍÓÚÑáéíóúñ .]+?)(?:,|-|$)/u', $dir, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /** Un tipo OSM es "específico" si no es un contorno de ciudad/administrativo. */
    private static function esEspecifico(string $tipo): bool
    {
        $genericos = ['administrative', 'city', 'town', 'village', 'hamlet', 'county',
            'state', 'region', 'province', 'municipality', 'locality', 'political'];
        return $tipo !== '' && !in_array(strtolower($tipo), $genericos, true);
    }

    private static function cerca(?array $centro, array $punto): bool
    {
        if ($centro === null) return true;
        return self::haversine($centro, $punto) <= 40;
    }

    /** Geocodifica una consulta con Nominatim (limitado a Ecuador). */
    private static function geocodificar(string $query): ?array
    {
        $url = self::NOMINATIM . '?' . http_build_query([
            'q' => $query, 'format' => 'json', 'limit' => 1, 'countrycodes' => 'ec',
        ]);
        $resp = self::httpGet($url);
        if ($resp === null) return null;
        $j = json_decode($resp, true);
        if (!is_array($j) || empty($j[0]['lat'])) return null;
        // Nominatim: máximo 1 petición por segundo.
        usleep(1100000);
        return [
            'lat' => (float)$j[0]['lat'],
            'lon' => (float)$j[0]['lon'],
            'fuente' => 'nominatim',
        ];
    }

    /**
     * Kilómetros reales de conducción entre dos puntos {lat,lon} usando OSRM.
     * Cachea el resultado. Si OSRM no responde, cae a Haversine * 1.3 (factor
     * de sinuosidad de carretera) y marca el resultado como aproximado.
     *
     * @return array ['km' => float, 'aprox' => bool]
     */
    public static function kmConduccion(array $a, array $b): array
    {
        // Motor de distancia: Google Distance Matrix si hay clave (para que los
        // km coincidan con Google Maps), OSRM como respaldo. La caché separa por
        // motor para no mezclar valores de uno y otro.
        $engine = self::hayGoogle() ? 'google' : 'osrm';

        $cache = is_file(self::cachePath())
            ? (json_decode((string)file_get_contents(self::cachePath()), true) ?: [])
            : [];
        $ka = round($a['lat'], 5) . ',' . round($a['lon'], 5);
        $kb = round($b['lat'], 5) . ',' . round($b['lon'], 5);
        $clave = $engine . '|' . $ka . '|' . $kb;
        if (isset($cache[$clave])) {
            return [
                'km' => (float)$cache[$clave]['km'],
                'aprox' => (bool)$cache[$clave]['aprox'],
                'fuente' => $cache[$clave]['fuente'] ?? $engine,
            ];
        }

        $resultado = null;
        if ($engine === 'google') {
            $resultado = self::kmGoogle($a, $b);
        }
        if ($resultado === null) {
            $resultado = self::kmOsrm($a, $b); // respaldo (o motor por defecto)
        }
        if ($resultado === null) {
            // Último respaldo sin red: línea recta * factor de carretera.
            $resultado = ['km' => round(self::haversine($a, $b) * 1.3, 1), 'aprox' => true, 'fuente' => 'aprox'];
        }

        $cache[$clave] = $resultado;
        file_put_contents(self::cachePath(), json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $resultado;
    }

    /** Distancia de conducción con Google Distance Matrix (km). */
    private static function kmGoogle(array $a, array $b): ?array
    {
        $key = self::googleKey();
        if ($key === '') return null;
        $url = self::GOOGLE_DM . '?' . http_build_query([
            'origins' => $a['lat'] . ',' . $a['lon'],
            'destinations' => $b['lat'] . ',' . $b['lon'],
            'mode' => 'driving',
            'language' => 'es',
            'key' => $key,
        ]);
        $resp = self::httpGet($url);
        if ($resp === null) return null;
        $j = json_decode($resp, true);
        $el = $j['rows'][0]['elements'][0] ?? null;
        if (($j['status'] ?? '') !== 'OK' || !$el || ($el['status'] ?? '') !== 'OK' || !isset($el['distance']['value'])) {
            return null;
        }
        return ['km' => round($el['distance']['value'] / 1000, 1), 'aprox' => false, 'fuente' => 'google'];
    }

    /** Distancia de conducción con OSRM (km). */
    private static function kmOsrm(array $a, array $b): ?array
    {
        $url = self::OSRM
            . $a['lon'] . ',' . $a['lat'] . ';' . $b['lon'] . ',' . $b['lat']
            . '?overview=false&alternatives=false&steps=false';
        $resp = self::httpGet($url);
        if ($resp === null) return null;
        $j = json_decode($resp, true);
        if (isset($j['code']) && $j['code'] === 'Ok' && isset($j['routes'][0]['distance'])) {
            usleep(250000); // pausa para no saturar el servidor público
            return ['km' => round($j['routes'][0]['distance'] / 1000, 1), 'aprox' => false, 'fuente' => 'osrm'];
        }
        return null;
    }

    /**
     * Matriz de distancias de conducción (km) entre una lista de puntos, en una
     * sola llamada a OSRM /table. $puntos = [ ['lat'=>..,'lon'=>..], ... ].
     * Devuelve una matriz NxN (km) o null si falla; los valores nulos de OSRM
     * se rellenan con Haversine * 1.3.
     */
    public static function matrizConduccion(array $puntos): ?array
    {
        if (count($puntos) < 2) return null;
        $coordStr = implode(';', array_map(fn($p) => $p['lon'] . ',' . $p['lat'], $puntos));
        $resp = self::httpGet(self::OSRM_TABLE . $coordStr . '?annotations=distance');
        if ($resp === null) return null;
        $j = json_decode($resp, true);
        if (!isset($j['distances']) || !is_array($j['distances'])) return null;

        $n = count($puntos);
        $M = [];
        foreach ($j['distances'] as $i => $fila) {
            foreach ($fila as $k => $m) {
                $M[$i][$k] = ($m === null)
                    ? round(self::haversine($puntos[$i], $puntos[$k]) * 1.3, 1)
                    : round($m / 1000, 1);
            }
        }
        return $M;
    }

    /** Distancia en línea recta (km) entre dos puntos. */
    public static function haversine(array $a, array $b): float
    {
        $R = 6371.0;
        $dLat = deg2rad($b['lat'] - $a['lat']);
        $dLon = deg2rad($b['lon'] - $a['lon']);
        $lat1 = deg2rad($a['lat']);
        $lat2 = deg2rad($b['lat']);
        $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;
        return $R * 2 * asin(min(1, sqrt($h)));
    }

    private static function httpGet(string $url): ?string
    {
        // Si ya se apagó la red en esta corrida (servicios inaccesibles), no
        // volvemos a intentar: devolvemos null al instante y el llamador cae a
        // las coordenadas de ciudad. Así el cálculo nunca se cuelga.
        if (self::$redCaida) return null;
        if (self::$limiteRed === null) self::$limiteRed = microtime(true) + self::TIME_BUDGET;
        if (microtime(true) > self::$limiteRed) { self::$redCaida = true; return null; }

        $c = curl_init($url);
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_FOLLOWLOCATION => true,
            // XAMPP en Windows suele no traer bundle de CA; estos son
            // servicios públicos conocidos y de solo lectura.
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $r = curl_exec($c);
        $errno = curl_errno($c);
        $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);

        // Fallo de conexión (sin red, DNS, timeout, bloqueo del proxy 403/407) o
        // respuesta de límite/servidor: cuenta como fallo de red. Tras MAX_FALLOS
        // seguidos apagamos la red para el resto del cálculo.
        if ($r === false || $errno !== 0 || $code === 0
            || in_array($code, [403, 407, 408, 429], true) || $code >= 500) {
            if (++self::$fallosRed >= self::MAX_FALLOS) self::$redCaida = true;
            return null;
        }
        self::$fallosRed = 0; // hubo respuesta válida del servidor
        if ($code < 200 || $code >= 300) return null;
        return (string)$r;
    }
}
