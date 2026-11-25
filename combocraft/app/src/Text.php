<?php

declare(strict_types=1);

namespace ComboCraft;

/** Names, emoji checks and the safety filter. */
final class Text
{
    /** @var array<string,mixed>|null */
    private static ?array $fluent = null;

    public static function key(string $name): string
    {
        $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? ''));
        return mb_substr($s, 0, 64);
    }

    /** Whole-word terms (after normalising leetspeak). */
    private const WORDS = [
        'sex', 'sexy', 'sexual', 'porn', 'porno', 'nude', 'nudes', 'naked', 'boob', 'boobs', 'tits', 'titty', 'penis', 'vagina',
        'dick', 'cock', 'pussy', 'cum', 'orgasm', 'horny', 'fetish', 'erotic', 'xxx', 'nsfw', 'rape', 'raped', 'rapist',
        'molest', 'pedo', 'pedophile', 'incest', 'slut', 'whore', 'hooker', 'stripper', 'onlyfans', 'kys', 'suicide',
        'nazi', 'nazis', 'hitler', 'kkk', 'swastika', 'genocide', 'terrorist', 'isis', 'jihad', 'lynch', 'lynching',
        'holocaust', 'cocaine', 'heroin', 'meth', 'fentanyl', 'gore', 'behead', 'beheading', 'fuck', 'fucking', 'fucker',
        'shit', 'bitch', 'bastard', 'asshole', 'cunt', 'twat', 'wank', 'wanker', 'retard', 'retarded', 'fag', 'faggot',
        'tranny', 'chink', 'spic', 'kike', 'gook', 'wetback', 'nigger', 'nigga', 'negro', 'coon', 'dyke', 'jizz', 'blowjob',
        'handjob', 'dildo', 'vibrator', 'condom', 'thong', 'bdsm', 'milf', 'hentai', 'trump', 'biden', 'obama', 'putin',
        'musk', 'zelensky', 'netanyahu', 'jinping', 'jong', 'bin laden', 'osama', 'selfharm', 'anorexia',
        'shooter', 'massacre', 'bomber', 'bombing', 'murder', 'murderer', 'corpse', 'dead body',
    ];

    /** Substrings that are never fine, even inside other words. */
    private const STEMS = [
        'fuck', 'nigg', 'faggot', 'pornhub', 'xvideo', 'hitler', 'rapist', 'pedophil', 'molest', 'kill yourself',
        'killyourself', 'self harm', 'selfharm', 'decapitat', 'dismember', 'mutilat', 'masturbat', 'ejaculat', 'genital',
    ];

    public static function isSafe(string ...$parts): bool
    {
        foreach ($parts as $raw) {
            if ($raw === '') {
                continue;
            }
            $s = mb_strtolower($raw);
            $s = strtr($s, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's', '!' => 'i']);
            $s = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
            $compact = (string) preg_replace('/\s+/u', '', $s);
            foreach (self::STEMS as $stem) {
                $st = str_replace(' ', '', $stem);
                if (str_contains($compact, $st)) {
                    return false;
                }
            }
            $padded = ' ' . preg_replace('/\s+/u', ' ', $s) . ' ';
            foreach (self::WORDS as $w) {
                if (str_contains($padded, ' ' . $w . ' ')) {
                    return false;
                }
            }
        }
        return true;
    }

    /** Title-case words, keeping short connectors lower and acronyms upper. */
    public static function title(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $small = ['a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'in', 'of', 'on', 'or', 'the', 'to', 'vs', 'with', 'who'];
        $words = explode(' ', $name);
        foreach ($words as $i => $w) {
            if ($w === '') {
                continue;
            }
            if (preg_match('/^[A-Z0-9]{2,4}$/', $w)) {
                continue; // AI, CEO, NFT
            }
            $lw = mb_strtolower($w);
            if ($i > 0 && in_array($lw, $small, true)) {
                $words[$i] = $lw;
                continue;
            }
            $words[$i] = mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1);
        }
        return implode(' ', $words);
    }

    public static function cleanName(string $name): ?string
    {
        $name = trim((string) preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', $name));
        $name = trim($name, " \t\n\r\0\x0B\"'`*.:;");
        $name = (string) preg_replace('/\s+/u', ' ', $name);
        if ($name === '' || mb_strlen($name) > 48) {
            return null;
        }
        if (!preg_match("/^[\\p{L}\\p{N}][\\p{L}\\p{N} '’&\\-!?.,:]*$/u", $name)) {
            return null;
        }
        if (count(explode(' ', $name)) > 8) {
            return null;
        }
        return self::title($name);
    }

    public static function cleanTagline(?string $t): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', (string) $t));
        $t = trim($t, " \"'`*");
        if (mb_strlen($t) > 90) {
            $t = rtrim(mb_substr($t, 0, 88)) . '…';
        }
        return $t;
    }

    /** @return array<string,mixed> */
    private static function fluent(): array
    {
        if (self::$fluent === null) {
            $file = dirname(Config::root()) . '/assets/data/fluent.json';
            $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            self::$fluent = is_array($data) ? $data : [];
        }
        return self::$fluent;
    }

    public static function emojiKnown(string $e): bool
    {
        $k = str_replace("\u{FE0F}", '', $e);
        return $k !== '' && isset(self::fluent()[$k]);
    }

    /** Crowns are never shown in ComboCraft (owner t424u). */
    public static function safeEmoji(?string $e): string
    {
        return str_replace('👑', '⭐', (string) $e);
    }

    /** First emoji in the text that has artwork, or null. */
    public static function pickEmoji(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if (self::emojiKnown($text)) {
            return self::safeEmoji($text);
        }
        // Try progressively: grapheme clusters
        if (preg_match_all('/\X/u', $text, $m)) {
            foreach ($m[0] as $g) {
                if (self::emojiKnown($g)) {
                    return self::safeEmoji($g);
                }
                $first = mb_substr($g, 0, 1);
                if (self::emojiKnown($first)) {
                    return self::safeEmoji($first);
                }
            }
        }
        return null;
    }
}
