<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

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

// Normalize items
$normalized = [];
foreach ($items as $item) {
    if (!is_array($item)) continue;
    $idProduit = (int)($item['id_produit'] ?? 0);
    $quantite = (int)($item['quantite'] ?? 0);
    if ($idProduit <= 0 || $quantite <= 0) continue;
    $normalized[$idProduit] = ($normalized[$idProduit] ?? 0) + $quantite;
}

if (count($normalized) === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Articles invalides']);
    exit();
}

$etudiantId = (int)$_SESSION['etudiant_id'];

try {
    $pdo = db();
    $pdo->beginTransaction();

    // Lock account
    $stmt = $pdo->prepare(
        'SELECT c.id_compte, c.solde
         FROM comptes c
         WHERE c.id_etudiant = :id
         FOR UPDATE'
    );
    $stmt->execute([':id' => $etudiantId]);
    $account = $stmt->fetch();

    if (!$account) {
        throw new UserFacingException('Compte introuvable');
    }

    // Lock the student's card: exactly one card, and it must be active
    $stmtCarte = $pdo->prepare('SELECT statut FROM cartes WHERE id_etudiant = :id FOR UPDATE');
    $stmtCarte->execute([':id' => $etudiantId]);
    $cartes = $stmtCarte->fetchAll();

    if (count($cartes) !== 1 || $cartes[0]['statut'] !== 'active') {
        throw new UserFacingException('Carte non active');
    }

    $idCompte = (int)$account['id_compte'];
    $soldeAvant = (float)$account['solde'];

    // Lock products and compute total
    $total = 0.0;
    $productDetails = []; // id_produit => [nom, prix, stock, quantite]

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
        $prix = (float)$p['prix'];
        $total += $prix * $quantite;
        $productDetails[$idProduit] = [
            'nom' => (string)$p['nom'],
            'prix' => $prix,
            'stock' => $stock,
            'quantite' => $quantite,
        ];
    }

    if ($total <= 0) {
        throw new UserFacingException('Montant invalide');
    }

    if ($soldeAvant < $total) {
        throw new UserFacingException('Solde insuffisant');
    }

    $soldeApres = $soldeAvant - $total;
    $reference = generate_reference();

    // Insert transfer
    $stmtTr = $pdo->prepare(
        'INSERT INTO transferts (id_compte, id_terminal, montant, type, statut, description, reference, solde_avant, solde_apres)
         VALUES (:id_compte, NULL, :montant, :type, :statut, :description, :reference, :solde_avant, :solde_apres)'
    );
    $stmtTr->execute([
        ':id_compte' => $idCompte,
        ':montant' => $total,
        ':type' => 'PAIEMENT_BUVETTE',
        ':statut' => 'REUSSI',
        ':description' => 'Achat buvette (web scan)',
        ':reference' => $reference,
        ':solde_avant' => $soldeAvant,
        ':solde_apres' => $soldeApres,
    ]);

    $idTransfert = (int)$pdo->lastInsertId();

    // Insert details + update stock
    $stmtDet = $pdo->prepare(
        'INSERT INTO detail_transfert_buvette (id_transfert, id_produit, quantite, prix_unitaire)
         VALUES (:id_transfert, :id_produit, :quantite, :prix_unitaire)'
    );
    $stmtUpdStock = $pdo->prepare('UPDATE produits SET stock = stock - :qte WHERE id_produit = :id');

    foreach ($productDetails as $idProduit => $d) {
        $stmtDet->execute([
            ':id_transfert' => $idTransfert,
            ':id_produit' => (int)$idProduit,
            ':quantite' => (int)$d['quantite'],
            ':prix_unitaire' => (float)$d['prix'],
        ]);
        $stmtUpdStock->execute([':qte' => (int)$d['quantite'], ':id' => (int)$idProduit]);
    }

    // Update account balance
    $stmtUpd = $pdo->prepare('UPDATE comptes SET solde = :solde WHERE id_compte = :id');
    $stmtUpd->execute([':solde' => $soldeApres, ':id' => $idCompte]);

    $pdo->commit();

    log_security($etudiantId, 'etudiant', 'PAIEMENT_BUVETTE', 'Paiement buvette réussi ref=' . $reference . ' montant=' . number_format($total, 2, '.', ''), 'SUCCES');

    echo json_encode([
        'ok' => true,
        'reference' => $reference,
        'montant' => $total,
        'solde_avant' => $soldeAvant,
        'solde_apres' => $soldeApres,
    ]);
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
