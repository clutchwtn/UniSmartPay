<?php
// Restaurant ticket payment from a RESTO terminal (ESP32).
// Expected JSON: {"card_uid":"...","terminal_id":1,"request_id":"..."}
// The price always comes from config_resto. An "amount" sent by the terminal
// is ignored, so a terminal (or a stolen token) cannot choose what to charge.
// request_id (optional, 8-64 chars of [A-Za-z0-9_-]): a repeat of an id that
// already succeeded returns the first response with "replayed": true and
// charges nothing.

declare(strict_types=1);

require_once __DIR__ . '/../includes/payment.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée']);
    exit();
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'JSON invalide']);
    exit();
}

$uid = strtoupper(trim((string)($payload['card_uid'] ?? '')));
$amount = 0; // millimes, set from config_resto below
$terminalId = (int)($payload['terminal_id'] ?? 0);
$token = terminal_token();

if ($uid === '' || $terminalId <= 0 || $token === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Paramètres invalides']);
    exit();
}

try {
    $requestId = payment_request_id($payload['request_id'] ?? null);

    $pdo = db();
    $pdo->beginTransaction();

    $terminal = require_terminal($pdo, $token, $terminalId, true);

    if ((string)$terminal['type'] !== 'RESTO') {
        throw new UserFacingException('Terminal non configuré pour le resto');
    }

    $scope = 'terminal:' . $terminalId;
    if ($requestId !== '' && ($replay = payment_request_claim($pdo, $scope, $requestId)) !== null) {
        $pdo->rollBack();
        echo json_encode($replay);
        exit();
    }

    $amount = resto_ticket_price($pdo);
    if ($amount <= 0) {
        throw new UserFacingException('Configuration resto indisponible');
    }

    $account = payment_lock_account_by_card($pdo, $uid);
    $charge = payment_charge($pdo, $account, 'PAIEMENT_RESTO', $amount, [], [], $terminalId, 'Ticket resto (terminal)');

    $response = [
        'success' => true,
        'reference' => $charge['reference'],
        'montant' => millimes_to_dt($charge['montant']),
        'solde_apres' => millimes_to_dt($charge['solde_apres']),
        'etudiant' => [
            'id_etudiant' => (int)$account['id_etudiant'],
            'nom' => (string)($account['nom'] ?? ''),
            'prenom' => (string)($account['prenom'] ?? ''),
        ],
    ];
    if ($requestId !== '') {
        payment_request_save($pdo, $scope, $requestId, $charge['id_transfert'], $response);
    }

    terminal_log_payment_ok($pdo, $terminalId, $uid, $account, $charge);

    $pdo->commit();
    echo json_encode($response);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $msg = $e instanceof UserFacingException ? $e->getMessage() : 'Erreur serveur';
    if (!($e instanceof UserFacingException)) {
        error_log('payment.php error: ' . $e->getMessage());
    }

    // Best-effort device log (so the web UI can show it like a Serial Monitor)
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            terminal_log($pdo, $terminalId, 'ERREUR', 'Paiement KO: ' . $msg, $uid, ['amount' => millimes_to_dt($amount)]);
        }
    } catch (Throwable $_) {
        // ignore logging failure
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $msg]);
}
