<?php

namespace App\Services;

use App\Models\Obligation;
use App\Models\Payment;
use App\Models\Team;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ObligationService
{
    public function __construct(private TransactionService $ledger, private AuditEventService $audit) {}

    /** @param  array<string, mixed>  $input */
    public function create(User $actor, Team $team, string $kind, array $input): Obligation
    {
        Gate::forUser($actor)->authorize('manageFinancialRecords', $team->fresh());
        abort_unless(in_array($kind, ['invoice', 'bill'], true), 404);

        return DB::transaction(function () use ($actor, $team, $kind, $input) {
            // Serialize invoice numbering and imports against other cash writers for this Team.
            Team::whereKey($team->id)->lockForUpdate()->firstOrFail();
            $money = ['required', 'string', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/D'];
            $rules = ['contact_id' => ['required', 'integer'], 'issue_date' => 'required|date_format:Y-m-d', 'due_date' => 'required|date_format:Y-m-d|after_or_equal:issue_date'];
            $invoice = $kind === 'invoice';
            $rules += $invoice ? [
                'number' => ['required', 'string', 'max:255', Rule::unique('obligations')->where('team_id', $team->id)],
                'lines' => 'required|array|min:1', 'lines.*.description' => 'required|string|max:255',
                'lines.*.quantity' => 'required|integer|min:1|max:2147483647', 'lines.*.unit_price' => $money,
            ] : ['description' => 'required|string|max:1000', 'total' => $money];
            $data = Validator::make($input, $rules)->validate();
            $contacts = $invoice ? $team->customers() : $team->suppliers();
            $contact = $contacts->lockForUpdate()->whereKey($data['contact_id'])->firstOrFail();
            $total = BigDecimal::of($invoice ? '0.00' : $data['total']);
            $lines = [];
            foreach ($data['lines'] ?? [] as $line) {
                $lineTotal = BigDecimal::of($line['unit_price'])->multipliedBy($line['quantity']);
                $total = $total->plus($lineTotal);
                $lines[] = ['description' => $line['description'], 'unit_price' => $line['unit_price'], 'quantity' => $line['quantity'], 'line_total' => (string) $lineTotal];
            }
            if (! $total->isPositive() || $total->isGreaterThan('9999999999999.99')) {
                throw ValidationException::withMessages(['total' => 'Enter a positive total within the ledger limit.']);
            }
            $document = Obligation::create([
                'team_id' => $team->id, 'kind' => $kind, ($invoice ? 'customer_id' : 'supplier_id') => $contact->id,
                'number' => $data['number'] ?? null, 'description' => $data['description'] ?? null,
                'issue_date' => $data['issue_date'], 'due_date' => $data['due_date'], 'total' => (string) $total,
            ]);
            $document->lines()->createMany($lines);
            $this->audit->record($actor, $team, $kind.'.created', $document);

            return $document;
        });
    }

    public function pay(User $actor, Team $team, string $kind, int $id, string $amount, string $date, string $key): Payment
    {
        Gate::forUser($actor)->authorize('manageFinancialRecords', $team->fresh());
        Validator::make(compact('amount', 'date', 'key'), [
            'amount' => ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/D'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Manila')->toDateString()],
            'key' => 'required|uuid',
        ])->validate();

        return DB::transaction(function () use ($actor, $team, $kind, $id, $amount, $date, $key) {
            Team::whereKey($team->id)->lockForUpdate()->firstOrFail();
            $document = Obligation::where('team_id', $team->id)->where('kind', $kind)->lockForUpdate()->findOrFail($id);
            $value = BigDecimal::of($amount);
            $existing = $document->payments()->where('submission_key', $key)->first();
            if ($existing) {
                if (! $value->isEqualTo($existing->transaction->amount) || $date !== $existing->transaction->transaction_date->toDateString()) {
                    throw ValidationException::withMessages(['amount' => 'This submission was already recorded with different details.']);
                }

                return $existing;
            }
            if (! $value->isPositive() || $value->isGreaterThan($document->balance())) {
                throw ValidationException::withMessages(['amount' => 'Enter a positive payment within the outstanding balance.']);
            }
            $movement = $this->ledger->create($actor, $team, [
                'direction' => $kind === 'invoice' ? 'income' : 'expense', 'amount' => $amount, 'transaction_date' => $date,
                'description' => ucfirst($kind).' payment '.($document->number ?? '#'.$id), 'source_type' => 'payment',
                'source_reference' => $document->number ?? (string) $id,
            ]);
            $payment = $document->payments()->create(['team_id' => $team->id, 'transaction_id' => $movement->id, 'submission_key' => $key]);
            $this->audit->record($actor, $team, 'payment.created', $payment);

            return $payment;
        });
    }
}
