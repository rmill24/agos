<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $team_id
 * @property string $access_token
 * @property string $merchant_id
 * @property string $store_id
 * @property list<string>|null $collected_method_ids
 */
#[Fillable(['team_id', 'access_token', 'merchant_id', 'store_id', 'collected_method_ids', 'verified_at'])]
class LoyverseConnection extends Model
{
    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'collected_method_ids' => 'array',
            'verified_at' => 'immutable_datetime',
        ];
    }
}
