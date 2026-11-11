<?php

declare(strict_types=1);

namespace SaintQuest;

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
                    error_log('[saintquest db ' . $stage . '] ' . $e::class . ': ' . $e->getMessage());
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
                    error_log('[saintquest health] not ready: ' . $reason . ($code !== null ? ' (' . $code . ')' : '') . ' source=' . ($diag['source'] ?? 'none') . ' chess=' . $diag['chess']);
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
                throw new HttpError('Saint Quest database is not ready.', 503);
            }

            $user = null;
            if ($path !== '/api/session') {
                $user = Auth::currentUser();
            }
            $uid = fn (): string => Auth::requireUser()['id'];
            match ($path) {
                '/api/session' => self::get(fn () => self::session()),
                '/api/auth/signup' => self::post(fn () => self::signup()),
                '/api/auth/login' => self::post(fn () => self::login()),
                '/api/auth/logout' => self::post(function () { Auth::signOut(); Http::json(['ok' => true, 'csrf' => Auth::csrfToken()]); }),
                '/api/me' => self::get(fn () => Http::json(['ok' => true, 'me' => Saints::me($uid())])),
                '/api/leaderboard' => self::get(fn () => Http::json(['ok' => true, 'top' => Saints::leaderboard(10)])),
                '/api/store/buy' => self::post(fn () => Http::json(['ok' => true] + Saints::buyFromStore($uid(), self::str('key')))),
                '/api/market' => self::get(fn () => Http::json(['ok' => true, 'listings' => Market::open((string) ($_GET['q'] ?? ''), 60)])),
                '/api/market/list' => self::post(fn () => Http::json(['ok' => true] + Market::list($uid(), self::str('saintId'), self::int('price'), self::str('benefit')))),
                '/api/market/cancel' => self::post(function () use ($uid) { Market::cancel($uid(), self::str('listingId')); Http::json(['ok' => true]); }),
                '/api/market/buy' => self::post(fn () => Http::json(['ok' => true] + Market::buy($uid(), self::str('listingId')))),
                '/api/saint' => self::get(fn () => Http::json(['ok' => true] + Market::saint((string) ($_GET['id'] ?? ''), $user['id'] ?? null))),
                '/api/player' => self::get(fn () => Http::json(['ok' => true, 'player' => Saints::player((string) ($_GET['name'] ?? ''))])),
                '/api/search' => self::get(fn () => Http::json(['ok' => true] + Saints::search((string) ($_GET['q'] ?? '')))),
                '/api/comments' => self::post(fn () => self::comment($uid())),
                '/api/comments/report' => self::post(fn () => Http::json(['ok' => true] + Comments::report($uid(), self::str('id')))),
                '/api/comments/hide' => self::post(function () use ($uid) { Comments::setHidden($uid(), self::str('id'), self::bool('hidden')); Http::json(['ok' => true]); }),
                '/api/image' => self::get(fn () => Images::serve((string) ($_GET['id'] ?? ''), $user['id'] ?? null)),
                '/api/cards/print' => self::post(fn () => Http::json(['ok' => true, 'cards' => Cards::print($uid(), self::str('key') ?: 'random', self::int('count') ?: 1)])),
                '/api/cards/printed' => self::get(fn () => Http::json(['ok' => true, 'cards' => Cards::printedUnclaimed($uid())])),
                '/api/cards/scan' => self::post(fn () => Http::json(['ok' => true] + Cards::scan($uid(), self::str('code')))),
                '/api/claims/respond' => self::post(fn () => Http::json(['ok' => true] + Cards::respond($uid(), self::str('id'), self::bool('traded')))),
                '/api/claims/photo' => self::post(fn () => self::claimPhoto($uid())),
                '/api/claims/withdraw' => self::post(fn () => Http::json(['ok' => true] + Cards::withdraw($uid(), self::str('id')))),
                '/api/notifications' => self::get(fn () => Http::json(['ok' => true, 'items' => Notify::list($uid()), 'questions' => Cards::openQuestions($uid())])),
                '/api/notifications/seen' => self::post(function () use ($uid) { Notify::markSeen($uid()); Http::json(['ok' => true]); }),
                '/api/cases' => self::get(fn () => Http::json(['ok' => true, 'cases' => Cases::status($uid())])),
                '/api/cases/do' => self::post(fn () => Http::json(['ok' => true] + Cases::complete($uid(), self::str('case')))),
                '/api/blessing/claim' => self::post(fn () => Http::json(['ok' => true] + Cases::claimBlessing($uid()))),
                '/api/mystery/solve' => self::post(fn () => Http::json(['ok' => true] + Cases::solveMystery($uid()))),
                '/api/deeds' => self::post(fn () => Http::json(['ok' => true] + Deeds::complete($uid(), self::str('deed')))),
                '/api/battle/start' => self::post(fn () => Http::json(['ok' => true] + Battles::start($uid(), self::str('game'), self::str('saintId')))),
                '/api/battle/finish' => self::post(fn () => Http::json(['ok' => true] + Battles::finish($uid(), self::str('battleId'), self::str('token'), self::int('score'), self::bool('won')))),
                default => throw new HttpError('Not found', 404),
            };
        } catch (HttpError $e) {
            Http::json(['ok' => false, 'error' => $e->getMessage(), 'status' => $e->status], $e->status);
        } catch (Throwable $e) {
            error_log('[saintquest api ' . $path . '] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
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

    private static function get(callable $fn): never
    {
        if (Http::method() !== 'GET') {
            throw new HttpError('Method not allowed', 405);
        }
        $fn();
        exit;
    }

    private static function post(callable $fn): never
    {
        if (Http::method() !== 'POST') {
            throw new HttpError('Method not allowed', 405);
        }
        Http::assertSameSite();
        Auth::assertCsrf();
        $fn();
        exit;
    }

    private static function input(string $key): mixed
    {
        $b = Http::jsonBody();
        return array_key_exists($key, $b) ? $b[$key] : ($_POST[$key] ?? null);
    }

    private static function str(string $key): string
    {
        $v = self::input($key);
        return is_scalar($v) ? trim((string) $v) : '';
    }

    private static function int(string $key): int
    {
        $v = self::input($key);
        return is_numeric($v) ? (int) $v : 0;
    }

    private static function bool(string $key): bool
    {
        $v = self::input($key);
        return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'yes';
    }

    private static function session(): never
    {
        $user = Auth::currentUser();
        Http::json(['ok' => true, 'me' => $user ? Saints::me($user['id']) : null, 'csrf' => Auth::csrfToken()]);
    }

    private static function signup(): never
    {
        $u = Auth::signUp(self::str('username'), (string) (self::input('password') ?? ''));
        Http::json(['ok' => true, 'me' => Saints::me($u['id']), 'csrf' => Auth::csrfToken()]);
    }

    private static function login(): never
    {
        $u = Auth::signIn(self::str('username'), (string) (self::input('password') ?? ''));
        Http::json(['ok' => true, 'me' => Saints::me($u['id']), 'csrf' => Auth::csrfToken()]);
    }

    private static function comment(string $userId): never
    {
        Auth::throttle('comment:' . $userId, 20, 600, true);
        if (Db::value('SELECT 1 FROM sq_saints WHERE id = ?', [self::str('saintId')]) === null) {
            throw new HttpError('That saint was not found.', 404);
        }
        $imageId = Images::fromUpload('image', $userId, 'comment');
        Http::json(['ok' => true] + Comments::add($userId, self::str('saintId'), self::str('body'), $imageId));
    }

    private static function claimPhoto(string $userId): never
    {
        Auth::throttle('claimphoto:' . $userId, 10, 3600, true);
        $imageId = Images::fromUpload('image', $userId, 'card_photo');
        if ($imageId === null) {
            throw new HttpError('Choose a photo of the card first.', 400);
        }
        Http::json(['ok' => true] + Cards::addPhoto($userId, self::str('id'), $imageId));
    }
}
