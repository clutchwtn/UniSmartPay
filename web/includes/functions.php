<?php
// UniSmart Pay utility functions

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * An exception whose message was written on purpose for the end user.
 * Handlers may return its message as-is; any other Throwable (PDO, etc.)
 * must be logged and answered with a generic error.
 */
class UserFacingException extends RuntimeException {}

function csrf_token(): string {
    if (empty($_SESSION[CSRF_SESSION_KEY])) {
        $_SESSION[CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_SESSION_KEY];
}

function require_csrf_token(string $token): void {
    $sessionToken = $_SESSION[CSRF_SESSION_KEY] ?? '';
    if (!$token || !$sessionToken || !hash_equals($sessionToken, $token)) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
}

function uid_candidates(string $uid): array {
    $uid = strtoupper(trim($uid));
    $candidates = [$uid];

    if (strpos($uid, ':') === false && preg_match('/^[0-9A-F]+$/', $uid) && strlen($uid) % 2 === 0) {
        $candidates[] = implode(':', str_split($uid, 2));
    }

    return array_values(array_unique($candidates));
}

function terminal_token(): string {
    return trim((string)($_SERVER['HTTP_X_TERMINAL_TOKEN'] ?? ''));
}

function hash_terminal_token(string $token): string {
    return hash('sha256', $token);
}

function require_terminal(PDO $pdo, string $token, ?int $terminalId = null, bool $forUpdate = false): array {
    if ($token === '') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Terminal authentication required']);
        exit();
    }

    $sql = 'SELECT id_terminal, id_faculte, nom, type, statut
            FROM terminaux
            WHERE token_auth = :token';
    $params = [':token' => hash_terminal_token($token)];

    if ($terminalId !== null) {
        $sql .= ' AND id_terminal = :terminal_id';
        $params[':terminal_id'] = $terminalId;
    }

    $sql .= ' LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $terminal = $stmt->fetch();

    if (!$terminal || (string)$terminal['statut'] !== 'actif') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Terminal not authorized']);
        exit();
    }

    return $terminal;
}

function is_hex_sha256(string $hash): bool {
    return (bool)preg_match('/^[a-f0-9]{64}$/i', $hash);
}

function verify_password(string $plainPassword, string $storedHash): bool {
    $storedHash = trim($storedHash);

    // PHP password_hash() output (bcrypt or Argon2).
    if (str_starts_with($storedHash, '$2y$') || str_starts_with($storedHash, '$argon2')) {
        return password_verify($plainPassword, $storedHash);
    }

    // SHA-256 hex output from the database seed data.
    if (is_hex_sha256($storedHash)) {
        $computed = hash('sha256', $plainPassword);
        return hash_equals(strtolower($storedHash), strtolower($computed));
    }

    return false;
}

// Called after a successful login: re-hash legacy (SHA-256) or outdated hashes with password_hash().
// $table and $idColumn are code constants, never user input.
function upgrade_password_hash(PDO $pdo, string $table, string $idColumn, int $id, string $plainPassword, string $storedHash): void {
    $storedHash = trim($storedHash);
    $isModern = str_starts_with($storedHash, '$2y$') || str_starts_with($storedHash, '$argon2');
    if ($isModern && !password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
        return;
    }

    $stmt = $pdo->prepare("UPDATE {$table} SET password_hash = :hash WHERE {$idColumn} = :id");
    $stmt->execute([
        ':hash' => password_hash($plainPassword, PASSWORD_DEFAULT),
        ':id' => $id,
    ]);
}

function require_student_login(): void {
    if (empty($_SESSION['etudiant_id'])) {
        header('Location: ' . BASE_URL . '/etudiant/login.php');
        exit();
    }

    if (!empty($_SESSION['login_time']) && (time() - (int)$_SESSION['login_time'] > SESSION_DURATION)) {
        session_destroy();
        header('Location: ' . BASE_URL . '/etudiant/login.php?expired=1');
        exit();
    }

    $_SESSION['login_time'] = time();
}

function require_admin_login(): void {
    if (empty($_SESSION['admin_id'])) {
        header('Location: ' . BASE_URL . '/admin/login.php');
        exit();
    }

    if (!empty($_SESSION['login_time']) && (time() - (int)$_SESSION['login_time'] > SESSION_DURATION)) {
        session_destroy();
        header('Location: ' . BASE_URL . '/admin/login.php?expired=1');
        exit();
    }

    $_SESSION['login_time'] = time();
}

// JSON counterpart of require_admin_login(): same session and timeout checks,
// but answers 401 + JSON instead of redirecting (for use by API endpoints).
function require_admin_api(): void {
    $fail = static function (string $error): void {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $error]);
        exit();
    };

    if (empty($_SESSION['admin_id'])) {
        $fail('Authentification requise');
    }

    if (!empty($_SESSION['login_time']) && (time() - (int)$_SESSION['login_time'] > SESSION_DURATION)) {
        session_destroy();
        $fail('Session expirée');
    }

    $_SESSION['login_time'] = time();
}

function log_security(?int $idUser, string $typeUser, string $action, string $details, string $statut = 'SUCCES'): void {
    try {
        $pdo = db();
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $pdo->prepare(
            'INSERT INTO logs_securite (id_user, type_user, action, details, ip_address, statut) VALUES (:id_user, :type_user, :action, :details, :ip, :statut)'
        );
        $stmt->execute([
            ':id_user' => $idUser,
            ':type_user' => $typeUser,
            ':action' => $action,
            ':details' => $details,
            ':ip' => $ip,
            ':statut' => $statut,
        ]);
    } catch (Throwable $e) {
        // Logging failures must not break the request.
        error_log('log_security error: ' . $e->getMessage());
    }
}

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW_SECONDS = 900; // 15 minutes

function login_identifier(string $identifier): string {
    return substr(strtolower(trim($identifier)), 0, 190);
}

// True when the account or the client IP has too many recent failed logins.
function login_is_locked(string $typeUser, string $identifier): bool {
    $stmt = db()->prepare(
        'SELECT COALESCE(SUM(type_user = :type AND identifier = :ident), 0) AS by_account,
                COALESCE(SUM(ip_address = :ip), 0) AS by_ip
         FROM login_attempts
         WHERE attempted_at > (NOW() - INTERVAL ' . LOGIN_WINDOW_SECONDS . ' SECOND)'
    );
    $stmt->execute([
        ':type' => $typeUser,
        ':ident' => login_identifier($identifier),
        ':ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    ]);
    $row = $stmt->fetch();
    return (int)$row['by_account'] >= LOGIN_MAX_ATTEMPTS || (int)$row['by_ip'] >= LOGIN_MAX_ATTEMPTS;
}

function login_record_failure(string $typeUser, string $identifier): void {
    $pdo = db();
    $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)')->execute();
    $pdo->prepare('INSERT INTO login_attempts (type_user, identifier, ip_address) VALUES (:type, :ident, :ip)')
        ->execute([
            ':type' => $typeUser,
            ':ident' => login_identifier($identifier),
            ':ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
}

function generate_reference(): string {
    return date('YmdHis') . random_int(1000, 9999);
}

// ---------------------------------------------------------------- money
// Balances and prices are DECIMAL(10,2). PHP does all arithmetic on integer
// millimes (1 DT = 1000 millimes, so 12.34 DT = 12340) and writes the result
// back as a 2-decimal string. Floats are never used for money.

// Parses a decimal amount ("12.5", "12.34", 12.34) into millimes.
// Returns null when the value is not a decimal with at most 2 decimals.
function money_parse(mixed $value): ?int {
    if (!is_scalar($value)) {
        return null;
    }
    if (!preg_match('/^(-?)(\d{1,8})(?:\.(\d{1,2}))?$/', trim((string)$value), $m)) {
        return null;
    }
    $cents = (isset($m[3]) && $m[3] !== '') ? (int)str_pad($m[3], 2, '0') : 0;
    $millimes = ((int)$m[2] * 1000) + ($cents * 10);
    return $m[1] === '-' ? -$millimes : $millimes;
}

// Same as money_parse() for values read from the database; throws when invalid.
function money_to_millimes(mixed $value): int {
    return money_parse($value) ?? throw new UnexpectedValueException('Montant invalide');
}

// Millimes -> float for JSON responses (ESP32 and web clients keep numeric fields).
function millimes_to_dt(int $millimes): float {
    return (float)$millimes / 1000;
}

// Millimes -> 2-decimal string for DECIMAL(10,2) columns, e.g. 12340 -> "12.34".
function millimes_to_db(int $millimes): string {
    if ($millimes % 10 !== 0) {
        throw new UnexpectedValueException('Montant non représentable en centimes');
    }
    $sign = $millimes < 0 ? '-' : '';
    $abs = abs($millimes);
    return sprintf('%s%d.%02d', $sign, intdiv($abs, 1000), intdiv($abs % 1000, 10));
}

// Debits an account in SQL. The WHERE clause refuses to go below zero, and the
// caller must already hold the row lock (SELECT ... FOR UPDATE).
// Returns false when the balance was insufficient (no row changed).
function account_debit(PDO $pdo, int $idCompte, int $millimes): bool {
    $stmt = $pdo->prepare(
        'UPDATE comptes
         SET solde = solde - CAST(:debit AS DECIMAL(10,2))
         WHERE id_compte = :id AND solde >= CAST(:min AS DECIMAL(10,2))'
    );
    $amount = millimes_to_db($millimes);
    $stmt->execute([':debit' => $amount, ':min' => $amount, ':id' => $idCompte]);
    return $stmt->rowCount() === 1;
}

function mask_uid(string $uid): string {
    // Example: AA:BB:CC:DD -> AA:BB:**:**
    $parts = explode(':', $uid);
    if (count($parts) >= 4) {
        $parts[2] = '**';
        $parts[3] = '**';
        return implode(':', $parts);
    }
    if (strlen($uid) <= 4) {
        return str_repeat('*', strlen($uid));
    }
    return substr($uid, 0, 4) . str_repeat('*', max(0, strlen($uid) - 4));
}
