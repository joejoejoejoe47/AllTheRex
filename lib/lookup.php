<?php
declare(strict_types=1);

/**
 * Search public databases for titles to add (no API keys):
 *   movies -> Wikipedia (film articles)   albums -> Deezer   books -> Open Library
 * Returns candidates: [category, title, creator, year, image, existing_id].
 */
function rex_external_search(string $category, string $q, int $limit = 8): array
{
    $q = clean_text($q, 80);
    $res = ['results' => [], 'failed' => false];
    if (mb_strlen($q) < 2 || !valid_category($category)) {
        return $res;
    }
    $GLOBALS['REX_HTTP_FAILED'] = false;
    try {
        $found = match ($category) {
            'movie' => rex_ext_movies($q),
            'album' => rex_ext_albums($q),
            'book'  => rex_ext_books($q),
        };
    } catch (Throwable $e) {
        error_log('[rex] external search failed: ' . $e->getMessage());
        $found = [];
        $GLOBALS['REX_HTTP_FAILED'] = true;
    }
    $res['failed'] = !empty($GLOBALS['REX_HTTP_FAILED']) && !$found;
    $seen = [];
    foreach ($found as $c) {
        $k = rex_norm($c['title']) . '|' . rex_norm($c['creator']) . '|' . $c['year'];
        if (isset($seen[$k]) || $c['title'] === '') {
            continue;
        }
        $seen[$k] = true;
        $c['category']    = $category;
        $c['image']       = rex_clean_image_url($c['image'] ?? '') ?? '';
        $c['existing_id'] = catalog_find_existing($category, $c['title'], $c['creator'], $c['year']);
        $res['results'][] = $c;
        if (count($res['results']) >= $limit) {
            break;
        }
    }
    return $res;
}

function rex_ext_movies(string $q): array
{
    $body = rex_http_get('https://en.wikipedia.org/w/api.php?' . http_build_query([
        'action' => 'query', 'format' => 'json', 'generator' => 'search', 'gsrnamespace' => 0,
        'gsrlimit' => 15, 'gsrsearch' => $q . ' film', 'prop' => 'description',
    ]));
    $pages = (json_decode((string)$body, true)['query']['pages'] ?? []);
    uasort($pages, fn($a, $b) => ($a['index'] ?? 99) <=> ($b['index'] ?? 99));
    $out = [];
    foreach ($pages as $p) {
        $desc = (string)($p['description'] ?? '');
        $name = (string)($p['title'] ?? '');
        // must be a single film, not a person, series, studio or list
        if (!preg_match('/\bfilm\b/i', $desc) || preg_match('/\b(series|franchise|trilogy|studio|company|festival|award|list|soundtrack|album|filmmaker|actor|actress|director)\b/i', $desc)) {
            continue;
        }
        $year = null;
        if (preg_match('/^(\d{4})\b/', $desc, $m) || preg_match('/\((\d{4}) [^)]*film\)/i', $name, $m)) {
            $year = (int)$m[1];
        }
        $by = '';
        if (preg_match('/\bfilm (?:directed )?by ([^,;(]+?)(?:\s+and starring|\s+starring|$)/iu', $desc, $m)) {
            $by = trim($m[1]);
        }
        $out[] = [
            'title'   => trim(preg_replace('/\s*\((?:\d{4} )?(?:[\p{L}\-]+ ){0,3}film\)$/iu', '', $name)),
            'creator' => $by,
            'year'    => $year,
            'image'   => '',     // poster is looked up when the title is added
        ];
    }
    return $out;
}

function rex_ext_albums(string $q): array
{
    $body = rex_http_get('https://api.deezer.com/search/album?' . http_build_query(['q' => $q, 'limit' => 12]));
    $out = [];
    foreach ((json_decode((string)$body, true)['data'] ?? []) as $r) {
        $out[] = [
            'title'   => trim((string)($r['title'] ?? '')),
            'creator' => trim((string)($r['artist']['name'] ?? '')),
            'year'    => null,
            'image'   => (string)($r['cover_big'] ?? $r['cover_medium'] ?? ''),
        ];
    }
    return $out;
}

function rex_ext_books(string $q): array
{
    $body = rex_http_get('https://openlibrary.org/search.json?' . http_build_query([
        'q' => $q, 'limit' => 12, 'fields' => 'title,author_name,first_publish_year,cover_i',
    ]));
    $out = [];
    foreach ((json_decode((string)$body, true)['docs'] ?? []) as $r) {
        $out[] = [
            'title'   => trim((string)($r['title'] ?? '')),
            'creator' => trim((string)(($r['author_name'] ?? [''])[0] ?? '')),
            'year'    => isset($r['first_publish_year']) ? (int)$r['first_publish_year'] : null,
            'image'   => !empty($r['cover_i']) ? 'https://covers.openlibrary.org/b/id/' . (int)$r['cover_i'] . '-L.jpg' : '',
        ];
    }
    return $out;
}
