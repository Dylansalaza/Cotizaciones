<?php

class Camiones
{
    public static function catalogo(): array
    {
        $json = file_get_contents(__DIR__ . '/../data/camiones.json');
        $lista = json_decode($json, true) ?: [];
        usort($lista, fn($a, $b) => $a['capacidad_kg'] <=> $b['capacidad_kg']);
        return $lista;
    }

    public static function sugerir(float $pesoKg, float $volumenM3): array
    {
        $catalogo = self::catalogo();

        foreach ($catalogo as $camion) {
            if ($pesoKg <= $camion['capacidad_kg'] && $volumenM3 <= $camion['capacidad_m3']) {
                return [
                    'camion' => $camion,
                    'viajes' => 1,
                    'nota' => null,
                ];
            }
        }

        $mayor = end($catalogo);
        $viajesPorPeso = ceil($pesoKg / $mayor['capacidad_kg']);
        $viajesPorVolumen = ceil($volumenM3 / $mayor['capacidad_m3']);
        $viajes = max($viajesPorPeso, $viajesPorVolumen, 1);

        return [
            'camion' => $mayor,
            'viajes' => (int)$viajes,
            'nota' => "La carga supera la capacidad de un solo vehículo; se estiman {$viajes} viajes (o el uso de {$viajes} camiones tipo '{$mayor['nombre']}' en simultáneo).",
        ];
    }
}