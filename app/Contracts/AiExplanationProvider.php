<?php

namespace App\Contracts;

use App\Data\RecommendationExplanationContext;

interface AiExplanationProvider
{
    /** Return an untrusted JSON explanation plan, never financial results. */
    public function explain(RecommendationExplanationContext $context, float $timeoutSeconds): string;
}
