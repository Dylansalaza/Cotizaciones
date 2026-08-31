<?php
require_once __DIR__ . '/CotizacionParser.php';

class Distancias
{
    private static function tabla(): array
    {
        $json = file_get_contents(__DIR__ . '/../data/distancias.json');
        return json_decode($json, true) ?: [];
    }

    public static function km(string $ciudad): ?int
    {
        $tabla = self::tabla();
        $norm = CotizacionParser::normalize($ciudad);
        foreach ($tabla as $nombre => $km) {
            if (CotizacionParser::normalize($nombre) === $norm) {
                return (int)$km;
            }
        }
        return null;
    }

    /**
     * Dada una lista de ciudades (con su carga), arma un orden de entrega sugerido
     * (de la más cercana a la más lejana desde la matriz/Quito) y estima el
     * kilometraje total del recorrido (ida a cada punto + regreso al final).
     */
    public static function sugerirRuta(array $ciudades): array
    {
        $conKm = [];
        $sinKm = [];

        foreach ($ciudades as $c) {
            $km = self::km($c['ciudad']);
            if ($km === null) {
                $sinKm[] = $c['ciudad'];
                continue;
            }
            $conKm[] = ['ciudad' => $c['ciudad'], 'km_desde_quito' => $km];
        }

        usort($conKm, fn($a, $b) => $a['km_desde_quito'] <=> $b['km_desde_quito']);

        $totalKm = 0;
        if (!empty($conKm)) {
            $masLejos = end($conKm);
            $totalKm = $masLejos['km_desde_quito'] * 2; // ida hasta el punto más lejano y vuelta
        }

        return [
            'orden_sugerido' => $conKm,
            'ciudades_sin_dato_km' => $sinKm,
            'km_total_estimado' => $totalKm,
        ];
    }
}
