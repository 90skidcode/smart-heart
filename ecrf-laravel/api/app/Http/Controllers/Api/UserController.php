<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Staff users. No deletes: deactivate instead, so the audit trail keeps resolving names. */
class UserController extends Controller
{
    public function index()
    {
        return response()->json(User::with('role')->orderBy('name')->get()->map->toPublic());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'designation' => ['nullable', 'string', 'max:120'],
            'role_id' => ['required', 'exists:roles,id'],
        ]);
        $temp = $this->tempPassword();
        $user = User::create($data + [
            'email' => strtolower($data['email']),
            'password' => $temp,
            'must_change_password' => true,
            'is_active' => true,
        ]);
        Audit::log('user_created', ['entity_type' => 'user', 'entity_id' => $user->id,
            'meta' => ['name' => $user->name, 'email' => $user->email, 'role_id' => $user->role_id]]);

        return response()->json(['user' => $user->load('role')->toPublic(), 'temporary_password' => $temp], 201);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'designation' => ['nullable', 'string', 'max:120'],
            'role_id' => ['required', 'exists:roles,id'],
            'is_active' => ['required', 'boolean'],
        ]);
        if ($user->id === $request->user()->id && (! $data['is_active'] || (int) $data['role_id'] !== $user->role_id)) {
            return response()->json(['message' => 'You cannot deactivate yourself or change your own role.'], 422);
        }
        $old = $user->only(array_keys($data));
        $user->fill($data)->save();
        Audit::diff('user_updated', $old, $user->only(array_keys($data)), ['entity_type' => 'user', 'entity_id' => $user->id]);
        if (! $user->is_active) {
            ApiToken::where('user_id', $user->id)->delete();
        }

        return response()->json($user->load('role')->toPublic());
    }

    public function resetPassword(User $user)
    {
        $temp = $this->tempPassword();
        $user->forceFill(['password' => $temp, 'must_change_password' => true, 'failed_attempts' => 0, 'locked_until' => null])->save();
        ApiToken::where('user_id', $user->id)->delete();
        Audit::log('password_reset', ['entity_type' => 'user', 'entity_id' => $user->id]);

        return response()->json(['temporary_password' => $temp]);
    }

    private function tempPassword(): string
    {
        // Letters + digits, no look-alike characters, meets the password policy.
        $alpha = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $p = '';
        for ($i = 0; $i < 8; $i++) {
            $p .= $alpha[random_int(0, strlen($alpha) - 1)];
        }
        for ($i = 0; $i < 4; $i++) {
            $p .= $digits[random_int(0, strlen($digits) - 1)];
        }

        return str_shuffle($p);
    }
}
