<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

require_post();
$u   = require_login();
$cat = valid_category($_POST['category'] ?? null);
$title   = clean_text($_POST['title'] ?? '', 190);
$creator = clean_text($_POST['creator'] ?? '', 190);
$yearRaw = trim((string)($_POST['year'] ?? ''));
$year    = ctype_digit($yearRaw) ? (int)$yearRaw : null;

if (!$cat || $title === '') {
    flash('Could not add that title.', 'err');
    redirect('add.php');
}
if (($existing = catalog_find_existing($cat, $title, $creator, $year)) !== null) {
    flash('That one is already in the catalog.');
    redirect('title.php?id=' . $existing);
}
[$id, $errors] = add_item($cat, $title, $creator, $year !== null ? (string)$year : '', (int)$u['id'], true, (string)($_POST['image_url'] ?? ''));
if ($id && !$errors) {
    flash('Added! Now give it a rating.');
    redirect('title.php?id=' . $id);
}
if ($id) {
    redirect('title.php?id=' . $id);
}
flash(implode(' ', $errors) ?: 'Could not add that title.', 'err');
redirect('add.php');
