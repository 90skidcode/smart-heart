<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Models\CaregiverSeen;
use App\Models\MedSchedule;
use App\Models\Participant;
use App\Models\PushLog;
use App\Models\Reading;
use App\Services\App\AppService;
use App\Services\App\PushService;
use App\Support\Audit;
use Illuminate\Http\Request;

/**
 * Staff side of the participant app: who has access, the published medicine list, and what came in.
 * Only the intervention arm uses the app, so these screens need 'view_allocation' as well as 'app_access'.
 */
class AppAdminController extends Controller
{
    public function __construct(private AppService $app, private PushService $push) {}

    private function guard(Request $r): void
    {
        abort_unless($r->user()->canScreen('view_allocation'), 403, 'The app screens show the allocated arm, so they need the "See allocated arm" permission.');
    }

    public function show(Request $request, Participant $participant)
    {
        $this->guard($request);
        $p = $participant;
        $users = AppUser::with('devices')->where('participant_id', $p->id)->orderBy('role', 'desc')->orderBy('id')->get();
        $s = MedSchedule::with('publisher')->where('participant_id', $p->id)->orderByDesc('version')->first();

        return response()->json([
            'blocker' => $this->app->blocker($p),
            'caregiver_allowed' => $this->app->caregiverAllowed($p),
            'max_caregivers' => config('smartheart.app.max_caregivers'),
            'firebase_configured' => (bool) config('smartheart.app.firebase_project_id'),
            'push_configured' => $this->push->configured(),
            'users' => $users->map(fn (AppUser $u) => [
                'id' => $u->id, 'role' => $u->role, 'name' => $u->role === 'participant' ? $p->full_name : $u->name, 'relation' => $u->relation,
                'phone' => $u->role === 'participant' ? $p->phone : $u->phone, 'status' => $u->status, 'lang' => $u->lang,
                'activated_at' => $u->activated_at?->toIso8601String(), 'last_seen_at' => $u->last_seen_at?->toIso8601String(),
                'revoked_at' => $u->revoked_at?->toIso8601String(), 'revoke_reason' => $u->revoke_reason, 'devices' => $u->devices->count(),
            ])->values(),
            'schedule' => $s ? ['version' => $s->version, 'items' => $s->items, 'source' => $s->source, 'note' => $s->note,
                'published_at' => $s->published_at->toIso8601String(), 'published_by' => $s->publisher?->name] : null,
            'draft' => $this->app->draftFromBaseline($p),
            'doses' => $this->app->doseSummary($p),
            'readings' => Reading::where('participant_id', $p->id)->orderByDesc('measured_at')->limit(50)->get()
                ->map(fn ($r) => ['id' => $r->id, 'type' => $r->type, 'values' => $r->values, 'measured_at' => $r->measured_at->toIso8601String(),
                    'uploaded_at' => $r->uploaded_at->toIso8601String(), 'source' => $r->source, 'device' => $r->device])->values(),
            'seen' => CaregiverSeen::where('participant_id', $p->id)->orderByDesc('day')->limit(7)->get(['day', 'seen_at', 'app_user_id']),
            'push' => PushLog::whereIn('app_user_id', $users->pluck('id'))->latest()->limit(10)->get(['kind', 'status', 'error', 'created_at']),
        ]);
    }

    public function enable(Request $request, Participant $participant)
    {
        $this->guard($request);
        if ($why = $this->app->blocker($participant)) {
            return response()->json(['message' => $why], 422);
        }
        $existing = AppUser::where('participant_id', $participant->id)->where('role', 'participant')->first();
        if ($existing && $existing->status !== 'revoked') {
            return response()->json(['message' => 'App access is already enabled.'], 422);
        }
        $lang = in_array($request->input('lang'), ['en', 'ta'], true) ? $request->input('lang') : 'ta';
        $u = $existing ?? new AppUser(['participant_id' => $participant->id, 'role' => 'participant', 'created_by' => $request->user()->id]);
        $u->forceFill(['phone' => $participant->phone, 'status' => 'invited', 'lang' => $lang, 'revoked_at' => null, 'revoke_reason' => null])->save();
        Audit::log('app_enabled', ['entity_type' => 'app_user', 'entity_id' => $u->id, 'participant_id' => $participant->id, 'new_value' => 'participant']);

        return $this->show($request, $participant);
    }

    public function addCaregiver(Request $request, Participant $participant)
    {
        $this->guard($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'relation' => ['required', 'string', 'max:60'],
            'phone' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'lang' => ['nullable', 'in:en,ta'],
        ], ['phone.regex' => 'Enter a 10-digit Indian mobile number.']);
        if ($why = $this->app->blocker($participant)) {
            return response()->json(['message' => $why], 422);
        }
        if (! $this->app->caregiverAllowed($participant)) {
            return response()->json(['message' => 'Consent (CON-01) does not allow a caregiver to view the app.'], 422);
        }
        if ($data['phone'] === $participant->phone) {
            return response()->json(['message' => "The caregiver needs their own mobile number, not the participant's."], 422);
        }
        $active = AppUser::where('participant_id', $participant->id)->where('role', 'caregiver')->where('status', '!=', 'revoked');
        if ((clone $active)->where('phone', $data['phone'])->exists()) {
            return response()->json(['message' => 'This caregiver is already added.'], 422);
        }
        if ($active->count() >= config('smartheart.app.max_caregivers')) {
            return response()->json(['message' => 'The maximum number of caregivers is already added. Remove one first.'], 422);
        }
        $u = AppUser::create(['participant_id' => $participant->id, 'role' => 'caregiver', 'name' => $data['name'], 'relation' => $data['relation'],
            'phone' => $data['phone'], 'status' => 'invited', 'lang' => $data['lang'] ?? 'ta', 'created_by' => $request->user()->id]);
        Audit::log('app_caregiver_added', ['entity_type' => 'app_user', 'entity_id' => $u->id, 'participant_id' => $participant->id,
            'new_value' => "{$data['name']} ({$data['relation']})"]);

        return $this->show($request, $participant);
    }

    public function revoke(Request $request, AppUser $appUser)
    {
        $this->guard($request);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']])['reason'];
        $appUser->tokens()->delete();
        $appUser->devices()->delete();
        $appUser->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'revoke_reason' => $reason])->save();
        Audit::log('app_access_revoked', ['entity_type' => 'app_user', 'entity_id' => $appUser->id, 'participant_id' => $appUser->participant_id,
            'old_value' => $appUser->role, 'reason' => $reason]);

        return $this->show($request, $appUser->participant);
    }

    public function publishMeds(Request $request, Participant $participant)
    {
        $this->guard($request);
        $data = $request->validate(['items' => ['present', 'array'], 'note' => ['nullable', 'string', 'max:255']]);
        if ($why = $this->app->blocker($participant)) {
            return response()->json(['message' => $why], 422);
        }
        $draft = $this->app->draftFromBaseline($participant);
        $source = $draft['source'] ? $draft['source'].' reviewed by staff' : 'Entered by staff';
        [$s, $errors] = $this->app->publish($participant, $data['items'], $data['note'] ?? null, $source, $request->user());
        if (! $s) {
            return response()->json(['message' => 'The medicine list was not published.', 'errors' => $errors], 422);
        }
        $users = AppUser::with('devices')->where('participant_id', $participant->id)->where('status', 'active')->get();
        dispatch(fn () => app(PushService::class)->send($users, 'meds_updated', ['version' => $s->version]))->afterResponse();

        return $this->show($request, $participant);
    }
}
