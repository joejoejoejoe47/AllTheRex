<?php

declare(strict_types=1);

namespace ComboCraft;

/** Shared chat: messages, shared discoveries and emoji reactions. */
final class Chat
{
    public const REACTIONS = ['😂', '🔥', '🤯', '❤️', '👏', '😮'];

    /** @return array<string,mixed> */
    public static function list(int $after): array
    {
        $me = Player::current();
        if ($me !== null && strtotime((string) $me['last_seen'] . ' UTC') < time() - 45) {
            Db::run('UPDATE cc_players SET last_seen = ? WHERE id = ?', [Db::now(), $me['id']]);
        }
        $rows = $after > 0
            ? Db::all('SELECT * FROM cc_chat WHERE id > ? ORDER BY id ASC LIMIT 100', [$after])
            : array_reverse(Db::all('SELECT * FROM cc_chat ORDER BY id DESC LIMIT 60'));
        // Reactions can change on older messages too: return the latest 60 message ids' reactions.
        $recentIds = array_map(static fn ($r) => (int) $r['id'], Db::all('SELECT id FROM cc_chat ORDER BY id DESC LIMIT 60'));
        $reactions = [];
        if ($recentIds) {
            $in = implode(',', array_fill(0, count($recentIds), '?'));
            foreach (Db::all("SELECT message_id, emoji, player_id FROM cc_reactions WHERE message_id IN ($in)", $recentIds) as $r) {
                $mid = (int) $r['message_id'];
                $reactions[$mid][$r['emoji']]['n'] = ($reactions[$mid][$r['emoji']]['n'] ?? 0) + 1;
                if ($me !== null && $r['player_id'] === $me['id']) {
                    $reactions[$mid][$r['emoji']]['mine'] = true;
                }
            }
        }
        $players = [];
        $msgs = [];
        foreach ($rows as $r) {
            $pid = (string) $r['player_id'];
            if (!isset($players[$pid])) {
                $players[$pid] = Db::one('SELECT nickname, avatar FROM cc_players WHERE id = ?', [$pid]) ?? ['nickname' => 'Someone', 'avatar' => '🐱'];
            }
            $el = $r['element_id'] ? Game::element((int) $r['element_id']) : null;
            $msgs[] = [
                'id' => (int) $r['id'],
                'player' => $pid,
                'nickname' => $players[$pid]['nickname'] ?: 'Anonymous crafter',
                'avatar' => $players[$pid]['avatar'] ?: '🐱',
                'body' => (string) $r['body'],
                'element' => $el ? Game::view($el, true) : null,
                'at' => Db::toMs($r['created_at']),
                'mine' => $me !== null && $me['id'] === $pid,
            ];
        }
        $out = [];
        foreach ($reactions as $mid => $set) {
            foreach ($set as $emoji => $info) {
                $out[$mid][] = ['emoji' => $emoji, 'n' => $info['n'], 'mine' => !empty($info['mine'])];
            }
        }
        $online = Db::all(
            'SELECT id, nickname, avatar FROM cc_players WHERE nickname IS NOT NULL AND last_seen > ? ORDER BY last_seen DESC LIMIT 30',
            [gmdate('Y-m-d H:i:s', time() - 300)]
        );
        return [
            'messages' => $msgs,
            'reactions' => (object) $out,
            'online' => array_map(static fn ($p) => ['id' => $p['id'], 'nickname' => $p['nickname'], 'avatar' => $p['avatar'] ?: '🐱'], $online),
        ];
    }

    /** @return array<string,mixed> */
    public static function post(string $body, ?int $elementId): array
    {
        $p = Player::require();
        if (empty($p['nickname'])) {
            throw new HttpError('Pick a nickname first.', 428);
        }
        $body = trim((string) preg_replace('/\s+/u', ' ', $body));
        if (mb_strlen($body) > 240) {
            $body = mb_substr($body, 0, 240);
        }
        if ($body === '' && !$elementId) {
            throw new HttpError('Say something!', 422);
        }
        if (!Text::isSafe($body) || preg_match('#https?://|www\.#i', $body)) {
            throw new HttpError('Let\'s keep chat friendly (and link-free).', 422);
        }
        if ($elementId) {
            $own = Db::value('SELECT 1 FROM cc_collection WHERE player_id = ? AND element_id = ?', [$p['id'], $elementId]);
            $base = Db::value('SELECT is_base FROM cc_elements WHERE id = ?', [$elementId]);
            if ($own === null && (int) $base !== 1) {
                throw new HttpError('You can only share your own discoveries.', 422);
            }
        }
        $last = Db::value('SELECT created_at FROM cc_chat WHERE player_id = ? ORDER BY id DESC LIMIT 1', [$p['id']]);
        if ($last !== null && Db::toMs($last) > (time() - 3) * 1000) {
            throw new HttpError('Whoa, slow down a little.', 429);
        }
        $recent = (int) Db::value('SELECT COUNT(*) FROM cc_chat WHERE player_id = ? AND created_at > ?', [$p['id'], gmdate('Y-m-d H:i:s', time() - 60)]);
        if ($recent >= 10) {
            throw new HttpError('Whoa, slow down a little.', 429);
        }
        Db::run('INSERT INTO cc_chat (player_id, body, element_id, created_at) VALUES (?,?,?,?)', [$p['id'], $body, $elementId ?: null, Db::now()]);
        Db::run('UPDATE cc_players SET last_seen = ? WHERE id = ?', [Db::now(), $p['id']]);
        return ['ok' => true];
    }

    /** @return array<string,mixed> */
    public static function react(int $messageId, string $emoji): array
    {
        $p = Player::require();
        if (!in_array($emoji, self::REACTIONS, true)) {
            throw new HttpError('Unknown reaction.', 422);
        }
        if (Db::value('SELECT 1 FROM cc_chat WHERE id = ?', [$messageId]) === null) {
            throw new HttpError('Message not found.', 404);
        }
        $has = Db::value('SELECT 1 FROM cc_reactions WHERE message_id = ? AND player_id = ? AND emoji = ?', [$messageId, $p['id'], $emoji]);
        if ($has !== null) {
            Db::run('DELETE FROM cc_reactions WHERE message_id = ? AND player_id = ? AND emoji = ?', [$messageId, $p['id'], $emoji]);
        } else {
            Db::run('INSERT INTO cc_reactions (message_id, player_id, emoji, created_at) VALUES (?,?,?,?)', [$messageId, $p['id'], $emoji, Db::now()]);
        }
        return ['ok' => true];
    }
}
