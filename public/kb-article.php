<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
$staff = has_role('technician', 'administrator');
$id = valid_id($_GET['id'] ?? null);
if (!$id) { http_response_code(400);
    exit('Invalid article ID.'); }
$stmt = $pdo->prepare('SELECT k.*, c.name AS category, u.name AS author FROM kb_articles k
    LEFT JOIN categories c ON c.id = k.category_id JOIN users u ON u.id = k.author_id WHERE k.id = ?');
$stmt->execute([$id]);
$article = $stmt->fetch();
// Drafts are invisible to employees: same response as a missing article.
if (!$article || (!$article['published'] && !$staff)) { http_response_code(404);
    exit('Article not found.'); }
$pdo->prepare('UPDATE kb_articles SET views = views + 1 WHERE id = ?')->execute([$id]);
$pageTitle = $article['title'];
require __DIR__ . '/partials/header.php';
?>
<div class="page-header">
    <div>
        <p class="breadcrumb"><a href="kb.php">Knowledge Base</a> / <?= e($article['category'] ?? 'General') ?></p>
        <h1><?= e($article['title']) ?><?= $article['published'] ? '' : ' <span class="badge status-closed">Draft</span>' ?></h1>
        <p class="subtitle">By <?= e($article['author']) ?> &middot; updated <?= e(format_time($article['updated_at'])) ?> &middot; <?= (int) $article['views'] + 1 ?> views</p>
    </div>
    <?php if ($staff) : ?><div class="page-actions"><a class="button secondary" href="kb-edit.php?id=<?= $id ?>">Edit</a></div><?php endif; ?>
</div>
<article class="card kb-body"><?= nl2br(e($article['body'])) ?></article>
<section class="card">
    <h2>Did this solve your problem?</h2>
    <p class="meta">If not, create a ticket and mention this article so IT knows what you already tried.</p>
    <a class="button" href="ticket-create.php?kb=<?= $id ?>"><?= icon('plus') ?>Create a ticket</a>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
