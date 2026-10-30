<?php

declare(strict_types=1);

// Request and response helpers used by every page: output escaping, redirects,
// one-time flash messages, and CSRF tokens for forms.

/** Escapes text for safe output inside HTML (prevents cross-site scripting). */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

/** Stores a message shown once on the next page (see partials/header.php). */
function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/** The session's CSRF token; every form includes it in a hidden field. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Stops the request unless the submitted form carries this session's CSRF token. */
function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        // 403 rather than the non-standard 419, which Apache turns into a 500 error.
        http_response_code(403);
        exit('The form expired or was invalid. Go back, refresh, and try again.');
    }
}
