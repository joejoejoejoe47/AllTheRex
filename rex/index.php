<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$u   = current_user();
$cat = valid_category($_GET['cat'] ?? null);
$back = current_back();

page_head($u ? 'Recommended for you' : 'Rate it. Find your next favorite.', 'home');

if ($u):
    $recs = rec_for_user((int)$u['id'], $cat, 12);
    $myCount = (int)(q1('SELECT COUNT(*) AS c FROM ratings WHERE user_id = ?', [(int)$u['id']])['c'] ?? 0);
    ?>
    <section class="head">
      <h1>Recommended for you</h1>
      <p class="sub">Built from <?= $myCount ?> rating<?= $myCount === 1 ? '' : 's' ?> of yours and what people with similar taste rated.</p>
    </section>
    <div class="tabs">
      <a href="<?= e(url('index.php')) ?>" class="<?= $cat ? '' : 'on' ?>">Everything</a>
      <?php foreach (REX_CATEGORIES as $slug => $label): ?>
        <a href="<?= e(url('index.php?cat=' . $slug)) ?>" class="<?= $cat === $slug ? 'on' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>

    <?php if ($myCount === 0): ?>
      <div class="empty">
        <h2>Start with a few you know</h2>
        <p>Rate at least a handful of movies, albums or books. The more you rate, the sharper this page gets.</p>
        <p><a class="btn" href="<?= e(url('browse.php')) ?>">Browse &amp; rate titles</a></p>
      </div>
      <h2 class="sec">Popular right now</h2>
      <div class="grid">
        <?php foreach (catalog_popular($cat, 12, (int)$u['id']) as $it) { item_card($it, $back); } ?>
      </div>
    <?php elseif (!$recs): ?>
      <div class="empty"><h2>Nothing to recommend yet</h2>
        <p>You've rated everything in this list. <a href="<?= e(url('add.php')) ?>">Add a title</a> or try another category.</p></div>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($recs as $it):
            if ($it['reason'] === 'similar') {
                $names = array_map(fn($b) => '<a href="' . e(url('title.php?id=' . (int)$b['id'])) . '">' . e($b['title']) . '</a>', $it['because']);
                $extra = '<p class="why"><span class="pred">' . number_format((float)$it['score'], 1) . ' ★ predicted for you</span>'
                       . ($names ? '<br>Because you rated ' . implode(' &amp; ', $names) . '</p>' : '</p>');
            } else {
                $extra = '<p class="why"><span class="pop">Popular pick</span></p>';
            }
            item_card($it, $back, $extra);
        endforeach; ?>
      </div>
      <?php if ($myCount < 8): ?>
        <p class="nudge">Tip: with <?= $myCount ?> ratings the picks are rough. <a href="<?= e(url('browse.php')) ?>">Rate a few more</a> to sharpen them.</p>
      <?php endif; ?>
    <?php endif; ?>

<?php else: ?>
    <section class="hero">
      <h1>Rate it.<br>Find your next favorite.</h1>
      <p>Rate movies, albums and books. We compare your taste with everyone else's: when lots of people love both Title&nbsp;X and Title&nbsp;Y, rating X high gets Y recommended to you.</p>
      <p><a class="btn" href="<?= e(url('register.php')) ?>">Create a free account</a> <a class="btn ghost" href="<?= e(url('login.php')) ?>">Log in</a></p>
    </section>
    <?php foreach (REX_CATEGORIES as $slug => $label):
        $pop = catalog_popular($slug, 6, null, false);
        if (!$pop) { continue; } ?>
      <h2 class="sec"><?= e($label) ?> people are rating <a class="more" href="<?= e(url('browse.php?cat=' . $slug)) ?>">See all →</a></h2>
      <div class="grid"><?php foreach ($pop as $it) { item_card($it, $back); } ?></div>
    <?php endforeach; ?>
<?php endif;

page_foot();
