# Cotizador de Encomiendas por Rutas — Transervilog

Aplicación web (PHP) para cotizar encomiendas por rutas. Sube un Excel de
cotización y obtiene, por ruta: peso, volumen, camión sugerido y el
**kilometraje real por carretera** con la **guía de entrega optimizada**
(orden óptimo por dirección) y el costo. Exporta el tarifario a **Word**.

## Características
- Lectura de cotización `.xlsx` (sin dependencias externas). Soporta tres formatos:
  detallado (con dimensiones), resumen (Envibox) y "por ruta" (plan de introducción
  por local, con la ruta ya asignada, p. ej. QSG2/QSG3).
- **Lectura desde una FOTO**: sube una imagen de la tabla y la IA (visión) la lee
  y arma el recorrido por ruta. Usa **Google Gemini** (capa GRATUITA); requiere
  configurar `GEMINI_API_KEY` (ver abajo).
- Geolocalización con software libre: OpenStreetMap (Nominatim + Photon) y
  ruteo real con OSRM. Opcionalmente Google Maps si se configura una clave.
- Orden de ruta óptimo (vecino más cercano + 2-opt) empezando en Amaguaña.
- Exportación del tarifario a `.docx` usando `data/plantilla_tarifa.docx`.

## Ejecutar localmente
Requiere PHP 8+ con extensiones `curl` y `zip`.
```bash
php -S 127.0.0.1:8000
```
Luego abre http://127.0.0.1:8000

## Leer cotizaciones desde una FOTO (IA) — GRATIS con Google Gemini
Para subir una imagen/foto de la tabla en vez de un Excel, configura una clave
**gratuita** de Google Gemini:
1. Entra a **https://aistudio.google.com/apikey** con tu cuenta de Google y crea
   una **API key** (gratis, sin tarjeta).
2. En **Render**: panel del servicio → *Environment* → añade `GEMINI_API_KEY` con
   esa clave. (En local: exporta `GEMINI_API_KEY=...` o crea `config.local.php`
   que devuelva `['gemini_api_key' => '...']`.)

El modelo por defecto es `gemini-2.0-flash` (gratuito); se puede cambiar con
`GEMINI_MODEL` o `['gemini_model' => '...']`.

Alternativa opcional de pago (más precisa): configura `ANTHROPIC_API_KEY` (Claude);
si está presente junto con Gemini, se usa Gemini por ser gratis.

La foto debe mostrar una tabla con al menos las columnas **Ciudad** y **Ruta**
(formato "plan de introducción por local"). Sin clave, la lectura por Excel sigue
funcionando igual.

## Despliegue gratuito (Docker / Render)
El repo incluye `Dockerfile` y `render.yaml`. En https://render.com:
1. New → Blueprint → conecta este repositorio.
2. Render detecta `render.yaml` y crea el servicio web (plan Free).
3. Al terminar, entrega una URL pública `https://...onrender.com`.

Cualquier host con Docker (Railway, Fly.io) también sirve con el mismo `Dockerfile`.

## Estructura
- `api/` endpoints PHP · `includes/` clases · `js/` `css/` frontend
- `data/*.json` configuración de rutas, camiones, coordenadas
- `data/plantilla_tarifa.docx` plantilla del Word
