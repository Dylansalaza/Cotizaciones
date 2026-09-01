const $ = (sel) => document.querySelector(sel);

let rutasActuales = {};
let rutaActiva = null;
let cotizacionCargada = false;
let hubCoords = null;

async function cargarRutas() {
  const res = await fetch('api/rutas.php?accion=listar');
  const data = await res.json();
  if (!data.ok) return;
  rutasActuales = data.rutas;
  pintarRutas(data.rutas);
}

function pintarRutas(rutas) {
  const cont = $('#rutas-lista');
  cont.innerHTML = '';
  
  // Crear menú de navegación de rutas
  const menuRutas = document.createElement('div');
  menuRutas.className = 'menu-rutas';
  
  for (const [id, r] of Object.entries(rutas)) {
    // Botón para cada ruta en el menú
    const btnRuta = document.createElement('button');
    btnRuta.className = 'btn-ruta';
    btnRuta.dataset.id = id;
    btnRuta.textContent = `${r.nombre} (${r.lugares.length})`;
    btnRuta.onclick = () => mostrarRuta(id);
    menuRutas.appendChild(btnRuta);
    
    // Contenedor de la ruta (oculto por defecto)
    const box = document.createElement('div');
    box.className = 'ruta-box';
    box.id = `ruta-${id}`;
    box.style.display = 'none';
    box.innerHTML = `
      <div class="ruta-box-top">
        <div>
          <div class="grupo">${r.grupo}</div>
          <h3>${r.nombre}</h3>
        </div>
        <button class="btn-eliminar-ruta" data-id="${id}" title="Eliminar ruta">🗑</button>
      </div>
      <ul>${r.lugares.map(l => `<li>
        ${l}
        <button class="btn-eliminar-lugar" data-ruta-id="${id}" data-lugar="${l}" title="Eliminar lugar">✕</button>
      </li>`).join('')}</ul>
      <details class="agregar-lugar">
        <summary>➕ Agregar lugar a esta ruta</summary>
        <div class="inline-form">
          <input type="text" class="input-nuevo-lugar" placeholder="Nombre del lugar">
          <button class="btn btn-mini btn-agregar-lugar" data-id="${id}">Agregar</button>
        </div>
      </details>
    `;
    cont.appendChild(box);
  }
  
  // Insertar menú al inicio
  cont.insertBefore(menuRutas, cont.firstChild);
  
  // Si hay una ruta activa, mostrarla
  if (rutaActiva && rutas[rutaActiva]) {
    mostrarRuta(rutaActiva);
  } else if (Object.keys(rutas).length > 0) {
    // Mostrar la primera ruta por defecto
    const primeraRuta = Object.keys(rutas)[0];
    mostrarRuta(primeraRuta);
  }
}

function mostrarRuta(id) {
  // Ocultar todas las rutas
  document.querySelectorAll('.ruta-box').forEach(box => {
    box.style.display = 'none';
  });
  
  // Mostrar solo la ruta seleccionada
  const rutaSeleccionada = document.getElementById(`ruta-${id}`);
  if (rutaSeleccionada) {
    rutaSeleccionada.style.display = 'block';
    rutaActiva = id;
  }
  
  // Actualizar botones activos
  document.querySelectorAll('.btn-ruta').forEach(btn => {
    if (btn.dataset.id === id) {
      btn.classList.add('activo');
    } else {
      btn.classList.remove('activo');
    }
  });
}

// Delegación de eventos para botones dinámicos
$('#rutas-lista').addEventListener('click', async (e) => {
  const btnEliminarRuta = e.target.closest('.btn-eliminar-ruta');
  if (btnEliminarRuta) {
    await eliminarRuta(btnEliminarRuta.dataset.id);
    return;
  }
  
  const btnEliminarLugar = e.target.closest('.btn-eliminar-lugar');
  if (btnEliminarLugar) {
    await eliminarLugar(btnEliminarLugar.dataset.rutaId, btnEliminarLugar.dataset.lugar);
    return;
  }
  
  const btnAgregar = e.target.closest('.btn-agregar-lugar');
  if (btnAgregar) {
    const input = btnAgregar.parentElement.querySelector('.input-nuevo-lugar');
    if (input && input.value.trim()) {
      await agregarLugar(btnAgregar.dataset.id, input.value.trim());
    }
  }
});

async function agregarLugar(rutaId, lugar) {
  const fd = new FormData();
  fd.append('accion', 'agregar_lugar');
  fd.append('ruta_id', rutaId);
  fd.append('lugar', lugar);

  try {
    const res = await fetch('api/rutas.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error);
    rutasActuales = data.rutas;
    pintarRutas(data.rutas);
    if (cotizacionCargada) await calcularDirecciones();
  } catch (err) {
    alert('No se pudo agregar el lugar: ' + err.message);
  }
}

async function eliminarLugar(rutaId, lugar) {
  if (!confirm(`¿Eliminar "${lugar}" de esta ruta?`)) return;

  const fd = new FormData();
  fd.append('accion', 'eliminar_lugar');
  fd.append('ruta_id', rutaId);
  fd.append('lugar', lugar);

  try {
    const res = await fetch('api/rutas.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error);
    rutasActuales = data.rutas;
    pintarRutas(data.rutas);
    if (cotizacionCargada) await calcularDirecciones();
  } catch (err) {
    alert('No se pudo eliminar el lugar: ' + err.message);
  }
}

async function eliminarRuta(rutaId) {
  if (!confirm('¿Eliminar esta ruta? Esta acción no se puede deshacer.')) return;

  const fd = new FormData();
  fd.append('accion', 'eliminar_ruta');
  fd.append('ruta_id', rutaId);

  try {
    const res = await fetch('api/rutas.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error);
    rutasActuales = data.rutas;
    pintarRutas(data.rutas);
    if (rutaActiva === rutaId) {
      rutaActiva = null;
    }
    if (cotizacionCargada) await calcularDirecciones();
  } catch (err) {
    alert('No se pudo eliminar la ruta: ' + err.message);
  }
}

// Agregar nueva ruta
$('#btn-agregar-ruta').addEventListener('click', async () => {
  const nombre = prompt('Nombre de la nueva ruta:');
  if (!nombre) return;
  
  const grupo = prompt('Grupo de la ruta (ej: RUTA 3):') || 'SIN GRUPO';
  const id = 'ruta_' + Date.now();

  const fd = new FormData();
  fd.append('accion', 'crear_ruta');
  fd.append('ruta_id', id);
  fd.append('nombre', nombre);
  fd.append('grupo', grupo);

  try {
    const res = await fetch('api/rutas.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error);
    rutasActuales = data.rutas;
    pintarRutas(data.rutas);
    rutaActiva = id;
    mostrarRuta(id);
    if (cotizacionCargada) await calcularDirecciones();
  } catch (err) {
    alert('No se pudo crear la ruta: ' + err.message);
  }
});

$('#form-upload').addEventListener('submit', async (e) => {
  e.preventDefault();
  const archivo = $('#input-excel').files[0];
  if (!archivo) return;
  const fd = new FormData();
  fd.append('excel', archivo);
  $('#upload-status').innerHTML = 'Procesando…';

  try {
    const res = await fetch('api/procesar.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error);
    $('#upload-status').innerHTML = `<span class="msg-ok">✔ ${data.ciudades.length} ciudades encontradas en el archivo.</span>`;
    cotizacionCargada = true;
    // La ruta por dirección exacta se calcula y muestra automáticamente.
    await calcularDirecciones();
  } catch (err) {
    $('#upload-status').innerHTML = `<span class="msg-error">✖ ${err.message}</span>`;
  }
});

async function calcular() {
  const res = await fetch('api/calcular.php');
  const data = await res.json();
  if (!data.ok) {
    $('#resultado-card').style.display = 'none';
    return;
  }
  pintarSinAsignar(data.sin_asignar);
  pintarResultado(data.rutas);
}

function pintarSinAsignar(lista) {
  const card = $('#sin-asignar-card');
  if (!lista || lista.length === 0) {
    card.style.display = 'none';
    return;
  }
  card.style.display = 'block';
  $('#sin-asignar-lista').innerHTML = lista.map(c => 
    `<span class="ciudad-pendiente">${c.ciudad} (${c.provincia || 's/d'}) — ${fmt(c.peso_kg)} kg / ${fmtTon(c.peso_kg)} ton / ${fmtVol(c.volumen_m3)} m³</span>`
  ).join('');
}

function pintarResultado(rutas) {
  const card = $('#resultado-card');
  const cont = $('#resultado-lista');
  const ids = Object.keys(rutas);

  if (ids.length === 0) {
    card.style.display = 'none';
    return;
  }
  card.style.display = 'block';
  cont.innerHTML = '';

  // Crear menú de navegación para resultados (HORIZONTAL)
  const menuResultados = document.createElement('div');
  menuResultados.className = 'menu-resultados-horizontal';
  
  for (const id of ids) {
    const btnResultado = document.createElement('button');
    btnResultado.className = 'btn-resultado-horizontal';
    btnResultado.dataset.id = id;
    
    // Crear contenido del botón con nombre y métricas principales
    const ruta = rutas[id];
    btnResultado.innerHTML = `
      <div class="btn-ruta-nombre">${ruta.nombre}</div>
      <div class="btn-ruta-metricas">
        <span>📦 ${ruta.cajas_total}</span>
        <span>⚖️ ${fmtTon(ruta.peso_total_kg)} ton</span>
      </div>
    `;
    
    btnResultado.onclick = () => mostrarResultado(id);
    menuResultados.appendChild(btnResultado);
  }
  
  cont.appendChild(menuResultados);

  for (const id of ids) {
    const r = rutas[id];
    const cs = r.camion_sugerido;
    const rs = r.ruta_sugerida;
    
    // La columna de direcciones SOLO se muestra en la Ruta Interna Pichincha
    const esRutaPichincha = id === 'ruta_pichincha';

    // Generar filas de la tabla
    const filas = r.ciudades.map(c => {
      let direccionesHtml = '';
      
      if (c.es_quito && c.direcciones && typeof c.direcciones === 'object') {
        // Para Quito: mostrar cada dirección con sus cajas
        const direccionesArray = Object.values(c.direcciones);
        direccionesHtml = direccionesArray.map((datos) => `
          <div class="direccion-detalle">
            <div class="direccion-texto">📍 ${datos.direccion}</div>
            ${datos.cliente ? `<div class="direccion-cliente">${datos.cliente}</div>` : ''}
            <div class="direccion-cajas">
              <span class="badge-cajas">${datos.cajas} cajas</span>
              <span class="badge-peso">${fmt(datos.peso_kg)} kg</span>
            </div>
          </div>
        `).join('');
      } else {
        // Para otras ciudades: no mostrar direcciones
        direccionesHtml = '<span class="sin-direcciones">-</span>';
      }

      return `
      <tr>
        <td>
          <strong>${c.ciudad}</strong>
        </td>
        <td>${c.provincia || '-'}</td>
        ${esRutaPichincha && c.es_quito ? `
          <td class="celda-direcciones">${direccionesHtml}</td>
        ` : ''}
        <td class="celda-cajas">${c.cajas}</td>
        <td>${fmtVol(c.volumen_m3)}</td>
        <td>${fmt(c.peso_kg)}</td>
        <td>${fmtTon(c.peso_kg)}</td>
      </tr>
    `;
    }).join('');

    const tramosHtml = rs.tramos.map((t, i) => {
      const destino = t.cliente ? `${t.ciudad} — ${t.cliente}` : t.ciudad;
      const dir = t.direccion ? `<div class="tramo-dir">📌 ${t.direccion}</div>` : '';
      const aprox = (t.nivel && t.nivel !== 'direccion') ? ' <span class="badge-ciudad">aprox.</span>' : '';
      const comoLlegar = (t.lat && t.lon)
        ? `<a class="como-llegar" href="https://www.google.com/maps/dir/?api=1&destination=${t.lat},${t.lon}&travelmode=driving" target="_blank" rel="noopener">🧭 Cómo llegar</a>`
        : '';
      return `<li>
          <span class="tramo-num">${i + 1}</span>
          <span class="tramo-ruta"><strong>${destino}</strong>${aprox}${dir}${comoLlegar}</span>
          <span class="tramo-km">${t.km_tramo} km</span>
          <span class="km-acumulado">acum. ${t.km_acumulado} km</span>
        </li>`;
    }).join('');

    // Enlace a la ruta completa en Google Maps (hub → todas las paradas en orden).
    const puntosMaps = [];
    if (hubCoords) puntosMaps.push(`${hubCoords.lat},${hubCoords.lon}`);
    rs.tramos.forEach(t => { if (t.lat && t.lon) puntosMaps.push(`${t.lat},${t.lon}`); });
    const btnMaps = puntosMaps.length > 1
      ? `<a class="btn-maps" href="https://www.google.com/maps/dir/${puntosMaps.join('/')}" target="_blank" rel="noopener">🧭 Abrir ruta completa en Google Maps</a>`
      : '';

    // Precio del viaje = km total (solo ida) x multiplicador de la ruta.
    // Todas las rutas usan multiplicador 1 (precio = km); solo Oriente usa 1.25.
    const esOriente = id === "ruta1_oriente";
    const multiplicador = esOriente ? 1.25 : 1;
    const precioRuta = rs.km_total_ida * multiplicador;
    const etiquetaMult = esOriente ? ' (Oriente x 1.25)' : ' (x 1)';

    const infoKilometraje = `
      <div class="resumen-ruta">
        <div class="resumen-item">
          <span class="ri-label">🛣️ Recorrido (ida)</span>
          <span class="ri-valor">${rs.km_total_ida} km</span>
        </div>
        <div class="resumen-item resumen-precio">
          <span class="ri-label">💵 Costo estimado${etiquetaMult}</span>
          <span class="ri-valor">$${precioRuta.toFixed(2)}</span>
        </div>
      </div>
    `;

    // Determinar columnas de la tabla según el tipo de ruta
    const columnasAdicionales = esRutaPichincha ? '<th>Direcciones de Entrega</th>' : '';

    const bloque = document.createElement('div');
    bloque.className = 'ruta-resultado';
    bloque.id = `resultado-${id}`;
    bloque.style.display = 'none';
    bloque.innerHTML = `
      <div class="header">
        <div>
          <div class="grupo-tag">${r.grupo}</div>
          <h3>${r.nombre}</h3>
        </div>
      </div>
      <div class="metricas">
        <div class="metrica"><div class="label">Cajas totales</div><div class="valor">${r.cajas_total}</div></div>
        <div class="metrica"><div class="label">Peso total</div><div class="valor">${fmt(r.peso_total_kg)} kg</div></div>
        <div class="metrica"><div class="label">Peso total</div><div class="valor">${fmtVol(r.peso_total_ton)} ton</div></div>
        <div class="metrica"><div class="label">Volumen total</div><div class="valor">${fmtVol(r.volumen_total_m3)} m³</div></div>
      </div>
      <div class="camion-sugerido">
        🚚 Camión sugerido: <strong>${cs.camion.nombre}</strong> (capacidad ${fmt(cs.camion.capacidad_kg)} kg / ${cs.camion.capacidad_m3} m³)
        ${cs.nota ? `<div class="nota">${cs.nota}</div>` : ''}
      </div>
      <div class="ruta-sugerida">
        <div class="guia-header">
          <strong>🗺️ Guía de entrega (orden y km acumulado por dirección):</strong>
          ${r.optimizada ? '<span class="badge-opt">✨ orden óptimo</span>' : ''}
        </div>
        ${btnMaps}
        <ol class="tramos-lista guia">${tramosHtml || '<li>Sin direcciones para esta ruta</li>'}</ol>
        ${infoKilometraje}
      </div>
      <table class="detalle">
        <thead>
          <tr>
            <th>Ciudad / Sector</th>
            <th>Provincia</th>
            ${columnasAdicionales}
            <th>Cajas</th>
            <th>Volumen (m³)</th>
            <th>Peso (kg)</th>
            <th>Peso (ton)</th>
          </tr>
        </thead>
        <tbody>${filas}</tbody>
      </table>
    `;
    cont.appendChild(bloque);
  }
  
  // Mostrar la primera ruta por defecto
  if (ids.length > 0) {
    mostrarResultado(ids[0]);
  }
}

function mostrarResultado(id) {
  // Ocultar todos los resultados
  document.querySelectorAll('.ruta-resultado').forEach(box => {
    box.style.display = 'none';
  });
  
  // Mostrar solo el resultado seleccionado
  const resultadoSeleccionado = document.getElementById(`resultado-${id}`);
  if (resultadoSeleccionado) {
    resultadoSeleccionado.style.display = 'block';
  }
  
  // Actualizar botones activos
  document.querySelectorAll('.btn-resultado-horizontal').forEach(btn => {
    if (btn.dataset.id === id) {
      btn.classList.add('activo');
    } else {
      btn.classList.remove('activo');
    }
  });
}



function fmt(n) { return Number(n).toLocaleString('es-EC', { maximumFractionDigits: 1 }); }
function fmtVol(n) { return Number(n).toLocaleString('es-EC', { maximumFractionDigits: 3 }); }
function fmtTon(kgN) { return Number(kgN / 1000).toLocaleString('es-EC', { maximumFractionDigits: 3 }); }

// ==================== RESULTADO POR RUTA (automático, por dirección exacta) ====================
// Se ejecuta solo tras subir la cotización o al cambiar una ruta. Ubica cada
// dirección del Excel (OpenStreetMap) y calcula el recorrido real (OSRM).
async function calcularDirecciones() {
  const card = document.getElementById('resultado-card');
  const cont = document.getElementById('resultado-lista');
  const estado = document.getElementById('estado-calculo');
  const btnWord = document.getElementById('btn-descargar-word');
  if (!cont) return;
  if (card) card.style.display = 'block';
  if (estado) estado.innerHTML = '<p class="cargando">⏳ Calculando el recorrido real por carretera… (la primera vez puede tardar; luego es rápido)</p>';
  try {
    const res = await fetch('api/calcular_direcciones.php');
    const data = await res.json();
    if (!data.ok) throw new Error(data.error);
    hubCoords = data.hub || null;
    pintarSinAsignar(data.sin_asignar);
    pintarResultado(data.rutas);
    if (estado) estado.innerHTML = '';
    if (btnWord) btnWord.style.display = '';
  } catch (err) {
    if (estado) estado.innerHTML = '';
    cont.innerHTML = `<span class="msg-error">✖ ${err.message}</span>`;
    if (btnWord) btnWord.style.display = 'none';
  }
}

const btnDescargarWord = document.getElementById('btn-descargar-word');
if (btnDescargarWord) btnDescargarWord.addEventListener('click', () => { window.location = 'api/exportar_word.php'; });

function renderDirecciones(data) {
  const cont = document.getElementById('resultado-direcciones');
  const cob = data.cobertura || { total: 0, nivel_direccion: 0, nivel_ciudad: 0 };
  const ids = Object.keys(data.rutas || {});

  let html = `<div class="cobertura">
      📍 Direcciones ubicadas con precisión exacta: <strong>${cob.nivel_direccion}/${cob.total}</strong>
      ${cob.nivel_ciudad ? ` · ${cob.nivel_ciudad} cayeron al centro de la ciudad` : ''}
    </div>`;

  if (ids.length === 0) {
    cont.innerHTML = html + '<p class="ayuda">No hay rutas con direcciones para calcular.</p>';
    return;
  }

  for (const id of ids) {
    const r = data.rutas[id];
    const cs = r.camion_sugerido;
    const tramos = r.tramos.map((t) => {
      const etiqueta = t.cliente ? `${t.ciudad} — ${t.cliente}` : t.ciudad;
      const dir = t.direccion ? `<div class="tramo-dir">📌 ${t.direccion}</div>` : '';
      const badge = t.nivel === 'direccion'
        ? '<span class="badge-dir">dirección exacta</span>'
        : '<span class="badge-ciudad">ciudad (aprox.)</span>';
      return `<li>
          <div class="tramo-top">${etiqueta} — <strong>${t.km_tramo} km</strong>
            <span class="km-acumulado">(acum: ${t.km_acumulado} km)</span> ${badge}</div>
          ${dir}
        </li>`;
    }).join('');

    const multTxt = r.multiplicador !== 1 ? ` (x${r.multiplicador})` : '';

    html += `<div class="ruta-resultado-dir">
        <div class="grupo-tag">${r.grupo}</div>
        <h3>${r.nombre}</h3>
        <div class="camion-sugerido">🚚 Camión sugerido: <strong>${cs.camion.nombre}</strong>
          (${fmt(cs.camion.capacidad_kg)} kg / ${cs.camion.capacidad_m3} m³) —
          carga: ${fmt(r.peso_total_kg)} kg / ${fmtVol(r.volumen_total_m3)} m³ / ${r.cajas_total} cajas</div>
        <ol class="tramos-lista">${tramos || '<li>Sin direcciones</li>'}</ol>
        <p class="km-total">Recorrido total (ida): ${r.km_total_ida} km</p>
        <p class="km-total">Costo estimado de la ruta${multTxt}: $${r.precio.toFixed(2)}</p>
        ${r.aproximado ? '<div class="aviso-aprox">⚠️ Algún tramo se estimó (sin servicio de ruteo).</div>' : ''}
      </div>`;
  }

  cont.innerHTML = html;
}

cargarRutas();