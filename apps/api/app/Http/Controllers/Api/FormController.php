<?php

namespace App\Http\Controllers\Api;

use App\Forms\EligibilityEngine;
use App\Forms\FormRegistry;
use App\Forms\FormValidator;
use App\Http\Controllers\Controller;
use App\Models\CrfForm;
use App\Models\Participant;
use App\Models\User;
use App\Services\FormService;
use Illuminate\Http\Request;

class FormController extends Controller
{
    public function __construct(private FormService $forms) {}

    public function definitions()
    {
        return response()->json([
            'forms' => collect(FormRegistry::codes())->mapWithKeys(fn ($c) => [$c => FormRegistry::get($c)]),
            'change_reasons' => config('smartheart.change_reasons'),
            'signature_meanings' => config('smartheart.signature_meanings'),
        ]);
    }

    public function show(Request $request, Participant $participant, string $code)
    {
        if ($deny = $this->guard($request->user(), $code, 'read')) {
            return $deny;
        }
        $participant->load('forms');

        return response()->json($this->payload($participant, $code));
    }

    /** Live calculation while typing (eligibility, warnings). Saves nothing. */
    public function preview(Request $request, string $code)
    {
        if ($deny = $this->guard($request->user(), $code, 'read')) {
            return $deny;
        }
        $data = FormValidator::clean($code, $request->input('data', []));
        $check = $this->forms->check($code, $data);
        $out = $check;
        if ($code === 'SCR-01') {
            $out['eligibility'] = EligibilityEngine::evaluate($data);
        }

        return response()->json($out);
    }

    public function save(Request $request, Participant $participant, string $code)
    {
        if ($deny = $this->guard($request->user(), $code, 'write')) {
            return $deny;
        }
        $res = $this->forms->save($participant, $code, (array) $request->input('data', []),
            (array) $request->input('reasons', []), (array) $request->input('overrides', []), $request->user());

        return $this->respond($res, $participant, $code);
    }

    public function complete(Request $request, Participant $participant, string $code)
    {
        if ($deny = $this->guard($request->user(), $code, 'write')) {
            return $deny;
        }
        $participant->load('forms');

        return $this->respond($this->forms->complete($participant, $code, $request->user()), $participant, $code);
    }

    public function sign(Request $request, Participant $participant, string $code)
    {
        if ($deny = $this->guard($request->user(), $code, 'read')) {
            return $deny;
        }
        if (! $request->user()->canScreen('sign_forms', 'write')) {
            return response()->json(['message' => 'Your role cannot sign forms.'], 403);
        }
        $request->validate(['password' => ['required', 'string']]);
        $participant->load('forms');

        return $this->respond($this->forms->sign($participant, $code, $request->user(), $request->input('password')), $participant, $code);
    }

    public function unlock(Request $request, Participant $participant, string $code)
    {
        if ($deny = $this->guard($request->user(), $code, 'read')) {
            return $deny;
        }
        if (! $request->user()->canScreen('unlock_forms', 'write')) {
            return response()->json(['message' => 'Your role cannot unlock signed forms.'], 403);
        }
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $participant->load('forms');

        return $this->respond($this->forms->unlock($participant, $code, $request->user(), $data['reason']), $participant, $code);
    }

    // ------------------------------------------------------------------

    private function guard(User $user, string $code, string $level)
    {
        if (! FormRegistry::exists($code)) {
            return response()->json(['message' => "Unknown form {$code}."], 404);
        }
        if (! $user->canScreen(FormRegistry::screenKey($code), $level)) {
            return response()->json(['message' => "Your role does not have {$level} access to {$code}."], 403);
        }

        return null;
    }

    private function respond(array $res, Participant $p, string $code)
    {
        if (! $res['ok']) {
            unset($res['form']);

            return response()->json($res, $res['status'] ?? 422);
        }

        return response()->json($this->payload($p->fresh('forms'), $code));
    }

    private function payload(Participant $p, string $code): array
    {
        /** @var CrfForm|null $f */
        $f = $p->form($code);
        $data = $f?->data ?? [];
        // Defaults for a brand-new form.
        if (! $f) {
            foreach (FormRegistry::fields($code) as $k => $field) {
                if (isset($field['default'])) {
                    $data[$k] = $field['default'];
                }
            }
        }
        $check = $this->forms->check($code, $data);

        return [
            'participant' => ['id' => $p->id, 'study_id' => $p->study_id, 'screening_id' => $p->screening_id,
                'full_name' => $p->full_name, 'status' => $p->status, 'arm' => $p->arm],
            'code' => $code,
            'status' => $f?->status ?? 'not_started',
            'locked_reason' => $this->forms->lockReason($p, $code),
            'data' => (object) $data,
            'computed' => (object) ($f?->computed ?: $check['computed']),
            'overrides' => (object) ($f?->overrides ?? []),
            'check' => $check,
            'eligibility' => $code === 'SCR-01' ? EligibilityEngine::evaluate($data) : null,
            'completed_at' => $f?->completed_at?->toIso8601String(),
            'signed_at' => $f?->signed_at?->toIso8601String(),
            'signed_by' => $f?->signer?->name,
            'signature_meaning' => $f?->signature_meaning,
            'unlock_count' => $f?->unlock_count ?? 0,
            'updated_at' => $f?->updated_at?->toIso8601String(),
        ];
    }
}
