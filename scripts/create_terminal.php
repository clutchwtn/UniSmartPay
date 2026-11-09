<?php
// Creates a terminal with a random token. The token is printed ONCE;
// only its SHA-256 hash is stored in the database.
//
// Usage: php scripts/create_terminal.php <nom> <RESTO|BUVETTE|RECHARGE> [id_faculte] [localisation]

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../web/includes/functions.php';

$nom = $argv[1] ?? '';
$type = strtoupper($argv[2] ?? '');
$idFaculte = isset($argv[3]) && $argv[3] !== '' ? (int)$argv[3] : null;
$localisation = $argv[4] ?? null;

if ($nom === '' || !in_array($type, ['RESTO', 'BUVETTE', 'RECHARGE'], true)) {
    fwrite(STDERR, "Usage: php scripts/create_terminal.php <nom> <RESTO|BUVETTE|RECHARGE> [id_faculte] [localisation]\n");
    exit(1);
}

$token = bin2hex(random_bytes(32));

$stmt = db()->prepare(
    'INSERT INTO terminaux (id_faculte, nom, type, localisation, token_auth, statut)
     VALUES (:f, :n, :t, :l, :h, \'actif\')'
);
$stmt->execute([
    ':f' => $idFaculte,
    ':n' => $nom,
    ':t' => $type,
    ':l' => $localisation,
    ':h' => hash('sha256', $token),
]);

echo "Terminal created (id " . db()->lastInsertId() . ").\n";
echo "Token (shown once, store it in the device firmware now):\n";
echo $token . "\n";
