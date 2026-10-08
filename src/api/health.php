<?php
require __DIR__ . '/db.php';

try {
    $pdo = pdo_ro();
    $pdo->query('SELECT 1');
    json_out(['status' => 'ok', 'db' => 'datawarehouse']);
} catch (Throwable $e) {
    json_out(['status' => 'error'], 503);
}
