<?php
declare(strict_types=1);

/**
 * Cover images, hotlinked (nothing is stored on this site, only the URL).
 *   movies -> Apple iTunes Search (no key)   albums -> Deezer (no key)   books -> Open Library (no key)
 * Lookups are best-effort. A title with no image just shows the generated colour cover.
 */
const REX_IMG_HOSTS = ['mzstatic.com', 'dzcdn.net', 'covers.openlibrary.org', 'archive.org'];

/** GET a URL, return the body or null. Tests replace this via $GLOBALS['REX_HTTP']. */
function rex_http_get(string $url): ?string
{
    if (isset($GLOBALS['REX_HTTP']) && is_callable($GLOBALS['REX_HTTP'])) {
        return ($GLOBALS['REX_HTTP'])($url);
    }
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
        curl_close($ch);
        return ($body !== false && $code >= 200 && $code < 300) ? (string)$body : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'header' => "User-Agent: $ua\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : $body;
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
            $body = rex_http_get('https://itunes.apple.com/search?' . http_build_query(['term' => $title, 'media' => 'movie', 'entity' => 'movie', 'limit' => 10]));
            foreach ((json_decode((string)$body, true)['results'] ?? []) as $r) {
                $img = (string)($r['artworkUrl100'] ?? '');
                $cands[] = ['t' => (string)($r['trackName'] ?? ''), 'by' => (string)($r['artistName'] ?? ''),
                            'img' => preg_replace('#/\d+x\d+bb\.(jpg|png)$#', '/400x600bb.$1', $img)];
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

/** Look up and save the image for one item. Returns the URL or null. Marks the item as tried either way. */
function rex_fetch_image_for(int $itemId): ?string
{
    $it = q1('SELECT id, category, title, creator, year FROM items WHERE id = ?', [$itemId]);
    if (!$it) {
        return null;
    }
    $url = rex_find_image($it['category'], $it['title'], $it['creator'], $it['year'] ? (int)$it['year'] : null);
    qx('UPDATE items SET image_url = ?, image_tried = 1 WHERE id = ?', [$url, $itemId]);
    return $url;
}

/** Fetch images for items not yet tried, within a time budget. Returns [done this run, still remaining]. */
function rex_backfill_images(float $seconds = 20.0): array
{
    $end = microtime(true) + $seconds;
    $done = 0;
    foreach (q('SELECT id FROM items WHERE image_url IS NULL AND image_tried = 0 ORDER BY id') as $row) {
        if (microtime(true) > $end) {
            break;
        }
        rex_fetch_image_for((int)$row['id']);
        $done++;
    }
    $left = (int)q1('SELECT COUNT(*) AS c FROM items WHERE image_url IS NULL AND image_tried = 0')['c'];
    return [$done, $left];
}
