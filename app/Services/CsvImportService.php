<?php

namespace App\Services;

use App\Models\ImportBatch;
use App\Models\Team;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CsvImportService
{
    public const FIELDS = ['date', 'type', 'amount', 'currency', 'description', 'source_reference', 'customer', 'supplier', 'category', 'payment_status', 'external_id'];

    public function __construct(private TransactionService $ledger, private AuditEventService $audit) {}

    public function batch(User $actor, Team $team, int $id): ImportBatch
    {
        Gate::forUser($actor)->authorize('manageFinancialRecords', $team->fresh());

        return ImportBatch::where('team_id', $team->id)->where('user_id', $actor->id)->findOrFail($id);
    }

    public function upload(User $actor, Team $team, string $csv): ImportBatch
    {
        Gate::forUser($actor)->authorize('manageFinancialRecords', $team->fresh());
        if (strlen($csv) > 2097152 || ! mb_check_encoding($csv, 'UTF-8') || str_contains($csv, "\0")) {
            throw ValidationException::withMessages(['file' => 'Choose a UTF-8 CSV up to 2 MiB.']);
        }
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }
        $csv = str_replace(["\r\n", "\r"], "\n", $csv);
        $stream = fopen('php://temp', 'w+b');
        try {
            fwrite($stream, $csv);
            rewind($stream);
            $headers = fgetcsv($stream, null, ',', '"', '');
            $headers = array_map(fn ($h) => trim((string) $h), $headers ?: []);
            $looksLikeData = array_intersect(['income', 'expense'], array_map('strtolower', $headers))
                && array_filter($headers, fn ($header) => preg_match('/^\d{1,4}[-\/]\d{1,2}[-\/]\d{1,4}$/D', $header));
            if ($looksLikeData || count($headers) < 2 || in_array('', $headers, true) || count(array_unique($headers)) !== count($headers)) {
                throw ValidationException::withMessages(['file' => 'Include a header row with distinct, nonempty column names.']);
            }

            return DB::transaction(function () use ($actor, $team, $stream, $csv, $headers) {
                $batch = ImportBatch::create(['team_id' => $team->id, 'user_id' => $actor->id, 'headers' => $headers]);
                $line = 1 + substr_count(substr($csv, 0, ftell($stream)), "\n");
                while (! feof($stream)) {
                    $start = ftell($stream);
                    $cells = fgetcsv($stream, null, ',', '"', '');
                    if ($cells === false) {
                        break;
                    }
                    $raw = rtrim(substr($csv, $start, ftell($stream) - $start), "\n");
                    // fgetcsv accepts unclosed quotes and may swallow subsequent records.
                    if (! preg_match('/\A(?:"(?:[^"]++|"")*+"|[^",\n]*+)(?:,(?:"(?:[^"]++|"")*+"|[^",\n]*+))*+\z/D', $raw)) {
                        throw ValidationException::withMessages(['file' => "Invalid CSV quoting at source row {$line}."]);
                    }
                    if ($cells !== [null]) {
                        $batch->rows()->create(['team_id' => $team->id, 'row_number' => $line, 'cells' => $cells]);
                    }
                    $line += substr_count(substr($csv, $start, ftell($stream) - $start), "\n");
                }
                if (! $batch->rows()->exists()) {
                    throw ValidationException::withMessages(['file' => 'Include at least one data row.']);
                }

                return $batch;
            });
        } finally {
            fclose($stream);
        }
    }

    public function review(User $actor, Team $team, int $id, array $mapping, string $format, bool $cash): ImportBatch
    {
        $batch = $this->batch($actor, $team, $id);
        $mapping = array_filter($mapping, fn ($v) => $v !== '' && $v !== null);
        if (array_diff(['date', 'type', 'amount', 'currency'], array_keys($mapping)) ||
            ! array_intersect(['description', 'source_reference'], array_keys($mapping)) ||
            ! in_array($format, ['Y-m-d', 'd-m-Y'], true) || array_diff(array_keys($mapping), self::FIELDS)) {
            throw ValidationException::withMessages(['mapping' => 'Map date, type, amount, currency, and a description or source reference.']);
        }
        $statusColumn = array_search('payment_status', array_map(fn ($header) => Str::snake(Str::lower(trim($header)), '_'), $batch->headers), true);
        if ($statusColumn !== false && (string) ($mapping['payment_status'] ?? '') !== (string) $statusColumn) {
            throw ValidationException::withMessages(['mapping' => 'Map the payment-status column so unsettled rows can be held for review.']);
        }
        foreach ($mapping as $index) {
            if ((! is_string($index) && ! is_int($index)) || ! ctype_digit((string) $index) || ! array_key_exists($index, $batch->headers)) {
                throw ValidationException::withMessages(['mapping' => 'Choose columns from the uploaded header.']);
            }
        }

        return DB::transaction(function () use ($batch, $mapping, $format, $cash, $team) {
            $batch = $batch->newQuery()->lockForUpdate()->findOrFail($batch->id);
            abort_if($batch->committed_at, 409);
            $batch->update(['mapping' => $mapping, 'date_format' => $format, 'confirmed_cash' => $cash, 'revision' => $batch->revision + 1]);
            $this->evaluate($batch, $team);

            return $batch;
        });
    }

    // Recompute against the current ledger and contacts before writing any cash records.
    private function evaluate(ImportBatch $batch, Team $team): bool
    {
        $rows = $batch->rows()->get();
        $seenIds = [];
        if (isset($batch->mapping['external_id'])) {
            foreach ($rows as $row) {
                $id = trim($row->cells[$batch->mapping['external_id']] ?? '');
                $seenIds[$id] = ($seenIds[$id] ?? 0) + 1;
            }
        }
        $seen = [];
        $changed = false;
        foreach ($rows as $row) {
            $fields = [];
            foreach ($batch->mapping as $field => $index) {
                $fields[$field] = trim($row->cells[$index] ?? '');
            }
            $review = ['status' => 'ready', 'reason' => null, 'matches' => [], 'attributes_reviewed' => null, 'customer_id' => null, 'supplier_id' => null];
            try {
                if (count($row->cells) !== count($batch->headers)) {
                    $this->reject('Column count differs from the header.');
                }
                $attributes = $this->attributes($team, $batch, $fields, $review);
                $external = $attributes['external_id'];
                if ($external !== null && ($seenIds[$external] > 1 || $team->transactions()->withTrashed()->where('source_type', 'csv')->where('external_id', $external)->exists())) {
                    $this->reject('Duplicate external ID.');
                }
                $candidates = $team->transactions()->where('direction', $attributes['direction'])->where('amount', $attributes['amount'])->whereDate('transaction_date', $attributes['transaction_date'])->get();
                $texts = $this->texts($attributes);
                $matches = [];
                foreach ($candidates as $candidate) {
                    if (array_intersect($texts, $this->texts($candidate->toArray()))) {
                        $matches[] = 'ledger '.$candidate->id;
                    }
                }
                $prefix = implode('|', [$attributes['direction'], $attributes['amount'], $attributes['transaction_date']]).'|';
                foreach ($texts as $text) {
                    foreach ($seen[$prefix.$text] ?? [] as $number) {
                        $matches[] = 'row '.$number;
                    }
                    $seen[$prefix.$text][] = $row->row_number;
                }
                sort($matches);
                $review['matches'] = array_values(array_unique($matches));
                if ($matches) {
                    $review['status'] = 'probable';
                    $review['reason'] = 'Possible duplicate of '.implode(', ', $review['matches']).'.';
                }
                $review['attributes_reviewed'] = $attributes;
            } catch (ValidationException $e) {
                $review['status'] = 'held';
                $review['reason'] = collect($e->errors())->flatten()->implode(' ');
            }
            $row->fill($review);
            $changed = $row->isDirty() || $changed;
            $row->save();
        }

        return $changed;
    }

    private function texts(array $attributes): array
    {
        return array_unique(array_filter(array_map(fn ($v) => Str::lower(Str::squish($v ?? '')), [$attributes['description'], $attributes['source_reference'] ?? '']), fn ($v) => $v !== ''));
    }

    private function reject(string $reason): never
    {
        throw ValidationException::withMessages(['row' => $reason]);
    }

    private function attributes(Team $team, ImportBatch $batch, array $f, array &$review): array
    {
        Validator::make($f, [
            'date' => ['required', 'date_format:'.$batch->date_format],
            'amount' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/D'],
            'source_reference' => 'nullable|string|max:255', 'external_id' => 'nullable|string|max:255',
            'description' => 'required_without:source_reference',
        ])->validate();
        $direction = Str::lower($f['type']);
        if (! in_array($direction, ['income', 'expense'], true)) {
            $this->reject('Type must be income or expense.');
        }
        if (strtoupper($f['currency']) !== 'PHP') {
            $this->reject('Currency must be PHP.');
        }
        if (isset($f['payment_status'])) {
            if (! in_array(Str::lower($f['payment_status']), ['paid', 'settled', 'received'], true)) {
                $this->reject('Settlement unconfirmed. Use the invoice, bill, or payment workflow.');
            }
        } elseif (! $batch->confirmed_cash) {
            $this->reject('Confirm that the file represents settled cash.');
        }
        foreach (['customer', 'supplier', 'category'] as $field) {
            if (($f[$field] ?? '') === '') {
                continue;
            }
            $relation = match ($field) {
                'customer' => $team->customers(),'supplier' => $team->suppliers(),default => $team->categories()
            };
            $matches = $relation->where('name', $f[$field])->limit(2)->get();
            if ($matches->count() !== 1) {
                $this->reject('Unresolved '.$field.'. Review the name or mapping.');
            }
            if ($field === 'category') {
                $category = $matches->first();
                if (! in_array($category->type, ['both', $direction], true)) {
                    $this->reject('Category direction does not match.');
                }
            } else {
                $review[$field.'_id'] = $matches->first()->id;
            }
        }

        return ['transaction_date' => \DateTimeImmutable::createFromFormat('!'.$batch->date_format, $f['date'])->format('Y-m-d'),
            'direction' => $direction, 'amount' => (string) BigDecimal::of($f['amount'])->toScale(2),
            'description' => ($f['description'] ?? '') !== '' ? $f['description'] : $f['source_reference'],
            'source_reference' => ($f['source_reference'] ?? '') !== '' ? $f['source_reference'] : null,
            'external_id' => ($f['external_id'] ?? '') !== '' ? $f['external_id'] : null, 'category_id' => $category->id ?? null, 'source_type' => 'csv'];
    }

    public function commit(User $actor, Team $team, int $id, int $revision, array $selected): ImportBatch
    {
        $batch = $this->batch($actor, $team, $id);

        return DB::transaction(function () use ($actor, $team, $batch, $revision, $selected) {
            Team::whereKey($team->id)->lockForUpdate()->firstOrFail();
            $batch = $batch->newQuery()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->committed_at) {
                return $batch;
            }
            if (! $revision || $revision !== $batch->revision) {
                throw ValidationException::withMessages(['review' => 'Preview the current mapping before committing.']);
            }
            if ($this->evaluate($batch, $team)) {
                $batch->increment('revision');

                return $batch->refresh();
            }
            $rows = $batch->rows()->get();
            Validator::make(['selected' => $selected], ['selected' => 'required|array', 'selected.*' => 'required|integer|distinct'])->validate();
            $selected = array_map('intval', $selected);
            $eligible = $rows->whereIn('status', ['ready', 'probable'])->pluck('row_number')->all();
            if (! $selected || array_diff($selected, $eligible)) {
                throw ValidationException::withMessages(['review' => 'Select valid reviewed rows.']);
            }
            $dates = [];
            foreach ($rows as $row) {
                if (! in_array($row->row_number, $selected, true)) {
                    if ($row->status !== 'held') {
                        $row->update(['status' => 'skipped', 'reason' => $row->reason ?? 'Skipped during review.']);
                    }

                    continue;
                }
                $movement = $this->ledger->create($actor, $team, $row->attributes_reviewed);
                $row->update(['status' => 'imported', 'transaction_id' => $movement->id, 'reason' => null]);
                $dates[] = $movement->transaction_date->toDateString();
            }
            $batch->update(['first_date' => min($dates), 'last_date' => max($dates), 'committed_at' => now()]);
            $this->audit->record($actor, $team, 'import.committed', $batch);

            return $batch;
        });
    }
}
