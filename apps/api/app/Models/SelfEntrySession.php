<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SelfEntrySession extends Model
{
    protected $fillable = ['token_hash', 'participant_id', 'form_code', 'lang', 'created_by', 'expires_at', 'started_at', 'completed_at', 'cancelled_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function isOpen(): bool
    {
        return ! $this->completed_at && ! $this->cancelled_at && $this->expires_at->isFuture();
    }
}
