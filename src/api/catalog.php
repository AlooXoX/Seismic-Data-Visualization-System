<?php
require __DIR__ . '/db.php';

// Consultas de ejemplo listas para ejecutar desde la página.
$ejemplos = [
    [
        'titulo' => 'Resumen general (KPIs)',
        'sql' => "SELECT COUNT(*) AS registros,\n       SUM(poblacion_afectada) AS poblacion_afectada_total,\n       ROUND(AVG(indice_zscore)::numeric, 3) AS zscore_promedio,\n       ROUND(SUM(impacto_economico)::numeric, 2) AS impacto_economico_total\nFROM fact_impacto_sismos_imputed",
    ],
    [
        'titulo' => 'Top 10 estados por población afectada',
        'sql' => "SELECT z.nom_ent AS estado,\n       SUM(f.poblacion_afectada) AS poblacion_afectada,\n       COUNT(*) AS eventos\nFROM fact_impacto_sismos_imputed f\nJOIN dim_zonas z ON z.id_zonas = f.id_zonas\nGROUP BY z.nom_ent\nORDER BY poblacion_afectada DESC\nLIMIT 10",
    ],
    [
        'titulo' => 'Sismos y magnitud promedio por año',
        'sql' => "SELECT t.anio,\n       COUNT(*) AS sismos,\n       ROUND(AVG(s.magnitud)::numeric, 2) AS magnitud_promedio,\n       MAX(s.magnitud) AS magnitud_maxima\nFROM fact_impacto_sismos_imputed f\nJOIN dim_tiempo t ON t.id_tiempo = f.id_tiempo\nJOIN dim_sismos s ON s.id_sismo = f.id_sismo\nGROUP BY t.anio\nORDER BY t.anio",
    ],
    [
        'titulo' => 'Impacto económico vs. producción bruta por estado',
        'sql' => "SELECT z.nom_ent AS estado,\n       ROUND(SUM(f.impacto_economico)::numeric, 2) AS impacto_economico,\n       e.produccion_bruta_total,\n       e.valor_agregado\nFROM fact_impacto_sismos_imputed f\nJOIN dim_zonas z ON z.id_zonas = f.id_zonas\nJOIN dim_economia e ON e.id_economia = f.id_economia\nGROUP BY z.nom_ent, e.produccion_bruta_total, e.valor_agregado\nORDER BY impacto_economico DESC\nLIMIT 15",
    ],
    [
        'titulo' => 'Eventos de mayor riesgo (Z-score)',
        'sql' => "SELECT t.fecha, s.magnitud, s.profundidad, z.nom_ent AS estado,\n       f.poblacion_afectada, f.indice_zscore\nFROM fact_impacto_sismos_imputed f\nJOIN dim_sismos s ON s.id_sismo = f.id_sismo\nJOIN dim_tiempo t ON t.id_tiempo = f.id_tiempo\nJOIN dim_zonas z ON z.id_zonas = f.id_zonas\nORDER BY f.indice_zscore DESC\nLIMIT 20",
    ],
    [
        'titulo' => 'Población afectada por trimestre',
        'sql' => "SELECT t.anio, t.trimestre, SUM(f.poblacion_afectada) AS poblacion_afectada\nFROM fact_impacto_sismos_imputed f\nJOIN dim_tiempo t ON t.id_tiempo = f.id_tiempo\nGROUP BY t.anio, t.trimestre\nORDER BY t.anio, t.trimestre",
    ],
    [
        'titulo' => 'Sismos más profundos',
        'sql' => "SELECT id_sismo, magnitud, profundidad, referencia_de_localizacion, nombre_estado\nFROM dim_sismos\nORDER BY profundidad DESC\nLIMIT 20",
    ],
    [
        'titulo' => 'Distribución por género de la población (dim_zonas)',
        'sql' => "SELECT nom_ent AS estado, pobtot, pobfem, pobmas,\n       ROUND(100.0 * pobfem / pobtot, 2) AS pct_mujeres\nFROM dim_zonas\nORDER BY pobtot DESC",
    ],
];

try {
    $pdo = pdo_ro();
    $rows = $pdo->query(
        "SELECT table_name, column_name, data_type
         FROM information_schema.columns
         WHERE table_schema = 'public'
         ORDER BY table_name, ordinal_position"
    )->fetchAll();

    $tablas = [];
    foreach ($rows as $r) {
        $tablas[$r['table_name']][] = ['columna' => $r['column_name'], 'tipo' => $r['data_type']];
    }
    json_out(['tablas' => $tablas, 'ejemplos' => $ejemplos]);
} catch (Throwable $e) {
    json_out(['error' => 'No se pudo leer el catálogo'], 503);
}
