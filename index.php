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
  <h1>📦 Cotizador de Encomiendas por Rutas</h1>
  <p>Sube tu Excel de cotización y obtén peso, volumen, camión sugerido y ruta.</p>
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

  <section class="card" id="resultado-card" style="display:none;">
    <div class="rutas-header">
      <h2>3. Resultado por ruta</h2>
      <button id="btn-descargar-word" class="btn btn-primary" style="display:none;">📄 Descargar Word</button>
    </div>
    <div id="estado-calculo"></div>
    <div id="resultado-lista"></div>
  </section>
</main>

<script src="js/app.js"></script>
</body>
</html>