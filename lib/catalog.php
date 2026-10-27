<?php
declare(strict_types=1);

const REX_PAGE_SIZE = 24;

function item_dedupe_key(string $category, string $title, string $creator): string
{
    $norm = fn(string $s) => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s));
    return sha1($category . '|' . $norm($title) . '|' . $norm($creator));
}

/** @return array{0:?int,1:array<string,string>} [item id, errors]. On a duplicate, returns the existing id. */
function add_item(string $category, string $title, string $creator, ?string $year, ?int $userId, bool $fetchImage = true, ?string $image = null): array
{
    $errors = [];
    $category = valid_category($category);
    $title    = clean_text($title, 190);
    $creator  = clean_text($creator, 190);
    $y = null;

    if (!$category) {
        $errors['category'] = 'Choose movie, album or book.';
    }
    if ($title === '') {
        $errors['title'] = 'A title is required.';
    }
    if ($year !== null && trim($year) !== '') {
        if (!ctype_digit(trim($year)) || (int)$year < 1000 || (int)$year > (int)date('Y') + 2) {
            $errors['year'] = 'Enter a four-digit year, or leave it blank.';
        } else {
            $y = (int)$year;
        }
    }
    if ($errors) {
        return [null, $errors];
    }

    $key = item_dedupe_key($category, $title, $creator);
    $existing = q1('SELECT id FROM items WHERE dedupe_key = ?', [$key]);
    if ($existing) {
        return [(int)$existing['id'], ['duplicate' => 'That title is already in the catalog.']];
    }
    qx('INSERT INTO items (category, title, creator, year, dedupe_key, created_by) VALUES (?, ?, ?, ?, ?, ?)',
        [$category, $title, $creator, $y, $key, $userId]);
    $newId = (int)db()->lastInsertId();
    if (($img = rex_clean_image_url($image)) !== null) {
        qx('UPDATE items SET image_url = ?, image_tried = 1 WHERE id = ?', [$img, $newId]);
    } elseif ($fetchImage) {
        rex_fetch_image_for($newId);   // best effort; never blocks adding the title
    }
    return [$newId, []];
}

/**
 * Is this title already in the catalog? Matches the exact key, or the same normalised title with a
 * compatible year (so "Inception" from a public database doesn't duplicate our "Inception").
 */
function catalog_find_existing(string $category, string $title, string $creator, ?int $year): ?int
{
    $r = q1('SELECT id FROM items WHERE dedupe_key = ?', [item_dedupe_key($category, $title, $creator)]);
    if ($r) {
        return (int)$r['id'];
    }
    $nt = rex_norm($title);
    if ($nt === '') {
        return null;
    }
    $first = explode(' ', $nt)[0];
    $like  = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $first) . '%';
    foreach (q("SELECT id, title, year FROM items WHERE category = ? AND title LIKE ? ESCAPE '!'", [$category, $like]) as $row) {
        if (rex_norm($row['title']) === $nt && ($year === null || $row['year'] === null || abs((int)$row['year'] - $year) <= 1)) {
            return (int)$row['id'];
        }
    }
    return null;
}

/** Stats SELECT shared by every listing. $uid = viewer, adds my_rating. */
function item_select_sql(): string
{
    return 'SELECT i.id, i.category, i.title, i.creator, i.year, i.image_url, i.created_at,
                   COUNT(r.id) AS n, COALESCE(AVG(r.rating), 0) AS avg, COALESCE(SUM(r.rating), 0) AS total,
                   MAX(m.rating) AS my_rating
            FROM items i
            LEFT JOIN ratings r ON r.item_id = i.id
            LEFT JOIN ratings m ON m.item_id = i.id AND m.user_id = ?';
}

function catalog_get(int $id, ?int $userId): ?array
{
    return q1(item_select_sql() . ' WHERE i.id = ? GROUP BY i.id', [(int)$userId, $id]);
}

/** @return array<int,array> keyed by item id (order not preserved; callers re-order). */
function catalog_items_by_ids(array $ids, ?int $userId): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $out = [];
    foreach (array_chunk($ids, 400) as $chunk) {
        $rows = q(item_select_sql() . ' WHERE i.id IN (' . placeholders(count($chunk)) . ') GROUP BY i.id',
            array_merge([(int)$userId], $chunk));
        foreach ($rows as $r) {
            $out[(int)$r['id']] = $r;
        }
    }
    return $out;
}

/** Bayesian average: a title with one 5-star rating shouldn't beat one with 200 ratings averaging 4.6. */
function bayes_sql(float $g): string
{
    return sprintf('((3 * %.4F + COALESCE(SUM(r.rating), 0)) / (3 + COUNT(r.id)))', $g);
}

function catalog_popular(?string $category, int $limit, ?int $userId, bool $excludeRated = true): array
{
    $where  = [];
    $params = [(int)$userId];
    if ($category) {
        $where[]  = 'i.category = ?';
        $params[] = $category;
    }
    $sql = item_select_sql() . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' GROUP BY i.id';
    if ($excludeRated && $userId) {
        $sql .= ' HAVING MAX(m.rating) IS NULL';
    }
    $sql .= ' ORDER BY ' . bayes_sql(rex_global_mean()) . ' DESC, COUNT(r.id) DESC, i.id DESC LIMIT ?';
    $params[] = $limit;
    return q($sql, $params);
}

/** @return array{0:array,1:int} [rows, total count] */
function catalog_list(?string $category, string $search, string $sort, int $page, ?int $userId): array
{
    $where  = [];
    $wparam = [];
    if ($category) {
        $where[]  = 'i.category = ?';
        $wparam[] = $category;
    }
    $search = trim($search);
    if ($search !== '') {
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
        $where[]  = "(i.title LIKE ? ESCAPE '!' OR i.creator LIKE ? ESCAPE '!')";
        $wparam[] = $like;
        $wparam[] = $like;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $total = (int)(q1('SELECT COUNT(*) AS c FROM items i' . $whereSql, $wparam)['c'] ?? 0);

    $order = match ($sort) {
        'new'   => 'i.id DESC',
        'az'    => 'i.title ASC',
        'top'   => bayes_sql(rex_global_mean()) . ' DESC, COUNT(r.id) DESC',
        default => 'COUNT(r.id) DESC, ' . bayes_sql(rex_global_mean()) . ' DESC',
    };
    $page = max(1, $page);
    $rows = q(
        item_select_sql() . $whereSql . ' GROUP BY i.id ORDER BY ' . $order . ', i.id DESC LIMIT ? OFFSET ?',
        array_merge([(int)$userId], $wparam, [REX_PAGE_SIZE, ($page - 1) * REX_PAGE_SIZE])
    );
    return [$rows, $total];
}

function user_ratings_list(int $userId, ?string $category = null): array
{
    $sql = 'SELECT i.id, i.category, i.title, i.creator, i.year, i.image_url, mine.rating AS my_rating, mine.rated_at,
                   (SELECT COUNT(*) FROM ratings x WHERE x.item_id = i.id) AS n,
                   (SELECT COALESCE(AVG(x.rating), 0) FROM ratings x WHERE x.item_id = i.id) AS avg
            FROM ratings mine JOIN items i ON i.id = mine.item_id
            WHERE mine.user_id = ?';
    $params = [$userId];
    if ($category) {
        $sql .= ' AND i.category = ?';
        $params[] = $category;
    }
    return q($sql . ' ORDER BY mine.rated_at DESC, mine.id DESC', $params);
}

/** Save (1-5) or remove (0) a rating, then refresh that title's similarities. */
function set_rating(int $userId, int $itemId, int $rating): void
{
    if ($rating < 0 || $rating > 5) {
        throw new InvalidArgumentException('Rating must be 0-5');
    }
    if ($rating === 0) {
        qx('DELETE FROM ratings WHERE user_id = ? AND item_id = ?', [$userId, $itemId]);
    } else {
        qx('REPLACE INTO ratings (user_id, item_id, rating) VALUES (?, ?, ?)', [$userId, $itemId, $rating]);
    }
    rec_update_item($itemId);
}
