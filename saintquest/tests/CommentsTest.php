<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use SaintQuest\Comments;
use SaintQuest\Config;
use SaintQuest\Db;
use SaintQuest\Images;
use SaintQuest\Saints;

test_db();

$owner = make_user('Owner');
$fan = make_user('Fan');
$r1 = make_user('Reporter One');
$r2 = make_user('Reporter Two');
$r3 = make_user('Reporter Three');
$saint = Saints::ofOwner($owner)[0]['id'];

// Build a real JPEG with EXIF (GPS-like text) embedded.
$im = imagecreatetruecolor(2400, 1200);
imagefill($im, 0, 0, imagecolorallocate($im, 200, 160, 40));
ob_start(); imagejpeg($im, null, 90); $jpeg = (string) ob_get_clean();
$exif = "Exif\0\0" . 'MM' . str_repeat("\0", 6) . 'GPSLatitude=41.89 SECRET-LOCATION';
$app1 = "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif;
$jpegWithExif = substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);
check(str_contains($jpegWithExif, 'SECRET-LOCATION'), 'test image carries embedded metadata');

$id = Images::store($jpegWithExif, $fan, 'comment');
$path = Images::path($id);
$stored = (string) file_get_contents($path);
check(!str_contains($stored, 'SECRET-LOCATION') && !str_contains($stored, 'Exif'), 'metadata is stripped by re-encoding');
$info = getimagesizefromstring($stored);
check($info[0] === 1280 && $info[1] === 640 && $info['mime'] === 'image/jpeg', 'large images are downscaled to 1280px and saved as JPEG');
check(str_starts_with(realpath($path), realpath(Config::storagePath())), 'uploads are stored in private storage, not the web folder');
check((bool) preg_match('/^[a-f0-9]{24}\.jpg$/', basename($path)), 'stored file gets a random name with a fixed .jpg extension');

// PNG, GIF, WebP accepted
ob_start(); imagepng($im); $png = (string) ob_get_clean();
check(Images::sniff($png) === 'image/png' && Images::store($png, $fan, 'comment') !== '', 'PNG accepted');
ob_start(); imagegif($im); $gif = (string) ob_get_clean();
check(Images::store($gif, $fan, 'comment') !== '', 'GIF accepted');
if (function_exists('imagewebp')) {
    ob_start(); imagewebp($im); $webp = (string) ob_get_clean();
    check(Images::store($webp, $fan, 'comment') !== '', 'WebP accepted');
}

// Rejections
$svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>';
expect_error(fn () => Images::store($svg, $fan, 'comment'), 415, 'SVG is refused');
expect_error(fn () => Images::store('<html><script>alert(1)</script></html>', $fan, 'comment'), 415, 'HTML disguised as a picture is refused');
try { Images::store("GIF89a<?php system('id'); ?>", $fan, 'comment'); check(false, 'a PHP payload with a GIF header is refused'); } catch (\SaintQuest\HttpError $e) { check(in_array($e->status, [413, 415], true), 'a PHP payload with a GIF header is refused (' . $e->status . ')'); }
expect_error(fn () => Images::store("\xFF\xD8\xFF" . random_bytes(200), $fan, 'comment'), 415, 'a corrupt JPEG is refused');
expect_error(fn () => Images::store(str_repeat('a', Images::MAX_BYTES + 1), $fan, 'comment'), 413, 'files over 5 MB are refused');
expect_error(fn () => Images::store('', $fan, 'comment'), 400, 'empty upload refused');
// Polyglot: valid JPEG with a script appended -> re-encode drops it
$poly = $jpeg . '<script>alert("x")</script>';
$pid = Images::store($poly, $fan, 'comment');
check(!str_contains((string) file_get_contents(Images::path($pid)), '<script>'), 'appended script in a JPEG polyglot is dropped');
check(Images::path('../../etc/passwd') === null && Images::path('ABC') === null, 'image ids are strictly validated (no path traversal)');

// Comments
$c = Comments::add($fan, $saint, 'What a kind saint!', $id);
$list = Comments::forSaint($saint, null, false);
check(count($list) === 1 && $list[0]['body'] === 'What a kind saint!' && $list[0]['author'] === 'Fan' && $list[0]['image'] === $id, 'comments are public with text and picture');
check((int) Db::value("SELECT COUNT(*) FROM sq_notifications WHERE user_id = ? AND kind = 'comment'", [$owner]) === 1, 'the owner is notified about the new comment');
expect_error(fn () => Comments::add($fan, $saint, '', null), 400, 'empty comment refused');
expect_error(fn () => Comments::add($fan, $saint, str_repeat('x', 501), null), 400, 'overlong comment refused');
expect_error(fn () => Comments::add($fan, 'nope', 'hi', null), 404, 'comment on a missing saint refused');
$xss = Comments::add($fan, $saint, '<img src=x onerror=alert(1)>', null);
check(Comments::forSaint($saint, null, false)[1]['body'] === '<img src=x onerror=alert(1)>', 'comment text is stored as plain text (the page renders it with textContent)');

// Report -> auto hide after 3 reports
expect_error(fn () => Comments::report($fan, $c['commentId']), 400, 'cannot report your own comment');
Comments::report($r1, $c['commentId']);
Comments::report($r1, $c['commentId']);
check((int) Db::value('SELECT reports FROM sq_comments WHERE id = ?', [$c['commentId']]) === 1, 'one player\'s repeated reports count once');
Comments::report($r2, $c['commentId']);
$res = Comments::report($r3, $c['commentId']);
check($res['hidden'] === true, 'three reports hide the comment');
$pub = Comments::forSaint($saint, null, false)[0];
check($pub['hidden'] === true && $pub['body'] === null && $pub['image'] === null, 'hidden comments show no text or picture to the public');
check(!Images::canView($id, null) && !Images::canView($id, $r1), 'the hidden comment\'s picture is not served to the public');
check(Images::canView($id, $owner) && Images::canView($id, $fan), 'owner and writer can still see it');
expect_error(fn () => Comments::setHidden($fan, $c['commentId'], false), 403, 'the writer cannot unhide a reported comment');
Comments::setHidden($owner, $c['commentId'], false);
check(Comments::forSaint($saint, null, false)[0]['hidden'] === false, 'the saint\'s owner can unhide');
expect_error(fn () => Comments::setHidden($r1, $c['commentId'], true), 403, 'other players cannot hide comments directly');
Comments::setHidden($owner, $xss['commentId'], true);
check(Comments::forSaint($saint, null, false)[1]['hidden'] === true, 'owner can hide any comment on their saint');

finish();
