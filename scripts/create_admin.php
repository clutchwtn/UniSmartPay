<?php
// Creates an admin account. The password is read interactively and stored
// with password_hash().
//
// Usage: php scripts/create_admin.php <email> <nom> <prenom> [super_admin|admin_faculte] [id_faculte]

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../web/includes/functions.php';

$email = $argv[1] ?? '';
$nom = $argv[2] ?? '';
$prenom = $argv[3] ?? '';
$role = $argv[4] ?? 'super_admin';
$idFaculte = isset($argv[5]) && $argv[5] !== '' ? (int)$argv[5] : null;

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $nom === '' || $prenom === ''
    || !in_array($role, ['super_admin', 'admin_faculte'], true)
    || ($role === 'admin_faculte' && $idFaculte === null)) {
    fwrite(STDERR, "Usage: php scripts/create_admin.php <email> <nom> <prenom> [super_admin|admin_faculte] [id_faculte]\n");
    fwrite(STDERR, "id_faculte is required for admin_faculte.\n");
    exit(1);
}

function read_hidden(string $prompt): string {
    echo $prompt;
    if (DIRECTORY_SEPARATOR === '\\') {
        // Windows: hide input through PowerShell.
        $cmd = 'powershell -NoProfile -Command "$p = Read-Host -AsSecureString; '
             . '[Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';
        $value = rtrim((string)shell_exec($cmd), "\r\n");
    } else {
        shell_exec('stty -echo');
        $value = rtrim((string)fgets(STDIN), "\r\n");
        shell_exec('stty echo');
        echo "\n";
    }
    return $value;
}

$password = read_hidden('Password (min 12 chars): ');
$confirm = read_hidden('Confirm password: ');

if ($password !== $confirm) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}
if (strlen($password) < 12) {
    fwrite(STDERR, "Password must be at least 12 characters.\n");
    exit(1);
}

$stmt = db()->prepare(
    'INSERT INTO admins (id_faculte, nom, prenom, email, password_hash, role)
     VALUES (:f, :n, :p, :e, :h, :r)'
);
$stmt->execute([
    ':f' => $role === 'super_admin' ? null : $idFaculte,
    ':n' => $nom,
    ':p' => $prenom,
    ':e' => $email,
    ':h' => password_hash($password, PASSWORD_DEFAULT),
    ':r' => $role,
]);

echo "Admin $email created.\n";
