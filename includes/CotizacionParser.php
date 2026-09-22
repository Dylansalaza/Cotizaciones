<?php
require_once __DIR__ . '/XlsxReader.php';

class CotizacionParser
{
    public static function parse(string $filepath): array
    {
        $hojas = XlsxReader::readSheets($filepath);

        // Existen tres formatos de Excel de cotización:
        //  - "detallado": trae columnas LARGO/ANCHO/ALTO y filas de resumen
        //    "Total <ciudad>" (formato clásico, p. ej. COTIZACIÓN GC46). El
        //    volumen se calcula a partir de las dimensiones y las ciudades se
        //    arman leyendo las filas "Total <ciudad>".
        //  - "resumen": trae directamente Nro Cajas / Peso total / CBM ya
        //    calculados por cada cliente, SIN dimensiones ni filas "Total"
        //    (p. ej. "Cotización Envibox"). Cada fila es una ciudad+cliente.
        //  - "por ruta": plan de introducción por LOCAL/tienda. Cada fila es una
        //    sucursal con su RUTA ya asignada en el archivo (p. ej. QSG2/QSG3),
        //    ciudad y provincia. La ruta NO se deduce por ciudad: viene dada.
        //    Puede estar en cualquiera de las hojas del libro (SEM15/MANTA/...).
        foreach ($hojas as $hoja) {
            if (self::detectarFormatoPorRuta($hoja['rows'])) {
                return self::parsePorRuta($hoja['rows']);
            }
        }

        // Formatos clásicos: usan la primera hoja del libro.
        $rows = $hojas[0]['rows'] ?? [];
        if (self::detectarFormato($rows) === 'resumen') {
            return self::parseResumen($rows);
        }

        return self::parseDetallado($rows);
    }

    /**
     * Formato clásico: dimensiones LARGO/ANCHO/ALTO y filas "Total <ciudad>".
     */
    private static function parseDetallado(array $rows): array
    {
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

    /**
     * Decide qué formato tiene el Excel mirando la fila de encabezados.
     * Si el encabezado incluye columnas de dimensiones (LARGO/ANCHO/ALTO) es
     * el formato "detallado"; si no, y trae una columna de cajas, es "resumen".
     * Por defecto devuelve "detallado" para conservar el comportamiento previo.
     */
    private static function detectarFormato(array $rows): string
    {
        foreach ($rows as $row) {
            $etiquetas = [];
            foreach ($row as $v) {
                $etiquetas[] = self::normalize((string)($v ?? ''));
            }
            $todo = '|' . implode('|', $etiquetas) . '|';

            // Es la fila de encabezados si contiene PROVINCIA y CIUDAD.
            if (strpos($todo, '|PROVINCIA|') === false || strpos($todo, '|CIUDAD|') === false) {
                continue;
            }

            $tieneDimensiones = strpos($todo, '|LARGO|') !== false
                || strpos($todo, '|ANCHO|') !== false
                || strpos($todo, '|ALTO|') !== false;

            return $tieneDimensiones ? 'detallado' : 'resumen';
        }

        return 'detallado';
    }

    /**
     * A partir de la fila de encabezados, ubica el índice de cada columna
     * relevante para el formato "resumen". Es tolerante a que cambien de
     * posición mientras el título sea reconocible.
     */
    private static function mapearColumnas(array $header): array
    {
        $map = [
            'provincia' => null, 'ciudad' => null, 'cliente' => null,
            'direccion' => null, 'cajas' => null, 'peso' => null, 'volumen' => null,
        ];

        foreach ($header as $i => $v) {
            $l = self::normalize((string)($v ?? ''));
            if ($l === '') continue;

            if ($map['provincia'] === null && strpos($l, 'PROVINCIA') !== false) {
                $map['provincia'] = $i;
            } elseif ($map['ciudad'] === null && strpos($l, 'CIUDAD') !== false) {
                $map['ciudad'] = $i;
            } elseif ($map['cliente'] === null && $l === 'CLIENTE') {
                // "CLIENTE" exacto, para no confundir con "CODIGO CLIENTE".
                $map['cliente'] = $i;
            } elseif ($map['direccion'] === null && strpos($l, 'DIRECCION') !== false) {
                $map['direccion'] = $i;
            } elseif ($map['cajas'] === null && strpos($l, 'CAJA') !== false) {
                $map['cajas'] = $i;
            } elseif ($map['peso'] === null && strpos($l, 'PESO') !== false) {
                $map['peso'] = $i;
            } elseif ($map['volumen'] === null &&
                      (strpos($l, 'CBM') !== false || strpos($l, 'VOLUMEN') !== false || strpos($l, 'M3') !== false)) {
                $map['volumen'] = $i;
            }
        }

        // Respaldo con las posiciones del formato Envibox por si algún título
        // no se reconoció (A=provincia, B=ciudad, C=cliente, F=dirección,
        // G=cajas, H=peso, I=cbm).
        $map['provincia'] = $map['provincia'] ?? 0;
        $map['ciudad']    = $map['ciudad']    ?? 1;
        $map['cliente']   = $map['cliente']   ?? 2;
        $map['direccion'] = $map['direccion'] ?? 5;
        $map['cajas']     = $map['cajas']     ?? 6;
        $map['peso']      = $map['peso']      ?? 7;
        $map['volumen']   = $map['volumen']   ?? 8;

        return $map;
    }

    /**
     * Formato "resumen" (p. ej. Envibox): cada fila trae ciudad, cliente,
     * dirección y los totales ya calculados (cajas, peso, CBM). No hay filas
     * "Total <ciudad>", así que las ciudades se acumulan fila por fila.
     */
    private static function parseResumen(array $rows): array
    {
        // 1. Localizar la fila de encabezados y mapear columnas.
        $headerIndex = null;
        $map = null;
        foreach ($rows as $i => $row) {
            $etiquetas = [];
            foreach ($row as $v) {
                $etiquetas[] = self::normalize((string)($v ?? ''));
            }
            $todo = '|' . implode('|', $etiquetas) . '|';
            if (strpos($todo, '|PROVINCIA|') !== false && strpos($todo, '|CIUDAD|') !== false) {
                $headerIndex = $i;
                $map = self::mapearColumnas($row);
                break;
            }
        }
        if ($map === null) {
            return [];
        }

        $ciudades = [];
        $provinciaActual = 'PICHINCHA';
        $ciudadActual = '';   // texto original (para mostrar)
        $clienteActual = '';  // se arrastra a filas de continuación
        $direccionActual = '';

        foreach ($rows as $i => $row) {
            if ($i <= $headerIndex) continue; // saltar encabezados y lo previo

            $colProv    = self::val($row, $map['provincia']);
            $colCiudad  = self::val($row, $map['ciudad']);
            $colCliente = self::val($row, $map['cliente']);
            $colDir     = self::val($row, $map['direccion']);
            $cajas      = self::num($row, $map['cajas']);
            $peso       = self::num($row, $map['peso']) ?? 0;
            $volumen    = self::num($row, $map['volumen']) ?? 0;

            // Filas de resumen tipo "Total ..." (por si el archivo las trae).
            $esFilaResumen = (stripos((string)($colProv ?? ''), 'Total') === 0)
                || (stripos((string)($colCiudad ?? ''), 'Total') === 0)
                || (stripos((string)($colCliente ?? ''), 'Total') === 0);

            // Arrastrar provincia / ciudad / cliente / dirección.
            if ($colProv !== null && stripos((string)$colProv, 'Total') === false
                && trim((string)$colProv) !== '') {
                $provinciaActual = trim((string)$colProv);
            }
            if ($colCiudad !== null && stripos((string)$colCiudad, 'Total') === false
                && trim((string)$colCiudad) !== '') {
                $ciudadActual = trim((string)$colCiudad);
            }
            if ($colCliente !== null && trim((string)$colCliente) !== '' && !$esFilaResumen) {
                $clienteActual = trim((string)$colCliente);
            }
            if ($colDir !== null && trim((string)$colDir) !== '') {
                $direccionActual = trim((string)$colDir);
            }

            if ($esFilaResumen) continue;
            if ($cajas === null || $cajas <= 0) continue;
            if ($ciudadActual === '') continue;

            $key = self::normalize($ciudadActual);
            if (!isset($ciudades[$key])) {
                $ciudades[$key] = [
                    'ciudad' => $ciudadActual,
                    'provincia' => $provinciaActual,
                    'cajas' => 0,
                    'volumen_m3' => 0,
                    'peso_kg' => 0,
                    'direcciones' => [],
                    'es_quito' => ($key === 'QUITO'),
                ];
            }
            $ciudades[$key]['cajas']      += (float)$cajas;
            $ciudades[$key]['volumen_m3'] += (float)$volumen;
            $ciudades[$key]['peso_kg']    += (float)$peso;

            // Dirección exacta por cliente (clave = dirección + cliente para no
            // fusionar clientes distintos que compartan un mismo edificio).
            $claveDir = ($direccionActual !== '' ? $direccionActual : '(sin dirección)')
                . '||' . self::normalize($clienteActual);
            if (!isset($ciudades[$key]['direcciones'][$claveDir])) {
                $ciudades[$key]['direcciones'][$claveDir] = [
                    'direccion' => $direccionActual,
                    'cliente' => $clienteActual,
                    'cajas' => 0,
                    'volumen_m3' => 0,
                    'peso_kg' => 0,
                ];
            }
            $ciudades[$key]['direcciones'][$claveDir]['cajas']      += (float)$cajas;
            $ciudades[$key]['direcciones'][$claveDir]['volumen_m3'] += (float)$volumen;
            $ciudades[$key]['direcciones'][$claveDir]['peso_kg']    += (float)$peso;
        }

        // Reindexar direcciones y agregar peso en toneladas.
        foreach ($ciudades as &$c) {
            $c['direcciones'] = array_values($c['direcciones']);
            $c['peso_ton'] = round($c['peso_kg'] / 1000, 3);
        }
        unset($c);

        return array_values($ciudades);
    }

    /**
     * ¿Es el formato "por ruta" (plan de introducción por local)? Se reconoce
     * porque el encabezado trae una columna RUTA (la ruta ya viene asignada en
     * el archivo, p. ej. QSG2/QSG3), junto con CIUDAD y una columna de locales
     * ("LOCALES CON INTRODUCCIÓN" o "NÚMERO DE SUC"). Los formatos de encomiendas
     * no tienen columna RUTA, así que no chocan con esta detección.
     */
    private static function detectarFormatoPorRuta(array $rows): bool
    {
        foreach ($rows as $row) {
            $etiquetas = [];
            foreach ($row as $v) {
                $e = self::normalize((string)($v ?? ''));
                if ($e !== '') $etiquetas[] = $e;
            }
            if (empty($etiquetas)) continue;
            $todo = '|' . implode('|', $etiquetas) . '|';

            $tieneRuta   = strpos($todo, '|RUTA|') !== false;
            $tieneCiudad = strpos($todo, '|CIUDAD|') !== false;
            $tieneLocal  = strpos($todo, 'LOCALES CON INTRODUCCION') !== false
                        || strpos($todo, 'NUMERO DE SUC') !== false;

            if ($tieneRuta && $tieneCiudad && $tieneLocal) {
                return true;
            }
        }
        return false;
    }

    /** Ubica el índice de cada columna del formato "por ruta". */
    private static function mapearColumnasPorRuta(array $header): array
    {
        $map = [
            'cliente' => null, 'num_suc' => null, 'local' => null,
            'ciudad' => null, 'provincia' => null, 'ruta' => null,
        ];
        foreach ($header as $i => $v) {
            $l = self::normalize((string)($v ?? ''));
            if ($l === '') continue;

            if ($map['cliente'] === null && $l === 'CLIENTE') {
                $map['cliente'] = $i;
            } elseif ($map['num_suc'] === null &&
                      (strpos($l, 'NUMERO DE SUC') !== false || strpos($l, 'SUCURSAL') !== false)) {
                $map['num_suc'] = $i;
            } elseif ($map['local'] === null && strpos($l, 'LOCALES') !== false) {
                $map['local'] = $i;
            } elseif ($map['provincia'] === null && strpos($l, 'PROVINCIA') !== false) {
                $map['provincia'] = $i;
            } elseif ($map['ciudad'] === null && strpos($l, 'CIUDAD') !== false) {
                $map['ciudad'] = $i;
            } elseif ($map['ruta'] === null && $l === 'RUTA') {
                $map['ruta'] = $i;
            }
        }
        return $map;
    }

    /**
     * Formato "por ruta": cada fila es una sucursal/local con su RUTA ya
     * asignada. Se agrupa por ciudad + ruta; cada local queda como una parada
     * (dirección) para el recorrido de entrega. No hay peso/volumen: se usa
     * "cajas" para contar los locales de cada ciudad.
     */
    private static function parsePorRuta(array $rows): array
    {
        // 1. Localizar la fila de encabezados y mapear columnas.
        $headerIndex = null;
        $map = null;
        foreach ($rows as $i => $row) {
            $etiquetas = [];
            foreach ($row as $v) {
                $etiquetas[] = self::normalize((string)($v ?? ''));
            }
            $todo = '|' . implode('|', $etiquetas) . '|';
            if (strpos($todo, '|RUTA|') !== false && strpos($todo, '|CIUDAD|') !== false
                && (strpos($todo, 'LOCALES') !== false || strpos($todo, 'NUMERO DE SUC') !== false)) {
                $headerIndex = $i;
                $map = self::mapearColumnasPorRuta($row);
                break;
            }
        }
        if ($map === null || $map['ciudad'] === null || $map['ruta'] === null) {
            return [];
        }

        // 2. Convertir las filas de datos a registros simples y agrupar.
        $registros = [];
        $provinciaActual = '';
        foreach ($rows as $i => $row) {
            if ($i <= $headerIndex) continue;

            $ciudadRaw = self::val($row, $map['ciudad']);
            $rutaRaw   = self::val($row, $map['ruta']);
            $provRaw   = $map['provincia'] !== null ? self::val($row, $map['provincia']) : null;

            // Arrastrar la provincia hacia abajo si una fila la trae vacía.
            if ($provRaw !== null && trim((string)$provRaw) !== ''
                && stripos((string)$provRaw, 'Total') === false) {
                $provinciaActual = trim((string)$provRaw);
            }

            $registros[] = [
                'cliente'   => $map['cliente'] !== null ? (string)(self::val($row, $map['cliente']) ?? '') : '',
                'num_suc'   => $map['num_suc'] !== null ? (string)(self::val($row, $map['num_suc']) ?? '') : '',
                'local'     => $map['local']   !== null ? (string)(self::val($row, $map['local'])   ?? '') : '',
                'ciudad'    => $ciudadRaw !== null ? (string)$ciudadRaw : '',
                'provincia' => $provRaw !== null && trim((string)$provRaw) !== '' ? trim((string)$provRaw) : $provinciaActual,
                'ruta'      => $rutaRaw !== null ? (string)$rutaRaw : '',
            ];
        }

        return self::construirCiudadesPorRuta($registros);
    }

    /**
     * Agrupa una lista de registros (cada uno con cliente, num_suc, local,
     * ciudad, provincia y ruta) en la estructura de "ciudades" que consume el
     * resto de la app. Cada local queda como una parada; se agrupa por
     * ciudad + ruta. Lo usan tanto el Excel "por ruta" como la lectura de
     * imágenes (VisionExtractor), para no duplicar la lógica.
     *
     * @param array<int,array<string,string>> $registros
     */
    public static function construirCiudadesPorRuta(array $registros): array
    {
        $ciudades = [];
        foreach ($registros as $r) {
            $ciudad = trim((string)($r['ciudad'] ?? ''));
            $ruta   = trim((string)($r['ruta'] ?? ''));
            if ($ciudad === '' || $ruta === '') continue;                 // sin ciudad o sin ruta: no aplica
            if (stripos($ciudad, 'Total') === 0) continue;                // fila de totales
            if (stripos($ruta, 'RUTA') === 0) continue;                   // encabezado repetido

            $cliente = trim((string)($r['cliente'] ?? ''));
            $local   = trim((string)($r['local'] ?? ''));
            $numSuc  = trim((string)($r['num_suc'] ?? ''));
            $prov    = trim((string)($r['provincia'] ?? ''));

            $key = self::normalize($ciudad) . '||' . self::normalize($ruta);
            if (!isset($ciudades[$key])) {
                $ciudades[$key] = [
                    'ciudad' => $ciudad,
                    'provincia' => $prov,
                    'cajas' => 0,
                    'volumen_m3' => 0,
                    'peso_kg' => 0,
                    'direcciones' => [],
                    'es_quito' => false,
                    'ruta_forzada' => $ruta, // la RUTA viene dada, no se deduce por ciudad
                ];
            }

            // Cada local = una parada. Se geolocaliza por CIUDAD (no hay dirección
            // exacta), y el nombre del local se muestra como "cliente" de la parada.
            $etiquetaLocal = $local !== '' ? $local : ($numSuc !== '' ? $numSuc : $ciudad);
            $nombreCliente = trim(($cliente !== '' ? $cliente : '') . ($local !== '' ? ' · ' . $local : ''));
            if ($nombreCliente === '') $nombreCliente = $etiquetaLocal;

            $ciudades[$key]['cajas'] += 1;
            $ciudades[$key]['direcciones'][] = [
                'direccion' => '',              // sin dirección exacta: ubica por ciudad
                'cliente' => $nombreCliente,
                'local' => $etiquetaLocal,
                'cajas' => 1,
                'volumen_m3' => 0,
                'peso_kg' => 0,
            ];
        }

        foreach ($ciudades as &$c) { $c['peso_ton'] = 0; }
        unset($c);

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