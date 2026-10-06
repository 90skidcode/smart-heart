<?php

namespace App\Http\Controllers\Api;

use App\Forms\FormRegistry;
use App\Forms\FormValidator;
use App\Http\Controllers\Controller;
use App\Models\Participant;
use App\Services\FormService;
use App\Services\IdGenerator;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ParticipantController extends Controller
{
    public function __construct(private FormService $forms) {}

    public function index(Request $request)
    {
        $q = Participant::query()->with('forms:id,participant_id,form_code,status,visit');
        if ($s = $request->query('status')) {
            $q->where('status', $s);
        }
        if (($arm = $request->query('arm')) && $arm !== 'all' && $request->user()->canScreen('view_allocation')) {
            $arm === 'unassigned' ? $q->whereNull('arm') : $q->where('arm', $arm);
        }
        if ($term = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('study_id', 'like', "%{$term}%")
                ->orWhere('screening_id', 'like', "%{$term}%")
                ->orWhere('full_name', 'like', "%{$term}%")
                ->orWhere('hospital_number', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%"));
        }

        return response()->json($q->orderByDesc('id')->limit(500)->get()->map(fn (Participant $p) => $this->summary($p)));
    }

    /** Registration: identity details + REG-01 in one step. Generates the study IDs. */
    public function store(Request $request)
    {
        $identity = $request->validate([
            'full_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'regex:/^[6-9]\d{9}$/'],
            'hospital_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'data' => ['required', 'array'],
        ], ['phone.regex' => 'Enter a 10-digit Indian mobile number.']);
        $user = $request->user();

        if ($identity['hospital_number'] ?? null) {
            $dup = Participant::where('hospital_number', $identity['hospital_number'])->first();
            if ($dup && ! $request->boolean('confirm_duplicate')) {
                return response()->json(['message' => "Hospital number already registered as {$dup->study_id}.", 'duplicate' => $dup->study_id], 409);
            }
        }

        // Validate REG-01 before creating anything, so a bad form never consumes a study ID.
        $check = $this->forms->check('REG-01', FormValidator::clean('REG-01', $request->input('data', [])));
        if ($check['errors']) {
            return response()->json(['ok' => false, 'message' => 'Some values are not valid.', 'errors' => $check['errors']], 422);
        }
        if ($check['missing']) {
            return response()->json(['ok' => false, 'message' => 'Please answer all REG-01 questions.',
                'errors' => array_fill_keys($check['missing'], 'Required.')], 422);
        }

        return DB::transaction(function () use ($identity, $request, $user) {
            $ids = IdGenerator::next();
            $p = Participant::create($ids + [
                'full_name' => $identity['full_name'],
                'phone' => $identity['phone'],
                'hospital_number' => $identity['hospital_number'] ?? null,
                'address' => $identity['address'] ?? null,
                'status' => 'registered',
                'created_by' => $user->id,
            ]);
            Audit::log('participant_registered', ['entity_type' => 'participant', 'entity_id' => $p->id, 'participant_id' => $p->id,
                'meta' => ['study_id' => $p->study_id, 'screening_id' => $p->screening_id]]);

            $res = $this->forms->save($p, 'REG-01', $request->input('data', []), [], $request->input('overrides', []), $user);
            if (! $res['ok']) {
                throw new \RuntimeException($res['message'] ?? 'Registration failed.');
            }
            $this->forms->complete($p->fresh('forms'), 'REG-01', $user);

            return response()->json($this->detail($p->fresh('forms')), 201);
        });
    }

    public function show(Participant $participant)
    {
        return response()->json($this->detail($participant->load('forms')));
    }

    /** Identity correction — always needs a reason. */
    public function update(Request $request, Participant $participant)
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'regex:/^[6-9]\d{9}$/'],
            'hospital_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $fields = Participant::IDENTITY_FIELDS;
        $old = $participant->only($fields);
        $participant->fill(collect($data)->only($fields)->all())->save();
        Audit::diff('identity_changed', $old, $participant->only($fields),
            ['entity_type' => 'participant', 'entity_id' => $participant->id, 'participant_id' => $participant->id],
            array_fill_keys($fields, $data['reason']));

        return response()->json($this->detail($participant->load('forms')));
    }

    /** Arm is shown only to roles with "view_allocation"; others see that the participant is randomised, not the arm. */
    private function armFor(Participant $p): ?string
    {
        if (! $p->arm) {
            return null;
        }

        return request()->user()?->canScreen('view_allocation') ? $p->arm : 'blinded';
    }

    private function summary(Participant $p): array
    {
        return [
            'id' => $p->id,
            'study_id' => $p->study_id,
            'screening_id' => $p->screening_id,
            'full_name' => $p->full_name,
            'hospital_number' => $p->hospital_number,
            'status' => $p->status,
            'status_label' => Participant::STATUSES[$p->status] ?? $p->status,
            'arm' => $this->armFor($p),
            'registered_on' => $p->created_at?->toDateString(),
            'forms' => $p->forms->mapWithKeys(fn ($f) => [$f->form_code => $f->status]),
        ];
    }

    private function detail(Participant $p): array
    {
        $forms = [];
        foreach (FormRegistry::codes() as $code) {
            $f = $p->form($code);
            $def = FormRegistry::get($code);
            $forms[] = [
                'code' => $code,
                'title' => $def['title'],
                'group' => $def['group'] ?? 'Other',
                'self_entry' => ! empty($def['self_entry']),
                'status' => $f?->status ?? 'not_started',
                'locked_reason' => $this->forms->lockReason($p, $code),
                'signed_at' => $f?->signed_at?->toIso8601String(),
                'updated_at' => $f?->updated_at?->toIso8601String(),
                'elig_status' => $code === 'SCR-01' ? ($f?->computed['ELIG_STATUS'] ?? null) : null,
            ];
        }
        foreach (FormRegistry::PLANNED as $code => [$title, $why]) {
            $forms[] = ['code' => $code, 'title' => $title, 'group' => 'PROs', 'status' => 'planned', 'locked_reason' => $why];
        }
        $rand = $p->form('RAND-01');

        return $this->summary($p) + [
            'phone' => $p->phone,
            'address' => $p->address,
            'age_stratum' => $p->age_stratum,
            'screen_fail_reasons' => $p->screen_fail_reasons,
            'screen_failed_on' => $p->screen_failed_on?->toDateString(),
            'randomisation' => $rand ? [
                'date' => $p->randomisation_date?->toDateString(),
                'stratum' => $p->age_stratum,
                'age' => $rand->data['RAND_AGE'] ?? null,
                'seq_no' => $rand->data['RAND_SEQ_NO'] ?? null,
                'by' => $rand->signer?->name,
                'at' => $rand->signed_at?->toIso8601String(),
            ] : null,
            'form_list' => $forms,
        ];
    }
}
