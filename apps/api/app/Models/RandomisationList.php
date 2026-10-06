<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RandomisationList extends Model
{
    protected $fillable = ['name', 'file_sha256', 'status', 'row_count', 'uploaded_by'];

    public function slots(): HasMany
    {
        return $this->hasMany(RandomisationSlot::class, 'list_id');
    }
}
