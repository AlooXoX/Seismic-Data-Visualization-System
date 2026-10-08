<?php
// Conexión de SOLO LECTURA al Data Warehouse + utilidades JSON.

function env_or(string $k, string $d): string {
    $v = getenv($k);
    return ($v !== false && $v !== '') ? $v : $d;
}

function pdo_ro(): PDO {
    $host = env_or('POSTGRES_HOST', 'db');
    $port = env_or('POSTGRES_PORT', '5432');
    $db   = env_or('POSTGRES_DB', 'datawarehouse');
    $user = env_or('POSTGRES_RO_USER', 'sismos_ro');
    $pass = env_or('POSTGRES_RO_PASSWORD', 'sismos_ro');
    return new PDO("pgsql:host=$host;port=$port;dbname=$db", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 3,
    ]);
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}
