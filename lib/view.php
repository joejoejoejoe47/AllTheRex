<?php
declare(strict_types=1);

function page_head(string $title, string $active = ''): void
{
    $u    = current_user();
    $site = rex_config()['site_name'];
    $flash = flash();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($site) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>">
<script>window.REX={csrf:<?= json_encode(csrf_token()) ?>,rateUrl:<?= json_encode(url('rate.php')) ?>,loggedIn:<?= $u ? 'true' : 'false' ?>,loginUrl:<?= json_encode(url('login.php')) ?>};</script>
<script src="<?= e(url('assets/app.js')) ?>" defer></script>
</head>
<body>
<header class="top">
  <a class="brand" href="<?= e(url('index.php')) ?>"><span class="brand-mark">★</span> <?= e($site) ?></a>
  <nav>
    <a href="<?= e(url('index.php')) ?>" class="<?= $active === 'home' ? 'on' : '' ?>"><?= $u ? 'For you' : 'Home' ?></a>
    <?php foreach (REX_CATEGORIES as $slug => $label): ?>
      <a href="<?= e(url('browse.php?cat=' . $slug)) ?>" class="<?= $active === $slug ? 'on' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <a href="<?= e(url('add.php')) ?>" class="<?= $active === 'add' ? 'on' : '' ?>">Add a title</a>
  </nav>
  <div class="acct">
    <?php if ($u): ?>
      <a href="<?= e(url('profile.php')) ?>" class="<?= $active === 'profile' ? 'on' : '' ?>"><?= e($u['display_name']) ?></a>
      <form method="post" action="<?= e(url('logout.php')) ?>" class="inline"><?= csrf_field() ?><button class="link">Log out</button></form>
    <?php else: ?>
      <a href="<?= e(url('login.php')) ?>">Log in</a>
      <a class="btn small" href="<?= e(url('register.php')) ?>">Sign up</a>
    <?php endif; ?>
  </div>
</header>
<main>
<?php if ($flash): ?><div class="flash <?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>
<?php
}

function page_foot(): void
{
    ?>
</main>
<footer class="foot">Ratings from everyone make the recommendations. Rate a few titles you know and the rest follows.</footer>
<div id="toast" class="toast" role="status" aria-live="polite"></div>
</body>
</html>
<?php
}

/** Clickable 1-5 star control. Works without JavaScript (each star is a submit button). */
function star_widget(int $itemId, ?int $mine, string $back = 'index.php'): void
{
    $mine = (int)$mine;
    ?>
<form class="stars" method="post" action="<?= e(url('rate.php')) ?>" data-item="<?= $itemId ?>" data-mine="<?= $mine ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="item_id" value="<?= $itemId ?>">
  <input type="hidden" name="back" value="<?= e($back) ?>">
  <?php for ($i = 1; $i <= 5; $i++): ?>
    <button type="submit" name="rating" value="<?= $i ?>" class="star <?= $i <= $mine ? 'on' : '' ?>" aria-label="Rate <?= $i ?> out of 5" title="<?= $i ?> / 5">★</button>
  <?php endfor; ?>
  <button type="submit" name="rating" value="0" class="clear" <?= $mine ? '' : 'hidden' ?> aria-label="Remove my rating" title="Remove my rating">clear</button>
</form>
<?php
}

/** The cover block: real image if we have one, otherwise (or if it fails to load) the generated cover. */
function cover_inner(array $it): string
{
    $img = !empty($it['image_url']) ? '<img src="' . e($it['image_url']) . '" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="var c=this.parentNode;c.classList.remove(\'has-img\');this.remove()">' : '';
    return $img . '<span class="cat">' . e(REX_SINGULAR[$it['category']] ?? $it['category']) . '</span><span class="ini">' . e(initials($it['title'])) . '</span>';
}

/** One title tile. $extra = optional line(s) of HTML under the meta. */
function item_card(array $it, string $back = 'index.php', string $extra = ''): void
{
    $cat = $it['category'];
    ?>
<article class="card">
  <a class="cover<?= !empty($it['image_url']) ? ' has-img' : '' ?>" href="<?= e(url('title.php?id=' . (int)$it['id'])) ?>" style="<?= e(cover_style($it['title'], $cat)) ?>" aria-label="<?= e($it['title']) ?>"><?= cover_inner($it) ?></a>
  <div class="body">
    <h3><a href="<?= e(url('title.php?id=' . (int)$it['id'])) ?>"><?= e($it['title']) ?></a></h3>
    <p class="meta"><?= e($it['creator']) ?><?= ($it['creator'] !== '' && $it['year']) ? ' · ' : '' ?><?= $it['year'] ? (int)$it['year'] : '' ?></p>
    <p class="avg" data-avg-for="<?= (int)$it['id'] ?>"><?= (int)$it['n'] > 0 ? '<b>' . e(fmt_avg($it['avg'])) . '</b> ★ · ' . (int)$it['n'] . ' rating' . ((int)$it['n'] === 1 ? '' : 's') : 'No ratings yet' ?></p>
    <?= $extra ?>
    <?php star_widget((int)$it['id'], isset($it['my_rating']) ? (int)$it['my_rating'] : null, $back); ?>
  </div>
</article>
<?php
}

function pager(int $total, int $page, string $baseQuery): void
{
    $pages = (int)ceil($total / REX_PAGE_SIZE);
    if ($pages <= 1) {
        return;
    }
    echo '<nav class="pager" aria-label="Pages">';
    if ($page > 1) {
        echo '<a href="' . e(url('browse.php?' . $baseQuery . '&page=' . ($page - 1))) . '">← Previous</a>';
    }
    echo '<span>Page ' . $page . ' of ' . $pages . '</span>';
    if ($page < $pages) {
        echo '<a href="' . e(url('browse.php?' . $baseQuery . '&page=' . ($page + 1))) . '">More →</a>';
    }
    echo '</nav>';
}
