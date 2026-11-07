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
            if ($ready) {
                try {
                    Db::ensureSchema();
                } catch (Throwable $e) {
                    error_log('[worlddomination api schema] ' . $e->getMessage());
                    if ($path === '/api/health') {
                        Http::json(['ok' => true, 'db' => false, 'setupRequired' => true], 200);
                    }
                    $ready = false;
                }
            }

            if ($path === '/api/health') {
                if (!$ready) {
                    Http::json(['ok' => true, 'db' => false, 'setupRequired' => true]);
                }
                Db::value('SELECT 1');
                Http::json(['ok' => true, 'db' => true, 'driver' => Db::driver()]);
            }

            if (!$ready) {
                if ($path === '/api/auth/session' && Http::method() === 'GET') {
                    Http::json([
                        'user' => null,
                        'csrf' => Auth::csrfToken(),
                        'setupRequired' => true,
                    ]);
                }
                throw new HttpError('World Domination still needs a one-time database setup.', 503);
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
