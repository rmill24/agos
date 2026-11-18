<?php

namespace App\Models;

use App\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'email', 'phone'])]
class Supplier extends Model
{
    use BelongsToTeam, SoftDeletes;

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
