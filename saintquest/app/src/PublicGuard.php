<?php

declare(strict_types=1);

namespace SaintQuest;

/** Paths that must never be served from the public game folder. */
final class PublicGuard
{
    public static function isForbidden(string $path): bool
    {
        $p = strtolower(str_replace('\\', '/', $path));
        $p = '/' . ltrim($p, '/');
        if (str_contains($p, '/app/') || str_ends_with($p, '/app') || $p === '/app') {
            return true;
        }
        if (str_contains($p, '/tests/') || str_ends_with($p, '/tests') || $p === '/tests') {
            return true;
        }
        if (str_ends_with($p, '/install.php') || $p === '/install.php') {
            return true;
        }
        if (preg_match('/\.(env|sqlite|sql)(\.|$)/', $p) === 1) {
            return true;
        }
        if (str_contains($p, '/.')) {
            return true;
        }
        return false;
    }
}
