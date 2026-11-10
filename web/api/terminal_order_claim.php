<?php

// Called by ESP32 after RFID card detected: claim oldest pending order for a terminal.

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
    $pdo->beginTransaction();

    require_terminal($pdo, $token, $terminalId, true);

    // Expire old pending orders
    $pdo->prepare('UPDATE terminal_orders SET statut = "EXPIRE", message_erreur = "Expirée" WHERE id_terminal = :id AND statut = "PENDING" AND expires_at IS NOT NULL AND expires_at < NOW()')
        ->execute([':id' => $terminalId]);

    // Claim the oldest pending order
    $stmt = $pdo->prepare(
        'SELECT id_order, mode, montant, items_json
         FROM terminal_orders
         WHERE id_terminal = :id AND statut = "PENDING"
         ORDER BY created_at ASC
         LIMIT 1
         FOR UPDATE'
    );
    $stmt->execute([':id' => $terminalId]);
    $o = $stmt->fetch();

    if (!$o) {
        $pdo->commit();
        echo json_encode(['ok' => true, 'found' => false]);
        exit();
    }

    $orderId = (int)$o['id_order'];

    $pdo->prepare('UPDATE terminal_orders SET statut = "IN_PROGRESS" WHERE id_order = :id')
        ->execute([':id' => $orderId]);

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'found' => true,
        'order' => [
            'order_id' => $orderId,
            'mode' => (string)$o['mode'],
            'montant' => (float)$o['montant'],
            'items_json' => $o['items_json'] !== null ? (string)$o['items_json'] : null,
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    $msg = $e instanceof UserFacingException ? $e->getMessage() : 'Erreur serveur';
    if (!($e instanceof UserFacingException)) {
        error_log('terminal_order_claim error: ' . $e->getMessage());
    }
    echo json_encode(['ok' => false, 'error' => $msg]);
}
