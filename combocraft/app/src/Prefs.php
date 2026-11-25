<?php

declare(strict_types=1);

namespace ComboCraft;

use Throwable;

/**
 * Per-player background preference. Additive cc_prefs only.
 * Built-in scenes, or a re-encoded custom webp stored under app/storage/bg.
 */
final class Prefs
{
    public const BACKGROUNDS = ['stadium', 'beach', 'candy', 'space', 'jungle', 'snow', 'coral'];
    public const MAX_BYTES = 5242880;
    public const MAX_EDGE = 2560;

    public static function boot(): void
    {
        static $ok = false;
        if ($ok) {
            return;
        }
        try {
            Db::run(
                "CREATE TABLE IF NOT EXISTS cc_prefs (
                    player_id VARCHAR(24) NOT NULL PRIMARY KEY,
                    bg VARCHAR(32) NOT NULL DEFAULT 'stadium',
                    file VARCHAR(48) NULL,
                    updated_at DATETIME NOT NULL
                )"
            );
        } catch (Throwable $e) {
            error_log('[combocraft prefs boot] ' . $e->getMessage());
        }
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $ok = true;
    }

    /** @param array<string,mixed> $player @return array<string,mixed> */
    public static function get(array $player): array
    {
        self::boot();
        $row = null;
        try {
            $row = Db::one('SELECT bg, file FROM cc_prefs WHERE player_id = ?', [$player['id']]);
        } catch (Throwable $e) {
        }
        return self::view($row);
    }

    /**
     * Set a built-in background, or reset to stadium.
     * @param array<string,mixed> $player
     * @return array<string,mixed>
     */
    public static function set(array $player, string $bg, bool $clearCustom = false): array
    {
        self::boot();
        if ($clearCustom) {
            $bg = 'stadium';
        }
        $bg = self::norm($bg);
        if ($clearCustom || $bg !== 'custom') {
            self::deleteFileFor($player['id']);
            $file = null;
        } else {
            $cur = Db::one('SELECT file FROM cc_prefs WHERE player_id = ?', [$player['id']]);
            $file = $cur['file'] ?? null;
        }
        self::upsert($player['id'], $bg, $file);
        return self::get($player);
    }

    /**
     * Accept an uploaded image, validate it, re-encode to a safe webp, store it.
     * @param array<string,mixed> $player
     * @return array<string,mixed>
     */
    public static function upload(array $player): array
    {
        self::boot();
        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            throw new HttpError('Choose a JPG, PNG or WebP image, up to 5 MB.', 400);
        }
        $f = $_FILES['file'];
        $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || (int) ($f['size'] ?? 0) > self::MAX_BYTES) {
            throw new HttpError('That image is too big. Please keep it under 5 MB.', 400);
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new HttpError('The upload failed. Try a different photo.', 400);
        }
        $tmp = (string) ($f['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new HttpError('The upload failed. Try again.', 400);
        }
        $mime = '';
        if (class_exists(\finfo::class)) {
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        }
        $info = @getimagesize($tmp);
        if (!is_array($info) || empty($info[0]) || empty($info[1]) || empty($info[2])) {
            throw new HttpError('That file is not a real image. Please use a JPG, PNG or WebP.', 400);
        }
        $type = (int) $info[2];
        $okTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
        if (!in_array($type, $okTypes, true)) {
            throw new HttpError('Please use a JPG, PNG or WebP image.', 400);
        }
        $okMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'];
        if ($mime !== '' && !in_array(strtolower($mime), $okMimes, true)) {
            throw new HttpError('Please use a JPG, PNG or WebP image.', 400);
        }
        $src = self::loadImage($tmp, $type);
        if ($src === false) {
            throw new HttpError('Could not read that image. Try another file.', 400);
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $edge = max($w, $h);
        if ($edge > self::MAX_EDGE) {
            $scale = self::MAX_EDGE / $edge;
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            if ($dst === false) {
                imagedestroy($src);
                throw new HttpError('Could not process that image.', 500);
            }
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $clear = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $nw, $nh, $clear);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($src);
            $src = $dst;
        }
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            imagedestroy($src);
            throw new HttpError('Could not save the background on the server.', 500);
        }
        $id = bin2hex(random_bytes(16));
        $name = $id . '.webp';
        $path = $dir . '/' . $name;
        $ok = false;
        if (function_exists('imagewebp')) {
            $ok = @imagewebp($src, $path, 82);
        }
        if (!$ok) {
            $name = $id . '.jpg';
            $path = $dir . '/' . $name;
            $canvas = imagecreatetruecolor(imagesx($src), imagesy($src));
            if ($canvas === false) {
                imagedestroy($src);
                throw new HttpError('Could not save that image.', 500);
            }
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, imagesx($src), imagesy($src), $white);
            imagecopy($canvas, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));
            $ok = @imagejpeg($canvas, $path, 85);
            imagedestroy($canvas);
        }
        imagedestroy($src);
        if (!$ok || !is_file($path)) {
            throw new HttpError('Could not save that image. Try a different photo.', 500);
        }
        self::deleteFileFor($player['id']);
        self::upsert($player['id'], 'custom', $name);
        return self::get($player);
    }

    public static function serve(string $name): never
    {
        if (!preg_match('/^[a-f0-9]{32}\.(webp|jpg)$/', $name)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Not found\n";
            exit;
        }
        $path = self::dir() . '/' . $name;
        $realDir = realpath(self::dir());
        $real = is_file($path) ? realpath($path) : false;
        if ($realDir === false || $real === false || !str_starts_with($real, $realDir)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Not found\n";
            exit;
        }
        $mime = str_ends_with($name, '.jpg') ? 'image/jpeg' : 'image/webp';
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . (string) filesize($real));
        readfile($real);
        exit;
    }

    public static function dir(): string
    {
        return Config::storagePath('bg');
    }

    public static function norm(string $bg): string
    {
        $bg = strtolower(trim($bg));
        if ($bg === 'custom') {
            return 'custom';
        }
        return in_array($bg, self::BACKGROUNDS, true) ? $bg : 'stadium';
    }

    /** @param array<string,mixed>|null $row @return array<string,mixed> */
    private static function view(?array $row): array
    {
        $bg = self::norm((string) ($row['bg'] ?? 'stadium'));
        $file = (string) ($row['file'] ?? '');
        if ($bg === 'custom' && $file === '') {
            $bg = 'stadium';
        }
        $url = null;
        if ($bg === 'custom' && $file !== '' && preg_match('/^[a-f0-9]{32}\.(webp|jpg)$/', $file)) {
            $url = 'bg/u/' . $file;
        }
        return ['bg' => $bg, 'file' => $file !== '' ? $file : null, 'url' => $url];
    }

    private static function upsert(string $playerId, string $bg, ?string $file): void
    {
        $now = Db::now();
        $row = Db::one('SELECT player_id FROM cc_prefs WHERE player_id = ?', [$playerId]);
        if ($row) {
            Db::run('UPDATE cc_prefs SET bg = ?, file = ?, updated_at = ? WHERE player_id = ?', [$bg, $file, $now, $playerId]);
            return;
        }
        try {
            Game::insertPublic('cc_prefs', [
                'player_id' => $playerId,
                'bg' => $bg,
                'file' => $file,
                'updated_at' => $now,
            ]);
        } catch (Throwable $e) {
            error_log('[combocraft prefs] ' . $e->getMessage());
        }
    }

    private static function deleteFileFor(string $playerId): void
    {
        try {
            $row = Db::one('SELECT file FROM cc_prefs WHERE player_id = ?', [$playerId]);
        } catch (Throwable $e) {
            return;
        }
        $file = (string) ($row['file'] ?? '');
        if ($file === '' || !preg_match('/^[a-f0-9]{32}\.(webp|jpg)$/', $file)) {
            return;
        }
        $path = self::dir() . '/' . $file;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** @return \GdImage|resource|false */
    private static function loadImage(string $tmp, int $type)
    {
        return match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
            IMAGETYPE_PNG => @imagecreatefrompng($tmp),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
            default => false,
        };
    }
}
