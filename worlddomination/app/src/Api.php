<?php

declare(strict_types=1);

namespace WorldDomination;

use Throwable;

/** Routes everything under /api/. */
final class Api
{
    public static function handle(string $path): never
    {
        try {
            $ready = Config::dbConfigured();
            $reason = $ready ? null : 'no_config';
            $code = null;
            if ($ready) {
                $stage = 'connect';
                try {
                    Db::pdo();
                    $stage = 'schema';
                    Db::ensureSchema();
                    $stage = 'query';
                    Db::value('SELECT 1');
                } catch (Throwable $e) {
                    // Full detail goes to the server log only.
                    error_log('[worlddomination db ' . $stage . '] ' . $e::class . ': ' . $e->getMessage());
                    $ready = false;
                    $reason = $stage . '_failed';
                    $code = self::driverCode($e);
                }
            }

            if ($path === '/api/health') {
                if (!$ready) {
                    $diag = Config::diagnose();
                    if ($reason === 'no_config' && in_array($diag['chess'], ['open_basedir', 'unreadable'], true)) {
                        $reason = 'chess_env_unreadable';
                    }
                    error_log('[worlddomination health] not ready: ' . $reason . ($code !== null ? ' (' . $code . ')' : '') . ' source=' . ($diag['source'] ?? 'none') . ' chess=' . $diag['chess']);
                    Http::json([
                        'ok' => false,
                        'db' => false,
                        'setupRequired' => true,
                        'reason' => $reason,
                        'code' => $code,
                        'source' => $diag['source'],
                        'chessEnv' => $diag['chess'],
                    ], 503);
                }
                Http::json(['ok' => true, 'db' => true, 'driver' => Db::driver(), 'source' => Config::diagnose()['source']]);
            }

            if (!$ready) {
                throw new HttpError('World Domination database is not ready.', 503);
            }

            match ($path) {
                '/api/auth/session' => self::session(),
                '/api/auth/signup' => self::mutating(fn () => self::signup()),
                '/api/auth/login' => self::mutating(fn () => self::login()),
                '/api/auth/logout' => self::mutating(fn () => self::logout()),
                '/api/leaderboard' => self::leaderboard(),
                '/api/match/start' => self::mutating(fn () => self::matchStart()),
                '/api/match/finish' => self::mutating(fn () => self::matchFinish()),
                default => throw new HttpError('Not found', 404),
            };
        } catch (HttpError $e) {
            Http::json(['ok' => false, 'error' => $e->getMessage(), 'status' => $e->status], $e->status);
        } catch (Throwable $e) {
            error_log('[worlddomination api ' . $path . '] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $message = Config::bool('APP_DEBUG') ? $e->getMessage() : 'Server error. Try once more.';
            Http::json(['ok' => false, 'error' => $message, 'status' => 500], 500);
        }
    }

    /** Numeric driver/SQLSTATE code only (e.g. 1045, 2002, 1142); never the message. */
    private static function driverCode(Throwable $e): int|string|null
    {
        if ($e instanceof \PDOException) {
            $n = $e->errorInfo[1] ?? null;
            if (is_int($n) || (is_string($n) && ctype_digit($n))) {
                return (int) $n;
            }
            $c = $e->getCode();
            if (is_int($c) && $c !== 0) {
                return $c;
            }
            if (is_string($c) && preg_match('/^[0-9A-Z]{5}$/', $c)) {
                return $c;
            }
            if (preg_match('/SQLSTATE\[([0-9A-Z]{5})\][^0-9]*\[?(\d{3,5})?/', $e->getMessage(), $m)) {
                return isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $m[1];
            }
        }
        return null;
    }

    /** @param callable():never $fn */
    private static function mutating(callable $fn): never
    {
        if (Http::method() !== 'POST') {
            throw new HttpError('Method not allowed', 405);
        }
        Http::assertSameSite();
        Auth::assertCsrf();
        $fn();
    }

    private static function session(): never
    {
        if (Http::method() !== 'GET') {
            throw new HttpError('Method not allowed', 405);
        }
        $user = Auth::currentUser();
        Http::json([
            'user' => $user,
            'csrf' => Auth::csrfToken(),
            'setupRequired' => false,
        ]);
    }

    private static function signup(): never
    {
        $b = Http::jsonBody();
        $user = Auth::signUp((string) ($b['email'] ?? ''), (string) ($b['password'] ?? ''), (string) ($b['name'] ?? ''));
        Http::json(['ok' => true, 'user' => $user, 'csrf' => Auth::csrfToken()]);
    }

    private static function login(): never
    {
        $b = Http::jsonBody();
        $user = Auth::signIn((string) ($b['email'] ?? ''), (string) ($b['password'] ?? ''));
        Http::json(['ok' => true, 'user' => $user, 'csrf' => Auth::csrfToken()]);
    }

    private static function logout(): never
    {
        Auth::signOut();
        Http::json(['ok' => true, 'user' => null, 'csrf' => Auth::csrfToken()]);
    }

    private static function leaderboard(): never
    {
        if (Http::method() !== 'GET') {
            throw new HttpError('Method not allowed', 405);
        }
        Http::json(['ok' => true, 'podium' => Leaderboard::podium()]);
    }

    private static function matchStart(): never
    {
        $user = Auth::requireUser();
        $b = Http::jsonBody();
        $match = Leaderboard::startMatch($user['id'], (string) ($b['country'] ?? ''));
        Http::json(['ok' => true, 'match' => $match]);
    }

    private static function matchFinish(): never
    {
        $user = Auth::requireUser();
        $result = Leaderboard::finishMatch($user['id'], Http::jsonBody());
        Http::json(['ok' => true] + $result);
    }
}
