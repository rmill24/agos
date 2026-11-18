<?php

use App\Models\ImportBatch;
use App\Models\Team;
use App\Services\CsvImportService;
use Flux\Flux;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads, WithPagination;

    public string $status = '';

    public Team $team;

    public $file;

    #[Locked]
    public ?int $batchId = null;

    #[Locked]
    public int $revision = 0;

    public array $mapping = [];

    public string $format = 'Y-m-d';

    public bool $cash = false;

    public array $selected = [];

    public string $notice = '';

    public function mount(Team $current_team): void
    {
        $this->team = $current_team;
        $this->permit();
    }

    private function permit(): void
    {
        Gate::authorize('manageFinancialRecords', $this->team->fresh());
    }

    #[Computed]
    public function batch()
    {
        $this->permit();

        return $this->batchId ? app(CsvImportService::class)->batch(auth()->user(), $this->team, $this->batchId) : null;
    }

    #[Computed]
    public function past()
    {
        $this->permit();

        return ImportBatch::where('team_id', $this->team->id)->where('user_id', auth()->id())
            ->when($this->status === 'draft', fn ($query) => $query->whereNull('committed_at'))
            ->when($this->status === 'imported', fn ($query) => $query->whereNotNull('committed_at'))
            ->withCount('rows')->latest('id')->paginate(10);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function startUpload(): void
    {
        $this->permit();
        $this->reset('file');
        $this->resetValidation();
        Flux::modal('import-upload')->show();
    }

    public function stageFile(CsvImportService $service): void
    {
        $this->permit();
        $this->validate(['file' => 'required|file|max:2048']);
        $batch = $service->upload(auth()->user(), $this->team, $this->file->get());
        Flux::modal('import-upload')->close();
        $this->open($batch->id);
        $this->reset('file');
    }

    public function open(int $id): void
    {
        $this->permit();
        $batch = app(CsvImportService::class)->batch(auth()->user(), $this->team, $id);
        $this->batchId = $id;
        $this->mapping = $batch->mapping ?? array_intersect_key(array_flip($batch->headers), array_flip(CsvImportService::FIELDS));
        $this->format = $batch->date_format;
        $this->cash = $batch->confirmed_cash;
        $this->showReview($batch);
        $this->resetValidation();
        $this->notice = '';
        Flux::modal('batch-detail')->show();
    }

    private function showReview(ImportBatch $batch): void
    {
        $this->revision = $batch->revision;
        $this->selected = $batch->rows()->where('status', 'ready')->pluck('row_number')->map(fn ($n) => (string) $n)->all();
    }

    public function updatedMapping(): void
    {
        $this->invalidateReview();
    }

    public function updatedFormat(): void
    {
        $this->invalidateReview();
    }

    public function updatedCash(): void
    {
        $this->invalidateReview();
    }

    private function invalidateReview(): void
    {
        $this->permit();
        $this->revision = 0;
        $this->selected = [];
    }

    public function review(CsvImportService $service): void
    {
        $this->permit();
        $batch = $service->review(auth()->user(), $this->team, $this->batchId, $this->mapping, $this->format, $this->cash);
        $this->showReview($batch);
        unset($this->batch);
        $this->notice = '';
    }

    public function commitReviewed(CsvImportService $service): void
    {
        $this->permit();
        $batch = $service->commit(auth()->user(), $this->team, $this->batchId, $this->revision, $this->selected);
        if (! $batch->committed_at) {
            $this->showReview($batch);
            $this->notice = 'Records changed since preview. Review the updated rows and confirm again.';
        } else {
            $this->notice = 'Reviewed rows imported.';
        }
        unset($this->batch);
    }

    public function download(CsvImportService $service)
    {
        $this->permit();
        $batch = $service->batch(auth()->user(), $this->team, $this->batchId);
        abort_unless($batch->revision > 0, 404);

        return response()->streamDownload(function () use ($batch) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Source row', 'Reason'], ',', '"', '');
            foreach ($batch->rows()->whereIn('status', ['held', 'skipped', 'probable'])->cursor() as $row) {
                fputcsv($out, [$row->row_number, $row->reason], ',', '"', '');
            }
            fclose($out);
        }, 'import-'.$batch->id.'-review.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}; ?>
<section class="agos-page">
    <header class="agos-page-header">
        <div>
            <flux:heading level="1" size="xl">CSV imports</flux:heading>
            <flux:subheading>Review spreadsheet transactions before adding them to the ledger.</flux:subheading>
        </div>
        <flux:button variant="primary" wire:click="startUpload">New import</flux:button>
    </header>

    <flux:modal name="import-upload" class="w-full sm:max-w-md">
        <form wire:submit="stageFile" class="space-y-4">
            <flux:heading level="2">New CSV import</flux:heading>
            <flux:input type="file" wire:model="file" accept=".csv,text/csv" label="UTF-8 CSV file (up to 2 MiB)" />
            <div class="flex flex-wrap gap-2">
                <flux:modal.close><flux:button type="button">Cancel</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" :disabled="!$file">Upload CSV</flux:button>
            </div>
            <p wire:loading role="status" class="text-sm">Preparing file…</p>
        </form>
    </flux:modal>

    <div class="mb-5 grid items-end gap-4 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700 sm:grid-cols-2">
        <flux:select wire:model.live="status" label="Import status">
            <option value="">All batches</option><option value="draft">Draft</option><option value="imported">Imported</option>
        </flux:select>
    </div>
    <x-directory-table :records="$this->past" caption="Your import batches">
        <x-slot:head>
            <th scope="col" class="px-5 py-3">Batch</th>
            <th scope="col" class="hidden px-3 py-3 sm:table-cell">Uploaded</th>
            <th scope="col" class="hidden px-3 py-3 md:table-cell">Rows</th>
            <th scope="col" class="hidden px-3 py-3 sm:table-cell">Status</th>
            <th scope="col" class="w-24 px-3 py-3 text-right font-medium">Actions</th>
        </x-slot:head>
        @forelse ($this->past as $previous)
            <tr wire:key="import-{{ $previous->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800">
                <th scope="row" class="px-5 py-4">
                    <button type="button" wire:click="open({{ $previous->id }})" class="text-left font-semibold">Batch {{ $previous->id }}</button>
                    <span class="block font-normal text-slate-600 sm:hidden">{{ $previous->committed_at ? 'Imported' : 'Draft' }} · {{ $previous->created_at->timezone('Asia/Manila')->toDateString() }}</span>
                </th>
                <td class="hidden px-3 py-4 sm:table-cell">{{ $previous->created_at->timezone('Asia/Manila')->toDateString() }}</td>
                <td class="hidden px-3 py-4 md:table-cell">{{ $previous->rows_count }}</td>
                <td class="hidden px-3 py-4 sm:table-cell">{{ $previous->committed_at ? 'Imported' : 'Draft' }}</td>
                <td class="px-3 py-4 text-right"><flux:button size="sm" variant="outline" wire:click="open({{ $previous->id }})" :aria-label="'View details for batch '.$previous->id">Details</flux:button></td>
            </tr>
        @empty
            <tr><td colspan="5" class="p-8 text-center text-slate-600">{{ $status ? 'No matching import batches.' : 'No CSV imports yet.' }}</td></tr>
        @endforelse
    </x-directory-table>

    <flux:modal name="batch-detail" variant="flyout" class="w-full sm:max-w-2xl">
        @if ($batch = $this->batch)
            <div class="space-y-5">
                <div><p class="agos-eyebrow">Import details</p><flux:heading level="2" size="xl">Batch {{ $batch->id }}</flux:heading></div>
                @if($errors->any())<div role="alert" class="rounded-lg border p-3">@foreach($errors->all() as $message)<p>{{ $message }}</p>@endforeach</div>@endif
                @if($notice)<p class="agos-card p-4" role="status">{{ $notice }}</p>@endif
                @if(!$batch->committed_at)
                    <form wire:submit="review" class="space-y-4 rounded-xl border p-4">
                        <flux:heading>Map columns</flux:heading>
                        <p>Map date, type, amount, currency, and either description or source reference.</p>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach(\App\Services\CsvImportService::FIELDS as $field)
                                <flux:select wire:model.live="mapping.{{ $field }}" :label="str($field)->replace('_',' ')->ucfirst()">
                                    <option value="">Unmapped</option>@foreach($batch->headers as $i=>$header)<option value="{{ $i }}">{{ $header }}</option>@endforeach
                                </flux:select>
                            @endforeach
                            <flux:select wire:model.live="format" label="Date format"><option value="Y-m-d">YYYY-MM-DD</option><option value="d-m-Y">DD-MM-YYYY</option></flux:select>
                        </div>
                        <flux:checkbox wire:model.live="cash" label="Rows without a payment-status column represent cash already received or paid."/>
                        <flux:button type="submit">Preview mapped rows</flux:button>
                    </form>
                @else
                    <p role="status">{{ $batch->rows()->where('status','imported')->count() }} {{ str('row')->plural($batch->rows()->where('status','imported')->count()) }} imported · {{ $batch->first_date }} through {{ $batch->last_date }}</p>
                    <p><strong>Incomplete history</strong> · Opening cash is unknown.</p>
                @endif
                @if($revision || $batch->committed_at)
                    <flux:heading>Row review</flux:heading>
                    <ul class="divide-y rounded-xl border px-4">
                        @foreach($batch->rows as $row)
                            <li class="space-y-1 break-words py-3" wire:key="batch-{{ $batch->id }}-row-{{ $row->id }}">
                                <strong>Row {{ $row->row_number }} · {{ ucfirst($row->status) }}</strong>
                                <p>{{ implode(' · ', $row->cells) }}</p>
                                @if($attributes=$row->attributes_reviewed)<p>{{ $attributes['transaction_date'] }} · {{ ucfirst($attributes['direction']) }} · ₱{{ $attributes['amount'] }} · {{ $attributes['description'] }}</p>@endif
                                @if($row->reason)<p>{{ $row->reason }}</p>@endif
                                @if(!$batch->committed_at && in_array($row->status,['ready','probable']))
                                    <flux:checkbox wire:model="selected" value="{{ $row->row_number }}" :label="'Include row '.$row->row_number.($row->status==='probable'?' as a distinct movement':'')"/>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <div class="flex flex-wrap gap-3">
                        @if(!$batch->committed_at)<flux:button variant="primary" wire:click="commitReviewed" wire:confirm="Record the selected rows as confirmed cash movements?">Commit selected rows</flux:button>@endif
                        <flux:button wire:click="download">Download row report</flux:button>
                    </div>
                @endif
                <p wire:loading role="status">Processing…</p>
            </div>
        @endif
    </flux:modal>
</section>
