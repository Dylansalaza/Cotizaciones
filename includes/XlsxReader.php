<?php
/**
 * Lector de archivos .xlsx sin dependencias externas.
 * Un archivo .xlsx es un ZIP con XML adentro, así que usamos
 * ZipArchive + SimpleXML (ambos vienen incluidos en PHP).
 */
class XlsxReader
{
    /**
     * Lee la PRIMERA hoja de un archivo .xlsx y devuelve un arreglo de filas.
     * Cada fila es un arreglo indexado por número de columna (0 = A, 1 = B, ...).
     * Se conserva por compatibilidad con los formatos que solo usan una hoja.
     */
    public static function read(string $filepath): array
    {
        $hojas = self::readSheets($filepath);
        return $hojas[0]['rows'] ?? [];
    }

    /**
     * Lee TODAS las hojas del libro (hasta 50) y devuelve una lista con
     * ['nombre' => ..., 'rows' => [...]] por cada hoja. Útil cuando el archivo
     * trae varias hojas (p. ej. SEM15 / MANTA / MACHALA) y hay que ubicar la
     * que contiene los datos de la cotización.
     */
    public static function readSheets(string $filepath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($filepath) !== true) {
            throw new Exception('No se pudo abrir el archivo .xlsx (¿está dañado?).');
        }

        // 1. Shared strings (textos reutilizados por Excel)
        $sharedStrings = [];
        $sstXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sstXml !== false) {
            $sst = simplexml_load_string($sstXml);
            if ($sst !== false) {
                foreach ($sst->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string)$si->t;
                    } else {
                        // texto con formato mixto (varios <r><t>)
                        $text = '';
                        foreach ($si->r as $r) {
                            $text .= (string)$r->t;
                        }
                        $sharedStrings[] = $text;
                    }
                }
            }
        }

        // 2. Nombres de las hojas (en orden), desde workbook.xml. Es solo para
        //    mostrar; si no se puede leer, se usan nombres genéricos.
        $nombres = [];
        $wbXml = $zip->getFromName('xl/workbook.xml');
        if ($wbXml !== false) {
            $wb = simplexml_load_string($wbXml);
            if ($wb !== false && isset($wb->sheets->sheet)) {
                foreach ($wb->sheets->sheet as $s) {
                    $nombres[] = (string)$s['name'];
                }
            }
        }

        // 3. Leer cada hoja física disponible (sheet1.xml, sheet2.xml, ...).
        $hojas = [];
        for ($i = 1; $i <= 50; $i++) {
            $candidate = "xl/worksheets/sheet{$i}.xml";
            if ($zip->locateName($candidate) === false) {
                continue;
            }
            $sheetXml = $zip->getFromName($candidate);
            if ($sheetXml === false) continue;
            $rows = self::parseSheetXml($sheetXml, $sharedStrings);
            $hojas[] = [
                'nombre' => $nombres[$i - 1] ?? ('Hoja' . $i),
                'rows'   => $rows,
            ];
        }

        $zip->close();

        if (empty($hojas)) {
            throw new Exception('No se encontró ninguna hoja dentro del archivo Excel.');
        }
        return $hojas;
    }

    /** Convierte el XML de una hoja en filas indexadas por columna (0 = A). */
    private static function parseSheetXml(string $sheetXml, array $sharedStrings): array
    {
        $sheet = simplexml_load_string($sheetXml);
        $rows = [];
        if ($sheet === false || !isset($sheet->sheetData)) {
            return $rows;
        }

        foreach ($sheet->sheetData->row as $row) {
            $rowIndex = (int)$row['r'] - 1;
            $rowData = [];

            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                $colIndex = self::colLetterToIndex($ref);
                $type = (string)$c['t'];

                $value = null;
                if ($type === 's') {
                    // shared string
                    $idx = (int)$c->v;
                    $value = $sharedStrings[$idx] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string)$c->is->t;
                } elseif ($type === 'str') {
                    $value = (string)$c->v;
                } else {
                    // numérico (o vacío)
                    if (isset($c->v)) {
                        $raw = (string)$c->v;
                        $value = is_numeric($raw) ? $raw + 0 : $raw;
                    } else {
                        $value = null;
                    }
                }

                $rowData[$colIndex] = $value;
            }

            if (empty($rowData)) {
                continue;
            }

            $maxCol = max(array_keys($rowData));
            $normalized = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $normalized[$i] = $rowData[$i] ?? null;
            }

            $rows[$rowIndex] = $normalized;
        }

        ksort($rows);
        return array_values($rows);
    }

    /** Convierte una referencia tipo "C7" al índice de columna base 0 (C -> 2) */
    private static function colLetterToIndex(string $ref): int
    {
        preg_match('/[A-Z]+/', $ref, $m);
        $letters = $m[0] ?? 'A';
        $index = 0;
        foreach (str_split($letters) as $char) {
            $index = $index * 26 + (ord($char) - ord('A') + 1);
        }
        return $index - 1;
    }
}
