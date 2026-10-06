<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RandomisationSlot extends Model
{
    public $timestamps = false;

    protected $fillable = ['list_id', 'stratum', 'seq_no', 'block_no', 'arm', 'participant_id', 'used_at'];

    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }
}
