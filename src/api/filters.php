<?php
require __DIR__ . '/db.php';

// Valores disponibles para los filtros del explorador.
try {
    $pdo = pdo_ro();

    $anios = $pdo->query('SELECT MIN(anio) AS mn, MAX(anio) AS mx FROM dim_tiempo')->fetch();
    $mags  = $pdo->query('SELECT MIN(magnitud) AS mn, MAX(magnitud) AS mx FROM dim_sismos')->fetch();
    $zonas = $pdo->query('SELECT id_zonas AS id, nom_ent AS nombre FROM dim_zonas ORDER BY nom_ent')->fetchAll();

    json_out([
        'anio_min' => (int)$anios['mn'],
        'anio_max' => (int)$anios['mx'],
        'mag_min'  => (float)$mags['mn'],
        'mag_max'  => (float)$mags['mx'],
        'zonas'    => array_map(fn($z) => ['id' => (int)$z['id'], 'nombre' => $z['nombre']], $zonas),
    ]);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_out(['error' => 'No se pudieron leer los filtros'], 503);
}
