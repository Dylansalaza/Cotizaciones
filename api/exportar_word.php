<?php
/**
 * Exporta el tarifario usando data/plantilla_tarifa.docx como PLANTILLA (mismo
 * diseño que el Word de referencia de Transervilog). Solo se reemplazan los
 * datos dinámicos: fecha, toneladas y precio por ruta, y el TOTAL GENERAL.
 * Precios calculados por destinatario (ida), Oriente x1.25.
 */
require_once __DIR__ . '/../config.php';

if (empty($_SESSION['ruta_direcciones']['rutas'])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Primero sube una cotización para generar el resultado.';
    exit;
}
$plantilla = __DIR__ . '/../data/plantilla_tarifa.docx';
if (!is_file($plantilla)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Falta la plantilla data/plantilla_tarifa.docx.';
    exit;
}

$data = $_SESSION['ruta_direcciones'];
$rutas = $data['rutas'];

// Fecha larga en español.
$meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$fecha = (int)date('j') . ' de ' . $meses[(int)date('n')] . ' de ' . date('Y');

$fmtTon = fn($t) => number_format((float)$t, 3, '.', '') . ' t';
$fmtPre = fn($p) => '$ ' . number_format((float)$p, 2, '.', ',');

// Rutas del tarifario (las filas fijas de la plantilla).
$known = ['ruta1_sierra', 'ruta1_costa', 'ruta1_oriente', 'ruta2', 'ruta_pichincha', 'ruta_individual'];

$tmp = tempnam(sys_get_temp_dir(), 'cot') . '.docx';
copy($plantilla, $tmp);
$zip = new ZipArchive();
$zip->open($tmp);
$xml = $zip->getFromName('word/document.xml');

$xml = str_replace('{{FECHA}}', $fecha, $xml);

$sumT = 0; $sumP = 0;
foreach ($known as $id) {
    $r = $rutas[$id] ?? null;
    $t = $r ? (float)($r['peso_total_ton'] ?? 0) : 0;
    $p = $r ? (float)($r['precio'] ?? 0) : 0;
    $sumT += $t; $sumP += $p;
    $xml = str_replace('{{T_' . $id . '}}', $fmtTon($t), $xml);
    $xml = str_replace('{{P_' . $id . '}}', $fmtPre($p), $xml);
}
$xml = str_replace('{{T_TOTAL}}', $fmtTon($sumT), $xml);
$xml = str_replace('{{P_TOTAL}}', $fmtPre($sumP), $xml);

$zip->addFromString('word/document.xml', $xml);
$zip->close();

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="cotizacion_rutas_transervilog.docx"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
@unlink($tmp);
