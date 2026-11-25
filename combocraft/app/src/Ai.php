<?php

declare(strict_types=1);

namespace ComboCraft;

/**
 * Asks a language model what two elements make. Server side only: keys are
 * read from real environment variables or this app's private app/.env and
 * never sent to the browser. With no key, the free anonymous Pollinations
 * endpoint is used (rate-limited per IP; when it is busy the browser may
 * relay the same request, and the server still validates the answer).
 */
final class Ai
{
    public const POLLINATIONS = 'https://text.pollinations.ai/openai';

    /** @return list<array{role:string,content:string}> */
    public static function messages(string $a, string $b, bool $retry = false): array
    {
        $system = 'You power ComboCraft, a funny "combine anything" browser game like Infinite Craft. '
            . 'Given two elements, invent the ONE thing you get by combining them. Be clever, absurd and internet-funny, '
            . 'but family friendly (PG): no hate, slurs, sexual content, drugs, gore, self-harm, or real people\'s names. '
            . 'Prefer short punchy names: at most 3 words and 24 characters, Title Case. '
            . 'Never pad a name with stacked prefixes like Double, Mega, Super, Ultra, Hyper, Triple, Giga or Squared, and never repeat a word. '
            . 'If the obvious idea would be "Double Mega Money Squared", invent a fresh, creative short name instead. '
            . 'Use real, readable English words (no made-up gibberish words); a pun, a pop-culture-style concept or a funny '
            . 'situation is great. Sometimes the answer is simply the real thing (Water + Fire = Steam). '
            . 'The name must make the connection to BOTH inputs obvious. '
            . 'Reply with ONLY JSON: {"name":"...","emoji":"one single emoji","tagline":"witty line, max 9 words"}. '
            . 'Examples: Cat + Chaos = Zoomies Apocalypse 🐈 "Hide your socks. Hide yourself."; '
            . 'AI + Love = The Perfect Boyfriend Who Never Texts Back 🤖; Money + Chaos = Crypto Black Hole 🕳️; '
            . 'Politician + Meme = Congressional Reaction Video 📺; Cat + Fire = Inferno Feline 😼.';
        $msgs = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $a . ' + ' . $b],
        ];
        if ($retry) {
            $msgs[] = [
                'role' => 'user',
                'content' => 'That name was too long or just stacked prefixes (Double, Mega, Super, Squared). '
                    . 'Invent a fresh, creative name of at most 3 words and 24 characters, with none of those prefixes. '
                    . 'Reply with ONLY JSON.',
            ];
        }
        return $msgs;
    }

    public static function seed(string $pairKey): int
    {
        return (int) (crc32($pairKey) % 100000);
    }

    /** @return array<string,mixed> OpenAI-style body for the free endpoint (also used by the browser relay). */
    public static function pollinationsBody(string $a, string $b, string $pairKey, bool $retry = false): array
    {
        return [
            'model' => 'openai',
            'messages' => self::messages($a, $b, $retry),
            'temperature' => $retry ? 1.05 : 0.9,
            'seed' => self::seed($pairKey) + ($retry ? 1 : 0),
            'reasoning_effort' => 'low',
            'response_format' => ['type' => 'json_object'],
            'referrer' => 'alltherex.com',
            'private' => true,
        ];
    }

    /**
     * @return array{status:string, data?:array{name:string,emoji:?string,tagline:string}, provider?:string}
     *   status: ok | busy | failed
     */
    public static function ask(string $a, string $b, string $pairKey): array
    {
        foreach (self::keyedProviders() as $p) {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $retry = $attempt === 1;
                $body = [
                    'model' => $p['model'],
                    'messages' => self::messages($a, $b, $retry),
                    'temperature' => $retry ? 1.05 : 0.9,
                    'max_tokens' => 120,
                ];
                if ($p['json']) {
                    $body['response_format'] = ['type' => 'json_object'];
                }
                $res = self::post($p['url'], $body, ['Authorization: Bearer ' . $p['key']], 15);
                if ($res['code'] === 200) {
                    $parsed = self::parse((string) $res['body']);
                    if ($parsed !== null) {
                        return ['status' => 'ok', 'data' => $parsed, 'provider' => $p['name']];
                    }
                    // Bad or puffed name — ask this provider once more for a short fresh one.
                    continue;
                }
                error_log('[combocraft ai] ' . $p['name'] . ' http ' . $res['code']);
                break;
            }
        }

        if (Config::bool('CC_FREE_AI_DISABLED')) {
            return ['status' => 'failed'];
        }
        $until = (int) (Store::get('pollinations_until') ?? '0');
        if ($until > time()) {
            return ['status' => 'busy'];
        }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $res = self::post(self::POLLINATIONS, self::pollinationsBody($a, $b, $pairKey, $attempt === 1), [], 18);
            if ($res['code'] === 200) {
                $parsed = self::parse((string) $res['body']);
                if ($parsed !== null) {
                    return ['status' => 'ok', 'data' => $parsed, 'provider' => 'pollinations'];
                }
                continue;
            }
            break;
        }
        if (($res['code'] ?? 0) === 200) {
            return ['status' => 'failed'];
        }
        if (in_array($res['code'], [402, 429, 503, 0], true)) {
            $retry = max(10, min(120, (int) ($res['retry'] ?? 45)));
            Store::set('pollinations_until', (string) (time() + $retry));
            return ['status' => 'busy'];
        }
        return ['status' => 'failed'];
    }

    /** @return list<array{name:string,url:string,key:string,model:string,json:bool}> */
    private static function keyedProviders(): array
    {
        $out = [];
        $defs = [
            ['CC_GROQ_API_KEY', 'groq', 'https://api.groq.com/openai/v1/chat/completions', 'llama-3.1-8b-instant', true],
            ['CC_OPENROUTER_API_KEY', 'openrouter', 'https://openrouter.ai/api/v1/chat/completions', 'meta-llama/llama-3.1-8b-instruct:free', false],
            ['CC_POLLINATIONS_KEY', 'pollinations-key', 'https://gen.pollinations.ai/v1/chat/completions', 'openai', true],
            ['CC_XAI_API_KEY', 'xai', 'https://api.x.ai/v1/chat/completions', 'grok-3-mini', true],
            ['CC_OPENAI_API_KEY', 'openai', 'https://api.openai.com/v1/chat/completions', 'gpt-4o-mini', true],
        ];
        foreach ($defs as [$env, $name, $url, $model, $json]) {
            $key = Config::get($env);
            if ($key !== null && $key !== '') {
                $out[] = ['name' => $name, 'url' => $url, 'key' => $key, 'model' => Config::get($env . '_MODEL', $model) ?? $model, 'json' => $json];
            }
        }
        return $out;
    }

    /** @return array{name:string,emoji:?string,tagline:string}|null */
    public static function parse(string $raw): ?array
    {
        $content = $raw;
        $j = json_decode($raw, true);
        if (is_array($j) && isset($j['choices'][0]['message']['content'])) {
            $content = (string) $j['choices'][0]['message']['content'];
        } elseif (is_array($j) && isset($j['name'])) {
            $content = $raw;
        }
        if (!preg_match('/\{.*\}/su', $content, $m)) {
            return null;
        }
        $obj = json_decode($m[0], true);
        if (!is_array($obj) || !isset($obj['name']) || !is_string($obj['name'])) {
            return null;
        }
        $name = Text::cleanName($obj['name']);
        if ($name === null) {
            return null;
        }
        $tagline = Text::cleanTagline(isset($obj['tagline']) && is_string($obj['tagline']) ? $obj['tagline'] : '');
        $emoji = isset($obj['emoji']) && is_string($obj['emoji']) ? Text::pickEmoji($obj['emoji']) : null;
        if (!Text::isSafe($name, $tagline)) {
            return null;
        }
        return ['name' => $name, 'emoji' => $emoji, 'tagline' => $tagline];
    }

    /**
     * @param array<string,mixed> $body
     * @param list<string> $headers
     * @return array{code:int, body:?string, retry:?int}
     */
    public static function post(string $url, array $body, array $headers, int $timeout): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = array_merge(['Content-Type: application/json', 'Accept: application/json', 'User-Agent: ComboCraft/1.0 (+https://alltherex.com/combocraft/)', 'Referer: https://alltherex.com/combocraft/'], $headers);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $retry = null;
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retry): int {
                    if (stripos($line, 'retry-after:') === 0) {
                        $retry = (int) trim(substr($line, 12));
                    }
                    return strlen($line);
                },
            ]);
            $out = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return ['code' => $out === false ? 0 : $code, 'body' => $out === false ? null : (string) $out, 'retry' => $retry];
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $payload,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);
        $out = @file_get_contents($url, false, $ctx);
        $code = 0;
        $retry = null;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $mm)) {
                $code = (int) $mm[1];
            } elseif (stripos($h, 'retry-after:') === 0) {
                $retry = (int) trim(substr($h, 12));
            }
        }
        return ['code' => $out === false ? 0 : $code, 'body' => $out === false ? null : $out, 'retry' => $retry];
    }
}
