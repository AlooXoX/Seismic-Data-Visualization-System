<?php
require __DIR__ . '/db.php';

// Explorador sin SQL: recibe filtros por GET, ejecuta consultas parametrizadas
// y devuelve KPIs, series para gráficas, puntos del mapa y una tabla de eventos.
// También devuelve el SQL equivalente para mostrárselo al usuario.

const MAX_MAPA  = 1500;
const MAX_TABLA = 100;

function num_or_null(string $k, bool $int): int|float|null {
    $v = $_GET[$k] ?? '';
    if ($v === '' || !is_numeric($v)) return null;
    return $int ? (int)$v : round((float)$v, 2);
}

$anioDesde = num_or_null('anio_desde', true);
$anioHasta = num_or_null('anio_hasta', true);
$zona      = num_or_null('zona', true);
$magMin    = num_or_null('mag_min', false);

$ordenes = [
    'magnitud'  => ['s.magnitud DESC NULLS LAST',            'magnitud (mayor primero)'],
    'fecha'     => ['t.fecha DESC NULLS LAST',               'fecha (más reciente primero)'],
    'zscore'    => ['f.indice_zscore DESC NULLS LAST',       'Z-score (mayor riesgo primero)'],
    'poblacion' => ['f.poblacion_afectada DESC NULLS LAST',  'población afectada (mayor primero)'],
];
$ordenKey = $_GET['orden'] ?? 'magnitud';
if (!isset($ordenes[$ordenKey])) $ordenKey = 'magnitud';
[$orderSql] = $ordenes[$ordenKey];

$cond = []; $par = []; $disp = [];
if ($anioDesde !== null) { $cond[] = 't.anio >= ?';     $par[] = $anioDesde; $disp[] = "t.anio >= $anioDesde"; }
if ($anioHasta !== null) { $cond[] = 't.anio <= ?';     $par[] = $anioHasta; $disp[] = "t.anio <= $anioHasta"; }
if ($zona !== null)      { $cond[] = 'z.id_zonas = ?';  $par[] = $zona;      $disp[] = "z.id_zonas = $zona"; }
if ($magMin !== null)    { $cond[] = 's.magnitud >= ?'; $par[] = $magMin;    $disp[] = "s.magnitud >= $magMin"; }

$where     = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';
$whereMapa = 'WHERE ' . implode(' AND ', array_merge($cond, ['s.latitud IS NOT NULL', 's.longitud IS NOT NULL']));

$from = 'FROM fact_impacto_sismos_imputed f
JOIN dim_sismos s ON s.id_sismo = f.id_sismo
JOIN dim_tiempo t ON t.id_tiempo = f.id_tiempo
JOIN dim_zonas  z ON z.id_zonas  = f.id_zonas';

function q(PDO $pdo, string $sql, array $par): array {
    $st = $pdo->prepare($sql);
    $st->execute($par);
    return $st->fetchAll();
}
$f = fn($v) => $v === null ? null : (float)$v;

try {
    $pdo = pdo_ro();

    $k = q($pdo, "SELECT COUNT(*) AS eventos, MAX(s.magnitud) AS mag_max, AVG(s.magnitud) AS mag_prom,
                         COALESCE(SUM(f.poblacion_afectada), 0) AS pob,
                         COALESCE(SUM(f.impacto_economico), 0) AS eco,
                         AVG(f.indice_zscore) AS z
                  $from $where", $par)[0];

    $porAnio = array_map(fn($r) => [
        'anio' => (int)$r['anio'], 'sismos' => (int)$r['sismos'], 'mag_prom' => $f($r['mag_prom']),
    ], q($pdo, "SELECT t.anio, COUNT(*) AS sismos, AVG(s.magnitud) AS mag_prom
                $from $where GROUP BY t.anio ORDER BY t.anio", $par));

    $porEstado = array_map(fn($r) => [
        'estado' => $r['estado'], 'pob' => $f($r['pob']), 'eventos' => (int)$r['eventos'],
    ], q($pdo, "SELECT z.nom_ent AS estado, SUM(f.poblacion_afectada) AS pob, COUNT(*) AS eventos
                $from $where GROUP BY z.nom_ent ORDER BY pob DESC NULLS LAST LIMIT 10", $par));

    $mapa = array_map(fn($r) => [
        'lat' => (float)$r['lat'], 'lon' => (float)$r['lon'], 'magnitud' => $f($r['magnitud']),
        'profundidad' => $f($r['profundidad']), 'lugar' => $r['lugar'], 'estado' => $r['estado'],
        'fecha' => $r['fecha'], 'z' => $f($r['z']),
    ], q($pdo, "SELECT s.latitud AS lat, s.longitud AS lon, s.magnitud, s.profundidad,
                       s.referencia_de_localizacion AS lugar, z.nom_ent AS estado, t.fecha, f.indice_zscore AS z
                $from $whereMapa ORDER BY s.magnitud DESC NULLS LAST LIMIT " . MAX_MAPA, $par));

    $tabla = q($pdo, "SELECT t.fecha, s.magnitud, s.profundidad, s.referencia_de_localizacion AS lugar,
                             z.nom_ent AS estado, f.poblacion_afectada, f.impacto_economico, f.indice_zscore AS zscore
                      $from $where ORDER BY $orderSql LIMIT " . MAX_TABLA, $par);

    // SQL equivalente (solo informativo; los valores ya fueron validados como números).
    $sqlVisible = "SELECT t.fecha, s.magnitud, s.profundidad,\n"
        . "       s.referencia_de_localizacion AS lugar, z.nom_ent AS estado,\n"
        . "       f.poblacion_afectada, f.impacto_economico, f.indice_zscore AS zscore\n"
        . "FROM fact_impacto_sismos_imputed f\n"
        . "JOIN dim_sismos s ON s.id_sismo = f.id_sismo\n"
        . "JOIN dim_tiempo t ON t.id_tiempo = f.id_tiempo\n"
        . "JOIN dim_zonas  z ON z.id_zonas  = f.id_zonas\n"
        . ($disp ? 'WHERE ' . implode("\n  AND ", $disp) . "\n" : '')
        . "ORDER BY $orderSql\nLIMIT " . MAX_TABLA;

    json_out([
        'kpis' => [
            'eventos' => (int)$k['eventos'], 'mag_max' => $f($k['mag_max']), 'mag_prom' => $f($k['mag_prom']),
            'pob' => (float)$k['pob'], 'eco' => (float)$k['eco'], 'z' => $f($k['z']),
        ],
        'por_anio'   => $porAnio,
        'por_estado' => $porEstado,
        'mapa'       => $mapa,
        'mapa_limite' => MAX_MAPA,
        'tabla' => [
            'columnas' => ['fecha', 'magnitud', 'profundidad', 'lugar', 'estado', 'poblacion_afectada', 'impacto_economico', 'zscore'],
            'filas'    => array_map('array_values', $tabla),
            'limite'   => MAX_TABLA,
        ],
        'sql' => $sqlVisible,
    ]);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_out(['error' => 'No se pudo consultar el Data Warehouse.'], 500);
}
