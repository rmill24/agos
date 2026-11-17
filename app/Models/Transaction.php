<?php

namespace App\Models;

use App\Concerns\BelongsToTeam;
use Brick\Math\BigDecimal;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $category_id
 * @property int|null $recorded_by
 * @property string $direction
 * @property string $amount
 * @property Carbon $transaction_date
 * @property string $description
 * @property string $source_type
 * @property string|null $source_reference
 * @property string|null $external_id
 * @property string $currency_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Team $team
 * @property-read Category|null $category
 * @property-read User|null $recordedBy
 */
#[Fillable([
    'team_id',
    'category_id',
    'recorded_by',
    'direction',
    'amount',
    'transaction_date',
    'description',
    'source_type',
    'source_reference',
    'external_id',
    'currency_code',
])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use BelongsToTeam, HasFactory, SoftDeletes;

    /**
     * Get the Team that owns the transaction.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the assigned category.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the user who recorded the transaction.
     *
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Return the persisted decimal amount without converting it to a float.
     */
    public function amountAsBigDecimal(): BigDecimal
    {
        return BigDecimal::of((string) $this->amount);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transaction_date' => 'date',
        ];
    }
}
