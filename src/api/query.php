<?php
require __DIR__ . '/db.php';

const MAX_FILAS = 500;
const MAX_LARGO = 5000;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'Usa POST con {"sql": "SELECT ..."}'], 405);
}

$body = json_decode(file_get_contents('php://input'), true);
$sql  = is_array($body) ? trim((string)($body['sql'] ?? '')) : '';

if ($sql === '') {
    json_out(['error' => 'Escribe una consulta.'], 400);
}
if (strlen($sql) > MAX_LARGO) {
    json_out(['error' => 'La consulta es demasiado larga.'], 400);
}

// Quitar ; final y rechazar múltiples sentencias.
$sql = rtrim($sql, " \t\n\r;");
if (strpos($sql, ';') !== false) {
    json_out(['error' => 'Solo se permite una sentencia por consulta.'], 400);
}
// Solo lectura: debe iniciar con SELECT o WITH.
if (!preg_match('/^\s*(select|with)\b/i', $sql)) {
    json_out(['error' => 'Solo se permiten consultas SELECT.'], 400);
}

try {
    $pdo = pdo_ro();
    // Defensa en profundidad: el rol ya es de solo lectura, además la transacción lo es.
    $pdo->exec('BEGIN READ ONLY');
    $pdo->exec("SET LOCAL statement_timeout = '5s'");

    $t0   = microtime(true);
    $stmt = $pdo->query('SELECT * FROM (' . $sql . ') AS q LIMIT ' . (MAX_FILAS + 1));
    $rows = $stmt->fetchAll();
    $ms   = round((microtime(true) - $t0) * 1000, 1);
    $pdo->exec('ROLLBACK');

    $truncado = count($rows) > MAX_FILAS;
    if ($truncado) {
        array_pop($rows);
    }
    $columnas = $rows ? array_keys($rows[0]) : [];
    if (!$columnas) {
        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $columnas[] = $stmt->getColumnMeta($i)['name'] ?? "col$i";
        }
    }

    json_out([
        'columnas' => $columnas,
        'filas'    => array_map('array_values', $rows),
        'total'    => count($rows),
        'truncado' => $truncado,
        'ms'       => $ms,
    ]);
} catch (Throwable $e) {
    // Mostrar el mensaje de Postgres (útil para corregir la consulta), sin rutas internas.
    $msg = $e instanceof PDOException && isset($e->errorInfo[2]) ? $e->errorInfo[2] : 'Error al ejecutar la consulta.';
    json_out(['error' => $msg], 400);
}
