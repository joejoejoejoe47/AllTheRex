<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$u = require_login();
$errors = [];
$cat = valid_category($_POST['category'] ?? $_GET['cat'] ?? null) ?? 'movie';
$title = $creator = $year = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post();
    $title   = (string)($_POST['title'] ?? '');
    $creator = (string)($_POST['creator'] ?? '');
    $year    = (string)($_POST['year'] ?? '');
    [$id, $errors] = add_item((string)($_POST['category'] ?? ''), $title, $creator, $year, (int)$u['id']);
    if ($id && !$errors) {
        flash('Added! Now give it a rating.');
        redirect('title.php?id=' . $id);
    }
    if ($id && isset($errors['duplicate'])) {
        flash('That one is already in the catalog.');
        redirect('title.php?id=' . $id);
    }
}

page_head('Add a title', 'add');
?>
<section class="formwrap">
  <h1>Add a title</h1>
  <p class="sub">Can't find something? Add it once and everyone can rate it. Search first to avoid duplicates.</p>
  <form method="post" class="form" novalidate>
    <?= csrf_field() ?>
    <fieldset class="seg">
      <legend>Type</legend>
      <?php foreach (REX_SINGULAR as $slug => $label): ?>
        <label><input type="radio" name="category" value="<?= e($slug) ?>" <?= $cat === $slug ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
      <?php endforeach; ?>
      <?php if (isset($errors['category'])): ?><small class="err"><?= e($errors['category']) ?></small><?php endif; ?>
    </fieldset>
    <label>Title
      <input name="title" value="<?= e($title) ?>" maxlength="190" required autofocus>
      <?php if (isset($errors['title'])): ?><small class="err"><?= e($errors['title']) ?></small><?php endif; ?>
    </label>
    <label><span id="creator-label"><?= e(REX_CREATOR[$cat]) ?></span> <small>(optional)</small>
      <input name="creator" value="<?= e($creator) ?>" maxlength="190" placeholder="Director, artist or author">
    </label>
    <label>Year <small>(optional)</small>
      <input name="year" value="<?= e($year) ?>" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" placeholder="e.g. 1994">
      <?php if (isset($errors['year'])): ?><small class="err"><?= e($errors['year']) ?></small><?php endif; ?>
    </label>
    <button class="btn">Add title</button>
  </form>
</section>
<script>
  // Relabel the creator field to match the chosen type.
  (function () {
    var labels = <?= json_encode(REX_CREATOR) ?>, el = document.getElementById('creator-label');
    document.querySelectorAll('input[name=category]').forEach(function (r) {
      r.addEventListener('change', function () { el.textContent = labels[r.value]; });
    });
  })();
</script>
<?php page_foot();
