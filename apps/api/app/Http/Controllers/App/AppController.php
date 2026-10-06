<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\AppDevice;
use App\Models\AppToken;
use App\Models\AppUser;
use App\Models\CaregiverSeen;
use App\Models\ContentItem;
use App\Models\MedDoseLog;
use App\Models\MedSchedule;
use App\Models\Reading;
use App\Services\App\AppService;
use App\Services\App\FirebaseTokenVerifier;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * API for the participant app. Participants and caregivers only ever see their own participant's data,
 * never scores, arm details or other eCRF content.
 */
class AppController extends Controller
{
    public function __construct(private AppService $app, private FirebaseTokenVerifier $firebase) {}

    private function user(Request $r): AppUser
    {
        return $r->attributes->get('app_user');
    }

    /** Firebase phone OTP (done on the phone) + Participant ID → app token. */
    public function activate(Request $request)
    {
        $data = $request->validate([
            'participant_id' => ['required', 'string', 'max:40'],
            'id_token' => ['required', 'string', 'max:4096'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);
        try {
            $fb = $this->firebase->verify($data['id_token']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'otp_invalid'], 422);
        }
        $u = $this->app->findForActivation($data['participant_id'], $fb['phone']);
        if (! $u) {
            Audit::log('app_activation_failed', ['user' => null, 'meta' => ['participant_id' => mb_substr($data['participant_id'], 0, 40), 'phone_last4' => substr($fb['phone'], -4)]]);

            return response()->json(['message' => 'This mobile number is not registered for this Participant ID. Please contact the study team.', 'code' => 'not_registered'], 422);
        }
        $plain = Str::random(64);
        AppToken::create(['app_user_id' => $u->id, 'token_hash' => hash('sha256', $plain), 'device_name' => $data['device_name'] ?? null,
            'last_used_at' => now(), 'expires_at' => now()->addDays(config('smartheart.app.token_days'))]);
        $first = ! $u->activated_at;
        $u->forceFill(['status' => 'active', 'firebase_uid' => $fb['uid'], 'activated_at' => $u->activated_at ?? now(), 'last_seen_at' => now(),
            'phone' => $u->role === 'participant' ? $fb['phone'] : $u->phone])->save();
        Audit::log($first ? 'app_activated' : 'app_signed_in', ['user' => null, 'entity_type' => 'app_user', 'entity_id' => $u->id,
            'participant_id' => $u->participant_id, 'meta' => ['role' => $u->role, 'device' => $data['device_name'] ?? null]]);

        return response()->json(['token' => $plain, 'me' => $this->mePayload($u->fresh('participant'))]);
    }

    public function me(Request $request)
    {
        return response()->json($this->mePayload($this->user($request)));
    }

    private function mePayload(AppUser $u): array
    {
        $p = $u->participant;
        $first = explode(' ', trim($p->full_name))[0] ?? '';

        return [
            'role' => $u->role,
            'read_only' => $u->isCaregiver(),
            'name' => $u->isCaregiver() ? $u->name : $first,
            'participant_name' => $first,
            'study_id' => $p->study_id,
            'lang' => $u->lang,
            'reminder_times' => ($u->reminder_times ?: []) + config('smartheart.app.default_reminders'),
            'study_phone' => config('smartheart.alerts.coordinator_phone') ?: null,
            'schedule_version' => MedSchedule::where('participant_id', $p->id)->max('version'),
        ];
    }

    public function updateMe(Request $request)
    {
        $u = $this->user($request);
        $data = $request->validate([
            'lang' => ['sometimes', 'in:en,ta'],
            'reminder_times' => ['sometimes', 'array'],
            'reminder_times.*' => ['regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ]);
        if (isset($data['reminder_times'])) {
            if ($u->isCaregiver()) {
                return response()->json(['message' => 'Caregivers can view but not change information.', 'code' => 'read_only'], 403);
            }
            $data['reminder_times'] = array_intersect_key($data['reminder_times'], array_flip(AppService::SLOTS));
        }
        $u->forceFill($data)->save();

        return response()->json($this->mePayload($u->fresh('participant')));
    }

    public function logout(Request $request)
    {
        $request->attributes->get('app_token')->delete();
        AppDevice::where('app_user_id', $this->user($request)->id)->where('fcm_token', $request->input('fcm_token'))->delete();

        return response()->json(['ok' => true]);
    }

    public function device(Request $request)
    {
        $data = $request->validate(['fcm_token' => ['required', 'string', 'max:512'], 'app_version' => ['nullable', 'string', 'max:32']]);
        AppDevice::updateOrCreate(['app_user_id' => $this->user($request)->id, 'fcm_token' => $data['fcm_token']],
            ['platform' => 'android', 'app_version' => $data['app_version'] ?? null]);

        return response()->json(['ok' => true]);
    }

    /** Today's checklist: doses, today's readings, caregiver "Seen". */
    public function today(Request $request)
    {
        $u = $this->user($request);
        $date = $request->query('date', now()->toDateString());
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), 422, 'date must be YYYY-MM-DD');
        $readings = Reading::where('participant_id', $u->participant_id)->whereDate('measured_at', $date)->orderBy('measured_at')->get();
        $seen = CaregiverSeen::with([])->where('participant_id', $u->participant_id)->whereDate('day', $date)->get()
            ->map(fn ($s) => ['name' => AppUser::find($s->app_user_id)?->name, 'seen_at' => $s->seen_at->toIso8601String()]);

        return response()->json([
            'date' => $date,
            'schedule_version' => MedSchedule::where('participant_id', $u->participant_id)->max('version'),
            'doses' => $this->app->dayPlan($u, $date),
            'readings' => $readings->map(fn ($r) => $this->readingRow($r))->values(),
            'seen_by' => $seen->values(),
        ]);
    }

    public function medications(Request $request)
    {
        $s = MedSchedule::latestFor($this->user($request)->participant_id);

        return response()->json($s ? ['version' => $s->version, 'published_at' => $s->published_at->toIso8601String(),
            'items' => array_map(fn ($m) => array_intersect_key($m, array_flip(['key', 'drug', 'dose', 'frequency', 'times', 'instructions'])), $s->items)]
            : ['version' => null, 'items' => []]);
    }

    public function saveDoses(Request $request)
    {
        $data = $request->validate(['doses' => ['required', 'array', 'min:1', 'max:200']]);

        return response()->json(['results' => $this->app->saveDoses($this->user($request), $data['doses'])]);
    }

    public function doses(Request $request)
    {
        $u = $this->user($request);
        $from = $request->query('from', now()->subDays(6)->toDateString());
        $to = $request->query('to', now()->toDateString());
        $logs = MedDoseLog::where('participant_id', $u->participant_id)->whereDate('dose_date', '>=', $from)->whereDate('dose_date', '<=', $to)
            ->orderBy('dose_date')->get(['med_key', 'dose_date', 'slot', 'status', 'answered_at']);

        return response()->json(['from' => $from, 'to' => $to, 'doses' => $logs]);
    }

    public function saveReadings(Request $request)
    {
        $data = $request->validate(['readings' => ['required', 'array', 'min:1', 'max:500']]);

        return response()->json(['results' => $this->app->saveReadings($this->user($request), $data['readings'])]);
    }

    public function readings(Request $request)
    {
        $u = $this->user($request);
        $days = min(90, max(1, (int) $request->query('days', 30)));
        $q = Reading::where('participant_id', $u->participant_id)->where('measured_at', '>=', now()->subDays($days))->orderByDesc('measured_at');
        if (in_array($request->query('type'), Reading::TYPES, true)) {
            $q->where('type', $request->query('type'));
        }

        return response()->json(['readings' => $q->limit(500)->get()->map(fn ($r) => $this->readingRow($r))->values()]);
    }

    private function readingRow(Reading $r): array
    {
        return ['id' => $r->id, 'client_uuid' => $r->client_uuid, 'type' => $r->type, 'values' => $r->values,
            'measured_at' => $r->measured_at->toIso8601String(), 'source' => $r->source, 'device' => $r->device];
    }

    public function content(Request $request)
    {
        $u = $this->user($request);
        $q = ContentItem::where('status', 'published')->orderBy('type')->orderBy('sort')->orderBy('id');
        if (in_array($request->query('type'), ContentItem::TYPES, true)) {
            $q->where('type', $request->query('type'));
        }
        $lang = in_array($request->query('lang'), ['en', 'ta'], true) ? $request->query('lang') : $u->lang;

        return response()->json(['items' => $q->get()->map(fn ($c) => $c->localised($lang))->values()]);
    }

    /** Caregiver confirms they have looked at the participant's day. */
    public function seen(Request $request)
    {
        $u = $this->user($request);
        if (! $u->isCaregiver()) {
            return response()->json(['message' => 'Only caregivers mark "Seen".'], 403);
        }
        $date = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']])['date'];
        CaregiverSeen::firstOrCreate(['app_user_id' => $u->id, 'day' => $date], ['participant_id' => $u->participant_id, 'seen_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
