<?php
require_once __DIR__ . '/CotizacionParser.php';
require_once __DIR__ . '/Distancias.php';

class Itinerarios
{
    private static function path(): string
    {
        return __DIR__ . '/../data/itinerarios.json';
    }

    public static function cargar(): array
    {
        $json = file_get_contents(self::path());
        $data = json_decode($json, true) ?: [];
        unset($data['_comentario']);
        return $data;
    }

    public static function guardar(array $itinerarios): void
    {
        $itinerarios = ['_comentario' => 'km_tramo = distancia por carretera (km) desde la parada anterior de la lista; la primera se mide desde el hub en Amaguaña. Se recalcula con el botón "Calcular distancias reales" (geolocalización OSRM) o se puede editar manualmente.'] + $itinerarios;
        file_put_contents(self::path(), json_encode($itinerarios, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public static function sugerirRuta(string $rutaId, array $ciudadesConCarga): array
    {
        // La Ruta Interna Pichincha usa orden óptimo automático (más cercana
        // a más lejana del Centro de Distribución) en vez del orden fijo
        // de itinerarios.json, ya que sus paradas son puntos radiales
        // dentro de la misma ciudad y no una secuencia de carretera fija.
        if ($rutaId === 'ruta_pichincha') {
            return self::sugerirRutaOptima($ciudadesConCarga);
        }

        $base = self::cargar()[$rutaId] ?? [];
        $nombresConCarga = array_map(fn($c) => CotizacionParser::normalize($c['ciudad']), $ciudadesConCarga);

        $tramos = [];
        $usadas = [];
        $acumulado = 0;

        foreach ($base as $paso) {
            $norm = CotizacionParser::normalize($paso['ciudad']);
            if (in_array($norm, $nombresConCarga, true)) {
                $km = round((float)$paso['km_tramo'], 1);
                $acumulado = round($acumulado + $km, 1);
                $tramos[] = [
                    'ciudad' => $paso['ciudad'],
                    'km_tramo' => $km,
                    'km_acumulado' => $acumulado,
                    'estimado' => (bool)$paso['estimado'],
                    'origen_verificado' => true,
                ];
                $usadas[] = $norm;
            }
        }

        foreach ($ciudadesConCarga as $c) {
            $norm = CotizacionParser::normalize($c['ciudad']);
            if (in_array($norm, $usadas, true)) {
                continue;
            }
            $kmHub = Distancias::km($c['ciudad']);
            $acumulado += ($kmHub ?? 0);
            $tramos[] = [
                'ciudad' => $c['ciudad'],
                'km_tramo' => $kmHub ?? 0,
                'km_acumulado' => $acumulado,
                'estimado' => true,
                'origen_verificado' => false,
            ];
        }

        $totalIda = array_sum(array_column($tramos, 'km_tramo'));

        return [
            'tramos' => $tramos,
            'km_total_ida' => $totalIda,
            'km_total_ida_vuelta' => $totalIda * 2,
        ];
    }

    /**
     * Calcula automáticamente el orden óptimo de visita para una ruta cuyas
     * paradas son todas radiales desde el mismo Centro de Distribución,
     * usando data/distancias.json como referencia de distancia al hub.
     *
     * Estrategia: se ordenan los lugares de más cercano a más lejano del
     * hub (orden óptimo tipo "salir y avanzar", sin retrocesos) y el
     * kilometraje de cada tramo es la diferencia entre la distancia al hub
     * del punto actual y la del punto anterior (el primero se mide desde
     * el hub, que está en km 0). Así el acumulado nunca duplica recorrido:
     * cada parada nueva solo suma lo que la separa de la parada anterior,
     * no su distancia total al hub.
     */
    public static function sugerirRutaOptima(array $ciudadesConCarga): array
    {
        $puntos = [];
        $sinDato = [];

        foreach ($ciudadesConCarga as $c) {
            $kmHub = Distancias::km($c['ciudad']);
            if ($kmHub === null) {
                $sinDato[] = $c['ciudad'];
                continue;
            }
            $puntos[] = ['ciudad' => $c['ciudad'], 'km_hub' => $kmHub];
        }

        // Orden óptimo: de la parada más cercana al hub a la más lejana.
        usort($puntos, fn($a, $b) => $a['km_hub'] <=> $b['km_hub']);

        $tramos = [];
        $anteriorKm = 0;
        $acumulado = 0;

        foreach ($puntos as $p) {
            $tramoKm = max(0, $p['km_hub'] - $anteriorKm);
            $acumulado += $tramoKm;
            $tramos[] = [
                'ciudad' => $p['ciudad'],
                'km_tramo' => $tramoKm,
                'km_acumulado' => $acumulado,
                // Los km salen de distancias.json, ahora medidos por
                // geolocalización real (OSRM), así que no son estimados.
                'estimado' => false,
                'origen_verificado' => true,
            ];
            $anteriorKm = $p['km_hub'];
        }

        // Lugares sin dato de distancia en distancias.json: se agregan al
        // final marcados como "sin_dato" para que no desaparezcan de la
        // ruta, aunque no se pueda calcular su tramo real.
        foreach ($sinDato as $ciudad) {
            $tramos[] = [
                'ciudad' => $ciudad,
                'km_tramo' => 0,
                'km_acumulado' => $acumulado,
                'estimado' => true,
                'origen_verificado' => false,
                'sin_dato' => true,
            ];
        }

        $totalIda = $acumulado;

        return [
            'tramos' => $tramos,
            'km_total_ida' => $totalIda,
            'km_total_ida_vuelta' => $totalIda * 2,
        ];
    }
}