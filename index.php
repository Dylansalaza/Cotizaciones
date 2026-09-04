<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cotizador de Encomiendas por Rutas</title>
<link rel="stylesheet" href="css/style.css">
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <div class="logo">🚚</div>
    <div>
      <div class="brand">Transervilog · Transporte y Logística</div>
      <h1>Cotizador de Encomiendas por Rutas</h1>
      <p>Sube tu Excel de cotización y obtén peso, volumen, camión sugerido y la ruta óptima.</p>
    </div>
  </div>
</header>

<main class="container">
  <section class="card" id="upload-card">
    <h2>1. Subir cotización (.xlsx)</h2>
    <form id="form-upload">
      <input type="file" id="input-excel" name="excel" accept=".xlsx" required>
      <button type="submit" class="btn btn-primary">Procesar Excel</button>
    </form>
    <div id="upload-status"></div>
  </section>

  <section class="card" id="rutas-card">
    <div class="rutas-header">
      <h2>2. Rutas configuradas (Fijas)</h2>
      <button id="btn-agregar-ruta" class="btn btn-secondary">➕ Agregar Nueva Ruta</button>
    </div>
    <div id="rutas-lista" class="rutas-grid"></div>
  </section>

  <section class="card" id="sin-asignar-card" style="display:none;">
    <h2>⚠️ Ciudades sin ruta asignada</h2>
    <div id="sin-asignar-lista"></div>
  </section>

  <section class="card" id="ubicaciones-card" style="display:none;">
    <div class="rutas-header">
      <h2>📍 Ubicaciones para compartir</h2>
      <button id="btn-ver-ubicaciones" class="btn btn-primary">📍 Ver ubicaciones</button>
    </div>
    <p class="ubi-ayuda">Puntos exactos de entrega. Agrúpalos por provincia o por ciudad y compártelos por WhatsApp.</p>
    <div id="ubicaciones-controles" style="display:none;">
      <div class="ubi-toggle" role="group" aria-label="Agrupar por">
        <span class="ubi-toggle-label">Agrupar por:</span>
        <button class="ubi-toggle-btn activo" data-modo="provincia">Provincia</button>
        <button class="ubi-toggle-btn" data-modo="ciudad">Ciudad</button>
        <button class="ubi-toggle-btn" data-modo="ruta">Ruta fija</button>
      </div>
      <span id="ubicaciones-resumen" class="ubi-resumen"></span>
    </div>
    <div id="ubicaciones-estado"></div>
    <div id="ubicaciones-lista"></div>
  </section>

  <section class="card" id="resultado-card" style="display:none;">
    <div class="rutas-header">
      <h2>3. Resultado por ruta</h2>
      <div class="acciones-resultado">
        <span id="total-general" class="total-general" style="display:none;"></span>
        <button id="btn-descargar-word" class="btn btn-primary" style="display:none;">📄 Descargar Word</button>
      </div>
    </div>
    <div id="estado-calculo"></div>
    <div id="resultado-lista"></div>
  </section>
</main>

<script src="js/app.js"></script>
</body>
</html>