<?php

declare(strict_types=1);

namespace ComboCraft;

use Throwable;

/**
 * Extra Combo Clash modes. State lives in additive cc_clash_mode_state only.
 * Hidden modes cannot be started until they are listed in LIVE.
 */
final class ClashModes
{
    public const GIFT = 'gift';
    public const PILE = 'pile';
    public const TELEPHONE = 'telephone';
    public const BOSS = 'boss';
    public const SHELF = 'shelf';

    /** Modes that may be started from the lobby. Flip these on as each one ships. */
    public const LIVE = [
        self::GIFT,
        self::PILE,
    ];

    public const INFO = [
        self::GIFT => [
            'id' => self::GIFT,
            'title' => 'Poison Gift',
            'tag' => 'New',
            'blurb' => 'Every combine makes two cards. Keep one. The other goes to your opponent, aimed at you.',
        ],
        self::PILE => [
            'id' => self::PILE,
            'title' => 'The Pile',
            'tag' => 'New',
            'blurb' => 'One shared card mutates by itself. Slap a card on it to steer. Theme hits score. Sludge hurts the last person who touched it.',
        ],
        self::TELEPHONE => [
            'id' => self::TELEPHONE,
            'title' => 'Telephone',
            'tag' => 'New',
            'blurb' => 'Four seats, one card. You only see the last pass, add one ingredient, and send it on. The full chain is read out at the end.',
        ],
        self::BOSS => [
            'id' => self::BOSS,
            'title' => 'World Boss',
            'tag' => 'New',
            'blurb' => 'Both sides feed the same giant card. Whoever lands the killing combine keeps that boss in their book forever.',
        ],
        self::SHELF => [
            'id' => self::SHELF,
            'title' => 'Stolen Shelf',
            'tag' => 'New',
            'blurb' => "You don't play your twelve cards. You get twenty seconds with a stranger's collection from yesterday.",
        ],
    ];

    private const GIFT_SECS = 12;
    private const PILE_PULSE_SECS = 5;
    private const TEL_TURN_SECS = 20;
    private const SHELF_PEEK_SECS = 20;
    private const BOSS_HP = 900;

    private const BOSSES = [
        ['The Landlord', '🏢', 'Rent is due. It always is.'],
        ['The Algorithm', '📡', 'It already knows what you will combine.'],
        ['The Group Chat', '💬', 'Someone is typing. Forever.'],
        ['The Hangover', '🥴', 'It mutates faster than you can think.'],
        ['The Inbox', '📥', '999+ unread, and growing.'],
        ['The Tax Form', '🧾', 'Every combo is an audit.'],
        ['The Notification', '🔔', 'You cannot mute this one.'],
        ['The Fine Print', '📜', 'You already agreed.'],
    ];

    private const DRIFT = [
        ['Sludge', '🫠', 'It was a good idea, once.'],
        ['Chaos', '🌀', 'Pure, unfiltered nonsense.'],
        ['Time', '⌛', 'Everything expires.'],
        ['Luck', '🍀', 'Do not count on it.'],
        ['Spark', '✨', 'A little push.'],
        ['Curse', '☠️', 'A gift with teeth.'],
        ['Wind', '🌬️', 'It will not sit still.'],
        ['Glue', '🫧', 'Now it is stuck that way.'],
    ];

    private const STARTERS = [
        ['Mystery Memo', '📝', 'Passed to you. No context.'],
        ['Weird Leftovers', '🥡', 'Still somehow alive.'],
        ['The First Rumor', '👂', 'It will not stay this small.'],
        ['A Single Spark', '✨', 'Add something. Anything.'],
        ['Open Tab', '🗂️', 'Do not close it.'],
    ];

    private const FALLBACK_SHELF = [
        ['Apocalypse Pizza', '🍕', 'The last slice after the end.', 3],
        ['Wet Resume', '📄', 'Qualified, dripping, desperate.', 2],
        ['Sad Moon', '🌙', 'It has seen things.', 2],
        ['Cursed Group Chat', '💬', 'The admin left years ago.', 3],
        ['Budget Time Machine', '🕰️', 'Only goes to awkward moments.', 3],
        ['Office Plant That Knows', '🪴', 'It has been taking notes.', 2],
        ['Emergency Confetti', '🎊', 'For better or worse.', 2],
        ['Midnight Deadline', '⏰', 'It always wins.', 3],
        ['Lucky Coupon', '🎟️', 'Expires while you read this.', 1],
        ['Haunted Spreadsheet', '📊', 'The formulas plot.', 3],
        ['Pocket Storm', '⛈️', 'Keep upright.', 2],
        ['Soft Revenge', '🧸', 'It smiles. It remembers.', 3],
        ['Broken Wi-Fi', '📶', 'One bar of hope.', 1],
        ['Gossip Engine', '📣', 'Fuel: secrets.', 2],
        ['Vintage Glitch', '📼', 'Tracking issues, emotionally.', 2],
        ['Last Good Idea', '💡', 'Handle carefully.', 4],
    ];

    public static function isLive(string $mode): bool
    {
        return in_array($mode, self::LIVE, true);
    }

    public static function normalize(string $mode): ?string
    {
        $mode = strtolower(trim($mode));
        return isset(self::INFO[$mode]) ? $mode : null;
    }

    public static function requireLive(string $mode): string
    {
        $mode = self::normalize($mode) ?? '';
        if ($mode === '') {
            throw new HttpError('Unknown Clash mode.', 422);
        }
        if (!self::isLive($mode)) {
            throw new HttpError('That mode is not open yet.', 422);
        }
        return $mode;
    }

    /** @return list<array<string,string>> */
    public static function lobby(): array
    {
        $out = [];
        foreach (self::LIVE as $id) {
            $out[] = self::INFO[$id];
        }
        return $out;
    }

    public static function of(array $m): ?string
    {
        $row = self::row((string) $m['id']);
        return $row ? (string) $row['mode'] : null;
    }

    public static function isSingleRound(array $m): bool
    {
        $mode = self::of($m);
        return in_array($mode, [self::PILE, self::TELEPHONE, self::BOSS], true);
    }

    /** @param array<string,mixed> $m */
    public static function attach(array $m, string $mode): void
    {
        $mode = self::requireLive($mode);
        $data = ['mode' => $mode];
        if ($mode === self::SHELF) {
            $data = self::buildShelf($m, $data);
        }
        if ($mode === self::BOSS) {
            $data['boss'] = self::weekBoss();
            $data['history'] = [];
            $data['killedBy'] = null;
            $data['granted'] = false;
        }
        self::save((string) $m['id'], $mode, $data);
    }

    /** @param array<string,mixed> $m */
    public static function onRoundStart(array $m, int $n, string $roundId): void
    {
        $st = self::state($m);
        if (!$st) {
            return;
        }
        $mode = (string) $st['mode'];
        if ($mode === self::PILE) {
            $start = self::STARTERS[abs(crc32($m['id'] . '|pile|' . $n)) % count(self::STARTERS)];
            $st['pile'] = self::card($start[0], $start[1], $start[2], 'Common');
            $st['lastToucher'] = null;
            $st['nextPulse'] = time() + self::KICK() + self::PILE_PULSE_SECS;
            $st['history'] = $st['history'] ?? [];
            $st['pending'] = null;
        }
        if ($mode === self::TELEPHONE) {
            $start = self::STARTERS[abs(crc32($m['id'] . '|tel|' . $n)) % count(self::STARTERS)];
            $a = (string) $m['a_id'];
            $b = (string) $m['b_id'];
            $st['seats'] = [
                ['n' => 1, 'playerId' => $a],
                ['n' => 2, 'playerId' => $b],
                ['n' => 3, 'playerId' => $a],
                ['n' => 4, 'playerId' => $b],
            ];
            $st['turn'] = 0;
            $st['current'] = self::card($start[0], $start[1], $start[2], 'Common');
            $st['starter'] = $st['current'];
            $st['chain'] = [];
            $st['revealed'] = false;
            $st['turnUntil'] = time() + self::KICK() + self::TEL_TURN_SECS;
            $st['pending'] = null;
        }
        if ($mode === self::GIFT) {
            $st['pendingBy'] = [];
            $st['pending'] = null;
        }
        if ($mode === self::BOSS && empty($st['boss'])) {
            $st['boss'] = self::weekBoss();
        }
        self::save((string) $m['id'], $mode, $st);
    }

    /** @param array<string,mixed> $m */
    public static function tick(array $m): void
    {
        $st = self::state($m);
        if (!$st || ($m['status'] ?? '') !== 'playing') {
            if ($st && ($m['status'] ?? '') === 'loadout' && ($st['mode'] ?? '') === self::SHELF) {
                self::tickShelf($m, $st);
            }
            return;
        }
        $round = Clash::modeRound($m);
        if (!$round || (int) $round['settled']) {
            return;
        }
        $started = strtotime((string) $round['started_at'] . ' UTC') ?: time();
        if (time() < $started + Clash::KICKOFF_SECS) {
            return;
        }
        $mode = (string) $st['mode'];
        if ($mode === self::GIFT) {
            self::expireGift($m, $st, $round);
        }
        if ($mode === self::PILE) {
            self::pulsePile($m, $st, $round);
        }
        if ($mode === self::TELEPHONE) {
            self::tickTelephone($m, $st, $round);
        }
    }

    /**
     * @param array<string,mixed> $p
     * @param array<string,mixed> $m
     * @param array<string,mixed> $round
     * @return array<string,mixed>|null
     */
    public static function interceptPlay(array $p, array $m, array $round, string $aTok, string $bTok, bool $internal): ?array
    {
        $st = self::state($m);
        if (!$st) {
            return null;
        }
        $mode = (string) $st['mode'];
        if ($mode === self::GIFT) {
            return self::playGift($p, $m, $round, $st, $aTok, $bTok, $internal);
        }
        if ($mode === self::PILE) {
            return self::slapPile($p, $m, $round, $st, $aTok);
        }
        if ($mode === self::TELEPHONE) {
            return self::passTelephone($p, $m, $round, $st, $aTok);
        }
        if ($mode === self::BOSS) {
            return self::feedBoss($p, $m, $round, $st, $aTok);
        }
        return null;
    }

    /**
     * @param array<string,mixed> $p
     * @param array<string,mixed> $m
     * @param array<string,array<string,mixed>> $hand
     * @return array<string,array<string,mixed>>
     */
    public static function extendHand(array $p, array $m, array $round, array $hand): array
    {
        $st = self::state($m);
        if (!$st) {
            return $hand;
        }
        foreach (($st['gifts'][(string) $p['id']] ?? []) as $g) {
            $tok = (string) ($g['token'] ?? '');
            if ($tok === '' || isset($hand[$tok])) {
                continue;
            }
            $hand[$tok] = [
                'token' => $tok,
                'temp' => true,
                'gift' => true,
                'id' => $tok,
                'name' => $g['name'],
                'emoji' => Text::safeEmoji((string) ($g['emoji'] ?? '☠️')),
                'tagline' => $g['tagline'] ?? '',
                'rarity' => $g['rarity'] ?? 'Rare',
                'depth' => 3,
            ];
        }
        return $hand;
    }

    /**
     * @param array<string,mixed> $m
     * @param array<string,mixed> $p
     * @param array<string,mixed> $view
     * @return array<string,mixed>
     */
    public static function decorate(array $m, array $p, array $view): array
    {
        $st = self::state($m);
        if (!$st) {
            $view['mode'] = null;
            $view['modesLive'] = self::lobby();
            return $view;
        }
        $mode = (string) $st['mode'];
        $info = self::INFO[$mode] ?? ['title' => $mode, 'blurb' => ''];
        $view['mode'] = $mode;
        $view['modeTitle'] = $info['title'];
        $view['modeBlurb'] = $info['blurb'];
        $view['modesLive'] = self::lobby();
        if ($mode === self::GIFT) {
            $pending = self::pendingOf($st, (string) $p['id']);
            $round = Clash::modeRound($m);
            if (is_array($pending) && $round && ($pending['roundId'] ?? '') === ($round['id'] ?? '')) {
                $view['pendingGift'] = [
                    'until' => ((int) ($pending['until'] ?? 0)) * 1000,
                    'keepSecs' => self::GIFT_SECS,
                    'a' => self::pubCard($pending['made'] ?? [], $pending['madeJudge'] ?? []),
                    'b' => self::pubCard($pending['shadow'] ?? [], $pending['shadowJudge'] ?? []),
                ];
            } else {
                $view['pendingGift'] = null;
            }
            $view['gifts'] = array_values($st['gifts'][(string) $p['id']] ?? []);
        }
        if ($mode === self::PILE) {
            $view['pile'] = $st['pile'] ?? null;
            $view['pileMine'] = ($st['lastToucher'] ?? null) === $p['id'];
            $view['pilePulseIn'] = max(0, ((int) ($st['nextPulse'] ?? 0)) - time());
            $view['pileHistory'] = array_slice($st['history'] ?? [], -6);
        }
        if ($mode === self::TELEPHONE) {
            $turn = (int) ($st['turn'] ?? 0);
            $seats = $st['seats'] ?? [];
            $seat = $seats[$turn] ?? null;
            $view['telephone'] = [
                'turn' => $turn + 1,
                'total' => 4,
                'yourTurn' => $seat && ($seat['playerId'] ?? '') === $p['id'] && empty($st['revealed']),
                'current' => empty($st['revealed']) ? ($st['current'] ?? null) : ($st['current'] ?? null),
                'turnUntil' => isset($st['turnUntil']) ? ((int) $st['turnUntil']) * 1000 : null,
                'revealed' => !empty($st['revealed']) || in_array($m['status'] ?? '', ['finished', 'forfeit', 'inter'], true),
                'seats' => array_map(function ($s) use ($p, $m) {
                    $pid = (string) ($s['playerId'] ?? '');
                    $you = $pid === $p['id'];
                    $bot = Clash::isAiId($pid);
                    return [
                        'n' => (int) $s['n'],
                        'you' => $you,
                        'bot' => $bot,
                        'label' => $you ? 'You' : ($bot ? 'Bot' : 'Seat ' . (int) $s['n']),
                    ];
                }, $seats),
                'chain' => (!empty($st['revealed']) || in_array($m['status'] ?? '', ['finished', 'forfeit', 'inter'], true))
                    ? array_map(function ($c) use ($p) {
                        $you = ($c['playerId'] ?? '') === $p['id'];
                        return [
                            'n' => $c['n'] ?? 0,
                            'you' => $you,
                            'label' => $you ? 'You' : (Clash::isAiId((string) ($c['playerId'] ?? '')) ? 'Bot' : 'Seat'),
                            'ingredient' => $c['ingredient'] ?? '',
                            'ingredientEmoji' => $c['ingredientEmoji'] ?? '',
                            'result' => $c['result'] ?? null,
                        ];
                    }, $st['chain'] ?? [])
                    : [],
            ];
        }
        if ($mode === self::BOSS) {
            $boss = $st['boss'] ?? self::weekBoss();
            $view['boss'] = [
                'name' => $boss['name'],
                'emoji' => $boss['emoji'],
                'tagline' => $boss['tagline'] ?? '',
                'hp' => (int) ($boss['hp'] ?? 0),
                'maxHp' => (int) ($boss['maxHp'] ?? self::BOSS_HP),
                'week' => $boss['week'] ?? '',
            ];
            $view['bossKilledByYou'] = ($st['killedBy'] ?? null) === $p['id'];
            $view['bossGranted'] = !empty($st['granted']) && ($st['killedBy'] ?? null) === $p['id'];
            $view['theme'] = $view['theme'] ?: ('Defeat ' . $boss['name']);
        }
        if ($mode === self::SHELF) {
            $peekUntil = (int) ($st['peekUntil'] ?? 0);
            $left = max(0, $peekUntil - time());
            $view['shelf'] = [
                'label' => "Yesterday's shelf",
                'peekLeft' => $left,
                'peekUntil' => $peekUntil * 1000,
                'count' => count($st['cards'] ?? []),
            ];
            if (($m['status'] ?? '') === 'loadout') {
                $view['collection'] = array_map(static function ($c) {
                    return [
                        'id' => (int) $c['id'],
                        'name' => $c['name'],
                        'emoji' => $c['emoji'],
                        'rarity' => $c['rarity'] ?? 'Common',
                        'tagline' => $c['tagline'] ?? '',
                        'depth' => $c['depth'] ?? 2,
                    ];
                }, $st['cards'] ?? []);
            }
        }
        return $view;
    }

    /** @param array<string,mixed> $p @param array<string,mixed> $m */
    public static function allowLoadoutIds(array $p, array $m, array $ids): array
    {
        $st = self::state($m);
        if (!$st || ($st['mode'] ?? '') !== self::SHELF) {
            return $ids;
        }
        $allowed = [];
        foreach ($st['cards'] ?? [] as $c) {
            $allowed[(int) $c['id']] = true;
        }
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (isset($allowed[$id])) {
                $out[] = $id;
            }
        }
        return array_values(array_unique($out));
    }

    public static function skipOwnedCheck(array $m): bool
    {
        return self::of($m) === self::SHELF;
    }

    /** @param array<string,mixed> $p */
    public static function chooseGift(array $p, string $matchId, string $keep): array
    {
        $m = Clash::modeMine($p, $matchId);
        $m = Clash::modeTick($m);
        $st = self::state($m);
        if (!$st || ($st['mode'] ?? '') !== self::GIFT) {
            throw new HttpError('This is not a Poison Gift match.', 422);
        }
        $round = Clash::modeRound($m);
        if (!$round) {
            throw new HttpError('This round is not in play.', 422);
        }
        return self::resolveGift($p, $m, $round, $st, $keep === 'b' ? 'b' : 'a', false);
    }

    public static function maybeAiGift(array $m): void
    {
        $st = self::state($m);
        if (!$st || ($st['mode'] ?? '') !== self::GIFT) {
            return;
        }
        $round = Clash::modeRound($m);
        if (!$round) {
            return;
        }
        foreach (self::allPending($st) as $pending) {
            $pid = (string) ($pending['playerId'] ?? '');
            if (!Clash::isAiId($pid)) {
                continue;
            }
            $bot = Db::one('SELECT * FROM cc_players WHERE id = ?', [$pid]);
            if (!$bot) {
                continue;
            }
            $ms = (int) (($pending['madeJudge']['score'] ?? 0));
            $ss = (int) (($pending['shadowJudge']['score'] ?? 0));
            $keep = $ms >= $ss ? 'a' : 'b';
            try {
                $st = self::state($m) ?? $st;
                self::resolveGift($bot, $m, $round, $st, $keep, true);
            } catch (Throwable $e) {
                error_log('[combocraft gift ai] ' . $e->getMessage());
            }
        }
    }

    public static function maybeAiSlap(array $m): void
    {
        $st = self::state($m);
        if (!$st || ($st['mode'] ?? '') !== self::PILE) {
            return;
        }
        $level = Clash::aiLevelOf($m);
        if (!$level || ($m['status'] ?? '') !== 'playing') {
            return;
        }
        $round = Clash::modeRound($m);
        if (!$round || (int) $round['settled'] || strtotime((string) $round['ends_at'] . ' UTC') <= time()) {
            return;
        }
        if (time() < strtotime((string) $round['started_at'] . ' UTC') + Clash::KICKOFF_SECS) {
            return;
        }
        $aiId = Clash::isAiId((string) ($m['a_id'] ?? '')) ? (string) $m['a_id'] : (Clash::isAiId((string) ($m['b_id'] ?? '')) ? (string) $m['b_id'] : '');
        if ($aiId === '') {
            return;
        }
        $wait = ['easy' => [7, 11], 'medium' => [3, 6], 'hard' => [2, 3]][$level] ?? [7, 11];
        $last = (int) ($st['lastAiSlap'] ?? 0);
        $from = $last > 0 ? $last : (strtotime((string) $round['started_at'] . ' UTC') ?: time());
        $need = $last > 0 ? $wait[1] : $wait[0];
        if (time() - $from < $need) {
            return;
        }
        $bot = Db::one('SELECT * FROM cc_players WHERE id = ?', [$aiId]);
        if (!$bot) {
            return;
        }
        $hand = Clash::modeHand($bot, $m, $round);
        $toks = array_keys($hand);
        if (!$toks) {
            return;
        }
        $pick = $toks[abs(crc32($m['id'] . '|' . ($round['id'] ?? '') . '|' . (string) time())) % count($toks)];
        try {
            $st['lastAiSlap'] = time();
            self::save((string) $m['id'], self::PILE, $st);
            self::slapPile($bot, $m, $round, $st, (string) $pick);
        } catch (Throwable $e) {
            error_log('[combocraft pile ai] ' . $e->getMessage());
        }
    }

    // ——— Poison Gift ———

    /**
     * @param array<string,mixed> $p
     * @param array<string,mixed> $m
     * @param array<string,mixed> $round
     * @param array<string,mixed> $st
     * @return array<string,mixed>
     */
    private static function playGift(array $p, array $m, array $round, array $st, string $aTok, string $bTok, bool $internal): array
    {
        if (self::pendingOf($st, (string) $p['id'])) {
            throw new HttpError('Keep one card first — the other goes to them.', 422);
        }
        self::maybeAiGift($m);
        $st = self::state($m) ?? $st;
        $hand = Clash::modeHand($p, $m, $round);
        $ea = $hand[$aTok] ?? null;
        $eb = $hand[$bTok] ?? null;
        if (!$ea || !$eb) {
            throw new HttpError('You can only combine cards from your loadout, gifts, or this round\'s results.', 422);
        }
        $made = Clash::modeCraft($ea, $eb);
        $shadow = self::shadowOf($made);
        $made['rarity'] = $made['rarity'] ?? 'Common';
        $shadow['rarity'] = $shadow['rarity'] ?? 'Rare';
        $madeJ = Clash::modeScore((string) $round['theme'], $made, $ea, $eb, (string) $round['id'], (string) $p['id']);
        $shadowJ = Clash::modeScore((string) $round['theme'], $shadow, $ea, $eb, (string) $round['id'], (string) $p['id']);
        if ($internal && Clash::isAiId((string) $p['id'])) {
            $st = self::setPending($st, (string) $p['id'], [
                'playerId' => $p['id'],
                'roundId' => $round['id'],
                'until' => time() + 2,
                'ea' => $ea,
                'eb' => $eb,
                'made' => $made,
                'shadow' => $shadow,
                'madeJudge' => $madeJ,
                'shadowJudge' => $shadowJ,
            ]);
            self::save((string) $m['id'], self::GIFT, $st);
            $keep = ((int) ($madeJ['score'] ?? 0)) >= ((int) ($shadowJ['score'] ?? 0)) ? 'a' : 'b';
            return self::resolveGift($p, $m, $round, $st, $keep, true);
        }
        $st = self::setPending($st, (string) $p['id'], [
            'playerId' => $p['id'],
            'roundId' => $round['id'],
            'until' => time() + self::GIFT_SECS,
            'ea' => $ea,
            'eb' => $eb,
            'made' => $made,
            'shadow' => $shadow,
            'madeJudge' => $madeJ,
            'shadowJudge' => $shadowJ,
        ]);
        Clash::modeTouchPlay((string) $m['id'], (string) $p['id']);
        self::save((string) $m['id'], self::GIFT, $st);
        return [
            'pendingGift' => true,
            'play' => null,
            'match' => Clash::modeView($m, $p),
            'now' => Db::nowMs(),
        ];
    }

    /**
     * @param array<string,mixed> $p
     * @param array<string,mixed> $m
     * @param array<string,mixed> $round
     * @param array<string,mixed> $st
     * @return array<string,mixed>
     */
    private static function resolveGift(array $p, array $m, array $round, array $st, string $keep, bool $auto): array
    {
        $pending = self::pendingOf($st, (string) $p['id']);
        if (!is_array($pending) || ($pending['roundId'] ?? '') !== $round['id']) {
            throw new HttpError('There is no gift to pick.', 422);
        }
        $kept = $keep === 'b' ? $pending['shadow'] : $pending['made'];
        $given = $keep === 'b' ? $pending['made'] : $pending['shadow'];
        $judge = $keep === 'b' ? $pending['shadowJudge'] : $pending['madeJudge'];
        $ea = $pending['ea'];
        $eb = $pending['eb'];
        $play = Clash::modeCommit($p, $m, $round, $ea, $eb, $kept, $judge, false);
        $st = self::state($m) ?? $st;
        $st = self::setPending($st, (string) $p['id'], null);
        $opp = Clash::modeOpp($m, (string) $p['id']);
        $n = (int) (($st['nextGift'] ?? 0) + 1);
        $st['nextGift'] = $n;
        $st['gifts'][$opp] = $st['gifts'][$opp] ?? [];
        $st['gifts'][$opp][] = [
            'token' => 'g:' . $n,
            'name' => $given['name'],
            'emoji' => $given['emoji'] ?? '☠️',
            'tagline' => $given['tagline'] ?? 'A gift with teeth.',
            'rarity' => $given['rarity'] ?? 'Rare',
        ];
        self::save((string) $m['id'], self::GIFT, $st);
        $fresh = Clash::modeGet((string) $m['id']);
        return [
            'ok' => true,
            'auto' => $auto,
            'kept' => self::pubCard($kept, $judge),
            'given' => self::pubCard($given, []),
            'play' => $play['play'] ?? $play,
            'match' => Clash::modeView($fresh, $p),
            'now' => Db::nowMs(),
        ];
    }

    /** @param array<string,mixed> $m @param array<string,mixed> $st @param array<string,mixed> $round */
    private static function expireGift(array $m, array $st, array $round): void
    {
        self::maybeAiGift($m);
        $st = self::state($m) ?? $st;
        foreach (self::allPending($st) as $pending) {
            if (time() < (int) ($pending['until'] ?? 0)) {
                continue;
            }
            $pid = (string) ($pending['playerId'] ?? '');
            $player = Db::one('SELECT * FROM cc_players WHERE id = ?', [$pid]);
            if (!$player) {
                $st = self::setPending($st, $pid, null);
                self::save((string) $m['id'], self::GIFT, $st);
                continue;
            }
            try {
                $st = self::state($m) ?? $st;
                self::resolveGift($player, $m, $round, $st, 'a', true);
            } catch (Throwable $e) {
                error_log('[combocraft gift expire] ' . $e->getMessage());
            }
        }
    }

    /** @param array<string,mixed> $made @return array<string,mixed> */
    private static function shadowOf(array $made): array
    {
        $name = (string) ($made['name'] ?? 'Idea');
        $h = abs(crc32(mb_strtolower($name)));
        $map = [
            'heart' => ['Debt', '📉', 'It wants paying back.'],
            'love' => ['Heartbreak', '💔', 'Same story, worse ending.'],
            'rich' => ['Debt', '📉', 'The bill arrives first.'],
            'gold' => ["Fool's Gold", '🪙', 'Shiny, then empty.'],
            'money' => ['Fine Print', '📜', 'You already signed.'],
            'pizza' => ['Food Poisoning', '🤢', 'The sequel no one ordered.'],
            'cat' => ['Hairball', '😹', 'A gift, technically.'],
            'fire' => ['Ash', '🪵', 'What is left when it is over.'],
            'water' => ['Drought', '🏜️', 'The well ran out.'],
            'hero' => ['Villain', '🦹', 'The other side of the same coin.'],
            'peace' => ['Grudge', '🪨', 'It keeps well.'],
            'luck' => ['Jinx', '🧿', 'Congratulations?'],
            'party' => ['Hangover', '🥴', 'The morning always finds you.'],
            'steam' => ['Burn', '🔥', 'Too much of a good combo.'],
        ];
        $low = mb_strtolower($name);
        $pick = null;
        foreach ($map as $k => $v) {
            if (str_contains($low, $k)) {
                $pick = $v;
                break;
            }
        }
        if (!$pick) {
            $forms = [
                ['Cursed ' . $name, '☠️', 'It looks right. It is not.'],
                [$name . ' Debt', '📉', 'You will pay for this later.'],
                ['Toxic ' . $name, '☢️', 'Handle with gloves.'],
                ['Evil ' . $name, '😈', 'The other one, from the same combine.'],
            ];
            $pick = $forms[$h % count($forms)];
        }
        if (Text::key($pick[0]) === Text::key($name)) {
            $pick[0] = 'Cursed ' . $name;
        }
        return [
            'name' => Text::cleanName(mb_substr($pick[0], 0, 48)) ?? ('Cursed ' . $name),
            'emoji' => Text::safeEmoji($pick[1]),
            'tagline' => $pick[2],
            'rarity' => 'Rare',
            'depth' => max(2, (int) ($made['depth'] ?? 2)),
        ];
    }

    // ——— The Pile ———

    /**
     * @param array<string,mixed> $p
     * @param array<string,mixed> $m
     * @param array<string,mixed> $round
     * @param array<string,mixed> $st
     * @return array<string,mixed>
     */
    private static function slapPile(array $p, array $m, array $round, array $st, string $tok): array
    {
        $hand = Clash::modeHand($p, $m, $round);
        $card = $hand[$tok] ?? null;
        if (!$card) {
            throw new HttpError('Slap one of your cards onto the pile.', 422);
        }
        $pile = $st['pile'] ?? null;
        if (!$pile) {
            throw new HttpError('The pile is not ready.', 422);
        }
        $made = Clash::modeCraft($pile, $card);
        $st['pile'] = $made;
        $st['lastToucher'] = $p['id'];
        $st['nextPulse'] = time() + self::PILE_PULSE_SECS;
        $scored = self::scorePile($p, $m, $round, $made, $card, $pile, false);
        $st['history'][] = $scored['event'];
        self::save((string) $m['id'], self::PILE, $st);
        $fresh = Clash::modeGet((string) $m['id']);
        return [
            'play' => $scored['play'],
            'match' => Clash::modeView($fresh, $p),
            'now' => Db::nowMs(),
        ];
    }

    /** @param array<string,mixed> $m @param array<string,mixed> $st @param array<string,mixed> $round */
    private static function pulsePile(array $m, array $st, array $round): void
    {
        $next = (int) ($st['nextPulse'] ?? 0);
        if ($next <= 0 || time() < $next) {
            return;
        }
        if (strtotime((string) $round['ends_at'] . ' UTC') <= time()) {
            return;
        }
        $pile = $st['pile'] ?? null;
        if (!$pile) {
            return;
        }
        $drift = self::DRIFT[abs(crc32(($m['id'] ?? '') . '|' . time() . '|' . ($pile['name'] ?? ''))) % count(self::DRIFT)];
        $ing = self::card($drift[0], $drift[1], $drift[2], 'Common');
        $made = Clash::modeCraft($pile, $ing);
        $st['pile'] = $made;
        $st['nextPulse'] = time() + self::PILE_PULSE_SECS;
        $toucher = $st['lastToucher'] ?? null;
        if ($toucher) {
            $player = Db::one('SELECT * FROM cc_players WHERE id = ?', [$toucher]);
            if ($player) {
                $scored = self::scorePile($player, $m, $round, $made, $ing, $pile, true);
                $st['history'][] = $scored['event'];
            }
        } else {
            $st['history'][] = [
                'auto' => true,
                'name' => $made['name'],
                'emoji' => $made['emoji'],
                'sludge' => self::isSludge($made, 0),
                'score' => 0,
            ];
        }
        self::save((string) $m['id'], self::PILE, $st);
    }

    /**
     * @param array<string,mixed> $p
     * @param array<string,mixed> $m
     * @param array<string,mixed> $round
     * @param array<string,mixed> $made
     * @param array<string,mixed> $ing
     * @param array<string,mixed> $prev
     * @return array{play:array<string,mixed>,event:array<string,mixed>}
     */
    private static function scorePile(array $p, array $m, array $round, array $made, array $ing, array $prev, bool $auto): array
    {
        $judge = Clash::modeScore((string) $round['theme'], $made, $prev, $ing, (string) $round['id'], (string) $p['id']);
        $close = (float) ($judge['closeness'] ?? 0);
        $sludge = self::isSludge($made, $close);
        if ($sludge) {
            $pen = max(25, (int) floor(((int) ($judge['score'] ?? 40)) * 0.8));
            $judge['score'] = -$pen;
            $judge['sludge'] = true;
            $judge['heat'] = 'Cold';
        }
        $play = Clash::modeCommit($p, $m, $round, $prev, $ing, $made, $judge, false);
        return [
            'play' => $play['play'] ?? $play,
            'event' => [
                'auto' => $auto,
                'playerId' => $p['id'],
                'name' => $made['name'],
                'emoji' => $made['emoji'],
                'sludge' => $sludge,
                'score' => (int) ($judge['score'] ?? 0),
                'ingredient' => $ing['name'] ?? '',
            ],
        ];
    }

    /** @param array<string,mixed> $made */
    private static function isSludge(array $made, float $close): bool
    {
        $n = mb_strtolower((string) ($made['name'] ?? ''));
        if ($close > 0 && $close < 0.18) {
            return true;
        }
        foreach (['sludge', 'muck', 'goo', 'mess', 'waste', 'gunk', 'sewage', 'dud'] as $w) {
            if (str_contains($n, $w)) {
                return true;
            }
        }
        return false;
    }

    // ——— Telephone ———

    /**
     * @param array<string,mixed> $p
     * @param array<string,mixed> $m
     * @param array<string,mixed> $round
     * @param array<string,mixed> $st
     * @return array<string,mixed>
     */
    private static function passTelephone(array $p, array $m, array $round, array $st, string $tok): array
    {
        if (!empty($st['revealed'])) {
            throw new HttpError('The chain is already done.', 422);
        }
        $turn = (int) ($st['turn'] ?? 0);
        $seat = $st['seats'][$turn] ?? null;
        if (!$seat || ($seat['playerId'] ?? '') !== $p['id']) {
            throw new HttpError('Wait your turn to pass the card.', 422);
        }
        $hand = Clash::modeHand($p, $m, $round);
        $card = $hand[$tok] ?? null;
        if (!$card) {
            throw new HttpError('Add one ingredient from your cards.', 422);
        }
        $current = $st['current'] ?? null;
        if (!$current) {
            throw new HttpError('There is no card to pass.', 422);
        }
        $made = Clash::modeCraft($current, $card);
        $judge = Clash::modeScore((string) $round['theme'], $made, $current, $card, (string) $round['id'], (string) $p['id']);
        $st['chain'][] = [
            'n' => $turn + 1,
            'playerId' => $p['id'],
            'ingredient' => $card['name'],
            'ingredientEmoji' => $card['emoji'] ?? '',
            'from' => $current,
            'result' => $made,
            'score' => (int) ($judge['score'] ?? 0),
        ];
        $st['current'] = $made;
        $st['turn'] = $turn + 1;
        $st['turnUntil'] = time() + self::TEL_TURN_SECS;
        $play = null;
        if ($st['turn'] >= 4) {
            $st['revealed'] = true;
            $play = Clash::modeCommit($p, $m, $round, $current, $card, $made, $judge, false);
            self::save((string) $m['id'], self::TELEPHONE, $st);
            // Last passer scores the final card; earlier seats already scored mid-chain below.
        } else {
            // Each seat scores their pass so the board moves, but the clip is the final chain.
            $play = Clash::modeCommit($p, $m, $round, $current, $card, $made, $judge, false);
            self::save((string) $m['id'], self::TELEPHONE, $st);
        }
        if ($st['turn'] >= 4) {
            $fresh = Clash::modeGet((string) $m['id']);
            $round2 = Clash::modeRound($fresh);
            if ($round2 && !(int) $round2['settled']) {
                Clash::modeSettle($fresh, $round2);
            }
            $fresh = Clash::modeGet((string) $m['id']);
            return ['play' => $play['play'] ?? $play, 'match' => Clash::modeView($fresh, $p), 'now' => Db::nowMs(), 'revealed' => true];
        }
        $fresh = Clash::modeGet((string) $m['id']);
        return ['play' => $play['play'] ?? $play, 'match' => Clash::modeView($fresh, $p), 'now' => Db::nowMs()];
    }

    /** @param array<string,mixed> $m @param array<string,mixed> $st @param array<string,mixed> $round */
    private static function tickTelephone(array $m, array $st, array $round): void
    {
        if (!empty($st['revealed']) || (int) ($st['turn'] ?? 0) >= 4) {
            return;
        }
        $turn = (int) ($st['turn'] ?? 0);
        $seat = $st['seats'][$turn] ?? null;
        if (!$seat) {
            return;
        }
        $pid = (string) $seat['playerId'];
        $expired = time() >= (int) ($st['turnUntil'] ?? 0);
        if (Clash::isAiId($pid) || $expired) {
            $player = Db::one('SELECT * FROM cc_players WHERE id = ?', [$pid]);
            if (!$player) {
                return;
            }
            $hand = Clash::modeHand($player, $m, $round);
            $toks = array_keys($hand);
            if (!$toks) {
                return;
            }
            $tok = $toks[abs(crc32($m['id'] . '|tel|' . $turn)) % count($toks)];
            if (Clash::isAiId($pid) || $expired) {
                try {
                    self::passTelephone($player, $m, $round, $st, (string) $tok);
                } catch (Throwable $e) {
                    error_log('[combocraft tel] ' . $e->getMessage());
                }
            }
        }
    }

    // ——— World Boss ———

    /**
     * @param array<string,mixed> $p
     * @param array<string,mixed> $m
     * @param array<string,mixed> $round
     * @param array<string,mixed> $st
     * @return array<string,mixed>
     */
    private static function feedBoss(array $p, array $m, array $round, array $st, string $tok): array
    {
        $boss = $st['boss'] ?? null;
        if (!$boss || (int) $boss['hp'] <= 0) {
            throw new HttpError('The boss is already down.', 422);
        }
        $hand = Clash::modeHand($p, $m, $round);
        $card = $hand[$tok] ?? null;
        if (!$card) {
            throw new HttpError('Feed one of your cards to the boss.', 422);
        }
        $theme = 'Defeat ' . $boss['name'];
        $made = Clash::modeCraft($boss, $card);
        $judge = Clash::modeScore($theme, $made, $boss, $card, (string) $round['id'], (string) $p['id']);
        $dmg = max(20, (int) ($judge['score'] ?? 20));
        $hp = max(0, (int) $boss['hp'] - $dmg);
        $boss['name'] = $made['name'] !== ($st['boss']['name'] ?? '') ? ($made['name']) : $boss['name'];
        // Keep the weekly identity: show mutation as the result, but the boss name stays for the book grant.
        $trueName = (string) ($st['boss']['trueName'] ?? $st['boss']['name'] ?? $boss['name']);
        $boss['trueName'] = $trueName;
        $boss['form'] = $made;
        $boss['emoji'] = $made['emoji'] ?? $boss['emoji'];
        $boss['tagline'] = $made['tagline'] ?? $boss['tagline'];
        $boss['hp'] = $hp;
        $st['boss'] = $boss;
        $st['history'][] = [
            'playerId' => $p['id'],
            'card' => $card['name'],
            'result' => $made['name'],
            'dmg' => $dmg,
        ];
        $killed = $hp <= 0;
        if ($killed && empty($st['killedBy'])) {
            $st['killedBy'] = $p['id'];
            $judge['score'] = (int) $judge['score'] + 250;
            $judge['kill'] = true;
            $st['granted'] = self::grantBoss($p, $trueName, (string) ($st['boss']['originEmoji'] ?? $st['boss']['emoji'] ?? '🏢'), (string) ($st['boss']['originTag'] ?? $st['boss']['tagline'] ?? ''));
        }
        $play = Clash::modeCommit($p, $m, $round, $boss, $card, $made, $judge, false);
        self::save((string) $m['id'], self::BOSS, $st);
        $fresh = Clash::modeGet((string) $m['id']);
        if ($killed) {
            $round2 = Clash::modeRound($fresh);
            if ($round2 && !(int) $round2['settled']) {
                Clash::modeSettle($fresh, $round2);
            }
            $fresh = Clash::modeGet((string) $m['id']);
        }
        return [
            'play' => $play['play'] ?? $play,
            'killed' => $killed,
            'granted' => !empty($st['granted']),
            'match' => Clash::modeView($fresh, $p),
            'now' => Db::nowMs(),
        ];
    }

    private static function grantBoss(array $p, string $name, string $emoji, string $tagline): bool
    {
        try {
            $el = Game::ensureNamedElement($name, $emoji, $tagline, 4);
            return Game::grantElement($p, (int) $el['id']);
        } catch (Throwable $e) {
            error_log('[combocraft boss grant] ' . $e->getMessage());
            return false;
        }
    }

    /** @return array<string,mixed> */
    private static function weekBoss(): array
    {
        $week = (int) gmdate('o') * 100 + (int) gmdate('W');
        $b = self::BOSSES[$week % count(self::BOSSES)];
        return [
            'name' => $b[0],
            'trueName' => $b[0],
            'emoji' => $b[1],
            'originEmoji' => $b[1],
            'tagline' => $b[2],
            'originTag' => $b[2],
            'hp' => self::BOSS_HP,
            'maxHp' => self::BOSS_HP,
            'week' => gmdate('o') . '-W' . gmdate('W'),
        ];
    }

    // ——— Stolen Shelf ———

    /**
     * @param array<string,mixed> $m
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function buildShelf(array $m, array $data): array
    {
        $human = Clash::isAiId((string) $m['a_id']) ? (string) $m['b_id'] : (string) $m['a_id'];
        $cards = self::yesterdayShelf($human, (string) $m['id']);
        if (count($cards) < 8) {
            $cards = self::cannedShelf();
        }
        $data['cards'] = $cards;
        $data['peekUntil'] = time() + self::SHELF_PEEK_SECS;
        $data['autoReady'] = false;
        return $data;
    }

    /** @param array<string,mixed> $m @param array<string,mixed> $st */
    private static function tickShelf(array $m, array $st): void
    {
        if (!empty($st['autoReady'])) {
            return;
        }
        if (time() < (int) ($st['peekUntil'] ?? 0)) {
            return;
        }
        $ids = array_map(static fn ($c) => (int) $c['id'], $st['cards'] ?? []);
        $ids = array_slice($ids, 0, 12);
        if (count($ids) < 8) {
            return;
        }
        foreach ([(string) $m['a_id'], (string) $m['b_id']] as $pid) {
            if (Clash::isAiId($pid)) {
                continue;
            }
            $lo = Db::one('SELECT * FROM cc_clash_loadouts WHERE match_id = ? AND player_id = ?', [$m['id'], $pid]);
            if ($lo && (int) $lo['ready'] === 1) {
                continue;
            }
            $use = $lo ? (json_decode((string) $lo['ids'], true) ?: []) : [];
            $use = array_values(array_filter($use, static fn ($id) => in_array((int) $id, $ids, true)));
            if (count($use) < 8) {
                $use = $ids;
            }
            Db::upsert(
                'cc_clash_loadouts',
                ['match_id' => $m['id'], 'player_id' => $pid, 'ids' => json_encode(array_values($use)), 'ready' => 1, 'last_play_ms' => 0],
                ['match_id', 'player_id'],
                ['ids', 'ready']
            );
        }
        $st['autoReady'] = true;
        self::save((string) $m['id'], self::SHELF, $st);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function yesterdayShelf(string $notPlayer, string $salt): array
    {
        $from = gmdate('Y-m-d 00:00:00', time() - 86400);
        $to = gmdate('Y-m-d 00:00:00', time());
        try {
            $rows = Db::all(
                "SELECT c.player_id, c.element_id, e.name, e.emoji, e.tagline, e.depth
                 FROM cc_collection c
                 JOIN cc_elements e ON e.id = c.element_id
                 WHERE c.created_at >= ? AND c.created_at < ?
                   AND c.player_id <> ?
                   AND c.player_id NOT LIKE 'pai_%'
                 ORDER BY c.player_id, c.created_at",
                [$from, $to, $notPlayer]
            );
        } catch (Throwable $e) {
            return [];
        }
        $by = [];
        foreach ($rows as $r) {
            $pid = (string) $r['player_id'];
            // Never keep the source id on the card payload later; grouping is server-only.
            $by[$pid][] = [
                'id' => (int) $r['element_id'],
                'name' => $r['name'],
                'emoji' => Text::safeEmoji((string) $r['emoji']),
                'tagline' => $r['tagline'] ?? '',
                'rarity' => Game::rarity((int) ($r['depth'] ?? 2)),
                'depth' => (int) ($r['depth'] ?? 2),
            ];
        }
        $cands = [];
        foreach ($by as $pid => $cards) {
            if (count($cards) >= 8) {
                $cands[$pid] = array_slice($cards, 0, 16);
            }
        }
        if (!$cands) {
            return [];
        }
        $keys = array_keys($cands);
        $pick = $keys[abs(crc32($salt)) % count($keys)];
        // Intentionally drop the source player id. Never decorate with a nickname.
        return $cands[$pick];
    }

    /** @return list<array<string,mixed>> */
    private static function cannedShelf(): array
    {
        $out = [];
        foreach (self::FALLBACK_SHELF as $row) {
            $el = Game::ensureNamedElement($row[0], $row[1], $row[2], (int) $row[3]);
            $out[] = [
                'id' => (int) $el['id'],
                'name' => $el['name'],
                'emoji' => Text::safeEmoji((string) $el['emoji']),
                'tagline' => $el['tagline'] ?? $row[2],
                'rarity' => Game::rarity((int) ($el['depth'] ?? $row[3])),
                'depth' => (int) ($el['depth'] ?? $row[3]),
            ];
        }
        return $out;
    }


    /** @param array<string,mixed> $st @return array<string,mixed>|null */
    private static function pendingOf(array $st, string $pid): ?array
    {
        $by = $st['pendingBy'] ?? [];
        if (isset($by[$pid]) && is_array($by[$pid])) {
            return $by[$pid];
        }
        $legacy = $st['pending'] ?? null;
        if (is_array($legacy) && ($legacy['playerId'] ?? '') === $pid) {
            return $legacy;
        }
        return null;
    }

    /** @param array<string,mixed> $st @return list<array<string,mixed>> */
    private static function allPending(array $st): array
    {
        $out = [];
        foreach (($st['pendingBy'] ?? []) as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        if (!$out && is_array($st['pending'] ?? null)) {
            $out[] = $st['pending'];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $st
     * @param array<string,mixed>|null $pending
     * @return array<string,mixed>
     */
    private static function setPending(array $st, string $pid, ?array $pending): array
    {
        $st['pendingBy'] = $st['pendingBy'] ?? [];
        if ($pending === null) {
            unset($st['pendingBy'][$pid]);
        } else {
            $st['pendingBy'][$pid] = $pending;
        }
        if (isset($st['pending']) && is_array($st['pending']) && ($st['pending']['playerId'] ?? '') === $pid) {
            unset($st['pending']);
        }
        return $st;
    }

    // ——— storage / helpers ———

    private static function KICK(): int
    {
        return Clash::KICKOFF_SECS;
    }

    /** @return array<string,mixed>|null */
    private static function row(string $matchId): ?array
    {
        try {
            return Db::one('SELECT * FROM cc_clash_mode_state WHERE match_id = ?', [$matchId]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @param array<string,mixed> $m @return array<string,mixed>|null */
    private static function state(array $m): ?array
    {
        $row = self::row((string) $m['id']);
        if (!$row) {
            return null;
        }
        $data = json_decode((string) $row['data'], true);
        if (!is_array($data)) {
            $data = [];
        }
        $data['mode'] = $row['mode'];
        return $data;
    }

    /** @param array<string,mixed> $data */
    private static function save(string $matchId, string $mode, array $data): void
    {
        $data['mode'] = $mode;
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $now = Db::now();
        $row = self::row($matchId);
        if ($row) {
            Db::run('UPDATE cc_clash_mode_state SET mode = ?, data = ?, updated_at = ? WHERE match_id = ?', [$mode, $json, $now, $matchId]);
        } else {
            Db::run(
                'INSERT INTO cc_clash_mode_state (match_id, mode, data, created_at, updated_at) VALUES (?,?,?,?,?)',
                [$matchId, $mode, $json, $now, $now]
            );
        }
    }

    /** @return array<string,mixed> */
    private static function card(string $name, string $emoji, string $tagline, string $rarity): array
    {
        return [
            'name' => $name,
            'emoji' => Text::safeEmoji($emoji),
            'tagline' => $tagline,
            'rarity' => $rarity,
            'depth' => 2,
            'temp' => true,
        ];
    }

    /**
     * @param array<string,mixed> $card
     * @param array<string,mixed> $judge
     * @return array<string,mixed>
     */
    private static function pubCard(array $card, array $judge): array
    {
        return [
            'name' => $card['name'] ?? '',
            'emoji' => $card['emoji'] ?? '',
            'tagline' => $card['tagline'] ?? '',
            'rarity' => $card['rarity'] ?? 'Common',
            'score' => (int) ($judge['score'] ?? 0),
            'heat' => $judge['heat'] ?? null,
            'breakdown' => $judge ?: new \stdClass(),
        ];
    }
}
