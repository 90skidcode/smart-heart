<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentItem extends Model
{
    public const TYPES = ['education', 'faq'];

    protected $fillable = ['type', 'category', 'sort', 'status', 'title_en', 'body_en', 'title_ta', 'body_ta', 'ta_reviewed',
        'ta_reviewed_by', 'published_at', 'updated_by'];

    protected function casts(): array
    {
        return ['ta_reviewed' => 'boolean', 'published_at' => 'datetime'];
    }

    /** Text in the requested language. Tamil only once a native reviewer has approved it; otherwise English. */
    public function localised(string $lang): array
    {
        $ta = $lang === 'ta' && $this->ta_reviewed && $this->title_ta && $this->body_ta;

        return ['id' => $this->id, 'type' => $this->type, 'category' => $this->category, 'lang' => $ta ? 'ta' : 'en',
            'title' => $ta ? $this->title_ta : $this->title_en, 'body' => $ta ? $this->body_ta : $this->body_en,
            'updated_at' => $this->updated_at?->toIso8601String()];
    }
}
