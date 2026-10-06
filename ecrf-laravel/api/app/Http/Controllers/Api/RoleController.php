<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\Audit;
use App\Support\Screens;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index()
    {
        return response()->json([
            'screens' => collect(Screens::ALL)->map(fn ($s, $k) => $s + ['key' => $k])->values(),
            'protected' => Screens::ADMIN_PROTECTED,
            'roles' => Role::with('permissions')->withCount('users')->orderBy('id')->get()->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'description' => $r->description,
                'is_system' => $r->is_system,
                'users_count' => $r->users_count,
                'permissions' => $r->matrix(),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('roles', 'name')],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $role = Role::create($data);
        Audit::log('role_created', ['entity_type' => 'role', 'entity_id' => $role->id, 'meta' => $data]);

        return response()->json(['id' => $role->id], 201);
    }

    /** Body: { name, description, permissions: { screen: {read, write} } } */
    public function update(Request $request, Role $role)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('roles', 'name')->ignore($role->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['required', 'array'],
        ]);

        DB::transaction(function () use ($role, $data) {
            $before = $role->load('permissions')->matrix();
            $role->fill(['name' => $data['name'], 'description' => $data['description'] ?? null])->save();

            foreach (Screens::keys() as $screen) {
                $p = $data['permissions'][$screen] ?? [];
                $write = (bool) ($p['write'] ?? false);
                $read = $write || (bool) ($p['read'] ?? false);
                if ($role->is_system && in_array($screen, Screens::ADMIN_PROTECTED, true)) {
                    $read = true;
                    $write = $screen !== 'audit' ? true : $write;
                }
                $role->permissions()->updateOrCreate(['screen' => $screen], ['can_read' => $read, 'can_write' => $write]);

                $old = $before[$screen] ?? ['read' => false, 'write' => false];
                if ($old['read'] !== $read || $old['write'] !== $write) {
                    Audit::log('permission_changed', [
                        'entity_type' => 'role', 'entity_id' => $role->id, 'field' => $screen,
                        'old_value' => self::level($old['read'], $old['write']),
                        'new_value' => self::level($read, $write),
                        'meta' => ['role' => $role->name],
                    ]);
                }
            }
        });

        return response()->json(['ok' => true]);
    }

    public function destroy(Role $role)
    {
        if ($role->is_system) {
            return response()->json(['message' => 'The system administrator role cannot be deleted.'], 422);
        }
        if ($role->users()->exists()) {
            return response()->json(['message' => 'Move or deactivate the users in this role first.'], 422);
        }
        Audit::log('role_deleted', ['entity_type' => 'role', 'entity_id' => $role->id, 'meta' => ['name' => $role->name, 'permissions' => $role->load('permissions')->matrix()]]);
        $role->delete();

        return response()->json(['ok' => true]);
    }

    private static function level(bool $r, bool $w): string
    {
        return $w ? 'read+write' : ($r ? 'read' : 'none');
    }
}
