<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedSchedule extends Model
{
    protected $fillable = ['participant_id', 'version', 'items', 'source', 'note', 'published_by', 'published_at'];

    protected function casts(): array
    {
        return ['items' => 'array', 'published_at' => 'datetime'];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public static function latestFor(int $participantId): ?self
    {
        return self::where('participant_id', $participantId)->orderByDesc('version')->first();
    }
}
