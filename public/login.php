<?php
require_once __DIR__ . '/../app/bootstrap.php';
if (current_user()) { redirect('tickets.php'); }
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = clean_text($_POST['email'] ?? '');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $normalizedEmail = mb_strtolower($email);
    $ip = client_ip();
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $errors[] = 'Enter a valid email and password.';
    } elseif (login_locked_out($pdo, $normalizedEmail, $ip)) {
        $errors[] = 'Too many failed sign-in attempts. Wait 15 minutes and try again.';
    } elseif (!login_user($pdo, $email, $password)) {
        record_login_attempt($pdo, $normalizedEmail, $ip, false);
        $errors[] = 'The email or password is incorrect, or the account is inactive.';
    } else {
        record_login_attempt($pdo, $normalizedEmail, $ip, true);
        redirect('tickets.php');
    }
}
$pageTitle = 'Sign In';
require __DIR__ . '/partials/header.php';
?>
<section class="card" style="max-width: 520px; margin-inline: auto">
    <h1>Sign in</h1>
    <?php foreach ($errors as $error): ?><p class="errors"><?= e($error) ?></p><?php endforeach; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" maxlength="190" autocomplete="username" required>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button type="submit">Sign In</button>
    </form>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>

