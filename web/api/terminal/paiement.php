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

$token = trim((string)($payload['token_auth'] ?? ($_SERVER['HTTP_X_TERMINAL_TOKEN'] ?? '')));
$uid = strtoupper(trim((string)($payload['uid'] ?? $payload['card_uid'] ?? '')));
$montant = (float)($payload['montant'] ?? $payload['amount'] ?? 0);
$description = trim((string)($payload['description'] ?? 'Paiement terminal'));

if ($token === '' || $uid === '' || $montant <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'token_auth, uid, montant requis']);
    exit();
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $stmtT = $pdo->prepare('SELECT id_terminal, type, statut FROM terminaux WHERE token_auth = :t LIMIT 1 FOR UPDATE');
    $stmtT->execute([':t' => $token]);
    $term = $stmtT->fetch();

    if (!$term || (string)$term['statut'] !== 'actif') {
        throw new RuntimeException('Terminal non autorisé');
    }

    $idTerminal = (int)$term['id_terminal'];
    $type = ((string)$term['type'] === 'RESTO') ? 'PAIEMENT_RESTO' : 'PAIEMENT_BUVETTE';

    $cands = uid_candidates($uid);
    $in = implode(',', array_fill(0, count($cands), '?'));
    $stmt = $pdo->prepare(
        "SELECT ca.id_etudiant, ca.statut AS carte_statut, c.id_compte, c.solde
         FROM cartes ca
         JOIN comptes c ON c.id_etudiant = ca.id_etudiant
         WHERE ca.uid_rfid IN ($in)
         FOR UPDATE"
    );
    $stmt->execute($cands);
    $row = $stmt->fetch();

    if (!$row) {
        throw new RuntimeException('Carte introuvable');
    }
    if ((string)$row['carte_statut'] !== 'active') {
        throw new RuntimeException('Carte non active');
    }

    $soldeAvant = (float)$row['solde'];
    if ($soldeAvant < $montant) {
        throw new RuntimeException('Solde insuffisant');
    }

    $soldeApres = $soldeAvant - $montant;
    $reference = generate_reference();

    $stmtTr = $pdo->prepare(
        'INSERT INTO transferts (id_compte, id_terminal, montant, type, statut, description, reference, solde_avant, solde_apres)
         VALUES (:id_compte, :id_terminal, :montant, :type, :statut, :description, :reference, :solde_avant, :solde_apres)'
    );
    $stmtTr->execute([
        ':id_compte' => (int)$row['id_compte'],
        ':id_terminal' => $idTerminal,
        ':montant' => $montant,
        ':type' => $type,
        ':statut' => 'REUSSI',
        ':description' => $description,
        ':reference' => $reference,
        ':solde_avant' => $soldeAvant,
        ':solde_apres' => $soldeApres,
    ]);

    $pdo->prepare('UPDATE comptes SET solde = :solde WHERE id_compte = :id')->execute([
        ':solde' => $soldeApres,
        ':id' => (int)$row['id_compte'],
    ]);

    $pdo->prepare('INSERT INTO code_appareil (id_terminal, type_message, message, uid_carte) VALUES (:id_terminal, :type, :msg, :uid)')
        ->execute([
            ':id_terminal' => $idTerminal,
            ':type' => 'PAIEMENT',
            ':msg' => 'Paiement OK ref=' . $reference,
            ':uid' => $uid,
        ]);

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'reference' => $reference,
        'solde_avant' => $soldeAvant,
        'solde_apres' => $soldeApres,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $msg = $e instanceof RuntimeException ? $e->getMessage() : 'Erreur serveur';
    if (!($e instanceof RuntimeException)) {
        error_log('terminal/paiement.php error: ' . $e->getMessage());
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $msg]);
}
