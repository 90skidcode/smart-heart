<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One eCRF form instance for one participant at one visit. Table: forms. */
class CrfForm extends Model
{
    protected $table = 'forms';

    protected $fillable = ['participant_id', 'form_code', 'visit', 'status', 'data', 'computed', 'overrides',
        'completed_at', 'completed_by', 'signed_at', 'signed_by', 'signature_meaning', 'unlock_count', 'updated_by'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'computed' => 'array',
            'overrides' => 'array',
            'completed_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function isSigned(): bool
    {
        return $this->status === 'signed';
    }
}
