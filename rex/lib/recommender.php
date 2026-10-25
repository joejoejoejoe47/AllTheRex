<?php
declare(strict_types=1);

/**
 * Item-based collaborative filtering.
 *
 * Idea: if many people rate Title X and Title Y both high (or both low), the two titles are
 * "correlated". When you rate X high, Y gets recommended, and vice versa.
 *
 * Similarity between two titles = adjusted cosine over the users who rated BOTH:
 *   each rating is first centred (dev = rating - that user's centre), then
 *   sim = sum(dev_x * dev_y) / sqrt(sum(dev_x^2) * sum(dev_y^2))        range -1 .. +1
 * The centre is halfway between the user's own average and the scale midpoint (3). Centring purely
 * on the user's average discounts generous raters well but, on a site where people mostly rate things
 * they like, it also cancels the "both liked" signal (measured: it missed a genuinely co-liked pair in
 * ~9% of small samples; the halfway centre never did). Two more safeguards keep small data honest:
 *   - a user's average is pulled toward the site-wide average by REX_PRIOR pseudo-ratings;
 *   - sim is shrunk by n / (n + REX_SHRINK), where n = number of shared raters, so two people
 *     agreeing is a weak signal and fifty people agreeing is a strong one.
 */
const REX_MIN_CORATERS = 2;     // need at least this many people who rated both titles
const REX_SHRINK       = 2.0;   // significance shrinkage (higher = more cautious)
const REX_PRIOR        = 2.0;   // pseudo-ratings pulling a user's mean toward the global mean
const REX_NEUTRAL      = 3.0;   // midpoint of the 1-5 scale
const REX_CENTER_BLEND = 0.5;   // 1 = centre on the user's mean only, 0 = centre on the scale midpoint only
const REX_MIN_ABS_SIM  = 0.05;  // don't store near-zero similarities
const REX_NEIGHBORS    = 20;    // max similar titles used to predict a single title
const REX_DAMPING      = 0.3;   // damps predictions that rest on little similarity evidence
const REX_MAX_PER_USER = 300;   // cap per-user pair explosion on rebuild

function rex_global_mean(): float
{
    $r = q1('SELECT AVG(rating) AS g FROM ratings');
    return ($r && $r['g'] !== null) ? (float)$r['g'] : 3.5;
}

/** The point a user's ratings are measured from. $ratings = [item_id => rating]. */
function rex_user_center(array $ratings, float $g): float
{
    $n    = count($ratings);
    $mean = ($n === 0) ? $g : (array_sum($ratings) + REX_PRIOR * $g) / ($n + REX_PRIOR);
    return REX_CENTER_BLEND * $mean + (1 - REX_CENTER_BLEND) * REX_NEUTRAL;
}

/**
 * Pure function (no database): similarities between titles.
 * $byUser = [user_id => [item_id => rating]]. When $only is set, just pairs involving that item.
 * Returns a list of [item_a, item_b, sim, n] with item_a < item_b.
 */
function rex_pair_sims(array $byUser, float $g, ?int $only = null): array
{
    $acc = []; // "a|b" => [num, devA2, devB2, n]
    foreach ($byUser as $ratings) {
        if (count($ratings) < 2) {
            continue;
        }
        if (count($ratings) > REX_MAX_PER_USER) {
            $ratings = array_slice($ratings, 0, REX_MAX_PER_USER, true);
        }
        $mu  = rex_user_center($ratings, $g);
        $dev = [];
        foreach ($ratings as $item => $r) {
            $dev[(int)$item] = $r - $mu;
        }
        $ids = array_keys($dev);
        sort($ids);
        $cnt = count($ids);
        for ($i = 0; $i < $cnt; $i++) {
            for ($j = $i + 1; $j < $cnt; $j++) {
                $a = $ids[$i];
                $b = $ids[$j];
                if ($only !== null && $a !== $only && $b !== $only) {
                    continue;
                }
                $k = $a . '|' . $b;
                if (!isset($acc[$k])) {
                    $acc[$k] = [0.0, 0.0, 0.0, 0];
                }
                $acc[$k][0] += $dev[$a] * $dev[$b];
                $acc[$k][1] += $dev[$a] * $dev[$a];
                $acc[$k][2] += $dev[$b] * $dev[$b];
                $acc[$k][3]++;
            }
        }
    }

    $out = [];
    foreach ($acc as $k => [$num, $da, $db, $n]) {
        if ($n < REX_MIN_CORATERS || $da <= 0.0 || $db <= 0.0) {
            continue;
        }
        $sim = ($num / sqrt($da * $db)) * ($n / ($n + REX_SHRINK));
        if (abs($sim) < REX_MIN_ABS_SIM) {
            continue;
        }
        [$a, $b] = array_map('intval', explode('|', $k));
        $out[] = [$a, $b, $sim, $n];
    }
    return $out;
}

/** Load [user_id => [item_id => rating]] for the given users. */
function rex_ratings_by_user(array $userIds): array
{
    $byUser = [];
    foreach (array_chunk($userIds, 400) as $chunk) {
        foreach (q('SELECT user_id, item_id, rating FROM ratings WHERE user_id IN (' . placeholders(count($chunk)) . ')', $chunk) as $row) {
            $byUser[(int)$row['user_id']][(int)$row['item_id']] = (int)$row['rating'];
        }
    }
    return $byUser;
}

function rex_store_pairs(array $pairs): void
{
    foreach (array_chunk($pairs, 200) as $chunk) {
        $sql = 'REPLACE INTO item_sim (item_a, item_b, sim, n) VALUES '
             . implode(',', array_fill(0, count($chunk), '(?,?,?,?)'));
        $params = [];
        foreach ($chunk as [$a, $b, $sim, $n]) {
            array_push($params, $a, $b, (string)round($sim, 6), $n);
        }
        qx($sql, $params);
    }
}

/** Called after someone rates (or un-rates) a title: refresh that title's similarities. */
function rec_update_item(int $itemId): void
{
    $pdo = db();
    $users = array_map('intval', array_column(q('SELECT user_id FROM ratings WHERE item_id = ?', [$itemId]), 'user_id'));
    $pairs = $users ? rex_pair_sims(rex_ratings_by_user($users), rex_global_mean(), $itemId) : [];

    $pdo->beginTransaction();
    try {
        qx('DELETE FROM item_sim WHERE item_a = ? OR item_b = ?', [$itemId, $itemId]);
        rex_store_pairs($pairs);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Recompute every similarity from scratch (cli/rebuild.php, or a nightly Scheduled Task). */
function rec_rebuild_all(): int
{
    $users = array_map('intval', array_column(q('SELECT DISTINCT user_id FROM ratings'), 'user_id'));
    $pairs = rex_pair_sims(rex_ratings_by_user($users), rex_global_mean());

    $pdo = db();
    $pdo->beginTransaction();
    try {
        qx('DELETE FROM item_sim');
        rex_store_pairs($pairs);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return count($pairs);
}

/**
 * Recommended titles for a user, best first.
 * Each result: item row + stats + 'score' (predicted 1-5 rating), 'because' (titles that drove it).
 * Falls back to well-rated popular titles to fill the list (reason = 'popular').
 */
function rec_for_user(int $userId, ?string $category = null, int $limit = 12): array
{
    $mine = [];
    foreach (q('SELECT item_id, rating FROM ratings WHERE user_id = ?', [$userId]) as $r) {
        $mine[(int)$r['item_id']] = (int)$r['rating'];
    }
    if (!$mine) {
        return [];
    }
    $g  = rex_global_mean();
    $mu = rex_user_center($mine, $g);
    $ids = array_keys($mine);

    $cand = []; // candidate item => list of [source_item, sim]
    foreach (array_chunk($ids, 400) as $chunk) {
        $ph = placeholders(count($chunk));
        $rows = q("SELECT item_a, item_b, sim FROM item_sim WHERE item_a IN ($ph) OR item_b IN ($ph)", array_merge($chunk, $chunk));
        foreach ($rows as $row) {
            $a = (int)$row['item_a'];
            $b = (int)$row['item_b'];
            $s = (float)$row['sim'];
            if (isset($mine[$a]) && !isset($mine[$b])) {
                $cand[$b][$a] = $s;
            } elseif (isset($mine[$b]) && !isset($mine[$a])) {
                $cand[$a][$b] = $s;
            }
        }
    }

    $scored = [];
    foreach ($cand as $itemId => $sources) {
        uasort($sources, fn($x, $y) => abs($y) <=> abs($x));
        $sources = array_slice($sources, 0, REX_NEIGHBORS, true);
        $num = 0.0;
        $den = 0.0;
        $why = [];
        foreach ($sources as $src => $s) {
            $contrib = $s * ($mine[$src] - $mu);
            $num += $contrib;
            $den += abs($s);
            if ($contrib > 0) {
                $why[$src] = $contrib;
            }
        }
        if ($num <= 0) {
            continue; // predicted at or below this person's own average: not a recommendation
        }
        arsort($why);
        $scored[$itemId] = [
            'score'   => min(5.0, max(1.0, $mu + $num / ($den + REX_DAMPING))),
            'weight'  => $den,
            'because' => array_slice(array_keys($why), 0, 2),
        ];
    }
    uasort($scored, fn($x, $y) => [$y['score'], $y['weight']] <=> [$x['score'], $x['weight']]);

    $results = [];
    if ($scored) {
        $top  = array_slice($scored, 0, 150, true);
        $rows = catalog_items_by_ids(array_keys($top), $userId);
        foreach ($top as $itemId => $info) {
            $row = $rows[$itemId] ?? null;
            if (!$row || ($category && $row['category'] !== $category)) {
                continue;
            }
            $row['score']   = $info['score'];
            $row['reason']  = 'similar';
            $row['because'] = array_values(catalog_items_by_ids($info['because'], $userId));
            $results[] = $row;
            if (count($results) >= $limit) {
                break;
            }
        }
    }

    if (count($results) < $limit) {
        $have = array_flip(array_column($results, 'id'));
        foreach (catalog_popular($category, $limit * 2, $userId) as $row) {
            if (isset($have[$row['id']])) {
                continue;
            }
            $row['score']   = null;
            $row['reason']  = 'popular';
            $row['because'] = [];
            $results[] = $row;
            if (count($results) >= $limit) {
                break;
            }
        }
    }
    return $results;
}

/** Titles most often rated like this one: "people who liked this also liked". */
function rec_similar_items(int $itemId, int $limit = 6, ?int $userId = null): array
{
    $rows = q(
        'SELECT item_a, item_b, sim, n FROM item_sim WHERE (item_a = ? OR item_b = ?) AND sim > 0 ORDER BY sim DESC LIMIT ?',
        [$itemId, $itemId, $limit]
    );
    $order = [];
    $meta  = [];
    foreach ($rows as $r) {
        $other = ((int)$r['item_a'] === $itemId) ? (int)$r['item_b'] : (int)$r['item_a'];
        $order[] = $other;
        $meta[$other] = ['sim' => (float)$r['sim'], 'n' => (int)$r['n']];
    }
    $items = catalog_items_by_ids($order, $userId);
    $out = [];
    foreach ($order as $id) {
        if (isset($items[$id])) {
            $out[] = $items[$id] + $meta[$id];
        }
    }
    return $out;
}
