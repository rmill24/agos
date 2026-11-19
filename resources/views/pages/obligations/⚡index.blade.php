<?php

use App\Models\Obligation;
use App\Models\Team;
use App\Services\ObligationService;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use WithPagination;

    public Team $team;

    public string $search = '';

    #[Locked]
    public string $kind;

    #[Locked]
    public ?int $selected = null;

    #[Locked]
    public string $key;

    public array $form = [];

    public string $amount = '';

    public string $date = '';

    public string $filter = '';

    public string $notice = '';

    public function mount(Team $current_team): void
    {
        $this->team = $current_team;
        $this->permit();
        $this->kind = request()->routeIs('bills.index') ? 'bill' : 'invoice';
        $this->date = now('Asia/Manila')->toDateString();
        $this->resetEntry();
        if (request()->has('document')) {
            $this->selected = $this->records()->findOrFail(request()->integer('document'))->id;
        }
    }

    private function permit(): void
    {
        Gate::authorize('manageFinancialRecords', $this->team->fresh());
    }

    private function resetEntry(): void
    {
        $this->form = ['issue_date' => $this->date, 'lines' => [['description' => '', 'quantity' => 1, 'unit_price' => '']]];
        $this->key = (string) Str::uuid();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function startCreate(): void
    {
        $this->permit();
        $this->resetEntry();
        $this->resetValidation();
        Flux::modal('document-form')->show();
    }

    public function addLine(): void
    {
        $this->permit();
        $this->form['lines'][] = ['description' => '', 'quantity' => 1, 'unit_price' => ''];
    }

    public function removeLine(int $index): void
    {
        $this->permit();
        unset($this->form['lines'][$index]);
        $this->form['lines'] = array_values($this->form['lines']);
    }

    public function save(ObligationService $service): void
    {
        $this->permit();
        $record = $service->create(auth()->user(), $this->team, $this->kind, $this->form);
        $this->resetEntry();
        Flux::modal('document-form')->close();
        $this->open($record->id);
        $this->notice = ucfirst($this->kind).' created.';
    }

    public function open(int $id): void
    {
        $this->permit();
        $this->selected = $this->records()->findOrFail($id)->id;
        $this->notice = '';
        $this->amount = '';
        $this->key = (string) Str::uuid();
        $this->resetValidation();
        Flux::modal('document-detail')->show();
    }

    public function pay(ObligationService $service): void
    {
        $this->permit();
        abort_if($this->selected === null, 404);
        $service->pay(auth()->user(), $this->team, $this->kind, $this->selected, $this->amount, $this->date, $this->key);
        $this->open($this->selected);
        $this->notice = 'Payment recorded.';
    }

    private function records()
    {
        $this->permit();

        return Obligation::where('team_id', $this->team->id)->where('kind', $this->kind)->with(['customer', 'supplier', 'payments.transaction']);
    }

    #[Computed]
    public function documents()
    {
        $query = $this->records()->orderBy('due_date')->orderBy('id');
        if (trim($this->search) !== '') {
            $pattern = '%'.trim($this->search).'%';
            $query->where(function ($query) use ($pattern) {
                $query->whereLike($this->kind === 'invoice' ? 'number' : 'description', $pattern)
                    ->orWhereHas($this->kind === 'invoice' ? 'customer' : 'supplier', fn ($contact) => $contact->whereLike('name', $pattern));
            });
        }
        if ($this->filter === '') {
            return $query->paginate(10);
        }

        // Due status also depends on the outstanding balance, including partial payments.
        $matches = $query->get()->filter(fn ($document) => $document->dueStatus() === $this->filter);
        return new LengthAwarePaginator($matches->forPage($this->getPage(), 10)->values(), $matches->count(), 10, $this->getPage());

    }

    #[Computed]
    public function detail()
    {
        return $this->selected ? $this->records()->with('lines')->findOrFail($this->selected) : null;
    }

    #[Computed]
    public function contacts()
    {
        $this->permit();

        return ($this->kind === 'invoice' ? $this->team->customers() : $this->team->suppliers())->orderBy('name')->get();
    }
}; ?>
<section class="agos-page">
    @if (request()->routeIs('invoices.index', 'bills.index') && $selected)
        <span x-init="$nextTick(() => $flux.modal('document-detail').show())"></span>
    @endif
    <header class="agos-page-header">
        <div>
            <flux:heading level="1" size="xl">{{ $kind === 'invoice' ? 'Invoices' : 'Supplier bills' }}</flux:heading>
            <flux:subheading>{{ $kind === 'invoice' ? 'Track customer invoices, balances and payments.' : 'Track supplier bills, due dates and payments.' }}</flux:subheading>
        </div>
        <flux:button variant="primary" wire:click="startCreate">New {{ $kind }}</flux:button>
    </header>

    <flux:modal name="document-form" class="w-full sm:max-w-xl">
        <form wire:submit="save" class="space-y-4">
            <flux:heading level="2">New {{ $kind }}</flux:heading>
            @if ($errors->any())<div role="alert" class="text-sm text-red-700">@foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach</div>@endif
            <flux:select wire:model="form.contact_id" :label="$kind==='invoice'?'Customer':'Supplier'">
                <option value="">Choose a contact</option>
                @foreach($this->contacts as $contact)<option value="{{ $contact->id }}">{{ $contact->name }}</option>@endforeach
            </flux:select>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input type="date" wire:model="form.issue_date" label="Issue date"/>
                <flux:input type="date" wire:model="form.due_date" label="Due date"/>
            </div>
            @if($kind==='invoice')
                <flux:input wire:model="form.number" label="Invoice number"/>
                @foreach($form['lines'] as $i=>$line)
                    <fieldset class="grid gap-3 rounded-lg border p-3 sm:grid-cols-3"><legend>Line {{ $i+1 }}</legend>
                        <flux:input wire:model="form.lines.{{ $i }}.description" label="Description"/>
                        <flux:input wire:model="form.lines.{{ $i }}.quantity" type="number" min="1" label="Quantity"/>
                        <flux:input wire:model="form.lines.{{ $i }}.unit_price" inputmode="decimal" label="Unit price (PHP)"/>
                        @if(count($form['lines'])>1)<flux:button wire:click="removeLine({{ $i }})">Remove line {{ $i+1 }}</flux:button>@endif
                    </fieldset>
                @endforeach
                <flux:button wire:click="addLine">Add line</flux:button>
            @else
                <flux:input wire:model="form.description" label="Bill description"/>
                <flux:input wire:model="form.total" inputmode="decimal" label="Bill amount (PHP)"/>
            @endif
            <div class="flex flex-wrap gap-2">
                <flux:modal.close><flux:button type="button">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Create {{ $kind }}</flux:button>
            </div>
            <p wire:loading wire:target="save" role="status" class="text-sm">Saving {{ $kind }}…</p>
        </form>
    </flux:modal>

    <div class="mb-5 grid items-end gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700 sm:grid-cols-2">
        <flux:input wire:model.live.debounce.300ms="search" type="search" icon="magnifying-glass" label="Search"
            :placeholder="$kind === 'invoice' ? 'Invoice number or customer' : 'Description or supplier'" />
        <flux:select wire:model.live="filter" label="Due status">
            <option value="">All dates</option>
            @foreach (['Upcoming', 'Due today', 'Overdue', 'Settled'] as $status)<option>{{ $status }}</option>@endforeach
        </flux:select>
    </div>
    <x-directory-table :records="$this->documents" :caption="$kind === 'invoice' ? 'Invoices' : 'Supplier bills'">
        <x-slot:head>
            <th scope="col" class="px-5 py-3">{{ ucfirst($kind) }}</th>
            <th scope="col" class="hidden px-3 py-3 md:table-cell">Due date</th>
            <th scope="col" class="hidden px-3 py-3 sm:table-cell">Status</th>
            <th scope="col" class="hidden px-3 py-3 text-right sm:table-cell">Outstanding</th>
            <th scope="col" class="w-24 px-3 py-3 text-right font-medium">Actions</th>
        </x-slot:head>
        @forelse ($this->documents as $doc)
            <tr wire:key="document-{{ $doc->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800">
                <th scope="row" class="wrap-break-word px-5 py-4">
                    <button type="button" wire:click="open({{ $doc->id }})" class="text-left font-semibold">{{ $doc->number ?? $doc->description }}</button>
                    <span class="block font-normal text-slate-600">{{ ($doc->customer ?? $doc->supplier)->name }}</span>
                    <span class="block font-normal md:hidden">Due {{ $doc->due_date }}</span>
                    <span class="block font-normal sm:hidden">{{ $doc->dueStatus() }} · {{ $doc->settlement() }} · ₱{{ $doc->balance() }} outstanding</span>
                </th>
                <td class="hidden px-3 py-4 md:table-cell">{{ $doc->due_date }}</td>
                <td class="hidden px-3 py-4 sm:table-cell">{{ $doc->dueStatus() }}<span class="block text-slate-600">{{ $doc->settlement() }}</span></td>
                <td class="hidden wrap-break-word px-3 py-4 text-right tabular-nums sm:table-cell">₱{{ $doc->balance() }}</td>
                <td class="px-3 py-4 text-right"><flux:button size="sm" variant="outline" wire:click="open({{ $doc->id }})" :aria-label="'View details for '.($doc->number ?? $doc->description)">Details</flux:button></td>
            </tr>
        @empty
            <tr><td colspan="5" class="p-8 text-center text-slate-600">No {{ $filter || $search ? 'matching ' : '' }}{{ $kind === 'invoice' ? 'invoices' : 'bills' }}{{ $filter || $search ? '.' : ' yet.' }}</td></tr>
        @endforelse
    </x-directory-table>

    <flux:modal name="document-detail" variant="flyout" class="w-full sm:max-w-lg">
        @if ($doc = $this->detail)
            <div class="space-y-5">
                <div>
                    <p class="agos-eyebrow">{{ ucfirst($kind) }} details</p>
                    <flux:heading level="2" size="xl" class="wrap-break-word">{{ $doc->number ?? $doc->description }}</flux:heading>
                    <flux:subheading>{{ ($doc->customer ?? $doc->supplier)->name }}</flux:subheading>
                </div>
                @if ($notice)
                    <p class="agos-card p-4" role="status">{{ $notice }}</p>
                @endif
                <dl class="grid grid-cols-2 gap-4 border-y border-slate-200 py-5 text-sm">
                    @foreach (['Issued' => $doc->issue_date, 'Due date' => $doc->due_date, 'Due status' => $doc->dueStatus(), 'Settlement' => $doc->settlement(), 'Total' => '₱'.$doc->total, 'Outstanding' => '₱'.$doc->balance()] as $label => $value)
                        <div><dt class="font-medium text-slate-600">{{ $label }}</dt><dd class="mt-1 wrap-break-word">{{ $value }}</dd></div>
                    @endforeach
                </dl>
                @if ($doc->lines->isNotEmpty())
                    <div>
                        <flux:heading level="3">Line items</flux:heading>
                        <ul class="divide-y divide-slate-200 text-sm">
                            @foreach ($doc->lines as $line)
                                <li class="flex flex-wrap justify-between gap-2 py-2">
                                    <span class="wrap-break-word">{{ $line->description }}</span>
                                    <span class="tabular-nums">{{ $line->quantity }} × ₱{{ $line->unit_price }} = ₱{{ $line->line_total }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <div>
                    <flux:heading level="3">Payments</flux:heading>
                    @forelse ($doc->payments as $payment)
                        <p class="flex justify-between gap-3 py-2 text-sm">{{ $payment->transaction->transaction_date->toDateString() }} <span>₱{{ $payment->transaction->amount }}</span></p>
                    @empty
                        <flux:text>No payments recorded.</flux:text>
                    @endforelse
                </div>
                @if ($doc->balance()->isPositive())
                    <form wire:submit="pay" class="grid gap-3 border-t border-slate-200 pt-4">
                        <flux:input wire:model="amount" label="Payment (PHP)" inputmode="decimal"/>
                        <flux:input type="date" wire:model="date" label="Payment date"/>
                        <flux:button type="submit" variant="primary">Record payment</flux:button>
                    </form>
                @endif
            </div>
        @endif
    </flux:modal>
</section>
