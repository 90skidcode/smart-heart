<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstrumentText extends Model
{
    protected $fillable = ['instrument', 'lang', 'key', 'text', 'updated_by'];
}
