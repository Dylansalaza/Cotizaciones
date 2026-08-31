# Cotizador de Encomiendas por Rutas — Transervilog

Aplicación web (PHP) para cotizar encomiendas por rutas. Sube un Excel de
cotización y obtiene, por ruta: peso, volumen, camión sugerido y el
**kilometraje real por carretera** con la **guía de entrega optimizada**
(orden óptimo por dirección) y el costo. Exporta el tarifario a **Word**.

## Características
- Lectura de cotización `.xlsx` (sin dependencias externas).
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
