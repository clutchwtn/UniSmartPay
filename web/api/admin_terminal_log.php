<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

require_admin_api();

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

$idTerminal = isset($payload['id_terminal']) ? (int)$payload['id_terminal'] : null;
$uidTerminal = isset($payload['uid_terminal']) ? trim((string)$payload['uid_terminal']) : null;
$typeMessage = strtoupper(trim((string)($payload['type_message'] ?? 'INFO')));
$message = trim((string)($payload['message'] ?? ''));
$uidCarte = isset($payload['uid_carte']) ? strtoupper(trim((string)$payload['uid_carte'])) : null;
$donnees = $payload['donnees_json'] ?? null;

// Same set of message types as terminal/log.php.
$allowed = [
    'INFO', 'SUCCESS', 'ERROR', 'ERREUR', 'WARNING',
    'SCAN', 'PANIER', 'RFID', 'PAIEMENT', 'RESPONSE',
    'SOLDE', 'USER', 'RESTO',
    'SYSTEME', 'CONNEXION',
];
if (!in_array($typeMessage, $allowed, true)) {
    $typeMessage = 'INFO';
}

if ($idTerminal === null || $idTerminal <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'id_terminal requis']);
    exit();
}

if (strlen($message) > 500) {
    $message = substr($message, 0, 500);
}

if ($message === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Message requis']);
    exit();
}

try {
    $pdo = db();

    $stmtT = $pdo->prepare('SELECT id_terminal FROM terminaux WHERE id_terminal = :id LIMIT 1');
    $stmtT->execute([':id' => $idTerminal]);
    if (!$stmtT->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Terminal introuvable']);
        exit();
    }

    $stmt = $pdo->prepare(
        'INSERT INTO code_appareil (id_terminal, uid_terminal, type_message, message, donnees_json, uid_carte)
         VALUES (:id_terminal, :uid_terminal, :type_message, :message, :donnees_json, :uid_carte)'
    );

    $stmt->execute([
        ':id_terminal' => $idTerminal,
        ':uid_terminal' => $uidTerminal,
        ':type_message' => $typeMessage,
        ':message' => $message,
        ':donnees_json' => $donnees !== null ? json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ':uid_carte' => $uidCarte,
    ]);

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('admin_terminal_log error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur']);
}
