<?php

declare(strict_types=1);

namespace WorldDomination;

use RuntimeException;

final class HttpError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 400,
    ) {
        parent::__construct($message, $status);
    }
}
