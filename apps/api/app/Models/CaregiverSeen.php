<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaregiverSeen extends Model
{
    protected $table = 'caregiver_seen';

    protected $fillable = ['app_user_id', 'participant_id', 'day', 'seen_at'];

    protected function casts(): array
    {
        return ['seen_at' => 'datetime'];
    }
}
