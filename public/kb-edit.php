<?php
// Create or edit a knowledge base article (technicians and administrators).
require_once __DIR__ . '/../app/bootstrap.php';
require_role('technician', 'administrator');
$id = valid_id($_GET['id'] ?? $_POST['id'] ?? null);
$categories = $pdo->query('SELECT id, name FROM categories WHERE active = 1 ORDER BY name')->fetchAll();
$article = ['title' => '', 'body' => '', 'category_id' => null, 'published' => 1];
if ($id) {
    $stmt = $pdo->prepare('SELECT id, title, body, category_id, published FROM kb_articles WHERE id = ?');
    $stmt->execute([$id]);
    $article = $stmt->fetch();
    if (!$article) {
        http_response_code(404);
        exit('Article not found.');
    }
}
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $article = [
        'title' => clean_text($_POST['title'] ?? ''),
        'body' => clean_text($_POST['body'] ?? ''),
        'category_id' => valid_id($_POST['category_id'] ?? null),
        'published' => isset($_POST['published']) ? 1 : 0,
    ];
    $categoryIds = array_map('intval', array_column($categories, 'id'));
    if ($article['title'] === '' || mb_strlen($article['title']) > 200) {
        $errors[] = 'A title is required (200 characters or fewer).';
    }
    if (mb_strlen($article['body']) < 20 || mb_strlen($article['body']) > 20000) {
        $errors[] = 'The article must be between 20 and 20,000 characters.';
    }
    if ($article['category_id'] && !in_array($article['category_id'], $categoryIds, true)) {
        $errors[] = 'Choose an active category.';
    }
    if (!$errors) {
        $values = [$article['title'], $article['body'], $article['category_id'], $article['published']];
        if ($id) {
            $pdo->prepare('UPDATE kb_articles
                SET title = ?, body = ?, category_id = ?, published = ?, updated_at = SYSDATETIME() WHERE id = ?')
                ->execute([...$values, $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO kb_articles (title, body, category_id, published, author_id)
                OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([...$values, current_user()['id']]);
            $id = (int) $stmt->fetchColumn();
        }
        flash('success', $article['published'] ? 'Article published.' : 'Draft saved.');
        redirect('kb-article.php?id=' . $id);
    }
}
$pageTitle = $id ? 'Edit Article' : 'New Article';
require __DIR__ . '/partials/header.php';
?>
<div class="page-header">
    <div>
        <p class="breadcrumb"><a href="kb.php">Knowledge Base</a> / <?= $id ? 'Edit' : 'New' ?></p>
        <h1><?= $id ? 'Edit article' : 'New article' ?></h1>
    </div>
</div>
<section class="card" style="max-width: 860px">
<?php foreach ($errors as $error) : ?>
    <p class="errors" role="alert"><?= e($error) ?></p>
<?php endforeach; ?>
<form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <?php if ($id) : ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

    <label for="title">Title</label>
    <input id="title" name="title" maxlength="200" value="<?= e($article['title']) ?>"
        placeholder="e.g. How to reset your campus Wi-Fi password" required>

    <label for="category_id">Category</label>
    <select id="category_id" name="category_id">
        <option value="">General</option>
        <?= select_options(array_column($categories, 'name', 'id'), $article['category_id']) ?>
    </select>

    <label for="body">Article</label>
    <textarea id="body" name="body" maxlength="20000" style="min-height: 18rem"
        placeholder="Step-by-step instructions. Blank lines start new paragraphs." required
    ><?= e($article['body']) ?></textarea>

    <label class="checkbox-label">
        <input type="checkbox" name="published" value="1"<?= $article['published'] ? ' checked' : '' ?>>
        Published (visible to everyone). Untick to keep it as a staff-only draft.
    </label>
    <div class="form-actions">
        <button type="submit">Save Article</button>
        <a class="button secondary" href="<?= $id ? 'kb-article.php?id=' . $id : 'kb.php' ?>"
            style="margin-top:1rem">Cancel</a>
    </div>
</form>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
