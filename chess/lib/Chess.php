<?php

/** Legal chess: moves, check, castling, en passant, promotion. */
final class MorseChess
{
    public array $sq = [];
    public string $turn = "w";
    public string $castle = "KQkq";
    public string $ep = "-";
    public int $half = 0;
    public int $full = 1;

    public static function start(): self
    {
        return self::fromFen("rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1");
    }

    public static function fromFen(string $fen): self
    {
        $g = new self();
        $parts = preg_split("/\s+/", trim($fen)) ?: [];
        $ranks = explode("/", $parts[0] ?? "");
        $i = 0;
        foreach ($ranks as $rank) {
            $chars = str_split($rank);
            foreach ($chars as $ch) {
                if (ctype_digit($ch)) {
                    $n = (int) $ch;
                    for ($k = 0; $k < $n; $k++) {
                        $g->sq[$i++] = ".";
                    }
                } else {
                    $g->sq[$i++] = $ch;
                }
            }
        }
        while (count($g->sq) < 64) {
            $g->sq[] = ".";
        }
        $g->sq = array_slice($g->sq, 0, 64);
        $g->turn = ($parts[1] ?? "w") === "b" ? "b" : "w";
        $g->castle = $parts[2] ?? "-";
        if ($g->castle === "") {
            $g->castle = "-";
        }
        $g->ep = $parts[3] ?? "-";
        $g->half = (int) ($parts[4] ?? 0);
        $g->full = max(1, (int) ($parts[5] ?? 1));
        return $g;
    }

    public function fen(): string
    {
        $rows = [];
        for ($r = 0; $r < 8; $r++) {
            $empty = 0;
            $row = "";
            for ($f = 0; $f < 8; $f++) {
                $p = $this->sq[$r * 8 + $f];
                if ($p === ".") {
                    $empty++;
                } else {
                    if ($empty) {
                        $row .= $empty;
                        $empty = 0;
                    }
                    $row .= $p;
                }
            }
            if ($empty) {
                $row .= $empty;
            }
            $rows[] = $row;
        }
        return implode("/", $rows) . " {$this->turn} {$this->castle} {$this->ep} {$this->half} {$this->full}";
    }

    public function result(): string
    {
        $moves = $this->legal();
        if ($moves) {
            return "active";
        }
        if ($this->inCheck($this->turn)) {
            return $this->turn === "w" ? "black_win" : "white_win";
        }
        return "draw";
    }

    /** @return list<array{from:string,to:string,promo:string}> */
    public function legal(): array
    {
        $out = [];
        foreach ($this->pseudo() as $m) {
            $copy = $this->copy();
            $copy->apply($m["from"], $m["to"], $m["promo"]);
            $mover = $this->turn;
            if (!$copy->inCheck($mover)) {
                $out[] = $m;
            }
        }
        return $out;
    }

    public function play(string $from, string $to, string $promo = ""): bool
    {
        $from = strtolower($from);
        $to = strtolower($to);
        $promo = strtolower($promo);
        foreach ($this->legal() as $m) {
            if ($m["from"] === $from && $m["to"] === $to && ($m["promo"] === "" || $m["promo"] === ($promo ?: "q"))) {
                $this->apply($from, $to, $m["promo"] ?: $promo);
                return true;
            }
        }
        return false;
    }

    public function inCheck(string $color): bool
    {
        $king = $color === "w" ? "K" : "k";
        $at = array_search($king, $this->sq, true);
        if ($at === false) {
            return true;
        }
        return $this->attacked((int) $at, $color === "w" ? "b" : "w");
    }

    private function copy(): self
    {
        $g = new self();
        $g->sq = $this->sq;
        $g->turn = $this->turn;
        $g->castle = $this->castle;
        $g->ep = $this->ep;
        $g->half = $this->half;
        $g->full = $this->full;
        return $g;
    }

    /** @return list<array{from:string,to:string,promo:string}> */
    private function pseudo(): array
    {
        $moves = [];
        $white = $this->turn === "w";
        for ($i = 0; $i < 64; $i++) {
            $p = $this->sq[$i];
            if ($p === "." || $this->isWhite($p) !== $white) {
                continue;
            }
            $from = self::name($i);
            $piece = strtolower($p);
            if ($piece === "p") {
                $dir = $white ? -1 : 1;
                $start = $white ? 6 : 1;
                $promoRank = $white ? 0 : 7;
                $r = intdiv($i, 8);
                $f = $i % 8;
                $one = $i + $dir * 8;
                if ($one >= 0 && $one < 64 && $this->sq[$one] === ".") {
                    $this->pushPawn($moves, $from, $one, intdiv($one, 8) === $promoRank);
                    $two = $i + $dir * 16;
                    if ($r === $start && $this->sq[$two] === ".") {
                        $moves[] = ["from" => $from, "to" => self::name($two), "promo" => ""];
                    }
                }
                foreach ([-1, 1] as $df) {
                    $nf = $f + $df;
                    if ($nf < 0 || $nf > 7) {
                        continue;
                    }
                    $j = $i + $dir * 8 + $df;
                    if ($j < 0 || $j >= 64) {
                        continue;
                    }
                    $target = $this->sq[$j];
                    $epHit = self::name($j) === $this->ep;
                    if (($target !== "." && $this->isWhite($target) !== $white) || $epHit) {
                        $this->pushPawn($moves, $from, $j, intdiv($j, 8) === $promoRank);
                    }
                }
            } elseif ($piece === "n") {
                foreach ([[-2, -1], [-2, 1], [-1, -2], [-1, 2], [1, -2], [1, 2], [2, -1], [2, 1]] as [$dr, $df]) {
                    $this->step($moves, $i, $dr, $df, $white, false);
                }
            } elseif ($piece === "k") {
                foreach ([[-1, -1], [-1, 0], [-1, 1], [0, -1], [0, 1], [1, -1], [1, 0], [1, 1]] as [$dr, $df]) {
                    $this->step($moves, $i, $dr, $df, $white, false);
                }
                $this->castleMoves($moves, $i, $white);
            } else {
                $rays = $piece === "b" ? [[-1, -1], [-1, 1], [1, -1], [1, 1]]
                    : ($piece === "r" ? [[-1, 0], [1, 0], [0, -1], [0, 1]]
                    : [[-1, -1], [-1, 0], [-1, 1], [0, -1], [0, 1], [1, -1], [1, 0], [1, 1]]);
                foreach ($rays as [$dr, $df]) {
                    $this->slide($moves, $i, $dr, $df, $white);
                }
            }
        }
        return $moves;
    }

    private function pushPawn(array &$moves, string $from, int $to, bool $promo): void
    {
        if ($promo) {
            foreach (["q", "r", "b", "n"] as $p) {
                $moves[] = ["from" => $from, "to" => self::name($to), "promo" => $p];
            }
            return;
        }
        $moves[] = ["from" => $from, "to" => self::name($to), "promo" => ""];
    }

    private function step(array &$moves, int $i, int $dr, int $df, bool $white, bool $slide): void
    {
        $r = intdiv($i, 8) + $dr;
        $f = ($i % 8) + $df;
        if ($r < 0 || $r > 7 || $f < 0 || $f > 7) {
            return;
        }
        $j = $r * 8 + $f;
        $t = $this->sq[$j];
        if ($t === "." || $this->isWhite($t) !== $white) {
            $moves[] = ["from" => self::name($i), "to" => self::name($j), "promo" => ""];
        }
    }

    private function slide(array &$moves, int $i, int $dr, int $df, bool $white): void
    {
        $r = intdiv($i, 8);
        $f = $i % 8;
        while (true) {
            $r += $dr;
            $f += $df;
            if ($r < 0 || $r > 7 || $f < 0 || $f > 7) {
                return;
            }
            $j = $r * 8 + $f;
            $t = $this->sq[$j];
            if ($t === ".") {
                $moves[] = ["from" => self::name($i), "to" => self::name($j), "promo" => ""];
                continue;
            }
            if ($this->isWhite($t) !== $white) {
                $moves[] = ["from" => self::name($i), "to" => self::name($j), "promo" => ""];
            }
            return;
        }
    }

    private function castleMoves(array &$moves, int $i, bool $white): void
    {
        $need = $white ? ["K", "Q"] : ["k", "q"];
        $home = $white ? 60 : 4;
        if ($i !== $home) {
            return;
        }
        if ($this->inCheck($white ? "w" : "b")) {
            return;
        }
        if (str_contains($this->castle, $need[0]) && $this->sq[$i + 1] === "." && $this->sq[$i + 2] === ".") {
            if (!$this->attacked($i + 1, $white ? "b" : "w") && !$this->attacked($i + 2, $white ? "b" : "w")) {
                $moves[] = ["from" => self::name($i), "to" => self::name($i + 2), "promo" => ""];
            }
        }
        if (str_contains($this->castle, $need[1]) && $this->sq[$i - 1] === "." && $this->sq[$i - 2] === "." && $this->sq[$i - 3] === ".") {
            if (!$this->attacked($i - 1, $white ? "b" : "w") && !$this->attacked($i - 2, $white ? "b" : "w")) {
                $moves[] = ["from" => self::name($i), "to" => self::name($i - 2), "promo" => ""];
            }
        }
    }

    private function attacked(int $target, string $by): bool
    {
        $white = $by === "w";
        $r = intdiv($target, 8);
        $f = $target % 8;
        $pawnDir = $white ? 1 : -1;
        foreach ([-1, 1] as $df) {
            $pr = $r + $pawnDir;
            $pf = $f + $df;
            if ($pr >= 0 && $pr < 8 && $pf >= 0 && $pf < 8) {
                $p = $this->sq[$pr * 8 + $pf];
                if ($p === ($white ? "P" : "p")) {
                    return true;
                }
            }
        }
        foreach ([[-2, -1], [-2, 1], [-1, -2], [-1, 2], [1, -2], [1, 2], [2, -1], [2, 1]] as [$dr, $df]) {
            $nr = $r + $dr;
            $nf = $f + $df;
            if ($nr >= 0 && $nr < 8 && $nf >= 0 && $nf < 8) {
                $p = $this->sq[$nr * 8 + $nf];
                if ($p === ($white ? "N" : "n")) {
                    return true;
                }
            }
        }
        foreach ([[-1, -1], [-1, 0], [-1, 1], [0, -1], [0, 1], [1, -1], [1, 0], [1, 1]] as [$dr, $df]) {
            $nr = $r + $dr;
            $nf = $f + $df;
            if ($nr < 0 || $nr > 7 || $nf < 0 || $nf > 7) {
                continue;
            }
            $p = $this->sq[$nr * 8 + $nf];
            if ($p === ($white ? "K" : "k")) {
                return true;
            }
        }
        $bishop = [[-1, -1], [-1, 1], [1, -1], [1, 1]];
        $rook = [[-1, 0], [1, 0], [0, -1], [0, 1]];
        foreach ([[$bishop, "bq"], [$rook, "rq"]] as [$rays, $kinds]) {
            foreach ($rays as [$dr, $df]) {
                $nr = $r;
                $nf = $f;
                while (true) {
                    $nr += $dr;
                    $nf += $df;
                    if ($nr < 0 || $nr > 7 || $nf < 0 || $nf > 7) {
                        break;
                    }
                    $p = $this->sq[$nr * 8 + $nf];
                    if ($p === ".") {
                        continue;
                    }
                    if ($this->isWhite($p) === $white && str_contains($kinds, strtolower($p))) {
                        return true;
                    }
                    break;
                }
            }
        }
        return false;
    }

    private function apply(string $from, string $to, string $promo): void
    {
        $a = self::index($from);
        $b = self::index($to);
        $piece = $this->sq[$a];
        $captured = $this->sq[$b];
        $white = $this->isWhite($piece);
        $pawn = strtolower($piece) === "p";
        $epHit = $pawn && $to === $this->ep;
        $this->sq[$b] = $piece;
        $this->sq[$a] = ".";
        if ($epHit) {
            $cap = $b + ($white ? 8 : -8);
            $this->sq[$cap] = ".";
            $captured = "p";
        }
        if ($pawn && $promo) {
            $this->sq[$b] = $white ? strtoupper($promo) : $promo;
        }
        if (strtolower($piece) === "k" && abs(($a % 8) - ($b % 8)) === 2) {
            if ($b > $a) {
                $this->sq[$b - 1] = $this->sq[$a + 3];
                $this->sq[$a + 3] = ".";
            } else {
                $this->sq[$b + 1] = $this->sq[$a - 4];
                $this->sq[$a - 4] = ".";
            }
        }
        $this->castle = $this->dropCastle($piece, $from, $to);
        if ($pawn && abs(intdiv($a, 8) - intdiv($b, 8)) === 2) {
            $mid = intdiv($a + $b, 2);
            $this->ep = self::name($mid);
        } else {
            $this->ep = "-";
        }
        if ($pawn || $captured !== ".") {
            $this->half = 0;
        } else {
            $this->half++;
        }
        if (!$white) {
            $this->full++;
        }
        $this->turn = $white ? "b" : "w";
    }

    private function dropCastle(string $piece, string $from, string $to): string
    {
        $c = $this->castle === "-" ? "" : $this->castle;
        $kill = function (string $right) use (&$c): void {
            $c = str_replace($right, "", $c);
        };
        if ($piece === "K") {
            $kill("K");
            $kill("Q");
        }
        if ($piece === "k") {
            $kill("k");
            $kill("q");
        }
        foreach ([$from, $to] as $sq) {
            if ($sq === "h1") $kill("K");
            if ($sq === "a1") $kill("Q");
            if ($sq === "h8") $kill("k");
            if ($sq === "a8") $kill("q");
        }
        return $c === "" ? "-" : $c;
    }

    private function isWhite(string $p): bool
    {
        return $p !== "." && ctype_upper($p);
    }

    private static function name(int $i): string
    {
        return chr(97 + ($i % 8)) . (string) (8 - intdiv($i, 8));
    }

    private static function index(string $sq): int
    {
        $f = ord($sq[0]) - 97;
        $r = ord($sq[1]) - 49;
        return (7 - $r) * 8 + $f;
    }
}
