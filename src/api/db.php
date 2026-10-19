<?php
// Conexión de SOLO LECTURA al Data Warehouse + utilidades JSON.

function env_or(string $k, string $d): string {
    $v = getenv($k);
    return ($v !== false && $v !== '') ? $v : $d;
}

// CORS: permite que la landing (GitHub Pages) consulte el contenedor que corre en tu computadora.
// Solo se aceptan estos orígenes; se pueden agregar más con CORS_ORIGINS="https://otro.com,http://x:8080".
(function () {
    $permitidos = array_merge(
        [
            'https://alooxox.github.io',
            'http://localhost', 'http://127.0.0.1',
            'http://localhost:8080', 'http://127.0.0.1:8080',
            'http://localhost:5500', 'http://127.0.0.1:5500',   // Live Server de VS Code
            'http://localhost:5501', 'http://127.0.0.1:5501',
        ],
        array_filter(array_map('trim', explode(',', env_or('CORS_ORIGINS', ''))))
    );
    $origen = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origen !== '' && in_array($origen, $permitidos, true)) {
        header("Access-Control-Allow-Origin: $origen");
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        // Chrome exige esto cuando una página pública habla con localhost.
        header('Access-Control-Allow-Private-Network: true');
        header('Access-Control-Max-Age: 600');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {   // petición previa (preflight)
        http_response_code(204);
        exit;
    }
})();

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
