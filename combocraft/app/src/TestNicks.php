<?php

declare(strict_types=1);

namespace ComboCraft;

/**
 * Agent test display names (CcMorse, CcHeat, CcBot, …) stay off public boards.
 */
final class TestNicks
{
    /**
     * Cc + a capital letter, plus the historical explicit prefixes
     * (CcBot, CcFin, CcQA, CcTest, CcAi, CcAud, CcHide, CcPty, CcDay, …).
     */
    public const PATTERN = '/^Cc[A-Z]/';

    public static function isHidden(?string $name): bool
    {
        $n = trim((string) $name);
        return $n !== '' && (bool) preg_match(self::PATTERN, $n);
    }
}
