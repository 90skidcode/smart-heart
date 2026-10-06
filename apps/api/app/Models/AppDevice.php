<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppDevice extends Model
{
    protected $fillable = ['app_user_id', 'fcm_token', 'platform', 'app_version'];
}
