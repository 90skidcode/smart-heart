<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppToken extends Model
{
    protected $fillable = ['app_user_id', 'token_hash', 'device_name', 'last_used_at', 'expires_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class);
    }
}
