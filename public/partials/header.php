<?php
// Fragments loaded into the split-view pane (ticket.php?partial=1) render without the page layout.
if (!empty($partialView)) {
    return;
}
$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$currentUser = current_user();
$navActive = fn (array $pages) => in_array($currentPage, $pages, true) ? ' aria-current="page"' : '';
$topbarQuery = $currentPage === 'tickets.php' ? (string) ($_GET['q'] ?? '') : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'ResolveIT') ?> · ResolveIT</title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="assets/app.js" defer></script>
</head>
<?php if (!$currentUser) : ?>
<body class="auth-layout">
<header class="auth-brand"><span class="logo" aria-hidden="true"><?= icon('tickets') ?></span> ResolveIT</header>
<main class="auth-main">
<?php else : ?>
<body class="app-layout">
<a class="skip-link" href="#content">Skip to content</a>
<aside class="sidebar" id="sidebar">
    <a class="brand" href="index.php">
        <span class="logo" aria-hidden="true"><?= icon('tickets') ?></span><span>ResolveIT</span>
    </a>
    <nav aria-label="Main navigation">
        <a href="dashboard.php"<?= $navActive(['dashboard.php']) ?>><?= icon('dashboard') ?>Dashboard</a>
        <a href="tickets.php"<?= $navActive(['tickets.php', 'ticket.php', 'ticket-edit.php']) ?>>
            <?= icon('tickets') ?><?= has_role('employee') ? 'My Tickets' : 'Tickets' ?>
        </a>
        <a href="ticket-create.php"<?= $navActive(['ticket-create.php']) ?>><?= icon('plus') ?>New Ticket</a>
        <a href="kb.php"<?= $navActive(['kb.php', 'kb-article.php', 'kb-edit.php']) ?>>
            <?= icon('book') ?>Knowledge Base
        </a>
        <?php if (has_role('administrator')) : ?>
            <p class="nav-heading">Administration</p>
            <a href="users.php"<?= $navActive(['users.php', 'user-edit.php']) ?>>
                <?= icon('users') ?>Manage Users
            </a>
            <a href="reports.php"<?= $navActive(['reports.php']) ?>><?= icon('chart') ?>Reports</a>
            <a href="settings.php"<?= $navActive(['settings.php']) ?>><?= icon('settings') ?>Settings</a>
        <?php endif; ?>
    </nav>
    <div class="sidebar-footer">ResolveIT &middot; CTS 4911 capstone</div>
</aside>
<div class="app-main">
    <header class="topbar">
        <button type="button" class="icon-button menu-toggle" aria-controls="sidebar" aria-expanded="false"
            aria-label="Open menu"><?= icon('menu') ?></button>
        <form class="topbar-search" action="tickets.php" method="get" role="search">
            <?= icon('search') ?>
            <input type="search" name="q" placeholder="Search tickets by ID, title, or description"
                aria-label="Search tickets" value="<?= e($topbarQuery) ?>">
        </form>
        <div class="topbar-user">
            <?= avatar($currentUser['name']) ?>
            <span class="user-meta">
                <strong><?= e($currentUser['name']) ?></strong>
                <span class="role-tag"><?= e(ucfirst($currentUser['role'])) ?></span>
            </span>
            <a class="icon-button" href="logout.php" title="Sign out" aria-label="Sign out"><?= icon('logout') ?></a>
        </div>
    </header>
    <main class="content" id="content">
<?php endif; ?>
<?php if ($flashMessage) : ?>
    <div class="alert <?= e($flashMessage['type']) ?>" role="status">
        <?= icon($flashMessage['type'] === 'success' ? 'check' : 'alert') ?>
        <span><?= e($flashMessage['message']) ?></span>
    </div>
<?php endif; ?>
