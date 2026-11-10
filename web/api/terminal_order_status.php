<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

require_admin_api();

$orderId = (int)($_GET['order_id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'order_id requis']);
    exit();
}

try {
    $pdo = db();
    // Non-super admins only see the orders they created.
    $isSuper = ($_SESSION['admin_role'] ?? '') === 'super_admin';

    $stmt = $pdo->prepare('SELECT id_order, id_terminal, mode, statut, montant, message_erreur, reference, id_transfert, expires_at, updated_at FROM terminal_orders WHERE id_order = :id'
        . ($isSuper ? '' : ' AND created_by_admin = :admin')
        . ' LIMIT 1');
    $stmt->execute($isSuper ? [':id' => $orderId] : [':id' => $orderId, ':admin' => (int)$_SESSION['admin_id']]);
    $o = $stmt->fetch();

    if (!$o) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Commande introuvable']);
        exit();
    }

    echo json_encode([
        'ok' => true,
        'order' => [
            'order_id' => (int)$o['id_order'],
            'terminal_id' => (int)$o['id_terminal'],
            'mode' => (string)$o['mode'],
            'statut' => (string)$o['statut'],
            'montant' => (float)$o['montant'],
            'error' => $o['message_erreur'] !== null ? (string)$o['message_erreur'] : null,
            'reference' => $o['reference'] !== null ? (string)$o['reference'] : null,
            'id_transfert' => $o['id_transfert'] !== null ? (int)$o['id_transfert'] : null,
            'expires_at' => $o['expires_at'] !== null ? (string)$o['expires_at'] : null,
            'updated_at' => (string)$o['updated_at'],
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('terminal_order_status error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur']);
}
