<?php

use App\Support\RecommendationPriority;

it('ranks severity before timing before decimal impact with stable ties', function () {
    $base = ['priority' => 'High', 'due_date' => '2026-11-20', 'amount' => '9999999999999.98', 'id' => 'b'];
    expect(RecommendationPriority::compare(['priority' => 'Critical', 'due_date' => '2026-12-20', 'amount' => '0.01', 'id' => 'z'], $base))->toBeLessThan(0)
        ->and(RecommendationPriority::compare(['due_date' => '2026-11-19', 'amount' => '0.01'] + $base, $base))->toBeLessThan(0)
        ->and(RecommendationPriority::compare(['amount' => '9999999999999.99'] + $base, $base))->toBeLessThan(0)
        ->and(RecommendationPriority::compare(['id' => 'a'] + $base, $base))->toBeLessThan(0)
        ->and(RecommendationPriority::compare($base, $base))->toBe(0);
});
