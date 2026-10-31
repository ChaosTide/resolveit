<?php

declare(strict_types=1);

/**
 * Reads the signed-in Microsoft account from the header Azure App Service's own Easy Auth
 * proxy attaches after it verifies the sign-in -- PHP never sees a request that didn't go
 * through that proxy first. Still re-checks the tenant and an explicit email allowlist
 * rather than trusting "signed in with Microsoft" alone, in case the allowed tenant ever
 * includes accounts outside this project's team.
 */
function azure_team_identity(): array
{
    $raw = base64_decode($_SERVER['HTTP_X_MS_CLIENT_PRINCIPAL'] ?? '', true);
    $principal = $raw === false ? null : json_decode($raw, true);
    $claims = [];
    foreach (($principal['claims'] ?? []) as $claim) {
        $claims[$claim['typ']] = $claim['val'];
    }
    $tenant = $claims['http://schemas.microsoft.com/identity/claims/tenantid'] ?? $claims['tid'] ?? '';
    $upn = $claims['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/upn'] ?? null;
    $email = strtolower($upn ?? $claims['preferred_username'] ?? '');
    $allowed = array_map('trim', explode(',', getenv('TEAM_EMAILS') ?: ''));
    $signedInWithEntra = ($principal['auth_typ'] ?? '') === 'aad';
    if (!$signedInWithEntra || $tenant !== getenv('TEAM_TENANT') || !in_array($email, $allowed, true)) {
        http_response_code(403);
        exit('Access is restricted to the six approved ResolveIT team members.');
    }
    return ['email' => $email, 'name' => $claims['name'] ?? $email];
}

/** Signs in the ResolveIT account matching the verified Microsoft identity's email. */
function azure_team_login(PDO $pdo, array $identity): void
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, u.email, u.active, r.name AS role
         FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.email = ?'
    );
    $stmt->execute([$identity['email']]);
    $user = $stmt->fetch();
    if (!$user || !$user['active']) {
        http_response_code(403);
        exit('Your team account is not active. Contact Jordan.');
    }
    if (($_SESSION['user']['id'] ?? null) !== (int) $user['id']) {
        session_regenerate_id(true);
    }
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];
}
