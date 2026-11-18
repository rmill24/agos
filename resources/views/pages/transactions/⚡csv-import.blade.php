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
    public int $uploadSession = 0;

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
        $this->uploadSession++;
        Flux::modal('import-upload')->show();
    }

    public function updatedFile(): void
    {
        $this->permit();
        $this->validateOnly('file', $this->fileRules());
    }

    private function fileRules(): array
    {
        return ['file' => 'required|file|extensions:csv|max:2048'];
    }

    public function stageFile(CsvImportService $service): void
    {
        $this->permit();
        $this->validate($this->fileRules());
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
        Flux::modal('import-history')->close();
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
            $this->dispatch('transactions-imported');
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
<div class="flex flex-wrap gap-2">
    <flux:button wire:click="startUpload">Import CSV</flux:button>
    <flux:modal.trigger name="import-history">
        <flux:button>Import history</flux:button>
    </flux:modal.trigger>

    <flux:modal name="import-upload" class="w-full sm:max-w-lg">
        <form wire:submit="stageFile" wire:key="upload-session-{{ $uploadSession }}" class="space-y-6"
            x-data="{ dragging: false, uploading: false, failed: false, progress: 0 }"
            x-on:livewire-upload-start="uploading = true; failed = false; progress = 0"
            x-on:livewire-upload-progress="progress = $event.detail.progress"
            x-on:livewire-upload-finish="uploading = false"
            x-on:livewire-upload-error="uploading = false; failed = true"
            x-on:livewire-upload-cancel="uploading = false">
            <div>
                <flux:heading level="2" size="lg">Import transactions</flux:heading>
                <flux:subheading>Choose a CSV, then map its columns and review the rows.</flux:subheading>
            </div>

            <div>
                <label class="relative flex cursor-pointer flex-col items-center gap-2 rounded-xl border-2 border-dashed p-6 text-center focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-brand-600 sm:p-8"
                    x-bind:class="dragging ? 'border-brand-600 bg-brand-50 dark:bg-zinc-800' : 'border-zinc-300 bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-900'">
                    <flux:icon.arrow-up-tray class="size-7 text-brand-600" />
                    <span class="max-w-full break-all font-medium">{{ $file ? $file->getClientOriginalName() : __('Drop your transaction CSV here') }}</span>
                    <span class="font-medium underline underline-offset-4">{{ $file ? __('Browse to replace file') : __('Browse files') }}</span>
                    <span id="csv-requirements" class="text-sm text-zinc-600 dark:text-zinc-400">UTF-8 CSV with a header row · Up to 2 MiB</span>
                    <input type="file" wire:model="file" accept=".csv,text/csv" aria-label="Choose transaction CSV"
                        aria-describedby="csv-requirements" class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                        x-on:dragenter="dragging = true" x-on:dragleave="dragging = false" x-on:drop="dragging = false" />
                </label>
                <div role="status" aria-live="polite" aria-atomic="true">
                    @if ($file && ! $errors->has('file'))
                        <div x-show="!uploading && !failed" class="mt-3 flex items-center gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-100">
                            <span aria-hidden="true" class="grid size-8 shrink-0 place-items-center rounded-full bg-emerald-700 text-white motion-safe:transition-transform motion-safe:duration-200 motion-safe:starting:scale-50">
                                <flux:icon.check class="size-5" />
                            </span>
                            <div class="min-w-0 text-sm">
                                <p class="font-semibold">File uploaded</p>
                                <p class="break-all">{{ $file->getClientOriginalName() }} is ready for column mapping.</p>
                            </div>
                        </div>
                    @endif
                </div>
                <flux:error name="file" />
                <p x-cloak x-show="uploading" role="status" class="mt-2 text-sm">Uploading… <span x-text="progress + '%'"></span></p>
                <p x-cloak x-show="failed" role="alert" class="mt-2 text-sm">Upload failed. Choose the file again to retry.</p>
            </div>
            <div class="flex flex-wrap justify-end gap-2">
                <flux:modal.close><flux:button type="button">Cancel</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" :disabled="!$file" x-bind:disabled="uploading || failed || !$wire.file">Continue to mapping</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="import-history" class="w-full sm:max-w-3xl">
        <flux:heading level="2" size="lg" class="mb-4">Import history</flux:heading>
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
    </flux:modal>

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
</div>
