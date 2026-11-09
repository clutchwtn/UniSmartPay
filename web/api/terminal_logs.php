<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

require_admin_api();

$terminalId = (int)($_GET['terminal_id'] ?? 0);
$limit = (int)($_GET['limit'] ?? 20);
if ($limit <= 0) $limit = 20;
if ($limit > 50) $limit = 50;

if ($terminalId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'terminal_id requis']);
    exit();
}

try {
    $pdo = db();

    // Validate terminal exists (active or not)
    $stmtT = $pdo->prepare('SELECT id_terminal FROM terminaux WHERE id_terminal = :id LIMIT 1');
    $stmtT->execute([':id' => $terminalId]);
    if (!$stmtT->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Terminal introuvable']);
        exit();
    }

    $stmt = $pdo->prepare(
        'SELECT id_message, type_message, message, uid_carte, date_message
         FROM code_appareil
         WHERE id_terminal = :id
         ORDER BY date_message DESC
         LIMIT ' . $limit
    );
    $stmt->execute([':id' => $terminalId]);
    $rows = $stmt->fetchAll();

    $logs = [];
    foreach ($rows as $r) {
        $logs[] = [
            'id' => (int)$r['id_message'],
            'type' => (string)$r['type_message'],
            'message' => (string)$r['message'],
            'uid_carte' => $r['uid_carte'] !== null ? mask_uid((string)$r['uid_carte']) : null,
            'date' => (string)$r['date_message'],
        ];
    }

    echo json_encode(['ok' => true, 'logs' => $logs]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('terminal_logs error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur']);
}
