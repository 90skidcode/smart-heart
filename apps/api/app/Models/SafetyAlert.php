<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SafetyAlert extends Model
{
    protected $fillable = ['participant_id', 'rule', 'severity', 'source_form_id', 'summary', 'status', 'raised_at', 'notified_at',
        'source_reading_id', 'escalated_at', 'acknowledged_at', 'acknowledged_by', 'closed_at', 'closed_by', 'close_note'];

    protected function casts(): array
    {
        return ['raised_at' => 'datetime', 'notified_at' => 'datetime', 'escalated_at' => 'datetime', 'acknowledged_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
