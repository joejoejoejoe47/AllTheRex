<?php

declare(strict_types=1);

namespace ComboCraft;

use Throwable;

/** Elements, recipes, discoveries. */
final class Game
{
    /** Seed base elements and featured gallery picks (idempotent). */
    public static function ensureBase(): void
    {
        self::scrubCrowns();
        $flag = Config::storagePath('base-' . substr(sha1(json_encode([Seeds::BASE, Seeds::FEATURED]) . Db::driver()), 0, 12) . '.ok');
        if (is_file($flag) && Db::value('SELECT COUNT(*) FROM cc_elements WHERE is_base = 1') >= count(Seeds::BASE)) {
            return;
        }
        $now = Db::now();
        $i = 0;
        foreach (Seeds::BASE as $name => [$emoji, $tag]) {
            $i++;
            if (Db::value('SELECT id FROM cc_elements WHERE name_key = ?', [Text::key($name)]) === null) {
                self::insertIgnore('cc_elements', [
                    'name' => $name, 'name_key' => Text::key($name), 'emoji' => $emoji, 'tagline' => $tag, 'art' => null,
                    'label' => null, 'depth' => 0, 'is_base' => 1, 'sort_order' => $i, 'discovered_by' => null,
                    'discovered_at' => null, 'first_a' => null, 'first_b' => null, 'uses' => 0, 'source' => 'base', 'created_at' => $now,
                ]);
            }
        }
        $j = 0;
        foreach (Seeds::FEATURED as $name => $feat) {
            [$art, $label] = $feat;
            $j++;
            $key = Text::key($name);
            $existing = Db::one('SELECT id FROM cc_elements WHERE name_key = ?', [$key]);
            if ($existing !== null) {
                Db::run('UPDATE cc_elements SET art = ?, label = ?, sort_order = ? WHERE id = ?', [$art, $label, $j, $existing['id']]);
                continue;
            }
            $recipe = null;
            foreach (Seeds::RECIPES as $pair => $r) {
                if (Text::key($r[0]) === $key) {
                    $recipe = [$pair, $r];
                    break;
                }
            }
            if ($recipe === null) {
                continue;
            }
            [$pa, $pb] = isset(Seeds::FEATURED[$name][2]) ? array_map([Text::class, 'key'], Seeds::FEATURED[$name][2]) : explode('|', $recipe[0]);
            $ida = Db::value('SELECT id FROM cc_elements WHERE name_key = ?', [$pa]);
            $idb = Db::value('SELECT id FROM cc_elements WHERE name_key = ?', [$pb]);
            self::insertIgnore('cc_elements', [
                'name' => $recipe[1][0], 'name_key' => $key, 'emoji' => $recipe[1][1], 'tagline' => $recipe[1][2], 'art' => $art,
                'label' => $label, 'depth' => 1, 'is_base' => 0, 'sort_order' => $j, 'discovered_by' => null,
                'discovered_at' => null, 'first_a' => $ida, 'first_b' => $idb, 'uses' => 0, 'source' => 'seed', 'created_at' => $now,
            ]);
        }
        @file_put_contents($flag, gmdate('c'));
    }


    /** Replace any stored crown emoji so it cannot appear on a card. */
    private static function scrubCrowns(): void
    {
        $flag = Config::storagePath('scrub-crowns.ok');
        if (is_file($flag)) {
            return;
        }
        try {
            Db::run("UPDATE cc_elements SET emoji = ? WHERE name_key = ?", ['💰', 'dragon hoard']);
            Db::run("UPDATE cc_elements SET emoji = ? WHERE name_key = ?", ['🐱', 'cat overlords']);
            Db::run("UPDATE cc_elements SET emoji = REPLACE(emoji, '👑', '⭐') WHERE emoji LIKE ?", ['%👑%']);
            @file_put_contents($flag, gmdate('c'));
        } catch (Throwable $e) {
            error_log('[combocraft scrub-crowns] ' . $e->getMessage());
        }
    }

    /** @param array<string,mixed> $row */
    public static function insertPublic(string $table, array $row): void
    {
        self::insertIgnore($table, $row);
    }

    /** @param array<string,mixed> $row */
    private static function insertIgnore(string $table, array $row): void
    {
        $cols = array_keys($row);
        $verb = Db::isSqlite() ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        Db::run($verb . ' INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($row));
    }

    /** @return array<string,mixed>|null */
    public static function element(int $id): ?array
    {
        return Db::one('SELECT * FROM cc_elements WHERE id = ?', [$id]);
    }

    /** @param array<string,mixed> $e @return array<string,mixed> */
    public static function view(array $e, bool $withRecipe = false): array
    {
        $v = [
            'id' => (int) $e['id'],
            'name' => (string) $e['name'],
            'emoji' => Text::safeEmoji((string) $e['emoji']),
            'tagline' => (string) ($e['tagline'] ?? ''),
            'art' => $e['art'] ?: null,
            'label' => $e['label'] ?: null,
            'depth' => (int) $e['depth'],
            'base' => (int) $e['is_base'] === 1,
            'uses' => (int) $e['uses'],
            'rarity' => self::rarity((int) $e['depth'], (int) $e['uses']),
        ];
        if ($withRecipe) {
            $v['recipe'] = null;
            if (!empty($e['first_a']) && !empty($e['first_b'])) {
                $a = self::element((int) $e['first_a']);
                $b = self::element((int) $e['first_b']);
                if ($a && $b) {
                    $v['recipe'] = [self::mini($a), self::mini($b)];
                }
            }
            $v['discoveredBy'] = null;
            $v['discoveredAt'] = null;
            $owner = Firsts::ownerId((int) $e['id']) ?: ($e['discovered_by'] ?? null);
            if (!empty($owner)) {
                $p = Db::one('SELECT nickname, avatar FROM cc_players WHERE id = ?', [$owner]);
                $v['discoveredBy'] = $p ? ($p['nickname'] ?: 'Anonymous crafter') : null;
                $v['discoveredById'] = $owner;
                $v['discoveredByAvatar'] = $p['avatar'] ?? null;
                $v['discoveredAt'] = $e['discovered_at'] ? Db::toMs($e['discovered_at']) : null;
                $v['worldFirst'] = true;
            }
        }
        return $v;
    }

    /** @param array<string,mixed> $e @return array<string,mixed> */
    public static function mini(array $e): array
    {
        return ['id' => (int) $e['id'], 'name' => (string) $e['name'], 'emoji' => Text::safeEmoji((string) $e['emoji'])];
    }

    public static function rarity(int $depth, int $uses = 0): string
    {
        if ($depth <= 1) {
            return 'Common';
        }
        if ($depth === 2) {
            return 'Rare';
        }
        if ($depth === 3) {
            return 'Epic';
        }
        return 'Legendary';
    }

    /** @param array<string,mixed> $player @return list<array<string,mixed>> */
    public static function collection(array $player): array
    {
        $rows = Db::all('SELECT * FROM cc_elements WHERE is_base = 1 ORDER BY sort_order, id');
        $mine = Db::all(
            'SELECT e.*, c.created_at AS got_at, c.via_a, c.via_b FROM cc_collection c JOIN cc_elements e ON e.id = c.element_id WHERE c.player_id = ? AND e.is_base = 0 ORDER BY c.created_at, e.id',
            [$player['id']]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = self::view($r) + ['mineFirst' => false];
        }
        $owners = Firsts::owners(array_map(static fn ($r) => (int) $r['id'], $mine));
        foreach ($mine as $r) {
            $oid = $owners[(int) $r['id']] ?? ($r['discovered_by'] ?? null);
            $out[] = self::view($r) + [
                'mineFirst' => $oid === $player['id'],
                'via' => $r['via_a'] ? [(int) $r['via_a'], (int) $r['via_b']] : null,
                'gotAt' => Db::toMs($r['got_at']),
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $player */
    private static function owns(array $player, int $id): bool
    {
        $e = self::element($id);
        if ($e === null) {
            return false;
        }
        if ((int) $e['is_base'] === 1) {
            return true;
        }
        return Db::value('SELECT 1 FROM cc_collection WHERE player_id = ? AND element_id = ?', [$player['id'], $id]) !== null;
    }

    /** @return array<string,mixed> */
    public static function combine(int $a, int $b, bool $relayOk): array
    {
        $player = Player::require();
        if ($a <= 0 || $b <= 0 || !self::owns($player, $a) || !self::owns($player, $b)) {
            throw new HttpError('You can only combine elements you have.', 422);
        }
        [$x, $y] = $a <= $b ? [$a, $b] : [$b, $a];
        $recipe = Db::one('SELECT * FROM cc_recipes WHERE a_id = ? AND b_id = ?', [$x, $y]);
        if (!Player::spend($player)) {
            throw new HttpError('limit', 429);
        }
        if ($recipe !== null) {
            return self::finish($player, (int) $recipe['result_id'], $a, $b, false);
        }
        // Keep the player's order (A then B) for how the recipe is shown.
        $ea = self::element($a);
        $eb = self::element($b);
        if (!$ea || !$eb) {
            throw new HttpError('Unknown element.', 404);
        }
        $pairKey = Seeds::key((string) $ea['name'], (string) $eb['name']);
        $seed = Seeds::RECIPES[$pairKey] ?? null;
        if ($seed !== null) {
            return self::create($player, $ea, $eb, ['name' => $seed[0], 'emoji' => $seed[1], 'tagline' => $seed[2]], 'seed');
        }
        $ai = Ai::ask((string) $ea['name'], (string) $eb['name'], $pairKey);
        if ($ai['status'] === 'ok') {
            return self::create($player, $ea, $eb, $ai['data'], 'ai');
        }
        if ($ai['status'] === 'busy' && $relayOk) {
            return [
                'pending' => true,
                'ticket' => self::ticket($player['id'], $a, $b),
                'relay' => ['url' => Ai::POLLINATIONS, 'body' => Ai::pollinationsBody((string) $ea['name'], (string) $eb['name'], $pairKey)],
            ];
        }
        return self::create($player, $ea, $eb, Remix::make($ea, $eb), 'remix');
    }

    /** @return array<string,mixed> */
    public static function resolve(string $ticket, ?string $content): array
    {
        $player = Player::require();
        [$a, $b] = self::checkTicket($ticket, (string) $player['id']);
        $recipe = Db::one('SELECT * FROM cc_recipes WHERE a_id = ? AND b_id = ?', [min($a, $b), max($a, $b)]);
        if ($recipe !== null) {
            return self::finish($player, (int) $recipe['result_id'], $a, $b, false);
        }
        $ea = self::element($a);
        $eb = self::element($b);
        if (!$ea || !$eb) {
            throw new HttpError('Unknown element.', 404);
        }
        $data = $content !== null && $content !== '' ? Ai::parse($content) : null;
        if ($data !== null) {
            return self::create($player, $ea, $eb, $data, 'ai-relay');
        }
        return self::create($player, $ea, $eb, Remix::make($ea, $eb), 'remix');
    }

    private static function ticket(string $pid, int $x, int $y): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode(['p' => $pid, 'a' => $x, 'b' => $y, 'e' => time() + 120])), '+/', '-_'), '=');
        return $payload . '.' . substr(hash_hmac('sha256', $payload, Store::secret()), 0, 32);
    }

    /** @return array{0:int,1:int} */
    private static function checkTicket(string $ticket, string $pid): array
    {
        $parts = explode('.', $ticket);
        if (count($parts) !== 2 || !hash_equals(substr(hash_hmac('sha256', $parts[0], Store::secret()), 0, 32), $parts[1])) {
            throw new HttpError('Bad ticket.', 400);
        }
        $d = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true);
        if (!is_array($d) || ($d['p'] ?? '') !== $pid || (int) ($d['e'] ?? 0) < time()) {
            throw new HttpError('Ticket expired. Try that combo again.', 400);
        }
        return [(int) $d['a'], (int) $d['b']];
    }

    /**
     * @param array<string,mixed> $player
     * @param array<string,mixed> $ea
     * @param array<string,mixed> $eb
     * @param array{name:string,emoji:?string,tagline:string} $data
     * @return array<string,mixed>
     */
    private static function create(array $player, array $ea, array $eb, array $data, string $source): array
    {
        $a = (int) $ea['id'];
        $b = (int) $eb['id'];
        [$x, $y] = $a <= $b ? [$a, $b] : [$b, $a];
        $emoji = $data['emoji'] ?? null;
        if ($emoji === null || !Text::emojiKnown($emoji)) {
            $emoji = Remix::emojiFor($data['name'], $ea, $eb);
        }
        $emoji = Text::safeEmoji((string) $emoji);
        $depth = max((int) $ea['depth'], (int) $eb['depth']) + 1;
        $key = Text::key($data['name']);
        $now = Db::now();
        $first = false;
        $resultId = 0;
        try {
            Db::transaction(function () use ($player, $data, $emoji, $depth, $key, $now, $a, $b, $x, $y, $source, &$first, &$resultId): void {
                $el = Db::one('SELECT * FROM cc_elements WHERE name_key = ?', [$key]);
                if ($el === null) {
                    self::insertIgnore('cc_elements', [
                        'name' => $data['name'], 'name_key' => $key, 'emoji' => $emoji, 'tagline' => $data['tagline'] ?: null,
                        'art' => null, 'label' => null, 'depth' => $depth, 'is_base' => 0, 'sort_order' => 0,
                        'discovered_by' => $player['id'], 'discovered_at' => $now, 'first_a' => $a, 'first_b' => $b,
                        'uses' => 0, 'source' => $source, 'created_at' => $now,
                    ]);
                    $el = Db::one('SELECT * FROM cc_elements WHERE name_key = ?', [$key]);
                    $first = $el !== null && ($el['discovered_by'] ?? null) === $player['id'] && ($el['discovered_at'] ?? null) === $now;
                } elseif ((int) $el['is_base'] === 0 && empty($el['discovered_by'])) {
                    $n = Db::run('UPDATE cc_elements SET discovered_by = ?, discovered_at = ? WHERE id = ? AND discovered_by IS NULL', [$player['id'], $now, $el['id']]);
                    $first = $n > 0;
                }
                if ($el === null) {
                    throw new HttpError('Could not save element.', 500);
                }
                $resultId = (int) $el['id'];
                self::insertIgnore('cc_recipes', ['a_id' => $x, 'b_id' => $y, 'result_id' => $resultId, 'source' => $source, 'created_by' => $player['id'], 'created_at' => $now]);
                $r = Db::one('SELECT result_id FROM cc_recipes WHERE a_id = ? AND b_id = ?', [$x, $y]);
                if ($r !== null && (int) $r['result_id'] !== $resultId) {
                    // Someone else saved this pair a moment ago: theirs wins.
                    $resultId = (int) $r['result_id'];
                    $first = false;
                }
            });
        } catch (HttpError $e) {
            throw $e;
        } catch (Throwable $e) {
            error_log('[combocraft create] ' . $e->getMessage());
            $r = Db::one('SELECT result_id FROM cc_recipes WHERE a_id = ? AND b_id = ?', [$x, $y]);
            if ($r === null) {
                throw new HttpError('Could not save that combination. Try again.', 500);
            }
            $resultId = (int) $r['result_id'];
            $first = false;
        }
        return self::finish($player, $resultId, $a, $b, $first, true);
    }

    /** @param array<string,mixed> $player @return array<string,mixed> */
    private static function finish(array $player, int $resultId, int $x, int $y, bool $first, bool $newRecipe = false): array
    {
        $el = self::element($resultId);
        if ($el === null) {
            throw new HttpError('Missing element.', 500);
        }
        $newForYou = false;
        if ((int) $el['is_base'] === 0) {
            $had = Db::value('SELECT 1 FROM cc_collection WHERE player_id = ? AND element_id = ?', [$player['id'], $resultId]);
            if ($had === null) {
                self::insertIgnore('cc_collection', ['player_id' => $player['id'], 'element_id' => $resultId, 'via_a' => $x, 'via_b' => $y, 'created_at' => Db::now()]);
                $newForYou = true;
            }
        }
        // Global first: only the winning INSERT IGNORE counts. Grandfathered
        // existing firsts never receive coins.
        $first = Firsts::claim($resultId, $player);
        if ($first) {
            Db::run(
                'UPDATE cc_elements SET discovered_by = COALESCE(discovered_by, ?), discovered_at = COALESCE(discovered_at, ?) WHERE id = ?',
                [$player['id'], Db::now(), $resultId]
            );
            Db::run('UPDATE cc_players SET firsts = firsts + 1 WHERE id = ?', [$player['id']]);
            $el = self::element($resultId) ?? $el;
        }
        Db::run('UPDATE cc_elements SET uses = uses + 1 WHERE id = ?', [$resultId]);
        $el['uses'] = (int) $el['uses'] + 1;
        $a = self::element($x);
        $b = self::element($y);
        Player::reset();
        $me = Player::current();
        $out = [
            'result' => self::view($el, true) + ['mineFirst' => $first || (Firsts::ownerId($resultId) === $player['id'])],
            'inputs' => [$a ? self::mini($a) : null, $b ? self::mini($b) : null],
            'first' => $first,
            'newForYou' => $newForYou,
            'newRecipe' => $newRecipe,
            'me' => $me ? Player::publicView($me) : null,
        ];
        try {
            $out['daily'] = Daily::onCombine($player, $out['result'], $out['inputs'], $newForYou);
            $out['badges'] = Goals::onCombine($player, $out['result'], $first, $newForYou);
            $out['coins'] = MorseLink::onFirstDiscovery($player, $out['result'], $first);
            $out['progress'] = Goals::progress($player);
            if ($first) {
                Tree::record($player, $el);
            }
        } catch (Throwable $e) {
            error_log('[combocraft extras] ' . $e->getMessage());
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function leaderboard(): array
    {
        $rows = Db::all('SELECT id, nickname, avatar, firsts FROM cc_players WHERE firsts > 0 ORDER BY firsts DESC, created_at ASC LIMIT 80');
        $out = [];
        $rank = 0;
        foreach ($rows as $r) {
            if (TestNicks::isHidden($r['nickname'] ?? null)) {
                continue;
            }
            $rank++;
            $best = Db::one('SELECT * FROM cc_elements WHERE discovered_by = ? ORDER BY uses DESC, depth DESC, discovered_at DESC LIMIT 1', [$r['id']]);
            $out[] = [
                'rank' => $rank,
                'id' => $r['id'],
                'nickname' => $r['nickname'] ?: 'Anonymous crafter',
                'avatar' => $r['avatar'] ?: '🐱',
                'firsts' => (int) $r['firsts'],
                'best' => $best ? self::view($best, true) : null,
            ];
            if (count($out) >= 50) {
                break;
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function gallery(string $sort): array
    {
        $seen = [];
        $out = [];
        $push = static function (array $rows, ?string $label) use (&$seen, &$out): void {
            foreach ($rows as $r) {
                if (isset($seen[$r['id']])) {
                    continue;
                }
                $seen[$r['id']] = true;
                $v = self::view($r, true);
                if ($label !== null && $v['label'] === null) {
                    $v['label'] = $label;
                }
                $out[] = $v;
            }
        };
        if ($sort === 'popular') {
            $push(Db::all('SELECT * FROM cc_elements WHERE is_base = 0 AND (discovered_by IS NOT NULL OR label IS NOT NULL) ORDER BY uses DESC, id DESC LIMIT 60'), 'POPULAR');
        } elseif ($sort === 'featured') {
            $push(Db::all('SELECT * FROM cc_elements WHERE label IS NOT NULL ORDER BY sort_order'), null);
        } else {
            $push(Db::all('SELECT * FROM cc_elements WHERE label IS NOT NULL ORDER BY sort_order'), null);
            $push(Db::all('SELECT * FROM cc_elements WHERE is_base = 0 AND discovered_by IS NOT NULL ORDER BY discovered_at DESC, id DESC LIMIT 60'), 'COMMUNITY');
        }
        return $out;
    }
}
