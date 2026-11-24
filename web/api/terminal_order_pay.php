<?php

// Called by ESP32 after claiming an order: pay it using card UID, update stock, and log transfer.
// Expected JSON: {"order_id":1,"terminal_id":2,"card_uid":"...","request_id":"..."}
// request_id (optional): a repeat of an id that already succeeded returns the
// first response with "replayed": true and charges nothing.

declare(strict_types=1);

require_once __DIR__ . '/../includes/payment.php';

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

$orderId = (int)($payload['order_id'] ?? 0);
$terminalId = (int)($payload['terminal_id'] ?? 0);
$uid = strtoupper(trim((string)($payload['card_uid'] ?? '')));
$token = terminal_token();

if ($orderId <= 0 || $terminalId <= 0 || $uid === '' || $token === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Paramètres invalides']);
    exit();
}

try {
    $requestId = payment_request_id($payload['request_id'] ?? null);

    $pdo = db();
    $pdo->beginTransaction();

    $terminal = require_terminal($pdo, $token, $terminalId, true);

    // A retry of a payment that already went through: answer as the first time.
    $scope = 'terminal:' . $terminalId;
    if ($requestId !== '' && ($replay = payment_request_claim($pdo, $scope, $requestId)) !== null) {
        $pdo->rollBack();
        echo json_encode($replay);
        exit();
    }

    // Lock order
    $stmtO = $pdo->prepare(
        'SELECT id_order, mode, statut, montant, items_json, expires_at
         FROM terminal_orders
         WHERE id_order = :id AND id_terminal = :terminal_id
         LIMIT 1
         FOR UPDATE'
    );
    $stmtO->execute([':id' => $orderId, ':terminal_id' => $terminalId]);
    $order = $stmtO->fetch();

    if (!$order) {
        throw new UserFacingException('Commande introuvable');
    }

    $statut = (string)$order['statut'];
    if ($statut === 'PAYE') {
        throw new UserFacingException('Commande déjà payée');
    }
    if ($statut !== 'IN_PROGRESS') {
        throw new UserFacingException('Commande non disponible');
    }

    if (!empty($order['expires_at']) && strtotime((string)$order['expires_at']) < time()) {
        $pdo->prepare('UPDATE terminal_orders SET statut = "EXPIRE", message_erreur = "Expirée" WHERE id_order = :id')
            ->execute([':id' => $orderId]);
        throw new UserFacingException('Commande expirée');
    }

    $mode = (string)$order['mode'];
    $amount = money_to_millimes($order['montant']);

    // For buvette: the basket and the unit prices frozen at order creation.
    $items = [];
    $prices = [];
    if ($mode === 'BUVETTE') {
        $rawItems = json_decode((string)($order['items_json'] ?? '[]'), true);
        if (!is_array($rawItems) || count($rawItems) === 0) {
            throw new UserFacingException('Commande invalide (panier vide)');
        }
        $items = payment_normalize_items($rawItems);
        if (count($items) === 0) {
            throw new UserFacingException('Commande invalide (articles)');
        }
        foreach ($rawItems as $it) {
            $prixFige = is_array($it) ? money_parse($it['prix_unitaire'] ?? null) : null;
            if ($prixFige !== null && $prixFige >= 0) {
                $prices[(int)$it['id_produit']] = $prixFige;
            }
        }
    }

    $account = payment_lock_account_by_card($pdo, $uid);
    $type = ($mode === 'RESTO') ? 'PAIEMENT_RESTO' : 'PAIEMENT_BUVETTE';
    $charge = payment_charge($pdo, $account, $type, $amount, $items, $prices, $terminalId, 'Paiement terminal (commande)');

    // Mark order as paid
    $pdo->prepare('UPDATE terminal_orders SET statut = "PAYE", id_transfert = :id_transfert, reference = :reference, message_erreur = NULL WHERE id_order = :id')
        ->execute([
            ':id_transfert' => $charge['id_transfert'],
            ':reference' => $charge['reference'],
            ':id' => $orderId,
        ]);

    $response = [
        'ok' => true,
        'success' => true,
        'order_id' => $orderId,
        'reference' => $charge['reference'],
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

    terminal_log_payment_ok($pdo, $terminalId, $uid, $account, $charge, ' order=' . $orderId);

    $pdo->commit();
    echo json_encode($response);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $msg = $e instanceof UserFacingException ? $e->getMessage() : 'Erreur serveur';

    // Card errors (CardException: unknown/inactive card, low balance): back to
    // PENDING so another card can be tapped. Real order problems: ECHEC. Only
    // touch an order still being paid (never a PAYE one).
    try {
        if (isset($pdo) && $pdo instanceof PDO && isset($orderId) && $orderId > 0) {
            $newStatut = $e instanceof CardException ? 'PENDING' : 'ECHEC';
            $pdo->prepare('UPDATE terminal_orders SET statut = :statut, message_erreur = :msg WHERE id_order = :id AND statut = "IN_PROGRESS"')
                ->execute([':statut' => $newStatut, ':msg' => $msg, ':id' => $orderId]);
        }
    } catch (Throwable $_) {
    }

    // Device log (best-effort)
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            terminal_log($pdo, $terminalId, 'ERREUR', 'Commande ' . $orderId . ' KO: ' . $msg, $uid,
                ['order_id' => $orderId, 'mode' => ($mode ?? null)]);
        }
    } catch (Throwable $_) {
    }

    http_response_code(400);
    if (!($e instanceof UserFacingException)) {
        error_log('terminal_order_pay error: ' . $e->getMessage());
    }
    echo json_encode(['ok' => false, 'success' => false, 'error' => $msg]);
}
