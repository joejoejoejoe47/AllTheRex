<?php

declare(strict_types=1);

namespace ComboCraft;

use Throwable;

/** Dynamic Open Graph / Twitter share images for /e/{id} and daily results. */
final class OgImage
{
    public static function handle(string $path): never
    {
        try {
            if (preg_match('#^/og/e/(\d+)\.png$#', $path, $m)) {
                self::element((int) $m[1]);
            }
            if (preg_match('#^/og/daily(?:/(\d{4}-\d{2}-\d{2}))?\.png$#', $path, $m)) {
                self::daily($m[1] ?? Daily::today());
            }
            if (preg_match('#^/og/clash/([a-z0-9]+)\.png$#', $path, $m)) {
                self::clash($m[1]);
            }
        } catch (Throwable $e) {
            error_log('[combocraft og] ' . $e->getMessage());
        }
        self::fallback();
    }

    private static function element(int $id): never
    {
        $e = Game::element($id);
        if ($e === null) {
            self::fallback();
        }
        $v = Game::view($e, true);
        $recipe = $v['recipe'] ? $v['recipe'][0]['name'] . ' + ' . $v['recipe'][1]['name'] : 'A ComboCraft discovery';
        $sub = $v['tagline'] ?: $recipe;
        $title = $v['emoji'] . '  ' . $v['name'];
        self::paint($title, $recipe . ' = ' . $v['name'], $sub, $v['rarity'] ?? '');
    }

    private static function clash(string $id): never
    {
        $s = Clash::share($id);
        if (!$s) {
            self::fallback();
        }
        $best = $s['best'] ?? null;
        $recipe = $best ? ($best['a'] . ' + ' . $best['b'] . ' = ' . $best['name']) : 'Combo Clash';
        $title = ($best['emoji'] ?? '⚡') . '  ' . ($best['name'] ?? 'Victory');
        $kicker = $s['headline'] ?? ('I beat @' . ($s['loser']['name'] ?? 'a rival'));
        self::paint($kicker, $title, $recipe, $best['rarity'] ?? 'Combo Clash');
    }

    private static function daily(string $day): never
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $day = Daily::today();
        }
        $t = Daily::target($day);
        $name = $t ? $t['emoji'] . '  ' . $t['name'] : 'Daily Challenge';
        self::paint('ComboCraft Daily', $day, 'Today\'s target: ' . ($t['name'] ?? '???'), $name);
    }

    private static function paint(string $kicker, string $title, string $sub, string $badge): never
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::fallback();
        }
        $w = 1200;
        $h = 630;
        $im = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($im, 255, 255, 255);
        $ink = imagecolorallocate($im, 28, 24, 46);
        $mut = imagecolorallocate($im, 90, 80, 120);
        $pur = imagecolorallocate($im, 123, 63, 228);
        $gold = imagecolorallocate($im, 212, 168, 60);
        imagefilledrectangle($im, 0, 0, $w, $h, $white);
        // soft purple wash
        for ($i = 0; $i < 80; $i++) {
            $c = imagecolorallocatealpha($im, 167, 123, 245, 110 - (int) ($i / 2));
            if ($c !== false) {
                imagefilledellipse($im, 1000, -40 + $i * 2, 700, 520, $c);
            }
        }
        $font = self::font();
        $logo = dirname(Config::root()) . '/assets/logo/logo.png';
        if (is_file($logo)) {
            $src = @imagecreatefrompng($logo);
            if ($src) {
                $lw = imagesx($src);
                $lh = imagesy($src);
                $nw = 280;
                $nh = (int) ($lh * $nw / max(1, $lw));
                imagecopyresampled($im, $src, 72, 48, 0, 0, $nw, $nh, $lw, $lh);
                imagedestroy($src);
            }
        } elseif ($font) {
            imagettftext($im, 28, 0, 72, 90, $pur, $font, 'ComboCraft');
        }
        if ($font) {
            imagettftext($im, 22, 0, 72, 200, $pur, $font, self::fit($kicker, 40));
            imagettftext($im, 44, 0, 72, 280, $ink, $font, self::fit($title, 32));
            imagettftext($im, 24, 0, 72, 360, $mut, $font, self::fit($sub, 48));
            if ($badge !== '') {
                imagettftext($im, 20, 0, 72, 520, $gold, $font, $badge);
            }
            imagettftext($im, 18, 0, 72, 580, $mut, $font, 'alltherex.com/combocraft');
        } else {
            imagestring($im, 5, 72, 200, $kicker, $pur);
            imagestring($im, 5, 72, 260, $title, $ink);
            imagestring($im, 4, 72, 320, $sub, $mut);
        }
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=300');
        imagepng($im);
        imagedestroy($im);
        exit;
    }

    private static function fit(string $s, int $n): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        if (mb_strlen($s) <= $n) {
            return $s;
        }
        return rtrim(mb_substr($s, 0, $n - 1)) . '…';
    }

    private static function font(): ?string
    {
        foreach ([
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        ] as $f) {
            if (is_file($f)) {
                return $f;
            }
        }
        return null;
    }

    private static function fallback(): never
    {
        $file = dirname(Config::root()) . '/assets/og.png';
        if (is_file($file)) {
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=300');
            readfile($file);
            exit;
        }
        http_response_code(404);
        header('Content-Type: text/plain');
        echo "not found\n";
        exit;
    }
}
