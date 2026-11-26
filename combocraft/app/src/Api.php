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
            if (preg_match('#^/api/recipe/(\d+)$#', $path, $m)) {
                self::get();
                Http::json(['ok' => true] + Book::about((int) $m[1], Player::current()));
            }
            if (preg_match('#^/api/discovery/(\d+)$#', $path, $m)) {
                self::get();
                Http::json(['ok' => true] + Discover::about((int) $m[1], Player::current(), true));
            }
            if (preg_match('#^/api/discovery/(\d+)/(like|comment|post)$#', $path, $m)) {
                self::mutating(function () use ($m) {
                    $id = (int) $m[1];
                    $p = Player::require();
                    $out = match ($m[2]) {
                        'like' => Discover::like($id, $p),
                        'comment' => Discover::comment($id, $p, (string) (Http::jsonBody()['body'] ?? '')),
                        default => Discover::post($id, $p),
                    };
                    Http::json(['ok' => true] + $out);
                });
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
                '/api/daily' => self::dailyRoute(),
                '/api/prefs' => Http::method() === 'POST' ? self::mutating(fn () => Http::json(['ok' => true] + Prefs::set(Player::require(), (string) (Http::jsonBody()['bg'] ?? ''), !empty(Http::jsonBody()['reset'])))) : self::prefsGet(),
                '/api/prefs/bg' => self::mutating(fn () => Http::json(['ok' => true] + Prefs::upload(Player::require()))),
                '/api/hint' => self::mutating(fn () => Http::json(['ok' => true] + Hint::give(Player::require()))),
                '/api/hint/buy' => self::mutating(fn () => Http::json(Hint::buy(Player::require()))),
                '/api/auth/signin' => self::mutating(fn () => Http::json(MorseAuth::signIn(Player::require(), (string) (Http::jsonBody()['username'] ?? ''), (string) (Http::jsonBody()['password'] ?? '')))),
                '/api/auth/signup' => self::mutating(fn () => Http::json(MorseAuth::signUp(Player::require(), (string) (Http::jsonBody()['username'] ?? ''), (string) (Http::jsonBody()['password'] ?? '')))),
                '/api/auth/signout' => self::mutating(fn () => Http::json(MorseAuth::signOut())),
                '/api/auth/probe' => self::authProbe(),
                '/api/achievements' => self::goalsRoute(),
                '/api/speedrun' => self::speedrunDisabled(),
                '/api/speedrun/start' => self::speedrunDisabled(),
                '/api/speedrun/combine' => self::speedrunDisabled(),
                '/api/speedrun/board' => self::speedrunDisabled(),
                '/api/morse' => self::morseGet(),
                '/api/morse/link' => self::mutating(fn () => Http::json(['ok' => true] + MorseLink::link(Player::require()))),
                '/api/clash/inbox' => self::clashInbox(),
                '/api/clash/queue' => self::mutating(fn () => Http::json(['ok' => true] + Clash::queue(Player::require(), (string) (Http::jsonBody()['pair'] ?? '')))),
                '/api/clash/queue/cancel' => self::mutating(fn () => Http::json(['ok' => true] + Clash::cancelQueue(Player::require()))),
                '/api/clash/challenge' => self::mutating(fn () => Http::json(['ok' => true] + Clash::challenge(Player::require(), (string) (Http::jsonBody()['nickname'] ?? '')))),
                '/api/clash/ai' => self::mutating(fn () => Http::json(['ok' => true] + Clash::challengeAi(Player::require(), (string) (Http::jsonBody()['level'] ?? ''), (string) (Http::jsonBody()['mode'] ?? '')))),
                '/api/clash/gift' => self::mutating(fn () => Http::json(['ok' => true] + ClashModes::chooseGift(Player::require(), (string) (Http::jsonBody()['matchId'] ?? ''), (string) (Http::jsonBody()['keep'] ?? 'a')))),
                '/api/clash/invite' => self::mutating(fn () => Http::json(['ok' => true] + Clash::makeInvite(Player::require()))),
                '/api/clash/invite/open' => self::mutating(fn () => Http::json(['ok' => true] + Clash::openInvite(Player::require(), (string) (Http::jsonBody()['id'] ?? '')))),
                '/api/clash/respond' => self::mutating(fn () => Http::json(['ok' => true] + Clash::respond(Player::require(), (string) (Http::jsonBody()['id'] ?? ''), !empty(Http::jsonBody()['accept'])))),
                '/api/clash/match' => self::clashMatch(),
                '/api/clash/loadout' => self::mutating(fn () => Http::json(['ok' => true] + Clash::setLoadout(Player::require(), (string) (Http::jsonBody()['matchId'] ?? ''), is_array(Http::jsonBody()['ids'] ?? null) ? Http::jsonBody()['ids'] : []))),
                '/api/clash/ready' => self::mutating(fn () => Http::json(['ok' => true] + Clash::ready(Player::require(), (string) (Http::jsonBody()['matchId'] ?? '')))),
                '/api/clash/play' => self::mutating(fn () => Http::json(['ok' => true] + Clash::play(Player::require(), (string) (Http::jsonBody()['matchId'] ?? ''), (string) (Http::jsonBody()['a'] ?? ''), (string) (Http::jsonBody()['b'] ?? ''), !empty(Http::jsonBody()['attack']), false, !empty(Http::jsonBody()['burst'])))),
                '/api/clash/quick' => self::mutating(fn () => Http::json(['ok' => true] + Clash::quick(Player::require(), (string) (Http::jsonBody()['matchId'] ?? ''), (string) (Http::jsonBody()['phrase'] ?? '')))),
                '/api/clash/forfeit' => self::mutating(fn () => Http::json(['ok' => true] + Clash::forfeit(Player::require(), (string) (Http::jsonBody()['matchId'] ?? '')))),
                '/api/clash/revenge' => self::mutating(fn () => Http::json(['ok' => true] + Clash::revenge(Player::require(), (string) (Http::jsonBody()['matchId'] ?? '')))),
                '/api/clash/rematch' => self::mutating(function () {
                    $body = Http::jsonBody();
                    $p = Player::require();
                    $id = (string) ($body['matchId'] ?? '');
                    if (!empty($body['cancel']) || !empty($body['decline'])) {
                        return Http::json(['ok' => true] + ClashRematch::decline($p, $id));
                    }
                    return Http::json(['ok' => true] + ClashRematch::vote($p, $id));
                }),
                '/api/clash/board' => self::clashBoard(),
                '/api/clash/replays' => self::clashReplays(),
                '/api/clash/party/invite' => self::mutating(fn () => Http::json(['ok' => true] + Party::invite(Player::require(), (string) (Http::jsonBody()['nickname'] ?? '')))),
                '/api/clash/party/respond' => self::mutating(fn () => Http::json(['ok' => true] + Party::respond(Player::require(), (string) (Http::jsonBody()['id'] ?? ''), !empty(Http::jsonBody()['accept'])))),
                '/api/clash/party/name' => self::mutating(fn () => Http::json(['ok' => true] + Party::setName(Player::require(), (string) (Http::jsonBody()['name'] ?? '')))),
                '/api/clash/party/leave' => self::mutating(fn () => Http::json(['ok' => true] + Party::leave(Player::require()))),
                '/api/clash/party/boot' => self::mutating(fn () => Http::json(['ok' => true] + Party::boot(Player::require(), (string) (Http::jsonBody()['playerId'] ?? '')))),
                '/api/clash/party/ready' => self::mutating(fn () => Http::json(['ok' => true] + Party::ready(Player::require(), (string) (Http::jsonBody()['pair'] ?? '')))),
                '/api/clash/party/cancel' => self::mutating(fn () => Http::json(['ok' => true] + Party::cancelReady(Player::require()))),
                '/api/clash/party/ai' => self::mutating(fn () => Http::json(['ok' => true] + Party::challengeAi(Player::require(), (string) (Http::jsonBody()['level'] ?? '')))),
                '/api/clash/party/match' => self::partyMatch(),
                '/api/clash/party/play' => self::mutating(fn () => Http::json(['ok' => true] + Party::play(Player::require(), (string) (Http::jsonBody()['matchId'] ?? ''), (string) (Http::jsonBody()['a'] ?? ''), (string) (Http::jsonBody()['b'] ?? '')))),
                '/api/clash/party/chat' => Http::method() === 'POST' ? self::mutating(fn () => Http::json(['ok' => true] + Party::chatPost(Player::require(), (string) (Http::jsonBody()['body'] ?? '')))) : self::partyChat(),
                '/api/clash/party/exit' => self::mutating(fn () => Http::json(['ok' => true] + Party::exitMatch(Player::require(), (string) (Http::jsonBody()['matchId'] ?? '')))),
                '/api/clash/rtc' => Party::rtc(),
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
        try {
            Referral::remember();
        } catch (Throwable $e) {
            error_log('[combocraft me referral] ' . $e->getMessage());
        }
        Http::json([
            'ok' => true,
            'me' => Player::publicView($p),
            'elements' => self::mePart('collection', fn () => Game::collection($p), []),
            'recent' => self::mePart('recent', fn () => self::recent($p), []),
            'daily' => self::mePart('daily', fn () => Daily::status($p), null),
            'deepestTree' => self::mePart('deepestTree', fn () => Tree::headline(), null),
            'mineTree' => self::mePart('mineTree', fn () => Tree::mine($p), null),
            'hints' => self::mePart('hints', fn () => Hint::remaining($p), ['used' => 0, 'limit' => 3, 'left' => 3]),
            'progress' => self::mePart('progress', fn () => Goals::progress($p), null),
            'badges' => self::mePart('badges', fn () => Goals::all($p), []),
            'referral' => self::mePart('referral', fn () => Referral::for($p), null),
            'morse' => self::mePart('morse', fn () => MorseLink::status($p), MorseLink::guestStatus()),
            'prefs' => self::mePart('prefs', fn () => Prefs::get($p), ['bg' => 'stadium', 'file' => null, 'url' => null]),
        ]);
    }

    /** @param callable():mixed $fn */
    private static function mePart(string $label, callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            error_log('[combocraft me ' . $label . '] ' . $e::class . ': ' . $e->getMessage());
            return $fallback;
        }
    }

    private static function prefsGet(): never
    {
        self::get();
        Http::json(['ok' => true] + Prefs::get(Player::require()));
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
        $out = Game::resolve((string) ($b['ticket'] ?? ''), $content);
        $runId = isset($b['runId']) && is_string($b['runId']) ? $b['runId'] : '';
        if ($runId !== '') {
            $out = Speedrun::afterResolve(Player::require(), $runId, $out);
        }
        Http::json(['ok' => true] + $out);
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
        $trees = Tree::board($me);
        Http::json(['ok' => true, 'rows' => $rows, 'me' => $me ? $me['id'] : null, 'mine' => $mine, 'trees' => $trees]);
    }

    private static function galleryRoute(): never
    {
        self::get();
        $sort = (string) ($_GET['sort'] ?? 'latest');
        if ($sort === 'posted') {
            $items = Discover::posted();
        } else {
            $allowed = ['latest', 'popular', 'featured', 'loved'];
            $items = Discover::decorate(Game::gallery(in_array($sort, $allowed, true) ? ($sort === 'loved' ? 'latest' : $sort) : 'latest'));
            if ($sort === 'loved') {
                usort($items, static fn ($a, $b) => ($b['likes'] ?? 0) <=> ($a['likes'] ?? 0));
            }
        }
        Http::json(['ok' => true, 'items' => $items]);
    }

    private static function chatList(): never
    {
        self::get();
        Http::json(['ok' => true] + Chat::list((int) ($_GET['after'] ?? 0)));
    }

    private static function chatPost(): never
    {
        $b = Http::jsonBody();
        $out = Chat::post((string) ($b['body'] ?? ''), isset($b['elementId']) ? (int) $b['elementId'] : null);
        try {
            $p = Player::require();
            $new = Goals::unlock($p, 'chatty');
            if ($new) {
                $out['badges'] = $new;
            }
        } catch (Throwable $e) {
        }
        Http::json($out);
    }

    private static function dailyRoute(): never
    {
        self::get();
        $p = Player::current() ?? Player::require();
        Http::json(['ok' => true] + Daily::status($p));
    }

    private static function goalsRoute(): never
    {
        self::get();
        $p = Player::require();
        Http::json(['ok' => true, 'badges' => Goals::all($p), 'progress' => Goals::progress($p)]);
    }

    private static function speedrunDisabled(): never
    {
        throw new HttpError('Speedrun is not available.', 404);
    }

    private static function speedrunGet(): never
    {
        self::get();
        $p = Player::require();
        $id = (string) ($_GET['id'] ?? '');
        if ($id !== '') {
            Http::json(['ok' => true] + Speedrun::viewRun($id, $p));
        }
        Http::json(['ok' => true, 'targets' => Speedrun::targets()]);
    }

    private static function speedrunCombine(): never
    {
        $b = Http::jsonBody();
        try {
            Http::json(['ok' => true] + Speedrun::combine(Player::require(), (string) ($b['runId'] ?? ''), (int) ($b['a'] ?? 0), (int) ($b['b'] ?? 0)));
        } catch (HttpError $e) {
            if ($e->status === 429 && $e->getMessage() === 'limit') {
                Http::json(['ok' => false, 'limit' => true, 'error' => 'You used all ' . Player::FREE_DAILY . ' free combinations for today. Come back tomorrow!', 'status' => 429], 429);
            }
            throw $e;
        }
    }

    private static function speedrunBoard(): never
    {
        self::get();
        $tid = isset($_GET['target']) ? (int) $_GET['target'] : null;
        Http::json(['ok' => true] + Speedrun::board($tid ?: null));
    }

    private static function authProbe(): never
    {
        self::get();
        Http::json(MorseAuth::probe((string) ($_GET['username'] ?? '')));
    }

    private static function morseGet(): never
    {
        self::get();
        Http::json(['ok' => true] + MorseLink::status(Player::require()));
    }

    private static function clashInbox(): never
    {
        self::get();
        Http::json(['ok' => true] + Clash::inbox(Player::require()));
    }

    private static function clashMatch(): never
    {
        self::get();
        Http::json(['ok' => true] + Clash::match(Player::require(), (string) ($_GET['id'] ?? '')));
    }

    private static function clashBoard(): never
    {
        self::get();
        $kind = (string) ($_GET['kind'] ?? 'wins');
        if (!in_array($kind, ['wins', 'rare', 'daily'], true)) {
            $kind = 'wins';
        }
        Http::json(['ok' => true] + Clash::board($kind));
    }

    private static function clashReplays(): never
    {
        self::get();
        Http::json(['ok' => true, 'replays' => Party::replays()]);
    }

    private static function partyMatch(): never
    {
        self::get();
        Http::json(['ok' => true] + Party::match(Player::require(), (string) ($_GET['id'] ?? '')));
    }

    private static function partyChat(): never
    {
        self::get();
        Http::json(['ok' => true] + Party::chatList(Player::require(), (int) ($_GET['after'] ?? 0)));
    }
}
