<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reading extends Model
{
    public const TYPES = ['bp', 'glucose', 'weight'];

    public const SOURCES = ['manual', 'health_connect'];

    protected $fillable = ['client_uuid', 'participant_id', 'type', 'values', 'measured_at', 'uploaded_at', 'source', 'device', 'app_user_id'];

    protected function casts(): array
    {
        return ['values' => 'array', 'measured_at' => 'datetime', 'uploaded_at' => 'datetime'];
    }
}
