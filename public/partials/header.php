<?php $flashMessage = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'IT Ticket System') ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="site-header">
    <a class="brand" href="index.php">CTS 4911 Ticket System</a>
    <?php if (current_user()): ?>
        <nav aria-label="Main navigation">
            <a href="tickets.php">Tickets</a>
            <a href="ticket-create.php">New Ticket</a>
            <?php if (has_role('administrator')): ?><a href="users.php">Manage Users</a><?php endif; ?>
            <a href="logout.php">Sign Out</a>
        </nav>
    <?php endif; ?>
</header>
<main class="container">
<?php if ($flashMessage): ?>
    <p class="alert <?= e($flashMessage['type']) ?>" role="status"><?= e($flashMessage['message']) ?></p>
<?php endif; ?>

