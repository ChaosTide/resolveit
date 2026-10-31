<?php

declare(strict_types=1);

/** The signed-in user's id, name, email, and role, or null if nobody is signed in. */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

// Sign-in lockout: 5 wrong passwords for one email, or 20 from one address, within 15 minutes.
const LOGIN_WINDOW_MINUTES = 15;
const LOGIN_MAX_FAILURES_PER_EMAIL = 5;
const LOGIN_MAX_FAILURES_PER_IP = 20;

/** Visitor's address. Behind the local Cloudflare tunnel every request comes from loopback, so use Cloudflare's header there. */
function client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $forwarded = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if (in_array($remote, ['127.0.0.1', '::1'], true) && filter_var($forwarded, FILTER_VALIDATE_IP)) {
        return $forwarded;
    }
    return $remote;
}

/**
 * True if this email or this network address has failed to sign in too many times
 * recently (brute-force protection). Counts reset automatically after LOGIN_WINDOW_MINUTES.
 */
function login_locked_out(PDO $pdo, string $email, string $ip): bool
{
    // Failures for an email only count after its most recent successful sign-in.
    $window = -LOGIN_WINDOW_MINUTES;
    $stmt = $pdo->prepare("SELECT
        (SELECT COUNT(*) FROM login_attempts WHERE email = ? AND succeeded = 0
            AND created_at > DATEADD(minute, $window, SYSDATETIME())
            AND created_at > ISNULL(
                (SELECT MAX(created_at) FROM login_attempts WHERE email = ? AND succeeded = 1),
                '19000101'
            )) AS email_failures,
        (SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND succeeded = 0
            AND created_at > DATEADD(minute, $window, SYSDATETIME())) AS ip_failures");
    $stmt->execute([$email, $email, $ip]);
    $counts = $stmt->fetch();
    return (int) $counts['email_failures'] >= LOGIN_MAX_FAILURES_PER_EMAIL
        || (int) $counts['ip_failures'] >= LOGIN_MAX_FAILURES_PER_IP;
}

/** Logs one sign-in attempt so login_locked_out() can see recent history for this email/address. */
function record_login_attempt(PDO $pdo, string $email, string $ip, bool $succeeded): void
{
    $pdo->prepare('INSERT INTO login_attempts (email, ip, succeeded) VALUES (?, ?, ?)')
        ->execute([mb_substr($email, 0, 190), mb_substr($ip, 0, 45), $succeeded ? 1 : 0]);
}

/**
 * Checks an email/password against the stored hash and, on success, starts the session.
 * Returns false (never an exception) for a wrong email or wrong password, so callers can't
 * tell which one was wrong -- that would help an attacker guess valid emails.
 */
function login_user(PDO $pdo, string $email, string $password): bool
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, u.email, u.password_hash, r.name AS role
         FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.email = ? AND u.active = 1'
    );
    $stmt->execute([mb_strtolower(trim($email))]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }
    // A fresh session id on every successful sign-in stops an old/stolen session id
    // from being reused to hijack this login (session fixation).
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];
    $_SESSION['login_at'] = date('Y-m-d H:i:s');
    return true;
}

/**
 * Re-reads the signed-in user on every request so a deactivation, password reset,
 * or role change by an administrator takes effect immediately instead of at next sign-in.
 */
function refresh_session_user(PDO $pdo): void
{
    if (!isset($_SESSION['user']['id'])) {
        return;
    }
    $stmt = $pdo->prepare('SELECT u.name, u.email, u.active, u.password_changed_at, r.name AS role
        FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
    $stmt->execute([$_SESSION['user']['id']]);
    $user = $stmt->fetch();
    $passwordChanged = $user && $user['password_changed_at'] !== null
        && $user['password_changed_at'] > ($_SESSION['login_at'] ?? '1900-01-01 00:00:00');
    if (!$user || !$user['active'] || $passwordChanged) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('error', 'You have been signed out because your account was changed. Please sign in again.');
        return;
    }
    $_SESSION['user'] = [
        'id' => (int) $_SESSION['user']['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];
}

/** Sends an anonymous visitor to the sign-in page. Every protected page calls this first. */
function require_login(): void
{
    if (!current_user()) {
        flash('error', 'Please sign in to continue.');
        redirect('login.php');
    }
}

/** True if someone is signed in and their role is one of the given roles. */
function has_role(string ...$roles): bool
{
    $user = current_user();
    return $user !== null && in_array($user['role'], $roles, true);
}

/**
 * The access-control gate: require_login() first, then stop the request with 403 if
 * the signed-in user's role is not allowed. Called as the first line of every page
 * that should not be open to every role, so the check can never be skipped by accident.
 */
function require_role(string ...$roles): void
{
    require_login();
    if (!has_role(...$roles)) {
        http_response_code(403);
        exit('403 Forbidden: your role does not permit this action.');
    }
}

/** Employees may only see their own tickets; technicians and administrators may see every ticket. */
function can_view_ticket(array $ticket): bool
{
    $user = current_user();
    return $user !== null && ($user['role'] !== 'employee' || (int) $ticket['requester_id'] === (int) $user['id']);
}
