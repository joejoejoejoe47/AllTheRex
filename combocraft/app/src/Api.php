<?php

declare(strict_types=1);

namespace ComboCraft;

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
                    Game::ensureBase();
                    $stage = 'query';
                    Db::value('SELECT 1');
                } catch (Throwable $e) {
                    // Full detail goes to the server log only.
                    error_log('[combocraft db ' . $stage . '] ' . $e::class . ': ' . $e->getMessage());
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
                    error_log('[combocraft health] not ready: ' . $reason . ($code !== null ? ' (' . $code . ')' : '') . ' source=' . ($diag['source'] ?? 'none') . ' chess=' . $diag['chess']);
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
                throw new HttpError('ComboCraft database is not ready.', 503);
            }

            if (preg_match('#^/api/element/(\d+)$#', $path, $m)) {
                self::get();
                $e = Game::element((int) $m[1]);
                if ($e === null) {
                    throw new HttpError('Not found', 404);
                }
                Http::json(['ok' => true, 'element' => Game::view($e, true)]);
            }

            match ($path) {
                '/api/me' => self::me(),
                '/api/nickname' => self::mutating(fn () => Http::json(['ok' => true, 'me' => Player::setNickname((string) (Http::jsonBody()['nickname'] ?? ''))])),
                '/api/combine' => self::mutating(fn () => self::combine()),
                '/api/combine/resolve' => self::mutating(fn () => self::resolve()),
                '/api/leaderboard' => self::leaderboardRoute(),
                '/api/gallery' => self::galleryRoute(),
                '/api/chat' => Http::method() === 'POST' ? self::mutating(fn () => self::chatPost()) : self::chatList(),
                '/api/chat/react' => self::mutating(fn () => Http::json(Chat::react((int) (Http::jsonBody()['id'] ?? 0), (string) (Http::jsonBody()['emoji'] ?? '')))),
                default => throw new HttpError('Not found', 404),
            };
        } catch (HttpError $e) {
            Http::json(['ok' => false, 'error' => $e->getMessage(), 'status' => $e->status], $e->status);
        } catch (Throwable $e) {
            error_log('[combocraft api ' . $path . '] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
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
        if (Http::header('X-CC') !== '1') {
            throw new HttpError('Forbidden', 403);
        }
        $fn();
    }

    private static function get(): void
    {
        if (Http::method() !== 'GET') {
            throw new HttpError('Method not allowed', 405);
        }
    }

    private static function me(): never
    {
        self::get();
        $p = Player::require();
        Http::json([
            'ok' => true,
            'me' => Player::publicView($p),
            'elements' => Game::collection($p),
            'recent' => self::recent($p),
        ]);
    }

    /** @param array<string,mixed> $p @return list<array<string,mixed>> */
    private static function recent(array $p): array
    {
        $rows = Db::all('SELECT c.element_id, c.via_a, c.via_b FROM cc_collection c WHERE c.player_id = ? ORDER BY c.created_at DESC, c.element_id DESC LIMIT 12', [$p['id']]);
        $out = [];
        foreach ($rows as $r) {
            $e = Game::element((int) $r['element_id']);
            $a = $r['via_a'] ? Game::element((int) $r['via_a']) : null;
            $b = $r['via_b'] ? Game::element((int) $r['via_b']) : null;
            if ($e && $a && $b) {
                $out[] = ['result' => Game::view($e) + ['mineFirst' => ($e['discovered_by'] ?? null) === $p['id']], 'inputs' => [Game::mini($a), Game::mini($b)]];
            }
        }
        return $out;
    }

    private static function combine(): never
    {
        $b = Http::jsonBody();
        try {
            Http::json(['ok' => true] + Game::combine((int) ($b['a'] ?? 0), (int) ($b['b'] ?? 0), !empty($b['relay'])));
        } catch (HttpError $e) {
            if ($e->status === 429 && $e->getMessage() === 'limit') {
                Http::json(['ok' => false, 'limit' => true, 'error' => 'You used all ' . Player::FREE_DAILY . ' free combinations for today. Come back tomorrow!', 'status' => 429], 429);
            }
            throw $e;
        }
    }

    private static function resolve(): never
    {
        $b = Http::jsonBody();
        $content = isset($b['content']) && is_string($b['content']) ? mb_substr($b['content'], 0, 8000) : null;
        Http::json(['ok' => true] + Game::resolve((string) ($b['ticket'] ?? ''), $content));
    }

    private static function leaderboardRoute(): never
    {
        self::get();
        $me = Player::current();
        $rows = Game::leaderboard();
        $mine = null;
        if ($me !== null && (int) $me['firsts'] > 0) {
            $better = (int) Db::value('SELECT COUNT(*) FROM cc_players WHERE firsts > ?', [(int) $me['firsts']]);
            $mine = ['rank' => $better + 1, 'firsts' => (int) $me['firsts']];
        }
        Http::json(['ok' => true, 'rows' => $rows, 'me' => $me ? $me['id'] : null, 'mine' => $mine]);
    }

    private static function galleryRoute(): never
    {
        self::get();
        $sort = (string) ($_GET['sort'] ?? 'latest');
        Http::json(['ok' => true, 'items' => Game::gallery(in_array($sort, ['latest', 'popular', 'featured'], true) ? $sort : 'latest')]);
    }

    private static function chatList(): never
    {
        self::get();
        Http::json(['ok' => true] + Chat::list((int) ($_GET['after'] ?? 0)));
    }

    private static function chatPost(): never
    {
        $b = Http::jsonBody();
        Http::json(Chat::post((string) ($b['body'] ?? ''), isset($b['elementId']) ? (int) $b['elementId'] : null));
    }
}
