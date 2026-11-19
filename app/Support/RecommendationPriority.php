<?php

namespace App\Support;

use Brick\Math\BigDecimal;

final class RecommendationPriority
{
    /**
     * Severity first, earliest date next, then largest supported exposure.
     * Amounts describe exposure, never a promised cash benefit.
     *
     * @param  array{priority: string, due_date: string, amount: string, id: string}  $left
     * @param  array{priority: string, due_date: string, amount: string, id: string}  $right
     */
    public static function compare(array $left, array $right): int
    {
        $ranks = ['Critical' => 0, 'High' => 1, 'Normal' => 2];

        return ($ranks[$left['priority']] <=> $ranks[$right['priority']])
            ?: strcmp($left['due_date'], $right['due_date'])
            ?: BigDecimal::of($right['amount'])->compareTo($left['amount'])
            ?: strcmp($left['id'], $right['id']);
    }
}
