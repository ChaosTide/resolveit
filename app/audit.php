<?php
declare(strict_types=1);

// Records an administrator action in admin_audit_log (shown on settings.php?tab=audit).
function audit_log(PDO $pdo, string $action, ?string $target = null, ?string $details = null): void
{
    $stmt = $pdo->prepare('INSERT INTO admin_audit_log (admin_id, action, target, details) VALUES (?, ?, ?, ?)');
    $stmt->execute([
        current_user()['id'],
        mb_substr($action, 0, 60),
        $target === null ? null : mb_substr($target, 0, 190),
        $details === null ? null : mb_substr($details, 0, 500),
    ]);
}
