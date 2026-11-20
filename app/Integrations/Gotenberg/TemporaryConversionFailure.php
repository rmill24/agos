<?php

namespace App\Integrations\Gotenberg;

use RuntimeException;

class TemporaryConversionFailure extends RuntimeException
{
    public readonly int $delay;

    public function __construct(int $status, string $retryAfter)
    {
        parent::__construct("Gotenberg temporarily unavailable (HTTP $status).");
        $seconds = ctype_digit($retryAfter) ? (int) $retryAfter : (strtotime($retryAfter) ?: time() + 1) - time();
        $this->delay = max(0, $seconds);
    }
}
