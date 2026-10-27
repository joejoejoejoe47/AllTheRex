<?php
declare(strict_types=1);

/**
 * Cover images, hotlinked (nothing is stored on this site, only the URL).
 *   movies -> Apple iTunes Search (no key)   albums -> Deezer (no key)   books -> Open Library (no key)
 * Lookups are best-effort. A title with no image just shows the generated colour cover.
 */
const REX_IMG_HOSTS = ['mzstatic.com', 'dzcdn.net', 'covers.openlibrary.org', 'archive.org', 'wikimedia.org'];

/** GET a URL, return the body or null. Tests replace this via $GLOBALS['REX_HTTP']. */
function rex_http_get(string $url): ?string
{
    if (isset($GLOBALS['REX_HTTP']) && is_callable($GLOBALS['REX_HTTP'])) {
        $r = ($GLOBALS['REX_HTTP'])($url);
        if ($r === null) { $GLOBALS['REX_HTTP_FAILED'] = true; }
        return $r;
    }
    $t0 = microtime(true);
    $GLOBALS['REX_HTTP_LAST'] = ['url' => $url, 'code' => 0, 'errno' => 0, 'error' => '', 'ms' => 0, 'body' => ''];
    $ua = 'AllTheRex/1.0 (+https://alltherex.com)';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6, CURLOPT_USERAGENT => $ua,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $GLOBALS['REX_HTTP_LAST'] = ['url' => $url, 'code' => $code, 'errno' => curl_errno($ch), 'error' => curl_error($ch),
                                     'ms' => (int)((microtime(true) - $t0) * 1000), 'body' => $body === false ? '' : substr((string)$body, 0, 300)];
        curl_close($ch);
        if ($body !== false && $code >= 200 && $code < 300) {
            return (string)$body;
        }
        $GLOBALS['REX_HTTP_FAILED'] = true;
        return null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'header' => "User-Agent: $ua\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) { $GLOBALS['REX_HTTP_FAILED'] = true; return null; }
    return $body;
}

function rex_norm(string $s): string
{
    $s = mb_strtolower($s);
    $s = preg_replace('/\([^)]*\)|\[[^\]]*\]/u', ' ', $s);          // "(Remastered)", "[Deluxe]"
    return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s));
}

/** 0 = no match, 3 = same title and creator matches, 2 = same title, 1 = loosely related. */
function rex_match_score(string $wantTitle, string $wantBy, string $gotTitle, string $gotBy): int
{
    $wt = rex_norm($wantTitle);
    $gt = rex_norm($gotTitle);
    if ($wt === '' || $gt === '') {
        return 0;
    }
    $byOk = $wantBy === '' || $gotBy === '' || str_contains(rex_norm($gotBy), rex_norm($wantBy)) || str_contains(rex_norm($wantBy), rex_norm($gotBy));
    if ($wt === $gt) {
        return $byOk ? 3 : 2;
    }
    if ((str_contains($gt, $wt) || str_contains($wt, $gt)) && $byOk && $wantBy !== '') {
        return 1;
    }
    return 0;
}

/** Only https images from hosts we expect. Anything else is dropped. */
function rex_clean_image_url(?string $u): ?string
{
    $u = trim((string)$u);
    if ($u === '' || strlen($u) > 500 || !preg_match('#^https://[^\s"\'<>]+$#', $u)) {
        return null;
    }
    $host = strtolower((string)parse_url($u, PHP_URL_HOST));
    foreach (REX_IMG_HOSTS as $ok) {
        if ($host === $ok || str_ends_with($host, '.' . $ok)) {
            return $u;
        }
    }
    return null;
}

/** Looser check for URLs a person pastes in: any https image link. */
function rex_user_image_url(?string $u): ?string
{
    $u = trim((string)$u);
    return ($u !== '' && strlen($u) <= 500 && preg_match('#^https://[^\s"\'<>]+$#', $u) && parse_url($u, PHP_URL_HOST)) ? $u : null;
}

/** @param array<int,array{t:string,by:string,img:string}> $cands */
function rex_pick_best(array $cands, string $title, string $by): ?string
{
    $best = null;
    $bestScore = 0;
    foreach ($cands as $c) {
        $s = rex_match_score($title, $by, $c['t'], $c['by']);
        if ($s > $bestScore && rex_clean_image_url($c['img'])) {
            $best = $c['img'];
            $bestScore = $s;
        }
    }
    return $best;
}

function rex_find_image(string $category, string $title, string $creator = '', ?int $year = null): ?string
{
    try {
        $cands = [];
        if ($category === 'movie') {
            $body = rex_http_get('https://itunes.apple.com/search?' . http_build_query(['term' => $title, 'media' => 'movie', 'entity' => 'movie', 'country' => 'us', 'limit' => 10]));
            foreach ((json_decode((string)$body, true)['results'] ?? []) as $r) {
                $img = (string)($r['artworkUrl100'] ?? '');
                $cands[] = ['t' => (string)($r['trackName'] ?? ''), 'by' => (string)($r['artistName'] ?? ''),
                            'img' => preg_replace('#/\d+x\d+bb\.(jpg|png)$#', '/400x600bb.$1', $img)];
            }
            if (!rex_pick_best($cands, $title, $creator)) {
                return rex_wikipedia_poster($title, $year);
            }
        } elseif ($category === 'album') {
            $body = rex_http_get('https://api.deezer.com/search/album?' . http_build_query(['q' => trim($title . ' ' . $creator), 'limit' => 10]));
            foreach ((json_decode((string)$body, true)['data'] ?? []) as $r) {
                $cands[] = ['t' => (string)($r['title'] ?? ''), 'by' => (string)($r['artist']['name'] ?? ''),
                            'img' => (string)($r['cover_big'] ?? $r['cover_medium'] ?? '')];
            }
        } elseif ($category === 'book') {
            $body = rex_http_get('https://openlibrary.org/search.json?' . http_build_query(['title' => $title, 'author' => $creator, 'limit' => 10, 'fields' => 'title,author_name,cover_i']));
            foreach ((json_decode((string)$body, true)['docs'] ?? []) as $r) {
                if (empty($r['cover_i'])) {
                    continue;
                }
                $cands[] = ['t' => (string)($r['title'] ?? ''), 'by' => implode(' ', (array)($r['author_name'] ?? [])),
                            'img' => 'https://covers.openlibrary.org/b/id/' . (int)$r['cover_i'] . '-L.jpg'];
            }
        }
        return rex_pick_best($cands, $title, $creator);
    } catch (Throwable $e) {
        error_log('[rex] image lookup failed: ' . $e->getMessage());
        return null;
    }
}

/** Find the poster file listed on a film's Wikipedia page and return a 400px thumbnail URL. */
function rex_wikipedia_poster_file(string $page): ?string
{
    $body = rex_http_get('https://en.wikipedia.org/w/api.php?' . http_build_query([
        'action' => 'query', 'format' => 'json', 'titles' => $page, 'prop' => 'images', 'imlimit' => 40,
    ]));
    $files = [];
    foreach ((json_decode((string)$body, true)['query']['pages'] ?? []) as $p) {
        foreach (($p['images'] ?? []) as $im) {
            $files[] = (string)($im['title'] ?? '');
        }
    }
    $file = null;
    foreach ($files as $f) {                       // best: "...poster..."
        if (preg_match('/poster/i', $f) && preg_match('/\.(jpe?g|png)$/i', $f)) { $file = $f; break; }
    }
    if ($file === null) {
        return null;
    }
    $body = rex_http_get('https://en.wikipedia.org/w/api.php?' . http_build_query([
        'action' => 'query', 'format' => 'json', 'titles' => $file, 'prop' => 'imageinfo', 'iiprop' => 'url', 'iiurlwidth' => 400,
    ]));
    foreach ((json_decode((string)$body, true)['query']['pages'] ?? []) as $p) {
        $u = (string)($p['imageinfo'][0]['thumburl'] ?? $p['imageinfo'][0]['url'] ?? '');
        if ($u !== '') {
            return $u;
        }
    }
    return null;
}

/** Movie poster from Wikipedia's lead image (keyless). Used when iTunes has no match or can't be reached. */
function rex_wikipedia_poster(string $title, ?int $year, ?array &$why = null): ?string
{
    $why = [];
    $body = rex_http_get('https://en.wikipedia.org/w/api.php?' . http_build_query([
        'action' => 'query', 'format' => 'json', 'generator' => 'search', 'gsrnamespace' => 0, 'gsrlimit' => 5,
        'gsrsearch' => trim($title . ' ' . ($year ?: '') . ' film'),
        'prop' => 'pageimages|description', 'piprop' => 'thumbnail', 'pithumbsize' => 400,
    ]));
    $pages = (json_decode((string)$body, true)['query']['pages'] ?? []);
    uasort($pages, fn($a, $b) => ($a['index'] ?? 99) <=> ($b['index'] ?? 99));
    foreach ($pages as $p) {
        $desc = mb_strtolower((string)($p['description'] ?? ''));
        $img  = (string)($p['thumbnail']['source'] ?? '');
        $name = (string)($p['title'] ?? '');
        $tag  = "\"$name\" | desc=\"$desc\" | thumb=" . ($img !== '' ? 'yes' : 'NO') . ' => ';
        if (!str_contains($desc, 'film')) { $why[] = $tag . 'rejected: description lacks "film"'; continue; }
        if (rex_norm($name) !== rex_norm($title)) { $why[] = $tag . 'rejected: title differs'; continue; }
        if ($year && !str_contains($desc, (string)$year)) { $why[] = $tag . 'rejected: year not in description'; continue; }
        if ($img === '') {
            $img = (string)rex_wikipedia_poster_file($name);   // film posters are non-free files, so no automatic thumbnail
            if ($img === '') { $why[] = $tag . 'rejected: no thumbnail and no poster file found'; continue; }
        }
        if (!rex_clean_image_url($img)) { $why[] = $tag . 'rejected: image host/URL not allowed (' . $img . ')'; continue; }
        $why[] = $tag . 'ACCEPTED';
        return $img;
    }
    return null;
}

/** Look up and save the image for one item. Returns the URL or null. Marks the item as tried either way. */
function rex_fetch_image_for(int $itemId): ?string
{
    $it = q1('SELECT id, category, title, creator, year FROM items WHERE id = ?', [$itemId]);
    if (!$it) {
        return null;
    }
    $GLOBALS['REX_HTTP_FAILED'] = false;
    $url = rex_find_image($it['category'], $it['title'], $it['creator'], $it['year'] ? (int)$it['year'] : null);
    // If a request failed (timeout/blocked) and we found nothing, leave it untried so a later run can retry.
    $tried = ($url !== null || empty($GLOBALS['REX_HTTP_FAILED'])) ? 1 : 0;
    qx('UPDATE items SET image_url = ?, image_tried = ? WHERE id = ?', [$url, $tried, $itemId]);
    return $url;
}

/** Fetch images for items not yet tried, within a time budget. Returns [done this run, still remaining]. */
function rex_backfill_images(float $seconds = 20.0): array
{
    $end = microtime(true) + $seconds;
    $done = 0;
    $rows = q('SELECT id FROM items WHERE image_url IS NULL AND image_tried = 0 ORDER BY id');
    foreach ($rows as $row) {
        if (microtime(true) > $end) {
            break;
        }
        rex_fetch_image_for((int)$row['id']);
        $done++;
    }
    $left = (int)q1('SELECT COUNT(*) AS c FROM items WHERE image_url IS NULL AND image_tried = 0')['c'];
    return [$done, $left];
}
