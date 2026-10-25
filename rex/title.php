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

$similar = rec_similar_items($id, 6, $u ? (int)$u['id'] : null);
$dist = array_fill(1, 5, 0);
foreach (q('SELECT rating, COUNT(*) AS c FROM ratings WHERE item_id = ? GROUP BY rating', [$id]) as $r) {
    $dist[(int)$r['rating']] = (int)$r['c'];
}
$max = max(1, max($dist));
$back = current_back();

page_head($it['title'], $it['category']);
?>
<section class="detail">
  <div class="cover big" style="<?= e(cover_style($it['title'], $it['category'])) ?>">
    <span class="cat"><?= e(REX_SINGULAR[$it['category']]) ?></span>
    <span class="ini"><?= e(initials($it['title'])) ?></span>
  </div>
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

<h2 class="sec">People who liked this also liked</h2>
<?php if ($similar): ?>
  <div class="grid">
    <?php foreach ($similar as $s):
        $extra = '<p class="why"><span class="pred">' . (int)round($s['sim'] * 100) . '% match</span> · ' . (int)$s['n'] . ' shared rater' . ((int)$s['n'] === 1 ? '' : 's') . '</p>';
        item_card($s, $back, $extra);
    endforeach; ?>
  </div>
<?php else: ?>
  <div class="empty small"><p>Not enough overlapping ratings yet. When at least two people rate this and the same other titles, matches appear here.</p></div>
<?php endif;

page_foot();
