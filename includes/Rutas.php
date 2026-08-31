<?php
require_once __DIR__ . '/CotizacionParser.php';

class Rutas
{
    private static function path(): string
    {
        return __DIR__ . '/../data/rutas.json';
    }

    public static function cargar(): array
    {
        $json = file_get_contents(self::path());
        return json_decode($json, true) ?: [];
    }

    public static function guardar(array $rutas): void
    {
        file_put_contents(self::path(), json_encode($rutas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public static function agregarLugar(string $rutaId, string $lugar): array
    {
        $rutas = self::cargar();
        if (!isset($rutas[$rutaId])) {
            throw new Exception('La ruta indicada no existe.');
        }
        $lugar = trim($lugar);
        $norm = CotizacionParser::normalize($lugar);
        foreach ($rutas[$rutaId]['lugares'] as $existente) {
            if (CotizacionParser::normalize($existente) === $norm) {
                return $rutas;
            }
        }
        $rutas[$rutaId]['lugares'][] = mb_strtoupper($lugar, 'UTF-8');
        self::guardar($rutas);
        return $rutas;
    }

    public static function crearRuta(string $id, string $nombre, string $grupo): array
    {
        $rutas = self::cargar();
        if (isset($rutas[$id])) {
            throw new Exception('Ya existe una ruta con ese identificador.');
        }
        $rutas[$id] = ['nombre' => $nombre, 'grupo' => $grupo, 'lugares' => []];
        self::guardar($rutas);
        return $rutas;
    }

    public static function eliminarRuta(string $rutaId): array
    {
        $rutas = self::cargar();
        if (!isset($rutas[$rutaId])) {
            throw new Exception('La ruta indicada no existe.');
        }
        unset($rutas[$rutaId]);
        self::guardar($rutas);

        $itinerarioPath = __DIR__ . '/../data/itinerarios.json';
        $data = json_decode(file_get_contents($itinerarioPath), true) ?: [];
        if (isset($data[$rutaId])) {
            unset($data[$rutaId]);
            file_put_contents($itinerarioPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return $rutas;
    }

    public static function eliminarLugar(string $rutaId, string $lugar): array
    {
        $rutas = self::cargar();
        if (!isset($rutas[$rutaId])) {
            throw new Exception('La ruta indicada no existe.');
        }
        $norm = CotizacionParser::normalize($lugar);
        $rutas[$rutaId]['lugares'] = array_values(array_filter(
            $rutas[$rutaId]['lugares'],
            fn($l) => CotizacionParser::normalize($l) !== $norm
        ));
        self::guardar($rutas);
        return $rutas;
    }

    public static function buscarRutaDeCiudad(string $ciudad, array $rutas): ?string
    {
        $norm = CotizacionParser::normalize($ciudad);
        foreach ($rutas as $rutaId => $ruta) {
            foreach ($ruta['lugares'] as $lugar) {
                if (CotizacionParser::normalize($lugar) === $norm) {
                    return $rutaId;
                }
            }
        }
        return null;
    }
}