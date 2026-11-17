<?php

use App\Models\Category;
use App\Models\Team;
use App\Services\CategoryService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Categories')] class extends Component
{
    public Team $team;

    public string $name = '';

    public string $type = 'both';

    public ?int $editingId = null;

    public function mount(Team $current_team): void
    {
        $this->team = $current_team;
        $this->authorizeTeamView();
    }

    public function startCreate(): void
    {
        $this->authorizeTeamView();
        Gate::authorize('create', [Category::class, $this->team]);
        $this->resetForm();
    }

    public function editCategory(int $categoryId): void
    {
        $this->authorizeTeamView();
        $category = Category::query()->findOrFail($categoryId);
        Gate::authorize('update', $category);

        $this->editingId = $category->id;
        $this->name = $category->name;
        $this->type = $category->type;
    }

    public function saveCategory(CategoryService $categories): void
    {
        $this->authorizeTeamView();

        $validated = $this->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'name')
                    ->where(fn ($query) => $query->where('team_id', $this->team->id)->where('type', $this->type))
                    ->ignore($this->editingId),
            ],
            'type' => ['required', 'string', Rule::in(['income', 'expense', 'both'])],
        ]);

        if ($this->editingId === null) {
            Gate::authorize('create', [Category::class, $this->team]);
            $categories->create(Auth::user(), $this->team, $validated);
            $message = __('Category created.');
        } else {
            $category = Category::query()->findOrFail($this->editingId);
            Gate::authorize('update', $category);
            $categories->update(Auth::user(), $this->team, $category, $validated);
            $message = __('Category updated.');
        }

        $this->resetForm();
        Flux::toast(variant: 'success', text: $message);
    }

    public function archiveCategory(int $categoryId, CategoryService $categories): void
    {
        $this->authorizeTeamView();
        $category = Category::query()->findOrFail($categoryId);
        Gate::authorize('delete', $category);
        $categories->archive(Auth::user(), $this->team, $category);

        if ($this->editingId === $categoryId) {
            $this->resetForm();
        }

        Flux::toast(variant: 'success', text: __('Category archived.'));
    }

    #[Computed]
    public function categories(): iterable
    {
        $this->authorizeTeamView();

        return Category::forTeam($this->team)
            ->orderBy('name')
            ->get();
    }

    protected function authorizeTeamView(): void
    {
        $this->team = Team::query()->findOrFail($this->team->id);
        Gate::authorize('viewAny', [Category::class, $this->team]);
    }

    public function resetForm(): void
    {
        $this->authorizeTeamView();
        $this->reset('name', 'editingId');
        $this->type = 'both';
    }
}; ?>

<section class="w-full">
    <div class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Categories') }}</flux:heading>
                <flux:subheading>{{ __('Configure categories for this business.') }}</flux:subheading>
            </div>
            <flux:button variant="primary" wire:click="startCreate">{{ __('New category') }}</flux:button>
        </div>

        <form wire:submit="saveCategory" class="grid gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700 md:grid-cols-[1fr_12rem_auto]">
            <flux:input wire:model="name" :label="__('Category name')" required />
            <flux:select wire:model="type" :label="__('Applies to')">
                <flux:select.option value="both">{{ __('Income and expense') }}</flux:select.option>
                <flux:select.option value="income">{{ __('Income') }}</flux:select.option>
                <flux:select.option value="expense">{{ __('Expense') }}</flux:select.option>
            </flux:select>
            <div class="flex items-end gap-2">
                <flux:button type="submit" variant="primary">{{ $editingId ? __('Update') : __('Add') }}</flux:button>
                @if ($editingId)
                    <flux:button type="button" wire:click="resetForm">{{ __('Cancel') }}</flux:button>
                @endif
            </div>
        </form>

        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-sm font-medium">{{ __('Name') }}</th>
                        <th class="px-4 py-3 text-left text-sm font-medium">{{ __('Applies to') }}</th>
                        <th class="px-4 py-3 text-right text-sm font-medium">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($this->categories as $category)
                        <tr>
                            <td class="px-4 py-3">{{ $category->name }}</td>
                            <td class="px-4 py-3">{{ ucfirst($category->type) }}</td>
                            <td class="space-x-2 px-4 py-3 text-right">
                                <flux:button size="sm" variant="outline" wire:click="editCategory({{ $category->id }})">{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" variant="danger" wire:click="archiveCategory({{ $category->id }})">{{ __('Archive') }}</flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-8 text-center text-zinc-500">{{ __('No categories yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
