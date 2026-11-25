<?php

declare(strict_types=1);

namespace ComboCraft;

/** Offline fallback when no AI answer is available: a deterministic, PG mash-up. */
final class Remix
{
    private const TAGLINES = [
        'Nobody asked for this. Everyone loves it.',
        'Science has gone too far.',
        'Patent pending. Probably.',
        'Handle with extreme curiosity.',
        'It shouldn\'t work. It does.',
        'Limited edition chaos.',
        'Certified fresh from the lab.',
        'Your mom would not approve.',
        'Ten out of ten. No notes.',
        'Somehow both and neither.',
    ];

    /**
     * @param array<string,mixed> $ea
     * @param array<string,mixed> $eb
     * @return array{name:string,emoji:?string,tagline:string}
     */
    public static function make(array $ea, array $eb): array
    {
        $a = (string) $ea['name'];
        $b = (string) $eb['name'];
        $h = crc32(Seeds::key($a, $b));
        if (Text::key($a) === Text::key($b)) {
            $forms = ['Double ' . $a, 'Mega ' . $a, $a . ' Squared', 'Infinite ' . $a, $a . ' Army'];
            $name = $forms[$h % count($forms)];
        } else {
            // Shorter name first reads better ("Cat Tornado", "Pizza of Doom").
            [$s, $l] = mb_strlen($a) <= mb_strlen($b) ? [$a, $b] : [$b, $a];
            $lastWordL = self::lastWord($l);
            $lastWordS = self::lastWord($s);
            $forms = [
                $s . ' ' . $lastWordL,
                $lastWordL . ' ' . $s,
                'Super ' . $s . ' ' . $lastWordL,
                $lastWordS . '-Powered ' . $lastWordL,
                'The Great ' . $lastWordS . ' ' . $lastWordL,
                $lastWordL . ' of ' . $lastWordS,
                self::portmanteau($lastWordS, $lastWordL),
            ];
            $name = $forms[$h % count($forms)];
        }
        $name = Text::cleanName(mb_substr($name, 0, 48)) ?? ($a . ' ' . $b);
        return [
            'name' => $name,
            'emoji' => self::emojiFor($name, $ea, $eb),
            'tagline' => self::TAGLINES[($h >> 4) % count(self::TAGLINES)],
        ];
    }

    private static function lastWord(string $s): string
    {
        $parts = preg_split('/\s+/u', trim($s)) ?: [$s];
        return (string) end($parts);
    }

    private static function portmanteau(string $a, string $b): string
    {
        $va = preg_match('/^(.*?[aeiouy])[^aeiouy]*$/iu', $a, $m) ? $m[1] : $a;
        $cut = (int) floor(mb_strlen($b) / 2);
        $out = mb_substr($va !== '' ? $va : $a, 0, max(2, mb_strlen($va))) . mb_strtolower(mb_substr($b, $cut));
        return mb_strlen($out) >= 4 ? $out : $a . $b;
    }

    /** @param array<string,mixed> $ea @param array<string,mixed> $eb */
    public static function emojiFor(string $name, array $ea, array $eb): string
    {
        $map = [
            'cat' => '🐱', 'kitty' => '😺', 'dog' => '🐶', 'pizza' => '🍕', 'fire' => '🔥', 'flame' => '🔥', 'water' => '💧', 'ocean' => '🌊',
            'money' => '💰', 'cash' => '💸', 'gold' => '🪙', 'love' => '💖', 'heart' => '💗', 'robot' => '🤖', 'ai' => '🤖', 'bot' => '🤖',
            'meme' => '😂', 'chaos' => '🌀', 'storm' => '⛈️', 'cloud' => '☁️', 'wind' => '🌬️', 'earth' => '🌍', 'planet' => '🪐',
            'moon' => '🌙', 'star' => '⭐', 'sun' => '☀️', 'dragon' => '🐉', 'ghost' => '👻', 'king' => '👑', 'queen' => '👑', 'crown' => '👑',
            'rocket' => '🚀', 'space' => '🌌', 'time' => '⏳', 'music' => '🎵', 'party' => '🎉', 'phone' => '📱', 'video' => '📺', 'tv' => '📺',
            'coffee' => '☕', 'cake' => '🎂', 'ice' => '🧊', 'snow' => '❄️', 'magic' => '✨', 'wizard' => '🧙', 'zombie' => '🧟', 'alien' => '👽',
            'volcano' => '🌋', 'tornado' => '🌪️', 'tree' => '🌳', 'plant' => '🌱', 'flower' => '🌸', 'book' => '📖', 'school' => '🏫',
            'car' => '🚗', 'train' => '🚂', 'plane' => '✈️', 'boat' => '⛵', 'ship' => '🚢', 'bank' => '🏦', 'crypto' => '🪙', 'rich' => '🤑',
            'sad' => '😢', 'angry' => '😡', 'cool' => '😎', 'sleep' => '😴', 'nap' => '😴', 'brain' => '🧠', 'computer' => '💻', 'game' => '🎮',
            'sauce' => '🥫', 'cheese' => '🧀', 'taco' => '🌮', 'burger' => '🍔', 'donut' => '🍩', 'cookie' => '🍪', 'baby' => '👶', 'vote' => '🗳️',
        ];
        foreach (preg_split('/[\s\-]+/u', mb_strtolower($name)) ?: [] as $w) {
            $w = trim($w, "'’!?.,:");
            if (isset($map[$w])) {
                return $map[$w];
            }
            if (str_ends_with($w, 's') && isset($map[substr($w, 0, -1)])) {
                return $map[substr($w, 0, -1)];
            }
        }
        $pick = (crc32($name) & 1) ? $eb : $ea;
        return (string) $pick['emoji'] ?: '✨';
    }
}
