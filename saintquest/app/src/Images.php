<?php

declare(strict_types=1);

namespace SaintQuest;

/**
 * Image uploads (comment pictures, card photos).
 *
 * Only JPEG, PNG, WebP and GIF are accepted, checked by content sniffing (not
 * the file name or the browser's type). SVG and everything else is refused.
 * Every image is decoded and re-encoded as a fresh JPEG with GD, which drops
 * EXIF/GPS and any other embedded data, then stored under app/storage/uploads
 * (blocked from the web) with a random name, and served only through the API
 * with a fixed image Content-Type and nosniff.
 */
final class Images
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_SIDE = 1280;
    private const MAX_PIXELS = 40_000_000;
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public static function fromUpload(string $field, string $ownerId, string $kind): ?string
    {
        if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
            return null;
        }
        $f = $_FILES[$field];
        if (is_array($f['error'] ?? null)) {
            throw new HttpError('Send one picture at a time.', 400);
        }
        $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new HttpError('That picture is too big (5 MB max).', 413);
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            throw new HttpError('The picture did not upload. Try again.', 400);
        }
        if ((int) $f['size'] > self::MAX_BYTES) {
            throw new HttpError('That picture is too big (5 MB max).', 413);
        }
        $bytes = (string) file_get_contents((string) $f['tmp_name']);
        return self::store($bytes, $ownerId, $kind);
    }

    /** Validate, re-encode and save. Returns the image id. */
    public static function store(string $bytes, string $ownerId, string $kind): string
    {
        $len = strlen($bytes);
        if ($len === 0) {
            throw new HttpError('That picture is empty.', 400);
        }
        if ($len > self::MAX_BYTES) {
            throw new HttpError('That picture is too big (5 MB max).', 413);
        }
        $mime = self::sniff($bytes);
        if ($mime === null || !in_array($mime, self::ALLOWED, true)) {
            throw new HttpError('Only JPEG, PNG, WebP or GIF pictures are allowed.', 415);
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            throw new HttpError('That picture could not be read.', 415);
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS || $info[0] > 10000 || $info[1] > 10000) {
            throw new HttpError('That picture has too many pixels.', 413);
        }
        if (!function_exists('imagecreatefromstring')) {
            throw new HttpError('Picture uploads are not available on this server.', 503);
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            throw new HttpError('That picture could not be read.', 415);
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, self::MAX_SIDE / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        unset($src); // imagedestroy() is a no-op since PHP 8 and deprecated in 8.5
        ob_start();
        imagejpeg($dst, null, 84);
        $jpeg = (string) ob_get_clean();
        unset($dst);

        $id = Auth::randomId();
        $dir = Config::storagePath('uploads');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $id . '.jpg';
        if (@file_put_contents($dir . '/' . $file, $jpeg) === false) {
            throw new HttpError('Could not save the picture. Try again later.', 500);
        }
        Db::run(
            'INSERT INTO sq_images (id, owner_id, kind, file, mime, width, height, bytes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $ownerId, $kind, $file, 'image/jpeg', $nw, $nh, strlen($jpeg), Db::now()]
        );
        return $id;
    }

    /** Content sniffing by magic bytes. Anything text-like (SVG, HTML) returns null. */
    public static function sniff(string $bytes): ?string
    {
        $head = substr($bytes, 0, 16);
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return 'image/gif';
        }
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        return null;
    }

    public static function path(string $id): ?string
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $id)) {
            return null;
        }
        $r = Db::one('SELECT file FROM sq_images WHERE id = ?', [$id]);
        if ($r === null || !preg_match('/^[a-f0-9]{24}\.jpg$/', (string) $r['file'])) {
            return null;
        }
        $p = Config::storagePath('uploads/' . $r['file']);
        return is_file($p) ? $p : null;
    }

    /** Can this viewer see this image? Comment images unless hidden; claim photos only by the two players. */
    public static function canView(string $id, ?string $viewerId): bool
    {
        $c = Db::one('SELECT c.hidden, c.author_id, s.owner_id FROM sq_comments c JOIN sq_saints s ON s.id = c.saint_id WHERE c.image_id = ? LIMIT 1', [$id]);
        if ($c !== null) {
            return (int) $c['hidden'] === 0 || ($viewerId !== null && ($viewerId === $c['author_id'] || $viewerId === $c['owner_id']));
        }
        if ($viewerId === null) {
            return false;
        }
        $claim = Db::one('SELECT claimant_id, owner_id FROM sq_card_claims WHERE owner_photo_id = ? OR claimant_photo_id = ? LIMIT 1', [$id, $id]);
        return $claim !== null && ($viewerId === $claim['claimant_id'] || $viewerId === $claim['owner_id']);
    }

    public static function serve(string $id, ?string $viewerId): never
    {
        $path = self::path($id);
        if ($path === null || !self::canView($id, $viewerId)) {
            throw new HttpError('Picture not found.', 404);
        }
        header('Content-Type: image/jpeg');
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'; sandbox');
        header('Content-Disposition: inline; filename="picture.jpg"');
        header('Cache-Control: private, max-age=3600');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}
