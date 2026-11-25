<?php

declare(strict_types=1);

namespace ComboCraft;

/** Invite / referral codes and "challenge a friend" links. */
final class Referral
{
    public static function cookieName(): string
    {
        return 'cc_ref';
    }

    /** @param array<string,mixed> $player @return array{code:string,url:string,referredBy:?string} */
    public static function for(array $player): array
    {
        $row = Db::one('SELECT * FROM cc_referrals WHERE player_id = ?', [$player['id']]);
        if ($row === null) {
            $code = self::mint($player['id']);
            $from = self::pendingCode();
            $referrer = null;
            if ($from && $from !== $code) {
                $referrer = Db::value('SELECT player_id FROM cc_referrals WHERE code = ?', [$from]);
                if ($referrer === $player['id']) {
                    $referrer = null;
                }
            }
            Game::insertPublic('cc_referrals', [
                'player_id' => $player['id'], 'code' => $code,
                'referred_by' => $referrer, 'created_at' => Db::now(),
            ]);
            $row = Db::one('SELECT * FROM cc_referrals WHERE player_id = ?', [$player['id']]);
        }
        $code = (string) $row['code'];
        return [
            'code' => $code,
            'url' => self::absolute('?r=' . $code),
            'challengeUrl' => self::absolute('daily?r=' . $code),
            'referredBy' => $row['referred_by'] ?? null,
        ];
    }

    public static function absolute(string $path): string
    {
        $scheme = Http::isHttps() ? 'https' : 'http';
        $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
        return $scheme . '://' . Http::host() . $base . '/' . ltrim($path, '/');
    }

    public static function pendingCode(): ?string
    {
        $c = $_COOKIE[self::cookieName()] ?? ($_GET['r'] ?? null);
        if (!is_string($c)) {
            return null;
        }
        $c = strtoupper(preg_replace('/[^A-Z0-9]/', '', $c) ?? '');
        return strlen($c) === 8 ? $c : null;
    }

    public static function remember(?string $code = null): void
    {
        $code = $code ?? self::pendingCode();
        if ($code === null) {
            return;
        }
        if (!headers_sent()) {
            setcookie(self::cookieName(), $code, [
                'expires' => time() + 60 * 60 * 24 * 90,
                'path' => '/',
                'secure' => Http::isHttps(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }
    }

    private static function mint(string $playerId): string
    {
        for ($i = 0; $i < 8; $i++) {
            $code = strtoupper(substr(strtr(base_convert(hash('sha256', 'cc-ref|' . $playerId . '|' . $i), 16, 36), '01oil', 'XYZKQ'), 0, 8));
            $code = str_pad($code, 8, 'X');
            if (Db::value('SELECT 1 FROM cc_referrals WHERE code = ?', [$code]) === null) {
                return $code;
            }
        }
        return strtoupper(substr(bin2hex(random_bytes(6)), 0, 8));
    }
}
