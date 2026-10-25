<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$u    = current_user();
$cat  = valid_category($_GET['cat'] ?? null);
$q    = clean_text($_GET['q'] ?? '', 80);
$sort = in_array($_GET['sort'] ?? '', ['popular', 'top', 'new', 'az'], true) ? $_GET['sort'] : 'popular';
$page = max(1, (int)($_GET['page'] ?? 1));

[$rows, $total] = catalog_list($cat, $q, $sort, $page, $u ? (int)$u['id'] : null);
$back = current_back();
$label = $cat ? REX_CATEGORIES[$cat] : 'Everything';

page_head($label, $cat ?? 'browse');
?>
<section class="head"><h1><?= e($label) ?></h1>
  <p class="sub"><?= $total ?> title<?= $total === 1 ? '' : 's' ?><?= $q !== '' ? ' matching “' . e($q) . '”' : '' ?></p></section>

<form class="filters" method="get" action="<?= e(url('browse.php')) ?>">
  <select name="cat" aria-label="Category">
    <option value="">All categories</option>
    <?php foreach (REX_CATEGORIES as $slug => $l): ?>
      <option value="<?= e($slug) ?>" <?= $cat === $slug ? 'selected' : '' ?>><?= e($l) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search title or <?= e(strtolower(implode('/', array_values(REX_CREATOR)))) ?>…" aria-label="Search">
  <select name="sort" aria-label="Sort">
    <option value="popular" <?= $sort === 'popular' ? 'selected' : '' ?>>Most rated</option>
    <option value="top" <?= $sort === 'top' ? 'selected' : '' ?>>Best rated</option>
    <option value="new" <?= $sort === 'new' ? 'selected' : '' ?>>Newest</option>
    <option value="az" <?= $sort === 'az' ? 'selected' : '' ?>>A–Z</option>
  </select>
  <button class="btn small">Apply</button>
</form>

<?php if (!$rows): ?>
  <div class="empty"><h2>Nothing found</h2>
    <p>Can't find it? <a href="<?= e(url('add.php' . ($cat ? '?cat=' . $cat : ''))) ?>">Add it to the catalog</a> and be the first to rate it.</p></div>
<?php else: ?>
  <div class="grid"><?php foreach ($rows as $it) { item_card($it, $back); } ?></div>
  <?php pager($total, $page, http_build_query(array_filter(['cat' => $cat, 'q' => $q, 'sort' => $sort]))); ?>
<?php endif;

page_foot();
