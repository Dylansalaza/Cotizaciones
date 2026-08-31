<?php
require_once __DIR__ . '/XlsxReader.php';

class CotizacionParser
{
    public static function parse(string $filepath): array
    {
        $rows = XlsxReader::read($filepath);
        $ciudades = [];
        $provinciaActual = 'PICHINCHA'; 
        $ciudadActual = '';
        $clienteActual = '';   // Para arrastrar el cliente a las filas de continuación (2°, 3° bulto...)
        $direccionActual = ''; // Para arrastrar la dirección a las filas de continuación
        
        // Estructura para Quito con direcciones detalladas
        $quitoData = [
            'ciudad' => 'QUITO', 
            'provincia' => 'PICHINCHA', 
            'cajas' => 0, 
            'volumen_m3' => 0, 
            'peso_kg' => 0, 
            'direcciones' => [], // Solo para Quito: dirección => {cajas, peso, volumen}
            'es_quito' => true, // Flag para identificar que es Quito
        ];

        // Direcciones exactas por ciudad (para TODAS las ciudades, no solo Quito).
        // Se acumulan aquí por ciudad y se adjuntan al final, sin alterar los
        // totales de ciudad (que siguen viniendo de las filas "Total <ciudad>").
        $direccionesPorCiudad = [];

        foreach ($rows as $rowIndex => $row) {
            $colA = self::val($row, 0); // PROVINCIA
            $colB = self::val($row, 1); // CIUDAD
            $colC = self::val($row, 2); // CLIENTE
            $colF = self::val($row, 5); // DIRECCIÓN (Columna F)
            
            // Columnas de medidas (G=6, H=7, I=8)
            $largo = self::num($row, 6);
            $ancho = self::num($row, 7);
            $alto = self::num($row, 8);
            
            // Columna J = Nro Cajas (índice 9)
            $cajas = self::num($row, 9);
            
            // Calcular volumen si tenemos las medidas
            $volumen = 0;
            if ($largo !== null && $ancho !== null && $alto !== null) {
                // Convertir cm a metros cúbicos (si las medidas están en cm)
                $volumen = ($largo * $ancho * $alto) / 1000000;
            } else {
                // Si no hay medidas, usar columna K si existe
                $volumen = self::num($row, 10) ?? 0;
            }
            
            // Peso: buscar en columna L o M
            $peso = self::num($row, 11) ?? self::num($row, 12) ?? 0;

            // Detectar provincia
            if ($colA !== null && 
                stripos((string)$colA, 'Total') === false && 
                stripos((string)$colA, 'PROVINCIA') === false &&
                trim((string)$colA) !== '') {
                $provinciaActual = trim((string)$colA);
            }
            
            // Detectar ciudad actual
            if ($colB !== null && 
                stripos((string)$colB, 'Total') === false &&
                trim((string)$colB) !== '') {
                $posibleCiudad = self::normalize((string)$colB);
                if ($posibleCiudad !== '') {
                    $ciudadActual = $posibleCiudad;
                }
            }

            // === FIX: detectar cualquier fila "resumen" ===
            // Una fila es resumen (no es un bulto real) si CUALQUIERA de estas tres
            // columnas empieza con "Total": "Total <Cliente>", "Total <Ciudad>" o
            // "Total <Provincia>". Antes solo se revisaba colC, y eso dejaba pasar
            // las filas "Total QUITO" y "Total PICHINCHA", que se sumaban como si
            // fueran cajas de un cliente más, duplicando los totales de Quito.
            $esFilaResumen = (stripos((string)($colA ?? ''), 'Total') === 0)
                || (stripos((string)($colB ?? ''), 'Total') === 0)
                || (stripos((string)($colC ?? ''), 'Total') === 0);

            // Arrastrar cliente y dirección a las filas de continuación (bultos 2°, 3°...
            // de un mismo cliente), que vienen con las columnas C y F vacías.
            if ($colC !== null && trim((string)$colC) !== '' && !$esFilaResumen) {
                $clienteActual = trim((string)$colC);
            }
            if ($colF !== null && trim((string)$colF) !== '') {
                $direccionActual = trim((string)$colF);
            }
            
            // Procesar datos de Quito con direcciones individuales
            if ($ciudadActual === 'QUITO' && $cajas !== null && $cajas > 0 && !$esFilaResumen) {
                $quitoData['cajas'] += (float)$cajas;
                $quitoData['volumen_m3'] += (float)$volumen;
                $quitoData['peso_kg'] += (float)$peso;

                $direccionLimpia = $direccionActual;
                $clienteLimpio = $clienteActual;
                if ($direccionLimpia !== '') {
                    // Clave = dirección + cliente. Si dos clientes distintos comparten
                    // la misma dirección (ej. un mismo edificio), NO se deben sumar
                    // entre sí: cada uno debe quedar como una entrega separada.
                    // Las varias filas de bultos de UN MISMO cliente en esa dirección
                    // sí se siguen acumulando entre ellas (misma clave).
                    $claveDireccion = $direccionLimpia . '||' . self::normalize($clienteLimpio);

                    if (isset($quitoData['direcciones'][$claveDireccion])) {
                        $quitoData['direcciones'][$claveDireccion]['cajas'] += (float)$cajas;
                        $quitoData['direcciones'][$claveDireccion]['volumen_m3'] += (float)$volumen;
                        $quitoData['direcciones'][$claveDireccion]['peso_kg'] += (float)$peso;
                    } else {
                        $quitoData['direcciones'][$claveDireccion] = [
                            'direccion' => $direccionLimpia,
                            'cajas' => (float)$cajas,
                            'volumen_m3' => (float)$volumen,
                            'peso_kg' => (float)$peso,
                            'cliente' => $clienteLimpio,
                        ];
                    }
                }
            }
            
            // Capturar dirección exacta de cada cliente para ciudades NO Quito.
            // (Quito ya se procesa arriba con su propio bloque de direcciones.)
            if ($ciudadActual !== '' && $ciudadActual !== 'QUITO'
                && $cajas !== null && $cajas > 0 && !$esFilaResumen
                && $direccionActual !== '') {
                $keyCiudad = self::normalize($ciudadActual);
                $claveDireccion = $direccionActual . '||' . self::normalize($clienteActual);
                if (!isset($direccionesPorCiudad[$keyCiudad][$claveDireccion])) {
                    $direccionesPorCiudad[$keyCiudad][$claveDireccion] = [
                        'direccion' => $direccionActual,
                        'cliente' => $clienteActual,
                        'cajas' => 0,
                        'volumen_m3' => 0,
                        'peso_kg' => 0,
                    ];
                }
                $direccionesPorCiudad[$keyCiudad][$claveDireccion]['cajas'] += (float)$cajas;
                $direccionesPorCiudad[$keyCiudad][$claveDireccion]['volumen_m3'] += (float)$volumen;
                $direccionesPorCiudad[$keyCiudad][$claveDireccion]['peso_kg'] += (float)$peso;
            }

            // Procesar totales de otras ciudades (NO Quito)
            if ($colB !== null && stripos(trim((string)$colB), 'Total ') === 0) {
                $ciudad = trim(substr(trim((string)$colB), 6));
                $key = self::normalize($ciudad);
                
                // Ignorar Total QUITO porque ya lo procesamos con direcciones
                if ($key !== 'QUITO' && $ciudad !== '' && $cajas !== null && $cajas > 0) {
                    if (!isset($ciudades[$key])) {
                        $ciudades[$key] = [
                            'ciudad' => $ciudad,
                            'provincia' => $provinciaActual,
                            'cajas' => 0,
                            'volumen_m3' => 0,
                            'peso_kg' => 0,
                            'direcciones' => [], // Vacío para no Quito
                            'es_quito' => false,
                        ];
                    }
                    $ciudades[$key]['cajas'] += (float)$cajas;
                    $ciudades[$key]['volumen_m3'] += (float)$volumen;
                    $ciudades[$key]['peso_kg'] += (float)$peso;
                }
                $ciudadActual = '';
            }
        }

        // Adjuntar las direcciones exactas capturadas a cada ciudad (no Quito).
        foreach ($ciudades as &$c) {
            if (!empty($c['es_quito'])) continue;
            $nk = self::normalize($c['ciudad']);
            $c['direcciones'] = array_values($direccionesPorCiudad[$nk] ?? []);
        }
        unset($c);

        // Agregar Quito con sus direcciones detalladas
        if ($quitoData['cajas'] > 0 || $quitoData['peso_kg'] > 0) {
            $quitoData['peso_ton'] = round($quitoData['peso_kg'] / 1000, 3);
            $ciudades[] = $quitoData;
        }

        return array_values($ciudades);
    }

    public static function normalize(string $text): string
    {
        $text = trim($text);
        $text = mb_strtoupper($text, 'UTF-8');
        $unwanted = ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N'];
        $text = strtr($text, $unwanted);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    private static function val(array $row, int $i)
    {
        $v = $row[$i] ?? null;
        return (is_string($v) && trim($v) === '') ? null : (is_string($v) ? trim($v) : $v);
    }

    private static function num(array $row, int $i)
    {
        $v = $row[$i] ?? null;
        return is_numeric($v) ? (float)$v : null;
    }
}