<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RolePermission extends Model
{
    public $timestamps = false;

    protected $fillable = ['role_id', 'screen', 'can_read', 'can_write'];

    protected function casts(): array
    {
        return ['can_read' => 'boolean', 'can_write' => 'boolean'];
    }
}
