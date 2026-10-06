<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushLog extends Model
{
    protected $table = 'push_log';

    protected $fillable = ['app_user_id', 'kind', 'title', 'status', 'error'];
}
