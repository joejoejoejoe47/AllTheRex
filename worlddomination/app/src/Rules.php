<?php

declare(strict_types=1);

namespace WorldDomination;

/** Shared gameplay constants. Keep in sync with assets/js/game.js. */
final class Rules
{
    public const GRID = 160;
    public const START_RADIUS = 3;
    public const PLAYER_ID = 1;
    public const SPEED = 11.0;
    public const SPEED_SLACK = 1.35;
    public const WIN_PCT = 50.0;
    public const MATCH_MS = 180000;
    public const MAX_HEARTBEATS = 4000;
    public const MAX_CAPTURES = 80;
    public const MAX_DEATHS = 8;
}
