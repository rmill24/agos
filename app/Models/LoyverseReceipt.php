<?php

namespace App\Models;

use App\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string,mixed> $receipt
 * @property CarbonImmutable $source_updated_at
 */
#[Fillable(['receipt_number', 'merchant_id', 'store_id', 'currency_code', 'source_updated_at', 'synced_at', 'receipt'])]
class LoyverseReceipt extends Model
{
    use BelongsToTeam;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    protected function casts(): array
    {
        return [
            'source_updated_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
            'receipt' => 'array',
        ];
    }
}
