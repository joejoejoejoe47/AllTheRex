<?php

declare(strict_types=1);

namespace ComboCraft;

/**
 * Server-authoritative Clash scoring: unrelated combos stay low,
 * and points climb steeply as a combo gets closer to the theme or target.
 */
final class ClashScore
{
    public const BASE = 5;
    public const SCALE = 300;
    public const EXACT_BONUS = 500;
    public const COLD_MAX = 0.35;

    /** @var array<string,list<string>>|null */
    private static ?array $adj = null;

    /** @var array<string,string> name_key => display name */
    private static ?array $names = null;

    public static function heat(float $closeness): string
    {
        if ($closeness >= 0.90) {
            return 'On Fire';
        }
        if ($closeness >= 0.70) {
            return 'Hot';
        }
        if ($closeness >= 0.4) {
            return 'Warm';
        }
        return 'Cold';
    }

    /**
     * points = 5 + 300 * closeness^3, plus 500 for an exact target hit.
     * Repeated cold combos in the same round shrink toward 1 so mashing cannot
     * outscore one close combo.
     */
    public static function points(float $closeness, bool $exact, int $priorCold): int
    {
        $closeness = max(0.0, min(1.0, $closeness));
        $pts = self::BASE + self::SCALE * ($closeness ** 3);
        if ($exact) {
            $pts += self::EXACT_BONUS;
        }
        if (!$exact && $closeness < self::COLD_MAX && $priorCold > 0) {
            $pts = $pts / (1 + $priorCold * 0.9);
        }
        return max($exact ? self::EXACT_BONUS : 1, (int) round($pts));
    }

    /**
     * @param array<string,mixed> $made
     * @return array{closeness:float,heat:string,exact:bool,graph:float,theme:float,score:int}
     */
    public static function evaluate(string $theme, array $made, ?string $targetName, int $priorCold): array
    {
        $name = (string) ($made['name'] ?? '');
        $tag = (string) ($made['tagline'] ?? '');
        $target = $targetName !== null && $targetName !== '' ? $targetName : null;
        $exact = $target !== null && Text::key($name) !== '' && Text::key($name) === Text::key($target);
        if (!$exact && $target === null) {
            $exact = self::nameIsTheme($theme, $name);
        }

        $themeC = self::themeCloseness($theme, $name, $tag);
        $graphC = 0.0;
        if ($target !== null) {
            $graphC = self::graphCloseness($name, $target);
        } else {
            $graphC = self::graphClosenessToTheme($theme, $name);
        }
        $closeness = max($themeC, $graphC);
        if ($exact) {
            $closeness = 1.0;
        }
        $score = self::points($closeness, $exact, $priorCold);
        return [
            'closeness' => round($closeness, 4),
            'heat' => self::heat($closeness),
            'exact' => $exact,
            'graph' => round($graphC, 4),
            'theme' => round($themeC, 4),
            'score' => $score,
        ];
    }

    /**
     * Fold an optional 0–100 AI closeness into the 0–1 score.
     * The AI may not award On Fire on its own: loose associations
     * (steam is warm, therefore "cozy") stay at most Hot, and only a
     * strong local theme/graph fit can confirm On Fire.
     */
    public static function withAi(?float $aiCloseness, array $eval): array
    {
        if ($aiCloseness === null) {
            return $eval;
        }
        $local = (float) $eval['closeness'];
        $ai = max(0.0, min(1.0, $aiCloseness / 100));
        // Compress mid/high scores so "pretty good" does not become 1.0.
        $ai = $ai ** 1.45;
        $c = max($local, $ai);
        if (!empty($eval['exact'])) {
            $c = 1.0;
        } elseif ($c >= 0.90 && $local < 0.55) {
            // Uncorroborated AI: cap below On Fire.
            $c = min($c, 0.82);
        } elseif ($c >= 0.70 && $local < 0.28) {
            // Pure stretch associations stay Warm.
            $c = min($c, 0.58);
        }
        $eval['closeness'] = round($c, 4);
        $eval['heat'] = self::heat((float) $eval['closeness']);
        $eval['aiRaw'] = round($aiCloseness / 100, 4);
        return $eval;
    }

    public static function recountPoints(array $eval, int $priorCold): array
    {
        $eval['score'] = self::points((float) $eval['closeness'], !empty($eval['exact']), $priorCold);
        $eval['heat'] = self::heat((float) $eval['closeness']);
        return $eval;
    }

    private static function nameIsTheme(string $theme, string $name): bool
    {
        $nk = Text::key($name);
        if (strlen($nk) < 4) {
            return false;
        }
        foreach (self::themeTokens($theme) as $t) {
            if ($t === $nk || (strlen($nk) >= 5 && $t === $nk)) {
                return true;
            }
        }
        return false;
    }

    private static function themeCloseness(string $theme, string $name, string $tag): float
    {
        $hay = Text::key($name . ' ' . $tag);
        if ($hay === '') {
            return 0.0;
        }
        $tokens = self::themeTokens($theme);
        if ($tokens === []) {
            return 0.0;
        }
        $hit = 0;
        $best = 0.0;
        foreach ($tokens as $t) {
            if ($hay === $t) {
                return 0.97;
            }
            if (str_contains($hay, $t) || str_contains($t, $hay)) {
                $hit++;
                $best = max($best, min(0.9, strlen($t) / max(4, strlen($hay))));
            }
        }
        if ($hit === 0) {
            return 0.0;
        }
        return min(0.82, 0.18 * $hit + $best * 0.4);
    }

    /** @return list<string> */
    public static function themeTokens(string $theme): array
    {
        $stop = ['create' => 1, 'make' => 1, 'build' => 1, 'invent' => 1, 'brew' => 1, 'summon' => 1, 'design' => 1, 'spark' => 1, 'craft' => 1, 'the' => 1, 'most' => 1, 'a' => 1, 'an' => 1, 'of' => 1, 'that' => 1, 'would' => 1, 'no' => 1, 'one' => 1, 'asked' => 1, 'for' => 1, 'something' => 1, 'ultimate' => 1, 'biggest' => 1, 'weirdest' => 1];
        $extra = [
            'warrior' => ['war', 'battle', 'sword', 'hero', 'knight', 'armor', 'champion'],
            'disaster' => ['chaos', 'storm', 'crash', 'explode', 'wreck', 'ruin'],
            'meme' => ['funny', 'lol', 'cat', 'dog', 'cursed', 'joke'],
            'destroy' => ['destroy', 'counter', 'crush', 'anti', 'break'],
            'comfort' => ['cozy', 'soft', 'blanket', 'cocoa', 'pillow', 'hug', 'sweater'],
            'snack' => ['food', 'pizza', 'cake', 'candy', 'soup', 'bread', 'sweet'],
            'cute' => ['cute', 'cat', 'puppy', 'baby', 'soft', 'love'],
            'flex' => ['gold', 'rich', 'money', 'rare', 'jewel'],
            'twist' => ['secret', 'surprise', 'unexpected', 'glitch'],
            'underdog' => ['underdog', 'courage', 'hope', 'rebel'],
            'party' => ['party', 'music', 'dance', 'confetti', 'disco'],
            'hero' => ['hero', 'legendary', 'savior', 'myth'],
        ];
        $out = [];
        foreach (preg_split('/[^a-z0-9]+/i', mb_strtolower($theme)) ?: [] as $w) {
            if (strlen($w) < 4 || isset($stop[$w])) {
                continue;
            }
            $out[] = $w;
            if (isset($extra[$w])) {
                foreach ($extra[$w] as $s) {
                    $out[] = $s;
                }
            }
        }
        return array_values(array_unique($out));
    }

    public static function graphCloseness(string $fromName, string $toName): float
    {
        $a = Text::key($fromName);
        $b = Text::key($toName);
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        $d = self::graphDistance($a, $b);
        if ($d === null) {
            return 0.0;
        }
        return max(0.0, min(1.0, 0.72 ** $d));
    }

    public static function graphClosenessToTheme(string $theme, string $name): float
    {
        $from = Text::key($name);
        if ($from === '') {
            return 0.0;
        }
        self::loadGraph();
        $best = null;
        foreach (self::themeTokens($theme) as $t) {
            foreach (self::$names ?? [] as $key => $display) {
                $dn = Text::key($display);
                $hit = $key === $t || $dn === $t;
                if (!$hit && strlen($t) >= 5) {
                    $hit = str_contains($key, $t) || str_contains($dn, $t);
                }
                if (!$hit) {
                    continue;
                }
                $d = self::graphDistance($from, $key);
                if ($d !== null && ($best === null || $d < $best)) {
                    $best = $d;
                }
            }
        }
        if ($best === null) {
            return 0.0;
        }
        // Theme-token graph hits are supporting evidence, not an exact target.
        return max(0.0, min(0.78, 0.72 ** $best));
    }

    /** @return int|null hops, or null if unreachable / missing */
    public static function graphDistance(string $fromKey, string $toKey): ?int
    {
        self::loadGraph();
        if ($fromKey === $toKey) {
            return 0;
        }
        if (!isset(self::$adj[$fromKey], self::$adj[$toKey])) {
            return null;
        }
        $q = [$fromKey];
        $seen = [$fromKey => 0];
        $i = 0;
        while ($i < count($q) && $i < 4000) {
            $cur = $q[$i++];
            $d = $seen[$cur];
            if ($d >= 8) {
                continue;
            }
            foreach (self::$adj[$cur] ?? [] as $n) {
                if (isset($seen[$n])) {
                    continue;
                }
                if ($n === $toKey) {
                    return $d + 1;
                }
                $seen[$n] = $d + 1;
                $q[] = $n;
            }
        }
        return null;
    }

    private static function loadGraph(): void
    {
        if (self::$adj !== null) {
            return;
        }
        $adj = [];
        $names = [];
        try {
            $g = Daily::graph();
            foreach ($g['nodes'] as $k => $n) {
                $names[$k] = (string) ($n['name'] ?? $k);
                if (!isset($adj[$k])) {
                    $adj[$k] = [];
                }
            }
            foreach ($g['recipes'] as $rec) {
                $a = (string) $rec['a'];
                $b = (string) $rec['b'];
                $r = (string) $rec['r'];
                foreach ([[$a, $r], [$r, $a], [$b, $r], [$r, $b], [$a, $b], [$b, $a]] as [$x, $y]) {
                    if ($x === '' || $y === '' || $x === $y) {
                        continue;
                    }
                    $adj[$x][] = $y;
                }
            }
        } catch (\Throwable $e) {
        }
        foreach ($adj as $k => $list) {
            $adj[$k] = array_values(array_unique($list));
        }
        self::$adj = $adj;
        self::$names = $names;
    }
}
