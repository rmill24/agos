<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<array{amount:string,direction:string}>|null $movements
 */
class LoyverseSyncEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['movements' => 'array'];
    }

    /** @return BelongsTo<LoyverseSync, $this> */
    public function sync(): BelongsTo
    {
        return $this->belongsTo(LoyverseSync::class, 'loyverse_sync_id');
    }
}
