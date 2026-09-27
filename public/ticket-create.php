<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
$categories = $pdo->query('SELECT id, name FROM categories WHERE active = 1 ORDER BY name')->fetchAll();
$errors = [];
$data = ['title' => '', 'description' => '', 'category_id' => null];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    [$data, $errors] = validate_ticket_input($_POST);
    $check = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = ? AND active = 1');
    $check->execute([$data['category_id']]);
    if (!$check->fetchColumn()) { $errors[] = 'The selected category is unavailable.'; }
    if (!$errors) {
        $openId = (int) $pdo->query("SELECT id FROM statuses WHERE name = 'Open'")->fetchColumn();
        $mediumId = (int) $pdo->query("SELECT id FROM priorities WHERE name = 'Medium'")->fetchColumn();
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
<section class="card">
<h1>Create a support ticket</h1>
<?php foreach ($errors as $error): ?><p class="errors"><?= e($error) ?></p><?php endforeach; ?>
<form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <label for="title">Short title</label><input id="title" name="title" maxlength="150" value="<?= e($data['title']) ?>" required>
    <label for="category">Category</label><select id="category" name="category_id" required><option value="">Choose one</option>
        <?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= (int) $data['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?>
    </select>
    <label for="description">Problem description</label><textarea id="description" name="description" maxlength="5000" required><?= e($data['description']) ?></textarea>
    <button type="submit">Submit Ticket</button>
</form>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
