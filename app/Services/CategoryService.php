<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CategoryService
{
    public function __construct(private AuditEventService $auditEvents)
    {
        //
    }

    /**
     * @param  array{name: string, type: string}  $attributes
     */
    public function create(User $actor, Team $team, array $attributes): Category
    {
        Gate::forUser($actor)->authorize('create', [Category::class, $team]);
        $this->validateAttributes($attributes);

        $category = $team->categories()->create($attributes);

        $this->auditEvents->record($actor, $team, 'category.created', $category, after: [
            'name' => $category->name,
            'type' => $category->type,
        ]);

        return $category;
    }

    /**
     * @param  array{name: string, type: string}  $attributes
     */
    public function update(User $actor, Team $team, Category $category, array $attributes): Category
    {
        Gate::forUser($actor)->authorize('update', $category);
        abort_unless($category->belongsToTeam($team), 403);
        $this->validateAttributes($attributes);

        $before = [
            'name' => $category->name,
            'type' => $category->type,
        ];
        $category->update($attributes);

        $this->auditEvents->record($actor, $team, 'category.updated', $category, before: $before, after: [
            'name' => $category->name,
            'type' => $category->type,
        ]);

        return $category->fresh();
    }

    public function archive(User $actor, Team $team, Category $category): void
    {
        Gate::forUser($actor)->authorize('delete', $category);
        abort_unless($category->belongsToTeam($team), 403);

        $before = [
            'name' => $category->name,
            'type' => $category->type,
        ];
        $category->delete();

        $this->auditEvents->record($actor, $team, 'category.archived', $category, before: $before, after: [
            'deleted' => true,
        ]);
    }

    /**
     * @param  array{name?: string, type?: string}  $attributes
     */
    protected function validateAttributes(array $attributes): void
    {
        if (trim($attributes['name'] ?? '') === '' || ! in_array($attributes['type'] ?? null, ['income', 'expense', 'both'], true)) {
            throw ValidationException::withMessages([
                'name' => 'A category name and valid category type are required.',
            ]);
        }
    }
}
