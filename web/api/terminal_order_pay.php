<?php

// Called by ESP32 after claiming an order: pay it using card UID, update stock, and log transfer.

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

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

// Card problems (unknown/inactive card, low balance) leave the order payable by another card.
$cardError = false;

try {
    $pdo = db();
    $pdo->beginTransaction();

    $terminal = require_terminal($pdo, $token, $terminalId, true);

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
    $acc = $stmt->fetch();

    if (!$acc) {
        $cardError = true;
        throw new UserFacingException('Carte introuvable');
    }
    if ((string)$acc['carte_statut'] !== 'active') {
        $cardError = true;
        throw new UserFacingException('Carte non active');
    }

    $etudiantNom = trim((string)($acc['prenom'] ?? '') . ' ' . (string)($acc['nom'] ?? ''));

    $soldeAvant = money_to_millimes($acc['solde']);
    if ($soldeAvant < $amount) {
        $cardError = true;
        throw new UserFacingException('Solde insuffisant');
    }

    // For buvette: lock products + update stock + insert details
    if ($mode === 'BUVETTE') {
        $items = json_decode((string)($order['items_json'] ?? '[]'), true);
        if (!is_array($items) || count($items) === 0) {
            throw new UserFacingException('Commande invalide (panier vide)');
        }

        $normalized = [];
        $prices = []; // unit prices frozen at order creation (absent on older orders)
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $idProduit = (int)($it['id_produit'] ?? 0);
            $quantite = (int)($it['quantite'] ?? 0);
            if ($idProduit <= 0 || $quantite <= 0) continue;
            $normalized[$idProduit] = ($normalized[$idProduit] ?? 0) + $quantite;
            $prixFige = money_parse($it['prix_unitaire'] ?? null);
            if ($prixFige !== null && $prixFige >= 0) {
                $prices[$idProduit] = $prixFige;
            }
        }
        if (count($normalized) === 0) {
            throw new UserFacingException('Commande invalide (articles)');
        }

        $stmtProd = $pdo->prepare('SELECT id_produit, nom, prix, stock, actif FROM produits WHERE id_produit = :id FOR UPDATE');
        foreach ($normalized as $idProduit => $quantite) {
            $stmtProd->execute([':id' => $idProduit]);
            $p = $stmtProd->fetch();
            if (!$p || (int)$p['actif'] !== 1) {
                throw new UserFacingException('Produit introuvable');
            }
            $stock = (int)$p['stock'];
            if ($stock < $quantite) {
                throw new UserFacingException('Stock insuffisant: ' . (string)$p['nom']);
            }
        }

        // Stock will be updated after transfer insert.
    }

    $soldeApres = $soldeAvant - $amount;
    $reference = generate_reference();
    $type = ($mode === 'RESTO') ? 'PAIEMENT_RESTO' : 'PAIEMENT_BUVETTE';

    // Insert transfer
    $stmtTr = $pdo->prepare(
        'INSERT INTO transferts (id_compte, id_terminal, montant, type, statut, description, reference, solde_avant, solde_apres)
         VALUES (:id_compte, :id_terminal, :montant, :type, :statut, :description, :reference, :solde_avant, :solde_apres)'
    );
    $stmtTr->execute([
        ':id_compte' => (int)$acc['id_compte'],
        ':id_terminal' => $terminalId,
        ':montant' => millimes_to_db($amount),
        ':type' => $type,
        ':statut' => 'REUSSI',
        ':description' => 'Paiement terminal (commande)',
        ':reference' => $reference,
        ':solde_avant' => millimes_to_db($soldeAvant),
        ':solde_apres' => millimes_to_db($soldeApres),
    ]);

    $idTransfert = (int)$pdo->lastInsertId();

    // Update account balance (SQL guard: never below zero)
    if (!account_debit($pdo, (int)$acc['id_compte'], $amount)) {
        throw new UserFacingException('Solde insuffisant');
    }

    if ($mode === 'BUVETTE') {
        $stmtProd = $pdo->prepare('SELECT id_produit, prix, actif FROM produits WHERE id_produit = :id FOR UPDATE');
        $stmtDet = $pdo->prepare(
            'INSERT INTO detail_transfert_buvette (id_transfert, id_produit, quantite, prix_unitaire)
             VALUES (:id_transfert, :id_produit, :quantite, :prix_unitaire)'
        );
        $stmtUpdStock = $pdo->prepare('UPDATE produits SET stock = stock - :qte WHERE id_produit = :id');

        foreach ($normalized as $idProduit => $quantite) {
            $stmtProd->execute([':id' => $idProduit]);
            $p = $stmtProd->fetch();
            if (!$p || (int)$p['actif'] !== 1) {
                throw new UserFacingException('Produit introuvable');
            }
            $prix = $prices[$idProduit] ?? money_to_millimes($p['prix']);
            $stmtDet->execute([
                ':id_transfert' => $idTransfert,
                ':id_produit' => (int)$idProduit,
                ':quantite' => (int)$quantite,
                ':prix_unitaire' => millimes_to_db($prix),
            ]);
            $stmtUpdStock->execute([':qte' => (int)$quantite, ':id' => (int)$idProduit]);
        }
    }

    // Device log
    $pdo->prepare('INSERT INTO code_appareil (id_terminal, type_message, message, uid_carte) VALUES (:id_terminal, :type, :msg, :uid)')
        ->execute([
            ':id_terminal' => $terminalId,
            ':type' => 'PAIEMENT',
            ':msg' => 'Paiement OK ref=' . $reference . ' order=' . $orderId,
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

    // Mark order as paid
    $pdo->prepare('UPDATE terminal_orders SET statut = "PAYE", id_transfert = :id_transfert, reference = :reference, message_erreur = NULL WHERE id_order = :id')
        ->execute([
            ':id_transfert' => $idTransfert,
            ':reference' => $reference,
            ':id' => $orderId,
        ]);

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'success' => true,
        'order_id' => $orderId,
        'reference' => $reference,
        'solde_apres' => millimes_to_dt($soldeApres),
        'etudiant' => [
            'id_etudiant' => (int)$acc['id_etudiant'],
            'nom' => (string)($acc['nom'] ?? ''),
            'prenom' => (string)($acc['prenom'] ?? ''),
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $msg = $e instanceof UserFacingException ? $e->getMessage() : 'Erreur serveur';

    // Card errors: back to PENDING so another card can be tapped. Real order
    // problems: ECHEC. Only touch an order still being paid (never a PAYE one).
    try {
        if (isset($pdo) && $pdo instanceof PDO && isset($orderId) && $orderId > 0) {
            $newStatut = $cardError ? 'PENDING' : 'ECHEC';
            $pdo->prepare('UPDATE terminal_orders SET statut = :statut, message_erreur = :msg WHERE id_order = :id AND statut = "IN_PROGRESS"')
                ->execute([':statut' => $newStatut, ':msg' => $msg, ':id' => $orderId]);
        }
    } catch (Throwable $_) {
    }

    // Device log (best-effort)
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            $pdo->prepare('INSERT INTO code_appareil (id_terminal, type_message, message, donnees_json, uid_carte) VALUES (:id_terminal, :type, :msg, :json, :uid)')
                ->execute([
                    ':id_terminal' => $terminalId,
                    ':type' => 'ERREUR',
                    ':msg' => 'Commande ' . $orderId . ' KO: ' . $msg,
                    ':json' => json_encode(['order_id' => $orderId, 'mode' => ($mode ?? null)], JSON_UNESCAPED_UNICODE),
                    ':uid' => $uid,
                ]);
        }
    } catch (Throwable $_) {
    }

    http_response_code(400);
    if (!($e instanceof UserFacingException)) {
        error_log('terminal_order_pay error: ' . $e->getMessage());
    }
    echo json_encode(['ok' => false, 'success' => false, 'error' => $msg]);
}
