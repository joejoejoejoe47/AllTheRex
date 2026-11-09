<?php

declare(strict_types=1);

namespace WorldDomination;

/** Request / response helpers. */
final class Http
{
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        $fwd = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return $fwd === 'https' || str_starts_with($fwd, 'https,');
    }

    public static function host(): string
    {
        $raw = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = trim(explode(',', $raw)[0]);
        return $host !== '' ? $host : 'localhost';
    }

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $v = $_SERVER[$key] ?? null;
        return $v === null ? null : (string) $v;
    }

    /** Cookie path limited to this app folder so Morse Chess sessions stay separate. */
    public static function cookiePath(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        return ($dir === '' || $dir === '.') ? '/' : $dir;
    }

    /** @return array<string,mixed> */
    public static function jsonBody(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return $cache = [];
        }
        $data = json_decode($raw, true);
        return $cache = (is_array($data) ? $data : []);
    }

    public static function resetJsonBodyCache(): void
    {
        // test helper via reflection-less approach: not needed; tests call methods directly
    }

    public static function assertSameSite(): void
    {
        $site = self::header('Sec-Fetch-Site');
        if ($site !== null && $site !== '' && !in_array($site, ['same-origin', 'none'], true)) {
            $dest = self::header('Sec-Fetch-Dest');
            $topLevelGet = self::header('Sec-Fetch-Mode') === 'navigate'
                && self::method() === 'GET'
                && $dest !== 'object' && $dest !== 'embed';
            if (!$topLevelGet) {
                throw new HttpError('Forbidden: cross-site request blocked', 403);
            }
        }
        $origin = self::header('Origin');
        if ($origin !== null && $origin !== '' && $origin !== 'null') {
            $originHost = parse_url($origin, PHP_URL_HOST);
            $originPort = parse_url($origin, PHP_URL_PORT);
            $originAuth = strtolower((string) $originHost . ($originPort ? ':' . $originPort : ''));
            $own = strtolower(self::host());
            $trusted = array_filter(array_map('trim', explode(',', Config::get('TRUSTED_ORIGINS', '') ?? '')));
            $trustedHosts = [];
            foreach ($trusted as $o) {
                $h = parse_url($o, PHP_URL_HOST);
                $p = parse_url($o, PHP_URL_PORT);
                $trustedHosts[] = strtolower((string) $h . ($p ? ':' . $p : ''));
            }
            $appUrl = Config::get('APP_URL');
            if ($appUrl) {
                $h = parse_url($appUrl, PHP_URL_HOST);
                $p = parse_url($appUrl, PHP_URL_PORT);
                $trustedHosts[] = strtolower((string) $h . ($p ? ':' . $p : ''));
            }
            if ($originAuth !== $own && !in_array($originAuth, $trustedHosts, true)) {
                throw new HttpError('Forbidden: cross-site request blocked', 403);
            }
        }
    }

    public static function json(mixed $data, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }
        echo json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        exit;
    }

    public static function setCookie(string $name, string $value, int $expires, bool $httpOnly = true): void
    {
        if (!headers_sent()) {
            setcookie($name, $value, [
            'expires' => $expires,
            'path' => self::cookiePath(),
            'secure' => self::isHttps(),
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
            ]);
        }
    }

    public static function cookie(string $name): ?string
    {
        $v = $_COOKIE[$name] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }
}
