<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
require_admin_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']);
    exit();
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON invalide']);
    exit();
}

$csrf = (string)($payload['csrf_token'] ?? '');
require_csrf_token($csrf);

$terminalId = (int)($payload['terminal_id'] ?? 0);
$mode = strtoupper(trim((string)($payload['mode'] ?? '')));

if ($terminalId <= 0 || !in_array($mode, ['RESTO', 'BUVETTE'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'terminal_id et mode requis (RESTO/BUVETTE)']);
    exit();
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $stmtT = $pdo->prepare('SELECT id_terminal, type, statut FROM terminaux WHERE id_terminal = :id LIMIT 1 FOR UPDATE');
    $stmtT->execute([':id' => $terminalId]);
    $t = $stmtT->fetch();

    if (!$t) {
        throw new RuntimeException('Terminal introuvable');
    }
    if ((string)$t['statut'] !== 'actif') {
        throw new RuntimeException('Terminal inactif');
    }

    $pdo->prepare('UPDATE terminaux SET type = :type WHERE id_terminal = :id')
        ->execute([':type' => $mode, ':id' => $terminalId]);

    // Best-effort log for the UI
    $pdo->prepare('INSERT INTO code_appareil (id_terminal, type_message, message, donnees_json) VALUES (:id_terminal, :type, :msg, :json)')
        ->execute([
            ':id_terminal' => $terminalId,
            ':type' => 'SYSTEME',
            ':msg' => 'Mode terminal mis à jour: ' . $mode,
            ':json' => json_encode(['mode' => $mode], JSON_UNESCAPED_UNICODE),
        ]);

    $pdo->commit();

    echo json_encode(['ok' => true, 'terminal_id' => $terminalId, 'mode' => $mode]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    $msg = $e instanceof RuntimeException ? $e->getMessage() : 'Erreur serveur';
    if (!($e instanceof RuntimeException)) {
        error_log('terminal_mode_set error: ' . $e->getMessage());
    }
    echo json_encode(['ok' => false, 'error' => $msg]);
}
