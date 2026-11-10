<?php
// Restaurant ticket payment from a RESTO terminal (ESP32).
// Expected JSON: {"card_uid":"...","terminal_id":1}
// The price always comes from config_resto. An "amount" sent by the terminal
// is ignored, so a terminal (or a stolen token) cannot choose what to charge.

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

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
    $pdo = db();
    $pdo->beginTransaction();

    $terminal = require_terminal($pdo, $token, $terminalId, true);

    if ((string)$terminal['type'] !== 'RESTO') {
        throw new UserFacingException('Terminal non configuré pour le resto');
    }
    $amount = resto_ticket_price($pdo);
    if ($amount <= 0) {
        throw new UserFacingException('Configuration resto indisponible');
    }

    // Find card/account
    $cands = uid_candidates($uid);
    $in = implode(',', array_fill(0, count($cands), '?'));

    $stmt = $pdo->prepare(
        "SELECT ca.id_etudiant, ca.statut AS carte_statut, c.id_compte, c.solde, e.nom, e.prenom
         FROM cartes ca
         JOIN comptes c ON c.id_etudiant = ca.id_etudiant
         JOIN etudiants e ON e.id_etudiant = ca.id_etudiant
         WHERE ca.uid_rfid IN ($in)
         FOR UPDATE"
    );
    $stmt->execute($cands);
    $row = $stmt->fetch();

    if (!$row) {
        throw new UserFacingException('Carte introuvable');
    }
    if ((string)$row['carte_statut'] !== 'active') {
        throw new UserFacingException('Carte non active');
    }

    $etudiantNom = trim((string)($row['prenom'] ?? '') . ' ' . (string)($row['nom'] ?? ''));

    $soldeAvant = money_to_millimes($row['solde']);
    if ($soldeAvant < $amount) {
        throw new UserFacingException('Solde insuffisant');
    }

    $type = 'PAIEMENT_RESTO';
    $soldeApres = $soldeAvant - $amount;
    $reference = generate_reference();

    $stmtTr = $pdo->prepare(
        'INSERT INTO transferts (id_compte, id_terminal, montant, type, statut, description, reference, solde_avant, solde_apres)
         VALUES (:id_compte, :id_terminal, :montant, :type, :statut, :description, :reference, :solde_avant, :solde_apres)'
    );
    $stmtTr->execute([
        ':id_compte' => (int)$row['id_compte'],
        ':id_terminal' => $terminalId,
        ':montant' => millimes_to_db($amount),
        ':type' => $type,
        ':statut' => 'REUSSI',
        ':description' => 'Ticket resto (terminal)',
        ':reference' => $reference,
        ':solde_avant' => millimes_to_db($soldeAvant),
        ':solde_apres' => millimes_to_db($soldeApres),
    ]);

    if (!account_debit($pdo, (int)$row['id_compte'], $amount)) {
        throw new UserFacingException('Solde insuffisant');
    }

    // Device log
    $pdo->prepare('INSERT INTO code_appareil (id_terminal, type_message, message, uid_carte) VALUES (:id_terminal, :type, :msg, :uid)')
        ->execute([
            ':id_terminal' => $terminalId,
            ':type' => 'PAIEMENT',
            ':msg' => 'Paiement OK ref=' . $reference,
            ':uid' => $uid,
        ]);

    // Extra logs for Serial Monitor UI
    if ($etudiantNom !== '') {
        $pdo->prepare('INSERT INTO code_appareil (id_terminal, type_message, message, uid_carte) VALUES (:id_terminal, :type, :msg, :uid)')
            ->execute([
                ':id_terminal' => $terminalId,
                ':type' => 'USER',
                ':msg' => 'Étudiant : ' . $etudiantNom,
                ':uid' => $uid,
            ]);
    }
    $pdo->prepare('INSERT INTO code_appareil (id_terminal, type_message, message, donnees_json, uid_carte) VALUES (:id_terminal, :type, :msg, :json, :uid)')
        ->execute([
            ':id_terminal' => $terminalId,
            ':type' => 'SOLDE',
            ':msg' => 'Nouveau solde : ' . millimes_to_db($soldeApres) . ' DT',
            ':json' => json_encode(['solde_apres' => millimes_to_dt($soldeApres)], JSON_UNESCAPED_UNICODE),
            ':uid' => $uid,
        ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'reference' => $reference,
        'montant' => millimes_to_dt($amount),
        'solde_apres' => millimes_to_dt($soldeApres),
        'etudiant' => [
            'id_etudiant' => (int)$row['id_etudiant'],
            'nom' => (string)($row['nom'] ?? ''),
            'prenom' => (string)($row['prenom'] ?? ''),
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Best-effort device log (so the web UI can show it like a Serial Monitor)
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            $pdo->prepare('INSERT INTO code_appareil (id_terminal, type_message, message, donnees_json, uid_carte) VALUES (:id_terminal, :type, :msg, :json, :uid)')
                ->execute([
                    ':id_terminal' => $terminalId,
                    ':type' => 'ERREUR',
                    ':msg' => 'Paiement KO: ' . ($e instanceof UserFacingException ? $e->getMessage() : 'Erreur serveur'),
                    ':json' => json_encode(['amount' => millimes_to_dt($amount)], JSON_UNESCAPED_UNICODE),
                    ':uid' => $uid,
                ]);
        }
    } catch (Throwable $_) {
        // ignore logging failure
    }

    $msg = $e instanceof UserFacingException ? $e->getMessage() : 'Erreur serveur';
    if (!($e instanceof UserFacingException)) {
        error_log('payment.php error: ' . $e->getMessage());
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $msg]);
}
