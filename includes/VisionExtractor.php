<?php
require_once __DIR__ . '/CotizacionParser.php';

/**
 * Lee una FOTO/imagen de una tabla de "plan de introducción por local" y
 * extrae sus filas con IA de visión. No requiere tener el Excel: basta la foto.
 *
 * Proveedores admitidos (se elige automáticamente según la clave configurada):
 *   1) Google Gemini  — capa GRATUITA (clave gratis en Google AI Studio).
 *      Clave: variable de entorno GEMINI_API_KEY, o config.local.php ['gemini_api_key'].
 *   2) Anthropic Claude — de pago (alternativa opcional, más precisa).
 *      Clave: variable de entorno ANTHROPIC_API_KEY, o config.local.php ['anthropic_api_key'].
 *
 * Devuelve la misma estructura de "ciudades" que el resto de la app, con la
 * ruta ya asignada (viene en la tabla), lista para el recorrido por ruta.
 */
class VisionExtractor
{
    private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const GEMINI_MODELO   = 'gemini-3.6-flash';

    private const ANTHROPIC_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_VERSION  = '2023-06-01';
    private const ANTHROPIC_MODELO   = 'claude-opus-5';

    private static function configPath(): string { return __DIR__ . '/../config.local.php'; }

    private static function config(string $clave): string
    {
        if (is_file(self::configPath())) {
            $cfg = include self::configPath();
            if (is_array($cfg) && !empty($cfg[$clave])) return (string)$cfg[$clave];
        }
        return '';
    }

    /** Clave gratuita de Google Gemini (o '' si no está configurada). */
    public static function geminiKey(): string
    {
        $env = getenv('GEMINI_API_KEY');
        if (is_string($env) && trim($env) !== '') return trim($env);
        return self::config('gemini_api_key');
    }

    /** Clave de Anthropic (alternativa de pago, o '' si no está configurada). */
    public static function anthropicKey(): string
    {
        $env = getenv('ANTHROPIC_API_KEY');
        if (is_string($env) && trim($env) !== '') return trim($env);
        return self::config('anthropic_api_key');
    }

    public static function hayApiKey(): bool
    {
        return self::geminiKey() !== '' || self::anthropicKey() !== '';
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
     * Llama a la IA de visión con la imagen y devuelve una lista de registros
     * [ ['cliente','num_suc','local','ciudad','provincia','ruta'], ... ].
     */
    public static function extraerFilas(string $imgPath, string $mime): array
    {
        $mediaType = self::mediaType($mime, $imgPath);
        $bytes = @file_get_contents($imgPath);
        if ($bytes === false || $bytes === '') {
            throw new Exception('No se pudo leer el archivo de imagen subido.');
        }
        $b64 = base64_encode($bytes);
        $instrucciones = self::instrucciones();

        if (self::geminiKey() !== '') {
            $texto = self::llamarGemini($b64, $mediaType, $instrucciones);
        } elseif (self::anthropicKey() !== '') {
            $texto = self::llamarAnthropic($b64, $mediaType, $instrucciones);
        } else {
            throw new Exception('Para leer imágenes falta configurar una clave gratuita. Crea una clave GRATIS en Google AI Studio (https://aistudio.google.com/apikey) y ponla en Render como variable GEMINI_API_KEY.');
        }

        $texto = trim($texto);
        if ($texto === '') {
            throw new Exception('La IA no devolvió texto para analizar. Prueba con una foto más nítida.');
        }

        $datos = self::extraerJson($texto);
        $filas = $datos['filas'] ?? (isset($datos[0]) ? $datos : []);
        if (!is_array($filas)) {
            throw new Exception('No se encontró una lista de filas en la respuesta de la IA.');
        }

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

    /** Instrucciones (prompt) para la IA de visión. */
    private static function instrucciones(): string
    {
        return <<<TXT
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
- "ruta": la columna RUTA (por ejemplo QSG2, QSG3). Es obligatoria; si una fila no tiene ruta, omítela.
- Copia exactamente lo que ves. No inventes filas ni valores. Si una celda está vacía, usa "".
TXT;
    }

    // ---------------------------------------------------------------------
    //  Google Gemini (gratis)
    // ---------------------------------------------------------------------
    /**
     * Modelos de Gemini a probar, en orden. Si el usuario fijó uno (GEMINI_MODEL
     * o config), va primero; luego varios de respaldo, porque los flash pueden
     * saturarse (503) por picos de demanda y conviene tener alternativas.
     */
    private static function modelosGemini(): array
    {
        $userSel = self::config('gemini_model') ?: (getenv('GEMINI_MODEL') ?: '');
        $lista = [];
        if ($userSel !== '') $lista[] = $userSel;
        foreach ([self::GEMINI_MODELO, 'gemini-3.5-flash', 'gemini-3.7-flash', 'gemini-flash-latest'] as $m) {
            $lista[] = $m;
        }
        return array_values(array_unique($lista));
    }

    private static function llamarGemini(string $b64, string $mediaType, string $instrucciones): string
    {
        $modelos = self::modelosGemini();
        $rondas = 2;            // dos pasadas por toda la lista de modelos
        $ultimoError = '';

        for ($ronda = 0; $ronda < $rondas; $ronda++) {
            foreach ($modelos as $modelo) {
                try {
                    return self::pedirGemini($modelo, $b64, $mediaType, $instrucciones);
                } catch (Exception $e) {
                    // Clave inválida / sin permiso: no tiene sentido seguir probando.
                    if ($e->getCode() === 401 || $e->getCode() === 403) throw $e;
                    $ultimoError = $e->getMessage();
                    // Cualquier otro error (saturación 503/429, modelo no disponible,
                    // fallo de red): seguir con el siguiente modelo.
                }
            }
            if ($ronda < $rondas - 1) sleep(5); // esperar antes de otra pasada
        }
        throw new Exception($ultimoError !== ''
            ? ('No se pudo leer la imagen (los modelos de IA están ocupados). ' . $ultimoError)
            : 'No se pudo leer la imagen con Gemini.');
    }

    /** Una sola llamada a Gemini con un modelo concreto. Devuelve el texto. */
    private static function pedirGemini(string $modelo, string $b64, string $mediaType, string $instrucciones): string
    {
        $url = self::GEMINI_ENDPOINT . rawurlencode($modelo) . ':generateContent';
        $payload = [
            'contents' => [[
                'parts' => [
                    ['inline_data' => ['mime_type' => $mediaType, 'data' => $b64]],
                    ['text' => $instrucciones],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0,
                'response_mime_type' => 'application/json',
            ],
        ];
        $headers = [
            'content-type: application/json',
            'x-goog-api-key: ' . self::geminiKey(),
        ];
        $r = self::httpPost($url, $payload, $headers, 'Gemini');
        $j = json_decode($r, true);
        if (!is_array($j)) {
            throw new Exception('Gemini devolvió una respuesta no válida.');
        }
        if (isset($j['error'])) {
            throw new Exception('Gemini devolvió un error: ' . ($j['error']['message'] ?? 'desconocido'));
        }
        $texto = '';
        foreach (($j['candidates'][0]['content']['parts'] ?? []) as $p) {
            $texto .= (string)($p['text'] ?? '');
        }
        return $texto;
    }

    // ---------------------------------------------------------------------
    //  Anthropic Claude (de pago, opcional)
    // ---------------------------------------------------------------------
    private static function llamarAnthropic(string $b64, string $mediaType, string $instrucciones): string
    {
        $modelo = self::config('anthropic_model') ?: self::ANTHROPIC_MODELO;
        $payload = [
            'model' => $modelo,
            'max_tokens' => 8000,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => $b64]],
                    ['type' => 'text', 'text' => $instrucciones],
                ],
            ]],
        ];
        $headers = [
            'content-type: application/json',
            'x-api-key: ' . self::anthropicKey(),
            'anthropic-version: ' . self::ANTHROPIC_VERSION,
        ];
        $r = self::httpPost(self::ANTHROPIC_ENDPOINT, $payload, $headers, 'Claude');
        $j = json_decode($r, true);
        if (!is_array($j)) {
            throw new Exception('Claude devolvió una respuesta no válida.');
        }
        if (isset($j['type']) && $j['type'] === 'error') {
            throw new Exception('Claude devolvió un error: ' . ($j['error']['message'] ?? 'desconocido'));
        }
        $texto = '';
        foreach (($j['content'] ?? []) as $bloque) {
            if (($bloque['type'] ?? '') === 'text') $texto .= (string)($bloque['text'] ?? '');
        }
        return $texto;
    }

    /** Extrae el primer objeto/array JSON de un texto (tolera texto o ``` alrededor). */
    private static function extraerJson(string $texto): array
    {
        $t = trim($texto);
        $t = preg_replace('/^```(?:json)?\s*/i', '', $t);
        $t = preg_replace('/\s*```$/', '', $t);

        $directo = json_decode($t, true);
        if (is_array($directo)) return $directo;

        $ini = strpos($t, '{');
        $fin = strrpos($t, '}');
        if ($ini !== false && $fin !== false && $fin > $ini) {
            $j = json_decode(substr($t, $ini, $fin - $ini + 1), true);
            if (is_array($j)) return $j;
        }
        $ini = strpos($t, '[');
        $fin = strrpos($t, ']');
        if ($ini !== false && $fin !== false && $fin > $ini) {
            $j = json_decode(substr($t, $ini, $fin - $ini + 1), true);
            if (is_array($j)) return ['filas' => $j];
        }
        throw new Exception('No se pudo interpretar la respuesta de la IA como JSON.');
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

    /**
     * POST JSON (un intento). Devuelve el cuerpo, o lanza Exception con el
     * código HTTP como código de la excepción (para que el llamador distinga
     * un error de clave (401/403) de una saturación temporal (503/429)).
     */
    private static function httpPost(string $url, array $payload, array $headers, string $proveedor): string
    {
        $c = curl_init($url);
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $r = curl_exec($c);
        $errno = curl_errno($c);
        $err = curl_error($c);
        $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);

        if ($r === false || $errno !== 0) {
            throw new Exception("No se pudo contactar a la IA ($proveedor): $err", 0);
        }
        if ($code === 401 || $code === 403) {
            throw new Exception("La clave de API de $proveedor no es válida o no tiene permiso ($code). Revísala en Render.", $code);
        }
        if ($code < 200 || $code >= 300) {
            $detalle = '';
            $j = json_decode((string)$r, true);
            if (is_array($j)) {
                $detalle = $j['error']['message'] ?? ($j['error']['status'] ?? '');
                if ($detalle !== '') $detalle = ': ' . $detalle;
            }
            throw new Exception("$proveedor respondió con código $code$detalle.", $code);
        }
        return (string)$r;
    }
}
