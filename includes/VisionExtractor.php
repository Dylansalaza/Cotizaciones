<?php
require_once __DIR__ . '/CotizacionParser.php';

/**
 * Lee una FOTO/imagen de una tabla de "plan de introducción por local" y
 * extrae sus filas usando la API de Claude (visión). No requiere que el
 * usuario tenga el Excel: basta con subir la foto.
 *
 * Devuelve la misma estructura de "ciudades" que el resto de la app, con la
 * ruta ya asignada (viene en la propia tabla), lista para el cálculo de
 * recorrido por ruta.
 *
 * Requiere una clave de API de Anthropic, tomada de:
 *   1) la variable de entorno ANTHROPIC_API_KEY (recomendado en Render), o
 *   2) config.local.php  ->  ['anthropic_api_key' => '...'].
 */
class VisionExtractor
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const MODELO_DEFECTO = 'claude-opus-5';

    private static function configPath(): string { return __DIR__ . '/../config.local.php'; }

    /** Clave de API de Anthropic (o '' si no está configurada). */
    public static function apiKey(): string
    {
        $env = getenv('ANTHROPIC_API_KEY');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }
        if (is_file(self::configPath())) {
            $cfg = include self::configPath();
            if (is_array($cfg) && !empty($cfg['anthropic_api_key'])) {
                return (string)$cfg['anthropic_api_key'];
            }
        }
        return '';
    }

    public static function hayApiKey(): bool
    {
        return self::apiKey() !== '';
    }

    /** Modelo a usar (configurable; por defecto Claude Opus 5). */
    private static function modelo(): string
    {
        if (is_file(self::configPath())) {
            $cfg = include self::configPath();
            if (is_array($cfg) && !empty($cfg['anthropic_model'])) {
                return (string)$cfg['anthropic_model'];
            }
        }
        return self::MODELO_DEFECTO;
    }

    /**
     * Lee la imagen y devuelve la estructura de "ciudades" lista para calcular
     * el recorrido por ruta. Lanza Exception con un mensaje claro si algo falla.
     */
    public static function procesarImagen(string $imgPath, string $mime): array
    {
        $filas = self::extraerFilas($imgPath, $mime);
        if (empty($filas)) {
            throw new Exception('No se pudieron leer filas de la imagen. Asegúrate de que la tabla (con columnas CIUDAD y RUTA) se vea nítida y completa.');
        }
        return CotizacionParser::construirCiudadesPorRuta($filas);
    }

    /**
     * Llama a la API de Claude con la imagen y devuelve una lista de registros
     * [ ['cliente','num_suc','local','ciudad','provincia','ruta'], ... ].
     */
    public static function extraerFilas(string $imgPath, string $mime): array
    {
        $key = self::apiKey();
        if ($key === '') {
            throw new Exception('Falta la clave de API de Anthropic. Configúrala en la variable de entorno ANTHROPIC_API_KEY (en Render) o en config.local.php.');
        }

        $mediaType = self::mediaType($mime, $imgPath);
        $bytes = @file_get_contents($imgPath);
        if ($bytes === false || $bytes === '') {
            throw new Exception('No se pudo leer el archivo de imagen subido.');
        }
        $b64 = base64_encode($bytes);

        $instrucciones = <<<TXT
Extrae los datos de esta tabla fotografiada de un "plan de introducción por local".
Devuelve ÚNICAMENTE un objeto JSON válido (sin explicaciones, sin markdown, sin ```), con esta forma exacta:
{"filas": [{"cliente": "", "num_suc": "", "local": "", "ciudad": "", "provincia": "", "ruta": ""}]}

Reglas:
- Una fila por cada LOCAL/sucursal de la tabla. Ignora filas de totales, subtotales y encabezados.
- "cliente": la columna CLIENTE (por ejemplo TIA, MICO).
- "num_suc": el NÚMERO DE SUC (si dice SN u otro texto, cópialo tal cual; si no hay, "").
- "local": el nombre del local (columna "LOCALES CON INTRODUCCIÓN").
- "ciudad": la columna CIUDAD.
- "provincia": la columna PROVINCIA.
- "ruta": la columna RUTA (por ejemplo QSG2, QSG3). Esta columna es obligatoria; si una fila no tiene ruta, omítela.
- Copia exactamente lo que ves. No inventes filas ni valores. Si una celda está vacía, usa "".
TXT;

        $payload = [
            'model' => self::modelo(),
            'max_tokens' => 8000,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => $b64]],
                    ['type' => 'text', 'text' => $instrucciones],
                ],
            ]],
        ];

        $resp = self::httpPost($payload, $key);
        $json = json_decode($resp, true);
        if (!is_array($json)) {
            throw new Exception('La API de Claude devolvió una respuesta no válida.');
        }
        if (isset($json['type']) && $json['type'] === 'error') {
            $msg = $json['error']['message'] ?? 'error desconocido';
            throw new Exception('La API de Claude devolvió un error: ' . $msg);
        }

        // Reunir todo el texto de la respuesta (ignorando bloques de "thinking").
        $texto = '';
        foreach (($json['content'] ?? []) as $bloque) {
            if (($bloque['type'] ?? '') === 'text') {
                $texto .= (string)($bloque['text'] ?? '');
            }
        }
        $texto = trim($texto);
        if ($texto === '') {
            throw new Exception('La API de Claude no devolvió texto para analizar.');
        }

        $datos = self::extraerJson($texto);
        $filas = $datos['filas'] ?? (isset($datos[0]) ? $datos : []);
        if (!is_array($filas)) {
            throw new Exception('No se encontró una lista de filas en la respuesta.');
        }

        // Normalizar a claves conocidas.
        $registros = [];
        foreach ($filas as $f) {
            if (!is_array($f)) continue;
            $registros[] = [
                'cliente'   => trim((string)($f['cliente']   ?? '')),
                'num_suc'   => trim((string)($f['num_suc']   ?? '')),
                'local'     => trim((string)($f['local']     ?? '')),
                'ciudad'    => trim((string)($f['ciudad']    ?? '')),
                'provincia' => trim((string)($f['provincia'] ?? '')),
                'ruta'      => trim((string)($f['ruta']      ?? '')),
            ];
        }
        return $registros;
    }

    /** Extrae el primer objeto/array JSON de un texto (tolera texto o ``` alrededor). */
    private static function extraerJson(string $texto): array
    {
        $t = trim($texto);
        // Quitar cercas de markdown si vinieran.
        $t = preg_replace('/^```(?:json)?\s*/i', '', $t);
        $t = preg_replace('/\s*```$/', '', $t);

        $directo = json_decode($t, true);
        if (is_array($directo)) return $directo;

        // Buscar el primer bloque {...} equilibrado.
        $ini = strpos($t, '{');
        $fin = strrpos($t, '}');
        if ($ini !== false && $fin !== false && $fin > $ini) {
            $sub = substr($t, $ini, $fin - $ini + 1);
            $j = json_decode($sub, true);
            if (is_array($j)) return $j;
        }
        // Como respaldo, intentar con un array [...].
        $ini = strpos($t, '[');
        $fin = strrpos($t, ']');
        if ($ini !== false && $fin !== false && $fin > $ini) {
            $sub = substr($t, $ini, $fin - $ini + 1);
            $j = json_decode($sub, true);
            if (is_array($j)) return ['filas' => $j];
        }
        throw new Exception('No se pudo interpretar la respuesta de la imagen como JSON.');
    }

    /** Determina el media_type a partir del mime o la extensión. */
    private static function mediaType(string $mime, string $imgPath): string
    {
        $mime = strtolower(trim($mime));
        $validos = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (in_array($mime, $validos, true)) return $mime;
        if ($mime === 'image/jpg') return 'image/jpeg';

        $ext = strtolower(pathinfo($imgPath, PATHINFO_EXTENSION));
        $mapa = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
        return $mapa[$ext] ?? 'image/jpeg';
    }

    /** POST JSON a la API de Anthropic. Devuelve el cuerpo de la respuesta. */
    private static function httpPost(array $payload, string $key): string
    {
        $c = curl_init(self::ENDPOINT);
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'content-type: application/json',
                'x-api-key: ' . $key,
                'anthropic-version: ' . self::API_VERSION,
            ],
        ]);
        $r = curl_exec($c);
        $errno = curl_errno($c);
        $err = curl_error($c);
        $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);

        if ($r === false || $errno !== 0) {
            throw new Exception('No se pudo contactar a la API de Claude (' . $err . ').');
        }
        if ($code === 401) {
            throw new Exception('La clave de API de Anthropic no es válida (401). Revísala en Render.');
        }
        if ($code < 200 || $code >= 300) {
            $detalle = '';
            $j = json_decode((string)$r, true);
            if (is_array($j) && isset($j['error']['message'])) $detalle = ': ' . $j['error']['message'];
            throw new Exception('La API de Claude respondió con código ' . $code . $detalle . '.');
        }
        return (string)$r;
    }
}
