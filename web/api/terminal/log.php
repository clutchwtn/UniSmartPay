<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

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

$idTerminal = isset($payload['id_terminal']) ? (int)$payload['id_terminal'] : null;
$uidTerminal = isset($payload['uid_terminal']) ? trim((string)$payload['uid_terminal']) : null;
$typeMessage = strtoupper(trim((string)($payload['type_message'] ?? 'INFO')));
$message = trim((string)($payload['message'] ?? ''));
$uidCarte = isset($payload['uid_carte']) ? strtoupper(trim((string)$payload['uid_carte'])) : null;
$donnees = $payload['donnees_json'] ?? null;

// Accept a richer set of types for a "Serial Monitor" experience.
$allowed = [
    'INFO', 'SUCCESS', 'ERROR', 'ERREUR', 'WARNING',
    'SCAN', 'PANIER', 'RFID', 'PAIEMENT', 'RESPONSE',
    'SOLDE', 'USER', 'RESTO',
    'SYSTEME', 'CONNEXION',
];
if (!in_array($typeMessage, $allowed, true)) {
    // Fall back to INFO for unexpected types
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

$token = terminal_token();
$terminalId = (int)($payload['id_terminal'] ?? 0);
if ($terminalId <= 0 || $token === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Authenticated terminal required']);
    exit();
}

try {
    $pdo = db();
    require_terminal($pdo, $token, $terminalId);

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
    error_log('terminal/log.php error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur']);
}
