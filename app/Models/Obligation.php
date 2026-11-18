<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Obligation extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['total' => 'decimal:2'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function balance(): BigDecimal
    {
        $balance = BigDecimal::of($this->total);
        foreach ($this->payments as $payment) {
            $balance = $balance->minus($payment->transaction->amount);
        }

        return $balance;
    }

    public function settlement(): string
    {
        $balance = $this->balance();
        if ($balance->isZero()) {
            return 'Paid';
        }

        return $balance->isEqualTo($this->total) ? 'Unpaid' : 'Partially paid';
    }

    public function dueStatus(): string
    {
        if ($this->balance()->isZero()) {
            return 'Settled';
        }
        $today = now('Asia/Manila')->toDateString();

        return match ($this->due_date <=> $today) {
            -1 => 'Overdue', 0 => 'Due today', default => 'Upcoming'
        };
    }
}
