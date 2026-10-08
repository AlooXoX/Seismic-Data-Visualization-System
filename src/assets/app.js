(() => {
'use strict';

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const nf  = new Intl.NumberFormat('es-MX', {maximumFractionDigits: 2});
const nfc = new Intl.NumberFormat('es-MX', {notation: 'compact', compactDisplay: 'long', maximumFractionDigits: 1});

// Los DOUBLE de Postgres llegan como texto desde PHP; los mostramos formateados.
const fmtVal = (v) => {
    if (v === null || v === undefined) return '<span class="text-gray-600">NULL</span>';
    if (typeof v === 'number') return nf.format(v);
    if (/^-?\d+\.\d+$/.test(v)) return nf.format(Number(v));
    return esc(v);
};
const asNum = (v) => (v === null || v === undefined || v === '' || isNaN(Number(v))) ? null : Number(v);

/* ------------------------------------------------------------------ */
/* Tabla con orden por columna                                         */
/* ------------------------------------------------------------------ */
function tabla(el, cols, rows) {
    let data = rows.slice(), sortCol = -1, asc = true;

    const cmp = (a, b) => {
        const x = a[sortCol], y = b[sortCol];
        if (x === null && y === null) return 0;
        if (x === null) return 1;
        if (y === null) return -1;
        const nx = asNum(x), ny = asNum(y);
        const r = (nx !== null && ny !== null) ? nx - ny : String(x).localeCompare(String(y), 'es');
        return asc ? r : -r;
    };

    const draw = () => {
        const head = '<thead class="sticky top-0 bg-brand-dark"><tr>' + cols.map((c, i) =>
            `<th data-i="${i}" title="Clic para ordenar" class="px-4 py-2 font-semibold text-brand-orange whitespace-nowrap cursor-pointer select-none hover:text-white">${esc(c)}${sortCol === i ? (asc ? ' ▲' : ' ▼') : ''}</th>`
        ).join('') + '</tr></thead>';
        const body = data.length
            ? '<tbody>' + data.map(f => '<tr class="border-t border-white/5 hover:bg-white/5">' +
                f.map(v => `<td class="px-4 py-2 whitespace-nowrap">${fmtVal(v)}</td>`).join('') + '</tr>').join('') + '</tbody>'
            : '<tbody><tr><td class="p-6 text-gray-500">Sin resultados con estos filtros.</td></tr></tbody>';
        el.innerHTML = head + body;
        el.querySelectorAll('th').forEach(th => th.onclick = () => {
            const i = Number(th.dataset.i);
            asc = (sortCol === i) ? !asc : true;
            sortCol = i;
            data.sort(cmp);
            draw();
        });
    };
    draw();
    return { rows: () => data };
}

function descargarCSV(cols, rows, nombre) {
    const q = (v) => {
        if (v === null || v === undefined) return '';
        const s = String(v);
        return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
    };
    const csv = '﻿' + [cols.map(q).join(','), ...rows.map(r => r.map(q).join(','))].join('\r\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], {type: 'text/csv;charset=utf-8'}));
    a.download = nombre;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

const hoy = () => new Date().toISOString().slice(0, 10);

/* ------------------------------------------------------------------ */
/* Explorador (sin SQL)                                                */
/* ------------------------------------------------------------------ */
let meta = null, chartAnio = null, chartEstado = null, mapa = null, capaMapa = null;
let tablaEventos = null, ultimaTablaCols = [], token = 0;

const campos = {
    desde: $('f-desde'), hasta: $('f-hasta'), zona: $('f-zona'), mag: $('f-mag'), orden: $('f-orden'),
};

function opcionesAnios(sel, min, max, valor) {
    sel.innerHTML = '';
    for (let a = min; a <= max; a++) sel.add(new Option(a, a));
    sel.value = valor;
}

async function cargarFiltros() {
    try {
        const r = await fetch('/api/filters.php');
        meta = await r.json();
        if (!r.ok || meta.error) throw new Error(meta.error);

        opcionesAnios(campos.desde, meta.anio_min, meta.anio_max, meta.anio_min);
        opcionesAnios(campos.hasta, meta.anio_min, meta.anio_max, meta.anio_max);
        campos.zona.innerHTML = '<option value="">Todos los estados</option>' +
            meta.zonas.map(z => `<option value="${z.id}">${esc(z.nombre)}</option>`).join('');
        campos.mag.min = Math.floor(meta.mag_min * 10) / 10;
        campos.mag.max = Math.ceil(meta.mag_max * 10) / 10;
        campos.mag.step = 0.1;
        campos.mag.value = campos.mag.min;
        $('f-mag-v').textContent = Number(campos.mag.value).toFixed(1);
        return true;
    } catch {
        $('explorador-error').textContent = 'No se pudo conectar con el Data Warehouse. Revisa que la base esté lista y recarga.';
        $('explorador-error').classList.remove('hidden');
        return false;
    }
}

function filtrosQS() {
    const p = new URLSearchParams({
        anio_desde: campos.desde.value, anio_hasta: campos.hasta.value,
        zona: campos.zona.value, mag_min: campos.mag.value, orden: campos.orden.value,
    });
    return p.toString();
}

function pintarKpis(k) {
    $('kpi-eventos').textContent = nf.format(k.eventos);
    $('kpi-magmax').textContent  = k.mag_max === null ? '—' : nf.format(k.mag_max);
    $('kpi-magprom').textContent = k.mag_prom === null ? '—' : nf.format(k.mag_prom);
    $('kpi-pob').textContent     = nfc.format(k.pob);
    $('kpi-eco').textContent     = nfc.format(k.eco);
    $('kpi-z').textContent       = k.z === null ? '—' : nf.format(k.z);
}

function pintarGraficas(d) {
    Chart.defaults.color = '#94a3b8';
    Chart.defaults.borderColor = 'rgba(255,255,255,0.06)';
    Chart.defaults.font.family = 'Inter, sans-serif';

    if (chartAnio) chartAnio.destroy();
    chartAnio = new Chart($('chart-anio'), {
        data: {
            labels: d.por_anio.map(r => r.anio),
            datasets: [
                { type: 'bar', label: 'Sismos', data: d.por_anio.map(r => r.sismos),
                  backgroundColor: 'rgba(249,115,22,0.75)', borderRadius: 3, yAxisID: 'y' },
                { type: 'line', label: 'Magnitud promedio', data: d.por_anio.map(r => r.mag_prom),
                  borderColor: '#ef4444', backgroundColor: '#ef4444', pointRadius: 0, borderWidth: 2, tension: 0.3, yAxisID: 'y1' },
            ],
        },
        options: {
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x:  { ticks: { maxTicksLimit: 12 }, grid: { display: false } },
                y:  { beginAtZero: true, title: { display: true, text: 'Sismos' } },
                y1: { position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Magnitud' } },
            },
            plugins: { legend: { position: 'bottom' } },
        },
    });

    if (chartEstado) chartEstado.destroy();
    chartEstado = new Chart($('chart-estado'), {
        type: 'bar',
        data: {
            labels: d.por_estado.map(r => r.estado),
            datasets: [{ label: 'Población afectada', data: d.por_estado.map(r => r.pob),
                         backgroundColor: 'rgba(239,68,68,0.75)', borderRadius: 3 }],
        },
        options: {
            indexAxis: 'y', maintainAspectRatio: false,
            scales: { x: { ticks: { callback: v => nfc.format(v) } }, y: { grid: { display: false } } },
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: c => ` ${nf.format(c.parsed.x)} personas · ${d.por_estado[c.dataIndex].eventos} eventos` } },
            },
        },
    });
}

function colorMag(m) {
    const lo = meta.mag_min, hi = meta.mag_max;
    const t = hi > lo ? Math.min(1, Math.max(0, (m - lo) / (hi - lo))) : 0.5;
    const a = [250, 204, 21], b = [239, 68, 68];
    return `rgb(${a.map((v, i) => Math.round(v + (b[i] - v) * t)).join(',')})`;
}

function pintarMapa(pts, total, limite) {
    if (!mapa) {
        mapa = L.map('mapa', { scrollWheelZoom: false }).setView([23.6, -102.5], 5);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; OpenStreetMap &copy; CARTO', maxZoom: 12,
        }).addTo(mapa);
        capaMapa = L.layerGroup().addTo(mapa);
    }
    capaMapa.clearLayers();
    const hi = meta.mag_max, lo = meta.mag_min;
    pts.forEach(p => {
        const m = p.magnitud ?? lo, t = hi > lo ? (m - lo) / (hi - lo) : 0.5, c = colorMag(m);
        L.circleMarker([p.lat, p.lon], { radius: 3 + 9 * t, color: c, weight: 1, fillColor: c, fillOpacity: 0.55 })
            .bindPopup(
                `<b>Magnitud ${p.magnitud === null ? '—' : nf.format(p.magnitud)}</b><br>` +
                `${esc(p.fecha)}<br>${esc(p.lugar || '')}<br>` +
                `Estado: ${esc(p.estado)}<br>Profundidad: ${p.profundidad === null ? '—' : nf.format(p.profundidad)} km<br>` +
                `Z-score: ${p.z === null ? '—' : nf.format(p.z)}`)
            .addTo(capaMapa);
    });
    $('mapa-nota').textContent = total > limite
        ? `Mostrando los ${nf.format(pts.length)} sismos de mayor magnitud de ${nf.format(total)}. Afina los filtros para ver el resto.`
        : `${nf.format(pts.length)} epicentros. Haz clic en un círculo para ver el detalle.`;
    setTimeout(() => mapa.invalidateSize(), 50);
}

async function buscar() {
    const mi = ++token;
    $('explorador-error').classList.add('hidden');
    $('explorador-estado').textContent = 'Consultando...';
    try {
        const r = await fetch('/api/stats.php?' + filtrosQS());
        const d = await r.json();
        if (mi !== token) return;               // llegó una respuesta vieja
        if (!r.ok || d.error) throw new Error(d.error || 'Error desconocido');

        pintarKpis(d.kpis);
        pintarGraficas(d);
        pintarMapa(d.mapa, d.kpis.eventos, d.mapa_limite);

        ultimaTablaCols = d.tabla.columnas;
        tablaEventos = tabla($('tabla-eventos'), d.tabla.columnas, d.tabla.filas);
        $('tabla-nota').textContent = d.kpis.eventos > d.tabla.limite
            ? `Primeros ${d.tabla.limite} de ${nf.format(d.kpis.eventos)} eventos (clic en un encabezado para ordenar).`
            : `${nf.format(d.kpis.eventos)} eventos (clic en un encabezado para ordenar).`;

        $('sql-generado').textContent = d.sql;
        $('explorador-estado').textContent = '';
    } catch (e) {
        if (mi !== token) return;
        $('explorador-estado').textContent = '';
        $('explorador-error').textContent = e.message;
        $('explorador-error').classList.remove('hidden');
    }
}

let debounce = null;
const buscarPronto = () => { clearTimeout(debounce); debounce = setTimeout(buscar, 250); };

Object.values(campos).forEach(c => c.addEventListener('change', () => {
    // Mantener el rango de años coherente.
    if (Number(campos.desde.value) > Number(campos.hasta.value)) {
        if (c === campos.desde) campos.hasta.value = campos.desde.value;
        else campos.desde.value = campos.hasta.value;
    }
    buscarPronto();
}));
campos.mag.addEventListener('input', () => { $('f-mag-v').textContent = Number(campos.mag.value).toFixed(1); });

$('f-reset').onclick = () => {
    if (!meta) return;
    campos.desde.value = meta.anio_min;
    campos.hasta.value = meta.anio_max;
    campos.zona.value = '';
    campos.mag.value = campos.mag.min;
    campos.orden.value = 'magnitud';
    $('f-mag-v').textContent = Number(campos.mag.value).toFixed(1);
    buscar();
};

$('csv-eventos').onclick = () => {
    if (tablaEventos) descargarCSV(ultimaTablaCols, tablaEventos.rows(), `sismos_${hoy()}.csv`);
};

/* ------------------------------------------------------------------ */
/* Consola SQL (modo avanzado)                                         */
/* ------------------------------------------------------------------ */
const input = $('sql-input');
const HIST_KEY = 'sismos_historial';
let ultimaConsola = null, tablaConsola = null;

const leerHist = () => { try { return JSON.parse(localStorage.getItem(HIST_KEY)) || []; } catch { return []; } };
function guardarHist(sql) {
    try {
        const h = leerHist().filter(x => x !== sql);
        h.unshift(sql);
        localStorage.setItem(HIST_KEY, JSON.stringify(h.slice(0, 20)));
    } catch {}
    pintarHist();
}
function pintarHist() {
    const h = leerHist();
    $('historial').innerHTML = h.length ? '' : '<span class="text-gray-500">Aún no has ejecutado consultas.</span>';
    h.forEach(sql => {
        const b = document.createElement('button');
        b.className = 'text-left font-mono text-xs text-gray-300 hover:text-white bg-white/5 hover:bg-white/10 rounded-lg p-2 truncate';
        b.title = sql;
        b.textContent = sql.replace(/\s+/g, ' ');
        b.onclick = () => { input.value = sql; };
        $('historial').appendChild(b);
    });
}
$('limpiar-historial').onclick = () => { try { localStorage.removeItem(HIST_KEY); } catch {} pintarHist(); };

async function ejecutar() {
    const sql = input.value.trim();
    if (!sql) return;
    $('error-consulta').classList.add('hidden');
    $('estado-consulta').textContent = 'Ejecutando...';
    try {
        const r = await fetch('/api/query.php', {
            method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({sql}),
        });
        const d = await r.json();
        if (!r.ok || d.error) throw new Error(d.error || 'Error desconocido');

        ultimaConsola = d;
        tablaConsola = tabla($('resultados'), d.columnas, d.filas);
        $('csv-consola').classList.toggle('hidden', d.filas.length === 0);
        $('estado-consulta').textContent = `${d.total} filas${d.truncado ? ' (truncado a 500)' : ''} · ${d.ms} ms`;
        guardarHist(sql);
    } catch (e) {
        $('estado-consulta').textContent = '';
        $('error-consulta').textContent = e.message;
        $('error-consulta').classList.remove('hidden');
    }
}
$('ejecutar').onclick = ejecutar;
input.addEventListener('keydown', e => { if (e.ctrlKey && e.key === 'Enter') ejecutar(); });
$('csv-consola').onclick = () => {
    if (ultimaConsola) descargarCSV(ultimaConsola.columnas, tablaConsola.rows(), `consulta_${hoy()}.csv`);
};

// "Abrir en consola" desde el explorador: pasa el SQL generado y baja a la consola.
$('abrir-en-consola').onclick = () => {
    input.value = $('sql-generado').textContent;
    $('consultas').scrollIntoView({behavior: 'smooth'});
};

function insertarEnEditor(txt) {
    const s = input.selectionStart ?? input.value.length, e = input.selectionEnd ?? input.value.length;
    input.setRangeText(txt, s, e, 'end');
    input.focus();
}

async function cargarCatalogo() {
    try {
        const r = await fetch('/api/catalog.php');
        const d = await r.json();
        if (!r.ok || d.error) throw new Error();

        $('ejemplos').innerHTML = '';
        d.ejemplos.forEach(ej => {
            const b = document.createElement('button');
            b.className = 'text-left text-gray-300 hover:text-white bg-white/5 hover:bg-white/10 rounded-lg px-3 py-2';
            b.textContent = ej.titulo;
            b.onclick = () => { input.value = ej.sql; ejecutar(); };
            $('ejemplos').appendChild(b);
        });

        $('esquema').innerHTML = Object.entries(d.tablas).map(([t, cols]) =>
            `<details><summary class="cursor-pointer text-white flex items-center gap-2">${esc(t)}` +
            `<button type="button" data-ins="${esc(t)}" class="text-xs text-brand-orange hover:underline">insertar</button></summary>` +
            `<ul class="ml-4 text-xs text-gray-400">${cols.map(c =>
                `<li><button type="button" data-ins="${esc(c.columna)}" class="hover:text-white">${esc(c.columna)}</button> <span class="text-gray-600">${esc(c.tipo)}</span></li>`
            ).join('')}</ul></details>`
        ).join('');
        $('esquema').onclick = (ev) => {
            const b = ev.target.closest('[data-ins]');
            if (!b) return;
            ev.preventDefault();             // no abrir/cerrar el <details> al insertar
            insertarEnEditor(b.dataset.ins);
        };
        if (!input.value) input.value = d.ejemplos[0].sql;
    } catch {
        $('esquema').textContent = 'No se pudo conectar con la base de datos.';
    }
}

/* ------------------------------------------------------------------ */
pintarHist();
cargarCatalogo();
cargarFiltros().then(ok => { if (ok) buscar(); });
})();
