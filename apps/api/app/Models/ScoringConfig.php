<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScoringConfig extends Model
{
    protected $fillable = ['engine', 'domain', 'version', 'rule', 'status', 'note', 'created_by', 'approved_at', 'approved_by'];

    protected function casts(): array
    {
        return ['rule' => 'array', 'approved_at' => 'datetime'];
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
