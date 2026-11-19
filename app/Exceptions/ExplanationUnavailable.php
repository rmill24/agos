<?php

namespace App\Exceptions;

use RuntimeException;

final class ExplanationUnavailable extends RuntimeException
{
    // Never attach the provider exception, credentials, request or response.
    public function __construct(public readonly string $reason)
    {
        parent::__construct('AI explanation unavailable.');
    }
}
