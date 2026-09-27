<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
$categories = $pdo->query('SELECT id, name FROM categories WHERE active = 1 ORDER BY name')->fetchAll();
$errors = [];
$data = ['title' => '', 'description' => '', 'category_id' => null];
// Arriving from a knowledge base article: note which article the requester already tried.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($kbId = valid_id($_GET['kb'] ?? null))) {
    $kb = $pdo->prepare('SELECT title, category_id FROM kb_articles WHERE id = ? AND published = 1');
    $kb->execute([$kbId]);
    if ($kbRow = $kb->fetch()) {
        $data['description'] = "I tried the knowledge base article \"{$kbRow['title']}\" but the problem is not fixed.

";
        $data['category_id'] = $kbRow['category_id'] === null ? null : (int) $kbRow['category_id'];
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    [$data, $errors] = validate_ticket_input($_POST);
    $check = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = ? AND active = 1');
    $check->execute([$data['category_id']]);
    if (!$check->fetchColumn()) { $errors[] = 'The selected category is unavailable.'; }
    if (!$errors) {
        $openId = (int) $pdo->query("SELECT id FROM statuses WHERE name = 'Open'")->fetchColumn();
        // Default priority is the second level (Medium) by sort order, so renaming it in Settings is safe.
        $mediumId = (int) $pdo->query('SELECT id FROM priorities WHERE sort_order = 2')->fetchColumn();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO tickets (requester_id, category_id, priority_id, status_id, title, description) OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([current_user()['id'], $data['category_id'], $mediumId, $openId, $data['title'], $data['description']]);
        $ticketId = (int) $stmt->fetchColumn();
        $history = $pdo->prepare("INSERT INTO ticket_history (ticket_id, changed_by, action, new_value) VALUES (?, ?, 'created', 'Open')");
        $history->execute([$ticketId, current_user()['id']]);
        $pdo->commit();
        flash('success', 'Ticket created successfully.');
        redirect('ticket.php?id=' . $ticketId);
    }
}
$pageTitle = 'Create Ticket'; require __DIR__ . '/partials/header.php';
?>
<div class="page-header">
    <div>
        <h1>Create a support ticket</h1>
        <p class="subtitle">Tell the IT help desk what is wrong. A technician will pick it up and keep you posted here.</p>
    </div>
</div>
<div class="two-col">
<section class="card">
<?php foreach ($errors as $error): ?><p class="errors" role="alert"><?= e($error) ?></p><?php endforeach; ?>
<form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <label for="title">Short title</label><input id="title" name="title" maxlength="150" value="<?= e($data['title']) ?>" placeholder="e.g. Laptop will not connect to campus Wi-Fi" required>
    <label for="category">Category</label><select id="category" name="category_id" required><option value="">Choose one</option>
        <?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= (int) $data['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?>
    </select>
    <label for="description">Problem description</label><textarea id="description" name="description" maxlength="5000" minlength="10" placeholder="What happened, when it started, and any error messages you saw." required><?= e($data['description']) ?></textarea>
    <p class="field-hint">At least 10 characters.</p>
    <div class="form-actions"><button type="submit"><?= icon('plus') ?>Submit Ticket</button></div>
</form>
</section>
<aside class="card">
    <h2>Check the Knowledge Base first</h2>
    <p class="meta">Many common problems (passwords, Wi-Fi, printers) have step-by-step fixes.</p>
    <p><a class="button secondary small" href="kb.php"><?= icon('book') ?>Browse articles</a></p>
    <h2>Tips for a fast fix</h2>
    <ul class="meta">
        <li>Include the exact error message or a description of what you see.</li>
        <li>Say which device, room, or account is affected.</li>
        <li>Mention anything you already tried.</li>
    </ul>
    <p class="meta">New tickets start as <strong>Open</strong> with <strong>Medium</strong> priority. IT staff may change the priority, which sets the response target.</p>
</aside>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
