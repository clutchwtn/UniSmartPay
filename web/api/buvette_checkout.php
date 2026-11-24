<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/payment.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['etudiant_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Non authentifié']);
    exit();
}

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

$items = $payload['items'] ?? null;
if (!is_array($items) || count($items) === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Panier vide']);
    exit();
}

$normalized = payment_normalize_items($items);
if (count($normalized) === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Articles invalides']);
    exit();
}

$etudiantId = (int)$_SESSION['etudiant_id'];

try {
    // Sent by the page once per basket, so a double click or a resend pays once.
    $requestId = payment_request_id($payload['request_id'] ?? null);

    $pdo = db();
    $pdo->beginTransaction();

    $scope = 'etudiant:' . $etudiantId;
    if ($requestId !== '' && ($replay = payment_request_claim($pdo, $scope, $requestId)) !== null) {
        $pdo->rollBack();
        echo json_encode($replay);
        exit();
    }

    $account = payment_lock_account_by_student($pdo, $etudiantId);
    $charge = payment_charge($pdo, $account, 'PAIEMENT_BUVETTE', null, $normalized, [], null, 'Achat buvette (web scan)');

    $response = [
        'ok' => true,
        'reference' => $charge['reference'],
        'montant' => millimes_to_dt($charge['montant']),
        'solde_avant' => millimes_to_dt($charge['solde_avant']),
        'solde_apres' => millimes_to_dt($charge['solde_apres']),
    ];
    if ($requestId !== '') {
        payment_request_save($pdo, $scope, $requestId, $charge['id_transfert'], $response);
    }

    $pdo->commit();

    log_security($etudiantId, 'etudiant', 'PAIEMENT_BUVETTE', 'Paiement buvette réussi ref=' . $charge['reference'] . ' montant=' . millimes_to_db($charge['montant']), 'SUCCES');

    echo json_encode($response);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $msg = $e instanceof UserFacingException ? $e->getMessage() : 'Erreur serveur';
    if (!($e instanceof UserFacingException)) {
        error_log('buvette_checkout error: ' . $e->getMessage());
    }

    log_security($etudiantId, 'etudiant', 'PAIEMENT_BUVETTE', 'Echec paiement buvette: ' . $msg, 'ECHEC');

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $msg]);
}
