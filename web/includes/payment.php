<?php
// Shared payment logic. Every purchase (terminal resto ticket, terminal cafe
// order, student web resto ticket, student web cafe basket) goes through
// payment_charge(), so the balance check, the debit, the transfer row and the
// stock update are written once.
//
// Retries: a client may send a request id with a payment. The first request
// with that id is executed; a repeat (the terminal retrying after a network
// drop, a double click) gets the stored response of the first one and is not
// charged again. See payment_request_claim() and payment_request_save().

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/**
 * A problem with the card or the student's balance (unknown or inactive card,
 * insufficient balance). A cafe order that fails with this stays payable by
 * another card; any other error marks the order as failed.
 */
class CardException extends UserFacingException {}

// ---------------------------------------------------------------- payer

// Locks the account behind a card UID (terminal payments). The card must be active.
function payment_lock_account_by_card(PDO $pdo, string $uid): array {
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
    $acc = $stmt->fetch();

    if (!$acc) {
        throw new CardException('Carte introuvable');
    }
    if ((string)$acc['carte_statut'] !== 'active') {
        throw new CardException('Carte non active');
    }
    return $acc;
}

// Locks a logged-in student's account (web payments). The student must have
// exactly one card, and it must be active, so a blocked card blocks web
// payments too.
function payment_lock_account_by_student(PDO $pdo, int $etudiantId): array {
    $stmt = $pdo->prepare(
        'SELECT c.id_etudiant, c.id_compte, c.solde, e.nom, e.prenom
         FROM comptes c
         JOIN etudiants e ON e.id_etudiant = c.id_etudiant
         WHERE c.id_etudiant = :id
         FOR UPDATE'
    );
    $stmt->execute([':id' => $etudiantId]);
    $acc = $stmt->fetch();
    if (!$acc) {
        throw new UserFacingException('Compte introuvable');
    }

    $stmtCarte = $pdo->prepare('SELECT statut FROM cartes WHERE id_etudiant = :id FOR UPDATE');
    $stmtCarte->execute([':id' => $etudiantId]);
    $cartes = $stmtCarte->fetchAll();
    if (count($cartes) !== 1 || (string)$cartes[0]['statut'] !== 'active') {
        throw new CardException('Carte non active');
    }
    return $acc;
}

// ---------------------------------------------------------------- items

// [{id_produit, quantite}, ...] -> [id_produit => total quantite]. Invalid lines are dropped.
function payment_normalize_items(mixed $items): array {
    $normalized = [];
    if (!is_array($items)) {
        return $normalized;
    }
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $idProduit = (int)($it['id_produit'] ?? 0);
        $quantite = (int)($it['quantite'] ?? 0);
        if ($idProduit <= 0 || $quantite <= 0) continue;
        $normalized[$idProduit] = ($normalized[$idProduit] ?? 0) + $quantite;
    }
    return $normalized;
}

// ---------------------------------------------------------------- charge

/**
 * Debits one account for one purchase, inside the caller's open transaction.
 *
 * $account   row from payment_lock_account_by_card() / _by_student()
 * $type      'PAIEMENT_RESTO' or 'PAIEMENT_BUVETTE'
 * $amount    millimes to charge; null = sum of the item lines
 * $items     [id_produit => quantite] (cafe only): stock is checked and
 *            decremented, and one detail_transfert_buvette row is written per product
 * $prices    [id_produit => millimes] unit prices frozen when an order was
 *            created; products missing here use the current price
 *
 * Returns id_transfert, reference, montant, solde_avant and solde_apres (millimes).
 */
function payment_charge(
    PDO $pdo,
    array $account,
    string $type,
    ?int $amount,
    array $items,
    array $prices,
    ?int $terminalId,
    string $description
): array {
    // Lock products, check stock, price each line.
    $lines = [];
    $itemsTotal = 0;
    $stmtProd = $pdo->prepare('SELECT id_produit, nom, prix, stock, actif FROM produits WHERE id_produit = :id FOR UPDATE');
    foreach ($items as $idProduit => $quantite) {
        $stmtProd->execute([':id' => (int)$idProduit]);
        $p = $stmtProd->fetch();
        if (!$p || (int)$p['actif'] !== 1) {
            throw new UserFacingException('Produit introuvable');
        }
        if ((int)$p['stock'] < $quantite) {
            throw new UserFacingException('Stock insuffisant: ' . (string)$p['nom']);
        }
        $prix = $prices[$idProduit] ?? money_to_millimes($p['prix']);
        $itemsTotal += $prix * $quantite;
        $lines[(int)$idProduit] = ['quantite' => (int)$quantite, 'prix' => $prix];
    }

    $amount ??= $itemsTotal;
    if ($amount <= 0) {
        throw new UserFacingException('Montant invalide');
    }

    $idCompte = (int)$account['id_compte'];
    $soldeAvant = money_to_millimes($account['solde']);
    if ($soldeAvant < $amount) {
        throw new CardException('Solde insuffisant');
    }
    $soldeApres = $soldeAvant - $amount;
    $reference = generate_reference();

    $pdo->prepare(
        'INSERT INTO transferts (id_compte, id_terminal, montant, type, statut, description, reference, solde_avant, solde_apres)
         VALUES (:id_compte, :id_terminal, :montant, :type, "REUSSI", :description, :reference, :solde_avant, :solde_apres)'
    )->execute([
        ':id_compte' => $idCompte,
        ':id_terminal' => $terminalId,
        ':montant' => millimes_to_db($amount),
        ':type' => $type,
        ':description' => $description,
        ':reference' => $reference,
        ':solde_avant' => millimes_to_db($soldeAvant),
        ':solde_apres' => millimes_to_db($soldeApres),
    ]);
    $idTransfert = (int)$pdo->lastInsertId();

    // SQL guard: never below zero.
    if (!account_debit($pdo, $idCompte, $amount)) {
        throw new CardException('Solde insuffisant');
    }

    if ($lines) {
        $stmtDet = $pdo->prepare(
            'INSERT INTO detail_transfert_buvette (id_transfert, id_produit, quantite, prix_unitaire)
             VALUES (:id_transfert, :id_produit, :quantite, :prix_unitaire)'
        );
        $stmtStock = $pdo->prepare('UPDATE produits SET stock = stock - :qte WHERE id_produit = :id');
        foreach ($lines as $idProduit => $l) {
            $stmtDet->execute([
                ':id_transfert' => $idTransfert,
                ':id_produit' => $idProduit,
                ':quantite' => $l['quantite'],
                ':prix_unitaire' => millimes_to_db($l['prix']),
            ]);
            $stmtStock->execute([':qte' => $l['quantite'], ':id' => $idProduit]);
        }
    }

    return [
        'id_transfert' => $idTransfert,
        'reference' => $reference,
        'montant' => $amount,
        'solde_avant' => $soldeAvant,
        'solde_apres' => $soldeApres,
    ];
}

// ---------------------------------------------------------------- request ids

// A request id is 8 to 64 characters of [A-Za-z0-9_-] (a UUID or random hex
// fits). Returns '' when absent; throws when present but malformed.
function payment_request_id(mixed $value): string {
    if ($value === null || $value === '') {
        return '';
    }
    $id = is_string($value) ? trim($value) : '';
    if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $id)) {
        throw new UserFacingException('request_id invalide');
    }
    return $id;
}

/**
 * Reserves a request id inside the caller's open transaction. Call it before
 * charging anything.
 *
 * Returns null when this is the first request with this id: go ahead, then
 * call payment_request_save() before committing. Returns the stored response
 * array when the id was already used successfully: answer with it and charge
 * nothing.
 *
 * The reservation row is inserted first, so a concurrent duplicate waits on
 * the unique key until the first request commits (then it gets the stored
 * response) or rolls back (then it runs normally). A failed payment rolls
 * back its reservation, so a retry after a failure is executed again.
 *
 * $scope says who owns the id ('terminal:3', 'etudiant:12'), so two clients
 * that pick the same id never see each other's responses.
 */
function payment_request_claim(PDO $pdo, string $scope, string $requestId): ?array {
    try {
        $pdo->prepare('INSERT INTO payment_requests (scope, request_id) VALUES (:scope, :rid)')
            ->execute([':scope' => $scope, ':rid' => $requestId]);
        return null;
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) !== 1062) { // 1062 = duplicate key
            throw $e;
        }
    }

    // Locking read: sees the committed row even under REPEATABLE READ.
    $stmt = $pdo->prepare('SELECT response_json FROM payment_requests WHERE scope = :scope AND request_id = :rid FOR UPDATE');
    $stmt->execute([':scope' => $scope, ':rid' => $requestId]);
    $stored = json_decode((string)$stmt->fetchColumn(), true);
    if (!is_array($stored)) {
        throw new UserFacingException('Requête déjà en cours');
    }
    $stored['replayed'] = true;
    return $stored;
}

// Stores the response of a successful payment for its request id.
function payment_request_save(PDO $pdo, string $scope, string $requestId, int $idTransfert, array $response): void {
    $pdo->prepare(
        'UPDATE payment_requests SET id_transfert = :id_transfert, response_json = :json
         WHERE scope = :scope AND request_id = :rid'
    )->execute([
        ':id_transfert' => $idTransfert,
        ':json' => json_encode($response, JSON_UNESCAPED_UNICODE),
        ':scope' => $scope,
        ':rid' => $requestId,
    ]);
}

// ---------------------------------------------------------------- terminal log

// One row in code_appareil (the terminal's Serial Monitor in the web UI).
function terminal_log(PDO $pdo, int $terminalId, string $type, string $message, string $uid = '', ?array $data = null): void {
    $pdo->prepare(
        'INSERT INTO code_appareil (id_terminal, type_message, message, donnees_json, uid_carte)
         VALUES (:id_terminal, :type, :msg, :json, :uid)'
    )->execute([
        ':id_terminal' => $terminalId,
        ':type' => $type,
        ':msg' => $message,
        ':json' => $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE),
        ':uid' => $uid,
    ]);
}

// The success logs shared by both terminal endpoints.
function terminal_log_payment_ok(PDO $pdo, int $terminalId, string $uid, array $account, array $charge, string $suffix = ''): void {
    terminal_log($pdo, $terminalId, 'PAIEMENT', 'Paiement OK ref=' . $charge['reference'] . $suffix, $uid);
    $nom = trim((string)($account['prenom'] ?? '') . ' ' . (string)($account['nom'] ?? ''));
    if ($nom !== '') {
        terminal_log($pdo, $terminalId, 'USER', 'Étudiant : ' . $nom, $uid);
    }
    terminal_log($pdo, $terminalId, 'SOLDE', 'Nouveau solde : ' . millimes_to_db($charge['solde_apres']) . ' DT', $uid,
        ['solde_apres' => millimes_to_dt($charge['solde_apres'])]);
}
