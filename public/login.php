<?php
// The sign-in page: the only page an anonymous visitor can POST to (see app/http.php's
// verify_csrf() for why that makes this page special), and the entry point for the whole
// authentication flow described in app/auth.php.
require_once __DIR__ . '/../app/bootstrap.php';
if (current_user()) {
    redirect('dashboard.php');
}
// Azure: sign-in is Microsoft-only (see app/azure-auth.php) -- the password form below
// can never work there (team accounts get an unusable random password on creation), so
// send the visitor straight to Microsoft sign-in instead of showing a dead-end form.
if (getenv('APP_ENV') === 'azure') {
    require __DIR__ . '/partials/header.php';
    ?>
    <section class="card auth-card">
        <h1>Sign in</h1>
        <p>This is the hosted ResolveIT site for the CTS 4911 team. Sign in with your
            <strong>@students.uwf.edu</strong> Microsoft account.</p>
        <a class="button" style="display:block;text-align:center;width:100%"
            href="/.auth/login/aad?post_login_redirect_uri=%2Fdashboard.php">Sign in with Microsoft</a>
    </section>
    <?php
    require __DIR__ . '/partials/footer.php';
    return;
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
<section class="card auth-card">
    <h1>Sign in</h1>
    <?php foreach ($errors as $error) : ?>
        <p class="errors" role="alert"><?= e($error) ?></p>
    <?php endforeach; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label for="email">Email</label>
        <div class="field-icon"><?= icon('mail') ?>
            <input id="email" name="email" type="email" maxlength="190" autocomplete="username"
                value="<?= e($email ?? '') ?>" required autofocus>
        </div>
        <label for="password">Password</label>
        <div class="field-icon password-field"><?= icon('lock') ?>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <button type="submit" style="width:100%">Sign In<?= icon('arrow-right') ?></button>
    </form>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
