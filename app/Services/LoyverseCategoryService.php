<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Team;

class LoyverseCategoryService
{
    /** @return array{income: Category, expense: Category} */
    public function resolve(Team $team): array
    {
        $income = $this->default($team, 'Income', 'income');
        $expense = $this->default($team, 'Expense', 'expense');

        return compact('income', 'expense');
    }

    private function default(Team $team, string $name, string $type): Category
    {
        $category = $team->categories()->withTrashed()->where('name', $name)->where('type', $type)->first();
        if ($category) {
            if ($category->trashed()) {
                $category->restore();
            }

            return $category;
        }

        return $team->categories()->create(['name' => $name, 'type' => $type]);
    }
}
