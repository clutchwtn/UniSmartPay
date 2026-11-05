<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$terminalId = (int)($_GET['terminal_id'] ?? 0);
$token = terminal_token();
if ($terminalId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'terminal_id requis']);
    exit();
}

try {
    $pdo = db();
    $t = require_terminal($pdo, $token, $terminalId);

    echo json_encode([
        'ok' => true,
        'terminal_id' => (int)$t['id_terminal'],
        'mode' => (string)$t['type'],
        'statut' => (string)$t['statut'],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('terminal_mode_get error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur']);
}
