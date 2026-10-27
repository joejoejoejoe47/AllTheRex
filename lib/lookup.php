<?php
declare(strict_types=1);

/**
 * Search public databases for titles to add (no API keys):
 *   movies -> Wikipedia (film articles)   albums -> MusicBrainz + Deezer   books -> Open Library
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
        $k = rex_norm($c['title']) . '|' . rex_norm($c['creator']) . ($category === 'album' ? '' : '|' . $c['year']);
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
    $mb = rex_ext_albums_musicbrainz($q);
    $dz = rex_ext_albums_deezer($q);

    // Prefer MusicBrainz entries (complete catalogue, release year); borrow Deezer's cover when it has the same album.
    $out = [];
    $usedDz = [];
    foreach ($mb as $m) {
        foreach ($dz as $i => $d) {
            if (isset($usedDz[$i])) {
                continue;
            }
            if (rex_norm($d['title']) === rex_norm($m['title']) && (str_contains(rex_norm($d['creator']), rex_norm($m['creator'])) || str_contains(rex_norm($m['creator']), rex_norm($d['creator'])))) {
                if ($d['image'] !== '') {
                    $m['image'] = $d['image'];
                }
                $usedDz[$i] = true;
                break;
            }
        }
        $out[] = $m;
    }
    foreach ($dz as $i => $d) {
        if (!isset($usedDz[$i])) {
            $out[] = $d;
        }
    }
    return $out;
}

/** MusicBrainz release groups. Terms are OR-ed so "van dyke parks discover america" (artist + title) still matches. */
function rex_ext_albums_musicbrainz(string $q): array
{
    $terms = array_filter(preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $q)) ?: []);
    $terms = array_slice(array_values($terms), 0, 8);
    if (!$terms) {
        return [];
    }
    $or  = implode(' OR ', $terms);
    $lq  = "(releasegroup:($or) OR artist:($or)) AND primarytype:album";
    $body = rex_http_get('https://musicbrainz.org/ws/2/release-group?' . http_build_query(['query' => $lq, 'fmt' => 'json', 'limit' => 15]));
    $out = [];
    foreach ((json_decode((string)$body, true)['release-groups'] ?? []) as $r) {
        if (!empty($r['secondary-types'])) {          // skip compilations, live albums, soundtracks, remixes
            continue;
        }
        $title = trim((string)($r['title'] ?? ''));
        $by = trim((string)($r['artist-credit'][0]['name'] ?? ''));
        $id = (string)($r['id'] ?? '');
        if ($title === '' || !preg_match('/^[0-9a-f\-]{36}$/i', $id)) {
            continue;
        }
        $out[] = [
            'title'   => $title,
            'creator' => $by,
            'year'    => preg_match('/^(\d{4})/', (string)($r['first-release-date'] ?? ''), $m) ? (int)$m[1] : null,
            'image'   => 'https://coverartarchive.org/release-group/' . $id . '/front-250',
        ];
    }
    return $out;
}

function rex_ext_albums_deezer(string $q): array
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
