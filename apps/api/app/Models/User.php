<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $fillable = ['name', 'email', 'designation', 'password', 'role_id', 'is_active', 'must_change_password', 'password_changed_at'];

    protected $hidden = ['password', 'remember_token'];

    private ?array $matrixCache = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function permissionMatrix(): array
    {
        return $this->matrixCache ??= $this->role?->matrix() ?? [];
    }

    public function canScreen(string $screen, string $level = 'read'): bool
    {
        $p = $this->permissionMatrix()[$screen] ?? null;

        return $p !== null && (bool) $p[$level === 'write' ? 'write' : 'read'];
    }

    public function toPublic(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'designation' => $this->designation,
            'role_id' => $this->role_id,
            'role' => $this->role?->name,
            'is_active' => $this->is_active,
            'must_change_password' => $this->must_change_password,
            'locked' => $this->locked_until !== null && $this->locked_until->isFuture(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
