<?php
// Knowledge base: searchable help articles. Everyone signed in can read published articles;
// technicians and administrators also see drafts and can write articles (kb-edit.php).
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
$staff = has_role('technician', 'administrator');
$q = mb_substr(clean_text($_GET['q'] ?? ''), 0, 100);
$categoryId = valid_id($_GET['category'] ?? null);
$categories = $pdo->query('SELECT id, name FROM categories WHERE active = 1 ORDER BY name')->fetchAll();

$where = $staff ? ['1 = 1'] : ['k.published = 1'];
$params = [];
if ($q !== '') {
    $like = '%' . strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_', '[' => '\\[']) . '%';
    $where[] = "(k.title LIKE ? ESCAPE '\\' OR k.body LIKE ? ESCAPE '\\')";
    array_push($params, $like, $like);
}
if ($categoryId) { $where[] = 'k.category_id = ?';
    $params[] = $categoryId; }
$stmt = $pdo->prepare('SELECT TOP 100 k.id, k.title, k.published, k.views, k.updated_at, LEFT(k.body, 220) AS excerpt, c.name AS category, u.name AS author
    FROM kb_articles k LEFT JOIN categories c ON c.id = k.category_id JOIN users u ON u.id = k.author_id
    WHERE ' . implode(' AND ', $where) . ' ORDER BY k.published DESC, k.views DESC, k.updated_at DESC');
$stmt->execute($params);
$articles = $stmt->fetchAll();
$pageTitle = 'Knowledge Base';
require __DIR__ . '/partials/header.php';
?>
<div class="page-header">
    <div><h1>Knowledge Base</h1><p class="subtitle">Answers to common IT problems. Try here first, many issues can be fixed in a minute.</p></div>
    <div class="page-actions">
        <?php if ($staff) : ?><a class="button" href="kb-edit.php"><?= icon('plus') ?>New Article</a><?php endif; ?>
        <a class="button secondary" href="ticket-create.php">Still stuck? Create a ticket</a>
    </div>
</div>
<form class="card filters" method="get" action="kb.php">
    <div class="filter wide"><label for="kb-q">Search articles</label><input id="kb-q" type="search" name="q" value="<?= e($q) ?>" placeholder="e.g. printer, password, Wi-Fi"></div>
    <div class="filter"><label for="kb-category">Category</label><select id="kb-category" name="category" data-autosubmit><option value="">All categories</option>
        <?php foreach ($categories as $c) : ?><option value="<?= (int) $c['id'] ?>"<?= $categoryId === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <button type="submit"><?= icon('search') ?>Search</button>
</form>
<p class="result-count"><?= count($articles) ?> article<?= count($articles) === 1 ? '' : 's' ?></p>
<div class="kb-list">
<?php foreach ($articles as $a) : ?>
    <a class="card kb-card" href="kb-article.php?id=<?= (int) $a['id'] ?>">
        <h2><?= e($a['title']) ?><?= $a['published'] ? '' : ' <span class="badge status-closed">Draft</span>' ?></h2>
        <p class="kb-excerpt"><?= e(mb_strimwidth((string) $a['excerpt'], 0, 200, '…')) ?></p>
        <p class="meta"><?= e($a['category'] ?? 'General') ?> &middot; <?= (int) $a['views'] ?> view<?= (int) $a['views'] === 1 ? '' : 's' ?> &middot; updated <?= relative_time($a['updated_at']) ?></p>
    </a>
<?php endforeach; ?>
<?php if (!$articles) : ?><div class="card empty-state"><?= icon('book') ?><p><?= $q !== '' || $categoryId ? 'No articles match your search.' : 'No articles yet.' ?></p></div><?php endif; ?>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
