<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$u   = require_login();
$cat = valid_category($_GET['cat'] ?? null);
$rows = user_ratings_list((int)$u['id'], $cat);
$back = current_back();

page_head('My ratings', 'profile');
?>
<section class="head">
  <h1>My ratings</h1>
  <p class="sub"><?= count($rows) ?> title<?= count($rows) === 1 ? '' : 's' ?> rated<?= $cat ? ' in ' . e(strtolower(REX_CATEGORIES[$cat])) : '' ?>. Change a rating any time and your recommendations update.</p>
</section>
<div class="tabs">
  <a href="<?= e(url('profile.php')) ?>" class="<?= $cat ? '' : 'on' ?>">All</a>
  <?php foreach (REX_CATEGORIES as $slug => $label): ?>
    <a href="<?= e(url('profile.php?cat=' . $slug)) ?>" class="<?= $cat === $slug ? 'on' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<?php if (!$rows): ?>
  <div class="empty"><h2>No ratings yet</h2><p><a class="btn" href="<?= e(url('browse.php')) ?>">Find something to rate</a></p></div>
<?php else: ?>
  <div class="grid"><?php foreach ($rows as $it) { item_card($it, $back); } ?></div>
<?php endif;
page_foot();
