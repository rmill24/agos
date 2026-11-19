<?php

use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Team;
use App\Models\Transaction;
use App\Services\TransactionService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Transactions')] class extends Component
{
    use WithPagination;

    public Team $team;

    public string $search = '';

    public string $directionFilter = 'all';

    public string $categoryFilter = 'all';

    public string $dateFrom = '';

    public string $dateTo = '';

    public ?int $editingId = null;

    public ?int $selectedId = null;

    public string $direction = 'income';

    public string $amount = '';

    public string $transactionDate = '';

    public string $description = '';

    public $categoryId = null;

    public string $sourceReference = '';

    public function mount(Team $current_team): void
    {
        $this->team = $current_team;
        $this->transactionDate = now()->toDateString();
        $this->authorizeTeamView();
    }

    public function startCreate(): void
    {
        $this->authorizeTeamView();
        Gate::authorize('create', [Transaction::class, $this->team]);
        $this->resetForm();
    }

    public function editTransaction(int $transactionId): void
    {
        $this->authorizeTeamView();
        $transaction = Transaction::query()->findOrFail($transactionId);
        Gate::authorize('update', $transaction);

        $this->editingId = $transaction->id;
        $this->direction = $transaction->direction;
        $this->amount = (string) $transaction->amount;
        $this->transactionDate = $transaction->transaction_date->toDateString();
        $this->description = $transaction->description;
        $this->categoryId = $transaction->category_id;
        $this->sourceReference = $transaction->source_reference ?? '';
    }

    public function viewTransaction(int $transactionId): void
    {
        $this->authorizeTeamView();
        $transaction = Transaction::query()->findOrFail($transactionId);
        Gate::authorize('view', $transaction);
        $this->selectedId = $transaction->id;
    }

    public function saveTransaction(TransactionService $transactions): void
    {
        $this->authorizeTeamView();

        $validated = $this->validate([
            'direction' => ['required', Rule::in(['income', 'expense'])],
            'amount' => ['required', 'string', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
            'transactionDate' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:2000'],
            'categoryId' => ['nullable', 'integer'],
            'sourceReference' => ['nullable', 'string', 'max:255'],
        ]);

        $attributes = [
            'direction' => $validated['direction'],
            'amount' => $validated['amount'],
            'transaction_date' => $validated['transactionDate'],
            'description' => $validated['description'],
            'category_id' => $validated['categoryId'],
            'source_type' => 'manual',
            'source_reference' => $validated['sourceReference'] ?: null,
        ];

        if ($this->editingId === null) {
            Gate::authorize('create', [Transaction::class, $this->team]);
            $transactions->create(Auth::user(), $this->team, $attributes);
            $message = __('Transaction created.');
        } else {
            $transaction = Transaction::query()->findOrFail($this->editingId);
            Gate::authorize('update', $transaction);
            $transactions->update(Auth::user(), $this->team, $transaction, $attributes);
            $message = __('Transaction updated.');
        }

        $this->resetForm();
        Flux::toast(variant: 'success', text: $message);
    }

    public function archiveTransaction(int $transactionId, TransactionService $transactions): void
    {
        $this->authorizeTeamView();
        $transaction = Transaction::query()->findOrFail($transactionId);
        Gate::authorize('delete', $transaction);
        $transactions->archive(Auth::user(), $this->team, $transaction);
        $this->resetPage('transactionsPage');

        if ($this->selectedId === $transactionId) {
            $this->selectedId = null;
        }
        if ($this->editingId === $transactionId) {
            $this->resetForm();
        }

        Flux::toast(variant: 'success', text: __('Transaction archived.'));
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'directionFilter', 'categoryFilter', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage('transactionsPage');
        }
    }

    public function clearFilters(): void
    {
        $this->authorizeTeamView();
        $this->resetPage('transactionsPage');
        $this->reset('search', 'dateFrom', 'dateTo');
        $this->directionFilter = 'all';
        $this->categoryFilter = 'all';
    }

    #[Computed]
    public function transactions(): iterable
    {
        $this->authorizeTeamView();

        return $this->filteredTransactions()->paginate(10, pageName: 'transactionsPage');
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorizeTeamView();
        Gate::authorize('manageFinancialRecords', $this->team);
        $transactions = $this->filteredTransactions();

        return response()->streamDownload(function () use ($transactions) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['date', 'type', 'amount', 'currency', 'description', 'source_reference', 'category', 'payment_status', 'external_id'], ',', '"', '');
            foreach ($transactions->lazy() as $transaction) {
                $cells = [
                    $transaction->transaction_date->toDateString(), $transaction->direction,
                    $transaction->amount, $transaction->currency_code, $transaction->description,
                    $transaction->source_reference, $transaction->category?->name, 'paid', $transaction->external_id,
                ];
                foreach ($cells as &$cell) {
                    if (preg_match('/^(?:[\t\r\n]|\s*[=+@-])/u', (string) $cell)) {
                        $cell = "'".$cell;
                    }
                }
                unset($cell);
                fputcsv($out, $cells, ',', '"', '');
            }
            fclose($out);
        }, 'transactions.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filteredTransactions(): \Illuminate\Database\Eloquent\Builder
    {
        return Transaction::forTeam($this->team)
            ->with('category')
            ->when($this->search !== '', fn ($query) => $query->where(function ($query) {
                $search = '%'.mb_strtolower($this->search, 'UTF-8').'%';

                $query->whereRaw('LOWER(description) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(source_reference) LIKE ?', [$search]);
            }))
            ->when($this->directionFilter !== 'all', fn ($query) => $query->where('direction', $this->directionFilter))
            ->when($this->categoryFilter !== 'all', fn ($query) => $query->where('category_id', (int) $this->categoryFilter))
            ->when($this->dateFrom !== '', fn ($query) => $query->whereDate('transaction_date', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($query) => $query->whereDate('transaction_date', '<=', $this->dateTo))
            ->latest('transaction_date')
            ->latest('id');
    }

    #[Computed]
    public function categories(): iterable
    {
        $this->authorizeTeamView();
        Gate::authorize('viewAny', [Category::class, $this->team]);

        return Category::forTeam($this->team)->orderBy('name')->get();
    }

    #[Computed]
    public function selectedTransaction(): ?Transaction
    {
        if ($this->selectedId === null) {
            return null;
        }

        $this->authorizeTeamView();
        $transaction = Transaction::query()->findOrFail($this->selectedId);
        Gate::authorize('view', $transaction);

        return $transaction->load('category', 'recordedBy');
    }

    #[Computed]
    public function selectedAuditEvents(): iterable
    {
        $transaction = $this->selectedTransaction;

        if ($transaction === null || ! Auth::user()->ownsTeam($this->team)) {
            return [];
        }

        Gate::authorize('viewAny', [AuditEvent::class, $this->team]);

        return AuditEvent::forTeam($this->team)
            ->where('auditable_type', Transaction::class)
            ->where('auditable_id', $transaction->id)
            ->latest('occurred_at')
            ->get();
    }

    protected function authorizeTeamView(): void
    {
        $this->team = Team::query()->findOrFail($this->team->id);
        Gate::authorize('viewAny', [Transaction::class, $this->team]);
    }

    public function resetForm(): void
    {
        $this->authorizeTeamView();
        $this->reset('editingId', 'amount', 'description', 'categoryId', 'sourceReference');
        $this->direction = 'income';
        $this->transactionDate = now()->toDateString();
    }
}; ?>

<section class="w-full lg:mx-auto lg:max-w-7xl lg:p-8">
    <div class="space-y-6">
        @if(\App\Models\ImportBatch::where('team_id',$team->id)->whereNotNull('committed_at')->where('incomplete_history',true)->exists())
            <p class="rounded-lg border p-3"><strong>Incomplete history</strong> · Imported movements cover a limited period. Opening cash is unknown.</p>
        @endif
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Transactions') }}</flux:heading>
                <flux:subheading>{{ __('Record and review this business\'s income and expenses.') }}</flux:subheading>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @can('manageFinancialRecords', $team)
                    <livewire:pages::transactions.csv-import :current_team="$team" @transactions-imported="$refresh" />
                    <flux:button wire:click="exportCsv">Export CSV</flux:button>
                @endcan
                <flux:button variant="primary" wire:click="startCreate">{{ __('New transaction') }}</flux:button>
            </div>
        </div>

        <div class="grid gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700 md:grid-cols-5">
            <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" placeholder="Description or reference" />
            <flux:select wire:model.live="directionFilter" :label="__('Direction')">
                <flux:select.option value="all">{{ __('All') }}</flux:select.option>
                <flux:select.option value="income">{{ __('Income') }}</flux:select.option>
                <flux:select.option value="expense">{{ __('Expense') }}</flux:select.option>
            </flux:select>
            <flux:select wire:model.live="categoryFilter" :label="__('Category')">
                <flux:select.option value="all">{{ __('All') }}</flux:select.option>
                @foreach ($this->categories as $category)
                    <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model.live="dateFrom" type="date" :label="__('From')" />
            <div class="flex items-end gap-2">
                <flux:input wire:model.live="dateTo" type="date" :label="__('To')" />
                <flux:button variant="ghost" wire:click="clearFilters">{{ __('Clear') }}</flux:button>
            </div>
        </div>

        <form wire:submit="saveTransaction" class="grid gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700 md:grid-cols-4">
            <flux:select wire:model="direction" :label="__('Direction')" required>
                <flux:select.option value="income">{{ __('Income') }}</flux:select.option>
                <flux:select.option value="expense">{{ __('Expense') }}</flux:select.option>
            </flux:select>
            <flux:input wire:model="amount" :label="__('Amount (PHP)')" inputmode="decimal" required />
            <flux:input wire:model="transactionDate" type="date" :label="__('Date')" required />
            <flux:select wire:model="categoryId" :label="__('Category')">
                <flux:select.option value="">{{ __('Uncategorized') }}</flux:select.option>
                @foreach ($this->categories as $category)
                    <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="md:col-span-2"><flux:input wire:model="description" :label="__('Description')" required /></div>
            <flux:input wire:model="sourceReference" :label="__('Reference (optional)')" />
            <div class="flex items-end gap-2">
                <flux:button type="submit" variant="primary">{{ $editingId ? __('Update transaction') : __('Save transaction') }}</flux:button>
                @if ($editingId)
                    <flux:button type="button" wire:click="resetForm">{{ __('Cancel') }}</flux:button>
                @endif
            </div>
        </form>

        <div class="agos-table-panel">
            <table class="min-w-full">
                <thead>
                    <tr>
                        <th class="px-4 py-3 text-left text-sm font-medium">{{ __('Date') }}</th>
                        <th class="px-4 py-3 text-left text-sm font-medium">{{ __('Description') }}</th>
                        <th class="px-4 py-3 text-left text-sm font-medium">{{ __('Category') }}</th>
                        <th class="px-4 py-3 text-right text-sm font-medium">{{ __('Amount') }}</th>
                        <th class="px-4 py-3 text-right text-sm font-medium">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->transactions as $transaction)
                        <tr>
                            <td class="px-4 py-3">{{ $transaction->transaction_date->toDateString() }}</td>
                            <td class="px-4 py-3">{{ $transaction->description }}</td>
                            <td class="px-4 py-3">{{ $transaction->category?->name ?? __('Uncategorized') }}</td>
                            <td class="px-4 py-3 text-right {{ $transaction->direction === 'expense' ? 'text-red-600 dark:text-red-300' : 'text-emerald-600 dark:text-emerald-300' }}">
                                {{ $transaction->direction === 'expense' ? '-' : '+' }}₱{{ $transaction->amount }}
                            </td>
                            <td class="space-x-2 px-4 py-3 text-right">
                                <flux:button size="sm" variant="outline" wire:click="viewTransaction({{ $transaction->id }})">{{ __('Details') }}</flux:button>
                                @can('update', $transaction)
                                <flux:button size="sm" variant="outline" wire:click="editTransaction({{ $transaction->id }})">{{ __('Edit') }}</flux:button>
                                <flux:button size="sm" variant="danger" wire:click="archiveTransaction({{ $transaction->id }})">{{ __('Archive') }}</flux:button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">{{ __('No transactions found.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            <x-table-pagination :records="$this->transactions" />
        </div>

        @if ($this->selectedTransaction)
            <div class="space-y-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <div class="flex items-center justify-between">
                    <flux:heading>{{ __('Transaction details') }}</flux:heading>
                    <flux:button variant="ghost" wire:click="$set('selectedId', null)">{{ __('Close') }}</flux:button>
                </div>
                <dl class="grid gap-3 text-sm md:grid-cols-3">
                    <div><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Direction') }}</dt><dd>{{ ucfirst($this->selectedTransaction->direction) }}</dd></div>
                    <div><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Amount') }}</dt><dd>₱{{ $this->selectedTransaction->amount }}</dd></div>
                    <div><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Recorded by') }}</dt><dd>{{ $this->selectedTransaction->recordedBy?->name ?? __('Deleted user') }}</dd></div>
                    <div class="md:col-span-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Description') }}</dt><dd>{{ $this->selectedTransaction->description }}</dd></div>
                </dl>
                @if (Auth::user()->ownsTeam($team))
                    <div>
                        <flux:heading size="sm">{{ __('Audit history') }}</flux:heading>
                        <ul class="mt-2 space-y-2 text-sm">
                            @foreach ($this->selectedAuditEvents as $event)
                                <li class="rounded border border-zinc-200 p-2 dark:border-zinc-700">
                                    {{ $event->event_type }} · {{ $event->occurred_at->toISOString() }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif
    </div>
</section>
