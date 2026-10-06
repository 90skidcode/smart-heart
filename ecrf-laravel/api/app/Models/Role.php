<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = ['name', 'description', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** ['screen' => ['read' => bool, 'write' => bool]] */
    public function matrix(): array
    {
        $out = [];
        foreach ($this->permissions as $p) {
            $out[$p->screen] = ['read' => (bool) ($p->can_read || $p->can_write), 'write' => (bool) $p->can_write];
        }

        return $out;
    }
}
