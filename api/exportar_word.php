<?php
/**
 * Genera por código el tarifario Transervilog en Word (.docx) con el diseño
 * corporativo: membrete navy, tabla Ruta | Ciudades | Precio (sin toneladas) y
 * TOTAL GENERAL. Los precios pueden venir editados desde el navegador
 * (POST 'precios' = JSON {rutaId: precio}); si no, usa los calculados.
 */
require_once __DIR__ . '/../config.php';

if (empty($_SESSION['ruta_direcciones']['rutas'])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Primero sube una cotización para generar el resultado.';
    exit;
}
$data = $_SESSION['ruta_direcciones'];
$rutas = $data['rutas'];

$preciosPost = [];
if (!empty($_POST['precios'])) {
    $tmp = json_decode($_POST['precios'], true);
    if (is_array($tmp)) $preciosPost = $tmp;
}
$precioDe = function ($id) use ($rutas, $preciosPost) {
    if (isset($preciosPost[$id]) && is_numeric($preciosPost[$id])) return (float)$preciosPost[$id];
    return isset($rutas[$id]) ? (float)($rutas[$id]['precio'] ?? 0) : 0.0;
};

function xml_e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }
$fmtPre = fn($p) => '$ ' . number_format((float)$p, 2, '.', ',');
$FONT = '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>';

/** Run de texto con estilo. */
function run(string $t, array $o = []): string
{
    global $FONT;
    $r = $FONT;
    if (!empty($o['bold'])) $r .= '<w:b/>';
    if (!empty($o['caps'])) $r .= '<w:caps/>';
    if (!empty($o['italic'])) $r .= '<w:i/>';
    if (isset($o['color'])) $r .= '<w:color w:val="' . $o['color'] . '"/>';
    if (isset($o['size'])) $r .= '<w:sz w:val="' . (int)$o['size'] . '"/>';
    return '<w:r><w:rPr>' . $r . '</w:rPr><w:t xml:space="preserve">' . xml_e($t) . '</w:t></w:r>';
}
/** Párrafo. $contenido = string (texto simple) o runs ya armados si $raw. */
function para($contenido, array $o = []): string
{
    $ppr = '';
    if (isset($o['align'])) $ppr .= '<w:jc w:val="' . $o['align'] . '"/>';
    if (isset($o['after']) || isset($o['before'])) {
        $ppr .= '<w:spacing' . (isset($o['after']) ? ' w:after="' . (int)$o['after'] . '"' : '')
              . (isset($o['before']) ? ' w:before="' . (int)$o['before'] . '"' : '') . '/>';
    }
    $ppr = $ppr ? "<w:pPr>$ppr</w:pPr>" : '';
    $body = !empty($o['raw']) ? $contenido : run($contenido, $o);
    return "<w:p>$ppr$body</w:p>";
}
/** Celda. $o: w (ancho), shade, span, align, valign. $parrafos = html de w:p. */
function celda(string $parrafos, array $o = []): string
{
    global $W;
    $tcpr = '<w:tcW w:w="' . (int)($o['w'] ?? 0) . '" w:type="dxa"/>';
    if (!empty($o['span'])) $tcpr .= '<w:gridSpan w:val="' . (int)$o['span'] . '"/>';
    if (!empty($o['shade'])) $tcpr .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $o['shade'] . '"/>';
    $tcpr .= '<w:tcMar><w:top w:w="120" w:type="dxa"/><w:left w:w="160" w:type="dxa"/><w:bottom w:w="120" w:type="dxa"/><w:right w:w="160" w:type="dxa"/></w:tcMar>';
    $tcpr .= '<w:vAlign w:val="' . ($o['valign'] ?? 'center') . '"/>';
    return '<w:tc><w:tcPr>' . $tcpr . '</w:tcPr>' . $parrafos . '</w:tc>';
}
/** Tabla con anchos de columna y bordes suaves. */
function tabla(array $gridCols, string $filas): string
{
    $grid = '';
    foreach ($gridCols as $w) $grid .= '<w:gridCol w:w="' . (int)$w . '"/>';
    $b = fn($p) => '<w:' . $p . ' w:val="single" w:sz="4" w:color="C3CCD6"/>';
    $bordes = '<w:tblBorders>' . $b('top') . $b('left') . $b('bottom') . $b('right') . $b('insideH') . $b('insideV') . '</w:tblBorders>';
    return '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/>' . $bordes . '</w:tblPr><w:tblGrid>' . $grid . '</w:tblGrid>' . $filas . '</w:tbl>';
}

// Etiqueta de ruta.
function rutaEtiqueta(string $id, array $r): string
{
    $m = [
        'ruta1_sierra' => 'Ruta 1 – Sierra', 'ruta1_costa' => 'Ruta 1 – Costa',
        'ruta1_oriente' => 'Ruta 1 – Oriente', 'ruta2' => 'Ruta 2',
        'ruta_pichincha' => 'Ruta Interna – Pichincha',
    ];
    if (isset($m[$id])) return $m[$id];
    if ($id === 'ruta_individual') return 'Individual – ' . ($r['ciudades_lista'] ?? $r['nombre'] ?? '');
    return $r['nombre'] ?? $id;
}

$meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$fecha = (int)date('j') . ' de ' . $meses[(int)date('n')] . ' de ' . date('Y');

// ===== Cuerpo =====
$body = '';

// Membrete: tabla 2 columnas.
$izq = para('TRANSERVILOG', ['bold' => true, 'size' => 34, 'color' => 'FFFFFF', 'after' => 40])
     . para('Transporte y logística de encomiendas a nivel nacional', ['size' => 17, 'color' => 'C9D3DC']);
$der = para('Gerente: Silvia Panchi', ['bold' => true, 'size' => 19, 'color' => 'FFFFFF', 'align' => 'right', 'after' => 20])
     . para('Tel: 0986820326', ['size' => 19, 'color' => 'FFFFFF', 'align' => 'right', 'after' => 20])
     . para('Email: transervilogi@gmail.com', ['size' => 19, 'color' => 'FFFFFF', 'align' => 'right']);
$body .= tabla([5100, 4700],
    '<w:tr>' . celda($izq, ['w' => 5100, 'shade' => '14293D']) . celda($der, ['w' => 4700, 'shade' => '1B3A5C']) . '</w:tr>');

$body .= para('COTIZACIÓN DE RUTAS', ['bold' => true, 'size' => 34, 'color' => '1B3A5C', 'before' => 280, 'after' => 60]);
$body .= para('Fecha: ' . $fecha, ['size' => 20, 'color' => '202B36', 'after' => 40]);
$body .= para(
    run('Cliente: ', ['bold' => true, 'size' => 20, 'color' => '202B36'])
    . run('______________________________________________', ['size' => 20, 'color' => '202B36']),
    ['raw' => true, 'after' => 40]
);
$body .= para('Tarifario referencial por ruta fija, según kilometraje.', ['italic' => true, 'size' => 18, 'color' => '5A6B7A', 'after' => 220]);

// Tabla del tarifario: Ruta | Ciudades | Precio.
$W_RUTA = 2600; $W_CIU = 5600; $W_PRE = 1600;
$th = fn($t, $w, $al) => celda(para($t, ['bold' => true, 'caps' => true, 'size' => 20, 'color' => 'FFFFFF', 'align' => $al]), ['w' => $w, 'shade' => '1B3A5C']);
$filas = '<w:tr><w:trPr><w:tblHeader/></w:trPr>'
    . $th('Ruta', $W_RUTA, 'left') . $th('Ciudades', $W_CIU, 'left') . $th('Precio', $W_PRE, 'center') . '</w:tr>';

$known = ['ruta1_sierra', 'ruta1_costa', 'ruta1_oriente', 'ruta2', 'ruta_pichincha', 'ruta_individual'];
$sumP = 0;
foreach ($known as $id) {
    if (!isset($rutas[$id])) continue;
    $r = $rutas[$id];
    $p = $precioDe($id);
    $sumP += $p;
    $filas .= '<w:tr>'
        . celda(para(rutaEtiqueta($id, $r), ['bold' => true, 'size' => 19, 'color' => '17324A']), ['w' => $W_RUTA])
        . celda(para($r['ciudades_lista'] ?? '', ['size' => 18, 'color' => '2B3948']), ['w' => $W_CIU])
        . celda(para($fmtPre($p), ['bold' => true, 'size' => 19, 'color' => '1B3A5C', 'align' => 'right']), ['w' => $W_PRE])
        . '</w:tr>';
}
// Fila TOTAL GENERAL (primera celda combinada Ruta+Ciudades).
$filas .= '<w:tr>'
    . celda(para('TOTAL GENERAL', ['bold' => true, 'caps' => true, 'size' => 20, 'color' => '17324A']), ['w' => $W_RUTA + $W_CIU, 'span' => 2, 'shade' => 'EAF1F7'])
    . celda(para($fmtPre($sumP), ['bold' => true, 'size' => 21, 'color' => '167A45', 'align' => 'right']), ['w' => $W_PRE, 'shade' => 'EAF1F7'])
    . '</w:tr>';

$body .= tabla([$W_RUTA, $W_CIU, $W_PRE], $filas);

$body .= para('* Costos calculados según recorrido real por destinatario (ida) — $1,00 por km, salvo Oriente ($1,25 por km).', ['italic' => true, 'size' => 16, 'color' => '5A6B7A', 'before' => 160, 'after' => 240]);
$body .= para('Gracias por confiar en Transervilog. Quedamos atentos a cualquier consulta adicional sobre esta cotización.', ['size' => 20, 'color' => '202B36', 'after' => 220]);
$body .= para('Atentamente,', ['size' => 20, 'color' => '202B36', 'after' => 20]);
$body .= para('Silvia Panchi', ['bold' => true, 'size' => 20, 'color' => '202B36']);

$documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body
    . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1100" w:right="1100" w:bottom="1000" w:left="1100" w:header="0" w:footer="0" w:gutter="0"/></w:sectPr>'
    . '</w:body></w:document>';

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
    . '</Types>';
$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
    . '</Relationships>';

$tmp = tempnam(sys_get_temp_dir(), 'cot') . '.docx';
$zip = new ZipArchive();
$zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $rels);
$zip->addFromString('word/document.xml', $documentXml);
$zip->close();

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="cotizacion_rutas_transervilog.docx"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
@unlink($tmp);
