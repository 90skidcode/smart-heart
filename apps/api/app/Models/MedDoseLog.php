<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedDoseLog extends Model
{
    protected $fillable = ['client_uuid', 'participant_id', 'schedule_version', 'med_key', 'dose_date', 'slot', 'status',
        'answered_at', 'received_at', 'app_user_id'];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime', 'received_at' => 'datetime'];
    }
}
