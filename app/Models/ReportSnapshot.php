<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ReportSnapshot extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = ['kind', 'data', 'html', 'created_at'];

    protected $casts = ['data' => 'array', 'created_at' => 'immutable_datetime'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasOne<ReportPdf, $this> */
    public function pdf(): HasOne
    {
        return $this->hasOne(ReportPdf::class);
    }
}
