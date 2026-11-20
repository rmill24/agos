<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportPdf extends Model
{
    protected $fillable = ['requested_by', 'status', 'content_base64'];

    protected $hidden = ['content_base64'];

    /** @return BelongsTo<ReportSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ReportSnapshot::class, 'report_snapshot_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
