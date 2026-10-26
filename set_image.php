<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

require_post();
$u = require_login();
$id = (int)($_POST['item_id'] ?? 0);
if (!q1('SELECT id FROM items WHERE id = ?', [$id])) {
    http_response_code(404);
    exit('No such title');
}
$raw = trim((string)($_POST['image_url'] ?? ''));
if ($raw === '') {
    $found = rex_fetch_image_for($id);
    flash($found ? 'Found an image.' : 'Couldn\'t find an image automatically. Paste a link instead.', $found ? 'ok' : 'err');
} elseif (($clean = rex_user_image_url($raw)) !== null) {
    qx('UPDATE items SET image_url = ?, image_tried = 1 WHERE id = ?', [$clean, $id]);
    flash('Image updated.');
} else {
    flash('That doesn\'t look like a valid https image link.', 'err');
}
redirect('title.php?id=' . $id);
