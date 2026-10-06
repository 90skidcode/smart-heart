<?php

namespace App\Services\App;

use App\Forms\FormContext;
use App\Models\AppUser;
use App\Models\MedDoseLog;
use App\Models\MedSchedule;
use App\Models\Participant;
use App\Models\Reading;
use App\Models\SafetyAlert;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Participant app data: who may use the app, the published medicine list, dose answers
 * and home readings. All checks and calculations happen here, never on the phone.
 */
class AppService
{
    public const SLOTS = ['Morning', 'Afternoon', 'Evening', 'Night'];

    public const NON_DAILY = ['Weekly', 'As needed'];

    public const GLUCOSE_CONTEXTS = ['fasting', 'before_meal', 'after_meal', 'random', 'bedtime'];

    // ---------------------------------------------------------------- access

    /** Why the app cannot be enabled for this participant, or null when it can. */
    public function blocker(Participant $p): ?string
    {
        return match (true) {
            $p->status === 'withdrawn' => 'Participant has withdrawn.',
            $p->status !== 'randomised' => 'The app is enabled after randomisation.',
            $p->arm !== 'intervention' => 'The app is only for the intervention arm. Control participants receive one-time links instead.',
            default => null,
        };
    }

    public function caregiverAllowed(Participant $p): bool
    {
        return FormContext::for($p)->value('CON-01', 'CON_CAREGIVER_VIEW') === 'Yes';
    }

    /** Find who may activate: participant (matched on the record's current phone) or a nominated caregiver. */
    public function findForActivation(string $studyId, string $phone): ?AppUser
    {
        $p = Participant::where('study_id', self::normaliseStudyId($studyId))->first();
        if (! $p || $this->blocker($p)) {
            return null;
        }
        $users = AppUser::where('participant_id', $p->id)->whereIn('status', ['invited', 'active'])->get();

        return $users->first(fn (AppUser $u) => $u->role === 'participant' ? $p->phone === $phone : $u->phone === $phone);
    }

    /** Accepts "SMART-HEART-0007", "smart-heart-7" or "7". */
    public static function normaliseStudyId(string $raw): string
    {
        $prefix = (string) config('smartheart.study_id_prefix');
        $raw = strtoupper(trim($raw));
        $digits = preg_replace('/\D/', '', str_starts_with($raw, strtoupper($prefix)) ? substr($raw, strlen($prefix)) : $raw);

        return $digits === '' ? $raw : $prefix.str_pad(ltrim($digits, '0') ?: '0', (int) config('smartheart.id_digits'), '0', STR_PAD_LEFT);
    }

    // ---------------------------------------------------------------- medicine list

    /** Medicine list as recorded in BL-M6 (complete or signed), shaped for the app. Not yet visible to the participant. */
    public function draftFromBaseline(Participant $p): array
    {
        $ctx = FormContext::for($p);
        $f = $ctx->form('BL-M6');
        if (! $f || ! in_array($f->status, ['complete', 'signed'], true)) {
            return ['items' => [], 'source' => null, 'note' => 'BL-M6 (medications) is not complete yet.'];
        }
        $items = [];
        foreach ($f->data['BL_MEDS'] ?? [] as $i => $m) {
            $items[] = [
                'key' => Str::slug(($m['drug'] ?? 'med').'-'.($i + 1)),
                'drug' => (string) ($m['drug'] ?? ''),
                'dose' => (string) ($m['dose'] ?? ''),
                'class' => (string) ($m['class'] ?? ''),
                'frequency' => (string) ($m['frequency'] ?? ''),
                'times' => array_values(array_intersect(self::SLOTS, $m['times'] ?? [])),
                'instructions' => '',
            ];
        }

        return ['items' => $items, 'source' => "BL-M6 ({$f->status}, form {$f->id})", 'note' => null];
    }

    /** Validate and publish a new version. Returns [schedule|null, errors]. */
    public function publish(Participant $p, array $items, ?string $note, string $source, User $by): array
    {
        $v = Validator::make(['items' => $items], [
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/', 'distinct'],
            'items.*.drug' => ['required', 'string', 'max:80'],
            'items.*.dose' => ['required', 'string', 'max:40'],
            'items.*.class' => ['nullable', 'string', 'max:60'],
            'items.*.frequency' => ['required', 'string', 'max:40'],
            'items.*.times' => ['present', 'array'],
            'items.*.times.*' => ['in:'.implode(',', self::SLOTS)],
            'items.*.instructions' => ['nullable', 'string', 'max:200'],
        ]);
        $errors = $v->errors()->all();
        foreach ($items as $i => $m) {
            if (! in_array($m['frequency'] ?? '', self::NON_DAILY, true) && empty($m['times'])) {
                $errors[] = 'Row '.($i + 1).' ('.($m['drug'] ?? '?').'): tick at least one time of day.';
            }
        }
        if ($errors) {
            return [null, $errors];
        }
        $clean = array_map(fn ($m) => ['key' => $m['key'], 'drug' => trim($m['drug']), 'dose' => trim($m['dose']), 'class' => $m['class'] ?? '',
            'frequency' => $m['frequency'], 'times' => array_values(array_intersect(self::SLOTS, $m['times'])), 'instructions' => trim($m['instructions'] ?? '')], $items);

        $s = DB::transaction(function () use ($p, $clean, $note, $source, $by) {
            $version = (int) MedSchedule::where('participant_id', $p->id)->lockForUpdate()->max('version') + 1;
            $s = MedSchedule::create(['participant_id' => $p->id, 'version' => $version, 'items' => $clean, 'source' => $source,
                'note' => $note, 'published_by' => $by->id, 'published_at' => now()]);
            Audit::log('med_schedule_published', ['entity_type' => 'med_schedule', 'entity_id' => $s->id, 'participant_id' => $p->id,
                'new_value' => $clean, 'reason' => $note, 'meta' => ['version' => $version, 'source' => $source]]);

            return $s;
        });

        return [$s, []];
    }

    /** Doses due on a date under the latest schedule, with any answers. */
    public function dayPlan(AppUser $u, string $date): array
    {
        $s = MedSchedule::latestFor($u->participant_id);
        if (! $s) {
            return [];
        }
        // Reminder times are the participant's own; a caregiver sees the same plan.
        $owner = $u->role === 'participant' ? $u : AppUser::where('participant_id', $u->participant_id)->where('role', 'participant')->first();
        $times = ($owner?->reminder_times ?: []) + config('smartheart.app.default_reminders');
        $logs = MedDoseLog::where('participant_id', $u->participant_id)->whereDate('dose_date', $date)->get()
            ->keyBy(fn ($l) => "{$l->med_key}|{$l->slot}");
        $out = [];
        foreach (self::SLOTS as $slot) {
            foreach ($s->items as $m) {
                if (in_array($m['frequency'], self::NON_DAILY, true) || ! in_array($slot, $m['times'], true)) {
                    continue;
                }
                $log = $logs["{$m['key']}|{$slot}"] ?? null;
                $out[] = ['med_key' => $m['key'], 'drug' => $m['drug'], 'dose' => $m['dose'], 'instructions' => $m['instructions'],
                    'slot' => $slot, 'time' => $times[$slot] ?? null, 'status' => $log?->status, 'answered_at' => $log?->answered_at?->toIso8601String()];
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- doses

    /** Batch from the phone's offline queue. Idempotent by client_uuid; the latest answer for a dose wins. */
    public function saveDoses(AppUser $u, array $doses): array
    {
        $results = [];
        $today = CarbonImmutable::now();
        $versions = MedSchedule::where('participant_id', $u->participant_id)->get()->keyBy('version');
        foreach ($doses as $d) {
            $uuid = (string) ($d['client_uuid'] ?? '');
            $err = $this->doseError($d, $versions, $today);
            if ($err) {
                $results[] = ['client_uuid' => $uuid, 'result' => 'rejected', 'reason' => $err];

                continue;
            }
            if (MedDoseLog::where('client_uuid', $uuid)->exists()) {
                $results[] = ['client_uuid' => $uuid, 'result' => 'duplicate'];

                continue;
            }
            $answered = CarbonImmutable::parse($d['answered_at']);
            $existing = MedDoseLog::where(['participant_id' => $u->participant_id, 'med_key' => $d['med_key'], 'slot' => $d['slot']])
                ->whereDate('dose_date', $d['date'])->first();
            if ($existing) {
                if ($answered->gt($existing->answered_at) && $existing->status !== $d['status']) {
                    Audit::log('dose_changed', ['user' => null, 'entity_type' => 'med_dose', 'entity_id' => $existing->id, 'participant_id' => $u->participant_id,
                        'field' => "{$d['med_key']} {$d['date']} {$d['slot']}", 'old_value' => $existing->status, 'new_value' => $d['status'], 'meta' => ['app_user_id' => $u->id]]);
                    $existing->forceFill(['status' => $d['status'], 'answered_at' => $answered, 'received_at' => now(), 'client_uuid' => $uuid, 'app_user_id' => $u->id])->save();
                }
                $results[] = ['client_uuid' => $uuid, 'result' => 'updated'];

                continue;
            }
            MedDoseLog::create(['client_uuid' => $uuid, 'participant_id' => $u->participant_id, 'schedule_version' => (int) $d['schedule_version'],
                'med_key' => $d['med_key'], 'dose_date' => $d['date'], 'slot' => $d['slot'], 'status' => $d['status'],
                'answered_at' => $answered, 'received_at' => now(), 'app_user_id' => $u->id]);
            $results[] = ['client_uuid' => $uuid, 'result' => 'saved'];
        }

        return $results;
    }

    private function doseError(array $d, $versions, CarbonImmutable $now): ?string
    {
        if (! Str::isUuid($d['client_uuid'] ?? '')) {
            return 'client_uuid must be a UUID.';
        }
        if (! in_array($d['status'] ?? null, ['taken', 'skipped'], true) || ! in_array($d['slot'] ?? null, self::SLOTS, true)) {
            return 'Invalid status or time of day.';
        }
        $s = $versions[(int) ($d['schedule_version'] ?? 0)] ?? null;
        $item = $s ? collect($s->items)->firstWhere('key', $d['med_key'] ?? null) : null;
        if (! $item || ! in_array($d['slot'], $item['times'], true)) {
            return 'This medicine is not in the list for that time.';
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($d['date'] ?? ''));
            $answered = CarbonImmutable::parse((string) ($d['answered_at'] ?? ''));
        } catch (\Throwable) {
            return 'Invalid date.';
        }
        if (! $date || $date->gt($now->addDay()) || $date->lt($now->subDays(config('smartheart.app.dose_backfill_days'))->startOfDay())) {
            return 'Date is outside the allowed range.';
        }
        if ($answered->gt($now->addMinutes(10))) {
            return 'Answer time is in the future.';
        }

        return null;
    }

    // ---------------------------------------------------------------- readings

    /** Batch of readings (manual or Health Connect). Idempotent by client_uuid. */
    public function saveReadings(AppUser $u, array $readings): array
    {
        $results = [];
        foreach ($readings as $r) {
            $uuid = (string) ($r['client_uuid'] ?? '');
            [$values, $err] = $this->readingCheck($r);
            if ($err) {
                $results[] = ['client_uuid' => $uuid, 'result' => 'rejected', 'reason' => $err];

                continue;
            }
            if (Reading::where('client_uuid', $uuid)->exists()) {
                $results[] = ['client_uuid' => $uuid, 'result' => 'duplicate'];

                continue;
            }
            $reading = Reading::create(['client_uuid' => $uuid, 'participant_id' => $u->participant_id, 'type' => $r['type'], 'values' => $values,
                'measured_at' => CarbonImmutable::parse($r['measured_at']), 'uploaded_at' => now(), 'source' => $r['source'],
                'device' => isset($r['device']) ? mb_substr((string) $r['device'], 0, 80) : null, 'app_user_id' => $u->id]);
            $flag = $this->readingAlert($reading);
            $results[] = ['client_uuid' => $uuid, 'result' => 'saved', 'id' => $reading->id, 'flag' => $flag];
        }

        return $results;
    }

    /** @return array{0: ?array, 1: ?string} [clean values, error] */
    private function readingCheck(array $r): array
    {
        if (! Str::isUuid($r['client_uuid'] ?? '')) {
            return [null, 'client_uuid must be a UUID.'];
        }
        if (! in_array($r['type'] ?? null, Reading::TYPES, true) || ! in_array($r['source'] ?? null, Reading::SOURCES, true)) {
            return [null, 'Invalid reading type or source.'];
        }
        try {
            $at = CarbonImmutable::parse((string) ($r['measured_at'] ?? ''));
        } catch (\Throwable) {
            return [null, 'Invalid measurement time.'];
        }
        if ($at->gt(now()->addMinutes(10)) || $at->lt(now()->subDays(config('smartheart.app.reading_backfill_days')))) {
            return [null, 'Measurement time is outside the allowed range.'];
        }
        $v = $r['values'] ?? [];
        $num = fn ($k, $lo, $hi) => isset($v[$k]) && is_numeric($v[$k]) && $v[$k] >= $lo && $v[$k] <= $hi;

        return match ($r['type']) {
            'bp' => (! $num('sbp', 60, 260) || ! $num('dbp', 30, 160) || $v['sbp'] <= $v['dbp'] || (isset($v['pulse']) && ! $num('pulse', 30, 220)))
                ? [null, 'Blood pressure must be top 60–260, bottom 30–160 (top higher than bottom), pulse 30–220.']
                : [['sbp' => (int) round($v['sbp']), 'dbp' => (int) round($v['dbp'])] + (isset($v['pulse']) ? ['pulse' => (int) round($v['pulse'])] : []), null],
            'glucose' => (! $num('mg_dl', 20, 600) || ! in_array($v['context'] ?? 'random', self::GLUCOSE_CONTEXTS, true))
                ? [null, 'Sugar must be 20–600 mg/dL.']
                : [['mg_dl' => (int) round($v['mg_dl']), 'context' => $v['context'] ?? 'random'], null],
            'weight' => ! $num('kg', 25, 250) ? [null, 'Weight must be 25–250 kg.'] : [['kg' => round((float) $v['kg'], 1)], null],
        };
    }

    /** Raise a warning alert for the study team when a reading is outside the configured limits. Returns high|low|null. */
    private function readingAlert(Reading $r): ?string
    {
        $c = config('smartheart.app.reading_alerts');
        $v = $r->values;
        [$rule, $flag, $summary] = match (true) {
            $r->type === 'bp' && ($v['sbp'] >= $c['bp_sbp_high'] || $v['dbp'] >= $c['bp_dbp_high']) => ['BP_HIGH', 'high', "Home BP {$v['sbp']}/{$v['dbp']} mmHg"],
            $r->type === 'bp' && $v['sbp'] < $c['bp_sbp_low'] => ['BP_LOW', 'low', "Home BP {$v['sbp']}/{$v['dbp']} mmHg (low)"],
            $r->type === 'glucose' && $v['mg_dl'] >= $c['glucose_high'] => ['GLUCOSE_HIGH', 'high', "Blood sugar {$v['mg_dl']} mg/dL ({$v['context']})"],
            $r->type === 'glucose' && $v['mg_dl'] < $c['glucose_low'] => ['GLUCOSE_LOW', 'low', "Blood sugar {$v['mg_dl']} mg/dL ({$v['context']}, low)"],
            default => [null, null, null],
        };
        if (! $rule) {
            return null;
        }
        $a = SafetyAlert::create(['participant_id' => $r->participant_id, 'rule' => $rule, 'severity' => 'warning', 'source_reading_id' => $r->id,
            'summary' => "{$summary}, measured {$r->measured_at->timezone('Asia/Kolkata')->format('d M H:i')} ({$r->source}). Contact the participant.",
            'status' => 'open', 'raised_at' => now()]);
        Audit::log('alert_raised', ['user' => null, 'entity_type' => 'safety_alert', 'entity_id' => $a->id, 'participant_id' => $r->participant_id,
            'new_value' => $rule, 'meta' => ['severity' => 'warning', 'reading_id' => $r->id]]);

        return $flag;
    }

    // ---------------------------------------------------------------- staff summary

    /** Dose answers for the last N days, for staff (counts only; the participant never sees a percentage). */
    public function doseSummary(Participant $p, int $days = 7): array
    {
        $out = [];
        $s = MedSchedule::latestFor($p->id);
        $perDay = $s ? collect($s->items)->reject(fn ($m) => in_array($m['frequency'], self::NON_DAILY, true))->sum(fn ($m) => count($m['times'])) : 0;
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now()->subDays($i)->toDateString();
            $logs = MedDoseLog::where('participant_id', $p->id)->whereDate('dose_date', $d)->get();
            $out[] = ['date' => $d, 'due' => $perDay, 'taken' => $logs->where('status', 'taken')->count(),
                'skipped' => $logs->where('status', 'skipped')->count(), 'unanswered' => max(0, $perDay - $logs->count())];
        }

        return $out;
    }
}
