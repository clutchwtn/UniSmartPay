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

$terminalId = (int)($payload['terminal_id'] ?? 0);
$items = $payload['items'] ?? null;

if ($terminalId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Terminal requis']);
    exit();
}

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

try {
    $pdo = db();

    // Check terminal
    $stmtT = $pdo->prepare('SELECT id_terminal, type, statut FROM terminaux WHERE id_terminal = :id LIMIT 1');
    $stmtT->execute([':id' => $terminalId]);
    $terminal = $stmtT->fetch();

    if (!$terminal || (string)$terminal['statut'] !== 'actif') {
        throw new UserFacingException('Terminal invalide');
    }
    if ((string)$terminal['type'] !== 'BUVETTE') {
        throw new UserFacingException('Terminal non configuré pour la buvette');
    }

    // Compute total based on DB prices (no stock reservation here)
    $total = 0.0;
    $prices = [];
    $stmtP = $pdo->prepare('SELECT id_produit, prix, actif FROM produits WHERE id_produit = :id LIMIT 1');
    foreach ($normalized as $idProduit => $quantite) {
        $stmtP->execute([':id' => $idProduit]);
        $p = $stmtP->fetch();
        if (!$p || (int)$p['actif'] !== 1) {
            throw new UserFacingException('Produit introuvable');
        }
        $prix = (float)$p['prix'];
        $prices[$idProduit] = $prix;
        $total += $prix * $quantite;
    }

    if ($total <= 0) {
        throw new UserFacingException('Montant invalide');
    }

    $itemsJson = json_encode(array_map(
        fn ($id) => ['id_produit' => (int)$id, 'quantite' => (int)$normalized[$id], 'prix_unitaire' => $prices[$id]],
        array_keys($normalized)
    ), JSON_UNESCAPED_UNICODE);

    $expiresAt = (new DateTimeImmutable('now'))->modify('+5 minutes')->format('Y-m-d H:i:s');

    $pdo->beginTransaction();

    // Only one order may wait per terminal: cancel anything still queued.
    $pdo->prepare('UPDATE terminal_orders SET statut = "ANNULE", message_erreur = "Remplacée par une nouvelle commande" WHERE id_terminal = :id AND statut IN ("PENDING", "IN_PROGRESS")')
        ->execute([':id' => $terminalId]);

    $stmt = $pdo->prepare(
        'INSERT INTO terminal_orders (id_terminal, mode, statut, montant, items_json, expires_at, created_by_admin)
         VALUES (:id_terminal, "BUVETTE", "PENDING", :montant, :items_json, :expires_at, :created_by_admin)'
    );
    $stmt->execute([
        ':id_terminal' => $terminalId,
        ':montant' => $total,
        ':items_json' => $itemsJson,
        ':expires_at' => $expiresAt,
        ':created_by_admin' => (int)$_SESSION['admin_id'],
    ]);
    $orderId = (int)$pdo->lastInsertId();

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'order_id' => $orderId,
        'montant' => $total,
        'expires_at' => $expiresAt,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    $msg = $e instanceof UserFacingException ? $e->getMessage() : 'Erreur serveur';
    if (!($e instanceof UserFacingException)) {
        error_log('terminal_order_create_buvette error: ' . $e->getMessage());
    }
    echo json_encode(['ok' => false, 'error' => $msg]);
}
