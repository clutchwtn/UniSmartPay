<?php
// Lightweight endpoint used by ESP32 to validate a card before attempting payment.
// Expected JSON: {"uid":"AABBCCDD"} or {"card_uid":"AABBCCDD"}
// Response: {ok:true, carte_statut:"active", solde:12.34, etudiant:{...}}

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

$uid = strtoupper(trim((string)($payload['uid'] ?? $payload['card_uid'] ?? '')));
if ($uid === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'uid requis']);
    exit();
}

try {
    $pdo = db();

    $cands = uid_candidates($uid);
    $in = implode(',', array_fill(0, count($cands), '?'));

    $stmt = $pdo->prepare(
        "SELECT e.matricule, e.nom, e.prenom, ca.statut AS carte_statut, c.solde
         FROM cartes ca
         JOIN etudiants e ON e.id_etudiant = ca.id_etudiant
         JOIN comptes c ON c.id_etudiant = e.id_etudiant
         WHERE ca.uid_rfid IN ($in)
         LIMIT 1"
    );
    $stmt->execute($cands);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Carte introuvable']);
        exit();
    }

    echo json_encode([
        'ok' => true,
        'carte_statut' => (string)$row['carte_statut'],
        'solde' => (float)$row['solde'],
        'etudiant' => [
            'matricule' => (string)$row['matricule'],
            'nom' => (string)$row['nom'],
            'prenom' => (string)$row['prenom'],
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('card_check.php error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur']);
}
