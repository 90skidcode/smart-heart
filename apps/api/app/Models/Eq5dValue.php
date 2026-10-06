<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Eq5dValue extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'health_state';

    protected $keyType = 'string';

    protected $fillable = ['health_state', 'utility'];
}
