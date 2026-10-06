<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A participant or caregiver allowed to use the app. Caregivers are read-only (plus "Seen"). */
class AppUser extends Model
{
    public const ROLES = ['participant', 'caregiver'];

    protected $fillable = ['participant_id', 'role', 'name', 'relation', 'phone', 'firebase_uid', 'status', 'lang', 'reminder_times',
        'activated_at', 'last_seen_at', 'revoked_at', 'revoke_reason', 'created_by'];

    protected function casts(): array
    {
        return ['reminder_times' => 'array', 'activated_at' => 'datetime', 'last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(AppToken::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(AppDevice::class);
    }

    public function isCaregiver(): bool
    {
        return $this->role === 'caregiver';
    }
}
