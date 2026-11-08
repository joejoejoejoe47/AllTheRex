<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$u  = current_user();
$id = (int)($_GET['id'] ?? 0);
$it = $id ? catalog_get($id, $u ? (int)$u['id'] : null) : null;
if (!$it) {
    http_response_code(404);
    page_head('Not found');
    echo '<div class="empty"><h2>We couldn\'t find that title</h2><p><a href="' . e(url('browse.php')) . '">Browse the catalog</a></p></div>';
    page_foot();
    exit;
}

if (empty($it['image_url']) && (int)(q1('SELECT image_tried t FROM items WHERE id = ?', [$id])['t'] ?? 1) === 0) {
    rex_fetch_image_for($id);                        // one-time cover lookup the first time a title is opened
    $it = catalog_get($id, $u ? (int)$u['id'] : null) ?? $it;
}

$similar = rec_related($id, 8, $u ? (int)$u['id'] : null);
$dist = array_fill(1, 5, 0);
foreach (q('SELECT rating, COUNT(*) AS c FROM ratings WHERE item_id = ? GROUP BY rating', [$id]) as $r) {
    $dist[(int)$r['rating']] = (int)$r['c'];
}
$max = max(1, max($dist));
$back = current_back();

page_head($it['title'], $it['category']);
?>
<section class="detail">
  <div class="cover big<?= !empty($it['image_url']) ? ' has-img' : '' ?>" style="<?= e(cover_style($it['title'], $it['category'])) ?>"><?= cover_inner($it) ?></div>
  <div>
    <p class="crumb"><a href="<?= e(url('browse.php?cat=' . $it['category'])) ?>"><?= e(REX_CATEGORIES[$it['category']]) ?></a></p>
    <h1><?= e($it['title']) ?></h1>
    <p class="meta big"><?= $it['creator'] !== '' ? e(REX_CREATOR[$it['category']]) . ': ' . e($it['creator']) : '' ?><?= ($it['creator'] !== '' && $it['year']) ? ' · ' : '' ?><?= $it['year'] ? (int)$it['year'] : '' ?></p>

    <div class="scorebox">
      <div class="score"><b><?= e(fmt_avg($it['avg'])) ?></b><span>/ 5</span></div>
      <div class="dist" aria-label="Rating distribution">
        <?php for ($i = 5; $i >= 1; $i--): ?>
          <div class="row"><span><?= $i ?>★</span><i style="width:<?= (int)round($dist[$i] / $max * 100) ?>%"></i><em><?= $dist[$i] ?></em></div>
        <?php endfor; ?>
      </div>
    </div>
    <p class="avg" data-avg-for="<?= (int)$it['id'] ?>"><?= (int)$it['n'] > 0 ? (int)$it['n'] . ' rating' . ((int)$it['n'] === 1 ? '' : 's') : 'Be the first to rate this' ?></p>

    <div class="yourrate">
      <strong><?= $u ? 'Your rating' : 'Rate it' ?></strong>
      <?php star_widget((int)$it['id'], isset($it['my_rating']) ? (int)$it['my_rating'] : null, $back); ?>
      <?php if (!$u): ?><p class="hint"><a href="<?= e(url('login.php')) ?>">Log in</a> or <a href="<?= e(url('register.php')) ?>">sign up</a> to save ratings.</p><?php endif; ?>
    </div>
  </div>
</section>

<?php if ($u): ?>
<details class="imgfix">
  <summary>Wrong or missing image?</summary>
  <form method="post" action="<?= e(url('set_image.php')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
    <label>Image link (https) <small>Leave blank to search again automatically.</small>
      <input name="image_url" type="url" maxlength="500" placeholder="https://…/cover.jpg">
    </label>
    <button class="btn small">Update image</button>
  </form>
</details>
<?php endif; ?>

<h2 class="sec">People who liked this also liked</h2>
<?php if ($similar): ?>
  <div class="grid">
    <?php foreach ($similar as $s):
        if ($s['kind'] === 'match') {
            $extra = '<p class="why"><span class="pred">' . (int)round($s['sim'] * 100) . '% match</span> · ' . (int)$s['n'] . ' shared rater' . ((int)$s['n'] === 1 ? '' : 's') . '</p>';
        } elseif ($s['kind'] === 'creator') {
            $extra = '<p class="why">Same ' . ($it['category'] === 'album' ? 'artist' : ($it['category'] === 'book' ? 'author' : 'director')) . '</p>';
        } else {
            $extra = '<p class="why">Popular pick</p>';
        }
        item_card($s, $back, $extra);
    endforeach; ?>
  </div>
  <?php if (!array_filter($similar, fn($s) => $s['kind'] === 'match')): ?>
    <p class="muted small">Rating-based matches appear here once at least two people have rated this and the same other titles. Until then you will see related picks.</p>
  <?php endif; ?>
<?php else: ?>
  <div class="empty small"><p>No related titles yet.</p></div>
<?php endif;

page_foot();
