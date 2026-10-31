<?php
// The sign-in page: the only page an anonymous visitor can POST to (see app/http.php's
// verify_csrf() for why that makes this page special), and the entry point for the whole
// authentication flow described in app/auth.php.
require_once __DIR__ . '/../app/bootstrap.php';
if (current_user()) {
    redirect('dashboard.php');
}
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = clean_text($_POST['email'] ?? '');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $normalizedEmail = mb_strtolower($email);
    $ip = client_ip();
    // Checked in this order: input shape first, then rate limit, then the real
    // credential check -- so a locked-out attacker never even reaches password_verify().
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $errors[] = 'Enter a valid email and password.';
    } elseif (login_locked_out($pdo, $normalizedEmail, $ip)) {
        $errors[] = 'Too many failed sign-in attempts. Wait 15 minutes and try again.';
    } elseif (!login_user($pdo, $email, $password)) {
        record_login_attempt($pdo, $normalizedEmail, $ip, false);
        // Deliberately the same message for "no such account" and "wrong password" --
        // telling them apart would let an attacker find out which emails have accounts.
        $errors[] = 'The email or password is incorrect, or the account is inactive.';
    } else {
        record_login_attempt($pdo, $normalizedEmail, $ip, true);
        redirect('dashboard.php');
    }
}
$pageTitle = 'Sign In';
require __DIR__ . '/partials/header.php';
?>
<section class="card">
    <h1>Sign in</h1>
    <p class="meta">The ResolveIT IT help desk. Use the email and password your administrator gave you.</p>
    <?php foreach ($errors as $error) : ?>
        <p class="errors" role="alert"><?= e($error) ?></p>
    <?php endforeach; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" maxlength="190" autocomplete="username"
            value="<?= e($email ?? '') ?>" required autofocus>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button type="submit" style="width:100%">Sign In</button>
    </form>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
